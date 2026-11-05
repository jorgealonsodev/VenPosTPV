//! One tray icon and one window per desktop session, kept in
//! `$XDG_RUNTIME_DIR` (private to the user): a lock file for the tray and a
//! socket the open window listens on, so a second launch only brings it to
//! the front.
use std::fs::File;
use std::io::{self, BufRead, BufReader, Write};
use std::os::unix::net::{UnixListener, UnixStream};
use std::path::{Path, PathBuf};

const TRAY_LOCK: &str = "venpos-agent-tray.lock";
const WINDOW_SOCKET: &str = "venpos-agent-window.sock";

pub fn runtime_dir() -> Option<PathBuf> {
    std::env::var_os("XDG_RUNTIME_DIR")
        .map(PathBuf::from)
        .filter(|d| d.is_dir())
}

pub fn tray_lock_path(dir: &Path) -> PathBuf {
    dir.join(TRAY_LOCK)
}

pub fn window_socket_path(dir: &Path) -> PathBuf {
    dir.join(WINDOW_SOCKET)
}

/// Held for the life of the tray process; the kernel releases it on exit.
pub struct Lock(#[allow(dead_code)] File);

/// `Ok(None)` when another process holds the lock.
pub fn try_lock(path: &Path) -> io::Result<Option<Lock>> {
    let f = File::options().create(true).truncate(false).write(true).open(path)?;
    match f.try_lock() {
        Ok(()) => Ok(Some(Lock(f))),
        Err(std::fs::TryLockError::WouldBlock) => Ok(None),
        Err(std::fs::TryLockError::Error(e)) => Err(e),
    }
}

/// Whether a tray process holds the lock right now.
pub fn is_locked(path: &Path) -> bool {
    matches!(try_lock(path), Ok(None))
}

/// Asks an open window to come to the front; false when there is none.
pub fn raise_existing_window(socket: &Path) -> bool {
    let Ok(mut s) = UnixStream::connect(socket) else {
        return false;
    };
    let _ = s.set_read_timeout(Some(std::time::Duration::from_secs(2)));
    if s.write_all(b"show\n").is_err() {
        return false;
    }
    let mut reply = String::new();
    BufReader::new(s).read_line(&mut reply).is_ok() && reply.trim() == "ok"
}

/// Listens for "show" from later launches; `on_show` runs on a helper thread.
pub fn listen_for_raise(socket: &Path, on_show: impl Fn() + Send + 'static) -> io::Result<()> {
    // Only reached when no window answered, so a leftover file is stale.
    let _ = std::fs::remove_file(socket);
    let listener = UnixListener::bind(socket)?;
    std::thread::Builder::new().name("raise".into()).spawn(move || {
        for conn in listener.incoming().flatten() {
            let mut line = String::new();
            let mut reader = BufReader::new(&conn);
            if reader.read_line(&mut line).is_ok() && line.trim() == "show" {
                on_show();
                let _ = (&conn).write_all(b"ok\n");
            }
        }
    })?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Arc;
    use std::sync::atomic::{AtomicUsize, Ordering};

    fn scratch(name: &str) -> PathBuf {
        let d = std::env::temp_dir().join(format!("venpos-tray-{name}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&d);
        std::fs::create_dir_all(&d).unwrap();
        d
    }

    #[test]
    fn only_one_tray_holds_the_lock() {
        let d = scratch("lock");
        let path = tray_lock_path(&d);
        assert!(!is_locked(&path));
        let held = try_lock(&path).unwrap();
        assert!(held.is_some());
        assert!(try_lock(&path).unwrap().is_none(), "second tray refused");
        assert!(is_locked(&path));
        drop(held);
        assert!(!is_locked(&path), "released when the tray exits");
        std::fs::remove_dir_all(&d).unwrap();
    }

    #[test]
    fn a_second_launch_raises_the_open_window() {
        let d = scratch("raise");
        let sock = window_socket_path(&d);
        assert!(!raise_existing_window(&sock), "no window yet");
        std::fs::write(&sock, b"stale").unwrap();
        let shown = Arc::new(AtomicUsize::new(0));
        let s = Arc::clone(&shown);
        listen_for_raise(&sock, move || {
            s.fetch_add(1, Ordering::SeqCst);
        })
        .unwrap();
        assert!(raise_existing_window(&sock));
        assert_eq!(shown.load(Ordering::SeqCst), 1);
        std::fs::remove_dir_all(&d).unwrap();
    }
}
