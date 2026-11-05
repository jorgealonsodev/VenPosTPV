/**
 * docs/prd/factura-completa.md phase 1 in the till: «Factura completa» in the sale
 * detail (InvoiceModal: customer, missing tax data, summary, confirm), «Sustituido
 * por», the invoice in the PDF / email window (consent, art. 9.2) and «Próxima
 * factura» in Ajustes → Cajas → Numeración.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '../../public/assets/js');
function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function httpError(status, body) {
    const e = new Error('HTTP ' + status + ': ' + JSON.stringify(body));
    e.status = status;
    e.body = body;
    return e;
}

function load(file, name, api, extra = {}) {
    const opened = [];
    const context = {
        Vue: { ref, computed, reactive: (v) => v, watch() {}, onMounted() {} },
        ApiClient: api,
        window: { TPV_BASE: '/tpv', open: (url) => opened.push(url) },
        JSON, String, Number, Error, Math, Date, Array, Object, Promise, console, encodeURIComponent,
        setTimeout: (fn) => { fn(); return 1; }, clearTimeout() {},
        ...extra,
    };
    context.globalThis = context;
    vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'components', file), 'utf8') + `\nglobalThis.__c = ${name};`, context, { filename: file });
    return { component: context.__c, opened, context };
}

function mount(file, name, api, props) {
    const { component, opened } = load(file, name, api);
    const emitted = [];
    const state = component.setup(props, { emit: (...args) => emitted.push(args) });
    return { state, emitted, opened, component };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

const SALE = { order_id: 42, reference: 'A26000123', total: 12.1, created_at: '2026-11-03 18:30:00' };
const COMPLETE = { local_id: 5, name: 'Ana Pérez', email: 'ana@example.com', tax_name: 'Deportes Ejemplo SL', tax_id: 'B12345674', country: 'ES', address: 'Calle Mayor 1', postcode: '33002', city: 'Oviedo', tax_complete: true, tax_missing: [] };
const INCOMPLETE = { ...COMPLETE, local_id: 6, tax_id: '', postcode: '', tax_complete: false, tax_missing: ['tax_id', 'postcode'] };
const PREVIEW = {
    replaced_reference: 'A26000123', replaced_date: '2026-11-03', total: 12.1, tax_total: 2.1, next_number: 'F26000001', warnings: [],
    recipient: { name: 'Deportes Ejemplo SL', tax_id: 'B12345674', address: 'Calle Mayor 1', postcode: '33002', city: 'Oviedo' },
    tax_breakdown: [{ rate: 21, base: 10, vat: 2.1 }],
};
const INVOICE = { id: 9, number: 'F26000001', recipient: { name: 'Deportes Ejemplo SL' }, email_consent_at: null };

function modalApi(overrides = {}) {
    const calls = { get: [], post: [], put: [], create: [] };
    const api = {
        customerSearch: async () => ({ data: [COMPLETE, INCOMPLETE] }),
        customerCreate: async (body) => { calls.create.push(body); return { data: { ...INCOMPLETE, local_id: 77, tax_missing: ['tax_name', 'tax_id', 'address', 'postcode', 'city'] } }; },
        get: async (url) => { calls.get.push(url); return { data: PREVIEW }; },
        put: async (url, body) => { calls.put.push([url, body]); return { data: { ...COMPLETE, local_id: Number(url.split('/').pop()) } }; },
        post: async (url, body) => { calls.post.push([url, body]); return { data: { ...INVOICE, warnings: [] } }; },
        ...overrides,
    };
    return { api, calls };
}

test('a customer with complete tax data goes straight to the summary, and «Emitir» issues the invoice', async () => {
    const { api, calls } = modalApi();
    const { state, emitted } = mount('InvoiceModal.js', 'InvoiceModal', api, { order: SALE });
    await flush();
    assert.equal(state.results.value.length, 2);

    await state.pick(COMPLETE);
    assert.equal(state.step.value, 'summary');
    assert.deepEqual(calls.get, ['/api/invoices/from-sale/42/preview?customer_id=5']);
    assert.equal(state.summary.value.next_number, 'F26000001');
    assert.equal(state.money(10), '10,00 €');
    assert.equal(state.rate(21), '21 %');
    assert.equal(state.date('2026-11-03'), '03/11/2026');

    state.emailConsent.value = true;
    await state.confirm();
    await state.confirm();
    assert.equal(calls.post.length, 2);
    const [url, body] = calls.post[0];
    assert.equal(url, '/api/invoices/from-sale/42');
    assert.equal(body.customer_id, 5);
    assert.equal(body.email_consent, true);
    assert.match(body.request_id, /^inv-/);
    assert.equal(calls.post[1][1].request_id, body.request_id, 'a retry is the same request');
    assert.equal(emitted[0][0], 'issued');
    assert.equal(emitted[0][1].number, 'F26000001');
});

test('missing tax data open the form with those fields marked, and saving them continues to the summary', async () => {
    const { api, calls } = modalApi();
    const { state } = mount('InvoiceModal.js', 'InvoiceModal', api, { order: SALE });

    await state.pick(INCOMPLETE);
    assert.equal(state.step.value, 'edit');
    assert.equal(state.isMissing('tax_id'), true);
    assert.equal(state.isMissing('postcode'), true);
    assert.equal(state.isMissing('address'), false);

    state.form.value.tax_id = ' B12345674 ';
    state.form.value.postcode = '33002';
    await state.saveTaxData();
    assert.equal(calls.put.length, 1);
    assert.equal(calls.put[0][0], '/api/customers/6');
    assert.equal(calls.put[0][1].tax_id, 'B12345674');
    assert.equal(calls.put[0][1].country, 'ES');
    assert.equal('tax_id_type' in calls.put[0][1], false, 'a Spanish NIF needs no document type');
    assert.equal(state.step.value, 'summary');
});

test('the server message is shown: invalid NIF, ticket already redeemed, refunds', async () => {
    const { api } = modalApi({
        put: async () => { throw httpError(422, { error: 'El NIF no es válido' }); },
        post: async () => { throw httpError(409, { error: 'Este ticket ya se canjeó por la factura F26000002', invoice_number: 'F26000002' }); },
    });
    const { state, emitted } = mount('InvoiceModal.js', 'InvoiceModal', api, { order: SALE });

    await state.pick(INCOMPLETE);
    await state.saveTaxData();
    assert.equal(state.error.value, 'El NIF no es válido');
    assert.equal(state.step.value, 'edit');

    await state.pick(COMPLETE);
    await state.confirm();
    assert.equal(state.error.value, 'Este ticket ya se canjeó por la factura F26000002');
    assert.equal(emitted.length, 0);

    const refunded = mount('InvoiceModal.js', 'InvoiceModal', modalApi({ get: async () => { throw httpError(422, { error: 'Este ticket tiene devoluciones; no se puede canjear' }); } }).api, { order: SALE }).state;
    await refunded.pick(COMPLETE);
    assert.equal(refunded.step.value, 'customer');
    assert.equal(refunded.error.value, 'Este ticket tiene devoluciones; no se puede canjear');
});

test('a new customer is created and then gets its tax data', async () => {
    const { api, calls } = modalApi();
    const { state } = mount('InvoiceModal.js', 'InvoiceModal', api, { order: SALE });

    state.startCreate();
    assert.equal(state.creating.value, true);
    Object.assign(state.form.value, { firstname: 'Luis', lastname: 'Gómez', email: 'luis@example.com', tax_name: 'Luis Gómez', tax_id: '12345678Z', address: 'Calle 2', postcode: '33003', city: 'Oviedo' });
    await state.saveTaxData();

    assert.deepEqual(JSON.parse(JSON.stringify(calls.create)), [{ firstname: 'Luis', lastname: 'Gómez', email: 'luis@example.com', phone: '' }]);
    assert.equal(calls.put[0][0], '/api/customers/77');
    assert.equal(state.step.value, 'summary');
});

function detailApi({ invoice = null, emailEnabled = true } = {}) {
    const calls = { get: [], post: [] };
    const server = { invoice };
    const api = {
        server,
        ticketSalePdf: () => {},
        get: async (url) => {
            calls.get.push(url);
            if (url.startsWith('/api/invoices/by-sale/')) return { data: server.invoice };
            return { data: { enabled: emailEnabled } };
        },
        post: async (url, body) => { calls.post.push([url, body]); return { data: { sent: true } }; },
        customerSearch: async () => ({ data: [] }),
    };
    return { api, calls };
}

test('the sale detail offers «Factura completa» until the ticket is redeemed, then «Sustituido por»', async () => {
    const plain = mount('OrderDetailView.js', 'OrderDetailView', detailApi().api, { order: SALE });
    await flush();
    assert.equal(plain.state.canInvoice.value, true);
    assert.equal(plain.state.invoice.value, null);

    const { api, calls } = detailApi({ invoice: INVOICE });
    const redeemed = mount('OrderDetailView.js', 'OrderDetailView', api, { order: SALE });
    await flush();
    assert.deepEqual(calls.get, ['/api/invoices/by-sale/42']);
    assert.equal(redeemed.state.invoice.value.number, 'F26000001');
    assert.equal(redeemed.state.canInvoice.value, false);
    assert.match(redeemed.component.template, /Sustituido por \{\{ invoice\.number \}\}/);
    assert.match(redeemed.component.template, /<invoice-modal v-if="showInvoice"/);

    const online = mount('OrderDetailView.js', 'OrderDetailView', detailApi().api, { order: { ps_order_id: 900 } });
    assert.equal(online.state.canInvoice.value, false, 'only TPV sales');
});

test('an issued invoice opens the PDF window for the invoice and is emailed only with consent', async () => {
    const { api, calls } = detailApi();
    const { state, emitted, opened } = mount('OrderDetailView.js', 'OrderDetailView', api, { order: SALE });
    await flush();

    api.server.invoice = INVOICE;
    state.onInvoiceIssued(INVOICE);
    await flush();
    assert.deepEqual(emitted[0], ['notify', 'Factura F26000001 emitida', 'var(--green)']);
    assert.equal(state.pdfChoice.value, true);
    assert.equal(state.pdfDoc.value, 'invoice');
    assert.equal(state.pdfConsent.value, false);

    state.pdfEmail.value = 'cliente@example.com';
    await state.sendPdfEmail();
    assert.deepEqual(calls.post, [], 'nothing is sent without consent');
    assert.equal(state.pdfEmailStatus.value, 'Marca que el cliente acepta recibir la factura por email');

    state.pdfConsent.value = true;
    await state.sendPdfEmail();
    assert.deepEqual(JSON.parse(JSON.stringify(calls.post)), [['/api/invoices/9/email', { email: 'cliente@example.com', consent: true }]]);
    assert.equal(state.pdfEmailStatus.value, 'Factura enviada a cliente@example.com');
    assert.equal(calls.get.filter((u) => u === '/api/invoices/by-sale/42').length, 2, 'reloaded with its stored consent');

    state.downloadPdf();
    assert.deepEqual(opened, ['/tpv/api/invoices/9/pdf']);

    await state.openPdfChoice({ type: 'click' });
    assert.equal(state.pdfDoc.value, 'ticket', 'the PDF button keeps opening the ticket');
});

test('Ajustes → Cajas → Numeración changes «Próxima factura»', async () => {
    const source = fs.readFileSync(path.join(ROOT, 'components/SettingsModal.js'), 'utf8');
    assert.ok(source.includes('Próxima factura <span'), 'invoice row in the numbering block');
    assert.ok(source.includes("@click=\"startNumberingChange('invoice')\""));

    const puts = [];
    const overview = {
        year: 2026, tills: [], changes: [],
        credit_notes: { series: 'R2026', last_number: 0, next_number: 1, next_code: 'R2026-000001' },
        invoices: { series: 'F26', last_number: 4, next_number: 5, next_code: 'F26000005' },
    };
    const { component } = load('SettingsModal.js', 'SettingsModal', {
        get: async (url) => (url === '/api/numbering' ? { data: overview } : { data: [] }),
        put: async (url, body) => { puts.push({ url, body }); return { data: {} }; },
        terminalGet: async () => ({ data: { id: 7, name: 'Caja 1' } }),
        terminalUpdate: async () => ({}),
    }, { useSession: { session: { terminalId: 7 }, startStockSyncTimer() {}, startStockPushTimer() {} } });
    const s = component.setup({}, { emit() {} });
    await s.loadNumbering();

    s.startNumberingChange('invoice');
    assert.equal(s.numbering.next, '5');
    s.numbering.next = '120';
    s.numbering.reason = ' Reinstalación ';
    assert.equal(await s.saveNumberingChange(), true);
    assert.equal(JSON.stringify(puts), JSON.stringify([{ url: '/api/numbering/invoices', body: { next_number: 120, reason: 'Reinstalación' } }]));
});
