//! File log with size rotation, 5 MB × 3 (A8). `tracing-appender` only
//! rotates by time, so rotation is done here and `tracing-appender`
//! provides the non-blocking writer. No `log`→`tracing` bridge is installed,
//! so dependencies (ureq) cannot write headers to this log (E8).
use std::fs::{File, OpenOptions};
use std::io::{self, Write};
use std::path::{Path, PathBuf};

pub const MAX_BYTES: u64 = 5 * 1024 * 1024;
pub const FILES: usize = 3;

/// `agent.log`, `agent.log.1`, `agent.log.2`; the oldest is dropped.
pub struct RotatingFile {
    path: PathBuf,
    max_bytes: u64,
    files: usize,
    file: Option<File>,
    size: u64,
}

impl RotatingFile {
    pub fn open(path: PathBuf, max_bytes: u64, files: usize) -> io::Result<Self> {
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir)?;
        }
        let file = OpenOptions::new().create(true).append(true).open(&path)?;
        share_with_group(&file)?;
        let size = file.metadata()?.len();
        Ok(Self {
            path,
            max_bytes,
            files: files.max(1),
            file: Some(file),
            size,
        })
    }

    fn rotated(&self, n: usize) -> PathBuf {
        let mut s = self.path.as_os_str().to_owned();
        s.push(format!(".{n}"));
        PathBuf::from(s)
    }

    fn rotate(&mut self) -> io::Result<()> {
        if let Some(f) = self.file.take() {
            let _ = f.sync_all();
        }
        // Oldest first: .{n-1} is dropped, .{i} → .{i+1}, current → .1
        if self.files > 1 {
            let _ = std::fs::remove_file(self.rotated(self.files - 1));
            for i in (1..self.files - 1).rev() {
                let from = self.rotated(i);
                if from.exists() {
                    std::fs::rename(&from, self.rotated(i + 1))?;
                }
            }
            std::fs::rename(&self.path, self.rotated(1))?;
        } else {
            std::fs::remove_file(&self.path)?;
        }
        let file = OpenOptions::new().create(true).append(true).open(&self.path)?;
        share_with_group(&file)?;
        self.file = Some(file);
        self.size = 0;
        Ok(())
    }
}

impl Write for RotatingFile {
    fn write(&mut self, buf: &[u8]) -> io::Result<usize> {
        let len = u64::try_from(buf.len()).unwrap_or(u64::MAX);
        if self.size > 0 && self.size.saturating_add(len) > self.max_bytes {
            self.rotate()?;
        }
        let file = self.file.as_mut().ok_or_else(|| io::Error::other("log cerrado"))?;
        let n = file.write(buf)?;
        self.size = self.size.saturating_add(u64::try_from(n).unwrap_or(u64::MAX));
        Ok(n)
    }

    fn flush(&mut self) -> io::Result<()> {
        match self.file.as_mut() {
            Some(f) => f.flush(),
            None => Ok(()),
        }
    }
}

/// Installs the global subscriber: file (rotating, non-blocking) and,
/// when `stderr` is true, a copy on stderr. Keep the guard alive.
pub fn init(log_file: &Path, stderr: bool) -> io::Result<tracing_appender::non_blocking::WorkerGuard> {
    use tracing_subscriber::layer::SubscriberExt;
    use tracing_subscriber::util::SubscriberInitExt;
    let file = RotatingFile::open(log_file.to_path_buf(), MAX_BYTES, FILES)?;
    let (nb, guard) = tracing_appender::non_blocking(file);
    let file_layer = tracing_subscriber::fmt::layer()
        .with_writer(nb)
        .with_ansi(false)
        .with_target(false);
    let err_layer = stderr.then(|| {
        tracing_subscriber::fmt::layer()
            .with_writer(io::stderr)
            .with_target(false)
    });
    tracing_subscriber::registry()
        .with(file_layer)
        .with(err_layer)
        .try_init()
        .map_err(|e| io::Error::other(e.to_string()))?;
    Ok(guard)
}

/// Stderr only (short CLI commands).
pub fn init_stderr() {
    let _ = tracing_subscriber::fmt()
        .with_writer(io::stderr)
        .with_target(false)
        .try_init();
}

/// The service runs with UMask=0077, so a new log is 0600 and the till user's
/// window cannot open it. 0640 lets the venpos-agent group read it (the group a
/// desktop user joins to use the agent); the configuration and its key stay
/// private. set_permissions is not limited by the umask.
#[cfg(unix)]
fn share_with_group(file: &File) -> io::Result<()> {
    use std::os::unix::fs::PermissionsExt;
    file.set_permissions(std::fs::Permissions::from_mode(0o640))
}

#[cfg(not(unix))]
fn share_with_group(_file: &File) -> io::Result<()> {
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rotates_by_size_and_keeps_three_files() {
        let dir = std::env::temp_dir().join(format!("venpos-log-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        let path = dir.join("agent.log");
        let mut w = RotatingFile::open(path.clone(), 100, 3).unwrap();
        for i in 0..20 {
            writeln!(w, "{i:02} {}", "x".repeat(40)).unwrap();
        }
        w.flush().unwrap();
        let mut names: Vec<String> = std::fs::read_dir(&dir)
            .unwrap()
            .map(|e| e.unwrap().file_name().into_string().unwrap())
            .collect();
        names.sort();
        assert_eq!(names, ["agent.log", "agent.log.1", "agent.log.2"]);
        for n in &names {
            assert!(std::fs::metadata(dir.join(n)).unwrap().len() <= 100, "{n} too big");
        }
        #[cfg(unix)]
        for n in &names {
            use std::os::unix::fs::PermissionsExt;
            let mode = std::fs::metadata(dir.join(n)).unwrap().permissions().mode() & 0o777;
            assert_eq!(mode, 0o640, "{n}: the venpos-agent group reads the log");
        }
        let newest = std::fs::read_to_string(&path).unwrap();
        assert!(newest.contains("19 "), "{newest}");
        let _ = std::fs::remove_dir_all(&dir);
    }
}
