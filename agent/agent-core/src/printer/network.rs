//! Raw TCP printing (`IP:9100`).
use super::PrintError;
use std::io::Write;
use std::net::{SocketAddr, TcpStream, ToSocketAddrs};
use std::time::Duration;

pub const DEFAULT_PORT: u16 = 9100;

/// `host` → `host:9100`; `host:port` unchanged.
pub fn with_default_port(address: &str) -> String {
    let has_port = if let Some(rest) = address.strip_prefix('[') {
        rest.contains("]:")
    } else {
        address.matches(':').count() == 1
    };
    if has_port {
        address.to_owned()
    } else {
        format!("{address}:{DEFAULT_PORT}")
    }
}

pub fn print(address: &str, data: &[u8]) -> Result<(), PrintError> {
    let target = with_default_port(address);
    let addrs: Vec<SocketAddr> = target
        .to_socket_addrs()
        .map_err(|e| PrintError::new("printer_not_found", format!("{target}: {e}")))?
        .collect();
    let mut last = None;
    for addr in addrs {
        match TcpStream::connect_timeout(&addr, Duration::from_secs(5)) {
            Ok(mut s) => {
                s.set_write_timeout(Some(Duration::from_secs(30)))
                    .map_err(|e| PrintError::new("network_write_failed", e.to_string()))?;
                s.write_all(data)
                    .and_then(|()| s.flush())
                    .map_err(|e| PrintError::new("network_write_failed", format!("{target}: {e}")))?;
                let _ = s.shutdown(std::net::Shutdown::Write);
                return Ok(());
            }
            Err(e) => last = Some(e),
        }
    }
    Err(PrintError::new(
        "printer_unreachable",
        format!(
            "{target}: {}",
            last.map(|e| e.to_string()).unwrap_or_else(|| "sin dirección".into())
        ),
    ))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Read;

    #[test]
    fn default_port_is_9100() {
        assert_eq!(with_default_port("10.0.0.5"), "10.0.0.5:9100");
        assert_eq!(with_default_port("10.0.0.5:9101"), "10.0.0.5:9101");
        assert_eq!(with_default_port("[::1]"), "[::1]:9100");
        assert_eq!(with_default_port("[::1]:9"), "[::1]:9");
    }

    #[test]
    fn bytes_arrive_unchanged() {
        let l = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let addr = l.local_addr().unwrap().to_string();
        let h = std::thread::spawn(move || {
            let (mut s, _) = l.accept().unwrap();
            let mut buf = Vec::new();
            s.read_to_end(&mut buf).unwrap();
            buf
        });
        print(&addr, &[0x1B, 0x70, 0x00, 0x19, 0xFA]).unwrap();
        assert_eq!(h.join().unwrap(), vec![0x1B, 0x70, 0x00, 0x19, 0xFA]);
    }
}
