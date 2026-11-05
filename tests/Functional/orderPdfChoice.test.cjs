const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/OrderDetailView.js'), 'utf8');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

/** The order detail with a fake API: email sending on or off, and a failing send. */
function mount(order, { emailEnabled = true, sendError = null } = {}) {
    const calls = { pdf: [], salePdf: [], post: [], get: [] };
    const context = {
        Vue: { ref, computed },
        ApiClient: {
            ticketSalePdf: (id) => calls.salePdf.push(id),
            ticketPdf: (id) => calls.pdf.push(id),
            get: async (url) => { calls.get.push(url); return { data: { enabled: emailEnabled } }; },
            post: async (url, body) => {
                calls.post.push([url, body]);
                if (sendError) throw new Error('HTTP 422: ' + JSON.stringify({ error: sendError }));
                return { data: { sent: true } };
            },
        },
        JSON, String, Error, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__view = OrderDetailView;`, context, { filename: 'OrderDetailView.js' });
    const emitted = [];
    const state = context.__view.setup({ order }, { emit: (...args) => emitted.push(args) });
    return { state, calls, emitted };
}

const SALE = { order_id: 42, reference: 'A26000042', total: 380 };
const ONLINE = { ps_order_id: 900, reference: 'XKBKNABJK', total: 20 };

test('the PDF button opens a window to download or send, without downloading yet', async () => {
    const { state, calls } = mount(SALE);

    await state.openPdfChoice();

    assert.equal(state.pdfChoice.value, true);
    assert.deepEqual(calls.salePdf, []);
    assert.equal(state.pdfEmailEnabled.value, true);
});

test('Descargar downloads the sale PDF and closes the window', async () => {
    const { state, calls } = mount(SALE);
    await state.openPdfChoice();

    state.downloadPdf();

    assert.deepEqual(calls.salePdf, [42]);
    assert.equal(state.pdfChoice.value, false);
});

test('Enviar sends the sale ticket to the typed email and says so', async () => {
    const { state, calls } = mount(SALE);
    await state.openPdfChoice();
    state.pdfEmail.value = ' cliente@example.com ';

    await state.sendPdfEmail();

    assert.deepEqual(JSON.parse(JSON.stringify(calls.post)), [['/api/ticket/sale/42/email', { email: 'cliente@example.com' }]]);
    assert.equal(state.pdfEmailStatus.value, 'Ticket enviado a cliente@example.com');
});

test('a refused send shows the server reason and keeps the window open', async () => {
    const { state } = mount(SALE, { sendError: 'El email no es válido' });
    await state.openPdfChoice();
    state.pdfEmail.value = 'mal';

    await state.sendPdfEmail();

    assert.equal(state.pdfEmailStatus.value, 'El email no es válido');
    assert.equal(state.pdfChoice.value, true);
});

test('without an outgoing mail account, or for an online order, only download is offered', async () => {
    const off = mount(SALE, { emailEnabled: false });
    await off.state.openPdfChoice();
    assert.equal(off.state.pdfEmailEnabled.value, false);

    const online = mount(ONLINE);
    await online.state.openPdfChoice();
    assert.equal(online.state.pdfEmailEnabled.value, false, 'only TPV sales can be emailed');
    online.state.downloadPdf();
    assert.deepEqual(online.calls.pdf, [900]);
});

test('the PDF button opens the window; the reprint fallback still downloads directly', () => {
    assert.match(source, /@click="openPdfChoice"[^>]*>\s*<icon name="receipt" :size="16" \/> PDF/);
    assert.match(source, /fallback === 'pdf'\) \{\s*pdf\(\);/);
});
