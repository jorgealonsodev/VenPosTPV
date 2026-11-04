// PRD I1-I3, V4, S6 (T7): the sync indicator counts the uploads still to do and the
// incidents, the cash close warns without blocking, the administrator retries the
// incidents, the cashier is warned about stock the TPV does not have, the order detail
// reprints by the local id and the paid list searches by the ticket code.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }
function reactive(value) { return value; }

function load(file, name, extra = {}) {
    const source = fs.readFileSync(path.join(ROOT, file), 'utf8');
    const context = {
        Vue: { ref, computed, reactive, watch() {}, onMounted(fn) { context.__mounted.push(fn); }, onUnmounted() {} },
        __mounted: [],
        document: { addEventListener() {}, removeEventListener() {} },
        setTimeout(fn) { return 1; }, clearTimeout() {},
        Number, String, Math, JSON, Object, Array, Promise, Error, console,
        ...extra,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__component = ${name};`, context, { filename: file });
    return { component: context.__component, context, source };
}

function setup(component, props, emitted = []) {
    return component.setup(props, { emit: (name, ...args) => emitted.push([name, ...args]) });
}

async function settle() { for (let i = 0; i < 5; i++) await Promise.resolve(); }

// I1: one indicator, with the pending uploads and the incidents.
function testIndicatorShowsPendingAndIncidentCounts() {
    const { component } = load('public/assets/js/components/SyncStatusIndicator.js', 'SyncStatusIndicator');
    const state = setup(component, { state: 'ok', data: { latency_ms: 40 }, outbox: { pending: 3, incidents: 1 } });
    assert.equal(state.pendingCount.value, 3);
    assert.equal(state.incidentCount.value, 1);
    assert.equal(state.tooltip.value, 'Sincronizado (40 ms) · 3 pendientes de subir a PrestaShop · 1 incidencia');

    const before = setup(component, { state: 'ok', data: { latency_ms: 40 } });
    assert.equal(before.tooltip.value, 'Sincronizado (40 ms)', 'without outbox data the tooltip is as before');
    assert.equal(before.pendingCount.value, 0);
    assert.equal(setup(component, { state: 'offline', data: null, outbox: { pending: 1, incidents: 2 } }).tooltip.value,
        'Sin conexión · 1 pendiente de subir a PrestaShop · 2 incidencias');
}

function testIndicatorIsATouchTarget() {
    const { component } = load('public/assets/js/components/SyncStatusIndicator.js', 'SyncStatusIndicator');
    assert.match(component.template, /<button[^>]*min-width:44px[^>]*min-height:44px|<button[^>]*min-height:44px[^>]*min-width:44px/s);
}

function testPanelOffersIncidentsToAdministratorsOnly() {
    const { component } = load('public/assets/js/components/SyncStatusPanel.js', 'SyncStatusPanel');
    assert.ok(component.emits.includes('show-incidents'));
    assert.match(component.template, /v-if="isAdmin"[^>]*@click="\$emit\('show-incidents'\)"|@click="\$emit\('show-incidents'\)"[^>]*v-if="isAdmin"/s);
    assert.match(component.template, /Pendientes de subir/);
    assert.match(component.template, /Incidencias/);
}

// The store polls the outbox counts next to the catalog sync status.
async function testSessionStoreKeepsTheOutboxCounts() {
    const source = fs.readFileSync(path.join(ROOT, 'public/assets/js/stores/useSession.js'), 'utf8');
    const storage = new Map([['venpostpv_session', JSON.stringify({ screen: 'sale' })]]);
    const context = {
        window: { __SESSION__: { user_id: 1, terminal_id: 7 }, TPV_BASE: '', addEventListener() {}, location: {} },
        navigator: { onLine: true },
        localStorage: { getItem: (k) => storage.get(k) || null, setItem: (k, v) => storage.set(k, v) },
        document: { documentElement: { setAttribute() {} } },
        Vue: { reactive: (v) => v },
        ApiClient: {
            cashCurrent: () => Promise.resolve({ data: null }),
            syncStatus: () => Promise.resolve({ state: 'ok' }),
            outboxStatus: () => Promise.resolve({ data: { pending: 2, incidents: 1 } }),
        },
        setTimeout() { return 1; }, clearTimeout() {}, setInterval() { return 1; }, clearInterval() {}, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__store = useSession;`, context, { filename: 'useSession.js' });
    const store = context.__store;
    store.startSyncPolling();
    await settle();
    assert.deepEqual({ ...store.session.outbox }, { pending: 2, incidents: 1, blocked: 0 }, 'an older server sends no blocked count');

    context.ApiClient.outboxStatus = () => Promise.reject(new Error('HTTP 500'));
    await store.refreshOutbox();
    assert.deepEqual({ ...store.session.outbox }, { pending: 2, incidents: 1, blocked: 0 }, 'a failed read keeps the last counts');

    context.ApiClient.outboxStatus = () => Promise.resolve({ data: { pending: 4, incidents: 1, blocked: 3 } });
    await store.refreshOutbox();
    assert.deepEqual({ ...store.session.outbox }, { pending: 4, incidents: 1, blocked: 3 });
}

// I2, A6: the close warns about pending uploads and incidents, and still closes.
function testCashCloseWarnsAboutPendingUploadsWithoutBlocking() {
    const { component } = load('public/assets/js/components/CashCloseScreen.js', 'CashCloseScreen');
    const emitted = [];
    const state = setup(component, { session: { openingCash: 0 }, parkedCount: 0, outbox: { pending: 3, incidents: 2 } }, emitted);
    assert.equal(state.pendingUploadsText.value, 'Hay 3 operaciones pendientes de subir a PrestaShop');
    assert.equal(state.incidentsText.value, '2 en incidencia: un administrador debe revisarlas en Incidencias');
    assert.equal(setup(component, { session: {}, outbox: { pending: 1, incidents: 0 } }).pendingUploadsText.value,
        'Hay 1 operación pendiente de subir a PrestaShop');
    assert.equal(setup(component, { session: {}, outbox: { pending: 1, incidents: 0 } }).incidentsText.value, '');
    assert.equal(setup(component, { session: {}, outbox: { pending: 0, incidents: 1 } }).pendingUploadsText.value, '');
    assert.equal(setup(component, { session: {} }).pendingUploadsText.value, '', 'no outbox data, no warning');

    const closeButton = component.template.match(/<button[^>]*@click="\$emit\('close'[^>]*>/);
    assert.ok(closeButton, 'the close button exists');
    assert.doesNotMatch(closeButton[0], /disabled/, 'pending uploads never disable the close');
    assert.match(component.template, /v-if="pendingUploadsText \|\| incidentsText"/);
}

// I2: uploads blocked by an incident do not upload on their own, and the close says so.
function testCashCloseSaysWhichUploadsWaitForAnIncident() {
    const { component } = load('public/assets/js/components/CashCloseScreen.js', 'CashCloseScreen');
    const blocked = setup(component, { session: {}, outbox: { pending: 3, incidents: 1, blocked: 3 } });
    assert.equal(blocked.pendingUploadsText.value, 'Hay 3 operaciones pendientes de subir a PrestaShop');
    assert.equal(blocked.blockedUploadsText.value, '3 esperando a una incidencia');
    assert.equal(blocked.uploadsOnTheirOwn.value, false, 'all of them wait for the incident');
    const mixed = setup(component, { session: {}, outbox: { pending: 3, incidents: 1, blocked: 1 } });
    assert.equal(mixed.blockedUploadsText.value, '1 esperando a una incidencia');
    assert.equal(mixed.uploadsOnTheirOwn.value, true);
    const free = setup(component, { session: {}, outbox: { pending: 2, incidents: 0 } });
    assert.equal(free.blockedUploadsText.value, '', 'an older server sends no blocked count');
    assert.equal(free.uploadsOnTheirOwn.value, true);
    assert.match(component.template, /v-if="blockedUploadsText"[^>]*>\{\{ blockedUploadsText \}\}/);
    assert.match(component.template, /<span v-if="uploadsOnTheirOwn">Se suben solas cuando PrestaShop responde\. <\/span>Puedes cerrar la caja igualmente\./);
}

function testPanelSaysWhichPendingUploadsWaitForAnIncident() {
    const { component } = load('public/assets/js/components/SyncStatusPanel.js', 'SyncStatusPanel');
    assert.equal(setup(component, { outbox: { pending: 4, incidents: 1, blocked: 2 } }).blockedText.value, '2 esperando a una incidencia');
    assert.equal(setup(component, { outbox: { pending: 4, incidents: 0, blocked: 0 } }).blockedText.value, '');
    assert.equal(setup(component, { outbox: null }).blockedText.value, '');
    assert.match(component.template, /v-if="blockedText"[^>]*>\{\{ blockedText \}\}/);
}

function loadSaleScreenFunction(name) {
    const source = fs.readFileSync(path.join(ROOT, 'public/assets/js/components/SaleScreen.js'), 'utf8');
    const match = source.match(new RegExp(`function ${name}\\([^)]*\\) \\{[\\s\\S]*?\\n        \\}\\n`));
    assert.ok(match, `SaleScreen.${name} is present`);
    return match[0];
}

// V4: after the sale, the lines the TPV had no stock for are named; nothing is blocked.
function testStockWarningsNameTheProductsAfterTheSale() {
    const context = { Array, String };
    vm.runInNewContext(`${loadSaleScreenFunction('stockWarningText')}\nglobalThis.__fn = stockWarningText;`, Object.assign(context, { globalThis: context }));
    const lines = [{ name: 'Camisa M' }, { name: 'Pantalón 42' }, { name: 'Gorra' }];
    assert.equal(context.__fn([{ line_index: 1, requested: 2, available: 0 }], lines), 'Sin stock en el TPV: Pantalón 42 — revisa el inventario');
    assert.equal(context.__fn([{ line_index: 0 }, { line_index: 2 }], lines), 'Sin stock en el TPV: Camisa M, Gorra — revisa el inventario');
    assert.equal(context.__fn([{ line_index: 9, product_id: 77 }], lines), 'Sin stock en el TPV: producto 77 — revisa el inventario');
    assert.equal(context.__fn([], lines), '');
    assert.equal(context.__fn(undefined, lines), '');
}

async function testSaleCompletionShowsTheStockNoticeAndStillClears() {
    const fnSource = loadSaleScreenFunction('stockWarningText') + loadSaleScreenFunction('handlePaymentComplete');
    const cleared = [];
    const notes = [];
    const context = {
        Array, String,
        showPayment: ref(true), ticketCount: ref(1), lastCompletedOrder: ref(null), stockNotice: ref(''),
        cart: { lines: [{ name: 'Camisa M' }] },
        clear: () => cleared.push(true),
        notify: (msg) => notes.push(msg),
    };
    vm.runInNewContext(`${fnSource}\nglobalThis.__done = handlePaymentComplete;`, Object.assign(context, { globalThis: context }));
    context.__done({ order_id: 5, reference: 'A26000005', stock_warnings: [{ line_index: 0, requested: 1, available: 0 }] });
    assert.equal(context.stockNotice.value, 'Sin stock en el TPV: Camisa M — revisa el inventario');
    assert.deepEqual(cleared, [true]);
    assert.deepEqual(notes, ['Ticket A26000005 completado']);

    context.__done({ order_id: 6, reference: 'A26000006', stock_warnings: [] });
    assert.equal(context.stockNotice.value, '', 'a sale with stock clears the previous notice');
}

// V4 before paying: a line with more units than the TPV's stock is marked, never blocked.
function testTicketPanelMarksLinesTheLocalStockDoesNotCover() {
    const { component } = load('public/assets/js/components/TicketPanel.js', 'TicketPanel');
    const props = {
        cart: { lines: [{ id: 'a', product_id: 1, id_product_attribute: 0, qty: 3 }, { id: 'b', product_id: 2, id_product_attribute: 5, qty: 1 }, { id: 'c', product_id: 3, qty: 1 }] },
        stockByKey: { '1:0': 2, '2:5': 1 },
    };
    const state = setup(component, props);
    assert.equal(state.outOfStock(props.cart.lines[0]), true, '3 units, 2 in stock');
    assert.equal(state.outOfStock(props.cart.lines[1]), false);
    assert.equal(state.outOfStock(props.cart.lines[2]), false, 'unknown stock is not marked');
    assert.equal(setup(component, { cart: props.cart }).outOfStock(props.cart.lines[0]), false, 'without stock data nothing is marked');
    assert.match(component.template, /v-if="outOfStock\(line\)"[^>]*>\s*Sin stock en el TPV/);
    assert.doesNotMatch(component.template, /:disabled="[^"]*outOfStock/, 'paying is never blocked');
}

function testSaleScreenRemembersTheStockOfWhatItAdds() {
    const context = { Number, stockByKey: {}, add: () => true, lastAdded: ref(null), setTimeout() {}, notify() {} };
    vm.runInNewContext(`${loadSaleScreenFunction('addProduct')}\nglobalThis.__add = addProduct;`, Object.assign(context, { globalThis: context }));
    context.__add({ id: 1, stock: 4 });
    context.__add({ id: 2, stock: 9, selected_combination_id: 7, combinations: [{ id: 7, stock: 1 }, { id: 8, stock: 5 }] });
    assert.deepEqual(context.stockByKey, { '1:0': 4, '2:7': 1 });
}

function incidentsApi(rows) {
    const calls = [];
    return {
        calls,
        api: {
            incidentsList: async () => { calls.push(['list']); return { data: rows }; },
            incidentRetry: async (id) => { calls.push(['retry', id]); return { data: { retried: [id] } }; },
            incidentRetryOrder: async (id) => { calls.push(['retryOrder', id]); return { data: { retried: [11, 12] } }; },
            ticketSalePdf: (id) => calls.push(['ticketSalePdf', id]),
            refundPdf: (id) => calls.push(['refundPdf', id]),
        },
    };
}

// I3: the administrator sees the error and retries one row or a whole sale.
async function testIncidentsScreenListsRetriesAndOpensTheTicket() {
    const rows = [
        { id: 11, type: 'sale', order_id: 3, sequence: 1, reference: 'A26000003', credit_note_number: null, refund_id: null, attempts: 1, last_error: 'HTTP 422: Product 77', created_at: '2026-11-03 09:00:00', waiting_behind: 1 },
        { id: 12, type: 'refund', order_id: 3, sequence: 2, reference: 'A26000003', credit_note_number: 'R2026-000009', refund_id: 9, attempts: 1, last_error: 'HTTP 404', created_at: '2026-11-03 09:05:00', waiting_behind: 0 },
    ];
    const { api, calls } = incidentsApi(rows);
    const { component, context } = load('public/assets/js/components/IncidentsScreen.js', 'IncidentsScreen', { ApiClient: api });
    const emitted = [];
    const state = setup(component, {}, emitted);
    for (const fn of context.__mounted) await fn();
    assert.equal(state.incidents.value.length, 2);
    assert.equal(state.typeLabel(rows[0]), 'Venta');
    assert.equal(state.typeLabel(rows[1]), 'Devolución');
    assert.equal(state.documentLabel(rows[1]), 'Abono R2026-000009 · Ticket A26000003');
    assert.equal(state.saleHasSeveral(rows[0]), true);

    await state.retry(rows[1]);
    await state.retryOrder(rows[0]);
    state.viewTicket(rows[0]);
    state.viewCreditNote(rows[1]);
    assert.deepEqual(calls, [['list'], ['retry', 12], ['list'], ['retryOrder', 3], ['list'], ['ticketSalePdf', 3], ['refundPdf', 9]]);
    assert.ok(emitted.some(([name]) => name === 'changed'), 'the indicator is refreshed after a retry');
    assert.match(component.template, /Reintentar/);
    assert.match(component.template, /Ver ticket/);
    assert.match(component.template, /min-height:44px|tpv-btn/);
}

async function testARetryOfARowAlreadyRetriedReloadsAndSaysSo() {
    const { api, calls } = incidentsApi([{ id: 11, type: 'sale', order_id: 3, sequence: 1, reference: 'A26000003', attempts: 1, last_error: 'x' }]);
    api.incidentRetry = async (id) => { calls.push(['retry', id]); throw new Error('HTTP 409: {"code":"not_incident"}'); };
    const { component, context } = load('public/assets/js/components/IncidentsScreen.js', 'IncidentsScreen', { ApiClient: api });
    const emitted = [];
    const state = setup(component, {}, emitted);
    for (const fn of context.__mounted) await fn();
    await state.retry(state.incidents.value[0]);
    assert.deepEqual(calls, [['list'], ['retry', 11], ['list']]);
    assert.equal(state.message.value, 'Esa incidencia ya se reintentó o se subió');
}

// Advisory L6-L8: the order detail reprints a sale by its local id, also before it is uploaded.
async function testOrderDetailReprintsByTheLocalId() {
    const calls = [];
    const api = {
        ticketReprintSale: async (id) => { calls.push(['ticketReprintSale', id]); return { data: { printed: true } }; },
        ticketReprint: async (id) => { calls.push(['ticketReprint', id]); return { data: { fallback: 'pdf' } }; },
        ticketSalePdf: (id) => calls.push(['ticketSalePdf', id]),
        ticketPdf: (id) => calls.push(['ticketPdf', id]),
    };
    const { component } = load('public/assets/js/components/OrderDetailView.js', 'OrderDetailView', { ApiClient: api });
    const emitted = [];
    const local = setup(component, { order: { order_id: 15, ps_order_id: null, reference: 'A26000015', lines: [] } }, emitted);
    assert.equal(local.title.value, 'A26000015');
    await local.reprint();
    local.pdf();
    const online = setup(component, { order: { order_id: null, ps_order_id: 42, reference: '', lines: [] } }, emitted);
    assert.equal(online.title.value, '#42');
    await online.reprint();
    online.pdf();
    assert.deepEqual(calls, [['ticketReprintSale', 15], ['ticketSalePdf', 15], ['ticketReprint', 42], ['ticketPdf', 42], ['ticketPdf', 42]]);
    assert.deepEqual(emitted.map((e) => e[1]), ['Ticket reimpreso', 'Descargando ticket PDF']);
    assert.match(component.template, /Reimprimir/);
}

// S6: the paid list finds a ticket by its code (or PrestaShop's reference).
async function testTicketListSearchesPaidTicketsByCode() {
    const calls = [];
    const api = { searchOrders: async (q, params) => { calls.push([q, params]); return { data: [{ order_id: 3, reference: 'A26000003' }] }; } };
    const { component } = load('public/assets/js/components/TicketListPanel.js', 'TicketListPanel', {
        ApiClient: api,
        useParkedTickets: { state: { tickets: [] }, refresh: async () => {}, removeTicket: async () => {} },
    });
    const state = setup(component, { activeCartCount: 0 });
    state.paidQuery.value = '  a26000003 ';
    await state.searchPaid();
    assert.deepEqual(calls, [['A26000003', undefined]]);
    state.paidQuery.value = '';
    await state.searchPaid();
    assert.deepEqual(calls[1], ['', undefined], 'an empty search lists the latest paid tickets as before');
    assert.equal(state.paidOrders.value[0].reference, 'A26000003');
    assert.equal(state.orderLabel({ order_id: 3, ps_order_id: 38, reference: 'A26000003' }), '#38 — A26000003', 'label as before');
    assert.equal(state.orderLabel({ order_id: 4, ps_order_id: null, reference: 'A26000004' }), 'A26000004');
}

// L3: every changed asset gets a new ?v= so browsers do not keep the old copy.
function testThePageLoadsTheIncidentsScreenAndNewVersionsOfTheChangedAssets() {
    const page = fs.readFileSync(path.join(ROOT, 'templates/pos.html.twig'), 'utf8');
    for (const asset of ['css/app.css', 'js/api.js', 'stores/useSession.js', 'SyncStatusIndicator.js', 'SyncStatusPanel.js', 'SaleScreen.js',
        'TicketPanel.js', 'OrderDetailView.js', 'TicketListPanel.js', 'CashCloseScreen.js', 'IncidentsScreen.js', 'js/app.js']) {
        assert.match(page, new RegExp(asset.replace('.', '\\.') + '\\?v=2026110[3-9]-'), asset);
    }
    assert.ok(page.indexOf('IncidentsScreen.js') < page.indexOf('/app.js'), 'the component loads before the app registers it');
    assert.match(fs.readFileSync(path.join(ROOT, 'public/assets/js/app.js'), 'utf8'), /app\.component\('incidents-screen', IncidentsScreen\)/);
}

(async () => {
    testThePageLoadsTheIncidentsScreenAndNewVersionsOfTheChangedAssets();
    testIndicatorShowsPendingAndIncidentCounts();
    testIndicatorIsATouchTarget();
    testPanelOffersIncidentsToAdministratorsOnly();
    await testSessionStoreKeepsTheOutboxCounts();
    testCashCloseWarnsAboutPendingUploadsWithoutBlocking();
    testCashCloseSaysWhichUploadsWaitForAnIncident();
    testPanelSaysWhichPendingUploadsWaitForAnIncident();
    testStockWarningsNameTheProductsAfterTheSale();
    await testSaleCompletionShowsTheStockNoticeAndStillClears();
    testTicketPanelMarksLinesTheLocalStockDoesNotCover();
    testSaleScreenRemembersTheStockOfWhatItAdds();
    await testIncidentsScreenListsRetriesAndOpensTheTicket();
    await testARetryOfARowAlreadyRetriedReloadsAndSaysSo();
    await testOrderDetailReprintsByTheLocalId();
    await testTicketListSearchesPaidTicketsByCode();
    console.log('syncVisibility: OK');
})().catch((err) => { console.error(err); process.exit(1); });
