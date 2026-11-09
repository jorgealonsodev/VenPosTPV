const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// «Crear cupón» → after «Código creado» the cashier prints the coupon on the till's
// ticket printer, opens its PDF or emails it.

function ref(value) { return { value }; }

function loadModal(api) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/CouponCreationModal.js'), 'utf8');
    const context = {
        Vue: { ref }, ApiClient: api, Date, Number, String, Math, parseInt, parseFloat, Promise, Error, JSON, console,
        navigator: { clipboard: { writeText: async () => {} } },
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = CouponCreationModal;`, context, { filename: 'CouponCreationModal.js' });
    return context.__modal;
}

function api(overrides = {}) {
    const calls = [];
    const client = {
        calls,
        createCoupon: async (payload) => { calls.push(['create', payload]); return { data: { id: 42, local_id: 5, printable: true, code: 'ABCD2345' } }; },
        ticketEmailEnabled: async () => { calls.push(['emailEnabled']); return { data: { enabled: true } }; },
        couponPrint: async (id) => { calls.push(['print', id]); return { data: { print_status: 'queued', print_job_id: 70 } }; },
        couponPdf: (id) => { calls.push(['pdf', id]); },
        couponEmail: async (id, email) => { calls.push(['email', id, email]); return { data: { sent: true } }; },
        ...overrides,
    };
    return client;
}

async function created(client) {
    const state = loadModal(client).setup({}, { emit: () => {} });
    state.value.value = '10';
    state.expiresAt.value = '2027-05-03';
    await state.submit();
    return state;
}

async function testTheThreeSlipActionsUseTheLocalCouponId() {
    const client = api();
    const state = await created(client);
    assert.equal(state.createdCode.value, 'ABCD2345');
    assert.equal(state.couponId.value, 5);
    assert.equal(state.printable.value, true);
    assert.equal(state.emailEnabled.value, true);

    await state.printSlip();
    assert.deepEqual(client.calls.find((c) => c[0] === 'print'), ['print', 5]);
    assert.match(state.slipMessage.value, /impresora/i);

    state.openPdf();
    assert.deepEqual(client.calls.find((c) => c[0] === 'pdf'), ['pdf', 5]);

    state.emailOpen.value = true;
    state.emailAddress.value = 'cliente@example.com';
    await state.sendEmail();
    assert.deepEqual(client.calls.find((c) => c[0] === 'email'), ['email', 5, 'cliente@example.com']);
    assert.match(state.slipMessage.value, /Cupón enviado a cliente@example\.com/);
    assert.equal(state.slipError.value, '');
}

async function testATillWithoutPrinterOpensThePdfInstead() {
    const client = api({ couponPrint: async (id) => { client.calls.push(['print', id]); return { data: { fallback: 'pdf' } }; } });
    const state = await created(client);
    await state.printSlip();
    assert.deepEqual(client.calls.filter((c) => c[0] === 'pdf'), [['pdf', 5]]);
    assert.match(state.slipMessage.value, /PDF/);
}

async function testErrorsAreShownAsTheBackendSentence() {
    const client = api({
        couponPrint: async () => { throw new Error('HTTP 500: {"error":"No se ha podido imprimir el cupón"}'); },
        couponEmail: async () => { throw new Error('HTTP 422: {"error":"El email del cliente no es v\\u00e1lido."}'); },
    });
    const state = await created(client);
    await state.printSlip();
    assert.equal(state.slipError.value, 'No se ha podido imprimir el cupón');
    state.emailAddress.value = 'no-es-email';
    await state.sendEmail();
    assert.equal(state.slipError.value, 'El email del cliente no es válido.');
    assert.equal(state.slipMessage.value, '');
}

async function testTheEmailButtonIsHiddenWhenMailIsDisabled() {
    const disabled = await created(api({ ticketEmailEnabled: async () => ({ data: { enabled: false } }) }));
    assert.equal(disabled.emailEnabled.value, false);
    const failing = await created(api({ ticketEmailEnabled: async () => { throw new Error('HTTP 500: x'); } }));
    assert.equal(failing.emailEnabled.value, false);

    const template = loadModal(api()).template;
    assert.match(template, /Imprimir ticket/);
    assert.match(template, />\s*PDF\s*</);
    assert.match(template, /v-if="emailEnabled"[^>]*>\s*Enviar por email/);
    assert.match(template, /Enviar<\/button>|>\s*\{\{ slipBusy \? 'Enviando…' : 'Enviar' \}\}/);
    assert.match(template, /Copiado/);
    assert.match(template, /Cerrar/);
}

async function testACouponWithoutLocalCopyOffersNoSlip() {
    const client = api({ createCoupon: async () => ({ data: { id: 42, local_id: null, printable: false, code: 'ABCD2345' } }) });
    const state = await created(client);
    assert.equal(state.createdCode.value, 'ABCD2345', 'the created code is still shown');
    assert.equal(state.printable.value, false);
    await state.printSlip();
    assert.equal(client.calls.filter((c) => c[0] === 'print').length, 0);
    const template = loadModal(client).template;
    assert.match(template, /v-if="printable"/);
}

async function testResetClearsTheSlipState() {
    const state = await created(api());
    state.emailOpen.value = true;
    state.slipMessage.value = 'x';
    state.reset();
    assert.equal(state.couponId.value, null);
    assert.equal(state.emailOpen.value, false);
    assert.equal(state.slipMessage.value, '');
}

function testTheApiClientAndThePageVersion() {
    const apiSource = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/api.js'), 'utf8');
    assert.match(apiSource, /couponPrint: \(couponId\) => request\('\/api\/coupons\/' \+ encodeURIComponent\(couponId\) \+ '\/print', \{ method: 'POST'[^\n]*queueOnNetworkFailure: false/);
    assert.match(apiSource, /window\.open\(BASE_URL \+ '\/api\/coupons\/' \+ encodeURIComponent\(couponId\) \+ '\/pdf', '_blank'\)/);
    assert.match(apiSource, /couponEmail: \(couponId, email\) => request\([^\n]*'\/email', \{ method: 'POST', body: JSON\.stringify\(\{ email \}\), queueOnNetworkFailure: false/);
    const page = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');
    assert.match(page, /CouponCreationModal\.js\?v=20261109-/);
    assert.match(page, /js\/api\.js\?v=20261109-/);
}

async function main() {
    await testTheThreeSlipActionsUseTheLocalCouponId();
    await testATillWithoutPrinterOpensThePdfInstead();
    await testErrorsAreShownAsTheBackendSentence();
    await testTheEmailButtonIsHiddenWhenMailIsDisabled();
    await testACouponWithoutLocalCopyOffersNoSlip();
    await testResetClearsTheSlipState();
    testTheApiClientAndThePageVersion();
    console.log('couponSlip functional harness: 7 passed');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
