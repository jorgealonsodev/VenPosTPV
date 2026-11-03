//! Reconnection backoff (A7): 1, 2, 5, 10, 30 s, then 30 s forever.
use std::time::Duration;

pub const DEFAULT_STEPS: [Duration; 5] = [
    Duration::from_secs(1),
    Duration::from_secs(2),
    Duration::from_secs(5),
    Duration::from_secs(10),
    Duration::from_secs(30),
];

#[derive(Debug, Clone)]
pub struct Backoff {
    steps: Vec<Duration>,
    index: usize,
}

impl Backoff {
    pub fn new(steps: &[Duration]) -> Self {
        Self {
            steps: steps.to_vec(),
            index: 0,
        }
    }

    /// Delay before the next attempt; advances until the last step.
    pub fn next_delay(&mut self) -> Duration {
        let Some(last) = self.steps.len().checked_sub(1) else {
            return Duration::from_secs(1);
        };
        let i = self.index.min(last);
        self.index = (i + 1).min(last);
        self.steps[i]
    }

    pub fn reset(&mut self) {
        self.index = 0;
    }
}

impl Default for Backoff {
    fn default() -> Self {
        Self::new(&DEFAULT_STEPS)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn steps_are_1_2_5_10_30_then_stay_at_30_and_reset() {
        let mut b = Backoff::default();
        let got: Vec<u64> = (0..7).map(|_| b.next_delay().as_secs()).collect();
        assert_eq!(got, vec![1, 2, 5, 10, 30, 30, 30]);
        b.reset();
        assert_eq!(b.next_delay().as_secs(), 1);
    }
}
