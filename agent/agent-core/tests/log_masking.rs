//! E8/A8: nothing secret reaches the log. Alone in its own test binary:
//! tracing caches per-callsite interest process-wide, so a parallel test
//! without a subscriber would hide these events.
mod support;

use agent_core::api::{ApiClient, HttpTiming, ProxyMode};
use agent_core::clock::SystemClock;
use agent_core::config::{Config, Destination, Destinations};
use agent_core::dedupe::DedupeStore;
use agent_core::job_loop::{Runner, RunnerDeps, Timing};
use agent_core::printer::PrintError;
use agent_core::secret::AgentKey;
use agent_core::state::AgentStatus;
use std::sync::atomic::AtomicBool;
use std::sync::{Arc, Mutex, RwLock};
use support::*;

struct Rig {
    runner: Runner,
}

fn rig(server: &FakeServer, printer: Arc<MockPrinter>, dir: &std::path::Path) -> Rig {
    let api = ApiClient::new(
        &server.url,
        Some(AgentKey::parse(KEY).unwrap()),
        &HttpTiming::default(),
        ProxyMode::None,
    )
    .unwrap();
    let runner = Runner::new(RunnerDeps {
        api,
        printer,
        dedupe: DedupeStore::open(dir.join("printed.json"), now_ms()),
        clock: Arc::new(SystemClock::new()),
        config: Arc::new(RwLock::new(Config {
            destinations: Destinations {
                ticket: Some(Destination::Cups {
                    queue: "TICKETS".into(),
                }),
                label: None,
            },
            ..Default::default()
        })),
        state: Arc::new(Mutex::new(AgentStatus::default())),
        timing: Timing::default(),
        hostname: "PC".into(),
        key_store: None,
        status_dirty: Arc::new(AtomicBool::new(false)),
    });
    Rig { runner }
}

#[test]
fn logs_never_contain_the_key() {
    use std::io::Write;
    #[derive(Clone, Default)]
    struct Buf(Arc<Mutex<Vec<u8>>>);
    impl Write for Buf {
        fn write(&mut self, b: &[u8]) -> std::io::Result<usize> {
            self.0.lock().unwrap().extend_from_slice(b);
            Ok(b.len())
        }
        fn flush(&mut self) -> std::io::Result<()> {
            Ok(())
        }
    }
    let buf = Buf::default();
    let b2 = buf.clone();
    let sub = tracing_subscriber::fmt()
        .with_max_level(tracing::Level::TRACE)
        .with_writer(move || b2.clone())
        .finish();
    tracing::subscriber::with_default(sub, || {
        let server = FakeServer::start();
        let dir = temp_dir("nolog");
        let printer = MockPrinter::new();
        printer
            .fail_next
            .lock()
            .unwrap()
            .push_back(PrintError::new("x", "boom"));
        server.add_job(131, "ticket", None, b"X", 120_000);
        server.st().drop_results = 1;
        let mut r = rig(&server, Arc::clone(&printer), &dir);
        let _ = r.runner.poll_once();
        tracing::info!(client = ?ApiClient::new(&server.url, Some(AgentKey::parse(KEY).unwrap()), &HttpTiming::default(), ProxyMode::None).unwrap(), "debug");
    });
    let text = String::from_utf8(buf.0.lock().unwrap().clone()).unwrap();
    assert!(text.contains("fallido"), "{text}");
    assert!(!text.contains(KEY), "{text}");
    assert!(!text.contains("claim-131"), "{text}");
}
