//! Windows tray loop: Win32 message pump on the main thread, service status
//! polled over the named pipe from a helper thread.
use crate::model;
use agent_core::config::{Config, Destination, Target};
use agent_core::ipc::{self, Request};
use agent_core::paths::Paths;
use agent_core::printer::{PrinterBackend, SystemPrinter};
use agent_core::state::{AgentStatus, Light};
use std::collections::HashMap;
use std::sync::mpsc;
use std::time::Duration;
use tray_icon::menu::{CheckMenuItem, Menu, MenuEvent, MenuId, MenuItem, PredefinedMenuItem, Submenu};
use tray_icon::{Icon, TrayIcon, TrayIconBuilder};
use windows::Win32::UI::Shell::ShellExecuteW;
use windows::Win32::UI::WindowsAndMessaging::{
    DispatchMessageW, MB_ICONINFORMATION, MB_ICONWARNING, MB_OK, MESSAGEBOX_STYLE, MSG, MessageBoxW, PM_REMOVE,
    PeekMessageW, SW_SHOWNORMAL, TranslateMessage,
};
use windows::core::HSTRING;

enum Action {
    TestPrint,
    Pair,
    Choose(Target, Destination),
    OpenLog,
    OpenSettings,
    About,
    Quit,
}

struct Ui {
    menu: Menu,
    status_item: MenuItem,
    actions: HashMap<MenuId, Action>,
}

fn config_dir_arg() -> Option<std::path::PathBuf> {
    let mut args = std::env::args_os().skip(1);
    while let Some(a) = args.next() {
        if a == "--config-dir" {
            return args.next().map(Into::into);
        }
    }
    None
}

fn message(text: &str, warn: bool) {
    let style: MESSAGEBOX_STYLE = MB_OK | if warn { MB_ICONWARNING } else { MB_ICONINFORMATION };
    // SAFETY: HSTRINGs are NUL-terminated and live for the call; no owner window.
    unsafe { MessageBoxW(None, &HSTRING::from(text), &HSTRING::from("VenPos Agent"), style) };
}

fn shell_open(target: &str) {
    // SAFETY: HSTRINGs live for the call; `target` is a validated https URL or the log path.
    unsafe {
        ShellExecuteW(
            None,
            &HSTRING::from("open"),
            &HSTRING::from(target),
            None,
            None,
            SW_SHOWNORMAL,
        )
    };
}

fn icon(light: Light) -> Option<Icon> {
    Icon::from_rgba(
        model::icon_rgba(light, model::ICON_SIZE),
        model::ICON_SIZE,
        model::ICON_SIZE,
    )
    .ok()
}

fn build_menu(paths: &Paths, status: Option<&AgentStatus>) -> Ui {
    let menu = Menu::new();
    let mut actions = HashMap::new();
    let status_item = MenuItem::new(model::status_line(status), false, None);
    let _ = menu.append(&status_item);
    let _ = menu.append(&PredefinedMenuItem::separator());

    let test = MenuItem::new("Imprimir prueba", true, None);
    actions.insert(test.id().clone(), Action::TestPrint);
    let _ = menu.append(&test);

    let current = Config::load(&paths.config_file).unwrap_or_default().destinations;
    let installed = SystemPrinter.list();
    let choose = Submenu::new("Elegir impresoras", true);
    for (target, label) in [(Target::Ticket, "Tickets"), (Target::Label, "Etiquetas")] {
        let sub = Submenu::new(label, true);
        if installed.is_empty() {
            let _ = sub.append(&MenuItem::new("(no hay impresoras instaladas)", false, None));
        }
        for p in &installed {
            let checked = current.get(target) == Some(&p.destination);
            let item = CheckMenuItem::new(p.destination.display_name(), true, checked, None);
            actions.insert(item.id().clone(), Action::Choose(target, p.destination.clone()));
            let _ = sub.append(&item);
        }
        let _ = choose.append(&sub);
    }
    let _ = menu.append(&choose);

    for (text, action) in [
        ("Conectar con el TPV…", Action::Pair),
        ("Ver registro", Action::OpenLog),
        ("Abrir Ajustes del TPV", Action::OpenSettings),
        ("Acerca de", Action::About),
    ] {
        let item = MenuItem::new(text, true, None);
        actions.insert(item.id().clone(), action);
        let _ = menu.append(&item);
    }
    let _ = menu.append(&PredefinedMenuItem::separator());
    let quit = MenuItem::new("Cerrar icono (el servicio sigue)", true, None);
    actions.insert(quit.id().clone(), Action::Quit);
    let _ = menu.append(&quit);
    Ui {
        menu,
        status_item,
        actions,
    }
}

fn handle(paths: &Paths, action: &Action, refresh: &mpsc::Sender<()>) -> bool {
    match action {
        Action::Pair => {
            let cfg = Config::load(&paths.config_file).unwrap_or_default();
            let prefill = model::pair_server_prefill(cfg.server_url.as_deref());
            if let Some(msg) = crate::pair_dialog::run(&paths.ipc_name, &prefill) {
                let _ = refresh.send(());
                message(&msg, false);
            }
        }
        Action::TestPrint => match ipc::request(&paths.ipc_name, &Request::TestPrint { target: Target::Ticket }) {
            Ok(r) if r.ok => message("Prueba enviada a la impresora de tickets.", false),
            Ok(r) => message(&r.error.unwrap_or_else(|| "No se pudo imprimir".into()), true),
            Err(_) => message(model::MSG_SERVICE_DOWN, true),
        },
        Action::Choose(target, dest) => {
            let req = match target {
                Target::Ticket => Request::ChoosePrinters {
                    ticket: Some(dest.clone()),
                    label: None,
                },
                Target::Label => Request::ChoosePrinters {
                    ticket: None,
                    label: Some(dest.clone()),
                },
            };
            match ipc::request(&paths.ipc_name, &req) {
                Ok(r) if r.ok => {}
                Ok(r) => message(&r.error.unwrap_or_default(), true),
                Err(_) => message(model::MSG_SERVICE_DOWN, true),
            }
        }
        Action::OpenLog => match ipc::request(&paths.ipc_name, &Request::OpenLog) {
            Ok(r) => shell_open(
                &r.log_path
                    .unwrap_or_else(|| paths.log_file().to_string_lossy().into_owned()),
            ),
            Err(_) => shell_open(&paths.log_file().to_string_lossy()),
        },
        Action::OpenSettings => {
            let cfg = Config::load(&paths.config_file).unwrap_or_default();
            match model::settings_url(cfg.server_url.as_deref()) {
                Some(url) => shell_open(&url),
                None => message("El agente todavía no está emparejado con el TPV.", true),
            }
        }
        Action::About => message(
            &format!(
                "VenPos Agent {}\nImprime tickets y etiquetas y abre el cajón de esta caja.",
                agent_core::VERSION
            ),
            false,
        ),
        Action::Quit => return false,
    }
    true
}

pub fn run() {
    let paths = Paths::resolve(config_dir_arg().as_deref());
    let (tx, rx) = mpsc::channel::<Option<AgentStatus>>();
    // Wakes the poller early (after pairing) instead of waiting 5 s.
    let (refresh, wake) = mpsc::channel::<()>();
    let pipe = paths.ipc_name.clone();
    std::thread::spawn(move || {
        loop {
            let s = ipc::request(&pipe, &Request::Status).ok().and_then(|r| r.status);
            if tx.send(s).is_err() {
                return;
            }
            if let Err(mpsc::RecvTimeoutError::Disconnected) = wake.recv_timeout(Duration::from_secs(5)) {
                return;
            }
        }
    });

    let mut status: Option<AgentStatus> = None;
    let mut ui = build_menu(&paths, None);
    let built: Result<TrayIcon, _> = {
        let mut b = TrayIconBuilder::new()
            .with_tooltip(model::status_line(None))
            .with_menu(Box::new(ui.menu.clone()));
        if let Some(i) = icon(Light::Amber) {
            b = b.with_icon(i);
        }
        b.build()
    };
    let Ok(tray) = built else {
        message("No se pudo crear el icono de la bandeja.", true);
        return;
    };

    loop {
        let mut msg = MSG::default();
        // SAFETY: standard message pump on the thread that owns the tray window.
        while unsafe { PeekMessageW(&mut msg, None, 0, 0, PM_REMOVE) }.as_bool() {
            // SAFETY: `msg` was filled by PeekMessageW.
            unsafe {
                let _ = TranslateMessage(&msg);
                DispatchMessageW(&msg);
            }
        }
        while let Ok(ev) = MenuEvent::receiver().try_recv() {
            if let Some(action) = ui.actions.get(&ev.id) {
                if !handle(&paths, action, &refresh) {
                    return;
                }
                if matches!(action, Action::Choose(..)) {
                    ui = build_menu(&paths, status.as_ref());
                    tray.set_menu(Some(Box::new(ui.menu.clone())));
                }
            }
        }
        while let Ok(s) = rx.try_recv() {
            let changed = s != status;
            status = s;
            if changed {
                let line = model::status_line(status.as_ref());
                ui.status_item.set_text(&line);
                let _ = tray.set_tooltip(Some(&line));
                let _ = tray.set_icon(icon(model::light_for(status.as_ref())));
            }
        }
        std::thread::sleep(Duration::from_millis(50));
    }
}
