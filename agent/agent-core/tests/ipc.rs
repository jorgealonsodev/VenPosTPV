//! Local control channel (A3, A4, S5).
mod support;

use agent_core::api::ProxyMode;
use agent_core::config::{Config, Destination, Destinations, Target};
use agent_core::ipc::{self, Handler, Pairing, Request};
use agent_core::job_loop::Shutdown;
use agent_core::secret::KeyStore;
use agent_core::state::{AgentStatus, Connection};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use support::*;

fn handler(dir: &std::path::Path, printer: Arc<MockPrinter>) -> Handler {
    Handler {
        pairing: Some(Pairing {
            key_store: KeyStore::new(dir.join("key")),
            proxy: ProxyMode::None,
            restart: Some(Shutdown::new()),
            // The fake TPV speaks plain HTTP on 127.0.0.1; the service never allows it.
            allow_http_localhost: true,
        }),
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

const CODE_REJECTED: &str = "Código caducado o incorrecto. Genera uno nuevo en Ajustes → Cajas.";

fn pair_line(server: &str, code: &str) -> String {
    serde_json::json!({"cmd": "pair", "server": server, "code": code}).to_string()
}

fn restart_of(h: &Handler) -> Arc<Shutdown> {
    Arc::clone(h.pairing.as_ref().unwrap().restart.as_ref().unwrap())
}

#[test]
fn pair_command_parses_strictly() {
    assert_eq!(
        ipc::parse_request(&pair_line("https://tpv.example", "482913")),
        Some(Request::Pair {
            server: "https://tpv.example".into(),
            code: "482913".into()
        })
    );
    for line in [
        r#"{"cmd":"pair","server":"https://tpv.example","code":"482913","key":"x"}"#,
        r#"{"cmd":"pair","server":"https://tpv.example"}"#,
        r#"{"cmd":"pair","code":"482913"}"#,
        r#"{"cmd":"pair","server":"https://tpv.example","code":482913}"#,
    ] {
        assert_eq!(ipc::parse_request(line), None, "{line}");
    }
    let dir = temp_dir("ipc-pair-unknown");
    let h = handler(&dir, MockPrinter::new());
    let r = h.handle_line(r#"{"cmd":"pair","server":"https://tpv.example","code":"482913","key":"x"}"#);
    assert_eq!(r.error.as_deref(), Some("orden no admitida"));
}

#[test]
fn pair_rejects_bad_codes_and_urls_before_calling_the_server() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let dir = temp_dir("ipc-pair-bad");
    let h = handler(&dir, MockPrinter::new());

    for code in ["48291", "4829134", "48 913", " 482913", "48291a"] {
        let r = h.handle_line(&pair_line(&server.url, code));
        assert!(!r.ok, "{code:?}");
        assert_eq!(r.error.as_deref(), Some("El código tiene 6 cifras."), "{code:?}");
    }
    for url in [
        "http://evil.example.com",
        "file:///C:/x",
        "https://user@tpv.example",
        "tpv.example",
    ] {
        let r = h.handle_line(&pair_line(url, "482913"));
        assert!(!r.ok, "{url}");
        assert!(r.error.as_deref().unwrap().contains("https://"), "{url}: {r:?}");
    }

    assert!(server.requests("POST", "/api/agent/v1/pair").is_empty());
    assert!(!dir.join("key").exists());
    assert!(!dir.join("config.json").exists());
    assert!(!restart_of(&h).take_restart());
}

#[test]
fn pair_stores_the_key_and_server_keeps_the_printers_and_restarts_the_loop() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let dir = temp_dir("ipc-pair-ok");
    let h = handler(&dir, MockPrinter::new());

    let r = h.handle_line(&pair_line(&format!("{}/", server.url), "482913"));

    assert!(r.ok, "{r:?}");
    assert_eq!(r.terminal_name.as_deref(), Some("Caja 3"));
    let key = KeyStore::new(dir.join("key")).load().unwrap().unwrap();
    assert_eq!(key.expose(), KEY);
    let saved = Config::load(&dir.join("config.json")).unwrap();
    assert_eq!(saved.server_url.as_deref(), Some(server.url.as_str()));
    assert_eq!(saved.terminal_id, Some(3));
    assert_eq!(saved.terminal_name.as_deref(), Some("Caja 3"));
    assert_eq!(
        saved.destinations.ticket,
        Some(Destination::Cups {
            queue: "TICKETS".into()
        }),
        "chosen printer kept"
    );
    assert_eq!(*h.config.read().unwrap(), saved, "running config updated");
    assert_eq!(h.state.lock().unwrap().terminal_name.as_deref(), Some("Caja 3"));
    let restart = restart_of(&h);
    assert!(restart.is_stopped(), "the job loop is asked to start again");
    assert!(restart.take_restart());
    assert!(!restart.is_stopped());
    let body = &server.requests("POST", "/api/agent/v1/pair")[0].body;
    assert_eq!(body["code"], "482913");
    assert_eq!(body["hostname"], "PC-CAJA3");
}

#[test]
fn pair_reports_a_rejected_code_or_an_unreachable_server_in_spanish() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let dir = temp_dir("ipc-pair-fail");
    let h = handler(&dir, MockPrinter::new());

    let r = h.handle_line(&pair_line(&server.url, "000000"));
    assert!(!r.ok);
    assert_eq!(r.error.as_deref(), Some(CODE_REJECTED));

    // A port nothing listens on.
    let closed = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://127.0.0.1:{}", closed.local_addr().unwrap().port());
    drop(closed);
    let r = h.handle_line(&pair_line(&url, "482913"));
    assert!(!r.ok);
    assert_eq!(
        r.error.as_deref(),
        Some("No se puede conectar con el TPV. Revisa la dirección y la conexión a Internet.")
    );

    assert!(!dir.join("key").exists());
    assert!(!dir.join("config.json").exists());
    assert!(!restart_of(&h).take_restart());
}

const CHANGE_TPV: &str = "Para cambiar de TPV, vuelve a ejecutar el instalador como administrador";

#[test]
fn the_service_refuses_plain_http_over_the_local_channel_even_on_localhost() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let dir = temp_dir("ipc-pair-http");
    let mut h = handler(&dir, MockPrinter::new());
    h.pairing.as_mut().unwrap().allow_http_localhost = false;

    for url in [
        server.url.clone(),
        "http://localhost:8080".into(),
        "http://[::1]".into(),
    ] {
        let r = h.handle_line(&pair_line(&url, "482913"));
        assert!(!r.ok, "{url}");
        assert_eq!(
            r.error.as_deref(),
            Some("La dirección del TPV no es válida: tiene que empezar por https://"),
            "{url}"
        );
    }

    assert!(server.requests("POST", "/api/agent/v1/pair").is_empty());
    assert!(!dir.join("key").exists());
    assert!(!restart_of(&h).take_restart());
}

#[test]
fn pairing_to_another_tpv_from_the_local_channel_is_refused() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let dir = temp_dir("ipc-pair-other");
    let h = handler(&dir, MockPrinter::new());
    h.config.write().unwrap().server_url = Some("https://tpv.tienda.example".into());
    h.config.write().unwrap().terminal_id = Some(1);

    let r = h.handle_line(&pair_line(&server.url, "482913"));

    assert!(!r.ok);
    assert_eq!(r.error.as_deref(), Some(CHANGE_TPV));
    assert!(
        server.requests("POST", "/api/agent/v1/pair").is_empty(),
        "no request to the other TPV"
    );
    assert!(!dir.join("key").exists());
    assert_eq!(
        h.config.read().unwrap().server_url.as_deref(),
        Some("https://tpv.tienda.example"),
        "still paired to the first TPV"
    );
    assert!(!restart_of(&h).take_restart());
}

#[test]
fn pairing_again_with_the_same_tpv_from_the_local_channel_works() {
    let server = FakeServer::start();
    server.st().pair_code = Some("482913".into());
    let dir = temp_dir("ipc-pair-same");
    let h = handler(&dir, MockPrinter::new());
    h.config.write().unwrap().server_url = Some(server.url.clone());
    h.config.write().unwrap().terminal_id = Some(1);

    let r = h.handle_line(&pair_line(&format!("{}/", server.url), "482913"));

    assert!(r.ok, "{r:?}");
    assert_eq!(h.config.read().unwrap().terminal_id, Some(3));
    assert_eq!(KeyStore::new(dir.join("key")).load().unwrap().unwrap().expose(), KEY);
}

#[test]
fn pair_is_refused_when_the_instance_cannot_store_a_key() {
    let dir = temp_dir("ipc-pair-off");
    let mut h = handler(&dir, MockPrinter::new());
    h.pairing = None;
    let r = h.handle_line(&pair_line("https://tpv.example", "482913"));
    assert!(!r.ok);
}

#[test]
fn a_real_stop_wins_over_a_restart() {
    let s = Shutdown::new();
    s.restart();
    assert!(
        s.wait(std::time::Duration::from_secs(5)),
        "restart wakes a waiting loop"
    );
    s.stop();
    assert!(!s.take_restart());
    assert!(s.is_stopped());
}
