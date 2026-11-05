// docs/prd/informes-ventas.md I2, I3, I5 and criterion 13: the «Informes» screen,
// run without a browser (ReportsScreen.js in a VM with a stubbed ApiClient and fetch).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const plain = (v) => JSON.parse(JSON.stringify(v));
const source = fs.readFileSync(path.join(ROOT, 'public/assets/js/components/ReportsScreen.js'), 'utf8');

const tills = [{ id: 1, name: 'Caja A' }, { id: 2, name: 'Caja B' }];

function summaryResponse(extra = {}) {
    const metrics = {
        gross_before_discounts: 110, discounts: 4, gross_sales: 106, refunds: 0, net_sales: 106, tickets: 3,
        average_ticket: 35.33, units_sold: 5, units_refunded: 0, units_net: 5, exchange_credit: 0, online_refunds: 0,
    };
    const comparison = {};
    Object.keys(metrics).forEach((k) => { comparison[k] = { difference: metrics[k], percent: null }; });
    return {
        data: {
            from: '2026-03-10', to: '2026-03-10', ...metrics,
            pending_upload: { sales: 0, refunds: 0 },
            previous: { from: '2026-03-09', to: '2026-03-09', ...Object.fromEntries(Object.keys(metrics).map((k) => [k, 0])) },
            comparison,
        },
        approximate_amount: 0, missing_lines: 0, ...extra,
    };
}

/**
 * Loads the screen. `answers` maps a report type to its JSON response (or a function
 * returning it / throwing); every GET is recorded in `calls`.
 */
function load({ answers = {}, today = new Date(2026, 2, 11, 10, 30) } = {}) {
    const calls = [];
    const downloads = [];
    const mounted = [];
    const ApiClient = {
        get: async (url) => {
            calls.push(url);
            if (url === '/api/device') return { data: { tills } };
            if (url === '/api/cash/z') return { data: [{ session_id: 3, terminal_name: 'Caja B', opened_at: '2026-03-12 09:00:00', closed_at: '2026-03-12 20:00:00' }] };
            const type = /^\/api\/reports\/([a-z]+)\?/.exec(url)[1];
            const answer = answers[type] !== undefined ? answers[type] : summaryResponse();
            return typeof answer === 'function' ? answer(url) : answer;
        },
    };
    const anchor = { click() { downloads.push({ href: this.href, download: this.download }); } };
    const context = {
        Vue: {
            ref: (value) => ({ value }), reactive: (v) => v,
            computed: (fn) => ({ get value() { return fn(); } }),
            onMounted: (fn) => mounted.push(fn), watch() {},
        },
        ApiClient,
        window: { TPV_BASE: '/tpv' },
        fetch: async (url, opts) => {
            downloads.push({ fetched: url, credentials: opts && opts.credentials });
            return { ok: true, status: 200, headers: { get: () => 'attachment; filename="informe-resumen-2026-03-11-2026-03-11.csv"' }, blob: async () => ({ size: 3 }) };
        },
        URL: { createObjectURL: () => 'blob:csv', revokeObjectURL() {} },
        document: { createElement: () => anchor, body: { appendChild() {}, removeChild() {} } },
        Date, Math, Number, String, JSON, Array, Object, Promise, console, encodeURIComponent, URLSearchParams, setTimeout,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__r = { ReportsLogic, ReportsScreen, REPORT_TABS };`, context, { filename: 'ReportsScreen.js' });
    const { ReportsLogic, ReportsScreen, REPORT_TABS } = context.__r;
    ReportsLogic.now = () => new Date(today.getTime());
    const state = ReportsScreen.setup({}, { emit() {} });
    return { ReportsLogic, REPORT_TABS, state, calls, downloads, mount: async () => { for (const fn of mounted) await fn(); } };
}

const reportCalls = (calls) => calls.filter((u) => u.startsWith('/api/reports/'));

test('date presets give the expected ranges for a fixed today (Wednesday 11/03/2026)', () => {
    const { ReportsLogic } = load();
    const today = new Date(2026, 2, 11);
    const range = (p) => plain(ReportsLogic.presetRange(p, today));
    assert.deepEqual(range('today'), { from: '2026-03-11', to: '2026-03-11' });
    assert.deepEqual(range('yesterday'), { from: '2026-03-10', to: '2026-03-10' });
    assert.deepEqual(range('week'), { from: '2026-03-09', to: '2026-03-15' }, 'Monday to Sunday');
    assert.deepEqual(range('month'), { from: '2026-03-01', to: '2026-03-31' });
    assert.deepEqual(range('previous_month'), { from: '2026-02-01', to: '2026-02-28' });
    assert.deepEqual(range('year'), { from: '2026-01-01', to: '2026-12-31' });
    // Sunday belongs to the week that started on the Monday before it.
    assert.deepEqual(plain(ReportsLogic.presetRange('week', new Date(2026, 2, 15))), { from: '2026-03-09', to: '2026-03-15' });
    // January: the previous month is December of the year before.
    assert.deepEqual(plain(ReportsLogic.presetRange('previous_month', new Date(2026, 0, 5))), { from: '2025-12-01', to: '2025-12-31' });
    assert.deepEqual(plain(ReportsLogic.PRESETS.map((p) => p.label)),
        ['Hoy', 'Ayer', 'Esta semana', 'Este mes', 'Mes anterior', 'Este año', 'Personalizado']);
});

test('a custom range is checked like the server does (max 366 days)', () => {
    const { ReportsLogic } = load();
    assert.equal(ReportsLogic.rangeError('2026-03-01', '2026-03-31'), '');
    assert.equal(ReportsLogic.rangeError('2025-03-01', '2026-03-01'), '', '366 days is allowed');
    assert.equal(ReportsLogic.rangeError('2025-03-01', '2026-03-02'), 'El rango máximo es de 366 días');
    assert.equal(ReportsLogic.rangeError('2026-03-02', '2026-03-01'), 'La fecha desde no puede ser posterior a la fecha hasta');
    assert.equal(ReportsLogic.rangeError('', '2026-03-01'), 'Indica las fechas desde y hasta (AAAA-MM-DD)');
});

test('amounts are formatted as 1.234,50 €', () => {
    const { ReportsLogic } = load();
    const f = ReportsLogic.formatAmount;
    assert.equal(f(1234.5), '1.234,50 €');
    assert.equal(f(0), '0,00 €');
    assert.equal(f(-36), '-36,00 €');
    assert.equal(f(1234567.891), '1.234.567,89 €');
    assert.equal(f(999.999), '1.000,00 €');
    assert.equal(f(-0.001), '0,00 €', 'no negative zero');
    assert.equal(f(null), '0,00 €');
    assert.equal(ReportsLogic.formatCount(12345), '12.345');
    assert.equal(ReportsLogic.formatPercent(null), '—');
    assert.equal(ReportsLogic.formatPercent(12.5), '+12,5 %');
    assert.equal(ReportsLogic.formatPercent(-100), '-100,0 %');
    assert.equal(ReportsLogic.formatDate('2026-03-09'), '09/03/2026');
});

test('the screen has the eight report tabs, built from one list', () => {
    const { REPORT_TABS } = load();
    assert.deepEqual(plain(REPORT_TABS.map((t) => [t.type, t.label])),
        [['summary', 'Resumen'], ['daily', 'Por día'], ['hourly', 'Por hora'], ['products', 'Por producto'], ['categories', 'Por categoría'],
            ['cashiers', 'Por cajero'], ['payments', 'Por forma de pago'], ['vat', 'IVA']]);
    const template = /template: `([\s\S]*)`/.exec(source)[1];
    for (const t of REPORT_TABS) {
        assert.match(template, new RegExp(`activeType === '${t.type}'`), `the ${t.type} tab has its table`);
    }
});

test('it opens on Resumen for today and loads the tills', async () => {
    const { state, calls, mount } = load();
    await mount();
    assert.ok(calls.includes('/api/device'), 'the till list comes from /api/device');
    assert.deepEqual(plain(state.tills.value), tills);
    assert.deepEqual(reportCalls(calls), ['/api/reports/summary?from=2026-03-11&to=2026-03-11']);
    assert.equal(state.loading.value, false);
    assert.equal(state.data.value.net_sales, 106);
});

test('switching tabs keeps the date and till filters', async () => {
    const { state, calls, mount } = load({ answers: { daily: { data: { from: '2026-03-01', to: '2026-03-31', days: [], totals: { tickets: 0, units: 0, gross_sales: 0, refunds: 0, net_sales: 0 } }, approximate_amount: 0, missing_lines: 0 } } });
    await mount();
    await state.setPreset('month');
    state.filters.terminalId = '2';
    await state.load();
    await state.selectTab('daily');
    assert.equal(state.activeType.value, 'daily');
    assert.equal(state.filters.preset, 'month');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/daily?from=2026-03-01&to=2026-03-31&terminal_id=2');
    await state.selectTab('payments');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/payments?from=2026-03-01&to=2026-03-31&terminal_id=2');
});

test('payments can be filtered by a closed cash session instead of dates', async () => {
    const { state, calls, mount } = load({ answers: { payments: { data: { sales_total: 4, refunds_total: 0, tickets_by_method: { cash: 0, card: 1, mixed: 0, exchange: 0 } }, approximate_amount: 0, missing_lines: 0 } } });
    await mount();
    await state.selectTab('payments');
    assert.ok(calls.includes('/api/cash/z'), 'the closed sessions come from /api/cash/z');
    state.filters.sessionId = '3';
    state.filters.terminalId = '1';
    await state.load();
    assert.equal(reportCalls(calls).at(-1), '/api/reports/payments?session_id=3');
    // Other reports never send the session.
    await state.selectTab('summary');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/summary?from=2026-03-11&to=2026-03-11&terminal_id=1');
});

test('«Descargar CSV» downloads the same request with format=csv and the session cookie', async () => {
    const { state, downloads, mount } = load();
    await mount();
    state.filters.terminalId = '1';
    await state.downloadCsv();
    assert.equal(downloads[0].fetched, '/tpv/api/reports/summary?from=2026-03-11&to=2026-03-11&terminal_id=1&format=csv');
    assert.equal(downloads[0].credentials, 'same-origin');
    assert.deepEqual(plain(downloads[1]), { href: 'blob:csv', download: 'informe-resumen-2026-03-11-2026-03-11.csv' });
    assert.equal(state.csvError.value, '');
});

test('an invalid custom range is not sent and shows the reason', async () => {
    const { state, calls, mount } = load();
    await mount();
    await state.setPreset('custom');
    state.filters.from = '2026-03-12';
    state.filters.to = '2026-03-01';
    const before = reportCalls(calls).length;
    await state.load();
    assert.equal(reportCalls(calls).length, before);
    assert.equal(state.error.value, 'La fecha desde no puede ser posterior a la fecha hasta');
});

test('empty, error with retry, and approximate states', async () => {
    let fail = true;
    const empty = summaryResponse();
    Object.assign(empty.data, { gross_sales: 0, net_sales: 0, tickets: 0, refunds: 0, units_sold: 0 });
    const { state, mount } = load({
        answers: {
            summary: () => {
                if (fail) {
                    const err = new Error('HTTP 422: {"error":"El rango máximo es de 366 días"}');
                    err.status = 422; err.body = { error: 'El rango máximo es de 366 días' };
                    throw err;
                }
                return empty;
            },
            daily: summaryResponse({ approximate_amount: 12.5, missing_lines: 0 }),
            payments: summaryResponse({ approximate_amount: 0, missing_lines: 2 }),
        },
    });
    await mount();
    assert.equal(state.error.value, 'El rango máximo es de 366 días');
    assert.equal(state.loading.value, false);

    fail = false;
    await state.retry();
    assert.equal(state.error.value, '');
    assert.equal(state.isEmpty.value, true);
    assert.equal(state.ReportsLogic.EMPTY_TEXT, 'No hay ventas en este periodo');
    assert.deepEqual(plain(state.notices.value), []);

    await state.selectTab('daily');
    assert.match(state.notices.value[0], /12,50 €/);
    await state.selectTab('payments');
    assert.match(state.notices.value[0], /2 ventas/);
});

test('a network failure shows a generic message', async () => {
    const { state, mount } = load({ answers: { summary: () => { throw new TypeError('Failed to fetch'); } } });
    await mount();
    assert.equal(state.error.value, 'No se ha podido cargar el informe. Comprueba la conexión y vuelve a intentarlo.');
});

test('daily bars: height relative to the largest day, negative days below the axis', () => {
    const { ReportsLogic } = load();
    const bars = ReportsLogic.bars([{ date: '2026-03-10', net_sales: 106 }, { date: '2026-03-11', net_sales: -53 }, { date: '2026-03-12', net_sales: 0 }]);
    assert.deepEqual(plain(bars.map((b) => [b.percent, b.negative])), [[100, false], [50, true], [0, false]]);
    assert.deepEqual(plain(ReportsLogic.bars([{ date: '2026-03-10', net_sales: 0 }]).map((b) => b.percent)), [0]);
});

test('the sale screen opens it with a header button shown only to administrators', () => {
    const sale = fs.readFileSync(path.join(ROOT, 'public/assets/js/components/SaleScreen.js'), 'utf8');
    assert.match(sale, /<button v-if="session\.role === 'admin'" @click="showReports = true"[^>]*>[\s\S]{0,300}Informes\s*<\/button>/);
    assert.match(sale, /v-else-if="showReports && session\.role === 'admin'"[\s\S]{0,200}<reports-screen @back="showReports = false"/);
    assert.match(sale, /const showReports = Vue\.ref\(false\)/);
    assert.match(sale, /return \{[\s\S]*showReports/);

    const app = fs.readFileSync(path.join(ROOT, 'public/assets/js/app.js'), 'utf8');
    assert.match(app, /app\.component\('reports-screen', ReportsScreen\)/);
    const page = fs.readFileSync(path.join(ROOT, 'templates/pos.html.twig'), 'utf8');
    const script = page.indexOf('/assets/js/components/ReportsScreen.js?v=');
    assert.ok(script > 0, 'pos.html.twig loads ReportsScreen.js with a cache version');
    assert.ok(script < page.indexOf('/assets/js/app.js'), 'before app.js');
});

test('the template uses only what setup exposes (no global consts)', () => {
    const template = /template: `([\s\S]*)`/.exec(source)[1];
    assert.doesNotMatch(template, /\bApiClient\b|\bREPORT_TABS\b|\bwindow\./);
});

function productsResponse({ view = 'sold', cursor = '0', next = null, previous = null, rows = null } = {}) {
    const sold = [{ product_id: 1, id_product_attribute: 0, reference: 'CAM-01', name: 'Camiseta', free_item: false, units_sold: 3, units_refunded: 1, units_net: 2,
        sales_amount: 75, refunds_amount: 25, net_amount: 50, percent: 47.17 }];
    return {
        data: {
            from: '2026-03-11', to: '2026-03-11', view, sort: 'amount', group: 'combination',
            rows: rows || (view === 'unsold' ? [{ product_id: 5, reference: 'GAF-01', name: 'Gafas', category_name: null }] : sold),
            totals: view === 'unsold' ? { products: 1 } : { units_sold: 3, units_refunded: 1, units_net: 2, sales_amount: 75, refunds_amount: 25, net_amount: 50 },
            page: { cursor, size: 50, total_rows: 120, next_cursor: next, previous_cursor: previous },
        },
        approximate_amount: 0, missing_lines: 0,
    };
}

test('«Por producto» pages with «Siguiente» / «Anterior» by cursor, like the print history', async () => {
    const pages = { '': productsResponse({ next: '50' }), 50: productsResponse({ cursor: '50', next: '100', previous: '0' }), 100: productsResponse({ cursor: '100', previous: '50' }) };
    const { state, calls, mount } = load({ answers: { products: (url) => pages[(/cursor=(\d+)/.exec(url) || [, ''])[1]] } });
    await mount();
    await state.selectTab('products');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11');
    assert.deepEqual(plain(state.productPage.value), { number: 1, hasPrev: false, hasNext: true, total: 120 });

    await state.nextPage();
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&cursor=50');
    await state.nextPage();
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&cursor=100');
    assert.deepEqual(plain(state.productPage.value), { number: 3, hasPrev: true, hasNext: false, total: 120 });
    const before = reportCalls(calls).length;
    await state.nextPage();
    assert.equal(reportCalls(calls).length, before, 'no page after the last one');

    await state.prevPage();
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&cursor=50');
    // «Reintentar» stays on the page; a filter change goes back to page 1.
    await state.retry();
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&cursor=50');
    state.filters.terminalId = '2';
    await state.load();
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&terminal_id=2');
    assert.equal(state.productPage.value.number, 1);
});

test('«Por producto» options: sort, grouping and «Sin ventas» go back to page 1, and the CSV has every row', async () => {
    const { state, calls, downloads, mount } = load({
        answers: { products: (url) => productsResponse({ view: /view=unsold/.test(url) ? 'unsold' : 'sold', next: '50' }) },
    });
    await mount();
    await state.selectTab('products');
    await state.nextPage();
    await state.setProductOption('sort', 'units');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&sort=units');
    await state.setProductOption('group', 'product');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&sort=units&group=product');
    await state.nextPage();
    await state.downloadCsv();
    assert.equal(downloads[0].fetched, '/tpv/api/reports/products?from=2026-03-11&to=2026-03-11&sort=units&group=product&format=csv', 'no cursor in the CSV');

    await state.setProductOption('view', 'unsold');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/products?from=2026-03-11&to=2026-03-11&view=unsold&sort=units&group=product');
    assert.equal(state.data.value.view, 'unsold');
    assert.equal(state.isEmpty.value, false, '«Sin ventas» is not «No hay ventas»');

    // The options stay when the tab changes, and other reports never send them.
    await state.selectTab('daily');
    assert.equal(reportCalls(calls).at(-1), '/api/reports/daily?from=2026-03-11&to=2026-03-11');
    await state.selectTab('products');
    assert.match(reportCalls(calls).at(-1), /view=unsold/);
    assert.deepEqual(plain(state.ReportsLogic.PRODUCT_VIEWS.map((v) => v.label)), ['Vendidos', 'Sin ventas']);
});

test('formats and empty states of the hourly, product, category, cashier and VAT reports', () => {
    const { ReportsLogic: L } = load();
    assert.equal(L.formatHour(9), '09:00');
    assert.equal(L.formatHour(23), '23:00');
    assert.equal(L.formatRate(21), '21 %');
    assert.equal(L.formatRate(5.26), '5,26 %');
    assert.equal(L.productName({ name: 'Cuota club', free_item: true }), 'Artículo libre: Cuota club');
    assert.equal(L.productName({ name: 'Gorro', free_item: false }), 'Gorro');

    assert.equal(L.isEmpty('hourly', { totals: { tickets: 0, gross_sales: 0 } }), true);
    assert.equal(L.isEmpty('hourly', { totals: { tickets: 1, gross_sales: 0 } }), false);
    assert.equal(L.isEmpty('products', { view: 'sold', rows: [] }), true);
    assert.equal(L.isEmpty('products', { view: 'unsold', rows: [] }), false);
    for (const type of ['categories', 'cashiers', 'vat']) {
        assert.equal(L.isEmpty(type, { rows: [] }), true, type);
        assert.equal(L.isEmpty(type, { rows: [{}] }), false, type);
    }
    assert.equal(L.UNSOLD_EMPTY_TEXT, 'Todos los productos activos del catálogo tienen ventas en este periodo');
});
