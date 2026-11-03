/**
 * docs/prd/agente-impresion.md C1-C3 on the payment screen of an agent till: the
 * server queued the ticket in the checkout (T0), so the screen follows the job
 * instead of asking for a reprint, and stays until it is sent or the cashier moves
 * on; other printers keep the 2-second close and their reprint call.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function load(printerType, checkoutData) {
    const root = path.resolve(__dirname, '../../public/assets/js');
    const clock = makeClock();
    const jobs = new Map();
    const calls = [];
    const api = {
        checkout: async () => ({ data: checkoutData }),
        couponCheckout: async () => ({ data: checkoutData }),
        couponPreview: async () => ({}),
        printJob: async (id) => { calls.push(['printJob', id]); return { ...(jobs.get(id) || { status: 'pending', claimed_at: null }) }; },
        ticketReprintSale: async (id) => { calls.push(['ticketReprintSale', id]); return { data: { printed: true } }; },
        ticketReprint: async (id) => { calls.push(['ticketReprint', id]); return { data: { printed: true } }; },
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
    return { state, emitted, calls, jobs, clock, store: context.__jobs };
}

const completes = (emitted) => emitted.filter(([name]) => name === 'complete');

async function pay(loaded, cash = '70') {
    loaded.state.method.value = 'cash';
    loaded.state.cashInput.value = cash;
    await loaded.state.handleConfirm();
}

async function testAgentSaleFollowsTheQueuedJobAndClosesWhenSent() {
    const loaded = load('agent', { order_id: 5, ps_order_id: null, total: 61.92, change_due: 8.08, print_status: 'queued', print_job_id: 10, drawer_job_id: 11 });
    await pay(loaded);
    assert.equal(loaded.state.step.value, 'done');
    assert.deepEqual(loaded.calls.filter((c) => c[0] !== 'printJob'), [], 'the server already queued the ticket: no reprint');
    assert.equal(loaded.store.ticketMessage(loaded.store.ticket.job), 'Imprimiendo ticket…');
    assert.equal(loaded.store.drawer.job.change, 8.08, 'the drawer warning goes next to the change');
    await loaded.clock.advance(3000);
    assert.equal(completes(loaded.emitted).length, 0, 'stays while the ticket is printing');
    loaded.jobs.set(10, { status: 'done', claimed_at: 'x' });
    loaded.jobs.set(11, { status: 'done', claimed_at: 'x' });
    await loaded.clock.advance(1000);
    assert.equal(loaded.store.ticketMessage(loaded.store.ticket.job), 'Ticket enviado');
    await loaded.clock.advance(2500);
    assert.equal(completes(loaded.emitted).length, 1);
    assert.equal(completes(loaded.emitted)[0][1].order_id, 5);
}

async function testAgentProblemWaitsForTheCashierAndKeepsTheWarning() {
    const loaded = load('agent', { order_id: 6, total: 61.92, change_due: 8.08, print_status: 'queued', print_job_id: 12, drawer_job_id: 13 });
    await pay(loaded);
    await loaded.clock.advance(5000);
    assert.equal(loaded.state.drawerWarning.value, 'El cajón no se ha abierto. Ábrelo con la llave.');
    await loaded.clock.advance(10000);
    assert.equal(loaded.store.ticketMessage(loaded.store.ticket.job), 'La impresora no está conectada');
    await loaded.clock.advance(30000);
    assert.equal(completes(loaded.emitted).length, 0, 'the cashier sees the problem before the screen closes');
    loaded.state.finish();
    loaded.state.finish();
    assert.equal(completes(loaded.emitted).length, 1, 'one sale, completed once');
    assert.equal(loaded.store.ticket.job.problem, true, 'C2: the warning stays for the sale screen');
}

async function testOtherPrintersKeepTheirReprintAndTwoSecondClose() {
    const loaded = load('escpos', { order_id: 7, total: 61.92, change_due: 8.08, print_status: 'dispatched' });
    await pay(loaded);
    assert.deepEqual(loaded.calls, [['ticketReprintSale', 7]]);
    await loaded.clock.advance(2000);
    assert.equal(completes(loaded.emitted).length, 1);
    assert.equal(loaded.store.ticket.job, null, 'nothing is followed');
    assert.equal(loaded.state.drawerWarning.value, '');
}

(async () => {
    await testAgentSaleFollowsTheQueuedJobAndClosesWhenSent();
    await testAgentProblemWaitsForTheCashierAndKeepsTheWarning();
    await testOtherPrintersKeepTheirReprintAndTwoSecondClose();
    console.log('paymentPrintAgent: ok');
})().catch((err) => { console.error(err); process.exit(1); });
