//! Direct character device (`/dev/usb/lp*`). Opened for writing only, never
//! created: a missing device is an error, not a new regular file.
use super::{InstalledPrinter, PrintError, PrinterState, QueueStatus};
use crate::config::Destination;
use std::io::Write;
use std::path::Path;

pub fn print(path: &Path, data: &[u8]) -> Result<(), PrintError> {
    let mut f = std::fs::OpenOptions::new()
        .write(true)
        .create(false)
        .truncate(false)
        .open(path)
        .map_err(|e| {
            let code = if e.kind() == std::io::ErrorKind::NotFound {
                "printer_not_found"
            } else {
                "device_open_failed"
            };
            PrintError::new(code, format!("{}: {e}", path.display()))
        })?;
    f.write_all(data)
        .and_then(|()| f.flush())
        .map_err(|e| PrintError::new("device_write_failed", format!("{}: {e}", path.display())))
}

pub fn status(path: &Path) -> QueueStatus {
    match std::fs::metadata(path) {
        Ok(_) => QueueStatus::new(PrinterState::Unknown, "modo directo"),
        Err(_) => QueueStatus::new(PrinterState::NotFound, format!("{} no existe", path.display())),
    }
}

pub fn list() -> Vec<InstalledPrinter> {
    let Ok(dir) = std::fs::read_dir("/dev/usb") else {
        return Vec::new();
    };
    let mut out: Vec<InstalledPrinter> = dir
        .flatten()
        .filter(|e| e.file_name().to_string_lossy().starts_with("lp"))
        .map(|e| InstalledPrinter {
            destination: Destination::Device { path: e.path() },
            detail: "USB directo".into(),
        })
        .collect();
    out.sort_by_key(|p| p.destination.display_name());
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn missing_device_is_not_created() {
        let p = std::env::temp_dir().join(format!("venpos-nodev-{}", std::process::id()));
        let err = print(&p, b"x").unwrap_err();
        assert_eq!(err.code, "printer_not_found");
        assert!(!p.exists());
    }
}
