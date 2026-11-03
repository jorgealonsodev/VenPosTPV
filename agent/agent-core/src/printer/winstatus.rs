//! Windows `PRINTER_INFO_2.Status`/`Attributes` → queue state. Pure, so it is
//! tested on Linux too.
use super::{PrinterState, QueueStatus};

pub const PRINTER_STATUS_PAUSED: u32 = 0x0000_0001;
pub const PRINTER_STATUS_ERROR: u32 = 0x0000_0002;
pub const PRINTER_STATUS_PAPER_JAM: u32 = 0x0000_0008;
pub const PRINTER_STATUS_PAPER_OUT: u32 = 0x0000_0010;
pub const PRINTER_STATUS_OFFLINE: u32 = 0x0000_0080;
pub const PRINTER_STATUS_NOT_AVAILABLE: u32 = 0x0000_1000;
pub const PRINTER_STATUS_DOOR_OPEN: u32 = 0x0040_0000;
pub const PRINTER_STATUS_USER_INTERVENTION: u32 = 0x0010_0000;
pub const PRINTER_ATTRIBUTE_WORK_OFFLINE: u32 = 0x0000_0400;

pub fn map_status(status: u32, attributes: u32) -> QueueStatus {
    if status & PRINTER_STATUS_PAUSED != 0 {
        return QueueStatus::new(PrinterState::Paused, "cola en pausa");
    }
    if status & (PRINTER_STATUS_OFFLINE | PRINTER_STATUS_NOT_AVAILABLE) != 0
        || attributes & PRINTER_ATTRIBUTE_WORK_OFFLINE != 0
    {
        return QueueStatus::new(PrinterState::Offline, "desconectada");
    }
    let detail = if status & PRINTER_STATUS_PAPER_OUT != 0 {
        "sin papel"
    } else if status & PRINTER_STATUS_DOOR_OPEN != 0 {
        "tapa abierta"
    } else if status & PRINTER_STATUS_PAPER_JAM != 0 {
        "atasco de papel"
    } else if status & (PRINTER_STATUS_ERROR | PRINTER_STATUS_USER_INTERVENTION) != 0 {
        "error"
    } else {
        return QueueStatus::new(PrinterState::Ready, "");
    };
    QueueStatus::new(PrinterState::Error, detail)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn windows_status_bits() {
        assert_eq!(map_status(0, 0).state, PrinterState::Ready);
        assert_eq!(map_status(PRINTER_STATUS_PAUSED, 0).state, PrinterState::Paused);
        assert_eq!(map_status(PRINTER_STATUS_OFFLINE, 0).state, PrinterState::Offline);
        assert_eq!(map_status(PRINTER_STATUS_NOT_AVAILABLE, 0).state, PrinterState::Offline);
        assert_eq!(
            map_status(0, PRINTER_ATTRIBUTE_WORK_OFFLINE).state,
            PrinterState::Offline
        );
        assert_eq!(map_status(PRINTER_STATUS_ERROR, 0).state, PrinterState::Error);
        let out = map_status(PRINTER_STATUS_PAPER_OUT, 0);
        assert_eq!(out.state, PrinterState::Error);
        assert_eq!(out.detail, "sin papel");
        assert_eq!(map_status(PRINTER_STATUS_DOOR_OPEN, 0).detail, "tapa abierta");
        // Paused wins over offline: the user can resume it from the queue.
        assert_eq!(
            map_status(PRINTER_STATUS_PAUSED | PRINTER_STATUS_OFFLINE, 0).state,
            PrinterState::Paused
        );
    }
}
