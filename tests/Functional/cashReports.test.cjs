/**
 * Informe X and Informe Z from the till: «Imprimir informe X» prints the open till
 * without closing it; closing the till prints its Z (or opens its PDF when the till
 * has no printer) and a printing problem never undoes the close; Ajustes →
 * «Cierres de caja» lists the closes with «Imprimir» and «PDF».
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = (file) => fs.readFileSync(path.resolve(__dirname, '../../public/assets/js', file), 'utf8');

function loadSession(api, tracked = []) {
    const storage = new Map([['venpostpv_session', JSON.stringify({ screen: 'sale', sessionId: 41, openingCash: 50 })]]);
    const context = {
        window: { __SESSION__: { user_id: 1, terminal_id: 7 }, TPV_BASE: '', addEventListener() {}, location: {} },
        navigator: { onLine: true },
        localStorage: { getItem: (k) => storage.get(k) || null, setItem: (k, v) => storage.set(k, v) },
        document: { documentElement: { setAttribute() {} } },
        Vue: { reactive: (v) => v },
        ApiClient: {
            cashCurrent: () => new Promise(() => {}),
            syncStatus: () => Promise.resolve({ state: 'ok' }),
            post: () => Promise.resolve({}),
            ...api,
        },
        usePrintJobs: { trackResponse: (data, ctx) => { tracked.push([data, ctx]); return true; } },
        setTimeout() { return 1; }, clearTimeout() {}, setInterval() { return 1; }, clearInterval() {},
        console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${read('stores/useSession.js')}\nglobalThis.__store = useSession;`, context, { filename: 'useSession.js' });
    return context.__store;
}

const closed = { data: { session_id: 41, closed_at: '2026-11-05 21:00:00', expected_cash: 85, counted_cash: 90, difference: 5 } };

test('closing prints the Z of that session on the agent and follows its job', async () => {
    const calls = [];
    const tracked = [];
    const store = loadSession({
        cashClose: async (counted) => { calls.push(['close', counted]); return closed; },
        cashZPrint: async (id) => { calls.push(['zprint', id]); return { data: { print_status: 'queued', print_job_id: 5 } }; },
        cashZPdf: (id) => calls.push(['zpdf', id]),
    }, tracked);

    const result = await store.closeCash('90.00');

    assert.deepEqual(calls, [['close', '90.00'], ['zprint', 41]]);
    assert.equal(JSON.stringify(tracked), JSON.stringify([[{ print_status: 'queued', print_job_id: 5 }, { kind: 'cash_report' }]]));
    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(store.session.sessionId, null);
    assert.equal(result.z_print, 'queued');
    assert.equal(result.session_id, 41);
    assert.equal(result.difference, 5);
});

test('a till without printer opens the Z PDF', async () => {
    const calls = [];
    const store = loadSession({
        cashClose: async () => closed,
        cashZPrint: async () => ({ data: { fallback: 'pdf' } }),
        cashZPdf: (id) => calls.push(['zpdf', id]),
    });

    const result = await store.closeCash('90.00');

    assert.deepEqual(calls, [['zpdf', 41]]);
    assert.equal(result.z_print, 'pdf');
    assert.equal(store.session.screen, 'cashOpen');
});

test('a printing failure never undoes the close', async () => {
    const store = loadSession({
        cashClose: async () => closed,
        cashZPrint: async () => { throw new Error('HTTP 500: {"error":"No se ha podido imprimir el informe"}'); },
        cashZPdf: () => { throw new Error('must not open'); },
    });

    const result = await store.closeCash('90.00');

    assert.equal(result.z_print, 'failed');
    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(store.session.sessionId, null);
    assert.equal(store.session.openingCash, 0);
});

test('a close answered without data still reaches the open-cash screen and prints nothing', async () => {
    let printed = 0;
    const store = loadSession({ cashClose: async () => ({}), cashZPrint: async () => { printed += 1; return {}; } });

    await store.closeCash('10.00');

    assert.equal(printed, 0);
    assert.equal(store.session.screen, 'cashOpen');
});

test('a refused close keeps the till open and prints nothing', async () => {
    let printed = 0;
    const store = loadSession({ cashClose: async () => { throw new Error('HTTP 409: No open cash session'); }, cashZPrint: async () => { printed += 1; return {}; } });
    store.session.screen = 'sale';

    await assert.rejects(() => store.closeCash('10.00'), /HTTP 409/);

    assert.equal(printed, 0);
    assert.equal(store.session.screen, 'sale');
});

test('the X report prints the open till or opens its PDF', async () => {
    const calls = [];
    let answer = { data: { print_status: 'printed' } };
    const store = loadSession({
        cashReportXPrint: async () => { calls.push('xprint'); return answer; },
        cashReportXPdf: () => calls.push('xpdf'),
    });
    store.session.screen = 'sale';

    assert.equal(await store.printCashReport('x'), 'printed');
    answer = { data: { fallback: 'pdf' } };
    assert.equal(await store.printCashReport('x'), 'pdf');
    assert.deepEqual(calls, ['xprint', 'xprint', 'xpdf']);
    assert.equal(store.session.screen, 'sale', 'the X report never closes the till');
});

test('the api calls the report routes and never queues a print offline', async () => {
    const fetched = [];
    const opened = [];
    const context = {
        window: { TPV_BASE: '/tpv', addEventListener() {}, location: {}, open: (url, target) => opened.push([url, target]) },
        navigator: { onLine: true },
        fetch: async (url, opts) => { fetched.push([url, opts.method]); return { ok: true, status: 200, json: async () => ({ data: {} }) }; },
        Promise, Error, JSON, URLSearchParams, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${read('api.js')}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });
    const api = context.__api;

    await api.cashReportXPrint();
    await api.cashZPrint(41);
    await api.cashZList();
    api.cashReportXPdf();
    api.cashZPdf(41);

    assert.deepEqual(fetched, [['/tpv/api/cash/report-x/print', 'POST'], ['/tpv/api/cash/z/41/print', 'POST'], ['/tpv/api/cash/z', 'GET']]);
    assert.deepEqual(opened, [['/tpv/api/cash/report-x/pdf', '_blank'], ['/tpv/api/cash/z/41/pdf', '_blank']]);

    context.navigator.onLine = false;
    const offline = { window: { ...context.window, addEventListener() {} }, navigator: { onLine: false }, fetch: context.fetch, Promise, Error, JSON, URLSearchParams, console };
    offline.globalThis = offline;
    vm.runInNewContext(`${read('api.js')}\nglobalThis.__api = ApiClient;`, offline, { filename: 'api.js' });
    await assert.rejects(() => offline.__api.cashZPrint(41), /not sent or queued/);
    await assert.rejects(() => offline.__api.cashReportXPrint(), /not sent or queued/);
});

test('«Imprimir informe X» is wired and tells what happened', async () => {
    const source = read('components/CashCloseScreen.js');
    assert.match(source, /<button @click="printX" :disabled="printingX"/);
    const outcomes = ['queued', 'printed', 'pdf'];
    const context = {
        Vue: { reactive: (v) => v, ref: (value) => ({ value }), computed: (fn) => ({ get value() { return fn(); } }) },
        useSession: { printCashReport: async (kind) => { assert.equal(kind, 'x'); const o = outcomes.shift(); if (!o) throw new Error('HTTP 409: {"error":"No hay ninguna caja abierta en este equipo"}'); return o; } },
        Number, String, Math, JSON, Promise, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__screen = CashCloseScreen;`, context, { filename: 'CashCloseScreen.js' });
    const state = context.__screen.setup({ session: {}, parkedCount: 0, outbox: null }, { emit() {} });

    const messages = [];
    for (let i = 0; i < 4; i += 1) {
        await state.printX();
        messages.push(state.xStatus.value);
    }

    assert.deepEqual(messages, [
        'Imprimiendo informe X…',
        'Informe X impreso',
        'Informe X descargado en PDF',
        'No se ha podido imprimir el informe X: No hay ninguna caja abierta en este equipo',
    ]);
    assert.equal(state.printingX.value, false);
});

test('Ajustes → «Cierres de caja» lists the closes and prints or downloads each Z', async () => {
    const source = read('components/SettingsModal.js');
    assert.match(source, /activeSection = 'cierres'; loadCashCloses\(\)/);
    assert.match(source, /Cierres de caja/);
    for (const header of ['Fecha de cierre', 'Cajero', 'Contado', 'Esperado', 'Diferencia']) assert.ok(source.includes(`>${header}</th>`), header);
    assert.match(source, /@click="printClose\(c\.session_id\)"/);
    assert.match(source, /@click="pdfClose\(c\.session_id\)"/);

    const calls = [];
    const rows = [{ session_id: 41, closed_at: '2026-11-05 21:00:00', cashier: 'Ana', counted_cash: 90, expected_cash: 85, difference: 5 }];
    const context = {
        Vue: { ref: (value) => ({ value }), reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: {
            get: async () => ({ data: [] }),
            terminalGet: async () => ({ data: {} }),
            cashZList: async () => { calls.push('list'); return { data: rows }; },
            cashZPdf: (id) => calls.push(['pdf', id]),
        },
        useSession: { session: { terminalId: 1 }, printCashReport: async (kind, id) => { calls.push([kind, id]); return 'queued'; } },
        setTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const state = context.__modal.setup({}, { emit() {} });

    await state.loadCashCloses();
    assert.deepEqual(state.cashCloses.list, rows);
    await state.printClose(41);
    state.pdfClose(41);

    assert.deepEqual(calls, ['list', ['z', 41], ['pdf', 41]]);
    assert.equal(state.success.value, 'Imprimiendo informe Z…');
    assert.equal(state.formatMoney(1234.5), '1.234,50 €');
    assert.equal(state.formatMoney(-2.56, true), '-2,56 €');
    assert.equal(state.formatMoney(5, true), '+5,00 €');
});

test('the changed scripts have a new cache version', () => {
    const twig = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');
    for (const file of ['api.js', 'stores/useSession.js', 'components/CashCloseScreen.js', 'components/SettingsModal.js', 'app.js']) {
        assert.ok(twig.includes(`/assets/js/${file}?v=20261105-cash-reports-v1`), file);
    }
});
