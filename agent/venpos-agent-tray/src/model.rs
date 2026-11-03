//! Platform-independent parts of the tray (A4): icon pixels and texts.
#![cfg_attr(not(windows), allow(dead_code))]

use agent_core::printer::PrinterState;
use agent_core::state::{AgentStatus, Connection, Light};

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
}
