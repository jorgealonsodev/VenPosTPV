//! Windows spooler, RAW datatype (A5): the bytes reach the printer exactly
//! as received. A v4 driver may reject RAW; use a v3 driver or
//! "Generic / Text Only". Also DPAPI for the key (E10).
use super::{InstalledPrinter, PrintError, PrinterState, QueueStatus};
use crate::config::Destination;
use windows::Win32::Foundation::{ERROR_INVALID_PRINTER_NAME, HLOCAL, LocalFree};
use windows::Win32::Graphics::Printing::{
    ClosePrinter, DOC_INFO_1W, EndDocPrinter, EndPagePrinter, EnumPrintersW, GetPrinterW, JOB_CONTROL_DELETE,
    OpenPrinterW, PRINTER_ENUM_CONNECTIONS, PRINTER_ENUM_LOCAL, PRINTER_HANDLE, PRINTER_INFO_2W, PRINTER_INFO_4W,
    SetJobW, StartDocPrinterW, StartPagePrinter, WritePrinter,
};
use windows::core::{PCWSTR, PWSTR};

fn wide(s: &str) -> Vec<u16> {
    s.encode_utf16().chain(std::iter::once(0)).collect()
}

fn last_error(what: &str) -> String {
    format!("{what}: {}", windows::core::Error::from_thread().message())
}

/// Closes the printer handle on every path.
struct Handle(PRINTER_HANDLE);

impl Drop for Handle {
    fn drop(&mut self) {
        // SAFETY: the handle came from a successful OpenPrinterW and is closed once.
        let _ = unsafe { ClosePrinter(self.0) };
    }
}

fn open(name: &str) -> Result<Handle, PrintError> {
    let w = wide(name);
    let mut h = PRINTER_HANDLE::default();
    // SAFETY: `w` is NUL-terminated and outlives the call; `h` is a valid out pointer.
    match unsafe { OpenPrinterW(PCWSTR(w.as_ptr()), &mut h, None) } {
        Ok(()) => Ok(Handle(h)),
        Err(e) if e.code() == ERROR_INVALID_PRINTER_NAME.to_hresult() => Err(PrintError::new(
            "printer_not_found",
            format!("Impresora no encontrada: {name}"),
        )),
        Err(e) => Err(PrintError::new(
            "printer_open_failed",
            format!("{name}: {}", e.message()),
        )),
    }
}

/// Sends one RAW document; returns the spooler job id.
pub fn print(name: &str, title: &str, data: &[u8]) -> Result<u32, PrintError> {
    let h = open(name)?;
    let mut doc = wide(title);
    let mut datatype = wide("RAW");
    let info = DOC_INFO_1W {
        pDocName: PWSTR(doc.as_mut_ptr()),
        pOutputFile: PWSTR::null(),
        pDatatype: PWSTR(datatype.as_mut_ptr()),
    };
    // SAFETY: `info` points to NUL-terminated buffers alive for the call.
    let job = unsafe { StartDocPrinterW(h.0, 1, &info) };
    if job == 0 {
        return Err(PrintError::new(
            "spooler_rejected",
            last_error("StartDocPrinter (¿driver v4 sin RAW?)"),
        ));
    }
    // SAFETY: valid open handle with a started document.
    if !unsafe { StartPagePrinter(h.0) }.as_bool() {
        let msg = last_error("StartPagePrinter");
        // SAFETY: as above.
        let _ = unsafe { EndDocPrinter(h.0) };
        return Err(PrintError::new("spooler_rejected", msg));
    }
    let mut result = Ok(job);
    for chunk in data.chunks(64 * 1024) {
        let len = u32::try_from(chunk.len()).unwrap_or(u32::MAX);
        let mut written = 0u32;
        // SAFETY: `chunk` is valid for `len` bytes; `written` is a valid out pointer.
        let ok = unsafe { WritePrinter(h.0, chunk.as_ptr().cast(), len, &mut written) }.as_bool();
        if !ok || written != len {
            result = Err(PrintError::new("spooler_write_failed", last_error("WritePrinter")));
            break;
        }
    }
    // SAFETY: page and document were started on this handle.
    unsafe {
        let _ = EndPagePrinter(h.0);
        let _ = EndDocPrinter(h.0);
    }
    result
}

pub fn status(name: &str) -> QueueStatus {
    let h = match open(name) {
        Ok(h) => h,
        Err(e) if e.code == "printer_not_found" => {
            return QueueStatus::new(
                PrinterState::NotFound,
                "Impresora no encontrada: elígela de nuevo desde el icono",
            );
        }
        Err(e) => return QueueStatus::new(PrinterState::Error, e.message),
    };
    let mut needed = 0u32;
    // SAFETY: size query with no buffer; `needed` is a valid out pointer.
    let _ = unsafe { GetPrinterW(h.0, 2, None, &mut needed) };
    let bytes = usize::try_from(needed).unwrap_or(0);
    if bytes < std::mem::size_of::<PRINTER_INFO_2W>() {
        return QueueStatus::new(PrinterState::Unknown, last_error("GetPrinter"));
    }
    // u64 storage keeps PRINTER_INFO_2W suitably aligned.
    let mut storage = vec![0u64; bytes.div_ceil(8)];
    // SAFETY: the u8 view covers exactly the u64 allocation.
    let buf = unsafe { std::slice::from_raw_parts_mut(storage.as_mut_ptr().cast::<u8>(), storage.len() * 8) };
    // SAFETY: `buf` is at least `needed` bytes.
    if unsafe { GetPrinterW(h.0, 2, Some(buf), &mut needed) }.is_err() {
        return QueueStatus::new(PrinterState::Unknown, last_error("GetPrinter"));
    }
    // SAFETY: GetPrinterW level 2 filled an aligned PRINTER_INFO_2W at the start.
    let info = unsafe { &*storage.as_ptr().cast::<PRINTER_INFO_2W>() };
    super::winstatus::map_status(info.Status, info.Attributes)
}

pub fn cancel(name: &str, job: u32) {
    if let Ok(h) = open(name) {
        // SAFETY: valid handle; deleting a finished job just fails.
        let _ = unsafe { SetJobW(h.0, job, 0, None, JOB_CONTROL_DELETE) };
    }
}

pub fn list() -> Vec<InstalledPrinter> {
    let flags = PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS;
    let (mut needed, mut count) = (0u32, 0u32);
    // SAFETY: size query; out pointers are valid.
    let _ = unsafe { EnumPrintersW(flags, PCWSTR::null(), 4, None, &mut needed, &mut count) };
    let bytes = usize::try_from(needed).unwrap_or(0);
    if bytes == 0 {
        return Vec::new();
    }
    let mut storage = vec![0u64; bytes.div_ceil(8)];
    // SAFETY: the u8 view covers exactly the u64 allocation.
    let buf = unsafe { std::slice::from_raw_parts_mut(storage.as_mut_ptr().cast::<u8>(), storage.len() * 8) };
    // SAFETY: `buf` is at least `needed` bytes.
    if unsafe { EnumPrintersW(flags, PCWSTR::null(), 4, Some(buf), &mut needed, &mut count) }.is_err() {
        return Vec::new();
    }
    let n = usize::try_from(count).unwrap_or(0);
    // SAFETY: EnumPrintersW wrote `count` PRINTER_INFO_4W records at the aligned start.
    let infos = unsafe { std::slice::from_raw_parts(storage.as_ptr().cast::<PRINTER_INFO_4W>(), n) };
    infos
        .iter()
        .filter_map(|i| {
            // SAFETY: pPrinterName points into `storage`, NUL-terminated.
            let name = unsafe { i.pPrinterName.to_string() }.ok()?;
            Some(InstalledPrinter {
                destination: Destination::Spooler { printer: name },
                detail: "Windows".into(),
            })
        })
        .collect()
}

pub mod dpapi {
    //! DPAPI, machine scope (`CRYPTPROTECT_LOCAL_MACHINE`): the blob only
    //! decrypts on this computer; the file ACL limits who can read it.
    use super::{HLOCAL, LocalFree};
    use windows::Win32::Security::Cryptography::{
        CRYPT_INTEGER_BLOB, CRYPTPROTECT_LOCAL_MACHINE, CRYPTPROTECT_UI_FORBIDDEN, CryptProtectData, CryptUnprotectData,
    };

    fn take(out: CRYPT_INTEGER_BLOB) -> Vec<u8> {
        let len = usize::try_from(out.cbData).unwrap_or(0);
        // SAFETY: DPAPI returned `cbData` bytes at `pbData`, freed right after copying.
        let v = unsafe { std::slice::from_raw_parts(out.pbData, len) }.to_vec();
        // SAFETY: the buffer was allocated by DPAPI with LocalAlloc.
        unsafe { LocalFree(Some(HLOCAL(out.pbData.cast()))) };
        v
    }

    pub fn protect(plain: &[u8]) -> Result<Vec<u8>, String> {
        let input = CRYPT_INTEGER_BLOB {
            cbData: u32::try_from(plain.len()).map_err(|_| "clave demasiado larga".to_owned())?,
            pbData: plain.as_ptr().cast_mut(),
        };
        let mut out = CRYPT_INTEGER_BLOB::default();
        // SAFETY: input points to `plain` (read only); `out` is a valid out pointer.
        unsafe {
            CryptProtectData(
                &input,
                windows::core::w!("VenPos Agent"),
                None,
                None,
                None,
                CRYPTPROTECT_LOCAL_MACHINE | CRYPTPROTECT_UI_FORBIDDEN,
                &mut out,
            )
        }
        .map_err(|e| e.message())?;
        Ok(take(out))
    }

    pub fn unprotect(blob: &[u8]) -> Result<Vec<u8>, String> {
        let input = CRYPT_INTEGER_BLOB {
            cbData: u32::try_from(blob.len()).map_err(|_| "fichero de clave demasiado grande".to_owned())?,
            pbData: blob.as_ptr().cast_mut(),
        };
        let mut out = CRYPT_INTEGER_BLOB::default();
        // SAFETY: as in `protect`.
        unsafe { CryptUnprotectData(&input, None, None, None, None, CRYPTPROTECT_UI_FORBIDDEN, &mut out) }
            .map_err(|e| e.message())?;
        Ok(take(out))
    }
}
