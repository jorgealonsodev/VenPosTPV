/**
 * «Ticket regalo» on the payment done screen: the sale ticket goes out as before and
 * the cashier can add the gift ticket (no prices). Asking for it keeps the screen
 * open until «Terminar» / «Nueva venta»; without a printer it opens the gift PDF.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function load(printerType, checkoutData, giftData = { printed: true }) {
    const root = path.resolve(__dirname, '../../public/assets/js');
    const clock = makeClock();
    const calls = [];
    const api = {
        checkout: async () => ({ data: checkoutData }),
        couponCheckout: async () => ({ data: checkoutData }),
        couponPreview: async () => ({}),
        get: async () => ({ data: { enabled: false } }),
        printJob: async (id) => ({ status: 'pending', claimed_at: null, id }),
        ticketReprintSale: async (id) => { calls.push(['ticketReprintSale', id]); return { data: { printed: true } }; },
        ticketReprint: async (id) => { calls.push(['ticketReprint', id]); return { data: { printed: true } }; },
        ticketGift: async (saleId, psOrderId) => { calls.push(['ticketGift', saleId, psOrderId]); return { data: giftData }; },
        ticketGiftPdf: (saleId, psOrderId) => calls.push(['ticketGiftPdf', saleId, psOrderId]),
        ticketSalePdf: (id) => calls.push(['ticketSalePdf', id]),
        ticketPdf: (id) => calls.push(['ticketPdf', id]),
    };
    const cart = { lines: [{ id: 1, product_id: 1, qty: 1, price: 61.92 }], subtotal: 61.92, discount: null, discount_amount: 0, total: 61.92, iva: 10.75, customer: null };
    const context = {
        ...sandboxGlobals(clock),
        Vue: { ref, computed, reactive: (v) => v, watch() {} },
        useCart: { cart },
        useSession: { session: { printerType }, hydrateCashSession: async () => {} },
        ApiClient: api,
    };
    context.globalThis = context;
    vm.runInNewContext(fs.readFileSync(path.join(root, 'stores/usePrintJobs.js'), 'utf8') + '\nglobalThis.__jobs = usePrintJobs;', context, { filename: 'usePrintJobs.js' });
    vm.runInNewContext(fs.readFileSync(path.join(root, 'components/PaymentModal.js'), 'utf8') + '\nglobalThis.__payment = PaymentModal;', context, { filename: 'PaymentModal.js' });
    const emitted = [];
    const state = context.__payment.setup({ total: 61.92 }, { emit: (name, value) => emitted.push([name, value]) });
    return { state, emitted, calls, clock };
}

const completes = (emitted) => emitted.filter(([name]) => name === 'complete');

async function pay(loaded) {
    loaded.state.method.value = 'cash';
    loaded.state.cashInput.value = '70';
    await loaded.state.handleConfirm();
}

async function testEscPosPrintsTheSaleTicketThenTheGiftTicketAndWaitsForTheCashier() {
    const loaded = load('escpos', { order_id: 7, total: 61.92, change_due: 8.08 });
    await pay(loaded);
    await loaded.state.printGiftTicket();
    assert.deepEqual(loaded.calls, [['ticketReprintSale', 7], ['ticketGift', 7, null]], 'the sale ticket first, then the gift one');
    assert.equal(loaded.state.giftStatus.value, 'Ticket regalo impreso.');
    await loaded.clock.advance(5000);
    assert.equal(completes(loaded.emitted).length, 0, 'the screen stays for the cashier');
    loaded.state.finish();
    loaded.state.finish();
    assert.equal(completes(loaded.emitted).length, 1, 'one sale, completed once');
}

async function testWithoutTheGiftTicketTheScreenStillClosesInTwoSeconds() {
    const loaded = load('escpos', { order_id: 7, total: 61.92, change_due: 8.08 });
    await pay(loaded);
    await loaded.clock.advance(2000);
    assert.equal(completes(loaded.emitted).length, 1);
    assert.deepEqual(loaded.calls, [['ticketReprintSale', 7]]);
}

async function testAnAgentTillQueuesTheGiftTicketAndStaysOpen() {
    const loaded = load('agent', { order_id: 5, total: 61.92, change_due: 8.08, print_status: 'queued', print_job_id: 10 }, { printed: false, print_status: 'queued', print_job_id: 12 });
    await pay(loaded);
    await loaded.state.printGiftTicket();
    assert.deepEqual(loaded.calls, [['ticketGift', 5, null]]);
    assert.equal(loaded.state.giftStatus.value, 'Ticket regalo enviado a la impresora de la caja.');
    await loaded.clock.advance(10000);
    assert.equal(completes(loaded.emitted).length, 0, 'waits for «Nueva venta»');
}

async function testWithoutPrinterTheGiftTicketOpensAsPdf() {
    const loaded = load('pdf', { order_id: 9, total: 61.92, change_due: 8.08 });
    await pay(loaded);
    await loaded.state.printGiftTicket();
    assert.deepEqual(loaded.calls, [['ticketGiftPdf', 9, null]]);
}

async function testAFailedPrintOpensTheGiftPdf() {
    const loaded = load('escpos', { order_id: 7, total: 61.92, change_due: 8.08 }, { printed: false, fallback: 'pdf' });
    await pay(loaded);
    await loaded.state.printGiftTicket();
    assert.deepEqual(loaded.calls.slice(1), [['ticketGift', 7, null], ['ticketGiftPdf', 7, null]]);
}

(async () => {
    await testEscPosPrintsTheSaleTicketThenTheGiftTicketAndWaitsForTheCashier();
    await testWithoutTheGiftTicketTheScreenStillClosesInTwoSeconds();
    await testAnAgentTillQueuesTheGiftTicketAndStaysOpen();
    await testWithoutPrinterTheGiftTicketOpensAsPdf();
    await testAFailedPrintOpensTheGiftPdf();
    console.log('paymentGiftTicket: ok');
})().catch((err) => { console.error(err); process.exit(1); });
