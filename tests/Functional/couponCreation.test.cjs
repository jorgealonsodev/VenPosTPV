const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

function loadModal(createCoupon) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/CouponCreationModal.js'), 'utf8');
    const context = {
        Vue: { ref }, ApiClient: { createCoupon }, Date, Number, String, Math, parseInt, parseFloat, Promise, Error, console,
        navigator: { clipboard: { writeText: async () => {} } },
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = CouponCreationModal;`, context, { filename: 'CouponCreationModal.js' });
    const emitted = [];
    const modal = context.__modal;
    // Every call models one open/close lifecycle of the same modal in the page.
    function createInstance() {
        return modal.setup({}, { emit: (...args) => emitted.push(args) });
    }
    return { createInstance, emitted, modal };
}

async function testAmountAndPercentageAreEditableAndSubmitThePublicPayload() {
    const payloads = [];
    const { createInstance } = loadModal(async (payload) => {
        payloads.push(payload);
        return { data: { id: 7, code: 'TPV-ABCD', type: payload.discount_type, value: payload.value, expires_at: payload.expires_at, quantity: payload.quantity, quantity_per_user: payload.quantity_per_user } };
    });
    const state = createInstance();
    assert.equal(state.quantity.value, 1);
    assert.equal(state.quantityPerUser.value, 1);
    state.discountType.value = 'amount';
    state.value.value = '12.50';
    state.expiresAt.value = '2027-05-03';
    state.quantity.value = '3';
    state.quantityPerUser.value = '2';
    await state.submit();
    // An amount coupon is a balance voucher: one use that keeps its rest, whatever the hidden fields held.
    assert.deepEqual(JSON.parse(JSON.stringify(payloads[0])), { discount_type: 'amount', value: 12.5, expires_at: '2027-05-03', quantity: 1, quantity_per_user: 1, partial_use: true });
    assert.equal(state.createdCode.value, 'TPV-ABCD');
    state.reset();
    state.discountType.value = 'percentage';
    state.value.value = '15';
    state.quantity.value = '3';
    state.quantityPerUser.value = '2';
    await state.submit();
    assert.deepEqual(JSON.parse(JSON.stringify(payloads[1])), { discount_type: 'percentage', value: 15, expires_at: payloads[1].expires_at, quantity: 3, quantity_per_user: 2 });
}

/** «Usos totales» and «Usos por cliente» are asked for percentage coupons only. */
function testUsesAreHiddenForAmountCoupons() {
    const { modal, createInstance } = loadModal(async () => ({}));
    const uses = modal.template.match(/<div v-if="([^"]+)"[^>]*>\s*<label[^>]*>Usos totales/);
    assert.ok(uses, 'the uses fields sit in a conditional block');
    assert.equal(uses[1], "discountType === 'percentage'");
    assert.match(modal.template, /<label[^>]*>Usos por cliente/);
    assert.equal(modal.template.split('Usos por cliente').length - 1, 1);
    assert.match(modal.template, /<div v-if="discountType === 'amount'"[^>]*>Vale de un solo uso\. Si la compra es menor, el saldo que sobra queda en un vale nuevo\.<\/div>/);
    // Invalid uses left in the hidden fields never block an amount coupon.
    const state = createInstance();
    state.discountType.value = 'amount';
    state.value.value = '20';
    state.quantity.value = '0';
    state.quantityPerUser.value = '0';
    assert.equal(state.validationError(), '');
    state.discountType.value = 'percentage';
    assert.match(state.validationError(), /usos/i);
}

async function testInvalidFormNeverRequestsAndPendingOrAmbiguousCreationCannotBeResubmitted() {
    let calls = 0;
    const { createInstance } = loadModal(async () => { calls += 1; throw new Error('HTTP 409: possible duplicate; code TPV-LOCK'); });
    const state = createInstance();
    state.value.value = '0';
    await state.submit();
    assert.equal(calls, 0);
    assert.match(state.error.value, /importe/i);
    state.value.value = '10';
    state.expiresAt.value = '2027-05-03';
    const first = state.submit();
    const second = state.submit();
    await Promise.all([first, second]);
    assert.equal(calls, 1);
    assert.equal(state.reconciliationRequired.value, true);
    assert.match(state.error.value, /TPV-LOCK/);
    await state.submit();
    assert.equal(calls, 1);
}

function testSaleScreenShowsCouponEntryToBothAuthorizedRolesOnly() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SaleScreen.js'), 'utf8');
    const appSource = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/app.js'), 'utf8');
    const pageSource = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');
    assert.match(source, /session\.role === 'admin' \|\| session\.role === 'cashier'/);
    assert.match(source, /Crear cupón/);
    assert.match(source, /<icon name="coupon" :size="13" \/> Crear cupón/);
    assert.doesNotMatch(source, /<icon name="discount"/);
    assert.doesNotMatch(source, /<icon name="tag"[^>]*\/> Crear cupón/);
    assert.doesNotMatch(source, /showSettings = true[\s\S]{0,160}Crear cupón/);
    assert.match(appSource, /app\.component\('coupon-creation-modal', CouponCreationModal\)/);
    assert.match(pageSource, /components\/CouponCreationModal\.js\?v=20261109-[a-z0-9-]+/);
    assert.match(pageSource, /SaleScreen\.js\?v=[^"']+/);
}

async function testAmbiguousCreationKeepsReopenedModalLocked() {
    let calls = 0;
    const { createInstance } = loadModal(async () => {
        calls += 1;
        throw new Error('HTTP 409: possible duplicate; code TPV-LOCK');
    });

    const first = createInstance();
    first.value.value = '10';
    first.expiresAt.value = '2027-05-03';
    await first.submit();
    assert.equal(calls, 1);
    assert.equal(first.reconciliationRequired.value, true);

    // The user closes the warning and reopens the modal: a brand new instance of
    // the same page must inherit the ambiguity lock instead of offering a retry.
    const reopened = createInstance();
    assert.equal(reopened.reconciliationRequired.value, true, 'reopened modal must stay reconciliation-locked');
    assert.match(reopened.error.value, /TPV-LOCK/);
    assert.doesNotMatch(reopened.error.value, /HTTP 409/);
    reopened.value.value = '10';
    await reopened.submit();
    assert.equal(calls, 1, 'reopened modal must not POST again');
    assert.equal(reopened.createdCode.value, '');

    // A lost response is the same uncertainty, so it inherits the same lock.
    const afterTransport = createInstance();
    assert.equal(afterTransport.reconciliationRequired.value, true);
    await afterTransport.submit();
    assert.equal(calls, 1);
}

async function testDefinitiveCreationFailuresKeepTheModalReusable() {
    let calls = 0;
    const { createInstance } = loadModal(async () => {
        calls += 1;
        throw new Error('HTTP 422: valor no válido');
    });
    const first = createInstance();
    first.value.value = '10';
    await first.submit();
    assert.equal(calls, 1);
    assert.equal(first.reconciliationRequired.value, false);
    assert.match(first.error.value, /valor no válido/);
    assert.doesNotMatch(first.error.value, /HTTP 422/);

    const reopened = createInstance();
    assert.equal(reopened.reconciliationRequired.value, false, 'a definite no-create failure must stay retryable');
    reopened.value.value = '10';
    await reopened.submit();
    assert.equal(calls, 2);

    let unavailable = 0;
    const service = loadModal(async () => {
        unavailable += 1;
        throw new Error('HTTP 503: service unavailable');
    });
    const retryable = service.createInstance();
    retryable.value.value = '10';
    await retryable.submit();
    assert.equal(unavailable, 1);
    assert.equal(retryable.reconciliationRequired.value, false);
    const serviceReopened = service.createInstance();
    assert.equal(serviceReopened.reconciliationRequired.value, false);
    serviceReopened.value.value = '10';
    await serviceReopened.submit();
    assert.equal(unavailable, 2);
}

async function testAmbiguousBackendJsonErrorIsShownAsCleanSpanishText() {
    let calls = 0;
    const { createInstance, modal } = loadModal(async () => {
        calls += 1;
        throw new Error('HTTP 409: {"error":"No se pudo confirmar la creaci\\u00f3n del cup\\u00f3n TPV-ABC. Compru\\u00e9balo en PrestaShop antes de repetirlo."}');
    });

    const state = createInstance();
    state.value.value = '10';
    state.expiresAt.value = '2027-05-03';
    await state.submit();

    assert.equal(calls, 1);
    assert.equal(state.reconciliationRequired.value, true, 'an unconfirmed 409 must stay locked');
    assert.match(state.error.value, /No se pudo confirmar la creación del cupón TPV-ABC/);
    assert.match(state.error.value, /Compruébalo en PrestaShop antes de repetirlo\./);
    assert.doesNotMatch(state.error.value, /\\u00f3/, 'JSON unicode escapes must not reach the cashier');
    assert.doesNotMatch(state.error.value, /HTTP 409/);
    assert.doesNotMatch(state.error.value, /\{"error"/);
    assert.equal(state.createdCode.value, '');

    // The reconciliation banner carries the summary once; the detail line carries
    // the backend text once, so the cashier does not read the same sentence twice.
    assert.match(modal.template, /Creación sin confirmar: no se puede volver a enviar desde este TPV\./);
    assert.equal(state.error.value.split('No se pudo confirmar la creación del cupón TPV-ABC').length - 1, 1);
    assert.doesNotMatch(modal.template, /No vuelvas a enviarlo/);
}

async function main() {
    await testAmountAndPercentageAreEditableAndSubmitThePublicPayload();
    testUsesAreHiddenForAmountCoupons();
    await testAmbiguousBackendJsonErrorIsShownAsCleanSpanishText();
    await testInvalidFormNeverRequestsAndPendingOrAmbiguousCreationCannotBeResubmitted();
    await testAmbiguousCreationKeepsReopenedModalLocked();
    testSaleScreenShowsCouponEntryToBothAuthorizedRolesOnly();
    await testDefinitiveCreationFailuresKeepTheModalReusable();
    console.log('couponCreation functional harness: 6 passed');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
