//! Assembles and runs the agent: logging, IPC listener and job loop.
use crate::{ExitCode, Failure};
use agent_core::api::{ApiClient, HttpTiming, ProxyMode};
use agent_core::clock::{Clock, SystemClock};
use agent_core::config::Config;
use agent_core::dedupe::DedupeStore;
use agent_core::ipc::{self, Handler};
use agent_core::job_loop::{Runner, RunnerDeps, Shutdown, Timing};
use agent_core::paths::Paths;
use agent_core::printer::{PrinterBackend, SystemPrinter};
use agent_core::secret::KeyStore;
use agent_core::state::{self, AgentStatus, Connection};
use std::sync::atomic::AtomicBool;
use std::sync::{Arc, Mutex, RwLock};
use std::time::Duration;

pub fn run_foreground(paths: &Paths) -> Result<(), Failure> {
    let _guard = agent_core::logging::init(&paths.log_file(), true)?;
    run(paths, &Shutdown::new())
}

/// Runs until `shutdown` is stopped. Logging must already be set up.
pub fn run(paths: &Paths, shutdown: &Shutdown) -> Result<(), Failure> {
    let printer: Arc<dyn PrinterBackend> = Arc::new(SystemPrinter);
    let state = Arc::new(Mutex::new(AgentStatus {
        version: agent_core::VERSION.into(),
        ..Default::default()
    }));
    let mut cfg = Config::load(&paths.config_file)?;
    let shared = Arc::new(RwLock::new(cfg.clone()));
    let dirty = Arc::new(AtomicBool::new(false));
    let hostname = agent_core::hostname();
    let handler = Arc::new(Handler {
        printer: Arc::clone(&printer),
        config: Arc::clone(&shared),
        config_path: paths.config_file.clone(),
        state: Arc::clone(&state),
        status_dirty: Arc::clone(&dirty),
        log_path: paths.log_file(),
        hostname: hostname.clone(),
    });
    if let Err(e) = ipc::serve(handler, &paths.ipc_name) {
        tracing::error!(error = %e, "no se pudo abrir el canal local; el icono no podrá hablar con el servicio");
    }
    // Installed but not paired yet: wait for `pair` to write the server URL.
    if cfg.server_url.is_none() {
        tracing::info!("agente sin emparejar; esperando a `venpos-agent pair`");
    }
    let server = loop {
        if let Some(s) = cfg.server_url.clone() {
            break s;
        }
        state::lock(&state).connection = Connection::NotPaired;
        if shutdown.wait(Duration::from_secs(5)) {
            return Ok(());
        }
        cfg = Config::load(&paths.config_file)?;
        *shared.write().unwrap_or_else(|e| e.into_inner()) = cfg.clone();
    };
    {
        let mut s = state::lock(&state);
        s.server_url = Some(server.clone());
        s.terminal_name = cfg.terminal_name.clone();
    }
    let key_store = KeyStore::new(paths.key_file.clone());
    let key = match key_store.load() {
        Ok(k) => k,
        Err(e) => {
            tracing::error!(error = %e, "no se puede leer la clave");
            None
        }
    };
    let api = ApiClient::new(&server, key, &HttpTiming::default(), ProxyMode::System)
        .map_err(|e| Failure::new(ExitCode::Other, e.to_string()))?;
    let clock: Arc<dyn Clock> = Arc::new(SystemClock::new());
    let dedupe = DedupeStore::open(paths.dedupe_file.clone(), clock.now_unix_ms());
    let mut runner = Runner::new(RunnerDeps {
        api,
        printer,
        clock,
        dedupe,
        config: shared,
        state,
        timing: Timing::default(),
        hostname,
        key_store: Some(key_store),
        status_dirty: dirty,
    });
    runner.run(shutdown);
    Ok(())
}
