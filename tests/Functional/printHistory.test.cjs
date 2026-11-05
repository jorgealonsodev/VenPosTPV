/**
 * docs/prd/agente-impresion.md S4, AC13: «Historial de impresión» in Ajustes, by
 * till and state, newest first, with cursor pagination (GET /api/print-jobs).
 */
process.env.TZ = 'Europe/Madrid';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

const row = (id, fields = {}) => ({
    id, terminal_id: 1, terminal_name: 'Caja 1', type: 'ticket', target: 'ticket', source: 'sale', status: 'done', attempts: 1,
    error_code: null, error: null, created_at: '2026-11-03T17:05:00+01:00', claimed_at: null, finished_at: null, expires_at: '2026-11-03T17:07:00+01:00', ...fields,
});

function load(pages) {
    const root = path.resolve(__dirname, '../../public/assets/js');
    const clock = makeClock();
    const asked = [];
    const hooks = { mounted: [] };
    const context = {
        ...sandboxGlobals(clock),
        Vue: { ref, computed, reactive: (v) => v, onMounted: (fn) => hooks.mounted.push(fn), watch() {} },
        ApiClient: {
            printJobs: async (params) => { asked.push({ ...params }); return pages.shift() || { items: [], next_cursor: null }; },
        },
    };
    context.globalThis = context;
    vm.runInNewContext(fs.readFileSync(path.join(root, 'stores/usePrintJobs.js'), 'utf8'), context, { filename: 'usePrintJobs.js' });
    vm.runInNewContext(fs.readFileSync(path.join(root, 'components/PrintHistoryPanel.js'), 'utf8') + '\nglobalThis.__panel = PrintHistoryPanel;', context, { filename: 'PrintHistoryPanel.js' });
    const state = context.__panel.setup({ tills: [{ id: 1, name: 'Caja 1' }, { id: 2, name: 'Caja 2' }] });
    hooks.mounted.forEach((fn) => fn());
    return { state, clock, asked };
}

async function testRowsShowDateTillTypeStateAndError() {
    const { state, clock, asked } = load([{ items: [
        row(9),
        row(8, { type: 'drawer', status: 'expired', terminal_name: 'Caja 2' }),
        row(7, { type: 'label', status: 'failed', error_code: 'paper_out' }),
        row(6, { status: 'pending' }),
        row(5, { status: 'cancelled', error_code: 'weird', error: 'Algo raro' }),
    ], next_cursor: null }]);
    await clock.advance(1);
    assert.deepEqual(asked, [{ limit: 25 }], 'all tills and states by default');
    assert.deepEqual(state.rows.value.map((r) => [r.date, r.till, r.type, r.status, r.error]), [
        ['03/11/2026 17:05', 'Caja 1', 'Ticket', 'Enviado', ''],
        ['03/11/2026 17:05', 'Caja 2', 'Cajón', 'Caducado', ''],
        ['03/11/2026 17:05', 'Caja 1', 'Etiqueta', 'Fallido', 'sin papel'],
        ['03/11/2026 17:05', 'Caja 1', 'Ticket', 'Pendiente', ''],
        ['03/11/2026 17:05', 'Caja 1', 'Ticket', 'Cancelado', 'Algo raro'],
    ]);
    assert.equal(state.hasMore.value, false);
}

async function testFiltersByTillAndState() {
    const { state, clock, asked } = load([{ items: [row(9)], next_cursor: null }, { items: [row(3, { status: 'failed' })], next_cursor: null }]);
    await clock.advance(1);
    state.terminalFilter.value = '2';
    state.statusFilter.value = 'failed';
    await state.reload();
    assert.deepEqual(asked[1], { terminal_id: '2', status: 'failed', limit: 25 });
    assert.deepEqual(state.rows.value.map((r) => r.id), [3], 'the list starts again with the filter');
    assert.deepEqual([...state.statusOptions].map((o) => o.label), ['Todos', 'Pendiente', 'Enviado', 'Fallido', 'Caducado', 'Cancelado']);
}

async function testPagesGoForwardAndBack() {
    const { state, clock, asked } = load([
        { items: [row(9), row(8)], next_cursor: 8 },
        { items: [row(7)], next_cursor: null },
        { items: [row(9), row(8)], next_cursor: 8 },
    ]);
    await clock.advance(1);
    assert.equal(state.page.value, 1);
    assert.equal(state.hasPrev.value, false);
    assert.equal(state.hasMore.value, true);

    await state.nextPage();
    assert.deepEqual(asked[1], { cursor: 8, limit: 25 });
    assert.deepEqual(state.rows.value.map((r) => r.id), [7], 'a page replaces the rows, it does not add them');
    assert.equal(state.page.value, 2);
    assert.equal(state.hasMore.value, false);
    assert.equal(state.hasPrev.value, true);

    await state.prevPage();
    assert.deepEqual(asked[2], { limit: 25 }, 'page 1 again, from the start');
    assert.deepEqual(state.rows.value.map((r) => r.id), [9, 8]);
    assert.equal(state.page.value, 1);
}

function testTheTableScrollsInsideItsOwnBox() {
    const css = fs.readFileSync(path.resolve(__dirname, '../../public/assets/css/app.css'), 'utf8');
    const rule = css.match(/\.print-history-scroll \{([^}]*)\}/);
    assert.ok(rule && /overflow-y:\s*auto/.test(rule[1]) && /max-height:/.test(rule[1]), 'its own vertical scroll');
    assert.match(css, /\.print-history-table th \{[^}]*position:\s*sticky/, 'the header stays visible');
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/PrintHistoryPanel.js'), 'utf8');
    assert.match(source, /@click="prevPage"/);
    assert.match(source, /@click="nextPage"/);
    assert.ok(!source.includes('Cargar más'));
}

(async () => {
    await testRowsShowDateTillTypeStateAndError();
    await testFiltersByTillAndState();
    await testPagesGoForwardAndBack();
    testTheTableScrollsInsideItsOwnBox();
    console.log('printHistory: ok');
})().catch((err) => { console.error(err); process.exit(1); });
