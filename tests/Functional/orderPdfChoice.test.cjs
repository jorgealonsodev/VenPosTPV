const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/OrderDetailView.js'), 'utf8');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

/** The order detail with a fake API: email sending on or off, and a failing send. */
function mount(order, { emailEnabled = true, sendError = null, customers = [], reprintData = { printed: true }, startWithChoice = false } = {}) {
    const calls = { pdf: [], salePdf: [], post: [], get: [], search: [] };
    const context = {
        Vue: { ref, computed },
        ApiClient: {
            ticketSalePdf: (id) => calls.salePdf.push(id),
            ticketPdf: (id) => calls.pdf.push(id),
            ticketReprintSale: async () => ({ data: reprintData }),
            ticketReprint: async () => ({ data: reprintData }),
            customerSearch: async (q) => { calls.search.push(q); return { data: customers }; },
            get: async (url) => { calls.get.push(url); return { data: { enabled: emailEnabled } }; },
            post: async (url, body) => {
                calls.post.push([url, body]);
                if (sendError) throw new Error('HTTP 422: ' + JSON.stringify({ error: sendError }));
                return { data: { sent: true } };
            },
        },
        JSON, String, Error, console,
        setTimeout: (fn) => { fn(); return 1; }, clearTimeout() {},
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__view = OrderDetailView;`, context, { filename: 'OrderDetailView.js' });
    const emitted = [];
    const state = context.__view.setup({ order, startWithChoice }, { emit: (...args) => emitted.push(args) });
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

test('the PDF button opens the window, and so does the reprint without a printer', () => {
    assert.match(source, /@click="openPdfChoice"[^>]*>\s*<icon name="receipt" :size="16" \/> PDF/);
    assert.match(source, /fallback === 'pdf'\) \{[\s\S]*?openPdfChoice\('ticket'\);/);
});

const ANA = { local_id: 1, id: 5, name: 'Ana Martínez', email: 'ana@example.com', phone: '600111222' };
const SIN_EMAIL = { local_id: 2, id: 6, name: 'Luis Sin Email', email: '', phone: '' };

test('typing looks up existing customers and a tap fills their email', async () => {
    const { state, calls } = mount(SALE, { customers: [ANA, SIN_EMAIL] });
    await state.openPdfChoice();

    state.pdfEmail.value = 'ana';
    await state.searchPdfCustomers();

    assert.deepEqual(calls.search, ['ana']);
    assert.deepEqual(JSON.parse(JSON.stringify(state.pdfCustomers.value)), [ANA], 'only customers with an email');

    state.pickPdfCustomer(ANA);
    assert.equal(state.pdfEmail.value, 'ana@example.com');
    assert.deepEqual(JSON.parse(JSON.stringify(state.pdfCustomers.value)), [], 'the list closes');
});

test('a short text or a typed email of no customer just stays as typed', async () => {
    const { state, calls } = mount(SALE, { customers: [] });
    await state.openPdfChoice();

    state.pdfEmail.value = 'a';
    await state.searchPdfCustomers();
    assert.deepEqual(calls.search, [], 'no lookup under 2 characters');

    state.pdfEmail.value = 'nuevo@example.com';
    await state.searchPdfCustomers();
    assert.deepEqual(JSON.parse(JSON.stringify(state.pdfCustomers.value)), []);
    assert.equal(state.pdfEmail.value, 'nuevo@example.com');
});

test('the email field searches as it is typed and lists the matches', () => {
    assert.match(source, /v-model="pdfEmail"[^>]*@input="searchPdfCustomers"/);
    assert.match(source, /v-for="c in pdfCustomers"[^>]*@click="pickPdfCustomer\(c\)"/);
});

// «Enviar por email» and «WhatsApp» are on the sale detail itself, not only inside «PDF».
test('the sale detail shows Enviar por email and WhatsApp next to Reimprimir and PDF', () => {
    assert.match(source, /@click="openPdfChoice\('ticket', 'email'\)"[^>]*>[\s\S]*?Enviar por email/);
    assert.match(source, /@click="openPdfChoice\('ticket', 'whatsapp'\)"[^>]*>[\s\S]*?WhatsApp/);
    assert.match(source, /v-if="order\.order_id"[^>]*class="order-share-actions"|class="order-share-actions"[^>]*v-if="order\.order_id"/, 'only for a TPV sale');
});

test('WhatsApp opens the window with the WhatsApp panel already open', async () => {
    const { state } = mount(SALE);
    await state.openPdfChoice('ticket', 'whatsapp');
    assert.equal(state.pdfChoice.value, true);
    assert.equal(state.pdfDoc.value, 'ticket');
    assert.equal(state.pdfWhatsapp.value, true);

    await state.openPdfChoice('ticket', 'email');
    assert.equal(state.pdfWhatsapp.value, false, 'email opens with WhatsApp closed');
    assert.equal(state.pdfEmailEnabled.value, true);
});

test('Reimprimir without a printer opens the choice instead of downloading the PDF', async () => {
    const { state, calls, emitted } = mount(SALE, { reprintData: { fallback: 'pdf' } });
    await state.reprint();
    assert.equal(state.pdfChoice.value, true);
    assert.equal(state.pdfDoc.value, 'ticket');
    assert.deepEqual(calls.salePdf, []);
    assert.deepEqual(emitted.at(-1), ['notify', 'No hay impresora: elige cómo entregar el ticket', 'var(--blue)']);
});

test('opened with start-with-choice, the detail shows the choice at once', async () => {
    const { state } = mount(SALE, { startWithChoice: true });
    await Promise.resolve();
    assert.equal(state.pdfChoice.value, true);
});
