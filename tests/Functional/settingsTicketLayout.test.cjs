const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Ajustes → Cajas: left and right ticket margins (mm) and the logo width (% of the width).
const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function ref(value) { return { value }; }

function load() {
    const sent = [];
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: { get: async () => ({ data: [] }), terminalGet: async () => ({ data: {} }), terminalUpdate: async (id, body) => { sent.push(body); return {}; } },
        useSession: { session: { terminalId: 99 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const state = context.__modal.setup({}, { emit() {} });
    return { state, sent };
}

const till = (extra) => ({ id: 1, name: 'Caja 1', dataphone_mode: 'integrated', printer_type: 'pdf', ticket_width_px: 302, ...extra });

test('the margins and the logo width are saved with the till, empty ones as the default', async () => {
    const { state, sent } = load();
    state.terminal.value = till({ ticket_margin_left_mm: '3.5', ticket_margin_right_mm: '', ticket_logo_width_pct: 60 });

    await state.saveTill();

    assert.equal(sent.length, 1);
    assert.equal(sent[0].ticket_margin_left_mm, 3.5);
    assert.equal(sent[0].ticket_margin_right_mm, null);
    assert.equal(sent[0].ticket_logo_width_pct, 60);
    assert.equal(sent[0].ticket_width_px, 302, 'the other till settings are still sent');
});

test('a till loaded without them (migration not applied yet) saves as before', async () => {
    const { state, sent } = load();
    state.terminal.value = till({});

    await state.saveTill();

    assert.deepEqual(Object.keys(sent[0]).sort(), ['dataphone_mode', 'name', 'printer_type', 'ticket_width_px']);
});

test('the form shows the three fields under the ticket width with their help', () => {
    const width = source.indexOf('Ancho del ticket (px)');
    const left = source.indexOf('Margen izquierdo (mm)');
    assert.ok(width > 0 && left > width, 'below «Ancho del ticket (px)»');
    assert.ok(source.indexOf('Margen derecho (mm)') > left);
    assert.ok(source.indexOf('Tamaño del logo (% del ancho)') > left);
    assert.ok(source.includes('Vacío: 2 mm de margen y logo a todo el ancho.'));
});
