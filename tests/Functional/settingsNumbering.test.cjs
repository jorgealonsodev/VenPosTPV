const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
const vm = require('node:vm');

function ref(value) { return { value }; }

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');
const TEMPLATE = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');

function load(api) {
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: Object.assign({ get: async () => ({ data: [] }), put: async () => ({ data: {} }), terminalGet: async () => ({ data: { id: 7, name: 'Caja 1' } }), terminalUpdate: async () => ({}) }, api),
        useSession: { session: { terminalId: 7 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout, clearTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return context.__modal.setup({}, { emit() {} });
}

const OVERVIEW = {
    year: 2026,
    tills: [{ terminal_id: 7, name: 'Caja 1', letter: 'A', series: 'A26', last_number: 123, next_number: 124, next_code: 'A26000124' }],
    credit_notes: { series: 'R2026', last_number: 12, next_number: 13, next_code: 'R2026-000013' },
    changes: [{ id: 1, series: 'A26', previous_last: 0, new_last: 123, reason: 'Base restaurada', user_id: 1, user_name: 'Ana', created_at: '2026-11-05 10:00:00' }],
};

function cajasSection() {
    const match = SOURCE.match(/<div v-if="activeSection === 'cajas'">([\s\S]*?)<!-- ==================== HISTORIAL DE IMPRESIÓN/);
    assert.ok(match, 'Cajas section found');
    return match[1];
}

function testTheCajasSectionShowsTheNumberingControls() {
    const section = cajasSection();
    assert.ok(section.includes('Próximo número de ticket:'), 'next ticket number in the till form');
    assert.ok(section.includes('Abonos: próximo número'), 'credit-note block');
    assert.ok(section.includes('Cambios de numeración'), 'audit list');
    for (const column of ['Fecha', 'Serie', 'De → a', 'Motivo', 'Usuario']) {
        assert.ok(section.includes(`>${column}</th>`), `column ${column}`);
    }
    assert.ok(section.includes('Solo puede avanzar. Úsalo tras restaurar o reinstalar para continuar la numeración donde se quedó; queda registrado.'), 'help text');
    assert.ok(section.includes(">Cambiar</button>"), 'Cambiar button');
    assert.ok(section.includes("@click=\"startNumberingChange('ticket')\"") && section.includes("@click=\"startNumberingChange('credit')\""), 'both series can be changed');
    assert.ok(section.includes('v-model="numbering.next"') && section.includes('v-model="numbering.reason"'), 'number and motivo inputs');
}

async function testTheTicketSeriesOfTheEditedTillIsChanged() {
    const puts = [];
    let gets = 0;
    const s = load({
        get: async (url) => { if (url === '/api/numbering') { gets += 1; return { data: OVERVIEW }; } return { data: [] }; },
        put: async (url, body) => { puts.push({ url, body }); return { data: {} }; },
    });
    await s.loadNumbering();
    await s.editTill({ id: 7 });
    assert.equal(s.tillNumbering(7).next_code, 'A26000124');

    s.startNumberingChange('ticket');
    assert.equal(s.numbering.editing, 'ticket');
    assert.equal(s.numbering.next, '124', 'starts from the current next number');
    s.numbering.next = '500';
    s.numbering.reason = '  Reinstalación  ';
    assert.equal(await s.saveNumberingChange(), true);
    assert.equal(JSON.stringify(puts), JSON.stringify([{ url: '/api/numbering/tickets/7', body: { next_number: 500, reason: 'Reinstalación' } }]));
    assert.equal(s.numbering.editing, null, 'the inputs close');
    assert.ok(gets >= 3, 'the numbering is reloaded after the change');
}

async function testTheCreditNoteSeriesIsChangedAndTheServerErrorIsShown() {
    const s = load({
        get: async () => ({ data: OVERVIEW }),
        put: async (url) => { throw new Error('HTTP 422: ' + JSON.stringify({ error: 'El próximo número debe ser mayor que el último emitido (12)' })); },
    });
    await s.loadNumbering();
    s.startNumberingChange('credit');
    assert.equal(s.numbering.next, '13');
    s.numbering.next = '5';
    s.numbering.reason = 'Restauración';
    assert.equal(await s.saveNumberingChange(), false);
    assert.equal(s.numbering.error, 'El próximo número debe ser mayor que el último emitido (12)');
    assert.equal(s.numbering.editing, 'credit', 'stays open to correct it');

    let sent = null;
    const ok = load({ get: async () => ({ data: OVERVIEW }), put: async (url, body) => { sent = { url, body }; return { data: {} }; } });
    await ok.loadNumbering();
    ok.startNumberingChange('credit');
    ok.numbering.next = '20';
    ok.numbering.reason = 'Otra tienda';
    await ok.saveNumberingChange();
    assert.equal(JSON.stringify(sent), JSON.stringify({ url: '/api/numbering/credit-notes', body: { next_number: 20, reason: 'Otra tienda' } }));
}

function testTheScriptVersionWasBumped() {
    assert.ok(hasCacheVersionSince(TEMPLATE, 'components/SettingsModal.js', '20261105'), 'SettingsModal.js has a 20261105- or later version');
    assert.doesNotMatch(TEMPLATE, /SettingsModal\.js\?v=20261105-backup-alert-v1/, 'browsers load the new SettingsModal.js');
}

(async () => {
    testTheCajasSectionShowsTheNumberingControls();
    await testTheTicketSeriesOfTheEditedTillIsChanged();
    await testTheCreditNoteSeriesIsChangedAndTheServerErrorIsShown();
    testTheScriptVersionWasBumped();
    console.log('settingsNumbering tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
