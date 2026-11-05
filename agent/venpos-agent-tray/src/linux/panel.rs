//! The "VenPos Agent" window (egui): status, printers, test prints and
//! pairing. A thin layer over `logic` and `client`; every request runs on a
//! helper thread so the window never freezes while the service answers.
use crate::linux::logic::{self, Choice, Notice, PrinterOption, Snapshot};
use crate::linux::{Options, client, desktop, instance};
use crate::model;
use agent_core::config::{Destination, Target};
use agent_core::printer::{PrinterBackend, SystemPrinter};
use agent_core::state::Light;
use eframe::egui;
use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc;
use std::time::Duration;

const POLL: Duration = Duration::from_secs(3);
const LIST_EVERY: u32 = 5;

#[derive(Debug, Clone, Copy)]
enum Slot {
    Printer(Target),
    Pair,
    General,
}

enum Msg {
    Status(Snapshot, Option<Vec<PrinterOption>>),
    Notice(Slot, Notice),
}

#[derive(Default)]
struct PrinterForm {
    choice: Option<Choice>,
    manual: String,
    /// Set once the user touches the selector, so polls stop resetting it.
    edited: bool,
    notice: Option<Notice>,
}

struct Panel {
    ipc_name: String,
    log_default: PathBuf,
    snapshot: Option<Snapshot>,
    options: Vec<PrinterOption>,
    forms: [PrinterForm; 2],
    server: Option<String>,
    code: String,
    pair_notice: Option<Notice>,
    notice: Option<Notice>,
    busy: usize,
    tx: mpsc::Sender<Msg>,
    rx: mpsc::Receiver<Msg>,
    wake: mpsc::Sender<()>,
    raise: Arc<AtomicBool>,
}

fn idx(t: Target) -> usize {
    match t {
        Target::Ticket => 0,
        Target::Label => 1,
    }
}

fn rgb(light: Light) -> egui::Color32 {
    match light {
        Light::Green => egui::Color32::from_rgb(0x2E, 0xA0, 0x43),
        Light::Amber => egui::Color32::from_rgb(0xF0, 0xA0, 0x20),
        Light::Red => egui::Color32::from_rgb(0xD0, 0x30, 0x30),
    }
}

fn show_notice(ui: &mut egui::Ui, n: &Option<Notice>) {
    if let Some(n) = n {
        let colour = if n.warn {
            ui.visuals().warn_fg_color
        } else {
            rgb(Light::Green)
        };
        ui.label(egui::RichText::new(logic::window_text(&n.text)).color(colour));
    }
}

impl Panel {
    /// Runs `job` on a helper thread; its notice lands in `slot`.
    fn run(&mut self, ctx: &egui::Context, slot: Slot, job: impl FnOnce(&str) -> Notice + Send + 'static) {
        self.busy += 1;
        let (tx, wake, ctx, name) = (self.tx.clone(), self.wake.clone(), ctx.clone(), self.ipc_name.clone());
        std::thread::spawn(move || {
            let n = job(&name);
            let _ = tx.send(Msg::Notice(slot, n));
            let _ = wake.send(());
            ctx.request_repaint();
        });
    }

    fn receive(&mut self) {
        while let Ok(m) = self.rx.try_recv() {
            match m {
                Msg::Status(s, options) => {
                    if let Some(o) = options {
                        self.options = o;
                    }
                    for t in [Target::Ticket, Target::Label] {
                        let f = &mut self.forms[idx(t)];
                        if !f.edited {
                            (f.choice, f.manual) = logic::initial_choice(&self.options, s.current(t));
                        }
                    }
                    if self.server.is_none() && s.status.is_some() {
                        self.server = Some(model::pair_server_prefill(s.server()));
                    }
                    self.snapshot = Some(s);
                }
                Msg::Notice(slot, n) => {
                    self.busy = self.busy.saturating_sub(1);
                    match slot {
                        Slot::Printer(t) => self.forms[idx(t)].notice = Some(n),
                        Slot::Pair => {
                            if !n.warn {
                                self.code.clear();
                            }
                            self.pair_notice = Some(n);
                        }
                        Slot::General => self.notice = Some(n),
                    }
                }
            }
        }
    }

    fn header(&self, ui: &mut egui::Ui) {
        let Some(s) = &self.snapshot else {
            ui.label("Consultando el servicio…");
            return;
        };
        ui.horizontal(|ui| {
            let (rect, _) = ui.allocate_exact_size(egui::vec2(18.0, 18.0), egui::Sense::hover());
            ui.painter().circle_filled(rect.center(), 8.0, rgb(s.light()));
            ui.heading(logic::window_text(&s.status_line()));
        });
        if let Some(name) = s.terminal_name() {
            ui.label(format!("Caja: {name}"));
        }
        if let Some(p) = s.problem() {
            ui.label(egui::RichText::new(p).color(ui.visuals().warn_fg_color));
        }
    }

    fn printer(&mut self, ui: &mut egui::Ui, ctx: &egui::Context, target: Target) {
        let options = self.options.clone();
        let f = &mut self.forms[idx(target)];
        ui.strong(logic::target_title(target));
        let selected = match f.choice {
            Some(Choice::Installed(i)) => options.get(i).map_or("", |o| o.label.as_str()),
            Some(Choice::Device) => logic::DEVICE_LABEL,
            Some(Choice::Network) => logic::NETWORK_LABEL,
            None => "(elige una impresora)",
        };
        let before = f.choice;
        egui::ComboBox::from_id_salt(target.as_str())
            .width(320.0)
            .selected_text(selected)
            .show_ui(ui, |ui| {
                for (i, o) in options.iter().enumerate() {
                    ui.selectable_value(&mut f.choice, Some(Choice::Installed(i)), &o.label);
                }
                if options.is_empty() {
                    ui.label("(no hay impresoras instaladas)");
                }
                ui.separator();
                ui.selectable_value(&mut f.choice, Some(Choice::Device), logic::DEVICE_LABEL);
                ui.selectable_value(&mut f.choice, Some(Choice::Network), logic::NETWORK_LABEL);
            });
        if f.choice != before {
            f.edited = true;
            f.notice = None;
        }
        let hint = match f.choice {
            Some(Choice::Device) => Some(logic::DEVICE_HINT),
            Some(Choice::Network) => Some(logic::NETWORK_HINT),
            _ => None,
        };
        if let Some(hint) = hint
            && ui
                .add(
                    egui::TextEdit::singleline(&mut f.manual)
                        .hint_text(hint)
                        .desired_width(320.0),
                )
                .changed()
        {
            f.edited = true;
        }
        let mut save: Option<Result<Destination, String>> = None;
        let mut test = false;
        ui.horizontal(|ui| {
            if ui.button("Guardar").clicked() {
                save = Some(logic::form_destination(f.choice, &f.manual, &options));
            }
            if ui.button("Imprimir prueba").clicked() {
                test = true;
            }
        });
        match save {
            Some(Ok(dest)) => {
                f.edited = false;
                self.run(ctx, Slot::Printer(target), move |n| client::choose(n, target, dest));
            }
            Some(Err(msg)) => f.notice = Some(Notice::warn(msg)),
            None => {}
        }
        if test {
            self.run(ctx, Slot::Printer(target), move |n| client::test_print(n, target));
        }
        show_notice(ui, &self.forms[idx(target)].notice);
    }

    fn pairing(&mut self, ui: &mut egui::Ui, ctx: &egui::Context) {
        ui.strong("Emparejar con el TPV");
        ui.label("El código lo da el TPV en Ajustes > Cajas > Emparejar agente.");
        let server = self.server.get_or_insert_with(|| model::pair_server_prefill(None));
        egui::Grid::new("pair").num_columns(2).show(ui, |ui| {
            ui.label("Dirección del TPV");
            ui.add(egui::TextEdit::singleline(server).desired_width(260.0));
            ui.end_row();
            ui.label("Código de 6 cifras");
            ui.add(
                egui::TextEdit::singleline(&mut self.code)
                    .char_limit(6)
                    .hint_text("123456")
                    .desired_width(120.0),
            );
            ui.end_row();
        });
        if ui.button("Emparejar").clicked() {
            let (server, code) = (server.clone(), self.code.clone());
            self.pair_notice = Some(Notice::ok("Conectando con el TPV…"));
            self.run(ctx, Slot::Pair, move |n| client::pair(n, &server, &code));
        }
        show_notice(ui, &self.pair_notice);
    }

    fn links(&mut self, ui: &mut egui::Ui, ctx: &egui::Context) {
        let server = self.snapshot.as_ref().and_then(|s| s.server()).map(str::to_owned);
        ui.horizontal(|ui| {
            if ui.button("Abrir ajustes del TPV").clicked() {
                self.notice = match logic::settings_target(server.as_deref()) {
                    Ok(url) if desktop::open(&url) => None,
                    Ok(_) => Some(Notice::warn("No se pudo abrir el navegador (falta xdg-open).")),
                    Err(msg) => Some(Notice::warn(msg)),
                };
            }
            if ui.button("Ver registro").clicked() {
                let default = self.log_default.clone();
                self.run(ctx, Slot::General, move |n| {
                    let path = client::log_path(n, &default);
                    if desktop::open(&path) {
                        Notice::ok(format!("Registro: {path}"))
                    } else {
                        Notice::warn(format!("No se pudo abrir {path}"))
                    }
                });
            }
            if ui.button("Reiniciar servicio").clicked() {
                self.run(ctx, Slot::General, |_| match desktop::restart_service() {
                    Ok(()) => Notice::ok("Servicio reiniciado".to_owned()),
                    Err(msg) => Notice::warn(msg),
                });
            }
            // The service keeps printing; this only closes the window.
            if ui.button("Cerrar").clicked() {
                ctx.send_viewport_cmd(egui::ViewportCommand::Close);
            }
        });
        show_notice(ui, &self.notice);
    }
}

impl eframe::App for Panel {
    fn update(&mut self, ctx: &egui::Context, _frame: &mut eframe::Frame) {
        if self.raise.swap(false, Ordering::SeqCst) {
            ctx.send_viewport_cmd(egui::ViewportCommand::Minimized(false));
            ctx.send_viewport_cmd(egui::ViewportCommand::Focus);
        }
        self.receive();
        egui::CentralPanel::default().show(ctx, |ui| {
            egui::ScrollArea::vertical().show(ui, |ui| {
                self.header(ui);
                ui.separator();
                self.printer(ui, ctx, Target::Ticket);
                ui.separator();
                self.printer(ui, ctx, Target::Label);
                ui.separator();
                self.pairing(ui, ctx);
                ui.separator();
                self.links(ui, ctx);
                if self.busy > 0 {
                    ui.spinner();
                }
            });
        });
    }
}

/// Polls `status` (and the printer list) and wakes the window.
fn poller(ipc_name: String, tx: mpsc::Sender<Msg>, wake: mpsc::Receiver<()>, ctx: egui::Context) {
    std::thread::spawn(move || {
        let mut n = 0u32;
        loop {
            let options = n
                .is_multiple_of(LIST_EVERY)
                .then(|| logic::printer_options(&SystemPrinter.list()));
            if tx.send(Msg::Status(client::status(&ipc_name), options)).is_err() {
                return;
            }
            ctx.request_repaint();
            n += 1;
            match wake.recv_timeout(POLL) {
                // A request just finished: list printers again too.
                Ok(()) => n = 0,
                Err(mpsc::RecvTimeoutError::Timeout) => {}
                Err(mpsc::RecvTimeoutError::Disconnected) => return,
            }
        }
    });
}

pub const MSG_NO_DISPLAY: &str = "no hay pantalla: abre VenPos Agent desde el escritorio \
     (o usa el asistente en terminal: sudo venpos-agent-setup)";

pub fn has_display() -> bool {
    ["WAYLAND_DISPLAY", "DISPLAY"]
        .iter()
        .any(|v| std::env::var_os(v).is_some_and(|x| !x.is_empty()))
}

pub fn run(opts: &Options) -> i32 {
    if !has_display() {
        eprintln!("venpos-agent-tray: {MSG_NO_DISPLAY}");
        return 1;
    }
    let raise = Arc::new(AtomicBool::new(false));
    let socket = instance::runtime_dir().map(|d| instance::window_socket_path(&d));
    if let Some(s) = &socket
        && instance::raise_existing_window(s)
    {
        return 0;
    }
    let icon = egui::IconData {
        rgba: model::icon_rgba(Light::Green, 64),
        width: 64,
        height: 64,
    };
    let native = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_title("VenPos Agent")
            .with_app_id("venpos-agent")
            .with_icon(icon)
            .with_inner_size([520.0, 640.0])
            .with_min_inner_size([420.0, 400.0]),
        renderer: eframe::Renderer::Glow,
        ..Default::default()
    };
    let ipc_name = opts.paths.ipc_name.clone();
    let log_default = opts.paths.log_file();
    let raise_flag = Arc::clone(&raise);
    let result = eframe::run_native(
        "VenPos Agent",
        native,
        Box::new(move |cc| {
            let (tx, rx) = mpsc::channel();
            let (wake, wake_rx) = mpsc::channel();
            poller(ipc_name.clone(), tx.clone(), wake_rx, cc.egui_ctx.clone());
            if let Some(s) = &socket {
                let ctx = cc.egui_ctx.clone();
                let flag = Arc::clone(&raise_flag);
                let _ = instance::listen_for_raise(s, move || {
                    flag.store(true, Ordering::SeqCst);
                    ctx.request_repaint();
                });
            }
            Ok(Box::new(Panel {
                ipc_name,
                log_default,
                snapshot: None,
                options: Vec::new(),
                forms: Default::default(),
                server: None,
                code: String::new(),
                pair_notice: None,
                notice: None,
                busy: 0,
                tx,
                rx,
                wake,
                raise: raise_flag,
            }))
        }),
    );
    match result {
        Ok(()) => 0,
        Err(e) => {
            eprintln!("venpos-agent-tray: no se pudo abrir la ventana: {e}");
            if !desktop::open_assistant() {
                eprintln!("venpos-agent-tray: usa el asistente en terminal: sudo venpos-agent-setup");
            }
            1
        }
    }
}
