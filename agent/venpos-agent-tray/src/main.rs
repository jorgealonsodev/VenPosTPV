//! Tray icon (A1, A4) for Windows and Linux (plus the Linux window); it shows the service state,
//! asks the service for a test print and lets the user pair later (I1: the
//! service redeems the code and stores its own key). It never reads or
//! writes the key (E10) and never opens the drawer (A4). Closing it does not
//! stop the service.
#![cfg_attr(windows, windows_subsystem = "windows")]

#[cfg(target_os = "linux")]
mod linux;
mod model;
#[cfg(windows)]
mod pair_dialog;
#[cfg(windows)]
mod win;

#[cfg(windows)]
fn main() {
    win::run();
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
