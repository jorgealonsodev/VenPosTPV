//! Linux desktop: the tray icon (`venpos-agent-tray`, started at login) and
//! the "VenPos Agent" window (`venpos-agent-tray --panel`, from the
//! applications menu, which also starts the icon if it is not running).
//! Both run as the desktop user, talk to the service over its socket and
//! never read the key (E10).
pub mod desktop;
mod instance;
mod tray;

use agent_core::paths::Paths;
use std::path::PathBuf;

pub struct Options {
    pub paths: Paths,
    pub config_dir: Option<PathBuf>,
}

#[derive(Debug, PartialEq, Eq)]
enum Mode {
    Tray,
    Panel,
    Help,
    Version,
}

const USAGE: &str = "Uso: venpos-agent-tray [--panel] [--config-dir CARPETA]

  (sin opciones)  icono en la bandeja del sistema (se arranca al iniciar sesión)
  --panel         abre la ventana de VenPos Agent y arranca el icono si no está
  --config-dir    carpeta de una instancia de desarrollo (su agent.sock)
  --version       versión
  --help          esta ayuda

El icono y la ventana hablan con el servicio por /run/venpos-agent/agent.sock:
tu usuario tiene que estar en el grupo venpos-agent
(sudo usermod -aG venpos-agent $USER y vuelve a iniciar sesión).";

fn parse(args: impl IntoIterator<Item = std::ffi::OsString>) -> Result<(Mode, Option<PathBuf>), String> {
    let mut mode = Mode::Tray;
    let mut dir = None;
    let mut it = args.into_iter();
    while let Some(a) = it.next() {
        match a.to_str() {
            Some("--panel") => mode = Mode::Panel,
            Some("--help" | "-h") => return Ok((Mode::Help, None)),
            Some("--version" | "-V") => return Ok((Mode::Version, None)),
            Some("--config-dir") => dir = Some(it.next().ok_or("falta la carpeta tras --config-dir")?.into()),
            _ => return Err(format!("opción desconocida: {}", a.to_string_lossy())),
        }
    }
    Ok((mode, dir))
}

fn is_root() -> bool {
    use std::os::unix::fs::MetadataExt;
    std::fs::metadata("/proc/self").is_ok_and(|m| m.uid() == 0)
}

/// The window starts the icon too, unless one is already running.
fn ensure_tray(opts: &Options) {
    let Some(dir) = instance::runtime_dir() else {
        return;
    };
    if instance::is_locked(&instance::tray_lock_path(&dir)) {
        return;
    }
    let Ok(exe) = std::env::current_exe() else {
        return;
    };
    let mut args: Vec<std::ffi::OsString> = Vec::new();
    if let Some(d) = &opts.config_dir {
        args.extend(["--config-dir".into(), d.clone().into_os_string()]);
    }
    let args: Vec<&std::ffi::OsStr> = args.iter().map(|a| a.as_os_str()).collect();
    desktop::spawn_detached(exe.as_os_str(), &args);
}

pub fn run() -> i32 {
    let (mode, config_dir) = match parse(std::env::args_os().skip(1)) {
        Ok(p) => p,
        Err(e) => {
            eprintln!("venpos-agent-tray: {e}\n\n{USAGE}");
            return 2;
        }
    };
    match mode {
        Mode::Help => {
            println!("{USAGE}");
            return 0;
        }
        Mode::Version => {
            println!("venpos-agent-tray {}", agent_core::VERSION);
            return 0;
        }
        Mode::Tray | Mode::Panel => {}
    }
    if is_root() {
        eprintln!(
            "venpos-agent-tray: no lo ejecutes como administrador; ábrelo con tu usuario \
             (para configurar como administrador: sudo venpos-agent-setup)"
        );
        return 1;
    }
    let opts = Options {
        paths: Paths::resolve(config_dir.as_deref()),
        config_dir,
    };
    if mode == Mode::Panel {
        ensure_tray(&opts);
        return crate::ui::panel::run(opts.paths.ipc_name.clone(), opts.paths.log_file());
    }
    tray::run(&opts)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(a: &[&str]) -> Vec<std::ffi::OsString> {
        a.iter().map(Into::into).collect()
    }

    #[test]
    fn command_line() {
        assert_eq!(parse(args(&[])), Ok((Mode::Tray, None)));
        assert_eq!(parse(args(&["--panel"])), Ok((Mode::Panel, None)));
        assert_eq!(
            parse(args(&["--panel", "--config-dir", "/tmp/dev"])),
            Ok((Mode::Panel, Some("/tmp/dev".into())))
        );
        assert_eq!(parse(args(&["--help"])), Ok((Mode::Help, None)));
        assert_eq!(
            parse(args(&["--config-dir"])),
            Err("falta la carpeta tras --config-dir".into())
        );
        assert_eq!(parse(args(&["--bogus"])), Err("opción desconocida: --bogus".into()));
    }
}
