/**
 * docs/prd/agente-impresion.md C1-C3 outside the payment screen: a reprint, a
 * credit note and the «Abrir cajón» button of an agent till hand their queued job
 * to usePrintJobs; other printers keep their messages.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

const ROOT = path.resolve(__dirname, '../../public/assets/js');
function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function sandbox(api) {
    const clock = makeClock();
    const tracked = [];
    const context = {
        ...sandboxGlobals(clock),
        Vue: { ref, computed, reactive: (v) => v, watch() {}, onMounted() {}, onUnmounted() {} },
        ApiClient: api,
        useSession: { session: { online: true }, logout() {} },
        window: { addEventListener() {} },
        decodeURIComponent, atob,
    };
    context.globalThis = context;
    vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'stores/usePrintJobs.js'), 'utf8') + '\nglobalThis.__jobs = usePrintJobs;', context, { filename: 'usePrintJobs.js' });
    const original = context.__jobs.trackResponse;
    context.__jobs.trackResponse = (data, ctx) => { tracked.push([data, ctx]); return original(data, ctx); };
    return { context, clock, tracked, store: context.__jobs };
}

function component(context, file, name, props) {
    vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'components', file), 'utf8') + `\nglobalThis.__c = ${name};`, context, { filename: file });
    const emitted = [];
    const state = context.__c.setup(props, { emit: (...args) => emitted.push(args) });
    return { state, emitted };
}

async function testReprintOnAnAgentTillIsFollowed() {
    const pdfs = [];
    const { context, tracked } = sandbox({
        ticketReprintSale: async () => ({ data: { printed: false, print_status: 'queued', print_job_id: 33 } }),
        ticketSalePdf: (id) => pdfs.push(id),
        printJob: async () => ({ status: 'pending', claimed_at: null }),
    });
    const { state, emitted } = component(context, 'OrderDetailView.js', 'OrderDetailView', { order: { order_id: 12, reference: 'A26000012' } });
    await state.reprint();
    assert.equal(tracked.length, 1);
    assert.equal(tracked[0][1].kind, 'reprint');
    assert.equal(tracked[0][1].saleId, 12);
    assert.deepEqual(emitted.at(-1), ['notify', 'Imprimiendo ticket…', 'var(--blue)']);
    assert.deepEqual(pdfs, []);
}

async function testReprintOnOtherPrintersKeepsItsMessages() {
    const pdfs = [];
    const { context, tracked } = sandbox({
        ticketReprintSale: async () => ({ data: { printed: true } }),
        ticketSalePdf: (id) => pdfs.push(id),
    });
    const printed = component(context, 'OrderDetailView.js', 'OrderDetailView', { order: { order_id: 12 } });
    await printed.state.reprint();
    assert.deepEqual(printed.emitted.at(-1), ['notify', 'Ticket reimpreso', 'var(--green)']);

    context.ApiClient.ticketReprintSale = async () => ({ data: { printed: false, fallback: 'pdf' } });
    await printed.state.reprint();
    assert.deepEqual(printed.emitted.at(-1), ['notify', 'Descargando ticket PDF', 'var(--blue)']);
    assert.deepEqual(pdfs, [12]);
    assert.equal(tracked.length, 0, 'nothing is followed');
}

const ORDER = {
    ps_order_id: 42, reference: 'REF42', payment_method: 'cash', tender: { cash: 60, card: 0 },
    lines: [{ name: 'Camisa M', product_id: 3, id_product_attribute: 7, qty: 1, paid_total: 24.18, refundable_qty: 1 }],
};

async function refund(data) {
    const pdfs = [];
    const { context, tracked, store } = sandbox({
        refundOrder: async () => ({ data }),
        refundRetry: async () => ({ data: {} }),
        refundPdf: (id) => pdfs.push(id),
        searchOrders: async () => ({ data: [] }),
        printJob: async () => ({ status: 'pending', claimed_at: null }),
    });
    const { state } = component(context, 'RefundScreen.js', 'RefundScreen', {});
    state.selectOrder({ ...ORDER });
    state.toggleLine(0);
    state.setReason('size');
    await state.confirmRefund();
    return { pdfs, tracked, store };
}

async function testCreditNoteOnAnAgentTillIsFollowed() {
    const { pdfs, tracked, store } = await refund({ refund_id: 9, credit_note_number: 'R2026-000009', refunded_amount: 24.18, cash_amount: 24.18, print_status: 'queued', print_job_id: 40, drawer_job_id: 41 });
    assert.equal(tracked.length, 1);
    assert.equal(tracked[0][1].kind, 'credit_note');
    assert.equal(tracked[0][1].refundId, 9);
    assert.equal(store.ticket.job.jobId, 40);
    assert.equal(store.drawer.job.jobId, 41);
    assert.deepEqual(pdfs, [], 'the PDF only when the agent cannot print it');
}

async function testCreditNoteOnOtherPrintersOpensThePdfAsBefore() {
    const { pdfs, tracked } = await refund({ refund_id: 9, refunded_amount: 24.18, cash_amount: 24.18, print_status: 'pdf' });
    assert.deepEqual(pdfs, [9]);
    assert.equal(tracked.length, 0);
}

async function testDrawerButtonWarnsWhenTheAgentDoesNotOpenIt() {
    const { context, clock, store } = sandbox({
        openDrawer: async () => ({ success: true, drawer_job_id: 77 }),
        printJob: async () => ({ status: 'pending', claimed_at: null }),
    });
    const { state } = component(context, 'CashOpenScreen.js', 'CashOpenScreen', {});
    await state.openDrawer();
    assert.equal(state.drawerStatus.value, 'Abriendo cajón…');
    assert.equal(store.drawer.job.jobId, 77);
    await clock.advance(5000);
    assert.equal(state.drawerStatus.value, 'El cajón no se ha abierto. Ábrelo con la llave.');
    assert.equal(state.drawerWarn.value, true);
}

async function testDrawerButtonOnOtherPrintersAsBefore() {
    const { context, clock, store } = sandbox({ openDrawer: async () => ({ success: true }) });
    const { state } = component(context, 'CashOpenScreen.js', 'CashOpenScreen', {});
    await state.openDrawer();
    assert.equal(state.drawerStatus.value, '✓ Cajón abierto');
    assert.equal(store.drawer.job, null);
    await clock.advance(2500);
    assert.equal(state.drawerStatus.value, '');
}

(async () => {
    await testReprintOnAnAgentTillIsFollowed();
    await testReprintOnOtherPrintersKeepsItsMessages();
    await testCreditNoteOnAnAgentTillIsFollowed();
    await testCreditNoteOnOtherPrintersOpensThePdfAsBefore();
    await testDrawerButtonWarnsWhenTheAgentDoesNotOpenIt();
    await testDrawerButtonOnOtherPrintersAsBefore();
    console.log('printAgentFlows: ok');
})().catch((err) => { console.error(err); process.exit(1); });
