//! The "VenPos Agent" window shared by Linux and Windows (egui): status,
//! printers, test prints and pairing. `logic` holds the texts and choices
//! (also the Linux tray menu), `client` the requests to the service and
//! `panel` the window. What depends on the desktop (opening a URL, starting
//! the service, raising an open window) lives in `os`.
pub mod client;
// The Linux tray menu helpers are unused on Windows.
#[cfg_attr(windows, allow(dead_code))]
pub mod logic;
pub mod panel;

#[cfg(target_os = "linux")]
pub(crate) use crate::linux::desktop as os;
#[cfg(windows)]
pub(crate) use crate::win_desktop as os;
