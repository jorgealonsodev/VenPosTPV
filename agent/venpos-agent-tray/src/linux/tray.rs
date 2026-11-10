//! Linux tray icon: a StatusNotifierItem over the session D-Bus (KDE, XFCE,
//! Cinnamon, MATE, Budgie; GNOME with the AppIndicator extension, which
//! Ubuntu ships). Menu clicks are sent to the main thread, which talks to
//! the service, so the D-Bus thread never blocks.
use crate::linux::{Options, desktop, instance};
use crate::model;
use crate::ui::client;
use crate::ui::logic::{self, Snapshot, TrayAction, TrayEntry};
use agent_core::config::Target;
use agent_core::printer::{InstalledPrinter, PrinterBackend, SystemPrinter};
use agent_core::state::Light;
use ksni::blocking::TrayMethods as _;
use std::sync::mpsc;
use std::time::Duration;

const POLL: Duration = Duration::from_secs(5);
/// Printers are listed (lpstat) every this many polls, and after each click.
const LIST_EVERY: u32 = 6;
/// The desktop may still be starting at login: retry the tray host for a minute.
const HOST_RETRIES: u32 = 12;

struct VenposTray {
    snapshot: Snapshot,
    printers: Vec<InstalledPrinter>,
    tx: mpsc::Sender<TrayAction>,
}

/// ARGB32 in network byte order, from the RGBA disc of the Windows tray.
fn pixmap(light: Light, size: u32) -> ksni::Icon {
    let mut data = model::icon_rgba(light, size);
    for px in data.as_chunks_mut::<4>().0 {
        px.rotate_right(1);
    }
    let side = i32::try_from(size).unwrap_or(0);
    ksni::Icon {
        width: side,
        height: side,
        data,
    }
}

fn item(label: &str, enabled: bool, action: Option<TrayAction>) -> ksni::MenuItem<VenposTray> {
    ksni::menu::StandardItem {
        label: label.replace('_', "__"),
        enabled,
        activate: Box::new(move |t: &mut VenposTray| {
            if let Some(a) = &action {
                let _ = t.tx.send(a.clone());
            }
        }),
        ..Default::default()
    }
    .into()
}

fn printers_submenu(
    label: String,
    target: Target,
    options: Vec<logic::PrinterOption>,
    selected: Option<usize>,
    other_current: Option<String>,
) -> ksni::MenuItem<VenposTray> {
    use ksni::menu::{RadioGroup, RadioItem, SubMenu};
    let mut sub = Vec::new();
    if let Some(c) = other_current {
        sub.push(item(&format!("Elegida: {c}"), false, None));
    }
    if options.is_empty() {
        sub.push(item("(no hay impresoras instaladas)", false, None));
    } else {
        let items = options
            .iter()
            .map(|o| RadioItem {
                label: o.label.replace('_', "__"),
                ..Default::default()
            })
            .collect();
        let dests: Vec<_> = options.into_iter().map(|o| o.destination).collect();
        sub.push(
            RadioGroup {
                // Out of range: nothing checked.
                selected: selected.unwrap_or(usize::MAX),
                select: Box::new(move |t: &mut VenposTray, i| {
                    if let Some(d) = dests.get(i) {
                        let _ = t.tx.send(TrayAction::Choose(target, d.clone()));
                    }
                }),
                options: items,
            }
            .into(),
        );
    }
    sub.push(ksni::MenuItem::Separator);
    sub.push(item(logic::DEVICE_LABEL, true, Some(TrayAction::OpenWindow)));
    sub.push(item(logic::NETWORK_LABEL, true, Some(TrayAction::OpenWindow)));
    SubMenu {
        label,
        submenu: sub,
        ..Default::default()
    }
    .into()
}

impl ksni::Tray for VenposTray {
    fn id(&self) -> String {
        "venpos-agent".into()
    }

    fn title(&self) -> String {
        "VenPos Agent".into()
    }

    fn category(&self) -> ksni::Category {
        ksni::Category::Hardware
    }

    fn icon_pixmap(&self) -> Vec<ksni::Icon> {
        [22, 32, 48].map(|s| pixmap(self.snapshot.light(), s)).into()
    }

    fn tool_tip(&self) -> ksni::ToolTip {
        ksni::ToolTip {
            title: "VenPos Agent".into(),
            description: self.snapshot.status_line(),
            ..Default::default()
        }
    }

    /// Left click opens the window.
    fn activate(&mut self, _x: i32, _y: i32) {
        let _ = self.tx.send(TrayAction::OpenWindow);
    }

    fn menu(&self) -> Vec<ksni::MenuItem<Self>> {
        logic::tray_menu(&self.snapshot, &self.printers)
            .into_iter()
            .map(|e| match e {
                TrayEntry::Action(label, a) => item(&label, true, Some(a)),
                TrayEntry::Text(t) => item(&t, false, None),
                TrayEntry::Separator => ksni::MenuItem::Separator,
                TrayEntry::Printers {
                    label,
                    target,
                    options,
                    selected,
                    other_current,
                } => printers_submenu(label, target, options, selected, other_current),
            })
            .collect()
    }
}

/// Starts `venpos-agent-tray --panel` (it raises an already open window).
pub fn open_window(opts: &Options) {
    let Ok(exe) = std::env::current_exe() else {
        return;
    };
    let mut args = vec![std::ffi::OsString::from("--panel")];
    if let Some(d) = &opts.config_dir {
        args.extend(["--config-dir".into(), d.clone().into_os_string()]);
    }
    let args: Vec<&std::ffi::OsStr> = args.iter().map(|a| a.as_os_str()).collect();
    desktop::spawn_detached(exe.as_os_str(), &args);
}

fn perform(opts: &Options, snapshot: &Snapshot, action: TrayAction) {
    let ipc_name = &opts.paths.ipc_name;
    match action {
        TrayAction::OpenWindow => open_window(opts),
        TrayAction::Choose(target, dest) => {
            let n = client::choose(ipc_name, target, dest);
            if n.warn {
                desktop::notify(&n.text, true);
            }
        }
        TrayAction::TestPrint(target) => {
            let n = client::test_print(ipc_name, target);
            desktop::notify(&n.text, n.warn);
        }
        TrayAction::OpenSettings => match logic::settings_target(snapshot.server()) {
            Ok(url) => {
                desktop::open(&url);
            }
            Err(msg) => desktop::notify(msg, true),
        },
        TrayAction::OpenLog => {
            desktop::open(&client::log_path(ipc_name, &opts.paths.log_file()));
        }
        TrayAction::Quit => {}
    }
}

const MSG_NO_HOST: &str = "No se puede mostrar el icono en la bandeja del sistema: este escritorio no tiene \
     bandeja compatible. En GNOME activa la extensión «AppIndicator and KStatusNotifierItem Support» \
     (Ubuntu ya la trae). Puedes usar VenPos Agent desde el cajón de aplicaciones.";

pub fn run(opts: &Options) -> i32 {
    let _lock = match instance::runtime_dir().map(|d| instance::try_lock(&instance::tray_lock_path(&d))) {
        Some(Ok(None)) => {
            eprintln!("venpos-agent-tray: el icono ya está en marcha");
            return 0;
        }
        Some(Ok(Some(l))) => Some(l),
        _ => None,
    };
    let ipc_name = &opts.paths.ipc_name;
    let (tx, rx) = mpsc::channel();
    let mut snapshot = client::status(ipc_name);
    let mut printers = SystemPrinter.list();

    let mut attempt = 0;
    let handle = loop {
        let tray = VenposTray {
            snapshot: snapshot.clone(),
            printers: printers.clone(),
            tx: tx.clone(),
        };
        match tray.spawn() {
            Ok(h) => break h,
            Err(ksni::Error::Dbus(e)) => {
                eprintln!("venpos-agent-tray: no hay sesión de escritorio (D-Bus): {e}");
                return 1;
            }
            Err(e) if attempt + 1 >= HOST_RETRIES => {
                eprintln!("venpos-agent-tray: {e}");
                desktop::notify(MSG_NO_HOST, true);
                return 1;
            }
            Err(_) => {
                attempt += 1;
                std::thread::sleep(POLL);
            }
        }
    };

    if snapshot.channel_error == Some(std::io::ErrorKind::PermissionDenied) {
        desktop::notify(logic::MSG_NO_PERMISSION, true);
    }

    let mut ticks = 0u32;
    loop {
        let clicked = match rx.recv_timeout(POLL) {
            Ok(TrayAction::Quit) => {
                handle.shutdown().wait();
                return 0;
            }
            Ok(a) => {
                perform(opts, &snapshot, a);
                true
            }
            Err(mpsc::RecvTimeoutError::Timeout) => false,
            Err(mpsc::RecvTimeoutError::Disconnected) => return 0,
        };
        ticks += 1;
        let new_snapshot = client::status(ipc_name);
        let new_printers = if clicked || ticks.is_multiple_of(LIST_EVERY) {
            SystemPrinter.list()
        } else {
            printers.clone()
        };
        if new_snapshot != snapshot || new_printers != printers {
            snapshot = new_snapshot;
            printers = new_printers;
            let (s, p) = (snapshot.clone(), printers.clone());
            if handle
                .update(move |t| {
                    t.snapshot = s;
                    t.printers = p;
                })
                .is_none()
            {
                return 0;
            }
        }
    }
}
