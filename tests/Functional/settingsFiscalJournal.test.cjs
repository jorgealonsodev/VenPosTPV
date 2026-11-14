const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Ajustes → VERI*FACTU → «Diario fiscal» (fiscal-journal D9): the journal state
// from GET /api/fiscal/journal, also summarised in Acerca de → «Estado de la instalación».

function ref(value) { return { value }; }

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function load(api) {
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: Object.assign({ get: async () => ({ data: [] }), terminalGet: async () => ({ data: {} }), terminalUpdate: async () => ({}) }, api),
        useSession: { session: { terminalId: 1 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout, clearTimeout, Promise, Number, String, Math, JSON, console, Array, Object, Date,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return context.__modal.setup({}, { emit() {} });
}

function state(status, extra) {
    return Object.assign({
        status,
        checked_at: '2026-11-14T18:03:00+01:00',
        last_document: { number: 'A26000124', invoice_type: 'F2', generated_at: '2026-11-14T18:02:10+01:00' },
        pending: { count: 0, oldest_number: null, oldest_at: null },
        damaged: null,
        unconfirmed_intents: [],
    }, extra || {});
}

async function rendered(payload) {
    const s = load({ get: async (url) => (url === '/api/fiscal/journal' ? { data: payload } : { data: {} }) });
    await s.loadFiscalJournal();
    return s;
}

test('the four journal states render from the payload with the texts of D9', async () => {
    let s = await rendered(state('ok'));
    assert.deepEqual({ ...s.fiscalJournalMessage(s.fiscalJournal.data) }, { tone: 'ok', text: 'Diario fiscal al día: último documento A26000124 (14/11/2026 18:02).' });

    s = await rendered(state('pending', { pending: { count: 3, oldest_number: 'A26000125', oldest_at: '2026-11-14T18:02:30+01:00' } }));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).text, 'Diario fiscal con 3 documentos pendientes de anotar; se anotan solos cada minuto.');

    s = await rendered(state('lagging', { pending: { count: 3, oldest_number: 'A26000125', oldest_at: '2026-11-14T17:50:00+01:00' } }));
    const lagging = s.fiscalJournalMessage(s.fiscalJournal.data);
    assert.equal(lagging.text, 'El diario fiscal lleva más de 5 minutos sin poder anotarse. Revisa los permisos de la carpeta backups/fiscal-journal.');
    assert.equal(lagging.tone, 'error');

    s = await rendered(state('damaged', { damaged: { folder: 'primary', file: 'journal-2026-11.jsonl', line: 812, reason: 'line' } }));
    const damaged = s.fiscalJournalMessage(s.fiscalJournal.data);
    assert.equal(damaged.text, 'El diario fiscal tiene una línea dañada en journal-2026-11.jsonl (línea 812). Ejecuta tpv:fiscal:journal --verify-all.');
    assert.equal(damaged.tone, 'error');
});

test('removed lines, a wrong head and a document that cannot be journaled each have their line', async () => {
    let s = await rendered(state('damaged', { damaged: { folder: 'primary', file: 'journal-2026-11.jsonl', line: 40, reason: 'removed' } }));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).text, 'Faltan líneas o archivos del diario fiscal (desde journal-2026-11.jsonl, línea 40). Ejecuta tpv:fiscal:journal --verify-all.');

    s = await rendered(state('damaged', { damaged: { folder: 'primary', file: 'head.json', line: null, reason: 'head' } }));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).text, 'El diario fiscal está dañado (head.json). Ejecuta tpv:fiscal:journal --verify-all.');

    s = await rendered(state('blocked', { blocked: { number: 'X-1', reason: 'Unknown fiscal document number X-1' }, pending: { count: 2, oldest_number: 'X-1', oldest_at: null } }));
    const blocked = s.fiscalJournalMessage(s.fiscalJournal.data);
    assert.equal(blocked.text, 'El diario fiscal no puede anotar el documento X-1 (Unknown fiscal document number X-1); los 2 documentos pendientes esperan en orden hasta que se resuelva.');
    assert.equal(blocked.tone, 'error');
});

test('a journal not created yet is neutral, never an error', async () => {
    const s = await rendered(state('not_started', { last_document: null }));
    assert.deepEqual({ ...s.fiscalJournalMessage(s.fiscalJournal.data) }, { tone: 'muted', text: 'Diario fiscal sin iniciar' });
    assert.equal(s.fiscalJournal.error, '');
    assert.equal(s.fiscalJournalTone(s.fiscalJournalMessage(s.fiscalJournal.data)), 'color:var(--text3);');
});

test('the states of later tasks show nothing here, and old unconfirmed documents get one line', async () => {
    const s = await rendered(state('head_missing'));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data), null);
    assert.equal(s.fiscalJournalUnconfirmed(state('ok')), '');
    assert.equal(
        s.fiscalJournalUnconfirmed(state('ok', { unconfirmed_intents: [{ number: 'A26000126', created_at: '2026-11-14T17:40:00+01:00' }] })),
        '1 documentos preparados hace más de 10 minutos sin confirmar; no bloquean la venta y se revisan solos cada minuto.'
    );
});

test('a load error is shown instead of the state', async () => {
    const s = load({ get: async (url) => { if (url === '/api/fiscal/journal') throw new Error('HTTP 403: {"error":"Acceso denegado — solo administradores"}'); return { data: {} }; } });
    await s.loadFiscalJournal();
    assert.equal(s.fiscalJournal.data, null);
    assert.equal(s.fiscalJournal.error, 'Acceso denegado — solo administradores');
});

test('opening VERI*FACTU and Acerca de loads the journal state', async () => {
    const urls = [];
    const s = load({ get: async (url) => { urls.push(url); return { data: url === '/api/fiscal/journal' ? state('ok') : {} }; } });
    await s.loadVerifactu();
    assert.ok(urls.includes('/api/fiscal/journal'));
    urls.length = 0;
    await s.loadProvisioning();
    assert.ok(urls.includes('/api/fiscal/journal'));
});

test('the template shows the block in VERI*FACTU and the summary in Estado de la instalación', () => {
    const verifactu = SOURCE.slice(SOURCE.indexOf(`<div v-if="activeSection === 'verifactu'">`), SOURCE.indexOf('<!-- ==================== CIERRES DE CAJA'));
    assert.ok(verifactu.includes('>Diario fiscal</div>'));
    assert.ok(verifactu.includes('data-fiscal-journal="state"'));
    assert.ok(verifactu.includes('{{ fiscalJournalMessage(fiscalJournal.data).text }}'));
    assert.ok(verifactu.includes('{{ fiscalJournal.error }}'));
    const about = SOURCE.slice(SOURCE.indexOf(`<div v-if="activeSection === 'acerca'">`));
    const summary = about.indexOf('data-fiscal-journal="summary"');
    assert.ok(summary > about.indexOf('Estado de la instalación') && summary < about.indexOf('Tienda PrestaShop'), 'inside «Estado de la instalación»');
});
