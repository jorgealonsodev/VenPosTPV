//! Requests the tray and the window send over the local socket (A3). The
//! tray never reads the key nor the configuration: everything it shows
//! comes from `status`, everything it changes goes through the service.
use crate::linux::logic::{self, Notice, Snapshot};
use crate::model;
use agent_core::config::{Destination, Target};
use agent_core::ipc::{self, Request};
use std::path::Path;

pub fn status(ipc_name: &str) -> Snapshot {
    Snapshot::from_result(ipc::request(ipc_name, &Request::Status))
}

pub fn choose(ipc_name: &str, target: Target, dest: Destination) -> Notice {
    logic::choose_notice(target, ipc::request(ipc_name, &logic::choose_request(target, dest)))
}

pub fn test_print(ipc_name: &str, target: Target) -> Notice {
    logic::test_print_notice(target, ipc::request(ipc_name, &Request::TestPrint { target }))
}

/// Checks the fields first; nothing is sent when they are wrong.
pub fn pair(ipc_name: &str, server: &str, code: &str) -> Notice {
    match model::pair_request(server, code) {
        Ok(req) => logic::pair_notice(ipc::request(ipc_name, &req)),
        Err(msg) => Notice::warn(msg),
    }
}

pub fn log_path(ipc_name: &str, default: &Path) -> String {
    logic::log_target(ipc::request(ipc_name, &Request::OpenLog), default)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::linux::logic::{MSG_NO_PERMISSION, MSG_SERVICE_STOPPED};
    use agent_core::config::{Config, Destinations};
    use agent_core::ipc::Handler;
    use agent_core::printer::{InstalledPrinter, PrintError, PrinterBackend, PrinterState, QueueStatus, SpoolRef};
    use agent_core::state::{AgentStatus, Connection};
    use std::io::{BufRead, BufReader, Write};
    use std::os::unix::net::UnixListener;
    use std::path::PathBuf;
    use std::sync::atomic::AtomicBool;
    use std::sync::{Arc, Mutex, RwLock};

    struct NullPrinter;

    impl PrinterBackend for NullPrinter {
        fn write_raw(&self, _: &Destination, _: &str, _: &[u8]) -> Result<Option<SpoolRef>, PrintError> {
            Ok(None)
        }
        fn queue_status(&self, _: &Destination) -> QueueStatus {
            QueueStatus::new(PrinterState::Ready, "")
        }
        fn cancel(&self, _: &Destination, _: &SpoolRef) {}
        fn list(&self) -> Vec<InstalledPrinter> {
            Vec::new()
        }
    }

    fn scratch(name: &str) -> PathBuf {
        let d = std::env::temp_dir().join(format!("venpos-tray-ipc-{name}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&d);
        std::fs::create_dir_all(&d).unwrap();
        d
    }

    fn sock(dir: &Path) -> String {
        dir.join("agent.sock").to_string_lossy().into_owned()
    }

    /// A fake service that records every request line and answers `ok`.
    fn recorder(name: &str) -> Arc<Mutex<Vec<Request>>> {
        let seen = Arc::new(Mutex::new(Vec::new()));
        let listener = UnixListener::bind(name).unwrap();
        let s = Arc::clone(&seen);
        std::thread::spawn(move || {
            for conn in listener.incoming().flatten() {
                let mut line = String::new();
                BufReader::new(&conn).read_line(&mut line).unwrap();
                s.lock()
                    .unwrap()
                    .push(ipc::parse_request(&line).expect("valid request"));
                (&conn).write_all(b"{\"ok\":true}\n").unwrap();
            }
        });
        seen
    }

    fn real_service(dir: &Path) -> Arc<RwLock<Config>> {
        let config = Arc::new(RwLock::new(Config {
            destinations: Destinations {
                ticket: Some(Destination::Cups { queue: "OLD".into() }),
                label: None,
            },
            ..Default::default()
        }));
        let handler = Handler {
            pairing: None,
            printer: Arc::new(NullPrinter),
            config: Arc::clone(&config),
            config_path: dir.join("config.json"),
            state: Arc::new(Mutex::new(AgentStatus {
                connection: Connection::Connected,
                ..Default::default()
            })),
            status_dirty: Arc::new(AtomicBool::new(false)),
            log_path: dir.join("logs/agent.log"),
            hostname: "PC-CAJA".into(),
        };
        ipc::serve(Arc::new(handler), &sock(dir)).unwrap();
        config
    }

    #[test]
    fn tray_actions_send_the_expected_requests() {
        let dir = scratch("rec");
        let name = sock(&dir);
        let seen = recorder(&name);
        let epson = Destination::Cups { queue: "EPSON".into() };

        assert!(!choose(&name, Target::Ticket, epson.clone()).warn);
        assert!(!test_print(&name, Target::Label).warn);
        assert!(!pair(&name, " https://tpv.example.com/ ", "482913").warn);
        assert_eq!(
            pair(&name, "https://tpv.example.com", "12"),
            Notice::warn("El código tiene 6 cifras.")
        );

        assert_eq!(
            *seen.lock().unwrap(),
            [
                Request::ChoosePrinters {
                    ticket: Some(epson),
                    label: None
                },
                Request::TestPrint { target: Target::Label },
                Request::Pair {
                    server: "https://tpv.example.com".into(),
                    code: "482913".into()
                },
            ],
            "a wrong code is never sent"
        );
        std::fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn choosing_on_the_real_service_changes_the_status() {
        let dir = scratch("real");
        let name = sock(&dir);
        let config = real_service(&dir);
        assert_eq!(status(&name).current(Target::Ticket), Some("OLD"));

        let notice = choose(&name, Target::Ticket, Destination::Cups { queue: "EPSON".into() });

        assert_eq!(notice, Notice::ok("Impresora de tickets guardada."));
        assert_eq!(
            config.read().unwrap().destinations.ticket,
            Some(Destination::Cups { queue: "EPSON".into() })
        );
        assert_eq!(config.read().unwrap().destinations.label, None, "label unchanged");
        assert_eq!(status(&name).current(Target::Ticket), Some("EPSON"));
        assert_eq!(
            test_print(&name, Target::Ticket),
            Notice::ok("Prueba enviada a la impresora de tickets.")
        );
        assert!(test_print(&name, Target::Label).warn, "no label printer chosen");
        assert_eq!(
            log_path(&name, Path::new("/nope")),
            dir.join("logs/agent.log").to_string_lossy()
        );
        std::fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn stopped_service_and_missing_permission_are_explained() {
        let dir = scratch("err");
        let name = sock(&dir);
        let stopped = status(&name);
        assert_eq!(stopped.problem(), Some(MSG_SERVICE_STOPPED));
        assert_eq!(test_print(&name, Target::Ticket), Notice::warn(MSG_SERVICE_STOPPED));

        let is_root = std::fs::metadata("/proc/self").map(|m| std::os::unix::fs::MetadataExt::uid(&m) == 0);
        if is_root.unwrap_or(true) {
            eprintln!("skipped the permission check: root ignores socket modes");
        } else {
            real_service(&dir);
            use std::os::unix::fs::PermissionsExt;
            std::fs::set_permissions(&name, std::fs::Permissions::from_mode(0o000)).unwrap();
            assert_eq!(status(&name).problem(), Some(MSG_NO_PERMISSION));
            assert_eq!(
                choose(&name, Target::Ticket, Destination::Cups { queue: "X".into() }),
                Notice::warn(MSG_NO_PERMISSION)
            );
        }
        std::fs::remove_dir_all(&dir).unwrap();
    }
}
