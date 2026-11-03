const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

function load(refundOrder, refundRetry = async () => ({ data: {} }), opened = [], extra = {}) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');
    const context = {
        Vue: { ref, ...(extra.vue || {}) },
        ApiClient: { refundOrder, refundRetry, refundPdf: (id) => opened.push(id), searchOrders: async () => ({ data: [] }), ...(extra.api || {}) },
        useSession: { session: { online: true } }, Number, Math, JSON, parseInt, Boolean, Promise, Error, Array, String, RegExp, console,
        decodeURIComponent, atob,
        ...(extra.globals || {}),
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__screen = RefundScreen;`, context, { filename: 'RefundScreen.js' });
    const emitted = [];
    const state = context.__screen.setup({}, { emit: (...args) => emitted.push(args) });
    return { state, emitted };
}

/** Loads the screen with lifecycle hooks and a fake scanner; returns a scan(code) helper. */
function loadWithScanner(api) {
    const hooks = { mounted: [], unmounted: [] };
    const scanner = { cb: null, onScan(cb) { this.cb = cb; }, offScan() { this.cb = null; } };
    const searches = [];
    const verified = [];
    const doc = { activeElement: null };
    const loaded = load(async () => ({ data: {} }), undefined, [], {
        vue: { onMounted: (fn) => hooks.mounted.push(fn), onUnmounted: (fn) => hooks.unmounted.push(fn) },
        api: {
            searchOrders: async (q) => { searches.push(q); return api.searchOrders(q); },
            ticketVerify: async (token) => { verified.push(token); return api.ticketVerify(token); },
        },
        globals: { useBarcode: scanner, document: doc },
    });
    hooks.mounted.forEach((fn) => fn());
    return {
        ...loaded, hooks, scanner, searches, verified, doc,
        scan: (code) => scanner.cb(code),
    };
}

function jwt(claims) {
    const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return `${b64({ alg: 'HS256', typ: 'JWT' })}.${b64(claims)}.signature`;
}

const ORDER = {
    ps_order_id: 42, reference: 'REF42', payment_method: 'mixed', tender: { cash: 25, card: 35 },
    lines: [
        { name: 'Camisa M', product_id: 3, id_product_attribute: 7, qty: 1, paid_total: 24.18, refundable_qty: 1 },
        { name: 'Calcetines', product_id: 4, id_product_attribute: 0, qty: 2, paid_total: 35.82, refundable_qty: 1 },
        { name: 'Venta antigua', qty: 1, price: 5, line_total: 5 },
    ],
};

async function testRequestCarriesOnlyIndexesQuantitiesRestockReasonAndConfirmation() {
    const calls = [];
    const { state, emitted } = load(async (id, request) => { calls.push([id, request]); return { data: { refunded_amount: 24.18, cash_amount: 10.08, card_amount: 14.1 } }; });
    state.selectOrder(ORDER);

    assert.equal(state.selectedLines.value[1].refundable, 1, 'refundable quantity comes from the server');
    state.toggleLine(2);
    assert.equal(state.selectedLines.value[2].checked, false, 'legacy lines without product ids cannot be selected');

    state.toggleLine(0);
    assert.equal(state.validationError(), 'Elige el motivo de la devolución');
    state.setReason('defective');
    assert.equal(state.selectedLines.value[0].restock, false, 'defective unticks restock by default');
    state.selectedLines.value[0].restock = true; // the cashier can still restock
    assert.deepEqual(JSON.parse(JSON.stringify(state.estimatedSplit())), { cash: 10.08, card: 14.1 });
    assert.equal(state.validationError(), 'Confirma que has hecho la devolución en el datáfono');
    state.cardConfirmed.value = true;
    assert.equal(state.validationError(), '');

    await state.confirmRefund();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], 42);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0][1])), {
        lines: [{ line_index: 0, quantity: 1, restock: true }],
        reason_code: 'defective', reason_text: '', card_confirmed: true,
    });
    assert.match(emitted.at(-1)[1], /24\.18 €/);
}

async function testOtherReasonNeedsTextAndQuantityIsCappedAtRefundable() {
    const { state } = load(async () => ({ data: {} }));
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    state.toggleLine(1);
    state.updateRefundQty(1, '5');
    assert.equal(state.selectedLines.value[1].refundQty, 1);
    state.setReason('other');
    assert.equal(state.validationError(), 'Escribe el motivo');
    state.reasonText.value = '  Talla equivocada ';
    assert.equal(state.validationError(), '', 'cash-only refunds need no card confirmation');
    assert.equal(state.buildRequest().reason_text, 'Talla equivocada');
    assert.equal(state.buildRequest().card_confirmed, false);
}

async function testServerErrorsAreShownInSpanishWithDetail() {
    const { state, emitted } = load(async () => { throw new Error('HTTP 422: {"error":"Refund validation failed: Only 0 unit(s) of line 1 are left to refund","retry":false}'); });
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    state.toggleLine(0);
    state.setReason('size');
    await state.confirmRefund();
    assert.equal(emitted.at(-1)[1], 'No se puede devolver: Refund validation failed: Only 0 unit(s) of line 1 are left to refund');
    assert.ok(state.selectedOrder.value, 'stays on the order after an error');
}

async function testPendingRefundBlocksNewOnesAndCanBeRetried() {
    const retried = [];
    const { state, emitted } = load(async () => { throw new Error('must not create a new refund'); }, async (id) => { retried.push(id); return { data: { refunded_amount: 24.18, cash_amount: 24.18 } }; });
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 }, pending_refund: { id: 5, total: 24.18 } });
    state.toggleLine(1);
    state.setReason('size');
    assert.equal(state.validationError(), 'Hay una devolución pendiente de confirmar: reinténtala primero');
    await state.retryPending();
    assert.deepEqual(retried, [5]);
    assert.match(emitted.at(-1)[1], /24\.18 € en efectivo/);
    assert.equal(state.selectedOrder.value, null);
}

async function testCreditNotePdfOpensWhenNotPrinted() {
    const opened = [];
    const { state, emitted } = load(async () => ({ data: { refund_id: 9, credit_note_number: 'R2026-000009', refunded_amount: 24.18, cash_amount: 24.18, print_status: 'pdf' } }), undefined, opened);
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    state.toggleLine(0);
    state.setReason('size');
    await state.confirmRefund();
    assert.deepEqual(opened, [9]);
    assert.match(emitted.at(-1)[1], /^Abono R2026-000009: 24\.18 €/);

    const printed = [];
    const second = load(async () => ({ data: { refund_id: 10, print_status: 'printed' } }), undefined, printed);
    second.state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    second.state.toggleLine(0);
    second.state.setReason('size');
    await second.state.confirmRefund();
    assert.deepEqual(printed, [], 'no PDF when the printer printed it');

    const none = [];
    const third = load(async () => ({ data: { refund_id: 11, print_status: 'none', saga_error: true } }), undefined, none);
    third.state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    third.state.toggleLine(0);
    third.state.setReason('size');
    await third.state.confirmRefund();
    assert.deepEqual(none, [], 'no PDF while the refund is incomplete');
}


async function testScanningTheQrResolvesTheTokenAndOpensTheOrder() {
    const token = jwt({ order_id: 42, reference: 'REF42' });
    const t = loadWithScanner({
        ticketVerify: async () => ({ data: { order_id: 42, reference: 'REF42' } }),
        searchOrders: async (q) => ({ data: q === '42' ? [ORDER] : [] }),
    });
    assert.ok(t.scanner.cb, 'scanner registered when the screen is mounted');

    await t.scan('https://tpv.example/api/ticket/verify?token=' + encodeURIComponent(token));

    assert.deepEqual(t.verified, [token]);
    assert.deepEqual(t.searches, ['42'], 'searches by the order id of the token');
    assert.equal(t.state.selectedOrder.value.ps_order_id, 42, 'opens the order directly');
    assert.equal(t.state.searchQuery.value, 'REF42');

    t.hooks.unmounted.forEach((fn) => fn());
    assert.equal(t.scanner.cb, null, 'scanner released when the screen is unmounted');
}

async function testExpiredQrTokenStillFindsTheOrderFromItsClaims() {
    const token = jwt({ order_id: 42, reference: 'REF42', exp: 1 });
    const t = loadWithScanner({
        ticketVerify: async () => { throw new Error('HTTP 401: {"error":"Invalid or expired token"}'); },
        searchOrders: async (q) => ({ data: q === '42' ? [ORDER] : [] }),
    });

    await t.scan('http://tpv/api/ticket/verify?token=' + token);

    assert.deepEqual(t.searches, ['42']);
    assert.equal(t.state.selectedOrder.value.ps_order_id, 42);
}

async function testUnreadableQrShowsAnErrorAndSearchesNothing() {
    const t = loadWithScanner({
        ticketVerify: async () => { throw new Error('HTTP 401'); },
        searchOrders: async () => ({ data: [ORDER] }),
    });

    await t.scan('http://tpv/api/ticket/verify?token=garbage');

    assert.deepEqual(t.searches, []);
    assert.equal(t.state.selectedOrder.value, null);
    assert.equal(t.emitted.at(-1)[1], 'No se reconoce el código del ticket');
}

async function testScanningAPlainReferenceWithOneMatchOpensIt() {
    const t = loadWithScanner({ ticketVerify: async () => { throw new Error('unused'); }, searchOrders: async () => ({ data: [ORDER] }) });

    await t.scan('REF42');

    assert.deepEqual(t.verified, []);
    assert.deepEqual(t.searches, ['REF42']);
    assert.equal(t.state.searchQuery.value, 'REF42');
    assert.equal(t.state.selectedOrder.value.ps_order_id, 42);
}

async function testSeveralMatchesShowTheListWithoutOpeningAny() {
    const other = { ...ORDER, ps_order_id: 43 };
    const t = loadWithScanner({ ticketVerify: async () => ({}), searchOrders: async () => ({ data: [ORDER, other] }) });

    await t.scan('REF4');

    assert.equal(t.state.searchResults.value.length, 2);
    assert.equal(t.state.selectedOrder.value, null);
}

async function testEnterInTheSearchBoxAcceptsAPastedQrPayload() {
    const t = loadWithScanner({
        ticketVerify: async () => ({ data: { order_id: 42, reference: 'REF42' } }),
        searchOrders: async (q) => ({ data: q === '42' ? [ORDER] : [] }),
    });
    t.state.searchQuery.value = 'http://tpv/api/ticket/verify?token=' + jwt({ order_id: 42 });

    await t.state.handleSearch();

    assert.equal(t.state.selectedOrder.value.ps_order_id, 42);
}


async function testScanIntoAFocusedFieldIsLeftToThatField() {
    const t = loadWithScanner({ ticketVerify: async () => ({}), searchOrders: async () => ({ data: [ORDER] }) });
    t.doc.activeElement = { tagName: 'INPUT' };

    await t.scan('REF42');

    assert.deepEqual(t.searches, [], 'the focused input gets the keystrokes and its own Enter handles them');
}

async function testExpiredTicketTokenDoesNotLogTheCashierOut() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/api.js'), 'utf8');
    const location = { href: '' };
    const context = {
        window: { TPV_BASE: '', addEventListener: () => {}, location },
        navigator: { onLine: true },
        fetch: async () => ({ ok: false, status: 401, text: async () => '' }),
        Promise, Error, JSON, URLSearchParams, encodeURIComponent, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });

    await assert.rejects(() => context.__api.ticketVerify('expired.jwt'), /Unauthorized/);
    assert.equal(location.href, '', 'a rejected ticket token is not a lost session');

    await assert.rejects(() => context.__api.searchOrders('REF42'), /Unauthorized/);
    assert.equal(location.href, '/app/login', 'other 401s still go to the login screen');
}

const CAMISA = { id: 3, name: 'Camisa', price: 24.2, has_combinations: true };
const CAMISA_DETAIL = { id: 3, name: 'Camisa', price: 24.2, combinations: [{ id: 7, price_impact: 6.05, attributes: [{ group_name: 'Talla', attribute_name: 'M' }] }] };
const CALCETINES = { id: 4, name: 'Calcetines', price: 12.5, has_combinations: false };

/** Loads the screen in "Sin ticket" mode with a fake catalog and refund API. */
async function loadNoTicket(refundNoTicket, extraApi = {}) {
    const searched = [];
    const opened = [];
    const loaded = load(async () => { throw new Error('refunds with ticket are not used here'); }, async (id) => ({ data: { refund_id: id, refunded_amount: 12.5, cash_amount: 12.5 } }), opened, {
        api: {
            searchCatalog: async (q) => { searched.push(q); return { data: [CAMISA, CALCETINES] }; },
            catalogProduct: async (id) => ({ data: id === 3 ? CAMISA_DETAIL : CALCETINES }),
            refundNoTicket,
            refundPendingNoTicket: async () => ({ data: [] }),
            ...extraApi,
        },
    });
    await loaded.state.openNoTicket();
    return { ...loaded, searched, opened };
}

async function testNoTicketPicksProductsAndCombinationsAndSendsOnlyIdsQuantitiesRestockAndMethod() {
    const calls = [];
    const t = await loadNoTicket(async (request) => { calls.push(request); return { data: { refund_id: 21, credit_note_number: 'R2026-000021', refunded_amount: 55.25, cash_amount: 55.25, print_status: 'pdf' } }; });

    t.state.productQuery.value = 'cam';
    await t.state.searchProducts();
    assert.deepEqual(t.searched, ['cam'], 'reuses the catalog search API');
    assert.equal(t.state.productResults.value.length, 2);

    await t.state.pickProduct(CAMISA);
    assert.equal(t.state.combinationChoice.value.id, 3, 'a product with combinations asks which one');
    t.state.pickCombination(CAMISA_DETAIL.combinations[0]);
    assert.equal(t.state.combinationChoice.value, null);
    await t.state.pickProduct(CALCETINES);
    await t.state.pickProduct(CALCETINES);
    assert.equal(t.state.noTicketLines.value.length, 2, 'picking the same product again adds a unit');
    assert.equal(t.state.noTicketLines.value[0].name, 'Camisa (Talla: M)');
    assert.equal(t.state.noTicketLines.value[1].quantity, 2);
    assert.equal(t.state.noTicketTotal(), 55.25, 'estimate at the current price');

    assert.equal(t.state.validationError(), 'Elige el motivo de la devolución');
    t.state.setReason('defective');
    assert.equal(t.state.noTicketLines.value[0].restock, false, 'defective unticks restock by default');
    t.state.noTicketLines.value[1].restock = true;
    assert.equal(t.state.validationError(), 'Elige cómo se devuelve: efectivo, tarjeta o vale');
    t.state.setMethod('cash');
    assert.equal(t.state.validationError(), '');

    await t.state.confirmRefund();
    assert.deepEqual(JSON.parse(JSON.stringify(calls)), [{
        lines: [
            { product_id: 3, id_product_attribute: 7, quantity: 1, restock: false },
            { product_id: 4, id_product_attribute: 0, quantity: 2, restock: true },
        ],
        method: 'cash', reason_code: 'defective', reason_text: '', card_confirmed: false,
    }], 'no price leaves the browser');
    assert.deepEqual(t.opened, [21], 'the credit note PDF opens when not printed');
    assert.match(t.emitted.at(-1)[1], /^Abono R2026-000021: 55\.25 € · 55\.25 € en efectivo/);
    assert.equal(t.state.noTicketLines.value.length, 0, 'ready for the next customer');
}

async function testNoTicketCardNeedsConfirmationAndVoucherShowsTheCode() {
    const calls = [];
    const t = await loadNoTicket(async (request) => { calls.push(request); return { data: { refund_id: 22, refunded_amount: 25, voucher_code: 'VALE2345', print_status: 'printed' } }; });
    await t.state.pickProduct(CALCETINES);
    await t.state.pickProduct(CALCETINES);
    t.state.setReason('size');

    t.state.setMethod('card');
    assert.equal(t.state.needsCardConfirmation(), true);
    assert.equal(t.state.validationError(), 'Confirma que has hecho la devolución en el datáfono');
    t.state.cardConfirmed.value = true;
    assert.equal(t.state.validationError(), '');

    t.state.setMethod('voucher');
    assert.equal(t.state.needsCardConfirmation(), false, 'a voucher needs no card confirmation');
    assert.equal(t.state.cardConfirmed.value, false, 'changing the method clears the card confirmation');
    await t.state.confirmRefund();

    assert.equal(calls[0].method, 'voucher');
    assert.equal(calls[0].card_confirmed, false);
    assert.equal(t.state.lastVoucher.value.code, 'VALE2345');
    assert.match(t.emitted.at(-1)[1], /Vale VALE2345/);
}

async function testNoTicketQuantityRemoveAndOtherReason() {
    const t = await loadNoTicket(async () => ({ data: {} }));
    assert.equal(t.state.validationError(), 'Añade al menos un producto');
    await t.state.pickProduct(CALCETINES);
    t.state.updateNoTicketQty(0, '0');
    assert.equal(t.state.noTicketLines.value[0].quantity, 1, 'at least one unit');
    t.state.updateNoTicketQty(0, '3');
    assert.equal(t.state.noTicketLines.value[0].quantity, 3);
    t.state.setReason('other');
    t.state.setMethod('cash');
    assert.equal(t.state.validationError(), 'Escribe el motivo');
    t.state.removeNoTicketLine(0);
    assert.equal(t.state.noTicketLines.value.length, 0);
}

async function testNoTicketPendingRefundsAreShownAndCanBeRetried() {
    const retried = [];
    const pending = [{ id: 30, total: 12.5, method: 'cash', created_at: '2026-11-03 10:00:00', error: null, voucher_unknown: false }];
    let pendingCalls = 0;
    const t = await loadNoTicket(async () => { throw new Error('HTTP 502: {"error":"PrestaShop refund service unavailable: timeout","retry":true}'); }, {
        refundPendingNoTicket: async () => { pendingCalls += 1; return { data: pendingCalls === 1 ? pending : [] }; },
    });
    assert.equal(t.state.pendingNoTicket.value[0].id, 30, 'pending refunds without ticket are listed on entry');

    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');
    assert.match(source, /Sin ticket/);

    const r = load(async () => ({ data: {} }), async (id) => { retried.push(id); return { data: { refund_id: id, refunded_amount: 12.5, cash_amount: 12.5 } }; }, [], {
        api: { refundPendingNoTicket: async () => ({ data: pending }), refundNoTicket: async () => ({ data: {} }) },
    });
    await r.state.openNoTicket();
    await r.state.retryNoTicket(30);
    assert.deepEqual(retried, [30]);
    assert.match(r.emitted.at(-1)[1], /12\.50 € en efectivo/);

    await t.state.pickProduct(CALCETINES);
    t.state.setReason('size');
    t.state.setMethod('cash');
    await t.state.confirmRefund();
    assert.match(t.emitted.at(-1)[1], /^PrestaShop no ha confirmado la devolución\. No entregues dinero/);
    assert.equal(t.state.noTicketLines.value.length, 1, 'the lines stay after an error');
}

async function testNoTicketApiCallsTheNewEndpoints() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/api.js'), 'utf8');
    const requests = [];
    const context = {
        window: { TPV_BASE: '', addEventListener: () => {}, location: { href: '' } },
        navigator: { onLine: true },
        fetch: async (url, opts) => { requests.push([url, opts.method, opts.body]); return { ok: true, status: 200, json: async () => ({ data: [] }) }; },
        Promise, Error, JSON, URLSearchParams, encodeURIComponent, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });

    await context.__api.refundNoTicket({ method: 'cash', lines: [] });
    await context.__api.refundPendingNoTicket();
    await context.__api.searchCatalog('cam isa');
    await context.__api.catalogProduct(3);

    assert.deepEqual(requests.map((r) => [r[0], r[1]]), [
        ['/api/refund/no-ticket', 'POST'],
        ['/api/refund/pending-no-ticket', 'GET'],
        ['/api/catalog/search?q=cam+isa&limit=20', 'GET'],
        ['/api/catalog/product/3', 'GET'],
    ]);
    assert.deepEqual(JSON.parse(requests[0][2]), { method: 'cash', lines: [] });
}


const PANTALON_ORDER = {
    ps_order_id: 42, reference: 'REF42', payment_method: 'cash', tender: { cash: 40, card: 0 },
    lines: [{ name: 'Pantalón', product_id: 5, id_product_attribute: 0, qty: 1, paid_total: 40, refundable_qty: 1 }],
};
const CHAQUETA = { id: 6, name: 'Chaqueta', price: 55, has_combinations: false };
const CINTURON = { id: 7, name: 'Cinturón', price: 25, has_combinations: false };

/** Loads the screen on a selected order in exchange mode, with a fake catalog and exchange API. */
function loadExchange(order, exchangeOrder, extraApi = {}) {
    const opened = [];
    const loaded = load(async () => { throw new Error('a plain refund must not be sent'); }, undefined, opened, {
        api: {
            searchCatalog: async () => ({ data: [CHAQUETA, CINTURON] }),
            catalogProduct: async () => ({ data: null }),
            exchangeOrder,
            ...extraApi,
        },
    });
    loaded.state.selectOrder(order);
    loaded.state.startExchange();
    return { ...loaded, opened };
}

async function testExchangeFortyForFiftyFiveCollectsTheBalanceAndShowsBothDocuments() {
    const calls = [];
    const t = loadExchange(PANTALON_ORDER, async (id, request) => {
        calls.push([id, request]);
        return { data: { exchange_id: 3, status: 'done', credit: 40, new_total: 55, balance: 15,
            refund: { refund_id: 9, credit_note_number: 'R2026-000009', cash_amount: 0, card_amount: 0, print_status: 'pdf' },
            sale: { ps_order_id: 990, reference: 'NEWREF', payable: 15, change_due: 5, print_status: 'dispatched' } } };
    });

    assert.equal(t.state.exchangeMode.value, true);
    assert.equal(t.state.validationError(), 'Selecciona al menos una línea a devolver');
    t.state.toggleLine(0);
    assert.equal(t.state.validationError(), 'Añade al menos un artículo nuevo');
    t.state.productQuery.value = 'cha';
    await t.state.searchProducts();
    assert.equal(t.state.productResults.value.length, 2, 'reuses the catalog search');
    await t.state.pickProduct(CHAQUETA);
    assert.equal(t.state.exchangeLines.value.length, 1, 'picked items go to the exchange, not to a refund without ticket');
    assert.equal(t.state.noTicketLines.value.length, 0);
    assert.equal(t.state.exchangeCredit(), 40);
    assert.equal(t.state.exchangeNewTotal(), 55);
    assert.equal(t.state.exchangeBalance(), 15);

    assert.equal(t.state.validationError(), 'Elige el motivo de la devolución');
    t.state.setReason('size');
    assert.equal(t.state.validationError(), 'Elige cómo paga la diferencia');
    t.state.setExchangePayment('cash');
    t.state.exchangeTendered.value = '10';
    assert.equal(t.state.validationError(), 'El efectivo entregado no cubre la diferencia');
    t.state.exchangeTendered.value = '20';
    assert.equal(t.state.needsCardConfirmation(), false);
    assert.equal(t.state.validationError(), '');

    await t.state.confirmRefund();
    assert.deepEqual(JSON.parse(JSON.stringify(calls)), [[42, {
        return_lines: [{ line_index: 0, quantity: 1, restock: true }],
        reason_code: 'size', reason_text: '',
        new_cart_data: { lines: [{ product_id: 6, id_product_attribute: 0, qty: 1, price: 55, name: 'Chaqueta' }], total: 55 },
        payment_method: 'cash', amount_tendered: 20, card_confirmed: false,
    }]]);
    assert.deepEqual(t.opened, [9], 'the credit note PDF opens when not printed');
    assert.deepEqual(JSON.parse(JSON.stringify(t.state.lastExchange.value)), {
        creditNote: 'R2026-000009', saleOrderId: 990, saleReference: 'NEWREF', balance: 15, changeDue: 5, cashBack: 0, cardBack: 0,
    });
    assert.match(t.emitted.at(-1)[1], /Abono R2026-000009/);
    assert.match(t.emitted.at(-1)[1], /NEWREF/);
    assert.match(t.emitted.at(-1)[1], /cobrar 15\.00 €/);
    assert.equal(t.state.selectedOrder.value, null, 'ready for the next customer');
    assert.equal(t.state.exchangeMode.value, false);
}

async function testExchangeFiftyFiveForFortyRefundsTheDifferenceByTheOriginalCard() {
    const calls = [];
    const order = { ...PANTALON_ORDER, payment_method: 'card', tender: { cash: 0, card: 55 }, lines: [{ ...PANTALON_ORDER.lines[0], name: 'Chaqueta', paid_total: 55 }] };
    const t = loadExchange(order, async (id, request) => { calls.push(request); return { data: { exchange_id: 4, status: 'done', balance: -15, refund: { refund_id: 10, credit_note_number: 'R2026-000010', cash_amount: 0, card_amount: 15, print_status: 'printed' }, sale: { ps_order_id: 991, reference: 'NEW2', payable: 0, change_due: 0 } } }; });
    t.state.toggleLine(0);
    await t.state.pickProduct({ id: 5, name: 'Pantalón', price: 40, has_combinations: false });
    t.state.setReason('size');
    assert.equal(t.state.exchangeBalance(), -15);
    assert.deepEqual(JSON.parse(JSON.stringify(t.state.exchangePayoutSplit())), { cash: 0, card: 15 });
    assert.equal(t.state.needsCardConfirmation(), true);
    assert.equal(t.state.validationError(), 'Confirma que has hecho la devolución en el datáfono');
    t.state.cardConfirmed.value = true;
    assert.equal(t.state.validationError(), '', 'no payment is collected when the shop owes the difference');

    await t.state.confirmRefund();
    assert.equal(calls[0].payment_method, '');
    assert.equal(calls[0].amount_tendered, 0);
    assert.equal(calls[0].card_confirmed, true);
    assert.deepEqual(t.opened, [], 'printed: no PDF');
    assert.match(t.emitted.at(-1)[1], /15\.00 € a tarjeta/);
}

async function testMixedBalanceSendsTheCashPart() {
    const calls = [];
    const t = loadExchange(PANTALON_ORDER, async (id, request) => { calls.push(request); return { data: { status: 'done', refund: {}, sale: {} } }; });
    t.state.toggleLine(0);
    await t.state.pickProduct(CHAQUETA);
    t.state.setReason('size');
    t.state.setExchangePayment('mixed');
    t.state.exchangeCash.value = '15';
    assert.equal(t.state.validationError(), 'El efectivo del pago mixto debe ser menor que la diferencia');
    t.state.exchangeCash.value = '5';
    assert.equal(t.state.validationError(), '');
    await t.state.confirmRefund();
    assert.equal(calls[0].payment_method, 'mixed');
    assert.equal(calls[0].cash_amount, 5);
    assert.equal(calls[0].amount_tendered, 5);
}

async function testFailedNewSaleKeepsTheCreditOpenAndTheExchangeCanBeRetried() {
    const retried = [];
    const t = loadExchange(PANTALON_ORDER, async () => ({ data: { exchange_id: 5, status: 'credit_open', credit: 40, new_total: 55, balance: 15,
        refund: { refund_id: 11, credit_note_number: 'R2026-000011', cash_amount: 0, print_status: 'printed' }, sale: null, sale_error: 'timeout', sale_outcome_unknown: true } }), {
        exchangeRetry: async (id) => { retried.push(id); return { data: { exchange_id: id, status: 'done', balance: 15, refund: { refund_id: 11, print_status: 'none' }, sale: { ps_order_id: 992, reference: 'NEW3', payable: 15, change_due: 0 } } }; },
    });
    t.state.toggleLine(0);
    await t.state.pickProduct(CHAQUETA);
    t.state.setReason('size');
    t.state.setExchangePayment('card');

    await t.state.confirmRefund();
    assert.match(t.emitted.at(-1)[1], /^Abono R2026-000011 emitido, pero la venta nueva no se ha creado/);
    assert.match(t.emitted.at(-1)[1], /comprueba en PrestaShop/);
    assert.ok(t.state.selectedOrder.value, 'stays on the order');
    assert.equal(t.state.selectedOrder.value.open_exchange.id, 5);
    assert.equal(t.state.exchangeMode.value, false);
    assert.equal(t.state.validationError(), 'Hay un cambio pendiente: complétalo primero');

    await t.state.retryExchange();
    assert.deepEqual(retried, [5]);
    assert.match(t.emitted.at(-1)[1], /NEW3/);
    assert.equal(t.state.lastExchange.value.saleReference, 'NEW3');
    assert.equal(t.state.selectedOrder.value, null);
}

async function testOrderWithAnOpenExchangeOffersOnlyItsRetry() {
    const t = loadExchange({ ...PANTALON_ORDER, pending_refund: { id: 7, total: 40 }, open_exchange: { id: 6, status: 'pending', credit_applied: 40, new_total: 55, balance: 15, error: null } }, async () => { throw new Error('no new exchange'); });
    t.state.toggleLine(0);
    await t.state.pickProduct(CHAQUETA);
    t.state.setReason('size');
    t.state.setExchangePayment('card');
    assert.equal(t.state.validationError(), 'Hay un cambio pendiente: complétalo primero');
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');
    assert.match(source, /selectedOrder\.pending_refund && !selectedOrder\.open_exchange/, 'the refund retry banner gives way to the exchange one');
    assert.match(source, />Cambiar</);
}

async function testExchangeApiCallsAreNeverQueuedOffline() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/api.js'), 'utf8');
    const requests = [];
    const context = {
        window: { TPV_BASE: '', addEventListener: () => {}, location: { href: '' } },
        navigator: { onLine: true },
        fetch: async (url, opts) => { requests.push([url, opts.method, opts.body]); return { ok: true, status: 200, json: async () => ({ data: {} }) }; },
        Promise, Error, JSON, URLSearchParams, encodeURIComponent, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });

    await context.__api.exchangeOrder(42, { return_lines: [] });
    await context.__api.exchangeRetry(5);
    await context.__api.exchangeVoucher(5);
    assert.deepEqual(requests.map((r) => [r[0], r[1]]), [['/api/exchange', 'POST'], ['/api/exchange/5/retry', 'POST'], ['/api/exchange/5/voucher', 'POST']]);
    assert.deepEqual(JSON.parse(requests[0][2]), { return_lines: [], ps_order_id: 42 });

    const offline = { ...context, navigator: { onLine: false }, fetch: async () => { throw new Error('must not be sent'); } };
    offline.globalThis = offline;
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, offline, { filename: 'api.js' });
    await assert.rejects(() => offline.__api.exchangeOrder(42, {}), /not sent or queued/);
    await assert.rejects(() => offline.__api.exchangeVoucher(5), /not sent or queued/);
}

async function testRefundOfASalePaidWithExchangeCreditShowsTheVoucherPart() {
    const { state } = load(async () => ({ data: {} }));
    state.selectOrder({ ...PANTALON_ORDER, payment_method: 'exchange', tender: { cash: 15, card: 0, exchange_credit: 40 },
        lines: [{ name: 'Chaqueta', product_id: 6, id_product_attribute: 0, qty: 1, paid_total: 55, refundable_qty: 1 }] });
    state.toggleLine(0);
    // Same three-way rule as the server: 55 x 15/55 in cash, the credit share as a voucher.
    assert.deepEqual(JSON.parse(JSON.stringify(state.estimatedSplit())), { cash: 15, card: 0, voucher: 40 });
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');
    assert.match(source, /En vale \(PrestaShop\): \{\{ estimatedSplit\(\)\.voucher\.toFixed\(2\) \}\} €/);
}

async function testCreditOpenExchangeCanBeClosedWithAVoucherAfterConfirming() {
    const closed = [];
    const t = loadExchange({ ...PANTALON_ORDER, open_exchange: { id: 6, status: 'credit_open', credit_applied: 40, new_total: 55, balance: 15, error: 'Product not found' } }, async () => { throw new Error('no new exchange'); }, {
        exchangeVoucher: async (id) => { closed.push(id); return { data: { exchange_id: id, status: 'closed_voucher', voucher_code: 'CAMB1XYZ', voucher_amount: 40 } }; },
    });

    t.state.closeExchangeWithVoucher();
    assert.deepEqual(closed, [], 'asks before issuing the voucher');
    assert.equal(t.state.exchangeVoucherConfirm.value, true);

    await t.state.confirmExchangeVoucher();
    assert.deepEqual(closed, [6]);
    assert.match(t.emitted.at(-1)[1], /Cambio #6 cerrado con vale CAMB1XYZ de 40\.00 €/);
    assert.equal(t.emitted.at(-1)[2], 'var(--green)');
    assert.deepEqual(JSON.parse(JSON.stringify(t.state.exchangeVoucher.value)), { code: 'CAMB1XYZ', amount: 40 });
    assert.equal(t.state.selectedOrder.value.open_exchange, null);
    assert.equal(t.state.exchangeVoucherConfirm.value, false);

    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');
    assert.match(source, /v-if="selectedOrder\.open_exchange\.status === 'credit_open'" @click="closeExchangeWithVoucher"[^>]*>Cerrar con vale</);
}

async function testVoucherCloseErrorsKeepTheOpenCreditAndPendingExchangesCannotBeClosed() {
    const closed = [];
    const t = loadExchange({ ...PANTALON_ORDER, open_exchange: { id: 6, status: 'credit_open', credit_applied: 40, new_total: 55, balance: 15, error: null } }, async () => { throw new Error('no new exchange'); }, {
        exchangeVoucher: async (id) => { closed.push(id); throw new Error('HTTP 409: {"error":"a voucher is never created twice automatically"}'); },
    });
    t.state.closeExchangeWithVoucher();
    await t.state.confirmExchangeVoucher();
    assert.deepEqual(closed, [6]);
    assert.match(t.emitted.at(-1)[1], /never created twice automatically/);
    assert.equal(t.state.selectedOrder.value.open_exchange.id, 6, 'the banner stays');
    assert.equal(t.state.exchangeVoucher.value, null);

    const pending = loadExchange({ ...PANTALON_ORDER, open_exchange: { id: 7, status: 'pending', credit_applied: 40, new_total: 55, balance: 15, error: null } }, async () => { throw new Error('no new exchange'); }, {
        exchangeVoucher: async () => { throw new Error('must not close a pending exchange'); },
    });
    pending.state.closeExchangeWithVoucher();
    assert.equal(pending.state.exchangeVoucherConfirm.value, false);
}

(async () => {
    await testExchangeApiCallsAreNeverQueuedOffline();
    await testExchangeFortyForFiftyFiveCollectsTheBalanceAndShowsBothDocuments();
    await testExchangeFiftyFiveForFortyRefundsTheDifferenceByTheOriginalCard();
    await testMixedBalanceSendsTheCashPart();
    await testFailedNewSaleKeepsTheCreditOpenAndTheExchangeCanBeRetried();
    await testOrderWithAnOpenExchangeOffersOnlyItsRetry();
    await testRefundOfASalePaidWithExchangeCreditShowsTheVoucherPart();
    await testCreditOpenExchangeCanBeClosedWithAVoucherAfterConfirming();
    await testVoucherCloseErrorsKeepTheOpenCreditAndPendingExchangesCannotBeClosed();
    await testNoTicketApiCallsTheNewEndpoints();
    await testNoTicketPicksProductsAndCombinationsAndSendsOnlyIdsQuantitiesRestockAndMethod();
    await testNoTicketCardNeedsConfirmationAndVoucherShowsTheCode();
    await testNoTicketQuantityRemoveAndOtherReason();
    await testNoTicketPendingRefundsAreShownAndCanBeRetried();
    await testExpiredTicketTokenDoesNotLogTheCashierOut();
    await testScanIntoAFocusedFieldIsLeftToThatField();
    await testScanningTheQrResolvesTheTokenAndOpensTheOrder();
    await testExpiredQrTokenStillFindsTheOrderFromItsClaims();
    await testUnreadableQrShowsAnErrorAndSearchesNothing();
    await testScanningAPlainReferenceWithOneMatchOpensIt();
    await testSeveralMatchesShowTheListWithoutOpeningAny();
    await testEnterInTheSearchBoxAcceptsAPastedQrPayload();
    await testCreditNotePdfOpensWhenNotPrinted();
    await testPendingRefundBlocksNewOnesAndCanBeRetried();
    await testRequestCarriesOnlyIndexesQuantitiesRestockReasonAndConfirmation();
    await testOtherReasonNeedsTextAndQuantityIsCappedAtRefundable();
    await testServerErrorsAreShownInSpanishWithDetail();
    console.log('refundScreen tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
