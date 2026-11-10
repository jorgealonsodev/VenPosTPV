const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
const test = require('node:test');

// «WhatsApp» next to «Enviar por email»: the cashier types a number or picks a
// customer, the till asks for the PDF link and opens WhatsApp with the message.
const read = (file) => fs.readFileSync(path.resolve(__dirname, '../../public/assets/js', file), 'utf8');
const source = read('components/WhatsAppShare.js');
const phone = read('lib/phone.js');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

const MESSAGE = 'Hola, aquí tienes tu ticket R26000012 de Nada Más: https://tpv.example.com/d/abc.def';

function mount(props, { blocked = false, linkError = null, customers = [] } = {}) {
    const calls = { link: [], search: [], open: [] };
    const context = {
        Vue: { ref, computed },
        ApiClient: {
            whatsappLink: async (kind, id, body) => {
                calls.link.push([kind, id, body]);
                if (linkError) throw new Error('HTTP 422: ' + JSON.stringify({ error: linkError }));
                return { data: { url: 'https://tpv.example.com/d/abc.def', message: MESSAGE } };
            },
            customerSearch: async (q) => { calls.search.push(q); return { data: customers }; },
        },
        window: {
            open: (...args) => { calls.open.push(args); return blocked ? null : { opener: 'the till' }; },
        },
        JSON, String, Error, console, encodeURIComponent,
        setTimeout: (fn) => { fn(); return 1; }, clearTimeout() {},
    };
    context.globalThis = context;
    vm.runInNewContext(`${phone}\n${source}\nglobalThis.__share = WhatsAppShare;`, context, { filename: 'WhatsAppShare.js' });
    const emitted = [];
    const state = context.__share.setup(props, { emit: (...args) => emitted.push(args) });
    return { state, calls, emitted, context };
}

test('the panel has the phone field, «Elegir cliente» and «Abrir WhatsApp»', () => {
    assert.match(source, /v-model="phone"[^>]*type="tel"/);
    assert.match(source, />\s*Elegir cliente\s*</);
    assert.match(source, /'Abrir WhatsApp'/);
    assert.match(source, /:href="link"[^>]*target="_blank"[^>]*rel="noopener noreferrer"/);
});

test('Abrir WhatsApp asks for the link and opens wa.me with the encoded message', async () => {
    const { state, calls, emitted } = mount({ kind: 'ticket', docId: 42 });
    state.phone.value = '600 11 12 22';

    await state.openWhatsApp();

    assert.deepEqual(JSON.parse(JSON.stringify(calls.link)), [['ticket', 42, {}]]);
    const url = 'https://wa.me/34600111222?text=' + encodeURIComponent(MESSAGE);
    assert.deepEqual(calls.open, [[url, '_blank']]);
    assert.equal(state.status.value, 'Abriendo WhatsApp…');
    assert.equal(state.link.value, url, 'the link stays as a fallback');
    assert.equal(emitted[0][0], 'shared');
});

test('the opened WhatsApp window gets no handle back to the till', async () => {
    const { state, context } = mount({ kind: 'ticket', docId: 42 });
    let opened = null;
    context.window.open = () => { opened = { opener: 'the till' }; return opened; };
    state.phone.value = '600111222';

    await state.openWhatsApp();

    assert.equal(opened.opener, null);
});

test('a blocked popup leaves the link to tap', async () => {
    const { state } = mount({ kind: 'coupon', docId: 5 }, { blocked: true });
    state.phone.value = '+44 7700 900123';

    await state.openWhatsApp();

    assert.match(state.link.value, /^https:\/\/wa\.me\/447700900123\?text=/);
    assert.equal(state.status.value, 'El navegador ha bloqueado la ventana: toca el enlace para abrir WhatsApp.');
});

test('an unusable number says so and asks the server nothing', async () => {
    const { state, calls } = mount({ kind: 'ticket', docId: 42 });
    for (const bad of ['', '12345', 'ana']) {
        state.phone.value = bad;
        await state.openWhatsApp();
        assert.equal(state.status.value, 'Número de teléfono no válido', bad);
    }
    assert.deepEqual(calls.link, []);
    assert.deepEqual(calls.open, []);
});

test('an invoice without consent is not shared; with it the consent goes along', async () => {
    const without = mount({ kind: 'invoice', docId: 8, consent: false });
    without.state.phone.value = '600111222';
    await without.state.openWhatsApp();
    assert.equal(without.state.status.value, 'Marca que el cliente acepta recibir la factura por email o WhatsApp');
    assert.deepEqual(without.calls.link, []);

    const withConsent = mount({ kind: 'invoice', docId: 8, consent: true });
    withConsent.state.phone.value = '600111222';
    await withConsent.state.openWhatsApp();
    assert.deepEqual(JSON.parse(JSON.stringify(withConsent.calls.link)), [['invoice', 8, { consent: true }]]);
});

test('a refusal from the server shows its reason and opens nothing', async () => {
    const { state, calls } = mount({ kind: 'invoice', docId: 8, consent: true }, { linkError: 'El cliente no ha aceptado recibir la factura en formato electrónico' });
    state.phone.value = '600111222';

    await state.openWhatsApp();

    assert.equal(state.status.value, 'El cliente no ha aceptado recibir la factura en formato electrónico');
    assert.deepEqual(calls.open, []);
});

test('the sale customer phone prefills the field', () => {
    const { state } = mount({ kind: 'ticket', docId: 42, initialPhone: '611 22 33 44' });
    assert.equal(state.phone.value, '+34 611 22 33 44', 'with its country code');
});

const ANA = { local_id: 1, id: 5, name: 'Ana Martínez', email: 'ana@example.com', phone: '600 111 222' };
const SIN_TELEFONO = { local_id: 2, id: 6, name: 'Luis', email: 'luis@example.com', phone: '' };

test('«Elegir cliente» lists every customer found; one without a phone says so and cannot be picked', async () => {
    const { state, calls } = mount({ kind: 'ticket', docId: 42 }, { customers: [ANA, SIN_TELEFONO] });

    state.togglePicker();
    assert.equal(state.pickerOpen.value, true);
    state.customerQuery.value = 'an';
    await state.searchCustomers();

    assert.deepEqual(calls.search, ['an']);
    assert.deepEqual(JSON.parse(JSON.stringify(state.customers.value.map((c) => c.name))), ['Ana Martínez', 'Luis']);
    assert.equal(state.hasPhone(ANA), true);
    assert.equal(state.hasPhone(SIN_TELEFONO), false);
    state.pickCustomer(SIN_TELEFONO);
    assert.equal(state.phone.value, '', 'nothing to fill');
    assert.equal(state.pickerOpen.value, true);
    assert.match(source, /:disabled="!hasPhone\(c\)"/);
    assert.match(source, /sin teléfono/);

    state.pickCustomer(ANA);
    assert.equal(state.phone.value, '+34 600 11 12 22', 'with its country code');
    assert.equal(state.pickerOpen.value, false);
    assert.deepEqual(JSON.parse(JSON.stringify(state.customers.value)), []);
    assert.match(source, /v-for="c in customers"[^>]*@click="pickCustomer\(c\)"/);
    assert.match(source, /v-model="customerQuery"[^>]*@input="searchCustomers"/);
});

test('a typed number gets its country code when the field is left', () => {
    const { state } = mount({ kind: 'ticket', docId: 42 });
    state.phone.value = '615658290';
    state.formatPhone();
    assert.equal(state.phone.value, '+34 615 65 82 90');
    state.phone.value = '61565';
    state.formatPhone();
    assert.equal(state.phone.value, '61565', 'an incomplete number is left as typed');
    assert.match(source, /@blur="formatPhone"/);
});

test('a search that finds nobody says so', async () => {
    const { state } = mount({ kind: 'ticket', docId: 42 }, { customers: [] });
    state.togglePicker();
    assert.equal(state.searched.value, false, 'nothing said before searching');
    state.customerQuery.value = 'zzz';
    await state.searchCustomers();
    assert.equal(state.searched.value, true);
    assert.equal(state.customers.value.length, 0);
    assert.match(source, /v-if="searched && !customers\.length"[^>]*>Ningún cliente con ese nombre o teléfono/);
});

test('ApiClient asks the link of each document without queueing it offline', () => {
    const api = read('api.js');
    assert.match(api, /whatsappLink: \(kind, id, body\) =>/);
    for (const prefix of ["'/api/ticket/sale/'", "'/api/invoices/'", "'/api/coupons/'"]) {
        assert.ok(api.includes(prefix + ' +') || api.includes(prefix), prefix);
    }
    assert.match(api, /whatsappLink:[^\n]*'\/whatsapp'[^\n]*queueOnNetworkFailure: false/);
});

test('the POS page loads the phone helper and the panel before the screens that use them', () => {
    const page = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');
    const at = (file) => (hasCacheVersionSince(page, file, '20261109') ? page.indexOf('/assets/js/' + file + '?v=') : -1);
    for (const file of ['lib/phone.js', 'components/WhatsAppShare.js', 'components/OrderDetailView.js', 'components/CouponCreationModal.js', 'components/PaymentModal.js', 'api.js']) {
        assert.ok(at(file) > 0, file);
    }
    assert.ok(at('lib/phone.js') < at('components/WhatsAppShare.js'));
    for (const screen of ['components/OrderDetailView.js', 'components/CouponCreationModal.js', 'components/PaymentModal.js']) {
        assert.ok(at('components/WhatsAppShare.js') < at(screen), screen);
    }
});
