//! Test doubles: an HTTP server implementing the agent API v1 table and a
//! recording printer.
#![allow(dead_code)]

use agent_core::config::Destination;
use agent_core::printer::{InstalledPrinter, PrintError, PrinterBackend, PrinterState, QueueStatus, SpoolRef};
use base64::Engine as _;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::VecDeque;
use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::path::PathBuf;
use std::sync::{Arc, Mutex};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

pub const KEY: &str = "test_key_0123456789abcdef0123456789abcdef0123456789abcdef01234567";

#[derive(Debug, Clone, PartialEq)]
pub enum JobState {
    Pending,
    Claimed(String),
    Done(String),
    Failed(String),
}

#[derive(Debug, Clone)]
pub struct FakeJob {
    pub id: u64,
    pub kind: String,
    pub target: Option<String>,
    pub payload: Vec<u8>,
    pub sha_override: Option<String>,
    pub expires_at: String,
    pub state: JobState,
    pub claims: u32,
}

#[derive(Debug, Clone)]
pub struct Recorded {
    pub method: String,
    pub path: String,
    pub authorization: Option<String>,
    pub body: Value,
}

#[derive(Default)]
pub struct ServerState {
    pub key: String,
    pub jobs: Vec<FakeJob>,
    pub requests: Vec<Recorded>,
    /// Drop the connection without answering the next N result posts.
    pub drop_results: usize,
    /// Status code forced on result posts (409, 410…).
    pub result_status: Option<u16>,
    pub get_delay: Duration,
    /// Offset added to the `Date` header (server clock skew).
    pub date_offset: i64,
    /// When set, checked on every result post: does the dedupe file contain the id?
    pub dedupe_file: Option<PathBuf>,
    pub dedupe_had_id_at_ack: Vec<bool>,
    pub pair_code: Option<String>,
    /// Written before the `GET /jobs` JSON, like the spaces of a streamed long poll.
    pub jobs_prefix: String,
    /// When set, `GET /jobs` carries `server_time_ms` = local now + this offset.
    pub server_time_offset: Option<i64>,
}

pub struct FakeServer {
    pub url: String,
    pub state: Arc<Mutex<ServerState>>,
}

pub fn now_ms() -> i64 {
    i64::try_from(SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_millis()).unwrap()
}

pub fn rfc3339(ms: i64) -> String {
    let t = time::OffsetDateTime::from_unix_timestamp_nanos(i128::from(ms) * 1_000_000).unwrap();
    t.format(&time::format_description::well_known::Rfc3339).unwrap()
}

impl FakeServer {
    pub fn start() -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://127.0.0.1:{}", listener.local_addr().unwrap().port());
        let state = Arc::new(Mutex::new(ServerState {
            key: KEY.into(),
            ..Default::default()
        }));
        let st = Arc::clone(&state);
        std::thread::spawn(move || {
            for conn in listener.incoming() {
                let Ok(conn) = conn else { continue };
                let st = Arc::clone(&st);
                std::thread::spawn(move || handle(conn, &st));
            }
        });
        Self { url, state }
    }

    pub fn st(&self) -> std::sync::MutexGuard<'_, ServerState> {
        self.state.lock().unwrap()
    }

    pub fn add_job(&self, id: u64, kind: &str, target: Option<&str>, payload: &[u8], expires_in_ms: i64) {
        self.st().jobs.push(FakeJob {
            id,
            kind: kind.into(),
            target: target.map(Into::into),
            payload: payload.to_vec(),
            sha_override: None,
            expires_at: rfc3339(now_ms() + expires_in_ms),
            state: JobState::Pending,
            claims: 0,
        });
    }

    /// Simulates the 60 s lease running out: a claimed job is offered again.
    pub fn release(&self, id: u64) {
        for j in &mut self.st().jobs {
            if j.id == id && matches!(j.state, JobState::Claimed(_)) {
                j.state = JobState::Pending;
            }
        }
    }

    pub fn job(&self, id: u64) -> FakeJob {
        self.st().jobs.iter().find(|j| j.id == id).cloned().unwrap()
    }

    pub fn requests(&self, method: &str, path_prefix: &str) -> Vec<Recorded> {
        self.st()
            .requests
            .iter()
            .filter(|r| r.method == method && r.path.starts_with(path_prefix))
            .cloned()
            .collect()
    }
}

fn handle(mut conn: TcpStream, st: &Arc<Mutex<ServerState>>) {
    let mut reader = BufReader::new(conn.try_clone().unwrap());
    let mut first = String::new();
    if reader.read_line(&mut first).is_err() {
        return;
    }
    let mut parts = first.split_whitespace();
    let method = parts.next().unwrap_or("").to_owned();
    let path = parts.next().unwrap_or("").to_owned();
    let mut len = 0usize;
    let mut auth = None;
    loop {
        let mut line = String::new();
        if reader.read_line(&mut line).is_err() || line == "\r\n" || line.is_empty() {
            break;
        }
        let (k, v) = line.split_once(':').unwrap_or(("", ""));
        let v = v.trim().to_owned();
        match k.to_ascii_lowercase().as_str() {
            "content-length" => len = v.parse().unwrap_or(0),
            "authorization" => auth = Some(v),
            _ => {}
        }
    }
    let mut body = vec![0; len];
    let _ = reader.read_exact(&mut body);
    let body: Value = serde_json::from_slice(&body).unwrap_or(Value::Null);
    let (status, json_body, delay, drop_conn, date_offset) = {
        let mut s = st.lock().unwrap();
        s.requests.push(Recorded {
            method: method.clone(),
            path: path.clone(),
            authorization: auth.clone(),
            body: body.clone(),
        });
        let (status, b, drop_conn) = route(&mut s, &method, &path, auth.as_deref(), &body);
        let delay = if method == "GET" { s.get_delay } else { Duration::ZERO };
        (status, b, delay, drop_conn, s.date_offset)
    };
    if drop_conn {
        let _ = conn.shutdown(std::net::Shutdown::Both);
        return;
    }
    std::thread::sleep(delay);
    let date =
        httpdate::fmt_http_date(UNIX_EPOCH + Duration::from_millis(u64::try_from(now_ms() + date_offset).unwrap()));
    let mut text = json_body.map(|b| b.to_string()).unwrap_or_default();
    if method == "GET" && path.starts_with("/api/agent/v1/jobs") && status == 200 {
        let s = st.lock().unwrap();
        if let Some(offset) = s.server_time_offset {
            let mut v: Value = serde_json::from_str(&text).unwrap();
            v["server_time_ms"] = json!(now_ms() + offset);
            text = v.to_string();
        }
        text = format!("{}{text}", s.jobs_prefix);
    }
    let reason = match status {
        200 => "OK",
        201 => "Created",
        204 => "No Content",
        _ => "X",
    };
    let resp = format!(
        "HTTP/1.1 {status} {reason}\r\nDate: {date}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{text}",
        text.len()
    );
    let _ = conn.write_all(resp.as_bytes());
}

fn route(
    s: &mut ServerState,
    method: &str,
    path: &str,
    auth: Option<&str>,
    body: &Value,
) -> (u16, Option<Value>, bool) {
    if path == "/api/agent/v1/pair" && method == "POST" {
        let ok = s.pair_code.as_deref().is_some() && body["code"].as_str() == s.pair_code.as_deref();
        if ok {
            s.pair_code = None;
            return (
                201,
                Some(json!({"key": s.key, "terminal_id": 3, "terminal_name": "Caja 3"})),
                false,
            );
        }
        return (422, Some(json!({"error": "pairing_code_invalid"})), false);
    }
    if auth != Some(format!("Bearer {}", s.key).as_str()) {
        return (401, Some(json!({"error": "agent_key_invalid"})), false);
    }
    if method == "GET" && path.starts_with("/api/agent/v1/jobs") {
        let mut out = Vec::new();
        for j in &mut s.jobs {
            if j.state == JobState::Pending {
                j.claims += 1;
                let token = format!("claim-{}-{}", j.id, j.claims);
                j.state = JobState::Claimed(token.clone());
                let sha = j
                    .sha_override
                    .clone()
                    .unwrap_or_else(|| hex(&Sha256::digest(&j.payload)));
                out.push(json!({
                    "id": j.id, "type": j.kind, "target": j.target,
                    "payload_b64": base64::engine::general_purpose::STANDARD.encode(&j.payload),
                    "sha256": sha, "claim_token": token, "expires_at": j.expires_at,
                }));
            }
        }
        return (200, Some(json!({"jobs": out, "poll_after_ms": 0})), false);
    }
    if method == "PUT" && path == "/api/agent/v1/status" {
        return (204, None, false);
    }
    let Some(rest) = path.strip_prefix("/api/agent/v1/jobs/") else {
        return (404, None, false);
    };
    let (id, action) = rest.split_once('/').unwrap_or((rest, ""));
    let id: u64 = id.parse().unwrap_or(0);
    let token = body["claim_token"].as_str().unwrap_or("").to_owned();
    if action == "result" {
        if let Some(f) = s.dedupe_file.clone() {
            let text = std::fs::read_to_string(f).unwrap_or_default();
            s.dedupe_had_id_at_ack.push(text.contains(&format!("\"{id}\"")));
        }
        if s.drop_results > 0 {
            s.drop_results -= 1;
            return (0, None, true);
        }
        if let Some(code) = s.result_status {
            return (
                code,
                Some(json!({"error": if code == 409 {"claim_stale"} else {"job_expired"}})),
                false,
            );
        }
    }
    let Some(job) = s.jobs.iter_mut().find(|j| j.id == id) else {
        return (404, None, false);
    };
    match action {
        "result" => {
            let status = body["status"].as_str().unwrap_or("");
            match &job.state {
                JobState::Claimed(t) if *t == token => {
                    job.state = if status == "done" {
                        JobState::Done(token)
                    } else {
                        JobState::Failed(token)
                    };
                    (200, Some(json!({"status": status})), false)
                }
                JobState::Done(t) | JobState::Failed(t) if *t == token => (200, Some(json!({"status": status})), false),
                _ => (409, Some(json!({"error": "claim_stale"})), false),
            }
        }
        "extend" => match &job.state {
            JobState::Claimed(t) if *t == token => {
                (200, Some(json!({"lease_until": rfc3339(now_ms() + 60_000)})), false)
            }
            _ => (409, Some(json!({"error": "claim_stale"})), false),
        },
        _ => (404, None, false),
    }
}

pub fn hex(b: &[u8]) -> String {
    b.iter().map(|x| format!("{x:02x}")).collect()
}

#[derive(Debug, Clone)]
pub struct Write_ {
    pub dest: Destination,
    pub title: String,
    pub data: Vec<u8>,
}

#[derive(Default)]
pub struct MockPrinter {
    pub writes: Mutex<Vec<Write_>>,
    pub fail_next: Mutex<VecDeque<PrintError>>,
    pub delay: Mutex<Duration>,
    pub status: Mutex<QueueStatus>,
    pub cancels: Mutex<Vec<SpoolRef>>,
    pub spool: Mutex<Option<SpoolRef>>,
}

impl MockPrinter {
    pub fn new() -> Arc<Self> {
        let p = Self::default();
        *p.status.lock().unwrap() = QueueStatus::new(PrinterState::Ready, "");
        Arc::new(p)
    }

    pub fn writes(&self) -> Vec<Write_> {
        self.writes.lock().unwrap().clone()
    }
}

impl PrinterBackend for MockPrinter {
    fn write_raw(&self, dest: &Destination, title: &str, data: &[u8]) -> Result<Option<SpoolRef>, PrintError> {
        let d = *self.delay.lock().unwrap();
        std::thread::sleep(d);
        if let Some(e) = self.fail_next.lock().unwrap().pop_front() {
            return Err(e);
        }
        self.writes.lock().unwrap().push(Write_ {
            dest: dest.clone(),
            title: title.into(),
            data: data.to_vec(),
        });
        Ok(self.spool.lock().unwrap().clone())
    }

    fn queue_status(&self, _dest: &Destination) -> QueueStatus {
        self.status.lock().unwrap().clone()
    }

    fn cancel(&self, _dest: &Destination, spool: &SpoolRef) {
        self.cancels.lock().unwrap().push(spool.clone());
    }

    fn list(&self) -> Vec<InstalledPrinter> {
        Vec::new()
    }
}

pub fn temp_dir(name: &str) -> PathBuf {
    let d = std::env::temp_dir().join(format!("venpos-it-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&d);
    std::fs::create_dir_all(&d).unwrap();
    d
}
