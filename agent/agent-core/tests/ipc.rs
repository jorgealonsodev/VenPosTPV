//! Local control channel (A3, A4, S5).
mod support;

use agent_core::config::{Config, Destination, Destinations, Target};
use agent_core::ipc::{self, Handler, Request};
use agent_core::state::{AgentStatus, Connection};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use support::*;

fn handler(dir: &std::path::Path, printer: Arc<MockPrinter>) -> Handler {
    Handler {
        printer,
        config: Arc::new(RwLock::new(Config {
            destinations: Destinations {
                ticket: Some(Destination::Cups {
                    queue: "TICKETS".into(),
                }),
                label: None,
            },
            ..Default::default()
        })),
        config_path: dir.join("config.json"),
        state: Arc::new(Mutex::new(AgentStatus {
            connection: Connection::Connected,
            ..Default::default()
        })),
        status_dirty: Arc::new(AtomicBool::new(false)),
        log_path: dir.join("logs/agent.log"),
        hostname: "PC-CAJA3".into(),
    }
}

#[test]
fn only_fixed_commands_are_accepted_and_none_opens_the_drawer() {
    let dir = temp_dir("ipc-fixed");
    let printer = MockPrinter::new();
    let h = handler(&dir, Arc::clone(&printer));
    for line in [
        r#"{"cmd":"open_drawer"}"#,
        r#"{"cmd":"drawer"}"#,
        r#"{"cmd":"test_print","target":"ticket","bytes":"G3AAGfo="}"#,
        r#"{"cmd":"status","extra":1}"#,
        "not json",
    ] {
        let r = h.handle_line(line);
        assert!(!r.ok, "{line} accepted");
    }
    assert!(printer.writes().is_empty());
    let status = h.handle_line(r#"{"cmd":"status"}"#).status.unwrap();
    // Before the first report the chosen printers are still listed.
    assert_eq!(status.printers["ticket"].name, "TICKETS");
    assert!(!status.printers.contains_key("label"));
}

#[test]
fn test_print_sends_the_test_page_and_never_the_drawer_pulse() {
    let dir = temp_dir("ipc-test");
    let printer = MockPrinter::new();
    let h = handler(&dir, Arc::clone(&printer));

    assert!(h.handle(Request::TestPrint { target: Target::Ticket }).ok);
    let r = h.handle(Request::TestPrint { target: Target::Label });

    assert!(!r.ok, "no label printer chosen");
    let w = printer.writes();
    assert_eq!(w.len(), 1);
    assert!(String::from_utf8_lossy(&w[0].data).contains("Prueba de impresi"));
    assert!(
        !w[0].data.windows(2).any(|p| p == [0x1B, 0x70]),
        "no ESC p in the test page"
    );
}

#[test]
fn choose_printers_persists_and_triggers_a_status_report() {
    let dir = temp_dir("ipc-choose");
    let h = handler(&dir, MockPrinter::new());
    let label = Destination::Device {
        path: "/dev/usb/lp1".into(),
    };

    let r = h.handle(Request::ChoosePrinters {
        ticket: None,
        label: Some(label.clone()),
    });

    assert!(r.ok, "{r:?}");
    let saved = Config::load(&dir.join("config.json")).unwrap();
    assert_eq!(saved.destinations.label, Some(label));
    assert_eq!(
        saved.destinations.ticket,
        Some(Destination::Cups {
            queue: "TICKETS".into()
        }),
        "ticket unchanged"
    );
    assert!(h.status_dirty.load(Ordering::SeqCst));
    let bad = h.handle(Request::ChoosePrinters {
        ticket: Some(Destination::Device {
            path: "/etc/shadow".into(),
        }),
        label: None,
    });
    assert!(!bad.ok);
}

#[cfg(unix)]
#[test]
fn unix_socket_is_0660_and_answers_status() {
    use std::os::unix::fs::PermissionsExt;
    let dir = temp_dir("ipc-sock");
    let name = dir.join("agent.sock").to_string_lossy().into_owned();
    let h = Arc::new(handler(&dir, MockPrinter::new()));
    ipc::serve(h, &name).unwrap();

    let resp = ipc::request(&name, &Request::Status).unwrap();

    assert!(resp.ok);
    assert_eq!(resp.status.unwrap().connection, Connection::Connected);
    let mode = std::fs::metadata(&name).unwrap().permissions().mode() & 0o777;
    assert_eq!(mode, 0o660);
    let log = ipc::request(&name, &Request::OpenLog).unwrap();
    assert!(log.log_path.unwrap().ends_with("agent.log"));
}
