//! Agent configuration (server URL from pairing, local destinations, A5/S5).
//! The server never chooses a printer, path or device (T8): destinations
//! only come from this file, written locally.
use serde::{Deserialize, Serialize};
use std::path::{Path, PathBuf};
use std::sync::{Arc, RwLock};

#[derive(Debug, thiserror::Error, PartialEq, Eq)]
pub enum ConfigError {
    #[error("dirección del TPV no válida: {0}")]
    InvalidUrl(String),
    #[error("solo se admite HTTPS (http solo para localhost): {0}")]
    InsecureUrl(String),
    #[error("destino de impresión no válido: {0}")]
    InvalidDestination(String),
    #[error("no se puede leer o escribir la configuración: {0}")]
    Io(String),
}

/// Which local destination a job goes to (T2).
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize, Deserialize, Hash, PartialOrd, Ord)]
#[serde(rename_all = "snake_case")]
pub enum Target {
    #[default]
    Ticket,
    Label,
}

impl Target {
    pub fn as_str(self) -> &'static str {
        match self {
            Target::Ticket => "ticket",
            Target::Label => "label",
        }
    }

    pub fn parse(s: &str) -> Option<Self> {
        match s {
            "ticket" => Some(Target::Ticket),
            "label" => Some(Target::Label),
            _ => None,
        }
    }
}

/// One mode per printer (A5).
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "mode", rename_all = "snake_case", deny_unknown_fields)]
pub enum Destination {
    /// Windows print spooler, RAW datatype.
    Spooler { printer: String },
    /// Linux CUPS queue, `lp -o raw`.
    Cups { queue: String },
    /// Character device, e.g. `/dev/usb/lp0`.
    Device { path: PathBuf },
    /// Raw TCP, `host:9100`.
    Network { address: String },
}

impl Destination {
    pub fn validate(&self) -> Result<(), ConfigError> {
        let bad = |why: &str| Err(ConfigError::InvalidDestination(why.to_owned()));
        match self {
            Destination::Spooler { printer } => {
                if printer.trim().is_empty() || printer.len() > 256 || printer.chars().any(char::is_control) {
                    return bad("nombre de impresora vacío o no válido");
                }
            }
            Destination::Cups { queue } => {
                // Passed as one argv element (no shell); still refuse option-like
                // or path-like names, which CUPS never uses for a queue.
                let ok = !queue.is_empty()
                    && queue.len() <= 127
                    && !queue.starts_with('-')
                    && queue
                        .chars()
                        .all(|c| c.is_ascii_graphic() && !matches!(c, '/' | '#' | '\\' | '\'' | '"'));
                if !ok {
                    return bad("nombre de cola CUPS no válido");
                }
            }
            Destination::Device { path } => {
                // `has_root` equals `is_absolute` on Unix; on Windows it keeps
                // the same rule for `/dev/...` (no drive letter), so the
                // Windows test run agrees with Linux.
                let ok = path.has_root()
                    && path.starts_with("/dev/")
                    && !path.components().any(|c| matches!(c, std::path::Component::ParentDir));
                if !ok {
                    return bad("el dispositivo debe estar en /dev/ (por ejemplo /dev/usb/lp0)");
                }
            }
            Destination::Network { address } => {
                let target = crate::printer::network::with_default_port(address);
                let host_ok = !address.is_empty()
                    && address.len() <= 255
                    && !address.starts_with('-')
                    && address
                        .chars()
                        .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | ':' | '[' | ']' | '_'));
                let port_ok = target
                    .rsplit(':')
                    .next()
                    .and_then(|p| p.parse::<u16>().ok())
                    .is_some_and(|p| p != 0);
                if !(host_ok && port_ok) {
                    return bad("dirección de red no válida (IP o nombre, puerto 9100 por defecto)");
                }
            }
        }
        Ok(())
    }

    /// Name shown in status reports.
    pub fn display_name(&self) -> String {
        match self {
            Destination::Spooler { printer } => printer.clone(),
            Destination::Cups { queue } => queue.clone(),
            Destination::Device { path } => path.to_string_lossy().into_owned(),
            Destination::Network { address } => address.clone(),
        }
    }
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Destinations {
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub ticket: Option<Destination>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub label: Option<Destination>,
}

impl Destinations {
    pub fn get(&self, target: Target) -> Option<&Destination> {
        match target {
            Target::Ticket => self.ticket.as_ref(),
            Target::Label => self.label.as_ref(),
        }
    }
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Config {
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub server_url: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub terminal_id: Option<i64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub terminal_name: Option<String>,
    #[serde(default)]
    pub destinations: Destinations,
}

impl Config {
    pub fn load(path: &Path) -> Result<Self, ConfigError> {
        match std::fs::read(path) {
            Ok(bytes) => {
                let cfg: Config =
                    serde_json::from_slice(&bytes).map_err(|e| ConfigError::Io(format!("{}: {e}", path.display())))?;
                if let Some(url) = &cfg.server_url {
                    validate_server_url(url)?;
                }
                for d in [&cfg.destinations.ticket, &cfg.destinations.label]
                    .into_iter()
                    .flatten()
                {
                    d.validate()?;
                }
                Ok(cfg)
            }
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Config::default()),
            Err(e) => Err(ConfigError::Io(format!("{}: {e}", path.display()))),
        }
    }

    pub fn save(&self, path: &Path) -> Result<(), ConfigError> {
        let io = |e: std::io::Error| ConfigError::Io(format!("{}: {e}", path.display()));
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir).map_err(io)?;
        }
        let json = serde_json::to_vec_pretty(self).map_err(|e| ConfigError::Io(e.to_string()))?;
        crate::dedupe::write_atomic(path, &json).map_err(io)
    }
}

pub type SharedConfig = Arc<RwLock<Config>>;

/// E11: HTTPS only, except `http://localhost` / `127.0.0.1` / `[::1]` (tests,
/// development). Returns the URL without a trailing slash.
pub fn validate_server_url(url: &str) -> Result<String, ConfigError> {
    let invalid = || ConfigError::InvalidUrl(url.to_owned());
    let trimmed = url.trim();
    let (scheme, rest) = trimmed.split_once("://").ok_or_else(invalid)?;
    let scheme = scheme.to_ascii_lowercase();
    if rest.contains(['?', '#', '@', ' ', '\\']) || rest.chars().any(char::is_control) {
        return Err(invalid());
    }
    let (authority, path) = match rest.find('/') {
        Some(i) => (&rest[..i], &rest[i..]),
        None => (rest, ""),
    };
    let host = if let Some(v6) = authority.strip_prefix('[') {
        let end = v6.find(']').ok_or_else(invalid)?;
        let after = &v6[end + 1..];
        if !(after.is_empty() || after.strip_prefix(':').is_some_and(|p| p.parse::<u16>().is_ok())) {
            return Err(invalid());
        }
        &authority[..end + 2]
    } else {
        let (h, port) = match authority.split_once(':') {
            Some((h, p)) => (h, Some(p)),
            None => (authority, None),
        };
        if port.is_some_and(|p| p.parse::<u16>().is_err()) {
            return Err(invalid());
        }
        h
    };
    let host_ok = !host.is_empty()
        && (host.starts_with('[') || host.chars().all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '-'));
    if !host_ok {
        return Err(invalid());
    }
    match scheme.as_str() {
        "https" => {}
        "http" => {
            let h = host.to_ascii_lowercase();
            if !(h == "localhost" || h == "127.0.0.1" || h == "[::1]") {
                return Err(ConfigError::InsecureUrl(url.to_owned()));
            }
        }
        _ => return Err(invalid()),
    }
    let path = path.trim_end_matches('/');
    Ok(format!("{scheme}://{authority}{path}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn https_is_accepted_and_trailing_slash_trimmed() {
        assert_eq!(
            validate_server_url("https://tpv.example.com/").unwrap(),
            "https://tpv.example.com"
        );
        assert_eq!(
            validate_server_url("https://tpv.example.com:8443/base").unwrap(),
            "https://tpv.example.com:8443/base"
        );
    }

    #[test]
    fn plain_http_is_rejected_except_loopback() {
        assert!(matches!(
            validate_server_url("http://tpv.example.com"),
            Err(ConfigError::InsecureUrl(_))
        ));
        assert!(matches!(
            validate_server_url("http://192.168.1.10"),
            Err(ConfigError::InsecureUrl(_))
        ));
        assert!(matches!(
            validate_server_url("http://localhost.evil.com"),
            Err(ConfigError::InsecureUrl(_))
        ));
        assert!(validate_server_url("http://localhost:8080").is_ok());
        assert!(validate_server_url("http://127.0.0.1:9").is_ok());
        assert!(validate_server_url("http://[::1]:9").is_ok());
    }

    #[test]
    fn malformed_urls_are_rejected() {
        for bad in [
            "",
            "tpv.example.com",
            "ftp://x",
            "https://",
            "https://user:pw@tpv.example.com",
            "https://tpv.example.com/?a=1",
            "https://tpv.example.com/#x",
        ] {
            assert!(validate_server_url(bad).is_err(), "{bad} accepted");
        }
    }

    #[test]
    fn destinations_are_validated() {
        assert!(
            Destination::Cups {
                queue: "Epson_TM-T20II".into()
            }
            .validate()
            .is_ok()
        );
        assert!(Destination::Cups { queue: "-o".into() }.validate().is_err());
        assert!(Destination::Cups { queue: "a b".into() }.validate().is_err());
        assert!(Destination::Cups { queue: "".into() }.validate().is_err());
        assert!(
            Destination::Device {
                path: "/dev/usb/lp0".into()
            }
            .validate()
            .is_ok()
        );
        assert!(
            Destination::Device {
                path: "/etc/passwd".into()
            }
            .validate()
            .is_err()
        );
        assert!(
            Destination::Device {
                path: "/dev/../etc/passwd".into()
            }
            .validate()
            .is_err()
        );
        assert!(
            Destination::Network {
                address: "192.168.1.50:9100".into()
            }
            .validate()
            .is_ok()
        );
        assert!(
            Destination::Network {
                address: "printer.local".into()
            }
            .validate()
            .is_ok()
        );
        assert!(
            Destination::Network {
                address: "bad host:9100".into()
            }
            .validate()
            .is_err()
        );
        assert!(
            Destination::Spooler {
                printer: "EPSON TM-T20II Receipt".into()
            }
            .validate()
            .is_ok()
        );
        assert!(Destination::Spooler { printer: "".into() }.validate().is_err());
    }

    #[test]
    fn config_json_round_trip() {
        let cfg = Config {
            server_url: Some("https://tpv.example.com".into()),
            terminal_id: Some(2),
            terminal_name: Some("Caja 2".into()),
            destinations: Destinations {
                ticket: Some(Destination::Cups { queue: "Epson".into() }),
                label: Some(Destination::Network {
                    address: "10.0.0.5:9100".into(),
                }),
            },
        };
        let json = serde_json::to_string(&cfg).unwrap();
        assert!(json.contains(r#""mode":"cups""#), "{json}");
        assert_eq!(serde_json::from_str::<Config>(&json).unwrap(), cfg);
    }
}
