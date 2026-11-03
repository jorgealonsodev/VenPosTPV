//! Core of the VenPos print agent (PRD `docs/prd/agente-impresion.md` v2, phase 1).
//!
//! Everything that touches the operating system sits behind a trait
//! ([`clock::Clock`], [`printer::PrinterBackend`]) so the job loop can be
//! tested on Linux without a printer, a spooler or a real server.

pub mod api;
pub mod backoff;
pub mod clock;
pub mod config;
pub mod dedupe;
pub mod escpos;
pub mod ipc;
pub mod job_loop;
pub mod linux_install;
pub mod logging;
pub mod paths;
pub mod printer;
pub mod secret;
pub mod state;

/// Version reported to the server (`pair`, `PUT status`) and by `version`.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");

/// Operating system name sent to the server.
pub const OS: &str = if cfg!(windows) { "windows" } else { "linux" };

/// Message shown when the server rejects the key (A7).
pub const MSG_KEY_REJECTED: &str = "Vuelve a conectar el agente desde Ajustes → Cajas";

/// Local hostname, lossy UTF-8.
pub fn hostname() -> String {
    gethostname::gethostname().to_string_lossy().into_owned()
}

/// Cut a free-text error to at most `max` characters (E8: 500 for job errors).
pub fn truncate_chars(text: &str, max: usize) -> String {
    text.chars().take(max).collect()
}
