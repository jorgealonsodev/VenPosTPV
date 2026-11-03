//! Tray icon (A1, A4). Windows only in phase 1; it shows the service state,
//! asks the service for a test print and lets the user pair later (I1: the
//! service redeems the code and stores its own key). It never reads or
//! writes the key (E10) and never opens the drawer (A4). Closing it does not
//! stop the service.
#![cfg_attr(windows, windows_subsystem = "windows")]

mod model;
#[cfg(windows)]
mod pair_dialog;
#[cfg(windows)]
mod win;

#[cfg(windows)]
fn main() {
    win::run();
}

#[cfg(not(windows))]
fn main() {
    eprintln!("venpos-agent-tray: no disponible en esta fase (icono de Linux en la fase 2); usa `venpos-agent status`");
    std::process::exit(2);
}
