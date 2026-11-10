const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Ajustes: closing the window saves what was changed and not saved yet; a failed save
// keeps it open on that section with its error. Nothing changed: it just closes.
const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function ref(value) { return { value }; }

const TILL = {
    id: 99, name: 'Caja 1', ticket_letter: 'D', dataphone_mode: 'integrated', printer_type: 'pdf', ticket_width_px: 302,
    store_name: 'Nada Más', legal_name: '', tax_id: '', store_address: '', ticket_header: '', ticket_footer: 'Gracias', ticket_logo: null,
    refund_window_days: 30, ticket_code: 'barcode',
};

function load({ failTerminal = false } = {}) {
    const sent = [];
    const emitted = [];
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: {
            get: async (url) => (url === '/api/settings/email'
                ? { data: { host: 'smtp.x', port: 587, encryption: 'tls', username: 'u', from_email: 'a@x.es', from_name: 'Tienda', has_password: true, configured: true, backup_alert_email: '' } }
                : { data: [] }),
            put: async (url, body) => { sent.push({ url, body }); return { data: body }; },
            post: async (url, body) => { sent.push({ url, body }); return { data: {} }; },
            terminalGet: async () => ({ data: JSON.parse(JSON.stringify(TILL)) }),
            terminalUpdate: async (id, body) => {
                sent.push({ url: 'terminal/' + id, body });
                if (failTerminal) throw new Error('HTTP 422: {"error":"El nombre de la tienda es obligatorio"}');
                return {};
            },
        },
        useSession: { session: { terminalId: 99, stockSyncIntervalSeconds: 60, stockPushIntervalSeconds: 0 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout: () => 0, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const state = context.__modal.setup({}, { emit: (name) => emitted.push(name) });
    return { state, sent, emitted };
}

test('nothing changed: closing saves nothing and closes', async () => {
    const { state, sent, emitted } = load();
    await state.loadTerminal();

    await state.closeSettings();

    assert.deepEqual(sent, []);
    assert.deepEqual(emitted, ['close']);
});

test('a changed shop field is saved on close, and only the shop', async () => {
    const { state, sent, emitted } = load();
    await state.loadTerminal();
    state.terminal.value.ticket_footer = 'Cambios o devoluciones con ticket en 30 días.';

    await state.closeSettings();

    assert.equal(sent.length, 1);
    assert.equal(sent[0].url, 'terminal/99');
    assert.equal(sent[0].body.ticket_footer, 'Cambios o devoluciones con ticket en 30 días.');
    assert.equal('printer_type' in sent[0].body, false, 'the till settings were not touched');
    assert.deepEqual(emitted, ['close']);
});

test('a changed till, sync interval, email and employee are each saved on close', async () => {
    const { state, sent, emitted } = load();
    await state.loadTerminal();
    await state.loadMail();
    state.terminal.value.printer_type = 'agent';
    state.syncInterval.value = 120;
    state.mail.from_name = 'Club Natación';
    state.startEdit({ id: 7, name: 'ana', full_name: '', code: '', role: 'cashier', active: true });
    state.editForm.full_name = 'Ana García';

    await state.closeSettings();

    const urls = sent.map((s) => s.url);
    assert.ok(sent.some((s) => s.url === 'terminal/99' && s.body.printer_type === 'agent'), 'till');
    assert.ok(sent.some((s) => s.url === 'terminal/99' && s.body.stock_sync_interval_seconds === 120), 'sync');
    assert.ok(urls.includes('/api/settings/email'), 'email');
    assert.ok(sent.some((s) => s.url === '/api/employees/7' && s.body.full_name === 'Ana García'), 'employee');
    assert.deepEqual(emitted, ['close']);
});

test('a save that fails keeps the window open on its section with the error', async () => {
    const { state, emitted } = load({ failTerminal: true });
    await state.loadTerminal();
    state.activeSection.value = 'empleados';
    state.terminal.value.store_name = '';

    await state.closeSettings();

    assert.deepEqual(emitted, [], 'not closed: the change would be lost');
    assert.equal(state.activeSection.value, 'tienda');
    assert.match(state.terminalError.value, /obligatorio/);
});

test('a saved section is not saved again on close', async () => {
    const { state, sent, emitted } = load();
    await state.loadTerminal();
    state.terminal.value.ticket_header = '692.324.838';
    await state.saveShop();

    await state.closeSettings();

    assert.equal(sent.length, 1, 'only the «Guardar» click');
    assert.deepEqual(emitted, ['close']);
});

test('the window closes only with its ✕, which saves first', () => {
    assert.doesNotMatch(source, /@click\.self/);
    assert.match(source, /<button @click="closeSettings"[^>]*:disabled="closing"/);
});
