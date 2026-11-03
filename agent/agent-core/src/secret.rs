//! Agent key and pairing code (E8, E10). Neither type can be printed: their
//! `Debug`/`Display` are masked, and the raw value is only reachable through
//! `expose()`, used solely to build the request body or the `Authorization`
//! header.
use std::fmt;
use std::io::Write;
use std::path::{Path, PathBuf};

#[derive(Debug, thiserror::Error)]
pub enum SecretError {
    #[error("la clave recibida no tiene un formato válido")]
    InvalidKey,
    #[error("el código debe tener 6 cifras")]
    InvalidCode,
    #[error("no se puede leer o escribir la clave en {path}: {source}")]
    Io { path: PathBuf, source: std::io::Error },
    #[error("no se puede proteger la clave con DPAPI: {0}")]
    Dpapi(String),
}

/// The 256-bit agent key returned by `POST /pair`.
#[derive(Clone, PartialEq, Eq)]
pub struct AgentKey(String);

impl AgentKey {
    /// Accepts printable ASCII without spaces (safe in a header), 16..=512 chars.
    pub fn parse(raw: &str) -> Result<Self, SecretError> {
        let ok = (16..=512).contains(&raw.len()) && raw.bytes().all(|b| b.is_ascii_graphic());
        if ok {
            Ok(Self(raw.to_owned()))
        } else {
            Err(SecretError::InvalidKey)
        }
    }

    pub fn expose(&self) -> &str {
        &self.0
    }

    pub fn bearer(&self) -> String {
        format!("Bearer {}", self.0)
    }
}

impl fmt::Debug for AgentKey {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("AgentKey(****)")
    }
}

/// Six-digit pairing code (E1, E3).
#[derive(Clone, PartialEq, Eq)]
pub struct PairingCode(String);

impl PairingCode {
    pub fn parse(raw: &str) -> Result<Self, SecretError> {
        let trimmed: String = raw.chars().filter(|c| !c.is_whitespace()).collect();
        if trimmed.len() == 6 && trimmed.bytes().all(|b| b.is_ascii_digit()) {
            Ok(Self(trimmed))
        } else {
            Err(SecretError::InvalidCode)
        }
    }

    pub fn expose(&self) -> &str {
        &self.0
    }
}

impl fmt::Debug for PairingCode {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("PairingCode(******)")
    }
}

impl fmt::Display for PairingCode {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("******")
    }
}

/// Where the key lives on disk. On Linux a 0600 file; on Windows a DPAPI
/// (machine scope) blob whose file ACL is set by `install`/`pair`.
#[derive(Debug, Clone)]
pub struct KeyStore {
    path: PathBuf,
}

impl KeyStore {
    pub fn new(path: PathBuf) -> Self {
        Self { path }
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    pub fn load(&self) -> Result<Option<AgentKey>, SecretError> {
        let bytes = match std::fs::read(&self.path) {
            Ok(b) => b,
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(None),
            Err(source) => {
                return Err(SecretError::Io {
                    path: self.path.clone(),
                    source,
                });
            }
        };
        let plain = unprotect(&bytes)?;
        let text = String::from_utf8(plain).map_err(|_| SecretError::InvalidKey)?;
        AgentKey::parse(text.trim()).map(Some)
    }

    pub fn save(&self, key: &AgentKey) -> Result<(), SecretError> {
        let io = |source| SecretError::Io {
            path: self.path.clone(),
            source,
        };
        if let Some(dir) = self.path.parent() {
            std::fs::create_dir_all(dir).map_err(io)?;
        }
        let blob = protect(key.expose().as_bytes())?;
        let tmp = self.path.with_extension("tmp");
        let _ = std::fs::remove_file(&tmp);
        {
            let mut f = open_private(&tmp).map_err(io)?;
            f.write_all(&blob).map_err(io)?;
            f.sync_all().map_err(io)?;
        }
        std::fs::rename(&tmp, &self.path).map_err(io)?;
        Ok(())
    }

    pub fn delete(&self) -> Result<(), SecretError> {
        match std::fs::remove_file(&self.path) {
            Ok(()) => Ok(()),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(source) => Err(SecretError::Io {
                path: self.path.clone(),
                source,
            }),
        }
    }
}

#[cfg(unix)]
fn open_private(path: &Path) -> std::io::Result<std::fs::File> {
    use std::os::unix::fs::OpenOptionsExt;
    // Mode at creation: never world/group readable, independent of a later chmod.
    std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(path)
}

#[cfg(windows)]
fn open_private(path: &Path) -> std::io::Result<std::fs::File> {
    std::fs::OpenOptions::new().write(true).create_new(true).open(path)
}

#[cfg(unix)]
fn protect(plain: &[u8]) -> Result<Vec<u8>, SecretError> {
    Ok(plain.to_vec())
}

#[cfg(unix)]
fn unprotect(blob: &[u8]) -> Result<Vec<u8>, SecretError> {
    Ok(blob.to_vec())
}

#[cfg(windows)]
fn protect(plain: &[u8]) -> Result<Vec<u8>, SecretError> {
    crate::printer::windows::dpapi::protect(plain).map_err(SecretError::Dpapi)
}

#[cfg(windows)]
fn unprotect(blob: &[u8]) -> Result<Vec<u8>, SecretError> {
    crate::printer::windows::dpapi::unprotect(blob).map_err(SecretError::Dpapi)
}

#[cfg(test)]
mod tests {
    use super::*;

    const RAW: &str = "k3y_0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab";

    #[test]
    fn debug_never_shows_the_key_or_the_code() {
        let key = AgentKey::parse(RAW).unwrap();
        let code = PairingCode::parse("123456").unwrap();
        let shown = format!("{key:?} {code:?} {code}");
        assert!(!shown.contains(RAW), "{shown}");
        assert!(!shown.contains("123456"), "{shown}");
        assert_eq!(key.bearer(), format!("Bearer {RAW}"));
    }

    #[test]
    fn key_format_is_validated() {
        assert!(AgentKey::parse(RAW).is_ok());
        assert!(AgentKey::parse("short").is_err());
        assert!(AgentKey::parse("has space 0123456789abcdef").is_err());
        assert!(AgentKey::parse("line\r\nInjected: 0123456789abcdef").is_err());
    }

    #[test]
    fn pairing_code_is_exactly_six_digits() {
        assert_eq!(PairingCode::parse(" 123 456 ").unwrap().expose(), "123456");
        assert!(PairingCode::parse("12345").is_err());
        assert!(PairingCode::parse("1234567").is_err());
        assert!(PairingCode::parse("12a456").is_err());
    }

    #[cfg(unix)]
    #[test]
    fn key_file_is_0600_and_round_trips() {
        use std::os::unix::fs::PermissionsExt;
        let dir = std::env::temp_dir().join(format!("venpos-key-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        let store = KeyStore::new(dir.join("key"));
        assert!(store.load().unwrap().is_none());
        let key = AgentKey::parse(RAW).unwrap();
        store.save(&key).unwrap();
        let mode = std::fs::metadata(store.path()).unwrap().permissions().mode() & 0o777;
        assert_eq!(mode, 0o600);
        assert_eq!(store.load().unwrap(), Some(key));
        store.delete().unwrap();
        assert!(store.load().unwrap().is_none());
        let _ = std::fs::remove_dir_all(&dir);
    }
}
