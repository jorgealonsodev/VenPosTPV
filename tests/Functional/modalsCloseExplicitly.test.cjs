const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// No window closes with a click outside it (or Escape): only with its own ✕,
// «Cancelar», «Cerrar» or «Terminar», so a stray click never loses what was typed.
const dir = path.resolve(__dirname, '../../public/assets/js/components');
const read = (file) => fs.readFileSync(path.join(dir, file), 'utf8');

test('no component closes on a click outside or on Escape', () => {
    for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.js'))) {
        const source = read(file);
        assert.doesNotMatch(source, /@click\.self=/, file);
        assert.doesNotMatch(source, /@keydown\.esc=/, file);
    }
});

test('every window keeps a button that closes it', () => {
    const closers = {
        'DiscountModal.js': /\$emit\('close'\)[^>]*>\s*Cancelar/,
        'ProductEditModal.js': /@click="\$emit\('close'\)"/,
        'CouponCreationModal.js': /@click="\$emit\('close'\)"/,
        'CustomerModal.js': /@click="\$emit\('close'\)"[^>]*aria-label="Cerrar"/,
        'LabelPrintModal.js': /@click="\$emit\('close'\)"/,
        'CombinationsModal.js': /@click="\$emit\('close'\)"/,
        'InvoiceModal.js': /@click="\$emit\('close'\)"/,
        'FreeItemModal.js': /@click="\$emit\('close'\)"/,
        'CouponListModal.js': /@click="\$emit\('close'\)"/,
        'OrderDetailView.js': /@click="pdfChoice = false"/,
        'IncidentsScreen.js': /@click="cancelDiscard"/,
        'PaymentModal.js': /class="pay-close"/,
        'SettingsModal.js': /@click="closeSettings"/,
    };
    for (const [file, closer] of Object.entries(closers)) {
        assert.match(read(file), closer, file);
    }
});
