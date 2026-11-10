//! The window's `ui::os` on Windows: opening a URL or the log, starting and
//! stopping the service (elevated with the UAC prompt) and raising a window
//! that is already open. The pure parts are in `model`.
use crate::model::{self, ServiceAction, WindowInfo};
use windows::Win32::Foundation::{CloseHandle, ERROR_CANCELLED, HWND, LPARAM};
use windows::Win32::System::Threading::{
    GetExitCodeProcess, INFINITE, OpenProcess, PROCESS_NAME_WIN32, PROCESS_QUERY_LIMITED_INFORMATION,
    QueryFullProcessImageNameW, WaitForSingleObject,
};
use windows::Win32::UI::Shell::{
    SEE_MASK_NOASYNC, SEE_MASK_NOCLOSEPROCESS, SHELLEXECUTEINFOW, ShellExecuteExW, ShellExecuteW,
};
use windows::Win32::UI::WindowsAndMessaging::{
    EnumWindows, GetClassNameW, GetWindowTextW, GetWindowThreadProcessId, IsIconic, IsWindowVisible,
    MB_ICONINFORMATION, MB_ICONWARNING, MB_OK, MESSAGEBOX_STYLE, MessageBoxW, SW_HIDE, SW_RESTORE, SW_SHOWNORMAL,
    SetForegroundWindow, ShowWindow,
};
use windows::core::{BOOL, HSTRING, PCWSTR, PWSTR};

pub const MSG_NO_BROWSER: &str = "No se pudo abrir el navegador.";

/// A message box titled "VenPos Agent".
pub fn message(text: &str, warn: bool) {
    let style: MESSAGEBOX_STYLE = MB_OK | if warn { MB_ICONWARNING } else { MB_ICONINFORMATION };
    // SAFETY: HSTRINGs are NUL-terminated and live for the call; no owner window.
    unsafe { MessageBoxW(None, &HSTRING::from(text), &HSTRING::from(model::WINDOW_TITLE), style) };
}

/// Opens a validated https URL or the log file with its default program.
pub fn open(target: &str) -> bool {
    // SAFETY: HSTRINGs live for the call.
    let r = unsafe {
        ShellExecuteW(
            None,
            &HSTRING::from("open"),
            &HSTRING::from(target),
            None,
            None,
            SW_SHOWNORMAL,
        )
    };
    // ShellExecuteW reports success with a value greater than 32.
    r.0 as isize > 32
}

/// Windows always has a desktop for the window.
pub fn check_display() -> Result<(), String> {
    Ok(())
}

/// The window runs without a console: a message box.
pub fn report(text: &str) {
    message(text, true);
}

pub fn restart_service() -> Result<(), String> {
    run_elevated(ServiceAction::Restart)
}

pub fn stop_service() -> Result<(), String> {
    run_elevated(ServiceAction::Stop)
}

/// Runs PowerShell as administrator (UAC prompt) and waits for it.
fn run_elevated(action: ServiceAction) -> Result<(), String> {
    let verb = HSTRING::from("runas");
    let file = HSTRING::from("powershell.exe");
    let params = HSTRING::from(model::windows_service_command(action));
    let mut info = SHELLEXECUTEINFOW {
        cbSize: size_of::<SHELLEXECUTEINFOW>() as u32,
        fMask: SEE_MASK_NOCLOSEPROCESS | SEE_MASK_NOASYNC,
        lpVerb: PCWSTR(verb.as_ptr()),
        lpFile: PCWSTR(file.as_ptr()),
        lpParameters: PCWSTR(params.as_ptr()),
        nShow: SW_HIDE.0,
        ..Default::default()
    };
    let what = match action {
        ServiceAction::Restart => "reinició",
        ServiceAction::Stop => "detuvo",
    };
    // SAFETY: `info` and the HSTRINGs it points to live for the call.
    if let Err(e) = unsafe { ShellExecuteExW(&mut info) } {
        return Err(if e.code() == ERROR_CANCELLED.to_hresult() {
            format!("No se {what} el servicio: se canceló el permiso de administrador.")
        } else {
            format!("No se {what} el servicio: {}", e.message())
        });
    }
    if info.hProcess.is_invalid() {
        return Ok(());
    }
    let mut code = 1u32;
    // SAFETY: hProcess is a process handle owned by us (SEE_MASK_NOCLOSEPROCESS), closed once.
    unsafe {
        WaitForSingleObject(info.hProcess, INFINITE);
        let _ = GetExitCodeProcess(info.hProcess, &mut code);
        let _ = CloseHandle(info.hProcess);
    }
    if code == 0 {
        Ok(())
    } else {
        Err(format!(
            "No se {what} el servicio {} (código {code}). Mira el registro.",
            model::WINDOWS_SERVICE
        ))
    }
}

fn window_text(hwnd: HWND, class: bool) -> String {
    let mut buf = [0u16; 256];
    // SAFETY: `buf` is a valid writable buffer of its length.
    let n = unsafe {
        if class {
            GetClassNameW(hwnd, &mut buf)
        } else {
            GetWindowTextW(hwnd, &mut buf)
        }
    };
    String::from_utf16_lossy(&buf[..usize::try_from(n).unwrap_or(0)])
}

fn process_image(pid: u32) -> Option<std::path::PathBuf> {
    // SAFETY: the handle is closed below; the buffer outlives the call.
    unsafe {
        let h = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, pid).ok()?;
        let mut buf = [0u16; 1024];
        let mut len = buf.len() as u32;
        let ok = QueryFullProcessImageNameW(h, PROCESS_NAME_WIN32, PWSTR(buf.as_mut_ptr()), &mut len).is_ok();
        let _ = CloseHandle(h);
        ok.then(|| String::from_utf16_lossy(&buf[..len as usize]).into())
    }
}

struct Search {
    exe: Option<std::path::PathBuf>,
    found: Option<HWND>,
}

unsafe extern "system" fn visit(hwnd: HWND, lparam: LPARAM) -> BOOL {
    // SAFETY: lparam is the &mut Search passed by raise_existing_window, alive during EnumWindows.
    let search = unsafe { &mut *(lparam.0 as *mut Search) };
    // SAFETY: plain queries on a window handle EnumWindows gave us.
    if !unsafe { IsWindowVisible(hwnd) }.as_bool() {
        return true.into();
    }
    let title = window_text(hwnd, false);
    if title != model::WINDOW_TITLE {
        return true.into();
    }
    let mut pid = 0u32;
    // SAFETY: `pid` is a valid out pointer.
    unsafe { GetWindowThreadProcessId(hwnd, Some(&mut pid)) };
    let info = WindowInfo {
        title,
        class: window_text(hwnd, true),
        same_exe: search.exe.is_some() && process_image(pid) == search.exe,
        other_process: pid != std::process::id(),
    };
    if model::is_agent_window(&info) {
        search.found = Some(hwnd);
        return false.into();
    }
    true.into()
}

/// Brings the window of another launch to the front; false when there is none.
pub fn raise_existing_window() -> bool {
    let mut search = Search {
        exe: std::env::current_exe().ok(),
        found: None,
    };
    // SAFETY: `search` outlives EnumWindows, which calls `visit` synchronously.
    let _ = unsafe { EnumWindows(Some(visit), LPARAM(&mut search as *mut Search as isize)) };
    let Some(hwnd) = search.found else {
        return false;
    };
    // SAFETY: plain calls on a live top-level window handle.
    unsafe {
        if IsIconic(hwnd).as_bool() {
            let _ = ShowWindow(hwnd, SW_RESTORE);
        }
        let _ = SetForegroundWindow(hwnd);
    }
    true
}

/// A later launch raises this window itself (raise_existing_window): nothing to listen for.
pub fn listen_for_raise(_on_show: impl Fn() + Send + 'static) {}

/// The window could not open (no OpenGL 2 on this computer, e.g. a remote session).
pub fn window_failed(error: &str) {
    message(
        &format!(
            "No se pudo abrir la ventana de VenPos Agent ({error}).\n\nUsa el menú del icono VenPos de la bandeja del sistema: \
             tiene las mismas opciones."
        ),
        true,
    );
}

/// Starts this program with `--panel` (the window); it raises an open one instead.
pub fn spawn_window(config_dir: Option<&std::path::Path>) -> bool {
    let Ok(exe) = std::env::current_exe() else {
        return false;
    };
    let mut cmd = std::process::Command::new(exe);
    cmd.arg("--panel");
    if let Some(d) = config_dir {
        cmd.arg("--config-dir").arg(d);
    }
    cmd.spawn().is_ok()
}
