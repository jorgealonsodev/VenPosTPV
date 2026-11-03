/**
 * docs/prd/agente-impresion.md C1-C5, T3: the print job of a sale, a reprint or a
 * credit note followed from the till (stores/usePrintJobs.js).
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

function loadStore(extraApi = {}) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/stores/usePrintJobs.js'), 'utf8');
    const clock = makeClock();
    const jobs = new Map();
    const calls = [];
    const api = {
        printJob: async (id) => {
            calls.push(['printJob', id]);
            if (!jobs.has(id)) throw new Error('HTTP 404: {}');
            return { ...jobs.get(id) };
        },
        ticketReprintSale: async (id) => { calls.push(['ticketReprintSale', id]); return { data: { printed: false, print_status: 'queued', print_job_id: 91 } }; },
        ticketReprint: async (id) => { calls.push(['ticketReprint', id]); return { data: { printed: false, print_status: 'queued', print_job_id: 92 } }; },
        ticketSalePdf: (id) => calls.push(['ticketSalePdf', id]),
        ticketPdf: (id) => calls.push(['ticketPdf', id]),
        refundPdf: (id) => calls.push(['refundPdf', id]),
        ...extraApi,
    };
    const context = { ...sandboxGlobals(clock), Vue: { reactive: (v) => v }, ApiClient: api };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__store = usePrintJobs;`, context, { filename: 'usePrintJobs.js' });
    const job = (id, fields) => jobs.set(id, { id, status: 'pending', claimed_at: null, error_code: null, error: null, ...fields });
    return { store: context.__store, clock, jobs, job, calls };
}

const message = (store) => store.ticketMessage(store.ticket.job);

async function testQueuedTicketShowsPrintingThenSent() {
    const { store, clock, job } = loadStore();
    job(10, {});
    store.trackResponse({ order_id: 5, print_status: 'queued', print_job_id: 10 }, { kind: 'sale', saleId: 5 });
    assert.equal(message(store), 'Imprimiendo ticket…');
    await clock.advance(1000);
    assert.equal(message(store), 'Imprimiendo ticket…');
    job(10, { status: 'done', claimed_at: 'x' });
    await clock.advance(1000);
    // T3: the queue of the system took it; the printer does not confirm the paper.
    assert.equal(message(store), 'Ticket enviado');
    assert.equal(store.ticket.job.phase, 'sent');
    assert.equal(store.ticket.job.problem, false);
}

async function testNotPickedUpIn15SecondsIsOffline() {
    const { store, clock, job } = loadStore();
    job(11, {});
    store.trackResponse({ print_status: 'queued', print_job_id: 11 }, { kind: 'sale', saleId: 6 });
    await clock.advance(14000);
    assert.equal(message(store), 'Imprimiendo ticket…');
    await clock.advance(1000);
    assert.equal(message(store), 'La impresora no está conectada');
    assert.equal(store.ticket.job.problem, true);
}

async function testAClaimedJobNotDoneIn15SecondsIsOfflineAndClearsWhenItPrints() {
    // A stopped agent's last long poll may claim the job into a dead connection:
    // claimed is not printed, so 15 s without "done" means not connected (C1).
    const { store, clock, job, jobs } = loadStore();
    job(12, { status: 'claimed', claimed_at: '2026-11-03T17:00:01+01:00' });
    store.trackResponse({ print_status: 'queued', print_job_id: 12 }, { kind: 'sale', saleId: 6 });
    await clock.advance(14000);
    assert.equal(message(store), 'Imprimiendo ticket…', 'still within 15 s');
    await clock.advance(1000);
    assert.equal(message(store), 'La impresora no está conectada', 'claimed but not done in 15 s');
    assert.equal(store.ticket.job.problem, true);
    assert.equal(store.canRetry(), false, 'C5: the job is not cancelled nor reprinted');
    job(12, { status: 'done', claimed_at: '2026-11-03T17:00:01+01:00' });
    await clock.advance(5000);
    assert.equal(message(store), 'Ticket enviado', 'C5: it printed later, the notice clears');
    assert.equal(store.ticket.job.problem, false);

    const other = loadStore();
    other.store.trackResponse({ print_status: 'queued', print_job_id: 99 }, { kind: 'sale', saleId: 6 });
    await other.clock.advance(15000);
    assert.equal(message(other.store), 'La impresora no está conectada', 'no answer about the job counts as not connected');
    assert.ok(jobs);
}

async function testFailedJobNamesTheReasonInSpanish() {
    const cases = [
        ['printer_not_found', 'Printer EPSON not found', 'La impresora tiene un problema: impresora no encontrada, elígela de nuevo desde el icono'],
        ['spooler_rejected', 'x', 'La impresora tiene un problema: la cola de impresión ha rechazado el ticket'],
        ['paper_out', null, 'La impresora tiene un problema: sin papel'],
        ['weird_code', 'Tapa abierta en la TM-T20', 'La impresora tiene un problema: Tapa abierta en la TM-T20'],
        [null, null, 'La impresora tiene un problema: error desconocido'],
    ];
    for (const [code, error, expected] of cases) {
        const { store, clock, job } = loadStore();
        job(13, {});
        store.trackResponse({ print_status: 'queued', print_job_id: 13 }, { kind: 'sale', saleId: 6 });
        job(13, { status: 'failed', claimed_at: 'x', error_code: code, error });
        await clock.advance(1000);
        assert.equal(message(store), expected, String(code));
        assert.equal(store.ticket.job.problem, true);
    }
}

async function testCheckoutThatCouldNotQueueIsAFailure() {
    const { store } = loadStore();
    store.trackResponse({ print_status: 'failed', print_error: 'terminal_not_resolved' }, { kind: 'sale', saleId: 6 });
    assert.equal(message(store), 'La impresora tiene un problema: este equipo no tiene caja asignada');
}

async function testPdfDoesNotCancelThePendingTicket() {
    const { store, clock, job, calls } = loadStore();
    job(14, {});
    store.trackResponse({ print_status: 'queued', print_job_id: 14 }, { kind: 'sale', saleId: 7 });
    await clock.advance(15000);
    assert.equal(message(store), 'La impresora no está conectada');
    store.downloadPdf();
    assert.deepEqual(calls.filter((c) => c[0] !== 'printJob'), [['ticketSalePdf', 7]], 'only the PDF, nothing cancels the job');
    // C5: the agent comes back within 2 minutes and prints it; the notice follows.
    job(14, { status: 'done', claimed_at: 'x' });
    await clock.advance(30000);
    assert.equal(message(store), 'Ticket enviado');
}

async function testFollowingStopsWhenTheTicketExpires() {
    const { store, clock, job, calls } = loadStore();
    job(15, {});
    store.trackResponse({ print_status: 'queued', print_job_id: 15 }, { kind: 'sale', saleId: 7 });
    await clock.advance(20000);
    const fast = calls.length;
    assert.ok(fast >= 19 && fast <= 21, 'every second for 20 s: ' + fast);
    await clock.advance(10000);
    assert.equal(calls.length - fast, 2, 'then every 5 s');
    await clock.advance(200000);
    const total = calls.length;
    await clock.advance(60000);
    assert.equal(calls.length, total, 'no polling after the 2-minute life of a ticket');
    assert.equal(message(store), 'La impresora no está conectada');
}

async function testRetryReprintsTheLocalSale() {
    const { store, clock, job, calls } = loadStore();
    job(16, { status: 'failed', claimed_at: 'x', error_code: 'spooler_unavailable' });
    store.trackResponse({ print_status: 'queued', print_job_id: 16 }, { kind: 'sale', saleId: 8, orderId: 300 });
    await clock.advance(1000);
    assert.equal(store.canRetry(), true);
    job(91, {});
    await store.retry();
    assert.deepEqual(calls.filter((c) => c[0] === 'ticketReprintSale'), [['ticketReprintSale', 8]]);
    assert.equal(store.ticket.job.jobId, 91);
    assert.equal(message(store), 'Imprimiendo ticket…');
    job(91, { status: 'done', claimed_at: 'x' });
    await clock.advance(1000);
    assert.equal(message(store), 'Ticket enviado');
}

// While the agent is not connected the queued ticket still prints when it comes
// back (C5), so a retry would print it twice: only the PDF is offered.
async function testNoRetryWhileThePrinterIsNotConnected() {
    const { store, clock, job } = loadStore();
    job(18, {});
    store.trackResponse({ print_status: 'queued', print_job_id: 18 }, { kind: 'sale', saleId: 9, orderId: 301 });
    await clock.advance(15000);
    assert.equal(message(store), 'La impresora no está conectada');
    assert.equal(store.canRetry(), false, 'retry would print the ticket twice');
}

async function testCreditNoteOffersItsPdfAndNoReprint() {
    const { store, clock, job, calls } = loadStore();
    job(17, {});
    store.trackResponse({ refund_id: 4, print_status: 'queued', print_job_id: 17 }, { kind: 'credit_note', refundId: 4 });
    await clock.advance(15000);
    assert.equal(message(store), 'La impresora no está conectada');
    assert.equal(store.canRetry(), false, 'there is no reprint of a credit note');
    store.downloadPdf();
    assert.deepEqual(calls.filter((c) => c[0] !== 'printJob'), [['refundPdf', 4]]);
}

async function testOtherPrintersAreNotFollowed() {
    const { store, clock, calls } = loadStore();
    assert.equal(store.trackResponse({ order_id: 5, print_status: 'dispatched' }, { kind: 'sale', saleId: 5 }), false);
    assert.equal(store.trackResponse({ refund_id: 5, print_status: 'pdf' }, { kind: 'credit_note', refundId: 5 }), false);
    await clock.advance(30000);
    assert.equal(store.ticket.job, null);
    assert.equal(store.drawer.job, null);
    assert.deepEqual(calls, []);
}

async function testDrawerWarnsAfterFiveSeconds() {
    const { store, clock, job } = loadStore();
    job(20, {});
    store.trackResponse({ print_status: 'queued', print_job_id: 21, drawer_job_id: 20 }, { kind: 'sale', saleId: 9, change: 6.86 });
    await clock.advance(4000);
    assert.equal(store.drawer.job.phase, 'waiting');
    assert.equal(store.drawerWarning(), '');
    await clock.advance(1000);
    assert.equal(store.drawerWarning(), 'El cajón no se ha abierto. Ábrelo con la llave.');
    assert.equal(store.drawer.job.change, 6.86);
}

async function testDrawerOpenedInTimeHasNoWarning() {
    const { store, clock, job } = loadStore();
    job(22, { status: 'done', claimed_at: 'x' });
    store.trackDrawer(22);
    await clock.advance(6000);
    assert.equal(store.drawer.job.phase, 'opened');
    assert.equal(store.drawerWarning(), '');
}

async function testLabels() {
    const { store } = loadStore();
    assert.deepEqual(['ready', 'paper_low', 'paper_out', 'cover_open', 'offline', 'not_found', 'paused', 'error'].map(store.printerStateLabel),
        ['Lista', 'Poco papel', 'Sin papel', 'Tapa abierta', 'Desconectada', 'No encontrada', 'En pausa', 'Error']);
    assert.deepEqual(['pending', 'claimed', 'done', 'failed', 'expired', 'cancelled'].map(store.jobStatusLabel),
        ['Pendiente', 'Pendiente', 'Enviado', 'Fallido', 'Caducado', 'Cancelado']);
    assert.deepEqual(['ticket', 'drawer', 'label'].map(store.jobTypeLabel), ['Ticket', 'Cajón', 'Etiqueta']);
}

(async () => {
    await testQueuedTicketShowsPrintingThenSent();
    await testNotPickedUpIn15SecondsIsOffline();
    await testAClaimedJobNotDoneIn15SecondsIsOfflineAndClearsWhenItPrints();
    await testFailedJobNamesTheReasonInSpanish();
    await testCheckoutThatCouldNotQueueIsAFailure();
    await testPdfDoesNotCancelThePendingTicket();
    await testFollowingStopsWhenTheTicketExpires();
    await testRetryReprintsTheLocalSale();
    await testNoRetryWhileThePrinterIsNotConnected();
    await testCreditNoteOffersItsPdfAndNoReprint();
    await testOtherPrintersAreNotFollowed();
    await testDrawerWarnsAfterFiveSeconds();
    await testDrawerOpenedInTimeHasNoWarning();
    await testLabels();
    console.log('printJobs: ok');
})().catch((err) => { console.error(err); process.exit(1); });
