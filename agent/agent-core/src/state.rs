//! Live state of the service, shared with the IPC server (status, tray colour).
use crate::config::Target;
use crate::printer::QueueStatus;
use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize, Default)]
#[serde(rename_all = "snake_case")]
pub enum Connection {
    #[default]
    Starting,
    Connected,
    Offline,
    NotPaired,
    KeyRejected,
    UpdateRequired,
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct PrinterReport {
    pub target: Target,
    pub name: String,
    pub status: QueueStatus,
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct AgentStatus {
    pub version: String,
    pub connection: Connection,
    pub message: Option<String>,
    pub server_url: Option<String>,
    pub terminal_name: Option<String>,
    pub last_contact_unix_ms: Option<i64>,
    pub printers: BTreeMap<String, PrinterReport>,
    pub printed_jobs: u64,
    pub failed_jobs: u64,
    pub last_error: Option<String>,
}

impl Connection {
    /// Text for the CLI and the tray.
    pub fn label(self) -> &'static str {
        match self {
            Connection::Starting => "arrancando",
            Connection::Connected => "conectado",
            Connection::Offline => "sin conexión",
            Connection::NotPaired => "sin emparejar",
            Connection::KeyRejected => "clave rechazada",
            Connection::UpdateRequired => "versión no admitida",
        }
    }
}

/// Tray colour (A4).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Light {
    Green,
    Amber,
    Red,
}

impl AgentStatus {
    pub fn light(&self) -> Light {
        if self.printers.values().any(|p| p.status.state.is_problem()) {
            return Light::Red;
        }
        match self.connection {
            Connection::Connected => Light::Green,
            _ => Light::Amber,
        }
    }
}

pub type SharedState = Arc<Mutex<AgentStatus>>;

pub fn lock(state: &SharedState) -> std::sync::MutexGuard<'_, AgentStatus> {
    state.lock().unwrap_or_else(|e| e.into_inner())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::printer::PrinterState;

    #[test]
    fn light_colours() {
        let mut s = AgentStatus {
            connection: Connection::Connected,
            ..Default::default()
        };
        assert_eq!(s.light(), Light::Green);
        s.connection = Connection::Offline;
        assert_eq!(s.light(), Light::Amber);
        s.printers.insert(
            "ticket".into(),
            PrinterReport {
                target: Target::Ticket,
                name: "x".into(),
                status: QueueStatus::new(PrinterState::Paused, ""),
            },
        );
        assert_eq!(s.light(), Light::Red);
    }
}
