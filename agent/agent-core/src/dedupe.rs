//! On-disk record of jobs printed in the last 24 h (T4, AC10, AC11).
//! A job id found here is never printed again; it is only acknowledged.
use std::collections::BTreeMap;
use std::io::Write;
use std::path::{Path, PathBuf};

pub const RETENTION_MS: i64 = 24 * 60 * 60 * 1000;

#[derive(Debug, thiserror::Error)]
#[error("no se puede guardar el registro de trabajos impresos en {path}: {source}")]
pub struct DedupeError {
    pub path: PathBuf,
    pub source: std::io::Error,
}

#[derive(Debug)]
pub struct DedupeStore {
    path: PathBuf,
    /// job id → printed at (unix ms)
    entries: BTreeMap<String, i64>,
}

impl DedupeStore {
    /// Opens the store; a missing or unreadable file starts empty (logged by the caller).
    pub fn open(path: PathBuf, now_ms: i64) -> Self {
        let entries = std::fs::read(&path)
            .ok()
            .and_then(|b| serde_json::from_slice::<BTreeMap<String, i64>>(&b).ok())
            .unwrap_or_default();
        let mut s = Self { path, entries };
        s.prune(now_ms);
        s
    }

    pub fn contains(&self, id: &str, now_ms: i64) -> bool {
        self.entries.get(id).is_some_and(|&at| !expired(at, now_ms))
    }

    /// Records the id and persists before returning (fsync + rename).
    pub fn record(&mut self, id: &str, now_ms: i64) -> Result<(), DedupeError> {
        self.entries.insert(id.to_owned(), now_ms);
        self.prune(now_ms);
        let json = serde_json::to_vec(&self.entries).map_err(|e| DedupeError {
            path: self.path.clone(),
            source: std::io::Error::other(e),
        })?;
        if let Some(dir) = self.path.parent() {
            std::fs::create_dir_all(dir).map_err(|source| DedupeError {
                path: self.path.clone(),
                source,
            })?;
        }
        write_atomic(&self.path, &json).map_err(|source| DedupeError {
            path: self.path.clone(),
            source,
        })
    }

    pub fn len(&self) -> usize {
        self.entries.len()
    }

    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    fn prune(&mut self, now_ms: i64) {
        self.entries.retain(|_, at| !expired(*at, now_ms));
    }

    pub fn path(&self) -> &Path {
        &self.path
    }
}

fn expired(printed_at: i64, now_ms: i64) -> bool {
    // A clock that jumped backwards keeps the entry (never reprint).
    now_ms.checked_sub(printed_at).is_some_and(|age| age > RETENTION_MS)
}

/// Write `bytes` to `path` through a temporary file, fsync and rename.
pub fn write_atomic(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    let tmp = path.with_extension("tmp");
    {
        let mut f = std::fs::File::create(&tmp)?;
        f.write_all(bytes)?;
        f.sync_all()?;
    }
    std::fs::rename(&tmp, path)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tmp(name: &str) -> PathBuf {
        let d = std::env::temp_dir().join(format!("venpos-dedupe-{name}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&d);
        std::fs::create_dir_all(&d).unwrap();
        d.join("printed.json")
    }

    #[test]
    fn recorded_ids_survive_a_restart() {
        let p = tmp("restart");
        let now = 1_800_000_000_000;
        let mut s = DedupeStore::open(p.clone(), now);
        assert!(!s.contains("42", now));
        s.record("42", now).unwrap();
        let reopened = DedupeStore::open(p.clone(), now + 1000);
        assert!(reopened.contains("42", now + 1000));
        let _ = std::fs::remove_dir_all(p.parent().unwrap());
    }

    #[test]
    fn ids_older_than_24h_are_forgotten() {
        let p = tmp("prune");
        let now = 1_800_000_000_000;
        let mut s = DedupeStore::open(p.clone(), now);
        s.record("1", now).unwrap();
        s.record("2", now + RETENTION_MS - 1).unwrap();
        let later = now + RETENTION_MS + 1;
        assert!(!s.contains("1", later));
        assert!(s.contains("2", later));
        let reopened = DedupeStore::open(p.clone(), later);
        assert_eq!(reopened.len(), 1);
        let _ = std::fs::remove_dir_all(p.parent().unwrap());
    }
}
