//! Windows service (A2): virtual account `NT SERVICE\VenPosAgent`, auto
//! start, restart on failure, depends on the spooler.
use crate::{ExitCode, Failure};
use agent_core::job_loop::Shutdown;
use agent_core::paths::Paths;
use std::ffi::{OsStr, OsString};
use std::process::Command;
use std::sync::{Arc, Mutex, OnceLock};
use std::time::Duration;
use windows_service::service::{
    ServiceAccess, ServiceAction, ServiceActionType, ServiceControlAccept, ServiceDependency, ServiceErrorControl,
    ServiceExitCode, ServiceFailureActions, ServiceFailureResetPeriod, ServiceInfo, ServiceStartType, ServiceState,
    ServiceStatus, ServiceType,
};
use windows_service::service_control_handler::{self, ServiceControlHandlerResult, ServiceStatusHandle};
use windows_service::service_manager::{ServiceManager, ServiceManagerAccess};
use windows_service::{define_windows_service, service_dispatcher};

pub const SERVICE_NAME: &str = "VenPosAgent";
pub const SERVICE_ACCOUNT: &str = r"NT SERVICE\VenPosAgent";

static PATHS: OnceLock<Paths> = OnceLock::new();

define_windows_service!(ffi_service_main, service_main);

pub fn dispatch(paths: Paths) -> Result<(), Failure> {
    let _ = PATHS.set(paths);
    service_dispatcher::start(SERVICE_NAME, ffi_service_main).map_err(|e| {
        Failure::new(
            ExitCode::Other,
            format!("{e} (solo lo arranca el administrador de servicios)"),
        )
    })
}

fn status(state: ServiceState, accept: ServiceControlAccept, wait: Duration) -> ServiceStatus {
    ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: state,
        controls_accepted: accept,
        exit_code: ServiceExitCode::Win32(0),
        checkpoint: 0,
        wait_hint: wait,
        process_id: None,
    }
}

fn service_main(_args: Vec<OsString>) {
    let Some(paths) = PATHS.get() else { return };
    let _guard = agent_core::logging::init(&paths.log_file(), false).ok();
    let shutdown = Shutdown::new();
    let handle: Arc<Mutex<Option<ServiceStatusHandle>>> = Arc::new(Mutex::new(None));
    let (s2, h2) = (Arc::clone(&shutdown), Arc::clone(&handle));
    let registered = service_control_handler::register(SERVICE_NAME, move |ev| match ev {
        windows_service::service::ServiceControl::Stop | windows_service::service::ServiceControl::Shutdown => {
            if let Some(h) = *h2.lock().unwrap_or_else(|e| e.into_inner()) {
                // A long poll may take up to 40 s to return.
                let _ = h.set_service_status(status(
                    ServiceState::StopPending,
                    ServiceControlAccept::empty(),
                    Duration::from_secs(45),
                ));
            }
            s2.stop();
            ServiceControlHandlerResult::NoError
        }
        windows_service::service::ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,
        _ => ServiceControlHandlerResult::NotImplemented,
    });
    let Ok(h) = registered else {
        tracing::error!("no se pudo registrar el servicio");
        return;
    };
    *handle.lock().unwrap_or_else(|e| e.into_inner()) = Some(h);
    let _ = h.set_service_status(status(
        ServiceState::Running,
        ServiceControlAccept::STOP | ServiceControlAccept::SHUTDOWN,
        Duration::ZERO,
    ));
    if let Err(f) = crate::agent::run(paths, &shutdown) {
        tracing::error!(error = %f.message, "el agente se detuvo con error");
    }
    let _ = h.set_service_status(status(
        ServiceState::Stopped,
        ServiceControlAccept::empty(),
        Duration::ZERO,
    ));
}

fn icacls(path: &std::path::Path, args: &[&str]) -> std::io::Result<()> {
    let st = Command::new("icacls")
        .arg(path)
        .args(args)
        .stdout(std::process::Stdio::null())
        .status()?;
    if st.success() {
        Ok(())
    } else {
        Err(std::io::Error::other(format!(
            "icacls {} {} falló",
            path.display(),
            args.join(" ")
        )))
    }
}

/// E10: base folder readable by users (config, log), writable only by the
/// service and administrators; `secret\` only for SYSTEM, Administrators
/// and the service account (modify: it stores its own key when paired from
/// the tray, I1). Users never get access to `secret\`.
pub fn secure_dirs(paths: &Paths) -> std::io::Result<()> {
    if !paths.system {
        return Ok(());
    }
    let Some(secret) = paths.key_file.parent() else {
        return Ok(());
    };
    let Some(base) = paths.config_file.parent() else {
        return Ok(());
    };
    std::fs::create_dir_all(secret)?;
    icacls(
        base,
        &[
            "/inheritance:r",
            "/grant:r",
            "*S-1-5-18:(OI)(CI)F",
            "*S-1-5-32-544:(OI)(CI)F",
            "*S-1-5-32-545:(OI)(CI)RX",
        ],
    )?;
    icacls(
        secret,
        &[
            "/inheritance:r",
            "/grant:r",
            "*S-1-5-18:(OI)(CI)F",
            "*S-1-5-32-544:(OI)(CI)F",
        ],
    )?;
    // The virtual account exists only once the service is created.
    if icacls(base, &["/grant", &format!("{SERVICE_ACCOUNT}:(OI)(CI)M")]).is_ok() {
        icacls(secret, &["/grant", &format!("{SERVICE_ACCOUNT}:(OI)(CI)M")])?;
    }
    Ok(())
}

fn win_err(e: windows_service::Error) -> Failure {
    let msg = e.to_string();
    let code = if msg.contains("denied") || msg.contains("denegado") || msg.contains("(os error 5)") {
        ExitCode::NeedsAdmin
    } else {
        ExitCode::Other
    };
    Failure::new(code, format!("{msg}\nEjecuta la orden como administrador."))
}

pub fn install(paths: &Paths) -> Result<(), Failure> {
    let manager = ServiceManager::local_computer(
        None::<&str>,
        ServiceManagerAccess::CONNECT | ServiceManagerAccess::CREATE_SERVICE,
    )
    .map_err(win_err)?;
    let info = ServiceInfo {
        name: OsString::from(SERVICE_NAME),
        display_name: OsString::from("VenPos Agent"),
        service_type: ServiceType::OWN_PROCESS,
        start_type: ServiceStartType::AutoStart,
        error_control: ServiceErrorControl::Normal,
        executable_path: std::env::current_exe()?,
        launch_arguments: vec![OsString::from("service")],
        dependencies: vec![ServiceDependency::Service(OsString::from("Spooler"))],
        account_name: Some(OsString::from(SERVICE_ACCOUNT)),
        account_password: None,
    };
    let service = manager
        .create_service(
            &info,
            ServiceAccess::CHANGE_CONFIG | ServiceAccess::START | ServiceAccess::QUERY_STATUS,
        )
        .map_err(win_err)?;
    let _ = service.set_description("Imprime los tickets y etiquetas y abre el cajón de esta caja del TPV VenPos.");
    let restart = |s| ServiceAction {
        action_type: ServiceActionType::Restart,
        delay: Duration::from_secs(s),
    };
    let _ = service.update_failure_actions(ServiceFailureActions {
        reset_period: ServiceFailureResetPeriod::After(Duration::from_secs(86_400)),
        reboot_msg: None,
        command: None,
        actions: Some(vec![restart(5), restart(10), restart(30)]),
    });
    secure_dirs(paths).map_err(|e| Failure::new(ExitCode::NeedsAdmin, e.to_string()))?;
    service.start(&[] as &[&OsStr]).map_err(win_err)?;
    println!("Servicio {SERVICE_NAME} instalado y en marcha ({SERVICE_ACCOUNT})");
    Ok(())
}

pub fn uninstall() -> Result<(), Failure> {
    let manager = ServiceManager::local_computer(None::<&str>, ServiceManagerAccess::CONNECT).map_err(win_err)?;
    let service = manager
        .open_service(
            SERVICE_NAME,
            ServiceAccess::STOP | ServiceAccess::DELETE | ServiceAccess::QUERY_STATUS,
        )
        .map_err(win_err)?;
    let _ = service.stop();
    service.delete().map_err(win_err)?;
    println!("Servicio {SERVICE_NAME} desinstalado; %ProgramData%\\VenPos Agent se conserva");
    Ok(())
}

/// After pairing: a new server URL or key is only read at start.
pub fn restart_if_installed() {
    let Ok(manager) = ServiceManager::local_computer(None::<&str>, ServiceManagerAccess::CONNECT) else {
        return;
    };
    let Ok(service) = manager.open_service(
        SERVICE_NAME,
        ServiceAccess::STOP | ServiceAccess::START | ServiceAccess::QUERY_STATUS,
    ) else {
        return;
    };
    let _ = service.stop();
    for _ in 0..90 {
        match service.query_status() {
            Ok(s) if s.current_state == ServiceState::Stopped => break,
            Ok(_) => std::thread::sleep(Duration::from_millis(500)),
            Err(_) => return,
        }
    }
    let _ = service.start(&[] as &[&OsStr]);
}
