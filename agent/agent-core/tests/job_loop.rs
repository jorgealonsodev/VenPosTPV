//! Job loop against a fake server implementing the agent API v1 table.
mod support;

use agent_core::api::{ApiClient, ApiError, HttpTiming, ProxyMode};
use agent_core::clock::{Clock, FakeClock, SystemClock};
use agent_core::config::{Config, Destination, Destinations};
use agent_core::dedupe::DedupeStore;
use agent_core::job_loop::{Outcome, Runner, RunnerDeps, Shutdown, Timing};
use agent_core::printer::{PrintError, PrinterState, QueueStatus};
use agent_core::secret::AgentKey;
use agent_core::state::{AgentStatus, Connection, SharedState};
use std::path::{Path, PathBuf};
use std::sync::atomic::AtomicBool;
use std::sync::{Arc, Mutex, RwLock};
use std::time::Duration;
use support::*;

fn ticket_dest() -> Destination {
    Destination::Cups {
        queue: "TICKETS".into(),
    }
}

fn label_dest() -> Destination {
    Destination::Network {
        address: "10.0.0.9:9100".into(),
    }
}

struct Rig {
    runner: Runner,
    state: SharedState,
}

fn rig(server: &FakeServer, printer: Arc<MockPrinter>, clock: Arc<dyn Clock>, dir: &Path, timing: Timing) -> Rig {
    let key = AgentKey::parse(KEY).unwrap();
    let api = ApiClient::new(&server.url, Some(key), &HttpTiming::default(), ProxyMode::None).unwrap();
    let state: SharedState = Arc::new(Mutex::new(AgentStatus::default()));
    let config = Arc::new(RwLock::new(Config {
        server_url: Some(server.url.clone()),
        terminal_id: Some(3),
        terminal_name: Some("Caja 3".into()),
        destinations: Destinations {
            ticket: Some(ticket_dest()),
            label: Some(label_dest()),
        },
    }));
    let runner = Runner::new(RunnerDeps {
        api,
        printer,
        dedupe: DedupeStore::open(dedupe_path(dir), clock.now_unix_ms()),
        clock,
        config,
        state: Arc::clone(&state),
        timing,
        hostname: "PC-CAJA3".into(),
        key_store: None,
        status_dirty: Arc::new(AtomicBool::new(false)),
    });
    Rig { runner, state }
}

fn dedupe_path(dir: &Path) -> PathBuf {
    dir.join("printed.json")
}

fn sys() -> Arc<dyn Clock> {
    Arc::new(SystemClock::new())
}

#[test]
fn ticket_is_printed_to_the_ticket_destination_and_acknowledged() {
    let server = FakeServer::start();
    let dir = temp_dir("print-ack");
    server.st().dedupe_file = Some(dedupe_path(&dir));
    let printer = MockPrinter::new();
    server.add_job(11, "ticket", Some("ticket"), b"\x1b@HOLA\n", 120_000);
    server.add_job(12, "label", Some("label"), b"LABEL", 86_400_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    let summary = r.runner.poll_once().unwrap();

    assert_eq!(
        summary.outcomes.iter().map(|(_, o)| o.clone()).collect::<Vec<_>>(),
        [Outcome::Printed, Outcome::Printed]
    );
    let writes = printer.writes();
    assert_eq!(writes[0].data, b"\x1b@HOLA\n");
    assert_eq!(writes[0].dest, ticket_dest());
    assert_eq!(writes[1].dest, label_dest());
    assert_eq!(server.job(11).state, JobState::Done("claim-11-1".into()));
    assert_eq!(server.job(12).state, JobState::Done("claim-12-1".into()));
    // Persisted before the acknowledgement left (T4).
    assert_eq!(server.st().dedupe_had_id_at_ack, [true, true]);
    // Long poll asks for wait=20 and authenticates with the bearer key.
    let gets = server.requests("GET", "/api/agent/v1/jobs");
    assert_eq!(gets[0].path, "/api/agent/v1/jobs?wait=20");
    assert_eq!(gets[0].authorization.as_deref(), Some(format!("Bearer {KEY}").as_str()));
    let results = server.requests("POST", "/api/agent/v1/jobs/11/result");
    assert_eq!(
        results[0].body,
        serde_json::json!({"claim_token": "claim-11-1", "status": "done"})
    );
}

#[test]
fn printer_failure_is_reported_as_failed_with_code_and_message() {
    let server = FakeServer::start();
    let dir = temp_dir("failed");
    let printer = MockPrinter::new();
    printer
        .fail_next
        .lock()
        .unwrap()
        .push_back(PrintError::new("spooler_rejected", "lp: paused"));
    server.add_job(21, "ticket", Some("ticket"), b"X", 120_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();

    assert_eq!(server.job(21).state, JobState::Failed("claim-21-1".into()));
    let body = &server.requests("POST", "/api/agent/v1/jobs/21/result")[0].body;
    assert_eq!(body["status"], "failed");
    assert_eq!(body["error_code"], "spooler_rejected");
    assert_eq!(body["error"], "lp: paused");
    assert!(printer.writes().is_empty());
    // Failed jobs are not remembered: the server's retry (T5) prints them.
    assert!(!DedupeStore::open(dedupe_path(&dir), now_ms()).contains("21", now_ms()));
}

#[test]
fn expired_job_is_never_printed() {
    let server = FakeServer::start();
    let dir = temp_dir("expired");
    let printer = MockPrinter::new();
    server.add_job(31, "drawer", Some("ticket"), b"", -1_000);
    server.add_job(32, "ticket", Some("ticket"), b"OLD", -60_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    let summary = r.runner.poll_once().unwrap();

    assert!(printer.writes().is_empty());
    assert!(summary.outcomes.iter().all(|(_, o)| *o == Outcome::Expired));
    let body = &server.requests("POST", "/api/agent/v1/jobs/32/result")[0].body;
    assert_eq!(body["status"], "failed");
    assert_eq!(body["error_code"], "job_expired");
}

#[test]
fn expiry_uses_the_server_clock_from_the_date_header() {
    let server = FakeServer::start();
    let dir = temp_dir("skew");
    let printer = MockPrinter::new();
    // Server clock is one hour ahead; the job expires in 30 min of *local* time,
    // i.e. it already expired 30 min ago on the server.
    server.st().date_offset = 3_600_000;
    server.add_job(1, "ticket", Some("ticket"), b"warm-up", 7_200_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());
    r.runner.poll_once().unwrap();
    server.add_job(33, "ticket", Some("ticket"), b"X", 1_800_000);

    let summary = r.runner.poll_once().unwrap();

    assert_eq!(summary.outcomes[0].1, Outcome::Expired);
    assert_eq!(printer.writes().len(), 1, "only the warm-up job");
}

#[test]
fn network_cut_after_printing_gives_a_single_ticket() {
    // AC10: the acknowledgement is lost; the retry and the redelivery never reprint.
    let server = FakeServer::start();
    let dir = temp_dir("ac10");
    let printer = MockPrinter::new();
    server.add_job(41, "ticket", Some("ticket"), b"SALE", 120_000);
    server.st().drop_results = 1;
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    assert!(matches!(r.runner.poll_once(), Err(ApiError::Transport(_))));
    assert_eq!(r.runner.pending_acks(), 1);
    // Lease ran out meanwhile: the server offers the job again.
    server.release(41);
    let summary = r.runner.poll_once().unwrap();

    assert_eq!(printer.writes().len(), 1);
    assert_eq!(summary.outcomes[0].1, Outcome::Duplicate);
    assert_eq!(server.job(41).state, JobState::Done("claim-41-2".into()));
    assert_eq!(r.runner.pending_acks(), 0);
}

#[test]
fn restart_does_not_repeat_printed_jobs() {
    // AC11: the process dies after printing and before acknowledging.
    let server = FakeServer::start();
    let dir = temp_dir("ac11");
    let printer = MockPrinter::new();
    server.add_job(51, "ticket", Some("ticket"), b"SALE", 120_000);
    server.st().drop_results = 1;
    {
        let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());
        let _ = r.runner.poll_once();
    }
    server.release(51);
    let mut restarted = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    let summary = restarted.runner.poll_once().unwrap();

    assert_eq!(printer.writes().len(), 1);
    assert_eq!(summary.outcomes[0].1, Outcome::Duplicate);
    assert_eq!(server.job(51).state, JobState::Done("claim-51-2".into()));
}

#[test]
fn drawer_pulse_is_generated_locally_and_payload_ignored() {
    let server = FakeServer::start();
    let dir = temp_dir("drawer");
    let printer = MockPrinter::new();
    server.add_job(61, "drawer", Some("ticket"), b"rm -rf / ; \x1b\x70\x01\xff\xff", 15_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();

    let w = printer.writes();
    assert_eq!(w.len(), 1);
    assert_eq!(w[0].data, [0x1B, 0x70, 0x00, 0x19, 0xFA]);
    assert_eq!(w[0].dest, ticket_dest());
}

#[test]
fn drawer_opens_at_most_once_every_5_seconds() {
    let server = FakeServer::start();
    let dir = temp_dir("drawer-rate");
    let printer = MockPrinter::new();
    let clock = Arc::new(FakeClock::new(now_ms()));
    server.add_job(71, "drawer", None, b"", 15_000);
    server.add_job(72, "drawer", None, b"", 15_000);
    let mut r = rig(&server, Arc::clone(&printer), clock.clone(), &dir, Timing::default());

    let first = r.runner.poll_once().unwrap();
    assert_eq!(first.outcomes[0].1, Outcome::Printed);
    assert!(matches!(&first.outcomes[1].1, Outcome::Failed { code, .. } if code == "drawer_rate_limited"));
    clock.advance(Duration::from_secs(5));
    server.add_job(73, "drawer", None, b"", 15_000);
    let later = r.runner.poll_once().unwrap();

    assert_eq!(later.outcomes[0].1, Outcome::Printed);
    assert_eq!(printer.writes().len(), 2);
}

#[test]
fn stale_drawer_pulse_left_in_the_queue_is_cancelled_after_expiry() {
    let server = FakeServer::start();
    let dir = temp_dir("drawer-cancel");
    let printer = MockPrinter::new();
    *printer.spool.lock().unwrap() = Some(agent_core::printer::SpoolRef::Cups("TICKETS-9".into()));
    server.add_job(74, "drawer", None, b"", 300);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();
    std::thread::sleep(Duration::from_millis(1_500));

    assert_eq!(
        *printer.cancels.lock().unwrap(),
        [agent_core::printer::SpoolRef::Cups("TICKETS-9".into())]
    );
}

#[test]
fn sha256_mismatch_is_failed_and_not_printed() {
    let server = FakeServer::start();
    let dir = temp_dir("sha");
    let printer = MockPrinter::new();
    server.add_job(81, "ticket", Some("ticket"), b"REAL", 120_000);
    server.st().jobs[0].sha_override = Some(hex(&[0u8; 32]));
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();

    assert!(printer.writes().is_empty());
    assert_eq!(
        server.requests("POST", "/api/agent/v1/jobs/81/result")[0].body["error_code"],
        "checksum_mismatch"
    );
}

#[test]
fn uppercase_sha256_is_accepted() {
    let server = FakeServer::start();
    let dir = temp_dir("sha-upper");
    let printer = MockPrinter::new();
    server.add_job(82, "ticket", None, b"REAL", 120_000);
    let upper = hex(&<sha2::Sha256 as sha2::Digest>::digest(b"REAL")).to_uppercase();
    server.st().jobs[0].sha_override = Some(upper);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();

    assert_eq!(printer.writes().len(), 1);
}

#[test]
fn rejected_key_stops_polling_and_shows_the_reconnect_message() {
    let server = FakeServer::start();
    let dir = temp_dir("401");
    server.st().key = "another_key_0000000000000000000000".into();
    let printer = MockPrinter::new();
    let timing = Timing {
        key_rejected_retry: Duration::from_secs(30),
        ..Timing::default()
    };
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, timing);
    assert!(matches!(r.runner.poll_once(), Err(ApiError::Unauthorized)));

    let shutdown = Shutdown::new();
    let s2 = Arc::clone(&shutdown);
    let state = Arc::clone(&r.state);
    let h = std::thread::spawn(move || {
        r.runner.run(&s2);
    });
    std::thread::sleep(Duration::from_millis(500));
    shutdown.stop();
    h.join().unwrap();

    let st = state.lock().unwrap().clone();
    assert_eq!(st.connection, Connection::KeyRejected);
    assert_eq!(
        st.message.as_deref(),
        Some("Vuelve a conectar el agente desde Ajustes → Cajas")
    );
    // One GET from poll_once and one from run(); then it waits 30 s instead of hammering.
    assert_eq!(server.requests("GET", "/api/agent/v1/jobs").len(), 2);
}

#[test]
fn claim_stale_and_job_expired_acks_are_dropped_not_retried() {
    for code in [409u16, 410] {
        let server = FakeServer::start();
        let dir = temp_dir(&format!("ack-{code}"));
        let printer = MockPrinter::new();
        server.add_job(91, "ticket", None, b"X", 120_000);
        server.st().result_status = Some(code);
        let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

        let summary = r.runner.poll_once().unwrap();

        assert_eq!(summary.outcomes[0].1, Outcome::Printed);
        assert_eq!(r.runner.pending_acks(), 0, "HTTP {code}");
        r.runner.poll_once().unwrap();
        assert_eq!(
            server.requests("POST", "/api/agent/v1/jobs/91/result").len(),
            1,
            "HTTP {code}"
        );
    }
}

#[test]
fn long_jobs_extend_their_lease() {
    let server = FakeServer::start();
    let dir = temp_dir("extend");
    let printer = MockPrinter::new();
    *printer.delay.lock().unwrap() = Duration::from_millis(450);
    server.add_job(101, "label", Some("label"), b"L1", 86_400_000);
    server.add_job(102, "label", Some("label"), b"L2", 86_400_000);
    let timing = Timing {
        lease_extend_every: Duration::from_millis(200),
        ..Timing::default()
    };
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, timing);

    r.runner.poll_once().unwrap();

    let ext102 = server.requests("POST", "/api/agent/v1/jobs/102/extend");
    assert!(
        ext102.len() >= 2,
        "102 waits behind 101 and must be extended: {}",
        ext102.len()
    );
    assert_eq!(ext102[0].body, serde_json::json!({"claim_token": "claim-102-1"}));
    // A finished job is no longer extended.
    let ext101 = server.requests("POST", "/api/agent/v1/jobs/101/extend").len();
    assert!(ext101 <= 3, "{ext101}");
    assert_eq!(server.job(102).state, JobState::Done("claim-102-1".into()));
}

#[test]
fn slow_request_drops_the_long_poll_to_5_seconds() {
    let server = FakeServer::start();
    let dir = temp_dir("fallback");
    let printer = MockPrinter::new();
    server.st().get_delay = Duration::from_millis(400);
    let timing = Timing {
        slow_request: Duration::from_millis(250),
        ..Timing::default()
    };
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, timing);

    r.runner.poll_once().unwrap();
    assert_eq!(r.runner.current_wait(), 5);
    server.st().get_delay = Duration::ZERO;
    r.runner.poll_once().unwrap();

    let gets = server.requests("GET", "/api/agent/v1/jobs");
    assert_eq!(gets[0].path, "/api/agent/v1/jobs?wait=20");
    assert_eq!(gets[1].path, "/api/agent/v1/jobs?wait=5");
}

#[test]
fn status_is_sent_on_start_and_only_when_it_changes() {
    let server = FakeServer::start();
    let dir = temp_dir("status");
    let printer = MockPrinter::new();
    let timing = Timing {
        status_check_every: Duration::ZERO,
        ..Timing::default()
    };
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, timing);

    assert!(r.runner.report_status_if_due().unwrap());
    assert!(!r.runner.report_status_if_due().unwrap());
    *printer.status.lock().unwrap() = QueueStatus::new(PrinterState::Paused, "en pausa");
    assert!(r.runner.report_status_if_due().unwrap());

    let puts = server.requests("PUT", "/api/agent/v1/status");
    assert_eq!(puts.len(), 2);
    assert_eq!(puts[0].body["version"], agent_core::VERSION);
    assert_eq!(puts[0].body["hostname"], "PC-CAJA3");
    assert_eq!(
        puts[1].body["printers"][0],
        serde_json::json!({"target": "ticket", "name": "TICKETS", "state": "paused", "detail": "en pausa"})
    );
    assert_eq!(
        r.state.lock().unwrap().printers["ticket"].status.state,
        PrinterState::Paused
    );
}

#[test]
fn missing_destination_and_unknown_type_fail_cleanly() {
    let server = FakeServer::start();
    let dir = temp_dir("unknown");
    let printer = MockPrinter::new();
    server.add_job(111, "fax", None, b"X", 120_000);
    server.add_job(112, "ticket", Some("printer_on_server"), b"X", 120_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();

    assert!(printer.writes().is_empty());
    assert_eq!(
        server.requests("POST", "/api/agent/v1/jobs/111/result")[0].body["error_code"],
        "unsupported_job_type"
    );
    assert_eq!(
        server.requests("POST", "/api/agent/v1/jobs/112/result")[0].body["error_code"],
        "unknown_target"
    );
}

#[test]
fn oversized_payload_is_rejected() {
    let server = FakeServer::start();
    let dir = temp_dir("big");
    let printer = MockPrinter::new();
    server.add_job(121, "label", None, &vec![b'A'; 1024 * 1024 + 1], 86_400_000);
    let mut r = rig(&server, Arc::clone(&printer), sys(), &dir, Timing::default());

    r.runner.poll_once().unwrap();

    assert!(printer.writes().is_empty());
    assert_eq!(
        server.requests("POST", "/api/agent/v1/jobs/121/result")[0].body["error_code"],
        "payload_too_large"
    );
}

#[test]
fn pairing_returns_the_key_and_rejects_bad_codes() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let api = ApiClient::new(&server.url, None, &HttpTiming::default(), ProxyMode::None).unwrap();
    let bad = agent_core::secret::PairingCode::parse("000000").unwrap();
    assert!(matches!(api.pair(&bad, "PC-CAJA3"), Err(ApiError::PairingCodeInvalid)));
    let good = agent_core::secret::PairingCode::parse("482913").unwrap();

    let resp = api.pair(&good, "PC-CAJA3").unwrap();

    assert_eq!(resp.key, KEY);
    assert_eq!(resp.terminal_id, 3);
    let body = &server.requests("POST", "/api/agent/v1/pair")[1].body;
    assert_eq!(body["code"], "482913");
    assert_eq!(body["hostname"], "PC-CAJA3");
    assert_eq!(body["os"], agent_core::OS);
    assert_eq!(body["version"], agent_core::VERSION);
    // Single use (server side).
    assert!(matches!(api.pair(&good, "PC-CAJA3"), Err(ApiError::PairingCodeInvalid)));
}
