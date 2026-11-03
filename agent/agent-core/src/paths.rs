//! On-disk locations. `--config-dir` puts everything under one directory
//! (tests, development); otherwise the system locations of E10.
use std::path::{Path, PathBuf};

#[derive(Debug, Clone)]
pub struct Paths {
    pub config_file: PathBuf,
    pub key_file: PathBuf,
    pub dedupe_file: PathBuf,
    pub log_dir: PathBuf,
    /// Unix socket path (Linux) or pipe name (Windows), see [`crate::ipc`].
    pub ipc_name: String,
    /// True when the system default locations are used.
    pub system: bool,
}

impl Paths {
    pub fn in_dir(dir: &Path) -> Self {
        Self {
            config_file: dir.join("config.json"),
            key_file: dir.join(if cfg!(windows) { "key.bin" } else { "key" }),
            dedupe_file: dir.join("printed.json"),
            log_dir: dir.join("logs"),
            ipc_name: if cfg!(windows) {
                // Distinct pipe per directory so a dev instance never answers for the service.
                format!("VenPosAgent-{:08x}", fnv1a(dir.to_string_lossy().as_bytes()))
            } else {
                dir.join("agent.sock").to_string_lossy().into_owned()
            },
            system: false,
        }
    }

    #[cfg(windows)]
    pub fn system_default() -> Self {
        let base = std::env::var_os("ProgramData")
            .map(PathBuf::from)
            .unwrap_or_else(|| PathBuf::from(r"C:\ProgramData"))
            .join("VenPos Agent");
        let mut p = Self::in_dir(&base);
        // Separate folder whose ACL excludes Users (E10): DPAPI machine scope
        // alone would let any local account decrypt the blob.
        p.key_file = base.join("secret").join("key.bin");
        p.ipc_name = "VenPosAgent".to_owned();
        p.system = true;
        p
    }

    #[cfg(not(windows))]
    pub fn system_default() -> Self {
        Self {
            config_file: PathBuf::from("/etc/venpos-agent/config.json"),
            key_file: PathBuf::from("/etc/venpos-agent/key"),
            dedupe_file: PathBuf::from("/var/lib/venpos-agent/printed.json"),
            log_dir: PathBuf::from("/var/log/venpos-agent"),
            ipc_name: "/run/venpos-agent/agent.sock".to_owned(),
            system: true,
        }
    }

    pub fn resolve(config_dir: Option<&Path>) -> Self {
        match config_dir {
            Some(d) => Self::in_dir(d),
            None => Self::system_default(),
        }
    }

    pub fn log_file(&self) -> PathBuf {
        self.log_dir.join("agent.log")
    }
}

fn fnv1a(bytes: &[u8]) -> u32 {
    bytes
        .iter()
        .fold(0x811c_9dc5_u32, |h, b| (h ^ u32::from(*b)).wrapping_mul(0x0100_0193))
}
