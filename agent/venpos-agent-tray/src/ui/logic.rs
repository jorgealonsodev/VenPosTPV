//! What the tray and the window show and send, without any GUI: texts,
//! error mapping, printer choices and the requests built from them. The
//! ksni and egui layers only render this and forward clicks.
use crate::model::{self, PairOutcome};
use agent_core::config::{Destination, Target};
use agent_core::ipc::{Request, Response};
use agent_core::printer::InstalledPrinter;
use agent_core::state::{AgentStatus, Light};
use std::io;
use std::path::PathBuf;

#[cfg(not(windows))]
pub const MSG_SERVICE_STOPPED: &str =
    "El servicio VenPos Agent no está en marcha. Arráncalo con: sudo systemctl start venpos-agent";
#[cfg(windows)]
pub const MSG_SERVICE_STOPPED: &str =
    "El servicio VenPos Agent no está en marcha. Pulsa «Iniciar / reiniciar servicio».";
#[cfg(not(windows))]
pub const MSG_NO_PERMISSION: &str = "No tienes permiso para hablar con el servicio VenPos Agent. \
     Añade tu usuario al grupo venpos-agent con: sudo usermod -aG venpos-agent $USER \
     y vuelve a iniciar sesión.";
#[cfg(windows)]
pub const MSG_NO_PERMISSION: &str = "No tienes permiso para hablar con el servicio VenPos Agent. \
     Vuelve a instalar VenPos Agent con su instalador.";
pub const MSG_NOT_PAIRED: &str = "El agente todavía no está emparejado con el TPV.";

/// Text for a failure to reach the service over the local socket.
pub fn channel_error_text(kind: io::ErrorKind) -> &'static str {
    match kind {
        io::ErrorKind::PermissionDenied => MSG_NO_PERMISSION,
        // NotFound: no socket (service stopped); ConnectionRefused: stale socket.
        _ => MSG_SERVICE_STOPPED,
    }
}

/// The window's built-in fonts have no arrow glyph ("Ajustes → Cajas").
pub fn window_text(text: &str) -> String {
    text.replace('→', ">")
}

/// Result of the last `status` poll.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Snapshot {
    pub status: Option<AgentStatus>,
    pub channel_error: Option<io::ErrorKind>,
}

impl Snapshot {
    pub fn from_result(r: io::Result<Response>) -> Self {
        match r {
            Ok(r) => Self {
                status: r.status,
                channel_error: None,
            },
            Err(e) => Self {
                status: None,
                channel_error: Some(e.kind()),
            },
        }
    }

    pub fn status_line(&self) -> String {
        if self.channel_error == Some(io::ErrorKind::PermissionDenied) {
            return "VenPos: sin permiso para hablar con el servicio".into();
        }
        model::status_line(self.status.as_ref())
    }

    pub fn light(&self) -> Light {
        model::light_for(self.status.as_ref())
    }

    /// Why the service cannot be reached, if it cannot.
    pub fn problem(&self) -> Option<&'static str> {
        match (&self.status, self.channel_error) {
            (_, Some(k)) => Some(channel_error_text(k)),
            (None, None) => Some(MSG_SERVICE_STOPPED),
            (Some(_), None) => None,
        }
    }

    /// Name of the printer chosen for `target`, as the service reports it.
    pub fn current(&self, target: Target) -> Option<&str> {
        self.status
            .as_ref()?
            .printers
            .get(target.as_str())
            .map(|p| p.name.as_str())
    }

    pub fn server(&self) -> Option<&str> {
        self.status.as_ref()?.server_url.as_deref()
    }

    pub fn terminal_name(&self) -> Option<&str> {
        self.status.as_ref()?.terminal_name.as_deref()
    }
}

pub fn target_title(target: Target) -> &'static str {
    match target {
        Target::Ticket => "Impresora de tickets",
        Target::Label => "Impresora de etiquetas",
    }
}

fn target_noun(target: Target) -> &'static str {
    match target {
        Target::Ticket => "tickets",
        Target::Label => "etiquetas",
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PrinterOption {
    pub label: String,
    pub destination: Destination,
}

/// Installed printers as choices, labelled like the CLI lists them.
pub fn printer_options(installed: &[InstalledPrinter]) -> Vec<PrinterOption> {
    installed
        .iter()
        .map(|p| {
            let name = p.destination.display_name();
            let label = match &p.destination {
                Destination::Device { .. } => format!("Dispositivo {name}"),
                Destination::Network { .. } => format!("Red {name}"),
                _ => name.clone(),
            };
            let label = if p.detail.is_empty() || p.detail == name {
                label
            } else {
                format!("{label} ({})", p.detail)
            };
            PrinterOption {
                label,
                destination: p.destination.clone(),
            }
        })
        .collect()
}

/// Which option is the current printer (matched by the name the service reports).
pub fn selected_index(options: &[PrinterOption], current: Option<&str>) -> Option<usize> {
    let current = current?;
    options.iter().position(|o| o.destination.display_name() == current)
}

pub fn choose_request(target: Target, dest: Destination) -> Request {
    match target {
        Target::Ticket => Request::ChoosePrinters {
            ticket: Some(dest),
            label: None,
        },
        Target::Label => Request::ChoosePrinters {
            ticket: None,
            label: Some(dest),
        },
    }
}

/// What the window's printer selector holds.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Choice {
    Installed(usize),
    Device,
    Network,
}

pub const DEVICE_LABEL: &str = "Dispositivo USB…";
pub const NETWORK_LABEL: &str = "Impresora de red…";
pub const DEVICE_HINT: &str = "/dev/usb/lp0";
pub const NETWORK_HINT: &str = "192.168.1.50 (puerto 9100 si no indicas otro)";

/// What the selector starts with for the printer the service reports.
pub fn initial_choice(options: &[PrinterOption], current: Option<&str>) -> (Option<Choice>, String) {
    match (selected_index(options, current), current) {
        (Some(i), _) => (Some(Choice::Installed(i)), String::new()),
        (None, Some(c)) if c.starts_with("/dev/") => (Some(Choice::Device), c.to_owned()),
        (None, Some(c)) if !c.is_empty() => (Some(Choice::Network), c.to_owned()),
        _ => (None, String::new()),
    }
}

/// Destination of a selector, checked before asking the service (which checks again).
pub fn form_destination(
    choice: Option<Choice>,
    manual: &str,
    options: &[PrinterOption],
) -> Result<Destination, String> {
    let manual = manual.trim();
    let dest = match choice {
        None => return Err("Elige una impresora.".into()),
        Some(Choice::Installed(i)) => options
            .get(i)
            .map(|o| o.destination.clone())
            .ok_or_else(|| "Elige una impresora.".to_owned())?,
        Some(Choice::Device) if manual.is_empty() => {
            return Err(format!("Escribe la ruta del dispositivo, por ejemplo {DEVICE_HINT}."));
        }
        Some(Choice::Device) => Destination::Device {
            path: PathBuf::from(manual),
        },
        Some(Choice::Network) if manual.is_empty() => {
            return Err("Escribe la IP o el nombre de la impresora de red.".into());
        }
        Some(Choice::Network) => Destination::Network {
            address: manual.to_owned(),
        },
    };
    dest.validate().map_err(|e| e.to_string())?;
    Ok(dest)
}

/// Result of a request, as one line for the user; `warn` marks a failure.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Notice {
    pub text: String,
    pub warn: bool,
}

impl Notice {
    pub fn ok(text: impl Into<String>) -> Self {
        Self {
            text: text.into(),
            warn: false,
        }
    }

    pub fn warn(text: impl Into<String>) -> Self {
        Self {
            text: text.into(),
            warn: true,
        }
    }
}

fn answer(result: io::Result<Response>, ok: impl FnOnce(Response) -> String, fallback: &str) -> Notice {
    match result {
        Ok(r) if r.ok => Notice::ok(ok(r)),
        Ok(r) => Notice::warn(r.error.unwrap_or_else(|| fallback.to_owned())),
        Err(e) => Notice::warn(channel_error_text(e.kind())),
    }
}

pub fn choose_notice(target: Target, result: io::Result<Response>) -> Notice {
    answer(
        result,
        |_| format!("Impresora de {} guardada.", target_noun(target)),
        "No se pudo guardar la impresora.",
    )
}

pub fn test_print_notice(target: Target, result: io::Result<Response>) -> Notice {
    answer(
        result,
        |_| format!("Prueba enviada a la impresora de {}.", target_noun(target)),
        "No se pudo imprimir.",
    )
}

pub fn pair_notice(result: io::Result<Response>) -> Notice {
    match result {
        Err(e) => Notice::warn(channel_error_text(e.kind())),
        Ok(r) => match model::pair_outcome(Ok(r)) {
            PairOutcome::Connected(m) => Notice::ok(m),
            PairOutcome::Failed(m) => Notice::warn(m),
        },
    }
}

/// Log path reported by the service, else the default one.
/// Why the log at `path` cannot be opened, from trying to read it; None when it can.
/// The service writes it as the venpos-agent user, readable by that group only.
pub fn log_open_problem(path: &str, readable: io::Result<()>) -> Option<String> {
    match readable {
        Ok(()) => None,
        Err(e) if e.kind() == io::ErrorKind::NotFound => Some(format!("Todavía no hay registro en {path}")),
        Err(e) if e.kind() == io::ErrorKind::PermissionDenied => Some(format!(
            "No tienes permiso para leer {path}.{}",
            if cfg!(windows) {
                ""
            } else {
                " Añade tu usuario al grupo venpos-agent con: \
                 sudo usermod -aG venpos-agent $USER (y vuelve a iniciar sesión)."
            }
        )),
        Err(e) => Some(format!("No se pudo leer {path}: {e}")),
    }
}

pub fn log_target(result: io::Result<Response>, default: &std::path::Path) -> String {
    result
        .ok()
        .and_then(|r| r.log_path)
        .unwrap_or_else(|| default.to_string_lossy().into_owned())
}

pub fn settings_target(server: Option<&str>) -> Result<String, &'static str> {
    model::settings_url(server).ok_or(MSG_NOT_PAIRED)
}

/// Clicks of the tray menu.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum TrayAction {
    OpenWindow,
    Choose(Target, Destination),
    TestPrint(Target),
    OpenSettings,
    OpenLog,
    Quit,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum TrayEntry {
    Action(String, TrayAction),
    /// Disabled text (status line, "no printers").
    Text(String),
    Separator,
    Printers {
        label: String,
        target: Target,
        options: Vec<PrinterOption>,
        selected: Option<usize>,
        /// The current printer when it is not among the installed ones (e.g. a USB device).
        other_current: Option<String>,
    },
}

fn act(label: &str, a: TrayAction) -> TrayEntry {
    TrayEntry::Action(label.into(), a)
}

/// The tray menu for a status and the installed printers.
pub fn tray_menu(snapshot: &Snapshot, installed: &[InstalledPrinter]) -> Vec<TrayEntry> {
    let options = printer_options(installed);
    let mut menu = vec![
        act("Abrir VenPos Agent", TrayAction::OpenWindow),
        TrayEntry::Separator,
        TrayEntry::Text(snapshot.status_line()),
        TrayEntry::Separator,
    ];
    for target in [Target::Ticket, Target::Label] {
        let current = snapshot.current(target);
        let selected = selected_index(&options, current);
        menu.push(TrayEntry::Printers {
            label: target_title(target).into(),
            target,
            options: options.clone(),
            selected,
            other_current: if selected.is_none() {
                current.map(str::to_owned)
            } else {
                None
            },
        });
    }
    menu.extend([
        act("Imprimir prueba de ticket", TrayAction::TestPrint(Target::Ticket)),
        act("Imprimir prueba de etiqueta", TrayAction::TestPrint(Target::Label)),
        TrayEntry::Separator,
        act("Emparejar con el TPV…", TrayAction::OpenWindow),
        act("Abrir ajustes del TPV", TrayAction::OpenSettings),
        act("Ver registro", TrayAction::OpenLog),
        TrayEntry::Separator,
        act("Cerrar el icono (el servicio sigue)", TrayAction::Quit),
    ]);
    menu
}

#[cfg(all(test, target_os = "linux"))]
mod tests {
    use super::*;
    use agent_core::printer::{PrinterState, QueueStatus};
    use agent_core::state::{Connection, PrinterReport};

    fn cups(q: &str) -> Destination {
        Destination::Cups { queue: q.into() }
    }

    fn installed() -> Vec<InstalledPrinter> {
        vec![
            InstalledPrinter {
                destination: cups("TICKETS"),
                detail: "EPSON TM-T20".into(),
            },
            InstalledPrinter {
                destination: cups("ZEBRA"),
                detail: String::new(),
            },
        ]
    }

    fn connected(ticket: Option<&str>) -> Snapshot {
        let mut s = AgentStatus {
            connection: Connection::Connected,
            terminal_name: Some("Caja 1".into()),
            server_url: Some("https://tpv.example.com".into()),
            ..Default::default()
        };
        if let Some(name) = ticket {
            s.printers.insert(
                "ticket".into(),
                PrinterReport {
                    target: Target::Ticket,
                    name: name.into(),
                    status: QueueStatus::new(PrinterState::Ready, ""),
                },
            );
        }
        Snapshot {
            status: Some(s),
            channel_error: None,
        }
    }

    #[test]
    fn channel_errors_tell_what_to_do() {
        assert_eq!(
            channel_error_text(io::ErrorKind::PermissionDenied),
            "No tienes permiso para hablar con el servicio VenPos Agent. Añade tu usuario al grupo venpos-agent con: \
             sudo usermod -aG venpos-agent $USER y vuelve a iniciar sesión."
        );
        for k in [io::ErrorKind::NotFound, io::ErrorKind::ConnectionRefused] {
            assert_eq!(
                channel_error_text(k),
                "El servicio VenPos Agent no está en marcha. Arráncalo con: sudo systemctl start venpos-agent"
            );
        }
        let denied = Snapshot::from_result(Err(io::Error::from(io::ErrorKind::PermissionDenied)));
        assert_eq!(denied.status_line(), "VenPos: sin permiso para hablar con el servicio");
        assert_eq!(denied.problem(), Some(MSG_NO_PERMISSION));
        assert_eq!(denied.light(), Light::Amber);
        let stopped = Snapshot::from_result(Err(io::Error::from(io::ErrorKind::NotFound)));
        assert_eq!(stopped.status_line(), "VenPos: el servicio no está en marcha");
        assert_eq!(stopped.problem(), Some(MSG_SERVICE_STOPPED));
        assert_eq!(connected(None).problem(), None);
    }

    #[test]
    fn window_texts_avoid_the_arrow_glyph() {
        assert_eq!(
            window_text("Vuelve a conectar el agente desde Ajustes → Cajas"),
            "Vuelve a conectar el agente desde Ajustes > Cajas"
        );
    }

    #[test]
    fn tray_menu_checks_the_current_printer() {
        let menu = tray_menu(&connected(Some("TICKETS")), &installed());
        assert_eq!(menu[0], act("Abrir VenPos Agent", TrayAction::OpenWindow));
        assert_eq!(menu[2], TrayEntry::Text("Conectado: Caja 1".into()));
        let TrayEntry::Printers {
            label,
            options,
            selected,
            other_current,
            ..
        } = &menu[4]
        else {
            panic!("{:?}", menu[4]);
        };
        assert_eq!(label, "Impresora de tickets");
        assert_eq!(options[0].label, "TICKETS (EPSON TM-T20)");
        assert_eq!(options[1].label, "ZEBRA");
        assert_eq!(*selected, Some(0));
        assert_eq!(*other_current, None);
        let TrayEntry::Printers {
            label,
            selected,
            target,
            ..
        } = &menu[5]
        else {
            panic!("{:?}", menu[5]);
        };
        assert_eq!(
            (label.as_str(), *target, *selected),
            ("Impresora de etiquetas", Target::Label, None)
        );
        let labels: Vec<&str> = menu
            .iter()
            .filter_map(|e| match e {
                TrayEntry::Action(l, _) => Some(l.as_str()),
                _ => None,
            })
            .collect();
        assert_eq!(
            labels,
            [
                "Abrir VenPos Agent",
                "Imprimir prueba de ticket",
                "Imprimir prueba de etiqueta",
                "Emparejar con el TPV…",
                "Abrir ajustes del TPV",
                "Ver registro",
                "Cerrar el icono (el servicio sigue)"
            ]
        );
    }

    #[test]
    fn a_current_printer_not_installed_is_still_shown() {
        let menu = tray_menu(&connected(Some("/dev/usb/lp0")), &installed());
        let TrayEntry::Printers {
            selected,
            other_current,
            ..
        } = &menu[4]
        else {
            panic!();
        };
        assert_eq!(*selected, None);
        assert_eq!(other_current.as_deref(), Some("/dev/usb/lp0"));
    }

    #[test]
    fn printer_form_builds_valid_destinations() {
        let opts = printer_options(&installed());
        assert_eq!(
            form_destination(Some(Choice::Installed(1)), "", &opts),
            Ok(cups("ZEBRA"))
        );
        assert_eq!(
            form_destination(Some(Choice::Device), " /dev/usb/lp0 ", &opts),
            Ok(Destination::Device {
                path: "/dev/usb/lp0".into()
            })
        );
        assert_eq!(
            form_destination(Some(Choice::Network), "192.168.1.50", &opts),
            Ok(Destination::Network {
                address: "192.168.1.50".into()
            })
        );
        assert_eq!(form_destination(None, "", &opts), Err("Elige una impresora.".into()));
        assert_eq!(
            form_destination(Some(Choice::Device), "  ", &opts),
            Err("Escribe la ruta del dispositivo, por ejemplo /dev/usb/lp0.".into())
        );
        assert_eq!(
            form_destination(Some(Choice::Network), "", &opts),
            Err("Escribe la IP o el nombre de la impresora de red.".into())
        );
        assert!(form_destination(Some(Choice::Device), "/etc/passwd", &opts).is_err());
    }

    #[test]
    fn selector_starts_on_the_current_printer() {
        let opts = printer_options(&installed());
        assert_eq!(
            initial_choice(&opts, Some("ZEBRA")),
            (Some(Choice::Installed(1)), String::new())
        );
        assert_eq!(
            initial_choice(&opts, Some("/dev/usb/lp0")),
            (Some(Choice::Device), "/dev/usb/lp0".into())
        );
        assert_eq!(
            initial_choice(&opts, Some("192.168.1.50:9100")),
            (Some(Choice::Network), "192.168.1.50:9100".into())
        );
        assert_eq!(initial_choice(&opts, None), (None, String::new()));
    }

    #[test]
    fn choosing_a_printer_changes_only_that_target() {
        assert_eq!(
            choose_request(Target::Ticket, cups("T")),
            Request::ChoosePrinters {
                ticket: Some(cups("T")),
                label: None
            }
        );
        assert_eq!(
            choose_request(Target::Label, cups("L")),
            Request::ChoosePrinters {
                ticket: None,
                label: Some(cups("L"))
            }
        );
    }

    #[test]
    fn notices() {
        let ok = || {
            Ok(Response {
                ok: true,
                ..Default::default()
            })
        };
        assert_eq!(
            test_print_notice(Target::Label, ok()),
            Notice::ok("Prueba enviada a la impresora de etiquetas.")
        );
        assert_eq!(
            test_print_notice(
                Target::Label,
                Ok(Response {
                    ok: false,
                    error: Some("no hay impresora de label elegida".into()),
                    ..Default::default()
                })
            ),
            Notice::warn("no hay impresora de label elegida")
        );
        assert_eq!(
            choose_notice(Target::Ticket, ok()),
            Notice::ok("Impresora de tickets guardada.")
        );
        assert_eq!(
            choose_notice(Target::Ticket, Err(io::Error::from(io::ErrorKind::PermissionDenied))),
            Notice::warn(MSG_NO_PERMISSION)
        );
        assert_eq!(
            pair_notice(Err(io::Error::from(io::ErrorKind::NotFound))),
            Notice::warn(MSG_SERVICE_STOPPED)
        );
        assert_eq!(
            pair_notice(Ok(Response {
                ok: true,
                terminal_name: Some("Caja 3".into()),
                ..Default::default()
            })),
            Notice::ok("Conectado a la caja Caja 3")
        );
        assert_eq!(settings_target(None), Err(MSG_NOT_PAIRED));
        assert_eq!(
            settings_target(Some("https://tpv.example.com/")).as_deref(),
            Ok("https://tpv.example.com")
        );
        let default = std::path::Path::new("/var/log/venpos-agent/agent.log");
        assert_eq!(
            log_target(Err(io::Error::from(io::ErrorKind::NotFound)), default),
            "/var/log/venpos-agent/agent.log"
        );
        assert_eq!(
            log_target(
                Ok(Response {
                    ok: true,
                    log_path: Some("/tmp/x.log".into()),
                    ..Default::default()
                }),
                default
            ),
            "/tmp/x.log"
        );
    }

    #[test]
    fn an_unreadable_log_says_how_to_get_access() {
        let denied = io::Error::from(io::ErrorKind::PermissionDenied);
        let text = log_open_problem("/var/log/venpos-agent/agent.log", Err(denied)).unwrap();
        assert!(text.contains("sudo usermod -aG venpos-agent $USER"), "{text}");
        assert!(text.contains("vuelve a iniciar sesión"), "{text}");
        assert_eq!(
            log_open_problem("/x.log", Err(io::Error::from(io::ErrorKind::NotFound))).as_deref(),
            Some("Todavía no hay registro en /x.log")
        );
        assert_eq!(log_open_problem("/x.log", Ok(())), None);
    }
}

/// Windows texts must not send the user to Linux commands (run by the Windows CI).
#[cfg(all(test, windows))]
mod windows_tests {
    use super::*;

    #[test]
    fn windows_texts_point_to_the_window_not_to_linux_commands() {
        for text in [MSG_SERVICE_STOPPED, MSG_NO_PERMISSION] {
            assert!(!text.contains("sudo") && !text.contains("systemctl"), "{text}");
        }
        assert!(MSG_SERVICE_STOPPED.contains("Iniciar / reiniciar servicio"));
        let denied = log_open_problem("C:\\log.txt", Err(io::ErrorKind::PermissionDenied.into())).unwrap();
        assert_eq!(denied, "No tienes permiso para leer C:\\log.txt.");
    }
}
