//! The job loop (T3-T8, A7, S1/S2 phase 1).
//!
//! For each `GET /jobs` batch, in the order the server returns it (T7):
//! duplicate → acknowledge only; expired → never print; drawer → pulse
//! generated here, rate limited; ticket/label → decode, verify sha256, write
//! to the configured destination. A printed id is persisted before the
//! acknowledgement is sent (T4), so a lost acknowledgement never reprints.
use crate::api::{ApiClient, ApiError, ClaimToken, Job, JobId, JobResult, PrinterStatusBody, StatusBody};
use crate::backoff::Backoff;
use crate::clock::Clock;
use crate::config::{Destination, SharedConfig, Target};
use crate::dedupe::DedupeStore;
use crate::printer::{PrinterBackend, PrinterState, QueueStatus};
use crate::secret::KeyStore;
use crate::state::{self, Connection, PrinterReport, SharedState};
use base64::Engine as _;
use sha2::{Digest, Sha256};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc;
use std::sync::{Arc, Condvar, Mutex};
use std::time::Duration;

/// T8: 1 MB per job.
pub const MAX_PAYLOAD: usize = 1024 * 1024;

#[derive(Debug, Clone)]
pub struct Timing {
    /// A7: the server waits up to 20 s.
    pub long_poll_wait: u8,
    /// A7: after a request slower than `slow_request`, wait 5 s instead.
    pub fallback_wait: u8,
    pub slow_request: Duration,
    /// T8: one drawer opening every 5 s.
    pub drawer_interval: Duration,
    /// T4: the lease is 60 s; extend well before.
    pub lease_extend_every: Duration,
    pub status_check_every: Duration,
    pub backoff: Vec<Duration>,
    pub key_rejected_retry: Duration,
    pub max_poll_after: Duration,
}

impl Default for Timing {
    fn default() -> Self {
        Self {
            long_poll_wait: 20,
            fallback_wait: 5,
            slow_request: Duration::from_secs(30),
            drawer_interval: Duration::from_secs(5),
            lease_extend_every: Duration::from_secs(40),
            status_check_every: Duration::from_secs(15),
            backoff: crate::backoff::DEFAULT_STEPS.to_vec(),
            key_rejected_retry: Duration::from_secs(30),
            max_poll_after: Duration::from_secs(30),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Outcome {
    Printed,
    Duplicate,
    Expired,
    Failed { code: String, message: String },
}

#[derive(Debug, Default)]
pub struct PollSummary {
    pub outcomes: Vec<(JobId, Outcome)>,
    pub poll_after: Option<Duration>,
}

/// Stop signal shared with the service control handler. A restart (after
/// IPC `pair`) also ends the current run; the caller then starts again with
/// the new server and key, while a real stop always wins.
#[derive(Debug, Default)]
pub struct Shutdown {
    stopped: Mutex<Flags>,
    cv: Condvar,
}

#[derive(Debug, Default, Clone, Copy)]
struct Flags {
    stop: bool,
    restart: bool,
}

impl Flags {
    fn any(self) -> bool {
        self.stop || self.restart
    }
}

impl Shutdown {
    pub fn new() -> Arc<Self> {
        Arc::new(Self::default())
    }

    fn flags(&self) -> std::sync::MutexGuard<'_, Flags> {
        self.stopped.lock().unwrap_or_else(|e| e.into_inner())
    }

    pub fn stop(&self) {
        self.flags().stop = true;
        self.cv.notify_all();
    }

    /// Ends the current run so the caller starts it again.
    pub fn restart(&self) {
        self.flags().restart = true;
        self.cv.notify_all();
    }

    /// True once if a restart was asked and no stop came; clears it.
    pub fn take_restart(&self) -> bool {
        let mut f = self.flags();
        let restart = f.restart && !f.stop;
        f.restart = false;
        restart
    }

    /// Stopped or restarting: the current run must end.
    pub fn is_stopped(&self) -> bool {
        self.flags().any()
    }

    /// Sleeps up to `d`; returns true if stopped or restarting.
    pub fn wait(&self, d: Duration) -> bool {
        let g = self.flags();
        let (g, _) = self
            .cv
            .wait_timeout_while(g, d, |f| !f.any())
            .unwrap_or_else(|e| e.into_inner());
        g.any()
    }
}

struct PendingAck {
    id: JobId,
    claim: ClaimToken,
    result: JobResult,
}

pub struct Runner {
    api: ApiClient,
    printer: Arc<dyn PrinterBackend>,
    clock: Arc<dyn Clock>,
    dedupe: DedupeStore,
    config: SharedConfig,
    state: SharedState,
    timing: Timing,
    hostname: String,
    key_store: Option<KeyStore>,
    wait: u8,
    skew_ms: i64,
    last_drawer: Option<Duration>,
    pending_acks: Vec<PendingAck>,
    last_reported: Option<Vec<PrinterStatusBody>>,
    last_status_check: Option<Duration>,
    status_dirty: Arc<AtomicBool>,
}

pub struct RunnerDeps {
    pub api: ApiClient,
    pub printer: Arc<dyn PrinterBackend>,
    pub clock: Arc<dyn Clock>,
    pub dedupe: DedupeStore,
    pub config: SharedConfig,
    pub state: SharedState,
    pub timing: Timing,
    pub hostname: String,
    /// Reloaded after a rejected key, in case `pair` ran meanwhile.
    pub key_store: Option<KeyStore>,
    /// Set by IPC `choose-printers` to force a status report.
    pub status_dirty: Arc<AtomicBool>,
}

impl Runner {
    pub fn new(d: RunnerDeps) -> Self {
        let wait = d.timing.long_poll_wait;
        Self {
            api: d.api,
            printer: d.printer,
            clock: d.clock,
            dedupe: d.dedupe,
            config: d.config,
            state: d.state,
            timing: d.timing,
            hostname: d.hostname,
            key_store: d.key_store,
            wait,
            skew_ms: 0,
            last_drawer: None,
            pending_acks: Vec::new(),
            last_reported: None,
            last_status_check: None,
            status_dirty: d.status_dirty,
        }
    }

    /// Current `wait` used for `GET /jobs` (20, or 5 after a slow request).
    pub fn current_wait(&self) -> u8 {
        self.wait
    }

    pub fn pending_acks(&self) -> usize {
        self.pending_acks.len()
    }

    fn server_now_ms(&self) -> i64 {
        self.clock.now_unix_ms().checked_add(self.skew_ms).unwrap_or(i64::MAX)
    }

    /// One `GET /jobs` and the processing of its batch.
    pub fn poll_once(&mut self) -> Result<PollSummary, ApiError> {
        self.flush_acks()?;
        let started = self.clock.monotonic();
        let result = self.api.get_jobs(self.wait);
        let elapsed = self.clock.monotonic().checked_sub(started).unwrap_or_default();
        if should_fall_back(elapsed, self.timing.slow_request) && self.wait != self.timing.fallback_wait {
            tracing::warn!(
                elapsed_ms = u64::try_from(elapsed.as_millis()).unwrap_or(u64::MAX),
                "la petición tardó demasiado (¿proxy?); se pasa a esperas de {} s",
                self.timing.fallback_wait
            );
            self.wait = self.timing.fallback_wait;
        }
        let resp = result?;
        {
            let mut s = state::lock(&self.state);
            s.connection = Connection::Connected;
            s.message = None;
            s.last_contact_unix_ms = Some(self.clock.now_unix_ms());
        }
        if let Some(server_ms) = resp.server_date_ms {
            self.skew_ms = server_ms.checked_sub(self.clock.now_unix_ms()).unwrap_or(0);
        }
        if resp.malformed > 0 {
            tracing::warn!(count = resp.malformed, "trabajos con formato no válido ignorados");
        }
        let poll_after = resp
            .poll_after_ms
            .map(|ms| Duration::from_millis(ms).min(self.timing.max_poll_after));
        let outcomes = self.process_batch(resp.jobs)?;
        Ok(PollSummary { outcomes, poll_after })
    }

    fn process_batch(&mut self, jobs: Vec<Job>) -> Result<Vec<(JobId, Outcome)>, ApiError> {
        if jobs.is_empty() {
            return Ok(Vec::new());
        }
        let unfinished: Mutex<Vec<(JobId, ClaimToken)>> =
            Mutex::new(jobs.iter().map(|j| (j.id.clone(), j.claim_token.clone())).collect());
        let (stop_tx, stop_rx) = mpsc::channel::<()>();
        let keeper_api = self.api.clone();
        let every = self.timing.lease_extend_every;
        let mut outcomes = Vec::with_capacity(jobs.len());
        let mut connectivity_error: Option<ApiError> = None;
        let res = std::thread::scope(|scope| {
            let unfinished_ref = &unfinished;
            scope.spawn(move || {
                // Lease keeper (T4): long label batches ask for more time.
                while let Err(mpsc::RecvTimeoutError::Timeout) = stop_rx.recv_timeout(every) {
                    let pending: Vec<(JobId, ClaimToken)> =
                        unfinished_ref.lock().unwrap_or_else(|e| e.into_inner()).clone();
                    for (id, claim) in pending {
                        match keeper_api.extend(&id, &claim) {
                            Ok(_) => tracing::debug!(job = %id, "plazo ampliado"),
                            Err(e) => tracing::warn!(job = %id, error = %e, "no se pudo ampliar el plazo"),
                        }
                    }
                }
            });
            let mut out: Result<(), ApiError> = Ok(());
            for job in &jobs {
                let outcome = self.process_job(job);
                let result = match &outcome {
                    Outcome::Printed | Outcome::Duplicate => JobResult::done(),
                    Outcome::Expired => JobResult::failed("job_expired", "caducado antes de imprimir"),
                    Outcome::Failed { code, message } => JobResult::failed(code, message),
                };
                if outcome == Outcome::Printed {
                    // Persist before acknowledging (T4).
                    if let Err(e) = self.dedupe.record(job.id.as_str(), self.clock.now_unix_ms()) {
                        tracing::error!(job = %job.id, error = %e, "no se pudo guardar el trabajo impreso");
                    }
                }
                unfinished
                    .lock()
                    .unwrap_or_else(|e| e.into_inner())
                    .retain(|(id, _)| id != &job.id);
                log_outcome(job, &outcome);
                self.count(&outcome);
                outcomes.push((job.id.clone(), outcome));
                match self.api.post_result(&job.id, &job.claim_token, &result) {
                    Ok(()) => {}
                    Err(ApiError::Unauthorized) => {
                        out = Err(ApiError::Unauthorized);
                        break;
                    }
                    Err(e @ (ApiError::ClaimStale | ApiError::JobExpired | ApiError::NotFound)) => {
                        tracing::info!(job = %job.id, reason = %e, "confirmación no aceptada");
                    }
                    Err(e) => {
                        tracing::warn!(job = %job.id, error = %e, "confirmación pendiente; se reintentará");
                        self.pending_acks.push(PendingAck {
                            id: job.id.clone(),
                            claim: job.claim_token.clone(),
                            result,
                        });
                        if e.is_connectivity() && connectivity_error.is_none() {
                            connectivity_error = Some(e);
                        }
                    }
                }
            }
            let _ = stop_tx.send(());
            out
        });
        res?;
        match connectivity_error {
            Some(e) => Err(e),
            None => Ok(outcomes),
        }
    }

    fn flush_acks(&mut self) -> Result<(), ApiError> {
        let pending = std::mem::take(&mut self.pending_acks);
        let mut iter = pending.into_iter();
        while let Some(a) = iter.next() {
            match self.api.post_result(&a.id, &a.claim, &a.result) {
                Ok(()) | Err(ApiError::ClaimStale | ApiError::JobExpired | ApiError::NotFound) => {}
                Err(e) => {
                    self.pending_acks.push(a);
                    self.pending_acks.extend(iter);
                    return Err(e);
                }
            }
        }
        Ok(())
    }

    fn count(&self, outcome: &Outcome) {
        let mut s = state::lock(&self.state);
        match outcome {
            Outcome::Printed => s.printed_jobs = s.printed_jobs.saturating_add(1),
            Outcome::Failed { code, message } => {
                s.failed_jobs = s.failed_jobs.saturating_add(1);
                s.last_error = Some(format!("{code}: {}", crate::truncate_chars(message, 200)));
            }
            Outcome::Duplicate | Outcome::Expired => {}
        }
    }

    /// Decides and performs one job. Never panics on server data.
    pub fn process_job(&mut self, job: &Job) -> Outcome {
        let now = self.clock.now_unix_ms();
        if self.dedupe.contains(job.id.as_str(), now) {
            return Outcome::Duplicate;
        }
        if let Some(exp) = job.expires_at
            && is_expired(exp.unix_ms, self.server_now_ms())
        {
            return Outcome::Expired;
        }
        let fail = |code: &str, message: &str| Outcome::Failed {
            code: code.to_owned(),
            message: message.to_owned(),
        };
        let (target, is_drawer) = match job.job_type.as_str() {
            "drawer" => (Target::Ticket, true),
            "ticket" | "label" => {
                let default = if job.job_type == "label" {
                    Target::Label
                } else {
                    Target::Ticket
                };
                match job.target.as_deref() {
                    None => (default, false),
                    Some(t) => match Target::parse(t) {
                        Some(t) => (t, false),
                        None => return fail("unknown_target", "destino desconocido"),
                    },
                }
            }
            _ => return fail("unsupported_job_type", "tipo de trabajo no admitido por esta versión"),
        };
        let dest: Option<Destination> = self
            .config
            .read()
            .unwrap_or_else(|e| e.into_inner())
            .destinations
            .get(target)
            .cloned();
        let Some(dest) = dest else {
            return fail(
                "printer_not_configured",
                &format!("no hay impresora de {} elegida", target.as_str()),
            );
        };
        let title = format!("VenPos {} {}", job.job_type, job.id);
        if is_drawer {
            let mono = self.clock.monotonic();
            if !drawer_allowed(self.last_drawer, mono, self.timing.drawer_interval) {
                return fail("drawer_rate_limited", "una apertura de cajón cada 5 s");
            }
            // T8: any payload sent with a drawer job is ignored.
            return match self.printer.write_raw(&dest, &title, &crate::escpos::drawer_pulse()) {
                Ok(spool) => {
                    self.last_drawer = Some(mono);
                    if let (Some(spool), Some(exp)) = (spool, job.expires_at) {
                        self.schedule_drawer_cancel(dest, spool, exp.unix_ms);
                    }
                    Outcome::Printed
                }
                Err(e) => fail(e.code, &e.message),
            };
        }
        let data = match decode_payload(job) {
            Ok(d) => d,
            Err((code, msg)) => return fail(code, msg),
        };
        match self.printer.write_raw(&dest, &title, &data) {
            Ok(_) => Outcome::Printed,
            Err(e) => fail(e.code, &e.message),
        }
    }

    /// T6: a drawer pulse still queued after its expiry is cancelled.
    fn schedule_drawer_cancel(&self, dest: Destination, spool: crate::printer::SpoolRef, expires_ms: i64) {
        let remaining = expires_ms.checked_sub(self.server_now_ms()).unwrap_or(0).max(0);
        let delay = Duration::from_millis(u64::try_from(remaining).unwrap_or(0));
        let printer = Arc::clone(&self.printer);
        let spawned = std::thread::Builder::new().name("drawer-cancel".into()).spawn(move || {
            std::thread::sleep(delay);
            printer.cancel(&dest, &spool);
        });
        if let Err(e) = spawned {
            tracing::warn!(error = %e, "no se pudo programar la cancelación del cajón");
        }
    }

    /// Current printer reports for configured targets.
    pub fn printer_reports(&self) -> Vec<PrinterStatusBody> {
        let dests = self
            .config
            .read()
            .unwrap_or_else(|e| e.into_inner())
            .destinations
            .clone();
        [Target::Ticket, Target::Label]
            .into_iter()
            .map(|t| match dests.get(t) {
                Some(d) => {
                    let QueueStatus { state, detail } = self.printer.queue_status(d);
                    PrinterStatusBody {
                        target: t.as_str().into(),
                        name: d.display_name(),
                        state,
                        detail,
                    }
                }
                None => PrinterStatusBody {
                    target: t.as_str().into(),
                    name: String::new(),
                    state: PrinterState::NotConfigured,
                    detail: String::new(),
                },
            })
            .collect()
    }

    /// S1: `PUT /status` on start and whenever printer state changes.
    pub fn report_status_if_due(&mut self) -> Result<bool, ApiError> {
        let mono = self.clock.monotonic();
        let dirty = self.status_dirty.swap(false, Ordering::SeqCst);
        let due = dirty
            || self.last_reported.is_none()
            || self
                .last_status_check
                .is_none_or(|t| mono.checked_sub(t).unwrap_or_default() >= self.timing.status_check_every);
        if !due {
            return Ok(false);
        }
        self.last_status_check = Some(mono);
        let reports = self.printer_reports();
        {
            let mut s = state::lock(&self.state);
            s.printers = reports
                .iter()
                .filter_map(|r| {
                    Target::parse(&r.target).map(|t| {
                        (
                            r.target.clone(),
                            PrinterReport {
                                target: t,
                                name: r.name.clone(),
                                status: QueueStatus::new(r.state, r.detail.clone()),
                            },
                        )
                    })
                })
                .collect();
        }
        if self.last_reported.as_ref() == Some(&reports) {
            return Ok(false);
        }
        let body = StatusBody {
            version: crate::VERSION.into(),
            hostname: self.hostname.clone(),
            printers: reports.clone(),
        };
        match self.api.put_status(&body) {
            Ok(()) => {
                self.last_reported = Some(reports);
                Ok(true)
            }
            Err(e) => {
                self.status_dirty.store(true, Ordering::SeqCst);
                Err(e)
            }
        }
    }

    fn set_connection(&self, c: Connection, message: Option<String>) {
        let mut s = state::lock(&self.state);
        s.connection = c;
        s.message = message;
    }

    fn reload_key(&mut self) {
        if let Some(store) = &self.key_store {
            match store.load() {
                Ok(k) => self.api.set_key(k),
                Err(e) => tracing::error!(error = %e, "no se puede leer la clave"),
            }
        }
    }

    /// Runs until `shutdown` is stopped.
    pub fn run(&mut self, shutdown: &Shutdown) {
        let mut backoff = Backoff::new(&self.timing.backoff);
        let mut key_warned = false;
        tracing::info!(version = crate::VERSION, server = self.api.base(), "agente en marcha");
        while !shutdown.is_stopped() {
            if !self.api.has_key() {
                self.reload_key();
                if !self.api.has_key() {
                    self.set_connection(Connection::NotPaired, Some("Agente sin emparejar".into()));
                    if shutdown.wait(self.timing.key_rejected_retry) {
                        break;
                    }
                    continue;
                }
            }
            if let Err(e) = self.report_status_if_due() {
                tracing::debug!(error = %e, "estado de impresoras pendiente de enviar");
            }
            let delay = match self.poll_once() {
                Ok(summary) => {
                    backoff.reset();
                    key_warned = false;
                    summary.poll_after.unwrap_or(Duration::ZERO)
                }
                Err(ApiError::Unauthorized) => {
                    if !key_warned {
                        tracing::error!("{}", crate::MSG_KEY_REJECTED);
                        key_warned = true;
                    }
                    self.set_connection(Connection::KeyRejected, Some(crate::MSG_KEY_REJECTED.into()));
                    self.api.set_key(None);
                    self.timing.key_rejected_retry
                }
                Err(ApiError::UpdateRequired) => {
                    self.set_connection(Connection::UpdateRequired, Some("Hay que actualizar el agente".into()));
                    backoff.next_delay().max(self.timing.key_rejected_retry)
                }
                Err(ApiError::RateLimited { retry_after }) => {
                    let d = backoff.next_delay();
                    retry_after.map_or(d, |r| r.max(d))
                }
                Err(e) => {
                    tracing::warn!(error = %e, "sin conexión con el TPV");
                    self.set_connection(Connection::Offline, Some(e.to_string()));
                    backoff.next_delay()
                }
            };
            if !delay.is_zero() && shutdown.wait(delay) {
                break;
            }
        }
        tracing::info!("agente detenido");
    }
}

fn log_outcome(job: &Job, outcome: &Outcome) {
    match outcome {
        Outcome::Printed => tracing::info!(job = %job.id, kind = %job.job_type, "enviado a la impresora"),
        Outcome::Duplicate => tracing::info!(job = %job.id, "ya impreso; solo se confirma"),
        Outcome::Expired => tracing::info!(job = %job.id, kind = %job.job_type, "caducado; no se imprime"),
        Outcome::Failed { code, message } => {
            tracing::warn!(job = %job.id, kind = %job.job_type, code = %code, error = %message, "fallido")
        }
    }
}

/// A7: a request slower than the threshold means a buffering proxy.
pub fn should_fall_back(elapsed: Duration, threshold: Duration) -> bool {
    elapsed > threshold
}

/// T6: expired when `now >= expires_at` (server clock).
pub fn is_expired(expires_ms: i64, server_now_ms: i64) -> bool {
    server_now_ms >= expires_ms
}

/// T8: one drawer opening per interval (monotonic clock).
pub fn drawer_allowed(last: Option<Duration>, now: Duration, interval: Duration) -> bool {
    match last {
        None => true,
        Some(at) => now.checked_sub(at).is_some_and(|since| since >= interval),
    }
}

/// Base64 → bytes, size limit and sha256 check (T8).
pub fn decode_payload(job: &Job) -> Result<Vec<u8>, (&'static str, &'static str)> {
    let b64 = job
        .payload_b64
        .as_deref()
        .ok_or(("payload_missing", "trabajo sin datos"))?;
    // 4/3 overhead plus padding/newlines margin.
    if b64.len() > MAX_PAYLOAD / 3 * 4 + 16 {
        return Err(("payload_too_large", "más de 1 MB"));
    }
    let data = base64::engine::general_purpose::STANDARD
        .decode(b64.trim())
        .map_err(|_| ("payload_invalid", "datos en base64 no válidos"))?;
    if data.len() > MAX_PAYLOAD {
        return Err(("payload_too_large", "más de 1 MB"));
    }
    let expected = job
        .sha256
        .as_deref()
        .ok_or(("checksum_missing", "falta la huella sha256"))?;
    if !checksum_ok(&data, expected) {
        return Err(("checksum_mismatch", "la huella sha256 no coincide"));
    }
    Ok(data)
}

fn checksum_ok(data: &[u8], expected_hex: &str) -> bool {
    let digest = Sha256::digest(data);
    let actual: String = digest.iter().map(|b| format!("{b:02x}")).collect();
    actual.eq_ignore_ascii_case(expected_hex.trim())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn expiry_boundary() {
        assert!(!is_expired(1_000, 999));
        assert!(is_expired(1_000, 1_000));
        assert!(is_expired(1_000, 5_000));
    }

    #[test]
    fn drawer_rate_limit_one_per_interval() {
        let i = Duration::from_secs(5);
        assert!(drawer_allowed(None, Duration::from_secs(0), i));
        assert!(!drawer_allowed(
            Some(Duration::from_secs(10)),
            Duration::from_millis(14_999),
            i
        ));
        assert!(drawer_allowed(
            Some(Duration::from_secs(10)),
            Duration::from_secs(15),
            i
        ));
    }

    #[test]
    fn slow_request_threshold() {
        assert!(!should_fall_back(Duration::from_secs(21), Duration::from_secs(30)));
        assert!(should_fall_back(Duration::from_millis(30_001), Duration::from_secs(30)));
    }
}
