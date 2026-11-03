//! Client of the agent API v1 (PRD §6 table). Blocking `ureq` over rustls
//! with the platform verifier (E11, A10); no redirects are followed, so the
//! key can never be sent to another origin or over plain HTTP.
use crate::secret::{AgentKey, PairingCode};
use serde::{Deserialize, Deserializer, Serialize};
use std::fmt;
use std::time::Duration;
use ureq::Agent;

/// Largest `GET /jobs` body accepted (several 1 MB payloads in base64).
pub const MAX_JOBS_BODY: u64 = 16 * 1024 * 1024;
const MAX_SMALL_BODY: u64 = 64 * 1024;

#[derive(Debug, Clone)]
pub struct HttpTiming {
    pub connect: Duration,
    /// A7: client read timeout 40 s (server waits up to 20 s).
    pub read: Duration,
}

impl Default for HttpTiming {
    fn default() -> Self {
        Self {
            connect: Duration::from_secs(10),
            read: Duration::from_secs(40),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ProxyMode {
    /// `HTTPS_PROXY`/`ALL_PROXY`/`HTTP_PROXY` + `NO_PROXY`; on Windows also the
    /// WinINet proxy of the account running the agent.
    System,
    None,
}

#[derive(Debug, thiserror::Error)]
pub enum ApiError {
    #[error("{}", crate::MSG_KEY_REJECTED)]
    Unauthorized,
    #[error("el TPV exige una versión más nueva del agente")]
    UpdateRequired,
    #[error("demasiadas peticiones; se reintentará")]
    RateLimited { retry_after: Option<Duration> },
    #[error("código de emparejamiento no válido, caducado o ya usado")]
    PairingCodeInvalid,
    #[error("la recogida del trabajo ya no es válida")]
    ClaimStale,
    #[error("el trabajo ha caducado")]
    JobExpired,
    #[error("el trabajo no existe")]
    NotFound,
    #[error("respuesta inesperada del TPV: HTTP {status} {}", code.as_deref().unwrap_or(""))]
    Status { status: u16, code: Option<String> },
    #[error("sin conexión con el TPV: {0}")]
    Transport(String),
    #[error("respuesta del TPV no válida: {0}")]
    Decode(String),
}

impl ApiError {
    pub fn is_connectivity(&self) -> bool {
        matches!(
            self,
            ApiError::Transport(_) | ApiError::Status { status: 500..=599, .. }
        )
    }
}

/// Job id as a path-safe string (the server may send a number or a string).
#[derive(Debug, Clone, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize)]
pub struct JobId(String);

impl JobId {
    pub fn as_str(&self) -> &str {
        &self.0
    }

    pub fn new(s: &str) -> Option<Self> {
        (!s.is_empty() && s.len() <= 64 && s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_'))
            .then(|| Self(s.to_owned()))
    }
}

impl fmt::Display for JobId {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl<'de> Deserialize<'de> for JobId {
    fn deserialize<D: Deserializer<'de>>(d: D) -> Result<Self, D::Error> {
        let v = serde_json::Value::deserialize(d)?;
        let s = match v {
            serde_json::Value::Number(n) => n.to_string(),
            serde_json::Value::String(s) => s,
            _ => return Err(serde::de::Error::custom("id must be a number or a string")),
        };
        JobId::new(&s).ok_or_else(|| serde::de::Error::custom("invalid job id"))
    }
}

/// Lease token of one claim (T4). Not a credential, but kept out of logs anyway.
#[derive(Clone, PartialEq, Eq, Deserialize, Serialize)]
#[serde(transparent)]
pub struct ClaimToken(String);

impl ClaimToken {
    pub fn new(s: impl Into<String>) -> Self {
        Self(s.into())
    }
}

impl fmt::Debug for ClaimToken {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("ClaimToken(…)")
    }
}

/// `expires_at`: RFC 3339 (PHP `DATE_ATOM`) or Unix seconds.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Timestamp {
    pub unix_ms: i64,
}

impl<'de> Deserialize<'de> for Timestamp {
    fn deserialize<D: Deserializer<'de>>(d: D) -> Result<Self, D::Error> {
        let v = serde_json::Value::deserialize(d)?;
        match v {
            serde_json::Value::Number(n) => n
                .as_i64()
                .and_then(|s| s.checked_mul(1000))
                .map(|unix_ms| Timestamp { unix_ms })
                .ok_or_else(|| serde::de::Error::custom("invalid unix time")),
            serde_json::Value::String(s) => parse_rfc3339_ms(&s)
                .map(|unix_ms| Timestamp { unix_ms })
                .ok_or_else(|| serde::de::Error::custom("invalid RFC 3339 time")),
            _ => Err(serde::de::Error::custom("invalid time")),
        }
    }
}

pub fn parse_rfc3339_ms(s: &str) -> Option<i64> {
    let t = time::OffsetDateTime::parse(s, &time::format_description::well_known::Rfc3339).ok()?;
    i64::try_from(t.unix_timestamp_nanos() / 1_000_000).ok()
}

#[derive(Debug, Clone, Deserialize)]
pub struct Job {
    pub id: JobId,
    #[serde(rename = "type")]
    pub job_type: String,
    #[serde(default)]
    pub target: Option<String>,
    #[serde(default)]
    pub payload_b64: Option<String>,
    #[serde(default)]
    pub sha256: Option<String>,
    pub claim_token: ClaimToken,
    #[serde(default)]
    pub expires_at: Option<Timestamp>,
}

#[derive(Debug, Clone, Deserialize)]
struct JobsBody {
    #[serde(default)]
    jobs: Vec<serde_json::Value>,
    #[serde(default)]
    poll_after_ms: Option<u64>,
}

#[derive(Debug, Clone)]
pub struct JobsResponse {
    pub jobs: Vec<Job>,
    /// Entries that did not parse; logged and left to expire on the server.
    pub malformed: usize,
    pub poll_after_ms: Option<u64>,
    /// Server `Date` header, used to correct clock skew for `expires_at`.
    pub server_date_ms: Option<i64>,
}

#[derive(Clone, Deserialize)]
pub struct PairResponse {
    pub key: String,
    pub terminal_id: i64,
    pub terminal_name: String,
}

impl fmt::Debug for PairResponse {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("PairResponse")
            .field("key", &"****")
            .field("terminal_id", &self.terminal_id)
            .field("terminal_name", &self.terminal_name)
            .finish()
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum ResultStatus {
    Done,
    Failed,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct JobResult {
    pub status: ResultStatus,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error_code: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<String>,
}

impl JobResult {
    pub fn done() -> Self {
        Self {
            status: ResultStatus::Done,
            error_code: None,
            error: None,
        }
    }

    pub fn failed(code: &str, error: &str) -> Self {
        Self {
            status: ResultStatus::Failed,
            error_code: Some(code.to_owned()),
            error: Some(crate::truncate_chars(error, 500)),
        }
    }
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct PrinterStatusBody {
    pub target: String,
    pub name: String,
    pub state: crate::printer::PrinterState,
    pub detail: String,
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct StatusBody {
    pub version: String,
    pub hostname: String,
    pub printers: Vec<PrinterStatusBody>,
}

#[derive(Clone)]
pub struct ApiClient {
    agent: Agent,
    base: String,
    key: Option<AgentKey>,
}

impl fmt::Debug for ApiClient {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ApiClient")
            .field("base", &self.base)
            .field("key", &self.key.as_ref().map(|_| "****"))
            .finish()
    }
}

#[derive(Serialize)]
struct PairBody<'a> {
    code: &'a str,
    hostname: &'a str,
    os: &'a str,
    version: &'a str,
}

#[derive(Serialize)]
struct ResultBody<'a> {
    claim_token: &'a ClaimToken,
    #[serde(flatten)]
    result: &'a JobResult,
}

#[derive(Serialize)]
struct ExtendBody<'a> {
    claim_token: &'a ClaimToken,
}

impl ApiClient {
    /// `base` must already be validated by [`crate::config::validate_server_url`].
    pub fn new(base: &str, key: Option<AgentKey>, timing: &HttpTiming, proxy: ProxyMode) -> Result<Self, ApiError> {
        let base = crate::config::validate_server_url(base).map_err(|e| ApiError::Decode(e.to_string()))?;
        let tls = ureq::tls::TlsConfig::builder()
            .provider(ureq::tls::TlsProvider::Rustls)
            .root_certs(ureq::tls::RootCerts::PlatformVerifier)
            .build();
        let mut cfg = Agent::config_builder()
            .tls_config(tls)
            .http_status_as_error(false)
            .max_redirects(0)
            .timeout_connect(Some(timing.connect))
            .timeout_recv_response(Some(timing.read))
            .timeout_recv_body(Some(timing.read))
            .user_agent(format!("venpos-agent/{}", crate::VERSION));
        if proxy == ProxyMode::None {
            cfg = cfg.proxy(None);
        }
        Ok(Self {
            agent: cfg.build().into(),
            base,
            key,
        })
    }

    pub fn base(&self) -> &str {
        &self.base
    }

    pub fn set_key(&mut self, key: Option<AgentKey>) {
        self.key = key;
    }

    pub fn has_key(&self) -> bool {
        self.key.is_some()
    }

    fn url(&self, path: &str) -> String {
        format!("{}/api/agent/v1{path}", self.base)
    }

    fn bearer(&self) -> Result<String, ApiError> {
        self.key.as_ref().map(AgentKey::bearer).ok_or(ApiError::Unauthorized)
    }

    pub fn pair(&self, code: &PairingCode, hostname: &str) -> Result<PairResponse, ApiError> {
        let body = PairBody {
            code: code.expose(),
            hostname,
            os: crate::OS,
            version: crate::VERSION,
        };
        let resp = self
            .agent
            .post(&self.url("/pair"))
            .header("Accept", "application/json")
            .send_json(&body)
            .map_err(transport)?;
        let (status, _, text) = read(resp, MAX_SMALL_BODY)?;
        match status {
            200 | 201 => serde_json::from_str(&text).map_err(|e| ApiError::Decode(e.to_string())),
            422 => Err(ApiError::PairingCodeInvalid),
            _ => Err(common_error(status, &text, None)),
        }
    }

    pub fn get_jobs(&self, wait_secs: u8) -> Result<JobsResponse, ApiError> {
        let resp = self
            .agent
            .get(&self.url(&format!("/jobs?wait={}", wait_secs.min(20))))
            .header("Authorization", &self.bearer()?)
            .header("Accept", "application/json")
            .call()
            .map_err(transport)?;
        let retry_after = retry_after(&resp);
        let server_date_ms = resp
            .headers()
            .get("date")
            .and_then(|v| v.to_str().ok())
            .and_then(|s| httpdate::parse_http_date(s).ok())
            .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
            .and_then(|d| i64::try_from(d.as_millis()).ok());
        let (status, _, text) = read(resp, MAX_JOBS_BODY)?;
        if status != 200 {
            return Err(common_error(status, &text, retry_after));
        }
        let body: JobsBody = serde_json::from_str(&text).map_err(|e| ApiError::Decode(e.to_string()))?;
        let mut jobs = Vec::with_capacity(body.jobs.len());
        let mut malformed = 0;
        for v in body.jobs {
            match serde_json::from_value::<Job>(v) {
                Ok(j) => jobs.push(j),
                Err(_) => malformed += 1,
            }
        }
        Ok(JobsResponse {
            jobs,
            malformed,
            poll_after_ms: body.poll_after_ms,
            server_date_ms,
        })
    }

    pub fn post_result(&self, id: &JobId, claim: &ClaimToken, result: &JobResult) -> Result<(), ApiError> {
        let resp = self
            .agent
            .post(&self.url(&format!("/jobs/{id}/result")))
            .header("Authorization", &self.bearer()?)
            .header("Accept", "application/json")
            .send_json(&ResultBody {
                claim_token: claim,
                result,
            })
            .map_err(transport)?;
        let ra = retry_after(&resp);
        let (status, _, text) = read(resp, MAX_SMALL_BODY)?;
        match status {
            200 | 204 => Ok(()),
            404 => Err(ApiError::NotFound),
            409 => Err(ApiError::ClaimStale),
            410 => Err(ApiError::JobExpired),
            _ => Err(common_error(status, &text, ra)),
        }
    }

    pub fn extend(&self, id: &JobId, claim: &ClaimToken) -> Result<Option<String>, ApiError> {
        let resp = self
            .agent
            .post(&self.url(&format!("/jobs/{id}/extend")))
            .header("Authorization", &self.bearer()?)
            .header("Accept", "application/json")
            .send_json(&ExtendBody { claim_token: claim })
            .map_err(transport)?;
        let ra = retry_after(&resp);
        let (status, _, text) = read(resp, MAX_SMALL_BODY)?;
        match status {
            200 => Ok(serde_json::from_str::<serde_json::Value>(&text)
                .ok()
                .and_then(|v| v.get("lease_until").and_then(|l| l.as_str()).map(str::to_owned))),
            409 => Err(ApiError::ClaimStale),
            404 => Err(ApiError::NotFound),
            _ => Err(common_error(status, &text, ra)),
        }
    }

    pub fn put_status(&self, body: &StatusBody) -> Result<(), ApiError> {
        let resp = self
            .agent
            .put(&self.url("/status"))
            .header("Authorization", &self.bearer()?)
            .header("Accept", "application/json")
            .send_json(body)
            .map_err(transport)?;
        let ra = retry_after(&resp);
        let (status, _, text) = read(resp, MAX_SMALL_BODY)?;
        match status {
            200 | 204 => Ok(()),
            _ => Err(common_error(status, &text, ra)),
        }
    }
}

fn transport(e: ureq::Error) -> ApiError {
    ApiError::Transport(e.to_string())
}

fn retry_after(resp: &ureq::http::Response<ureq::Body>) -> Option<Duration> {
    resp.headers()
        .get("retry-after")
        .and_then(|v| v.to_str().ok())
        .and_then(|s| s.trim().parse::<u64>().ok())
        .map(|s| Duration::from_secs(s.min(300)))
}

fn read(mut resp: ureq::http::Response<ureq::Body>, limit: u64) -> Result<(u16, (), String), ApiError> {
    let status = resp.status().as_u16();
    let text = if status == 204 {
        String::new()
    } else {
        resp.body_mut()
            .with_config()
            .limit(limit)
            .read_to_string()
            .map_err(transport)?
    };
    Ok((status, (), text))
}

/// Error code from `{"error": "..."}` (house style), `{"code"}` or `{"error_code"}`.
pub fn error_code(text: &str) -> Option<String> {
    let v: serde_json::Value = serde_json::from_str(text).ok()?;
    ["error", "code", "error_code"]
        .iter()
        .find_map(|k| v.get(*k).and_then(|x| x.as_str()))
        .map(|s| crate::truncate_chars(s, 64))
}

fn common_error(status: u16, text: &str, retry_after: Option<Duration>) -> ApiError {
    match status {
        401 => ApiError::Unauthorized,
        426 => ApiError::UpdateRequired,
        429 => ApiError::RateLimited { retry_after },
        _ => ApiError::Status {
            status,
            code: error_code(text),
        },
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn timestamps_accept_atom_and_unix_seconds() {
        let t: Timestamp = serde_json::from_str("\"2026-11-03T10:00:00+01:00\"").unwrap();
        assert_eq!(t.unix_ms, 1_793_696_400_000);
        let z: Timestamp = serde_json::from_str("\"2026-11-03T09:00:00.500Z\"").unwrap();
        assert_eq!(z.unix_ms, 1_793_696_400_500);
        let n: Timestamp = serde_json::from_str("1793696400").unwrap();
        assert_eq!(n.unix_ms, 1_793_696_400_000);
        assert!(serde_json::from_str::<Timestamp>("\"2026-11-03 10:00:00\"").is_err());
    }

    #[test]
    fn job_ids_are_path_safe() {
        assert!(serde_json::from_str::<JobId>("17").is_ok());
        assert!(serde_json::from_str::<JobId>("\"a-1\"").is_ok());
        assert!(serde_json::from_str::<JobId>("\"../x\"").is_err());
        assert!(serde_json::from_str::<JobId>("\"1/result?x\"").is_err());
    }

    #[test]
    fn debug_of_client_and_pair_response_hides_the_key() {
        let key = AgentKey::parse("secretsecretsecretsecretsecret00").unwrap();
        let c = ApiClient::new(
            "https://tpv.example.com",
            Some(key),
            &HttpTiming::default(),
            ProxyMode::None,
        )
        .unwrap();
        let shown = format!("{c:?}");
        assert!(!shown.contains("secretsecret"), "{shown}");
        let p = PairResponse {
            key: "secretsecretsecretsecretsecret00".into(),
            terminal_id: 1,
            terminal_name: "Caja".into(),
        };
        assert!(!format!("{p:?}").contains("secretsecret"));
    }

    #[test]
    fn client_refuses_plain_http_servers() {
        assert!(ApiClient::new("http://tpv.example.com", None, &HttpTiming::default(), ProxyMode::None).is_err());
    }

    #[test]
    fn error_text_is_truncated_to_500() {
        let r = JobResult::failed("x", &"é".repeat(800));
        assert_eq!(r.error.unwrap().chars().count(), 500);
    }
}
