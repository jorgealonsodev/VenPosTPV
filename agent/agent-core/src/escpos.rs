//! ESC/POS bytes the agent generates itself (T8: the drawer job carries no data).

/// `ESC p m t1 t2` with m = 0 (pin 2), t1 = 25 × 2 ms, t2 = 250 × 2 ms.
pub fn drawer_pulse() -> Vec<u8> {
    vec![0x1B, 0x70, 0x00, 0x19, 0xFA]
}

/// A short test page: initialise, code page 16 (WPC1252, as the tickets),
/// a few lines, feed and partial cut.
pub fn test_page(target: &str, hostname: &str, when: &str) -> Vec<u8> {
    let mut out = vec![0x1B, 0x40, 0x1B, 0x74, 16];
    let text = format!(
        "VenPos Agent {}\nPrueba de impresión ({target})\nEquipo: {hostname}\n{when}\n",
        crate::VERSION
    );
    out.extend(encode_cp1252(&text));
    out.extend([0x1B, 0x64, 4, 0x1D, 0x56, 66, 0]);
    out
}

/// Encode text as Windows-1252 for the characters the agent prints itself.
/// U+00A0..=U+00FF map one to one; `€` is 0x80; anything else becomes `?`.
pub fn encode_cp1252(text: &str) -> Vec<u8> {
    text.chars()
        .map(|c| match c {
            '€' => 0x80,
            c if (c as u32) < 0x80 => c as u8,
            c if (0xA0..=0xFF).contains(&(c as u32)) => c as u8,
            _ => b'?',
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn drawer_pulse_is_exact_esc_p_bytes() {
        assert_eq!(drawer_pulse(), vec![0x1B, 0x70, 0x00, 0x19, 0xFA]);
    }

    #[test]
    fn test_page_uses_code_page_16_and_encodes_accents() {
        let page = test_page("ticket", "PC-CAJA1", "2026-11-03 10:00");
        assert_eq!(&page[..5], &[0x1B, 0x40, 0x1B, 0x74, 16]);
        assert!(page.windows(1).any(|w| w == [0xF3]), "ó must be 0xF3 in WPC1252");
        assert_eq!(&page[page.len() - 4..], &[0x1D, 0x56, 66, 0]);
        assert_eq!(encode_cp1252("€"), vec![0x80]);
    }
}
