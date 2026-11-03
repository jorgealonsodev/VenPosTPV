//! Linux CUPS: `lp -d <queue> -o raw`, data on stdin, no shell (A5, A9).
use super::{InstalledPrinter, PrintError, PrinterState, QueueStatus};
use crate::config::Destination;
use std::ffi::OsString;
use std::io::Write;
use std::process::{Command, Stdio};

/// Arguments for `lp`; the queue was validated (no leading `-`, no spaces).
pub fn print_argv(queue: &str, title: &str) -> Vec<OsString> {
    ["-d", queue, "-o", "raw", "-t", title, "--", "-"]
        .into_iter()
        .map(OsString::from)
        .collect()
}

pub fn print(queue: &str, title: &str, data: &[u8]) -> Result<Option<String>, PrintError> {
    let mut child = Command::new("lp")
        .args(print_argv(queue, title))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| PrintError::new("spooler_unavailable", format!("no se puede ejecutar lp: {e}")))?;
    if let Some(mut stdin) = child.stdin.take() {
        stdin
            .write_all(data)
            .map_err(|e| PrintError::new("spooler_write_failed", format!("lp: {e}")))?;
    }
    let out = child
        .wait_with_output()
        .map_err(|e| PrintError::new("spooler_write_failed", format!("lp: {e}")))?;
    if !out.status.success() {
        let err = String::from_utf8_lossy(&out.stderr);
        let code = if err.contains("does not exist") || err.contains("Unknown destination") || err.contains("no existe")
        {
            "printer_not_found"
        } else {
            "spooler_rejected"
        };
        return Err(PrintError::new(code, crate::truncate_chars(err.trim(), 300)));
    }
    Ok(parse_request_id(&String::from_utf8_lossy(&out.stdout)))
}

/// `request id is Epson-123 (1 file(s))` → `Epson-123`.
pub fn parse_request_id(stdout: &str) -> Option<String> {
    let rest = stdout.split("request id is ").nth(1)?;
    let id = rest.split_whitespace().next()?;
    (!id.is_empty() && !id.starts_with('-')).then(|| id.to_owned())
}

pub fn cancel(request_id: &str) {
    if request_id.starts_with('-') {
        return;
    }
    let _ = Command::new("cancel")
        .arg(request_id)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status();
}

pub fn status(queue: &str) -> QueueStatus {
    match Command::new("lpstat")
        .args(["-p", queue, "-l"])
        .stdin(Stdio::null())
        .output()
    {
        Ok(out) => parse_lpstat_p(
            out.status.success(),
            &String::from_utf8_lossy(&out.stdout),
            &String::from_utf8_lossy(&out.stderr),
        ),
        Err(e) => QueueStatus::new(PrinterState::Unknown, format!("lpstat: {e}")),
    }
}

/// Maps `lpstat -p <queue> -l` output to a queue state.
pub fn parse_lpstat_p(success: bool, stdout: &str, stderr: &str) -> QueueStatus {
    let first = stdout.lines().find(|l| l.starts_with("printer ")).unwrap_or("");
    if !success || first.is_empty() {
        let err = stderr.trim();
        if err.contains("Invalid destination")
            || err.contains("does not exist")
            || err.contains("no existe")
            || err.contains("Unknown destination")
        {
            return QueueStatus::new(PrinterState::NotFound, "la cola no existe");
        }
        return QueueStatus::new(PrinterState::Unknown, crate::truncate_chars(err, 200));
    }
    let lower = stdout.to_ascii_lowercase();
    let reason = stdout
        .lines()
        .skip(1)
        .map(str::trim)
        .find(|l| !l.is_empty() && !l.contains(':'))
        .unwrap_or("");
    if first.contains(" disabled") {
        return QueueStatus::new(
            PrinterState::Paused,
            if reason.is_empty() { "cola en pausa" } else { reason },
        );
    }
    if lower.contains("offline")
        || lower.contains("not connected")
        || lower.contains("waiting for printer to become available")
        || lower.contains("unplugged")
    {
        return QueueStatus::new(PrinterState::Offline, reason);
    }
    if lower.contains("error") || lower.contains("stopped") {
        return QueueStatus::new(PrinterState::Error, reason);
    }
    QueueStatus::new(PrinterState::Ready, "")
}

/// `lpstat -e` lists queue names, one per line.
pub fn parse_lpstat_e(stdout: &str) -> Vec<String> {
    stdout
        .lines()
        .map(str::trim)
        .filter(|l| !l.is_empty() && !l.starts_with('-') && !l.contains(char::is_whitespace))
        .map(str::to_owned)
        .collect()
}

pub fn list() -> Vec<InstalledPrinter> {
    let Ok(out) = Command::new("lpstat").arg("-e").stdin(Stdio::null()).output() else {
        return Vec::new();
    };
    parse_lpstat_e(&String::from_utf8_lossy(&out.stdout))
        .into_iter()
        .map(|queue| InstalledPrinter {
            destination: Destination::Cups { queue },
            detail: "CUPS".into(),
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn argv_is_raw_to_the_queue_with_stdin_and_no_shell() {
        let argv: Vec<String> = print_argv("Epson_TM-T20II", "VenPos ticket 42")
            .into_iter()
            .map(|a| a.into_string().unwrap())
            .collect();
        assert_eq!(
            argv,
            ["-d", "Epson_TM-T20II", "-o", "raw", "-t", "VenPos ticket 42", "--", "-"]
        );
    }

    #[test]
    fn request_id_is_parsed() {
        assert_eq!(
            parse_request_id("request id is Epson-123 (1 file(s))\n").as_deref(),
            Some("Epson-123")
        );
        assert_eq!(parse_request_id("garbage"), None);
    }

    #[test]
    fn lpstat_states() {
        let idle = "printer Epson is idle.  enabled since Tue 03 Nov 2026\n";
        assert_eq!(parse_lpstat_p(true, idle, "").state, PrinterState::Ready);
        let printing = "printer Epson now printing Epson-7.  enabled since Tue\n";
        assert_eq!(parse_lpstat_p(true, printing, "").state, PrinterState::Ready);
        let paused = "printer Epson disabled since Tue 03 Nov 2026 -\n\tPaused\n";
        assert_eq!(parse_lpstat_p(true, paused, "").state, PrinterState::Paused);
        let offline = "printer Epson is idle.  enabled since Tue\n\tWaiting for printer to become available.\n";
        assert_eq!(parse_lpstat_p(true, offline, "").state, PrinterState::Offline);
        let off2 = "printer Epson is idle.  enabled since Tue\n\tThe printer is not connected.\n";
        assert_eq!(parse_lpstat_p(true, off2, "").state, PrinterState::Offline);
        let missing = "lpstat: Invalid destination name in list \"Nope\".\n";
        assert_eq!(parse_lpstat_p(false, "", missing).state, PrinterState::NotFound);
    }

    #[test]
    fn lpstat_e_lists_queues() {
        assert_eq!(parse_lpstat_e("Epson_TM-T20II\nPDF\n\n"), vec!["Epson_TM-T20II", "PDF"]);
    }
}
