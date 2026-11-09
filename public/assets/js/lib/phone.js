/**
 * VenPosPhone — the one place that turns a typed phone number into the digits of a
 * WhatsApp link (wa.me). Spaces, dots, dashes and parentheses are ignored; a leading
 * + or 00 keeps the country code; 9 digits starting with 6, 7, 8 or 9 are a Spanish
 * number (+34); anything else needs 8 to 15 digits including its country code.
 */
const VenPosPhone = (() => {
    function normalize(raw) {
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

    function whatsappUrl(digits, message) {
        return 'https://wa.me/' + digits + '?text=' + encodeURIComponent(String(message || ''));
    }

    return { normalize, whatsappUrl };
})();
