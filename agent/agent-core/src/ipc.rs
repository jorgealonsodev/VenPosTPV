//! Local control channel between the tray/CLI and the service (A3): a named
//! pipe on Windows, a 0660 Unix socket on Linux. No TCP. Fixed commands
//! only, `pair` included (I1: pair later from the tray); one JSON line in,
//! one JSON line out. The drawer cannot be opened from here (A4).
use crate::api::ProxyMode;
use crate::config::{Config, Destination, SharedConfig, Target};
use crate::job_loop::Shutdown;
use crate::printer::PrinterBackend;
use crate::secret::KeyStore;
use crate::state::{self, AgentStatus, SharedState};
use serde::{Deserialize, Serialize};
use std::io::{self, BufRead, BufReader, Read, Write};
use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

pub const MAX_LINE: u64 = 64 * 1024;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "cmd", rename_all = "snake_case", deny_unknown_fields)]
pub enum Request {
    Status,
    TestPrint {
        target: Target,
    },
    /// Sets the given destinations; a missing field leaves that one unchanged.
    ChoosePrinters {
        #[serde(default)]
        ticket: Option<Destination>,
        #[serde(default)]
        label: Option<Destination>,
    },
    OpenLog,
    /// Pairs with the 6-digit code from Ajustes → Cajas; the service stores its own key.
    Pair {
        server: String,
        code: String,
    },
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Response {
    pub ok: bool,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub error: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub status: Option<AgentStatus>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub log_path: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub terminal_name: Option<String>,
}

impl Response {
    fn ok() -> Self {
        Self {
            ok: true,
            ..Default::default()
        }
    }

    fn err(msg: impl Into<String>) -> Self {
        Self {
            ok: false,
            error: Some(msg.into()),
            ..Default::default()
        }
    }
}

/// Strict parse: serde ignores unknown fields on unit variants of an
/// internally tagged enum, so every key must also appear in the canonical
/// form of the parsed request.
pub fn parse_request(line: &str) -> Option<Request> {
    let value: serde_json::Value = serde_json::from_str(line.trim()).ok()?;
    let keys: Vec<String> = value.as_object()?.keys().cloned().collect();
    let req: Request = serde_json::from_value(value).ok()?;
    let canon = serde_json::to_value(&req).ok()?;
    let canon = canon.as_object()?;
    keys.iter().all(|k| canon.contains_key(k)).then_some(req)
}

/// What the service needs to pair from the local channel.
pub struct Pairing {
    pub key_store: KeyStore,
    pub proxy: ProxyMode,
    /// Restarted after a successful pairing so the job loop uses the new server and key.
    pub restart: Option<Arc<Shutdown>>,
    /// Plain HTTP to localhost, for tests only: the service always sets `false`, so
    /// the local channel only pairs over HTTPS (E11). The CLI keeps its own
    /// localhost exception for development.
    pub allow_http_localhost: bool,
}

pub struct Handler {
    /// `None`: the `pair` command is refused (e.g. a dev instance without a key store).
    pub pairing: Option<Pairing>,
    pub printer: Arc<dyn PrinterBackend>,
    pub config: SharedConfig,
    pub config_path: PathBuf,
    pub state: SharedState,
    pub status_dirty: Arc<AtomicBool>,
    pub log_path: PathBuf,
    pub hostname: String,
}

impl Handler {
    pub fn handle_line(&self, line: &str) -> Response {
        match parse_request(line) {
            Some(req) => self.handle(req),
            None => Response::err("orden no admitida"),
        }
    }

    pub fn handle(&self, req: Request) -> Response {
        match req {
            Request::Status => {
                let mut status = state::lock(&self.state).clone();
                status.version = crate::VERSION.into();
                // Before the first report (e.g. not paired yet) show the chosen printers.
                let dests = self
                    .config
                    .read()
                    .unwrap_or_else(|e| e.into_inner())
                    .destinations
                    .clone();
                for t in [Target::Ticket, Target::Label] {
                    if let (Some(d), false) = (dests.get(t), status.printers.contains_key(t.as_str())) {
                        status.printers.insert(
                            t.as_str().to_owned(),
                            crate::state::PrinterReport {
                                target: t,
                                name: d.display_name(),
                                status: self.printer.queue_status(d),
                            },
                        );
                    }
                }
                Response {
                    ok: true,
                    status: Some(status),
                    ..Default::default()
                }
            }
            Request::TestPrint { target } => {
                let dest = self
                    .config
                    .read()
                    .unwrap_or_else(|e| e.into_inner())
                    .destinations
                    .get(target)
                    .cloned();
                let Some(dest) = dest else {
                    return Response::err(format!("no hay impresora de {} elegida", target.as_str()));
                };
                let when = time::OffsetDateTime::now_utc()
                    .format(&time::format_description::well_known::Rfc3339)
                    .unwrap_or_default();
                let page = crate::escpos::test_page(target.as_str(), &self.hostname, &when);
                match self.printer.write_raw(&dest, "VenPos prueba", &page) {
                    Ok(_) => Response::ok(),
                    Err(e) => Response::err(e.to_string()),
                }
            }
            Request::ChoosePrinters { ticket, label } => {
                for d in [&ticket, &label].into_iter().flatten() {
                    if let Err(e) = d.validate() {
                        return Response::err(e.to_string());
                    }
                }
                let snapshot: Config = {
                    let mut cfg = self.config.write().unwrap_or_else(|e| e.into_inner());
                    if let Some(t) = ticket {
                        cfg.destinations.ticket = Some(t);
                    }
                    if let Some(l) = label {
                        cfg.destinations.label = Some(l);
                    }
                    cfg.clone()
                };
                if let Err(e) = snapshot.save(&self.config_path) {
                    return Response::err(e.to_string());
                }
                self.status_dirty.store(true, Ordering::SeqCst);
                Response::ok()
            }
            Request::OpenLog => Response {
                ok: true,
                log_path: Some(self.log_path.to_string_lossy().into_owned()),
                ..Default::default()
            },
            Request::Pair { server, code } => self.pair(&server, &code),
        }
    }

    /// Any local user who can open the channel may pair: the single-use
    /// code from a TPV administrator is the authorization. Only over HTTPS and
    /// only with the TPV already configured, if any (E11): changing TPV needs
    /// the installer as administrator. Logged without the code.
    fn pair(&self, server: &str, code: &str) -> Response {
        let Some(p) = &self.pairing else {
            return Response::err("este agente no puede emparejarse desde el canal local");
        };
        tracing::info!("emparejado pedido desde el canal local");
        let current = self.config.read().unwrap_or_else(|e| e.into_inner()).server_url.clone();
        let result = crate::pairing::strict_code(code)
            .and_then(|code| {
                crate::pairing::local_channel_server(server, current.as_deref(), p.allow_http_localhost)
                    .map(|server| (code, server))
            })
            .and_then(|(code, server)| crate::pairing::pair(&server, &code, &self.hostname, p.proxy, &p.key_store));
        let paired = match result {
            Ok(paired) => paired,
            Err(e) => {
                tracing::warn!(error = %e, "emparejado desde el canal local fallido");
                return Response::err(e.to_string());
            }
        };
        let snapshot: Config = {
            let mut cfg = self.config.write().unwrap_or_else(|e| e.into_inner());
            paired.apply(&mut cfg);
            cfg.clone()
        };
        if let Err(e) = snapshot.save(&self.config_path) {
            tracing::error!(error = %e, "clave guardada pero no la configuración del emparejado");
            return Response::err(e.to_string());
        }
        {
            let mut s = state::lock(&self.state);
            s.server_url = Some(paired.server_url.clone());
            s.terminal_name = Some(paired.terminal_name.clone());
        }
        tracing::info!(terminal = %paired.terminal_name, server = %paired.server_url, "agente emparejado desde el canal local");
        self.status_dirty.store(true, Ordering::SeqCst);
        if let Some(r) = &p.restart {
            r.restart();
        }
        Response {
            ok: true,
            terminal_name: Some(paired.terminal_name),
            ..Default::default()
        }
    }

    fn serve_conn<S: Read + Write>(&self, conn: S) -> io::Result<()> {
        let mut reader = BufReader::new(conn);
        let mut line = String::new();
        let n = (&mut reader).take(MAX_LINE).read_line(&mut line)?;
        let resp = if n == 0 {
            return Ok(());
        } else if !line.ends_with('\n') && u64::try_from(n).unwrap_or(u64::MAX) >= MAX_LINE {
            Response::err("orden demasiado larga")
        } else {
            self.handle_line(&line)
        };
        let mut out = serde_json::to_vec(&resp).map_err(io::Error::other)?;
        out.push(b'\n');
        let conn = reader.get_mut();
        conn.write_all(&out)?;
        conn.flush()
    }
}

#[cfg(unix)]
fn name_of(name: &str) -> io::Result<interprocess::local_socket::Name<'_>> {
    use interprocess::local_socket::{GenericFilePath, ToFsName};
    name.to_fs_name::<GenericFilePath>()
}

#[cfg(windows)]
fn name_of(name: &str) -> io::Result<interprocess::local_socket::Name<'_>> {
    use interprocess::local_socket::{GenericNamespaced, ToNsName};
    name.to_ns_name::<GenericNamespaced>()
}

/// Starts the listener thread. Linux: socket mode 0660 (owner + group).
/// Windows: SYSTEM and Administrators full, interactive users read/write.
pub fn serve(handler: Arc<Handler>, name: &str) -> io::Result<std::thread::JoinHandle<()>> {
    use interprocess::local_socket::ListenerOptions;
    use interprocess::local_socket::traits::ListenerExt as _;
    #[cfg(unix)]
    {
        if let Some(dir) = std::path::Path::new(name).parent() {
            std::fs::create_dir_all(dir)?;
        }
    }
    let opts = ListenerOptions::new()
        .name(name_of(name)?.into_owned())
        .try_overwrite(true);
    #[cfg(unix)]
    let opts = {
        use interprocess::os::unix::local_socket::ListenerOptionsExt;
        opts.mode(0o660)
    };
    #[cfg(windows)]
    let opts = {
        use interprocess::os::windows::local_socket::ListenerOptionsExt;
        use interprocess::os::windows::security_descriptor::SecurityDescriptor;
        let sddl = widestring::U16CString::from_str("D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)(A;;GA;;;OW)")
            .map_err(|e| io::Error::other(e.to_string()))?;
        opts.security_descriptor(SecurityDescriptor::deserialize(&sddl)?)
    };
    let listener = opts.create_sync()?;
    // The mode option is subject to the process umask (it differs between hosts and CI
    // runners): set 0660 explicitly so only the service user and its group can connect.
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(name, std::fs::Permissions::from_mode(0o660))?;
    }
    std::thread::Builder::new().name("ipc".into()).spawn(move || {
        for conn in listener.incoming() {
            match conn {
                Ok(c) => {
                    let h = Arc::clone(&handler);
                    let _ = std::thread::Builder::new().name("ipc-conn".into()).spawn(move || {
                        if let Err(e) = h.serve_conn(c) {
                            tracing::debug!(error = %e, "conexión local cerrada");
                        }
                    });
                }
                Err(e) => tracing::warn!(error = %e, "error en el canal local"),
            }
        }
    })
}

/// Client side (tray, CLI).
pub fn request(name: &str, req: &Request) -> io::Result<Response> {
    use interprocess::local_socket::Stream;
    use interprocess::local_socket::traits::Stream as _;
    let mut conn = Stream::connect(name_of(name)?)?;
    let mut line = serde_json::to_vec(req).map_err(io::Error::other)?;
    line.push(b'\n');
    conn.write_all(&line)?;
    conn.flush()?;
    let mut reader = BufReader::new(conn);
    let mut resp = String::new();
    (&mut reader).take(MAX_LINE * 4).read_line(&mut resp)?;
    serde_json::from_str(&resp).map_err(|e| io::Error::new(io::ErrorKind::InvalidData, e))
}
