// Local-first sale (PRD V2): the ticket of a sale not uploaded to PrestaShop yet is
// printed, downloaded and reprinted by its local order_id; a response with only a
// PrestaShop ps_order_id keeps the previous calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function apiSpy(checkoutData) {
    const calls = [];
    const api = {
        checkout: async () => ({ data: checkoutData }),
        couponCheckout: async () => ({ data: checkoutData }),
        couponPreview: async () => ({}),
        ticketPdf: (id) => { calls.push(['ticketPdf', id]); },
        ticketSalePdf: (id) => { calls.push(['ticketSalePdf', id]); },
        ticketReprint: async (id) => { calls.push(['ticketReprint', id]); return { data: { printed: true } }; },
        ticketReprintSale: async (id) => { calls.push(['ticketReprintSale', id]); return { data: { printed: true } }; },
    };
    return { api, calls };
}

function loadPayment(api, printerType) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js'), 'utf8');
    const cart = { lines: [{ id: 1, product_id: 1, qty: 1, price: 10 }], subtotal: 10, discount: null, discount_amount: 0, total: 10, iva: 1.74, customer: null };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart },
        useSession: { session: { printerType }, hydrateCashSession: async () => {} },
        ApiClient: api,
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const emitted = [];
    const state = context.__payment.setup({ total: 10 }, { emit: (name, value) => emitted.push([name, value]) });
    return { state, emitted };
}

async function pay(state) {
    state.method.value = 'card';
    await state.handleConfirm();
}

async function testUnsyncedSalePrintsByLocalIdOnEscPos() {
    const { api, calls } = apiSpy({ order_id: 15, ps_order_id: null, reference: 'A26000001', total: 10, change_due: 0 });
    const { state, emitted } = loadPayment(api, 'escpos');
    await pay(state);
    assert.deepEqual(calls, [['ticketReprintSale', 15]]);
    assert.equal(state.step.value, 'done');
    assert.equal(emitted[0][1].order_id, 15, 'the sale screen receives the local id for its reprint button');
}

async function testUnsyncedSaleDownloadsItsPdfByLocalId() {
    const { api, calls } = apiSpy({ order_id: 16, ps_order_id: null, reference: 'A26000002', total: 10, change_due: 0 });
    const { state } = loadPayment(api, 'pdf');
    await pay(state);
    assert.deepEqual(calls, [], 'without a printer nothing is downloaded on its own');
    state.downloadTicket();
    assert.deepEqual(calls, [['ticketSalePdf', 16]]);
}

// Without a printer the «Cobro completado» screen stays open and lets the cashier
// choose: download the PDF, email, WhatsApp, or «Terminar».
async function testWithoutAPrinterTheDoneScreenWaitsForTheChoice() {
    for (const printerType of ['pdf', 'none']) {
        const { api, calls } = apiSpy({ order_id: 17, ps_order_id: null, reference: 'A26000003', total: 10, change_due: 0 });
        const { state, emitted } = loadPayment(api, printerType);
        await pay(state);
        assert.equal(state.step.value, 'done');
        assert.deepEqual(calls, [], printerType + ': no automatic download');
        assert.deepEqual(emitted, [], printerType + ': the sale is completed only by «Terminar»');
        state.finish();
        assert.equal(emitted.length, 1);
        assert.equal(emitted[0][0], 'complete');
        assert.equal(emitted[0][1].order_id, 17);
    }
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js'), 'utf8');
    assert.ok(!source.includes('Abriendo ticket PDF'), 'the screen no longer announces an automatic PDF');
}

async function testResponseWithOnlyAPrestaShopIdKeepsThePreviousCalls() {
    const { api, calls } = apiSpy({ ps_order_id: 88, reference: 'XKQWERTYU', total: 10, change_due: 0 });
    const { state } = loadPayment(api, 'pdf');
    await pay(state);
    state.downloadTicket();
    assert.deepEqual(calls, [['ticketPdf', 88]]);

    const escpos = apiSpy({ ps_order_id: 89, total: 10, change_due: 0 });
    const loaded = loadPayment(escpos.api, 'system');
    await pay(loaded.state);
    assert.deepEqual(escpos.calls, [['ticketReprint', 89]]);
}

function loadSaleScreen(api) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SaleScreen.js'), 'utf8');
    const match = source.match(/async function handleReprint\(\) \{[\s\S]*?\n        \}\n/);
    assert.ok(match, 'SaleScreen.handleReprint is present');
    const notes = [];
    const context = {
        ApiClient: api, lastCompletedOrder: ref(null), notify: (msg) => notes.push(msg), Promise,
    };
    vm.runInNewContext(`${match[0]}\nglobalThis.__reprint = handleReprint;`, Object.assign(context, { globalThis: context }), { filename: 'SaleScreen.js' });
    return { context, notes };
}

async function testSaleScreenReprintsTheLastSaleByLocalIdOrPrestaShopId() {
    const local = apiSpy({});
    const screen = loadSaleScreen(local.api);
    screen.context.lastCompletedOrder.value = { order_id: 15, ps_order_id: null, reference: 'A26000001' };
    await screen.context.__reprint();
    assert.deepEqual(local.calls, [['ticketReprintSale', 15]]);
    assert.deepEqual(screen.notes, ['Ticket reimpreso']);

    const legacy = apiSpy({});
    const old = loadSaleScreen(legacy.api);
    old.context.lastCompletedOrder.value = { ps_order_id: 42 };
    await old.context.__reprint();
    assert.deepEqual(legacy.calls, [['ticketReprint', 42]]);

    const none = apiSpy({});
    const empty = loadSaleScreen(none.api);
    empty.context.lastCompletedOrder.value = { reference: 'X' };
    await empty.context.__reprint();
    assert.deepEqual(none.calls, []);
    assert.deepEqual(empty.notes, ['No hay ticket para reimprimir']);
}

// Verifier advisory L7: one request_id per payment attempt, the same on every retry
// (and on the replay api.js queues when the network drops), so the server answers
// the sale it already created instead of charging twice.
async function testEveryRetryOfOnePaymentAttemptCarriesTheSameRequestId() {
    const sent = [];
    let failures = 1;
    const api = {
        ...apiSpy({}).api,
        checkout: async (payload) => {
            sent.push(payload);
            if (failures-- > 0) throw new Error('network: Failed to fetch');
            return { data: { order_id: 17, ps_order_id: null, reference: 'A26000017', total: 10, change_due: 0, replayed: true } };
        },
    };
    const { state } = loadPayment(api, 'pdf');
    await pay(state);
    assert.equal(state.step.value, 'error');
    assert.equal(state.errorRetry.value, true, 'a network failure offers the retry');

    await state.retryCheckout();

    assert.equal(sent.length, 2);
    assert.match(sent[0].request_id, /^[A-Za-z0-9_-]{8,64}$/);
    assert.equal(sent[1].request_id, sent[0].request_id, 'the retry is the same attempt');
    assert.equal(state.step.value, 'done');

    const next = loadPayment({ ...api, checkout: async (payload) => { sent.push(payload); return { data: { order_id: 18, total: 10, change_due: 0 } }; } }, 'pdf');
    await pay(next.state);
    assert.notEqual(sent[2].request_id, sent[0].request_id, 'a new payment is a new attempt');
}

(async () => {
    await testEveryRetryOfOnePaymentAttemptCarriesTheSameRequestId();
    await testUnsyncedSalePrintsByLocalIdOnEscPos();
    await testUnsyncedSaleDownloadsItsPdfByLocalId();
    await testWithoutAPrinterTheDoneScreenWaitsForTheChoice();
    await testResponseWithOnlyAPrestaShopIdKeepsThePreviousCalls();
    await testSaleScreenReprintsTheLastSaleByLocalIdOrPrestaShopId();
    console.log('localSaleTicket: OK');
})().catch((err) => { console.error(err); process.exit(1); });
