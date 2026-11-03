//! "Conectar con el TPV…" (I1): a small modal Win32 window with two edit
//! boxes. The tray never writes the key: it sends IPC `pair` and the
//! service, which owns the key, redeems the code. The request runs on a
//! helper thread so the window keeps painting while the service waits for
//! the TPV.
use crate::model::{self, PairOutcome};
use agent_core::ipc::{self, Response};
use std::cell::RefCell;
use std::sync::{Arc, Mutex};
use windows::Win32::Foundation::{HWND, LPARAM, LRESULT, WPARAM};
use windows::Win32::Graphics::Gdi::{COLOR_BTNFACE, DEFAULT_GUI_FONT, GetStockObject, HBRUSH};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::UI::Input::KeyboardAndMouse::{EnableWindow, SetFocus};
use windows::Win32::UI::WindowsAndMessaging::{
    CreateWindowExW, DefWindowProcW, DestroyWindow, DispatchMessageW, GetMessageW, GetSystemMetrics, GetWindowTextW,
    HMENU, IDC_ARROW, IsDialogMessageW, LoadCursorW, MSG, PostMessageW, PostQuitMessage, RegisterClassW, SM_CXSCREEN,
    SM_CYSCREEN, SW_SHOW, SendMessageW, SetForegroundWindow, SetWindowTextW, ShowWindow, TranslateMessage,
    WINDOW_EX_STYLE, WINDOW_STYLE, WM_APP, WM_CLOSE, WM_COMMAND, WM_DESTROY, WM_SETFONT, WNDCLASSW, WS_BORDER,
    WS_CAPTION, WS_CHILD, WS_EX_CLIENTEDGE, WS_EX_CONTROLPARENT, WS_EX_DLGMODALFRAME, WS_EX_TOPMOST, WS_SYSMENU,
    WS_TABSTOP, WS_VISIBLE,
};
use windows::core::{HSTRING, PCWSTR, w};

const ID_OK: usize = 1;
const ID_CANCEL: usize = 2;
const WM_PAIR_DONE: u32 = WM_APP + 1;
// Edit and button styles (CommCtrl.h / WinUser.h).
const ES_AUTOHSCROLL: u32 = 0x0080;
const ES_NUMBER: u32 = 0x2000;
const BS_DEFPUSHBUTTON: u32 = 0x0001;
const EM_LIMITTEXT: u32 = 0x00C5;
const CLASS: PCWSTR = w!("VenPosAgentPairDialog");

type Pending = Arc<Mutex<Option<std::io::Result<Response>>>>;

struct Dialog {
    hwnd: HWND,
    server: HWND,
    code: HWND,
    status: HWND,
    buttons: [HWND; 2],
    ipc_name: String,
    pending: Pending,
    busy: bool,
    connected: Option<String>,
    done: bool,
}

thread_local! {
    static DIALOG: RefCell<Option<Dialog>> = const { RefCell::new(None) };
}

/// Shows the dialog and blocks (with its own message loop) until it closes.
/// Returns the success message when the agent was paired.
pub fn run(ipc_name: &str, server_prefill: &str) -> Option<String> {
    if DIALOG.with(|d| d.borrow().is_some()) {
        return None;
    }
    let hwnd = create(server_prefill, ipc_name)?;
    let mut msg = MSG::default();
    loop {
        if DIALOG.with(|d| d.borrow().as_ref().is_none_or(|d| d.done)) {
            break;
        }
        // SAFETY: standard message loop on the thread that owns the window.
        let got = unsafe { GetMessageW(&mut msg, None, 0, 0) };
        if got.0 == 0 {
            // WM_QUIT: hand it back to the tray loop.
            // SAFETY: plain call.
            unsafe { PostQuitMessage(i32::try_from(msg.wParam.0).unwrap_or(0)) };
            break;
        }
        if got.0 < 0 {
            break;
        }
        // SAFETY: `msg` was filled by GetMessageW; `hwnd` is our live window.
        unsafe {
            if !IsDialogMessageW(hwnd, &msg).as_bool() {
                let _ = TranslateMessage(&msg);
                DispatchMessageW(&msg);
            }
        }
    }
    DIALOG.with(|d| d.borrow_mut().take()).and_then(|d| d.connected)
}

fn create(server_prefill: &str, ipc_name: &str) -> Option<HWND> {
    // SAFETY: Win32 window creation on this thread; every string outlives the
    // call that uses it and the class name is a static wide literal.
    unsafe {
        let instance = GetModuleHandleW(None).ok()?;
        let class = WNDCLASSW {
            lpfnWndProc: Some(wndproc),
            hInstance: instance.into(),
            hCursor: LoadCursorW(None, IDC_ARROW).unwrap_or_default(),
            // COLOR_* + 1 is the documented way to pass a system colour as a brush.
            hbrBackground: HBRUSH((COLOR_BTNFACE.0 + 1) as usize as *mut core::ffi::c_void),
            lpszClassName: CLASS,
            ..Default::default()
        };
        // A second registration fails harmlessly with "class already exists".
        RegisterClassW(&class);
        let (w, h) = (380, 270);
        let x = (GetSystemMetrics(SM_CXSCREEN) - w) / 2;
        let y = (GetSystemMetrics(SM_CYSCREEN) - h) / 2;
        let hwnd = CreateWindowExW(
            WS_EX_DLGMODALFRAME | WS_EX_TOPMOST | WS_EX_CONTROLPARENT,
            CLASS,
            &HSTRING::from("Conectar con el TPV"),
            WS_CAPTION | WS_SYSMENU,
            x.max(0),
            y.max(0),
            w,
            h,
            None,
            None,
            Some(instance.into()),
            None,
        )
        .ok()?;
        let font = GetStockObject(DEFAULT_GUI_FONT);
        let child = |class: PCWSTR, text: &str, style: u32, ex: WINDOW_EX_STYLE, r: [i32; 4], id: usize| {
            let c = CreateWindowExW(
                ex,
                class,
                &HSTRING::from(text),
                WS_CHILD | WS_VISIBLE | WINDOW_STYLE(style),
                r[0],
                r[1],
                r[2],
                r[3],
                Some(hwnd),
                Some(HMENU(id as *mut core::ffi::c_void)),
                Some(instance.into()),
                None,
            )
            .unwrap_or_default();
            SendMessageW(c, WM_SETFONT, Some(WPARAM(font.0 as usize)), Some(LPARAM(1)));
            c
        };
        let none = WINDOW_EX_STYLE(0);
        child(
            w!("STATIC"),
            "En el TPV, abre Ajustes → Cajas y genera el código de emparejado de esta caja (caduca a los 10 minutos).",
            0,
            none,
            [12, 10, 340, 32],
            0,
        );
        child(w!("STATIC"), "Dirección del TPV:", 0, none, [12, 50, 340, 16], 0);
        let server = child(
            w!("EDIT"),
            server_prefill,
            WS_TABSTOP.0 | WS_BORDER.0 | ES_AUTOHSCROLL,
            WS_EX_CLIENTEDGE,
            [12, 68, 340, 22],
            10,
        );
        child(w!("STATIC"), "Código de 6 cifras:", 0, none, [12, 100, 340, 16], 0);
        let code = child(
            w!("EDIT"),
            "",
            WS_TABSTOP.0 | WS_BORDER.0 | ES_NUMBER,
            WS_EX_CLIENTEDGE,
            [12, 118, 90, 22],
            11,
        );
        SendMessageW(code, EM_LIMITTEXT, Some(WPARAM(6)), Some(LPARAM(0)));
        let status = child(w!("STATIC"), "", 0, none, [12, 150, 340, 40], 0);
        let ok = child(
            w!("BUTTON"),
            "Conectar",
            WS_TABSTOP.0 | BS_DEFPUSHBUTTON,
            none,
            [180, 198, 82, 26],
            ID_OK,
        );
        let cancel = child(
            w!("BUTTON"),
            "Cancelar",
            WS_TABSTOP.0,
            none,
            [270, 198, 82, 26],
            ID_CANCEL,
        );
        DIALOG.with(|d| {
            *d.borrow_mut() = Some(Dialog {
                hwnd,
                server,
                code,
                status,
                buttons: [ok, cancel],
                ipc_name: ipc_name.to_owned(),
                pending: Arc::new(Mutex::new(None)),
                busy: false,
                connected: None,
                done: false,
            });
        });
        let _ = ShowWindow(hwnd, SW_SHOW);
        let _ = SetForegroundWindow(hwnd);
        let _ = SetFocus(Some(if server_prefill.len() > "https://".len() {
            code
        } else {
            server
        }));
        Some(hwnd)
    }
}

fn text_of(hwnd: HWND) -> String {
    let mut buf = [0u16; 512];
    // SAFETY: `buf` is a valid, writable buffer of the length passed.
    let n = unsafe { GetWindowTextW(hwnd, &mut buf) };
    String::from_utf16_lossy(&buf[..usize::try_from(n).unwrap_or(0).min(buf.len())])
}

fn set_text(hwnd: HWND, text: &str) {
    // SAFETY: the HSTRING lives for the call.
    let _ = unsafe { SetWindowTextW(hwnd, &HSTRING::from(text)) };
}

/// Copies what a handler needs out of the dialog without holding the borrow
/// across Win32 calls (which may re-enter `wndproc`).
fn with_dialog<T>(f: impl FnOnce(&mut Dialog) -> T) -> Option<T> {
    DIALOG.with(|d| d.try_borrow_mut().ok()?.as_mut().map(f))
}

fn set_busy(controls: &[HWND], busy: bool) {
    for c in controls {
        // SAFETY: child windows of our live dialog.
        let _ = unsafe { EnableWindow(*c, !busy) };
    }
}

fn on_connect() {
    let Some((hwnd, server, code, status, controls, busy)) = with_dialog(|d| {
        (
            d.hwnd,
            d.server,
            d.code,
            d.status,
            [d.server, d.code, d.buttons[0], d.buttons[1]],
            d.busy,
        )
    }) else {
        return;
    };
    if busy {
        return;
    }
    let req = match model::pair_request(&text_of(server), &text_of(code)) {
        Ok(r) => r,
        Err(msg) => {
            set_text(status, &msg);
            return;
        }
    };
    let Some((ipc_name, pending)) = with_dialog(|d| {
        d.busy = true;
        (d.ipc_name.clone(), Arc::clone(&d.pending))
    }) else {
        return;
    };
    set_busy(&controls, true);
    set_text(status, "Conectando con el TPV…");
    let target = hwnd.0 as usize;
    std::thread::spawn(move || {
        let result = ipc::request(&ipc_name, &req);
        *pending.lock().unwrap_or_else(|e| e.into_inner()) = Some(result);
        // SAFETY: posting to a window handle is safe even if it was destroyed
        // meanwhile (the call then fails); the handle is only used as a value.
        let _ = unsafe {
            PostMessageW(
                Some(HWND(target as *mut core::ffi::c_void)),
                WM_PAIR_DONE,
                WPARAM(0),
                LPARAM(0),
            )
        };
    });
}

fn on_done() {
    let Some((hwnd, status, controls, result)) = with_dialog(|d| {
        d.busy = false;
        let r = d.pending.lock().unwrap_or_else(|e| e.into_inner()).take();
        (d.hwnd, d.status, [d.server, d.code, d.buttons[0], d.buttons[1]], r)
    }) else {
        return;
    };
    let Some(result) = result else { return };
    match model::pair_outcome(result) {
        PairOutcome::Connected(msg) => {
            let _ = with_dialog(|d| d.connected = Some(msg));
            // SAFETY: our live window.
            let _ = unsafe { DestroyWindow(hwnd) };
        }
        PairOutcome::Failed(msg) => {
            set_busy(&controls, false);
            set_text(status, &msg);
        }
    }
}

fn close() {
    let Some((hwnd, busy)) = with_dialog(|d| (d.hwnd, d.busy)) else {
        return;
    };
    // While the service talks to the TPV the window stays: closing it would
    // hide whether the code was used.
    if !busy {
        // SAFETY: our live window.
        let _ = unsafe { DestroyWindow(hwnd) };
    }
}

extern "system" fn wndproc(hwnd: HWND, msg: u32, wparam: WPARAM, lparam: LPARAM) -> LRESULT {
    match msg {
        WM_COMMAND => match wparam.0 & 0xFFFF {
            ID_OK => on_connect(),
            ID_CANCEL => close(),
            _ => {}
        },
        WM_PAIR_DONE => on_done(),
        WM_CLOSE => close(),
        WM_DESTROY => {
            let _ = with_dialog(|d| d.done = true);
        }
        // SAFETY: default handling for every other message.
        _ => return unsafe { DefWindowProcW(hwnd, msg, wparam, lparam) },
    }
    LRESULT(0)
}
