//! Assembles and runs the agent: logging, IPC listener and job loop.
use crate::{ExitCode, Failure};
use agent_core::api::{ApiClient, HttpTiming, ProxyMode};
use agent_core::clock::{Clock, SystemClock};
use agent_core::config::{Config, SharedConfig};
use agent_core::dedupe::DedupeStore;
use agent_core::ipc::{self, Handler, Pairing};
use agent_core::job_loop::{Runner, RunnerDeps, Shutdown, Timing};
use agent_core::paths::Paths;
use agent_core::printer::{PrinterBackend, SystemPrinter};
use agent_core::secret::KeyStore;
use agent_core::state::{self, AgentStatus, Connection, SharedState};
use std::sync::atomic::AtomicBool;
use std::sync::{Arc, Mutex, RwLock};
use std::time::Duration;

pub fn run_foreground(paths: &Paths) -> Result<(), Failure> {
    let _guard = agent_core::logging::init(&paths.log_file(), true)?;
    run(paths, &Shutdown::new())
}

/// Runs until `shutdown` is stopped. Logging must already be set up. An IPC
/// `pair` restarts the job loop with the new server and key; the local
/// channel stays open throughout.
pub fn run(paths: &Paths, shutdown: &Arc<Shutdown>) -> Result<(), Failure> {
    let printer: Arc<dyn PrinterBackend> = Arc::new(SystemPrinter);
    let state = Arc::new(Mutex::new(AgentStatus {
        version: agent_core::VERSION.into(),
        ..Default::default()
    }));
    let shared = Arc::new(RwLock::new(Config::load(&paths.config_file)?));
    let dirty = Arc::new(AtomicBool::new(false));
    let hostname = agent_core::hostname();
    let handler = Arc::new(Handler {
        pairing: Some(Pairing {
            key_store: KeyStore::new(paths.key_file.clone()),
            proxy: ProxyMode::System,
            restart: Some(Arc::clone(shutdown)),
        }),
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
    let deps = Generation {
        paths,
        printer,
        shared,
        state,
        dirty,
        hostname,
    };
    loop {
        deps.run_once(shutdown)?;
        if !shutdown.take_restart() {
            return Ok(());
        }
        tracing::info!("emparejado nuevo: el agente vuelve a arrancar con el TPV y la clave nuevos");
    }
}

struct Generation<'a> {
    paths: &'a Paths,
    printer: Arc<dyn PrinterBackend>,
    shared: SharedConfig,
    state: SharedState,
    dirty: Arc<AtomicBool>,
    hostname: String,
}

impl Generation<'_> {
    /// One job loop with the configuration and key on disk; returns when
    /// `shutdown` is stopped or restarted.
    fn run_once(&self, shutdown: &Shutdown) -> Result<(), Failure> {
        let paths = self.paths;
        let mut cfg = Config::load(&paths.config_file)?;
        *self.shared.write().unwrap_or_else(|e| e.into_inner()) = cfg.clone();
        // Installed but not paired yet: wait for `pair` to write the server URL.
        if cfg.server_url.is_none() {
            tracing::info!("agente sin emparejar; esperando a `venpos-agent pair` o al icono");
        }
        let server = loop {
            if let Some(s) = cfg.server_url.clone() {
                break s;
            }
            state::lock(&self.state).connection = Connection::NotPaired;
            if shutdown.wait(Duration::from_secs(5)) {
                return Ok(());
            }
            cfg = Config::load(&paths.config_file)?;
            *self.shared.write().unwrap_or_else(|e| e.into_inner()) = cfg.clone();
        };
        {
            let mut s = state::lock(&self.state);
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
            printer: Arc::clone(&self.printer),
            clock,
            dedupe,
            config: Arc::clone(&self.shared),
            state: Arc::clone(&self.state),
            timing: Timing::default(),
            hostname: self.hostname.clone(),
            key_store: Some(key_store),
            status_dirty: Arc::clone(&self.dirty),
        });
        runner.run(shutdown);
        Ok(())
    }
}
