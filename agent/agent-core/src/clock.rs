//! Injected time: wall clock for expiry/dedupe, monotonic for rate limits.
use std::sync::Mutex;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

pub trait Clock: Send + Sync {
    /// Wall clock, milliseconds since the Unix epoch.
    fn now_unix_ms(&self) -> i64;
    /// Monotonic time since an arbitrary origin.
    fn monotonic(&self) -> Duration;
}

pub struct SystemClock {
    origin: Instant,
}

impl SystemClock {
    pub fn new() -> Self {
        Self { origin: Instant::now() }
    }
}

impl Default for SystemClock {
    fn default() -> Self {
        Self::new()
    }
}

impl Clock for SystemClock {
    fn now_unix_ms(&self) -> i64 {
        match SystemTime::now().duration_since(UNIX_EPOCH) {
            Ok(d) => i64::try_from(d.as_millis()).unwrap_or(i64::MAX),
            Err(_) => 0,
        }
    }

    fn monotonic(&self) -> Duration {
        self.origin.elapsed()
    }
}

/// Test clock: both clocks move only when told to.
pub struct FakeClock {
    inner: Mutex<(i64, Duration)>,
}

impl FakeClock {
    pub fn new(now_unix_ms: i64) -> Self {
        Self {
            inner: Mutex::new((now_unix_ms, Duration::ZERO)),
        }
    }

    pub fn advance(&self, d: Duration) {
        let mut g = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        let ms = i64::try_from(d.as_millis()).unwrap_or(i64::MAX);
        g.0 = g.0.checked_add(ms).unwrap_or(i64::MAX);
        g.1 = g.1.checked_add(d).unwrap_or(g.1);
    }
}

impl Clock for FakeClock {
    fn now_unix_ms(&self) -> i64 {
        self.inner.lock().unwrap_or_else(|e| e.into_inner()).0
    }

    fn monotonic(&self) -> Duration {
        self.inner.lock().unwrap_or_else(|e| e.into_inner()).1
    }
}
