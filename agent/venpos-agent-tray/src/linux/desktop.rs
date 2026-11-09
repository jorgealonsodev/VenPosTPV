//! Small desktop helpers run as commands: `xdg-open`, `notify-send` and the
//! terminal assistant fallback. Command construction (pure, tested) is
//! separated from execution.
use std::ffi::OsStr;
use std::process::{Command, Stdio};

pub const TITLE: &str = "VenPos Agent";
pub const ASSISTANT: &str = "venpos-agent-setup";

/// Terminal emulators tried, in order, to open the terminal assistant.
pub const TERMINALS: [&str; 4] = ["x-terminal-emulator", "gnome-terminal", "konsole", "xterm"];

fn argv<const N: usize>(parts: [&str; N]) -> Vec<String> {
    parts.into_iter().map(str::to_owned).collect()
}

/// Restarting the service needs root: `pkexec` asks for the administrator password
/// in a desktop dialog (polkit). None without pkexec.
pub fn restart_service_argv(has: impl Fn(&str) -> bool) -> Option<Vec<String>> {
    has("pkexec").then(|| argv(["pkexec", "systemctl", "restart", "venpos-agent"]))
}

/// Runs the restart and waits for it (the dialog may take a while); true when it worked.
pub fn restart_service() -> Result<(), String> {
    let Some(cmd) = restart_service_argv(on_path) else {
        return Err("Falta pkexec: reinícialo con sudo systemctl restart venpos-agent".to_owned());
    };
    match Command::new(&cmd[0]).args(&cmd[1..]).stdin(Stdio::null()).status() {
        Ok(st) if st.success() => Ok(()),
        Ok(_) => Err("No se reinició el servicio (contraseña cancelada o incorrecta)".to_owned()),
        Err(e) => Err(format!("No se pudo reiniciar el servicio: {e}")),
    }
}

/// `pkexec systemctl stop venpos-agent`: nothing prints until it is started again
/// (or the computer restarts: the service stays enabled).
pub fn stop_service_argv(has: impl Fn(&str) -> bool) -> Option<Vec<String>> {
    has("pkexec").then(|| argv(["pkexec", "systemctl", "stop", "venpos-agent"]))
}

/// Runs the stop and waits for it (the password dialog may take a while).
pub fn stop_service() -> Result<(), String> {
    let Some(cmd) = stop_service_argv(on_path) else {
        return Err("Falta pkexec: detenlo con sudo systemctl stop venpos-agent".to_owned());
    };
    match Command::new(&cmd[0]).args(&cmd[1..]).stdin(Stdio::null()).status() {
        Ok(st) if st.success() => Ok(()),
        Ok(_) => Err("No se detuvo el servicio (contraseña cancelada o incorrecta)".to_owned()),
        Err(e) => Err(format!("No se pudo detener el servicio: {e}")),
    }
}

/// The terminal assistant in the first available terminal emulator.
pub fn assistant_argv(has: impl Fn(&str) -> bool) -> Option<Vec<String>> {
    let term = TERMINALS.into_iter().find(|t| has(t))?;
    let sep = if term == "gnome-terminal" { "--" } else { "-e" };
    Some(argv([term, sep, ASSISTANT]))
}

/// A desktop notification, when `notify-send` exists.
pub fn notify_argv(has: impl Fn(&str) -> bool, text: &str, warn: bool) -> Option<Vec<String>> {
    has("notify-send").then(|| {
        argv([
            "notify-send",
            "--app-name",
            TITLE,
            "--icon",
            if warn { "dialog-warning" } else { "venpos-agent" },
            TITLE,
            text,
        ])
    })
}

/// Whether `program` is an executable file on the PATH.
pub fn on_path(program: &str) -> bool {
    use std::os::unix::fs::PermissionsExt;
    let Some(path) = std::env::var_os("PATH") else {
        return false;
    };
    std::env::split_paths(&path).any(|dir| {
        std::fs::metadata(dir.join(program)).is_ok_and(|m| m.is_file() && m.permissions().mode() & 0o111 != 0)
    })
}

/// Starts a program without waiting; a helper thread reaps it.
pub fn spawn_detached(program: &OsStr, args: &[&OsStr]) -> bool {
    match Command::new(program)
        .args(args)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
    {
        Ok(mut child) => {
            std::thread::spawn(move || child.wait());
            true
        }
        Err(_) => false,
    }
}

fn spawn_argv(argv: &[String]) -> bool {
    let Some((program, args)) = argv.split_first() else {
        return false;
    };
    let args: Vec<&OsStr> = args.iter().map(OsStr::new).collect();
    spawn_detached(OsStr::new(program), &args)
}

/// `xdg-open` for a validated https URL or the log file path.
pub fn open(target: &str) -> bool {
    spawn_detached(OsStr::new("xdg-open"), &[OsStr::new(target)])
}

/// Shows `text` as a notification; always also on stderr.
pub fn notify(text: &str, warn: bool) {
    eprintln!("venpos-agent-tray: {text}");
    if let Some(a) = notify_argv(on_path, text, warn) {
        spawn_argv(&a);
    }
}

/// Opens the terminal assistant; false when no terminal emulator exists.
pub fn open_assistant() -> bool {
    assistant_argv(on_path).is_some_and(|a| spawn_argv(&a))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn having(names: &'static [&'static str]) -> impl Fn(&str) -> bool {
        move |p| names.contains(&p)
    }

    #[test]
    fn restart_uses_pkexec_only_when_present() {
        assert_eq!(
            restart_service_argv(having(&["pkexec"])),
            Some(vec![
                "pkexec".to_owned(),
                "systemctl".to_owned(),
                "restart".to_owned(),
                "venpos-agent".to_owned()
            ])
        );
        assert_eq!(restart_service_argv(having(&[])), None);
    }

    #[test]
    fn stop_uses_pkexec_only_when_present() {
        assert_eq!(
            stop_service_argv(having(&["pkexec"])),
            Some(vec![
                "pkexec".to_owned(),
                "systemctl".to_owned(),
                "stop".to_owned(),
                "venpos-agent".to_owned()
            ])
        );
        assert_eq!(stop_service_argv(having(&[])), None);
    }

    #[test]
    fn terminal_assistant_uses_the_first_terminal_found() {
        assert_eq!(
            assistant_argv(having(&["xterm", "gnome-terminal", "x-terminal-emulator"])),
            Some(argv(["x-terminal-emulator", "-e", "venpos-agent-setup"]))
        );
        assert_eq!(
            assistant_argv(having(&["xterm", "gnome-terminal"])),
            Some(argv(["gnome-terminal", "--", "venpos-agent-setup"]))
        );
        assert_eq!(
            assistant_argv(having(&["xterm", "konsole"])),
            Some(argv(["konsole", "-e", "venpos-agent-setup"]))
        );
        assert_eq!(
            assistant_argv(having(&["xterm"])),
            Some(argv(["xterm", "-e", "venpos-agent-setup"]))
        );
        assert_eq!(assistant_argv(having(&[])), None);
    }

    #[test]
    fn notifications_only_with_notify_send() {
        assert_eq!(notify_argv(having(&[]), "hola", false), None);
        assert_eq!(
            notify_argv(having(&["notify-send"]), "Prueba enviada", false),
            Some(argv([
                "notify-send",
                "--app-name",
                "VenPos Agent",
                "--icon",
                "venpos-agent",
                "VenPos Agent",
                "Prueba enviada"
            ]))
        );
        assert_eq!(
            notify_argv(having(&["notify-send"]), "Mal", true).unwrap()[4],
            "dialog-warning"
        );
    }
}
