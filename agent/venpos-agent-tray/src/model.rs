//! Platform-independent parts of the tray (A4): icon pixels and texts.
#![cfg_attr(not(any(windows, target_os = "linux")), allow(dead_code))]

use agent_core::ipc::{Request, Response};
use agent_core::printer::PrinterState;
use agent_core::state::{AgentStatus, Connection, Light};

#[cfg_attr(not(windows), allow(dead_code))]
pub const ICON_SIZE: u32 = 32;

fn rgb(light: Light) -> [u8; 3] {
    match light {
        Light::Green => [0x2E, 0xA0, 0x43],
        Light::Amber => [0xF0, 0xA0, 0x20],
        Light::Red => [0xD0, 0x30, 0x30],
    }
}

/// RGBA of a filled disc with a darker rim; transparent outside.
pub fn icon_rgba(light: Light, size: u32) -> Vec<u8> {
    let [r, g, b] = rgb(light);
    let n = usize::try_from(size).unwrap_or(0);
    let mut out = Vec::with_capacity(n * n * 4);
    let c = f64::from(size) / 2.0;
    let radius = c - 1.0;
    for y in 0..size {
        for x in 0..size {
            let dx = f64::from(x) + 0.5 - c;
            let dy = f64::from(y) + 0.5 - c;
            let d = (dx * dx + dy * dy).sqrt();
            if d > radius {
                out.extend([0, 0, 0, 0]);
            } else if d > radius - 2.0 {
                out.extend([r / 2, g / 2, b / 2, 255]);
            } else {
                out.extend([r, g, b, 255]);
            }
        }
    }
    out
}

/// Colour shown; a stopped service cannot print, so amber.
pub fn light_for(status: Option<&AgentStatus>) -> Light {
    status.map_or(Light::Amber, AgentStatus::light)
}

fn state_text(s: PrinterState) -> &'static str {
    match s {
        PrinterState::Ready => "lista",
        PrinterState::Paused => "en pausa",
        PrinterState::Offline => "desconectada",
        PrinterState::Error => "con error",
        PrinterState::NotFound => "no encontrada: elígela de nuevo",
        PrinterState::NotConfigured => "sin elegir",
        PrinterState::Unknown => "",
    }
}

/// First line of the menu and tooltip.
pub fn status_line(status: Option<&AgentStatus>) -> String {
    let Some(s) = status else {
        return "VenPos: el servicio no está en marcha".into();
    };
    if let Some(p) = s.printers.values().find(|p| p.status.state.is_problem()) {
        let detail = if p.status.detail.is_empty() {
            state_text(p.status.state).to_owned()
        } else {
            p.status.detail.clone()
        };
        return format!("Impresora {}: {detail}", p.name);
    }
    match s.connection {
        Connection::Connected => format!("Conectado: {}", s.terminal_name.as_deref().unwrap_or("TPV")),
        Connection::Offline | Connection::Starting => "Sin conexión con el TPV".into(),
        Connection::NotPaired => "Sin emparejar".into(),
        Connection::KeyRejected => agent_core::MSG_KEY_REJECTED.into(),
        Connection::UpdateRequired => "Hay que actualizar el agente".into(),
    }
}

/// "Abrir Ajustes del TPV": only an already validated https (or loopback) URL.
pub fn settings_url(server: Option<&str>) -> Option<String> {
    agent_core::config::validate_server_url(server?).ok()
}

pub const MSG_SERVICE_DOWN: &str = "El servicio VenPos Agent no está en marcha.";

/// "Dirección del TPV" starts with the paired server, else `https://`.
pub fn pair_server_prefill(server: Option<&str>) -> String {
    server.unwrap_or("https://").to_owned()
}

/// Checks the dialog fields before asking the service (which checks again).
pub fn pair_request(server: &str, code: &str) -> Result<Request, String> {
    use agent_core::pairing::{PairError, strict_code};
    let server = server.trim();
    if server.is_empty() || server.eq_ignore_ascii_case("https://") {
        return Err("Escribe la dirección del TPV.".into());
    }
    let server = agent_core::config::validate_server_url(server).map_err(|e| PairError::InvalidUrl(e).to_string())?;
    let code = strict_code(code.trim()).map_err(|e| e.to_string())?;
    Ok(Request::Pair {
        server,
        code: code.expose().to_owned(),
    })
}

/// What the dialog does with the service's answer.
#[derive(Debug, PartialEq, Eq)]
pub enum PairOutcome {
    /// Close the dialog, show this and refresh the status.
    Connected(String),
    /// Keep the dialog open with this message.
    Failed(String),
}

pub fn pair_outcome(result: std::io::Result<Response>) -> PairOutcome {
    match result {
        Ok(r) if r.ok => PairOutcome::Connected(format!(
            "Conectado a la caja {}",
            r.terminal_name.as_deref().unwrap_or("del TPV")
        )),
        Ok(r) => PairOutcome::Failed(r.error.unwrap_or_else(|| "No se pudo conectar con el TPV.".into())),
        Err(_) => PairOutcome::Failed(MSG_SERVICE_DOWN.into()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use agent_core::config::Target;
    use agent_core::printer::QueueStatus;
    use agent_core::state::PrinterReport;

    #[test]
    fn icons_are_coloured_discs() {
        for (light, rgb) in [
            (Light::Green, [0x2E, 0xA0, 0x43]),
            (Light::Amber, [0xF0, 0xA0, 0x20]),
            (Light::Red, [0xD0, 0x30, 0x30]),
        ] {
            let px = icon_rgba(light, ICON_SIZE);
            assert_eq!(px.len(), 32 * 32 * 4);
            let centre = ((16 * 32 + 16) * 4) as usize;
            assert_eq!(&px[centre..centre + 4], &[rgb[0], rgb[1], rgb[2], 255]);
            assert_eq!(px[3], 0, "corner is transparent");
        }
    }

    #[test]
    fn texts() {
        assert_eq!(status_line(None), "VenPos: el servicio no está en marcha");
        assert_eq!(light_for(None), Light::Amber);
        let mut s = AgentStatus {
            connection: Connection::Connected,
            terminal_name: Some("Caja 1".into()),
            ..Default::default()
        };
        assert_eq!(status_line(Some(&s)), "Conectado: Caja 1");
        assert_eq!(light_for(Some(&s)), Light::Green);
        s.printers.insert(
            "ticket".into(),
            PrinterReport {
                target: Target::Ticket,
                name: "EPSON".into(),
                status: QueueStatus::new(PrinterState::NotFound, ""),
            },
        );
        assert_eq!(
            status_line(Some(&s)),
            "Impresora EPSON: no encontrada: elígela de nuevo"
        );
        assert_eq!(light_for(Some(&s)), Light::Red);
        s.printers.clear();
        s.connection = Connection::KeyRejected;
        assert_eq!(
            status_line(Some(&s)),
            "Vuelve a conectar el agente desde Ajustes → Cajas"
        );
    }

    #[test]
    fn settings_url_only_for_safe_urls() {
        assert_eq!(
            settings_url(Some("https://tpv.example.com/")).as_deref(),
            Some("https://tpv.example.com")
        );
        assert_eq!(settings_url(Some("http://evil.example.com")), None);
        assert_eq!(settings_url(Some("file:///C:/x")), None);
        assert_eq!(settings_url(None), None);
    }

    #[test]
    fn pair_dialog_prefills_the_paired_server() {
        assert_eq!(
            pair_server_prefill(Some("https://tpv.example.com")),
            "https://tpv.example.com"
        );
        assert_eq!(pair_server_prefill(None), "https://");
    }

    #[test]
    fn pair_dialog_checks_its_fields() {
        assert_eq!(
            pair_request(" https://tpv.example.com/ ", "482913"),
            Ok(Request::Pair {
                server: "https://tpv.example.com".into(),
                code: "482913".into()
            })
        );
        for code in ["", "48291", "4829134", "48291a", "48 913"] {
            assert_eq!(
                pair_request("https://tpv.example.com", code),
                Err("El código tiene 6 cifras.".into()),
                "{code:?}"
            );
        }
        assert_eq!(
            pair_request("  ", "482913"),
            Err("Escribe la dirección del TPV.".into())
        );
        assert_eq!(
            pair_request("https://", "482913"),
            Err("Escribe la dirección del TPV.".into())
        );
        for url in ["http://evil.example.com", "tpv.example.com", "file:///C:/x"] {
            assert_eq!(
                pair_request(url, "482913"),
                Err("La dirección del TPV no es válida: tiene que empezar por https://".into()),
                "{url}"
            );
        }
    }

    #[test]
    fn pair_dialog_messages() {
        let ok = Response {
            ok: true,
            terminal_name: Some("Caja 3".into()),
            ..Default::default()
        };
        assert_eq!(
            pair_outcome(Ok(ok)),
            PairOutcome::Connected("Conectado a la caja Caja 3".into())
        );
        let rejected = Response {
            ok: false,
            error: Some("Código caducado o incorrecto. Genera uno nuevo en Ajustes → Cajas.".into()),
            ..Default::default()
        };
        assert_eq!(
            pair_outcome(Ok(rejected)),
            PairOutcome::Failed("Código caducado o incorrecto. Genera uno nuevo en Ajustes → Cajas.".into())
        );
        assert_eq!(
            pair_outcome(Ok(Response::default())),
            PairOutcome::Failed("No se pudo conectar con el TPV.".into())
        );
        assert_eq!(
            pair_outcome(Err(std::io::Error::other("pipe"))),
            PairOutcome::Failed(MSG_SERVICE_DOWN.into())
        );
    }
}
