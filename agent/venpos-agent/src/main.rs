//! `venpos-agent`: the service and its command line (A1, A6).
//!
//! Exit codes (one place, see [`ExitCode`]): 0 ok, 1 other error, 2 usage,
//! 3 pairing code rejected, 4 not paired / key rejected, 5 server
//! unreachable, 6 printer error, 7 needs administrator/root.

mod agent;
#[cfg(not(windows))]
mod linux_svc;
#[cfg(windows)]
mod windows_svc;

use agent_core::api::{ApiError, ProxyMode};
use agent_core::config::{Config, Destination, Target};
use agent_core::ipc::{self, Request};
use agent_core::pairing::PairError;
use agent_core::paths::Paths;
use agent_core::printer::{PrinterBackend, SystemPrinter};
use agent_core::secret::{KeyStore, PairingCode};
use clap::{Parser, Subcommand, ValueEnum};
use std::path::PathBuf;

#[derive(Parser)]
#[command(name = "venpos-agent", version = agent_core::VERSION, about = "Agente de impresión de VenPos")]
struct Cli {
    /// Directorio único para configuración, clave, registro y canal local (pruebas y desarrollo).
    #[arg(long, global = true, value_name = "DIR")]
    config_dir: Option<PathBuf>,
    #[command(subcommand)]
    command: Command,
}

#[derive(Clone, Copy, ValueEnum)]
enum TargetArg {
    Ticket,
    Label,
}

impl From<TargetArg> for Target {
    fn from(t: TargetArg) -> Self {
        match t {
            TargetArg::Ticket => Target::Ticket,
            TargetArg::Label => Target::Label,
        }
    }
}

#[derive(Subcommand)]
enum Command {
    /// Empareja el agente con una caja usando el código de 6 cifras de Ajustes → Cajas.
    Pair {
        code: String,
        /// Dirección del TPV (https://…).
        #[arg(long)]
        server: String,
    },
    /// Muestra el estado del servicio, la conexión y las impresoras.
    Status,
    /// Lista las impresoras de este equipo.
    ListPrinters {
        /// Escribe solo los nombres de las colas (UTF-16LE con BOM, una por línea) para el instalador.
        #[arg(long, hide = true, value_name = "FILE")]
        names_file: Option<PathBuf>,
    },
    /// Elige la impresora de tickets o de etiquetas (un modo por impresora).
    SetPrinter {
        #[arg(long, value_enum)]
        target: TargetArg,
        /// Cola de Windows (RAW).
        #[arg(long, group = "mode")]
        spooler: Option<String>,
        /// Cola CUPS (lp -o raw).
        #[arg(long, group = "mode")]
        cups: Option<String>,
        /// Dispositivo directo, p. ej. /dev/usb/lp0.
        #[arg(long, group = "mode")]
        device: Option<PathBuf>,
        /// Impresora de red, IP o nombre (puerto 9100 por defecto).
        #[arg(long, group = "mode")]
        network: Option<String>,
    },
    /// Imprime una página de prueba.
    TestPrint {
        #[arg(long, value_enum, default_value = "ticket")]
        target: TargetArg,
        /// Envía tal cual los bytes de este fichero, sin pasar por el servicio (pruebas de CI).
        #[arg(long, hide = true, value_name = "FILE")]
        bytes_file: Option<PathBuf>,
    },
    /// Instala el servicio (Windows: servicio; Linux: unidad systemd).
    Install,
    /// Desinstala el servicio.
    Uninstall,
    /// Ejecuta el agente en primer plano.
    Run,
    /// Punto de entrada del administrador de servicios de Windows.
    #[command(hide = true)]
    Service,
    /// Muestra la versión.
    Version,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
pub enum ExitCode {
    Ok = 0,
    Other = 1,
    PairingRejected = 3,
    NotPaired = 4,
    Unreachable = 5,
    Printer = 6,
    NeedsAdmin = 7,
}

pub struct Failure {
    pub code: ExitCode,
    pub message: String,
}

impl Failure {
    pub fn new(code: ExitCode, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
        }
    }
}

impl<E: std::fmt::Display> From<E> for Failure {
    fn from(e: E) -> Self {
        Failure::new(ExitCode::Other, e.to_string())
    }
}

fn api_failure(e: ApiError) -> Failure {
    let code = match &e {
        ApiError::PairingCodeInvalid => ExitCode::PairingRejected,
        ApiError::Unauthorized => ExitCode::NotPaired,
        ApiError::Transport(_) => ExitCode::Unreachable,
        _ => ExitCode::Other,
    };
    Failure::new(code, e.to_string())
}

fn main() {
    let cli = Cli::parse();
    let paths = Paths::resolve(cli.config_dir.as_deref());
    let result = match cli.command {
        Command::Run => agent::run_foreground(&paths),
        Command::Service => service_entry(&paths),
        other => {
            agent_core::logging::init_stderr();
            dispatch(other, &paths)
        }
    };
    match result {
        Ok(()) => std::process::exit(ExitCode::Ok as i32),
        Err(f) => {
            eprintln!("{}", f.message);
            std::process::exit(f.code as i32);
        }
    }
}

#[cfg(windows)]
fn service_entry(paths: &Paths) -> Result<(), Failure> {
    windows_svc::dispatch(paths.clone())
}

#[cfg(not(windows))]
fn service_entry(_paths: &Paths) -> Result<(), Failure> {
    Err(Failure::new(ExitCode::Other, "solo en Windows; en Linux usa `run`"))
}

fn dispatch(cmd: Command, paths: &Paths) -> Result<(), Failure> {
    match cmd {
        Command::Pair { code, server } => pair(paths, &code, &server),
        Command::Status => status(paths),
        Command::ListPrinters { names_file } => {
            let printers = SystemPrinter.list();
            if let Some(file) = names_file {
                let names: Vec<&str> = printers.iter().filter_map(|p| queue_name(&p.destination)).collect();
                return std::fs::write(&file, utf16_lines(&names))
                    .map_err(|e| Failure::new(ExitCode::Other, format!("{}: {e}", file.display())));
            }
            for p in printers {
                println!("{}\t{}", describe(&p.destination), p.detail);
            }
            Ok(())
        }
        Command::SetPrinter {
            target,
            spooler,
            cups,
            device,
            network,
        } => {
            let dest = match (spooler, cups, device, network) {
                (Some(printer), None, None, None) => Destination::Spooler { printer },
                (None, Some(queue), None, None) => Destination::Cups { queue },
                (None, None, Some(path), None) => Destination::Device { path },
                (None, None, None, Some(address)) => Destination::Network { address },
                _ => {
                    return Err(Failure::new(
                        ExitCode::Other,
                        "indica un modo: --spooler, --cups, --device o --network",
                    ));
                }
            };
            set_printer(paths, target.into(), dest)
        }
        Command::TestPrint { target, bytes_file } => match bytes_file {
            Some(file) => print_bytes_file(paths, target.into(), &file),
            None => test_print(paths, target.into()),
        },
        Command::Install => install(paths),
        Command::Uninstall => uninstall(),
        Command::Version => {
            println!("venpos-agent {}", agent_core::VERSION);
            Ok(())
        }
        Command::Run | Command::Service => unreachable_cmd(),
    }
}

fn unreachable_cmd() -> Result<(), Failure> {
    Err(Failure::new(ExitCode::Other, "orden no válida aquí"))
}

fn describe(d: &Destination) -> String {
    match d {
        Destination::Spooler { printer } => format!("--spooler \"{printer}\""),
        Destination::Cups { queue } => format!("--cups {queue}"),
        Destination::Device { path } => format!("--device {}", path.display()),
        Destination::Network { address } => format!("--network {address}"),
    }
}

/// The queue name a system printer is chosen by (`set-printer --spooler/--cups`).
fn queue_name(d: &Destination) -> Option<&str> {
    match d {
        Destination::Spooler { printer } => Some(printer),
        Destination::Cups { queue } => Some(queue),
        _ => None,
    }
}

/// UTF-16LE with BOM, CRLF after each line: what NSIS `FileReadUTF16LE`
/// reads without mangling accented printer names.
fn utf16_lines(lines: &[&str]) -> Vec<u8> {
    let mut out = vec![0xFF, 0xFE];
    for line in lines {
        for unit in line.encode_utf16().chain("\r\n".encode_utf16()) {
            out.extend(unit.to_le_bytes());
        }
    }
    out
}

fn pair(paths: &Paths, code: &str, server: &str) -> Result<(), Failure> {
    agent_core::config::validate_server_url(server)?;
    let code = PairingCode::parse(code)?;
    // ACLs first: without administrator rights fail before spending the code.
    prepare_secret_storage(paths)?;
    let key_store = KeyStore::new(paths.key_file.clone());
    let paired = agent_core::pairing::pair(server, &code, &agent_core::hostname(), ProxyMode::System, &key_store)
        .map_err(pair_failure)?;
    let mut cfg = Config::load(&paths.config_file)?;
    paired.apply(&mut cfg);
    cfg.save(&paths.config_file).map_err(admin_hint)?;
    finish_secret_storage(paths);
    restart_service_if_installed(paths);
    println!("Conectado: {}", paired.terminal_name);
    Ok(())
}

/// Same messages and exit codes as before the shared pairing code existed.
fn pair_failure(e: PairError) -> Failure {
    match e {
        PairError::InvalidUrl(c) => Failure::from(c),
        PairError::InvalidCode => Failure::from(agent_core::secret::SecretError::InvalidCode),
        PairError::CodeRejected => api_failure(ApiError::PairingCodeInvalid),
        PairError::TooManyAttempts => api_failure(ApiError::RateLimited { retry_after: None }),
        PairError::Unreachable(api) | PairError::Api(api) => api_failure(api),
        PairError::Key(k) => admin_hint(k),
    }
}

fn admin_hint<E: std::fmt::Display>(e: E) -> Failure {
    let msg = e.to_string();
    if msg.contains("denied") || msg.contains("denegado") || msg.contains("Permission") || msg.contains("permiso") {
        Failure::new(
            ExitCode::NeedsAdmin,
            format!("{msg}\nEjecuta la orden como administrador (Windows) o root (Linux)."),
        )
    } else {
        Failure::new(ExitCode::Other, msg)
    }
}

#[cfg(windows)]
fn prepare_secret_storage(paths: &Paths) -> Result<(), Failure> {
    windows_svc::secure_dirs(paths).map_err(admin_hint)
}

#[cfg(not(windows))]
fn prepare_secret_storage(_paths: &Paths) -> Result<(), Failure> {
    Ok(())
}

#[cfg(windows)]
fn finish_secret_storage(_paths: &Paths) {}

#[cfg(not(windows))]
fn finish_secret_storage(paths: &Paths) {
    if paths.system {
        linux_svc::chown_to_service_user(&[&paths.key_file, &paths.config_file]);
    }
}

#[cfg(windows)]
fn restart_service_if_installed(paths: &Paths) {
    if paths.system {
        windows_svc::restart_if_installed();
    }
}

#[cfg(not(windows))]
fn restart_service_if_installed(paths: &Paths) {
    if paths.system {
        linux_svc::try_restart();
    }
}

fn status(paths: &Paths) -> Result<(), Failure> {
    match ipc::request(&paths.ipc_name, &Request::Status) {
        Ok(resp) if resp.ok => {
            let s = resp.status.unwrap_or_default();
            println!("Servicio: en marcha (versión {})", s.version);
            println!(
                "Conexión: {}{}",
                s.connection.label(),
                s.message.map(|m| format!(" — {m}")).unwrap_or_default()
            );
            if let Some(name) = s.terminal_name {
                println!("Caja: {name}");
            }
            for (target, p) in s.printers {
                let state = serde_json::to_value(p.status.state)
                    .ok()
                    .and_then(|v| v.as_str().map(str::to_owned))
                    .unwrap_or_default();
                println!("Impresora {target}: {} [{state}] {}", p.name, p.status.detail);
            }
            println!("Trabajos: {} impresos, {} fallidos", s.printed_jobs, s.failed_jobs);
            if let Some(e) = s.last_error {
                println!("Último error: {e}");
            }
            Ok(())
        }
        Ok(resp) => Err(Failure::new(ExitCode::Other, resp.error.unwrap_or_default())),
        Err(_) => {
            let cfg = Config::load(&paths.config_file)?;
            let paired = KeyStore::new(paths.key_file.clone()).load().ok().flatten().is_some();
            println!("Servicio: no está en marcha");
            println!("TPV: {}", cfg.server_url.as_deref().unwrap_or("(sin emparejar)"));
            println!("Caja: {}", cfg.terminal_name.as_deref().unwrap_or("-"));
            println!("Clave: {}", if paired { "guardada" } else { "no" });
            for t in [Target::Ticket, Target::Label] {
                let d = cfg
                    .destinations
                    .get(t)
                    .map(describe)
                    .unwrap_or_else(|| "(sin elegir)".into());
                println!("Impresora {}: {d}", t.as_str());
            }
            Ok(())
        }
    }
}

fn set_printer(paths: &Paths, target: Target, dest: Destination) -> Result<(), Failure> {
    dest.validate()?;
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
    // Through the service when it runs, so it reports the change at once (S5).
    if let Ok(resp) = ipc::request(&paths.ipc_name, &req) {
        return if resp.ok {
            Ok(())
        } else {
            Err(Failure::new(ExitCode::Other, resp.error.unwrap_or_default()))
        };
    }
    let mut cfg = Config::load(&paths.config_file)?;
    match target {
        Target::Ticket => cfg.destinations.ticket = Some(dest),
        Target::Label => cfg.destinations.label = Some(dest),
    }
    cfg.save(&paths.config_file).map_err(admin_hint)
}

fn test_print(paths: &Paths, target: Target) -> Result<(), Failure> {
    if let Ok(resp) = ipc::request(&paths.ipc_name, &Request::TestPrint { target }) {
        return if resp.ok {
            println!("Prueba enviada");
            Ok(())
        } else {
            Err(Failure::new(ExitCode::Printer, resp.error.unwrap_or_default()))
        };
    }
    // Service not running: print directly with the local configuration.
    let cfg = Config::load(&paths.config_file)?;
    let dest = cfg.destinations.get(target).ok_or_else(|| {
        Failure::new(
            ExitCode::Printer,
            format!("no hay impresora de {} elegida", target.as_str()),
        )
    })?;
    let page = agent_core::escpos::test_page(target.as_str(), &agent_core::hostname(), "");
    SystemPrinter
        .write_raw(dest, "VenPos prueba", &page)
        .map_err(|e| Failure::new(ExitCode::Printer, e.to_string()))?;
    println!("Prueba enviada");
    Ok(())
}

/// CI byte check (AC9): the file's bytes, unchanged, to the chosen printer.
fn print_bytes_file(paths: &Paths, target: Target, file: &std::path::Path) -> Result<(), Failure> {
    let data = std::fs::read(file).map_err(|e| Failure::new(ExitCode::Other, format!("{}: {e}", file.display())))?;
    let cfg = Config::load(&paths.config_file)?;
    let dest = cfg.destinations.get(target).ok_or_else(|| {
        Failure::new(
            ExitCode::Printer,
            format!("no hay impresora de {} elegida", target.as_str()),
        )
    })?;
    SystemPrinter
        .write_raw(dest, "VenPos prueba", &data)
        .map_err(|e| Failure::new(ExitCode::Printer, e.to_string()))?;
    println!("Enviados {} bytes", data.len());
    Ok(())
}

#[cfg(windows)]
fn install(paths: &Paths) -> Result<(), Failure> {
    windows_svc::install(paths)
}

#[cfg(not(windows))]
fn install(_paths: &Paths) -> Result<(), Failure> {
    linux_svc::install()
}

#[cfg(windows)]
fn uninstall() -> Result<(), Failure> {
    windows_svc::uninstall()
}

#[cfg(not(windows))]
fn uninstall() -> Result<(), Failure> {
    linux_svc::uninstall()
}

#[cfg(test)]
mod tests {
    use super::utf16_lines;

    #[test]
    fn names_file_is_utf16le_with_bom_and_crlf() {
        assert_eq!(utf16_lines(&[]), vec![0xFF, 0xFE]);
        assert_eq!(
            utf16_lines(&["Añ", "B"]),
            vec![
                0xFF, 0xFE, b'A', 0, 0xF1, 0, b'\r', 0, b'\n', 0, b'B', 0, b'\r', 0, b'\n', 0
            ]
        );
    }
}
