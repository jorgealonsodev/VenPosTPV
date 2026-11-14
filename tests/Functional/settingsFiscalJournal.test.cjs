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

test('a database behind the journal names what is missing and that the till cannot sell (S5)', async () => {
    const behind = (missing) => state('head_missing', { guard: { state: 'behind_journal', missing } });
    let s = await rendered(behind({ number: 'A26000003', invoice_type: 'F2', after: 2 }));
    let message = s.fiscalJournalMessage(s.fiscalJournal.data);
    assert.deepEqual({ ...message }, { tone: 'error', text: 'La base de datos está por detrás del diario fiscal (falta A26000003 y 2 posteriores). No se puede vender hasta recuperar los documentos.' });

    s = await rendered(behind({ number: 'A26000005', invoice_type: 'F2', after: 0 }));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).text, 'La base de datos está por detrás del diario fiscal (falta A26000005). No se puede vender hasta recuperar los documentos.');

    s = await rendered(behind({ number: 'A26000005', invoice_type: 'F2', after: null }));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).text, 'La base de datos está por detrás del diario fiscal (falta A26000005). No se puede vender hasta recuperar los documentos.');

    // The guard reads the database now: it wins over a damaged or up-to-date state of the last run.
    s = await rendered(state('damaged', { damaged: { folder: 'primary', file: 'head.json', line: null, reason: 'removed' }, guard: { state: 'behind_journal', missing: { number: 'A26000005', invoice_type: 'F2', after: null } } }));
    assert.match(s.fiscalJournalMessage(s.fiscalJournal.data).text, /^La base de datos está por detrás del diario fiscal/);

    s = await rendered(state('head_missing'));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).text, 'La base de datos está por detrás del diario fiscal. No se puede vender hasta recuperar los documentos.');

    s = await rendered(state('ok', { guard: { state: 'ok', missing: null } }));
    assert.equal(s.fiscalJournalMessage(s.fiscalJournal.data).tone, 'ok');
});

test('the states of later tasks show nothing here, and old unconfirmed documents get one line', async () => {
    const s = await rendered(state('journal_missing'));
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

// Ajustes «Recuperar documentos» (fiscal-journal S7, D8.5, D9): POST /api/fiscal/journal/replay,
// a dry-run list first, then the confirmation that recovers them.

const BEHIND = { state: 'behind_journal', missing: { number: 'A26000011', invoice_type: 'F2', after: 1 } };
const DOCUMENTS = [
    { seq: 11, kind: 'F2', number: 'A26000011', generated_at: '2026-11-14T18:02:10+01:00' },
    { seq: 12, kind: 'R5', number: 'R2026000001', generated_at: '2026-11-14T18:03:10+01:00' },
];

function replayHarness(answers) {
    const calls = { get: 0, post: [] };
    let guard = BEHIND;
    const s = load({
        get: async (url) => {
            if (url !== '/api/fiscal/journal') return { data: {} };
            calls.get++;
            return { data: state('head_missing', { guard }) };
        },
        post: async (url, body) => {
            calls.post.push({ url, body });
            const answer = answers.shift();
            if (answer instanceof Error) throw answer;
            if (answer && answer.data && answer.data.status === 'done') guard = { state: 'ok', missing: null };
            return answer;
        },
    });
    return { s, calls };
}

test('the button shows only while the database is behind the journal', async () => {
    let s = await rendered(state('head_missing', { guard: BEHIND }));
    assert.equal(s.fiscalJournalCanReplay(s.fiscalJournal.data), true);
    for (const payload of [state('ok', { guard: { state: 'ok', missing: null } }), state('damaged', { damaged: { file: 'journal-2026-11.jsonl', line: 3, reason: 'line' } }), state('not_started')]) {
        s = await rendered(payload);
        assert.equal(s.fiscalJournalCanReplay(s.fiscalJournal.data), false, payload.status);
    }
    assert.equal(s.fiscalJournalCanReplay(null), false);
});

test('«Recuperar documentos» lists first without writing, then the confirmation recovers them and reloads the state', async () => {
    const { s, calls } = replayHarness([
        { data: { status: 'pending', applied: false, documents: DOCUMENTS, message: null } },
        { data: { status: 'done', applied: true, documents: DOCUMENTS, message: null } },
    ]);
    await s.loadFiscalJournal();

    await s.previewReplay();
    assert.deepEqual(JSON.parse(JSON.stringify(calls.post)), [{ url: '/api/fiscal/journal/replay', body: { apply: false } }]);
    assert.deepEqual(s.fiscalReplay.documents.map((d) => d.number), ['A26000011', 'R2026000001']);
    assert.equal(s.fiscalReplay.confirming, true);
    assert.equal(s.fiscalReplay.error, '');

    await s.applyReplay();
    assert.deepEqual(JSON.parse(JSON.stringify(calls.post[1])), { url: '/api/fiscal/journal/replay', body: { apply: true } });
    assert.equal(s.fiscalReplay.done, 'Recuperados 2 documentos (A26000011 … R2026000001). La caja puede volver a emitir documentos.');
    assert.equal(s.fiscalReplay.confirming, false);
    assert.equal(s.fiscalReplay.documents, null);
    assert.equal(calls.get, 2, 'the journal state is read again');
    assert.equal(s.fiscalJournalCanReplay(s.fiscalJournal.data), false);
});

test('cancel discards the list and never applies', async () => {
    const { s, calls } = replayHarness([{ data: { status: 'pending', applied: false, documents: DOCUMENTS, message: null } }]);
    await s.loadFiscalJournal();
    await s.previewReplay();
    s.cancelReplay();
    assert.equal(s.fiscalReplay.confirming, false);
    assert.equal(s.fiscalReplay.documents, null);
    assert.equal(calls.post.length, 1);
});

test('a refused replay shows the server message and writes nothing', async () => {
    const tooOld = 'La copia restaurada es anterior al diario conservado (desde 01/11/2026). Restaura una copia más reciente.';
    const { s, calls } = replayHarness([new Error('HTTP 409: ' + JSON.stringify({ error: tooOld, code: 'replay_too_old' }))]);
    await s.loadFiscalJournal();
    await s.previewReplay();
    assert.equal(s.fiscalReplay.error, tooOld);
    assert.equal(s.fiscalReplay.confirming, false);
    assert.equal(s.fiscalReplay.busy, false);
    assert.equal(calls.post.length, 1);
});

test('a dry run that finds nothing says so instead of asking to confirm', async () => {
    const { s } = replayHarness([{ data: { status: 'nothing', applied: false, documents: [], message: 'Nada que recuperar: la base de datos tiene todos los documentos del diario fiscal.' } }]);
    await s.loadFiscalJournal();
    await s.previewReplay();
    assert.equal(s.fiscalReplay.confirming, false);
    assert.equal(s.fiscalReplay.done, 'Nada que recuperar: la base de datos tiene todos los documentos del diario fiscal.');
});

test('the template has the button, the list, what is not recovered and the confirmation', () => {
    const verifactu = SOURCE.slice(SOURCE.indexOf('Diario fiscal</div>'), SOURCE.indexOf('<!-- ==================== CIERRES DE CAJA'));
    assert.ok(verifactu.includes('v-if="fiscalJournalCanReplay(fiscalJournal.data) && !fiscalReplay.confirming"'));
    assert.ok(verifactu.includes('data-fiscal-journal="replay"'));
    assert.ok(verifactu.includes('Recuperar documentos'));
    assert.ok(verifactu.includes('data-fiscal-journal="replay-list"'));
    assert.ok(verifactu.includes('data-fiscal-journal="replay-confirm"'));
    assert.ok(verifactu.includes('No se recuperan los cierres Z ni el cierre de las sesiones de caja posteriores a la copia (vuelven abiertas: ciérralas de nuevo), el estado de impresión, el stock, los clientes nuevos, los tickets aparcados ni los movimientos de caja manuales. Las subidas a la tienda vuelven a la cola.'));
});
