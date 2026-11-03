//! Printing back ends (A5). Job data is opaque bytes for the printer (A9):
//! never interpreted, never passed through a shell.
use crate::config::Destination;
use serde::{Deserialize, Serialize};

pub mod cups;
pub mod device;
pub mod network;
#[cfg(windows)]
pub mod windows;
pub mod winstatus;

/// Queue state reported in `PUT /status` (S2 phase 1: queue state only).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum PrinterState {
    Ready,
    Paused,
    Offline,
    Error,
    NotFound,
    NotConfigured,
    /// Direct modes cannot read status in phase 1.
    Unknown,
}

impl PrinterState {
    pub fn is_problem(self) -> bool {
        matches!(
            self,
            PrinterState::Paused | PrinterState::Offline | PrinterState::Error | PrinterState::NotFound
        )
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct QueueStatus {
    pub state: PrinterState,
    /// Short human detail, at most 200 characters.
    pub detail: String,
}

impl Default for QueueStatus {
    fn default() -> Self {
        QueueStatus::new(PrinterState::NotConfigured, "")
    }
}

impl QueueStatus {
    pub fn new(state: PrinterState, detail: impl Into<String>) -> Self {
        Self {
            state,
            detail: crate::truncate_chars(&detail.into(), 200),
        }
    }
}

/// Error from a print attempt; `code` is the `error_code` sent to the server.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[error("{code}: {message}")]
pub struct PrintError {
    pub code: &'static str,
    pub message: String,
}

impl PrintError {
    pub fn new(code: &'static str, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
        }
    }
}

/// Handle of a job left in a system queue, so a stale drawer pulse can be
/// cancelled after its expiry (T6).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum SpoolRef {
    Cups(String),
    Windows(u32),
}

/// A printer installed on this machine, for `list-printers` / tray menus.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct InstalledPrinter {
    pub destination: Destination,
    pub detail: String,
}

pub trait PrinterBackend: Send + Sync {
    /// Writes `data` unchanged to `dest`. `title` never contains customer data.
    fn write_raw(&self, dest: &Destination, title: &str, data: &[u8]) -> Result<Option<SpoolRef>, PrintError>;
    fn queue_status(&self, dest: &Destination) -> QueueStatus;
    /// Best effort: removes a job still waiting in the queue.
    fn cancel(&self, dest: &Destination, spool: &SpoolRef);
    fn list(&self) -> Vec<InstalledPrinter>;
}

/// The real back end, dispatching by destination mode.
#[derive(Debug, Default, Clone)]
pub struct SystemPrinter;

impl PrinterBackend for SystemPrinter {
    fn write_raw(&self, dest: &Destination, title: &str, data: &[u8]) -> Result<Option<SpoolRef>, PrintError> {
        dest.validate()
            .map_err(|e| PrintError::new("printer_not_configured", e.to_string()))?;
        match dest {
            Destination::Cups { queue } => cups::print(queue, title, data).map(|id| id.map(SpoolRef::Cups)),
            Destination::Device { path } => device::print(path, data).map(|()| None),
            Destination::Network { address } => network::print(address, data).map(|()| None),
            #[cfg(windows)]
            Destination::Spooler { printer } => {
                windows::print(printer, title, data).map(|id| Some(SpoolRef::Windows(id)))
            }
            #[cfg(not(windows))]
            Destination::Spooler { .. } => Err(PrintError::new(
                "unsupported_destination",
                "la cola de Windows solo existe en Windows",
            )),
        }
    }

    fn queue_status(&self, dest: &Destination) -> QueueStatus {
        match dest {
            Destination::Cups { queue } => cups::status(queue),
            Destination::Device { path } => device::status(path),
            Destination::Network { .. } => QueueStatus::new(PrinterState::Unknown, "modo directo por red"),
            #[cfg(windows)]
            Destination::Spooler { printer } => windows::status(printer),
            #[cfg(not(windows))]
            Destination::Spooler { .. } => QueueStatus::new(PrinterState::NotFound, "solo Windows"),
        }
    }

    fn cancel(&self, dest: &Destination, spool: &SpoolRef) {
        match (dest, spool) {
            (Destination::Cups { .. }, SpoolRef::Cups(id)) => cups::cancel(id),
            #[cfg(windows)]
            (Destination::Spooler { printer }, SpoolRef::Windows(id)) => windows::cancel(printer, *id),
            _ => {}
        }
    }

    fn list(&self) -> Vec<InstalledPrinter> {
        let mut out = Vec::new();
        #[cfg(windows)]
        out.extend(windows::list());
        #[cfg(not(windows))]
        {
            out.extend(cups::list());
            out.extend(device::list());
        }
        out
    }
}
