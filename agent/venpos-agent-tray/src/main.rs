//! Tray icon (A1, A4) and the "VenPos Agent" window for Windows and Linux; it
//! shows the service state, asks the service for a test print and lets the
//! user pair later (I1: the service redeems the code and stores its own key).
//! It never reads or writes the key (E10) and never opens the drawer (A4).
//! Closing it does not stop the service.
#![cfg_attr(windows, windows_subsystem = "windows")]

#[cfg(target_os = "linux")]
mod linux;
mod model;
#[cfg(windows)]
mod pair_dialog;
#[cfg(any(windows, target_os = "linux"))]
mod ui;
#[cfg(windows)]
mod win;
#[cfg(windows)]
mod win_desktop;

#[cfg(windows)]
fn main() {
    let paths = agent_core::paths::Paths::resolve(win::config_dir_arg().as_deref());
    if std::env::args_os().skip(1).any(|a| a == "--panel") {
        std::process::exit(ui::panel::run(paths.ipc_name.clone(), paths.log_file()));
    }
    win::run(paths);
}

#[cfg(target_os = "linux")]
fn main() {
    std::process::exit(linux::run());
}

#[cfg(not(any(windows, target_os = "linux")))]
fn main() {
    eprintln!("venpos-agent-tray: no disponible en este sistema; usa `venpos-agent status`");
    std::process::exit(2);
}
