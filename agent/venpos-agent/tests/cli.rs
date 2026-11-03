//! Command line (A6) with `--config-dir` against a one-shot fake server.
use std::io::{BufRead, BufReader, Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::Command;

const KEY: &str = "cli_key_0123456789abcdef0123456789abcdef0123456789abcdef0123456789";

fn bin() -> Command {
    let mut c = Command::new(env!("CARGO_BIN_EXE_venpos-agent"));
    for v in [
        "HTTPS_PROXY",
        "https_proxy",
        "HTTP_PROXY",
        "http_proxy",
        "ALL_PROXY",
        "all_proxy",
    ] {
        c.env_remove(v);
    }
    c
}

fn dir(name: &str) -> PathBuf {
    let d = std::env::temp_dir().join(format!("venpos-cli-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&d);
    std::fs::create_dir_all(&d).unwrap();
    d
}

/// Answers one request with `status`/`body`; returns the URL and the request body.
fn one_shot(status: u16, body: &'static str) -> (String, std::thread::JoinHandle<String>) {
    let l = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://127.0.0.1:{}", l.local_addr().unwrap().port());
    let h = std::thread::spawn(move || {
        let (mut s, _) = l.accept().unwrap();
        let mut r = BufReader::new(s.try_clone().unwrap());
        let mut len = 0;
        let mut line = String::new();
        r.read_line(&mut line).unwrap();
        let path = line.split_whitespace().nth(1).unwrap_or("").to_owned();
        loop {
            line.clear();
            r.read_line(&mut line).unwrap();
            if line == "\r\n" {
                break;
            }
            if let Some(v) = line.to_ascii_lowercase().strip_prefix("content-length:") {
                len = v.trim().parse().unwrap();
            }
        }
        let mut b = vec![0; len];
        r.read_exact(&mut b).unwrap();
        let resp = format!(
            "HTTP/1.1 {status} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
            body.len()
        );
        s.write_all(resp.as_bytes()).unwrap();
        format!("{path} {}", String::from_utf8(b).unwrap())
    });
    (url, h)
}

#[test]
fn version_prints_the_crate_version() {
    let out = bin().arg("version").output().unwrap();
    assert!(out.status.success());
    assert_eq!(
        String::from_utf8_lossy(&out.stdout).trim(),
        format!("venpos-agent {}", env!("CARGO_PKG_VERSION"))
    );
}

#[test]
fn pair_saves_the_key_privately_and_the_server_in_the_config() {
    let d = dir("pair");
    let (url, h) = one_shot(
        201,
        r#"{"key":"cli_key_0123456789abcdef0123456789abcdef0123456789abcdef0123456789","terminal_id":2,"terminal_name":"Caja 2"}"#,
    );

    let out = bin()
        .args(["--config-dir", d.to_str().unwrap(), "pair", "123 456", "--server", &url])
        .output()
        .unwrap();

    assert!(out.status.success(), "{}", String::from_utf8_lossy(&out.stderr));
    assert_eq!(String::from_utf8_lossy(&out.stdout).trim(), "Conectado: Caja 2");
    let req = h.join().unwrap();
    assert!(req.starts_with("/api/agent/v1/pair "), "{req}");
    let body: serde_json::Value = serde_json::from_str(req.split_once(' ').unwrap().1).unwrap();
    assert_eq!(body["code"], "123456");
    let cfg: serde_json::Value = serde_json::from_slice(&std::fs::read(d.join("config.json")).unwrap()).unwrap();
    assert_eq!(cfg["server_url"], url);
    assert_eq!(cfg["terminal_id"], 2);
    let key_path = d.join(if cfg!(windows) { "key.bin" } else { "key" });
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        assert_eq!(
            std::fs::metadata(&key_path).unwrap().permissions().mode() & 0o777,
            0o600
        );
        assert_eq!(std::fs::read_to_string(&key_path).unwrap(), KEY);
    }
    // E10: on Windows the key is stored encrypted with machine-scope DPAPI.
    #[cfg(windows)]
    {
        let stored = std::fs::read(&key_path).expect("the DPAPI key file exists after pairing");
        assert!(!stored.is_empty());
        assert!(
            !stored.windows(KEY.len()).any(|w| w == KEY.as_bytes()),
            "the key is not stored in clear"
        );
    }
    // Neither the key nor the code reach the terminal.
    let all = format!(
        "{}{}",
        String::from_utf8_lossy(&out.stdout),
        String::from_utf8_lossy(&out.stderr)
    );
    assert!(!all.contains(KEY) && !all.contains("123456"));
}

#[test]
fn rejected_code_exits_3() {
    let d = dir("pair-bad");
    let (url, h) = one_shot(422, r#"{"error":"pairing_code_invalid"}"#);
    let out = bin()
        .args(["--config-dir", d.to_str().unwrap(), "pair", "000000", "--server", &url])
        .output()
        .unwrap();
    h.join().unwrap();
    assert_eq!(out.status.code(), Some(3));
    assert!(!d.join("key").exists());
}

#[test]
fn plain_http_server_is_refused_without_any_request() {
    let d = dir("pair-http");
    let out = bin()
        .args([
            "--config-dir",
            d.to_str().unwrap(),
            "pair",
            "123456",
            "--server",
            "http://tpv.example.com",
        ])
        .output()
        .unwrap();
    assert_eq!(out.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&out.stderr).contains("solo se admite HTTPS"));
}

#[test]
fn set_printer_and_status_without_service() {
    let d = dir("set");
    let dd = d.to_str().unwrap();
    let ok = bin()
        .args([
            "--config-dir",
            dd,
            "set-printer",
            "--target",
            "ticket",
            "--cups",
            "Epson_TM-T20II",
        ])
        .output()
        .unwrap();
    assert!(ok.status.success(), "{}", String::from_utf8_lossy(&ok.stderr));
    let bad = bin()
        .args([
            "--config-dir",
            dd,
            "set-printer",
            "--target",
            "label",
            "--device",
            "/etc/passwd",
        ])
        .output()
        .unwrap();
    assert!(!bad.status.success());

    let out = bin().args(["--config-dir", dd, "status"]).output().unwrap();

    let text = String::from_utf8_lossy(&out.stdout);
    assert!(text.contains("Servicio: no está en marcha"), "{text}");
    assert!(text.contains("Impresora ticket: --cups Epson_TM-T20II"), "{text}");
    assert!(text.contains("Impresora label: (sin elegir)"), "{text}");
}

#[test]
fn test_print_bytes_file_sends_the_exact_bytes() {
    let d = dir("bytes");
    let dd = d.to_str().unwrap();
    let l = TcpListener::bind("127.0.0.1:0").unwrap();
    let addr = format!("127.0.0.1:{}", l.local_addr().unwrap().port());
    let h = std::thread::spawn(move || {
        let (mut s, _) = l.accept().unwrap();
        let mut got = Vec::new();
        s.read_to_end(&mut got).unwrap();
        got
    });
    let set = bin()
        .args([
            "--config-dir",
            dd,
            "set-printer",
            "--target",
            "ticket",
            "--network",
            &addr,
        ])
        .output()
        .unwrap();
    assert!(set.status.success(), "{}", String::from_utf8_lossy(&set.stderr));
    let bytes: Vec<u8> = (0..=255u8).chain([0x1B, 0x40, 0x00, 0x0A]).collect();
    let file = d.join("in.bin");
    std::fs::write(&file, &bytes).unwrap();

    let out = bin()
        .args(["--config-dir", dd, "test-print", "--bytes-file", file.to_str().unwrap()])
        .output()
        .unwrap();

    assert_eq!(out.status.code(), Some(0), "{}", String::from_utf8_lossy(&out.stderr));
    assert!(String::from_utf8_lossy(&out.stdout).contains("Enviados 260 bytes"));
    assert_eq!(h.join().unwrap(), bytes);
}

#[test]
fn test_print_bytes_file_without_printer_exits_6() {
    let d = dir("bytes-none");
    let file = d.join("in.bin");
    std::fs::write(&file, b"x").unwrap();
    let out = bin()
        .args([
            "--config-dir",
            d.to_str().unwrap(),
            "test-print",
            "--bytes-file",
            file.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert_eq!(out.status.code(), Some(6));
    assert!(String::from_utf8_lossy(&out.stderr).contains("no hay impresora de ticket elegida"));
}

#[test]
fn list_printers_names_file_starts_with_utf16_bom_and_plain_list_still_works() {
    let d = dir("names");
    let file = d.join("names.txt");
    let out = bin()
        .args(["list-printers", "--names-file", file.to_str().unwrap()])
        .output()
        .unwrap();
    assert!(out.status.success(), "{}", String::from_utf8_lossy(&out.stderr));
    assert!(out.stdout.is_empty());
    let bytes = std::fs::read(&file).unwrap();
    assert_eq!(&bytes[..2], &[0xFF, 0xFE]);
    assert_eq!(bytes.len() % 2, 0);
    // Previous behaviour: without the option it prints to stdout and writes nothing.
    let plain = bin().arg("list-printers").output().unwrap();
    assert!(plain.status.success());
}

#[test]
fn hidden_options_stay_out_of_help() {
    let out = bin().args(["test-print", "--help"]).output().unwrap();
    let text = String::from_utf8_lossy(&out.stdout);
    assert!(text.contains("--target"), "{text}");
    assert!(!text.contains("--bytes-file"), "{text}");
}
