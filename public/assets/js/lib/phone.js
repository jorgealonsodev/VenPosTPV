/**
 * VenPosPhone — the one place that turns a typed phone number into the digits of a
 * WhatsApp link (wa.me). Spaces, dots, dashes and parentheses are ignored; a leading
 * + or 00 keeps the country code; 9 digits starting with 6, 7, 8 or 9 are a Spanish
 * number (+34); anything else needs 8 to 15 digits including its country code.
 *
 * A phone from the shop may hold two numbers or a note («615658290 / 944223344»,
 * «+34 615 65 82 90 (móvil)»): its first usable number is taken, a mobile first.
 * display() shows the digits with their country code, for the field.
 */
const VenPosPhone = (() => {
    function single(raw) {
        let text = String(raw == null ? '' : raw).replace(/[\s.()-]/g, '');
        let international = false;
        if (text.startsWith('+')) {
            text = text.slice(1);
            international = true;
        } else if (text.startsWith('00')) {
            text = text.slice(2);
            international = true;
        }
        if (!/^\d+$/.test(text)) return null;
        if (!international && /^[6789]\d{8}$/.test(text)) return '34' + text;
        return /^\d{8,15}$/.test(text) ? text : null;
    }

    function isMobile(digits) {
        return /^34[67]\d{8}$/.test(digits) || !digits.startsWith('34');
    }

    function normalize(raw) {
        const whole = single(raw);
        if (whole !== null) return whole;
        // Several numbers or a note in brackets: each part on its own, notes dropped.
        const parts = String(raw == null ? '' : raw)
            .split(/\s*(?:[\/,;|]|\sy\s|\so\s)\s*/)
            .map((part) => part.replace(/\([^)]*[A-Za-zÁÉÍÓÚÜÑáéíóúüñ][^)]*\)/g, ''))
            .map(single)
            .filter((digits) => digits !== null);
        if (parts.length === 0) return null;
        return parts.find(isMobile) || parts[0];
    }

    function display(digits) {
        const d = String(digits == null ? '' : digits);
        if (d === '') return '';
        const spanish = /^34(\d{3})(\d{2})(\d{2})(\d{2})$/.exec(d);
        return spanish ? '+34 ' + spanish.slice(1).join(' ') : '+' + d;
    }

    function whatsappUrl(digits, message) {
        return 'https://wa.me/' + digits + '?text=' + encodeURIComponent(String(message || ''));
    }

    return { normalize, display, whatsappUrl };
})();
