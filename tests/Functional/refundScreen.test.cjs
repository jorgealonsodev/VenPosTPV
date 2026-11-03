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

(async () => {
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
