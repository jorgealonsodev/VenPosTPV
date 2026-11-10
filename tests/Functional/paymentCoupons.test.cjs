const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function loadPayment({ preview, checkout, couponCheckout = checkout }) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js'), 'utf8');
    const cart = {
        lines: [{ id: 1, product_id: 1, qty: 1, price: 23.14 }], subtotal: 23.14,
        discount: null, discount_amount: 0, total: 23.14, iva: 4.01, customer: { id: 9 },
    };
    const watchers = [];
    const context = {
        Vue: { ref, computed, watch(source, callback) { watchers.push({ source, callback }); } },
        useCart: { cart },
        useSession: { session: {}, hydrateCashSession: async () => {} },
        ApiClient: { couponPreview: preview, couponCheckout, checkout, ticketPdf() {}, ticketReprint: async () => {} },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const emitted = [];
    const state = context.__payment.setup({ total: 23.14 }, { emit: (name, value) => emitted.push([name, value]) });
    return { state, cart, emitted, triggerWatches: () => watchers.forEach(({ source, callback }) => callback(source())) };
}

async function testBlankOrUnverifiedCouponCannotConfirm() {
    const { state } = loadPayment({ preview: async () => ({}), checkout: async () => ({}) });
    state.method.value = 'voucher';
    assert.equal(state.canConfirm(), false);
    await state.applyVoucher();
    assert.match(state.voucherError.value, /Introduce/);
    assert.equal(state.canConfirm(), false);
}

async function testAppliedCouponUsesAuthoritativePayableAndNetCash() {
    const preview = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'SAVE10' }, quote: 'signed.quote' };
    let received;
    const { state, emitted } = loadPayment({ preview: async () => ({ data: preview }), checkout: async (payload) => { received = payload; return { data: { ps_order_id: 88 } }; } });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    assert.equal(state.voucherPayable.value, 13.14);
    assert.equal(state.canConfirm(), true);
    await state.handleConfirm();
    assert.equal(received.payment_method, 'cash');
    assert.equal(received.amount_tendered, 13.14);
    assert.equal(received.cart_data.cash_amount, 13.14);
    assert.equal(received.cart_data.coupon.quote, 'signed.quote');
    state.finish(); // without a printer the sale completes on «Terminar»
    assert.equal(emitted[0][0], 'complete');
}

async function testCouponPreviewInvalidatesOnCartChangeAndCardUsesPayable() {
    const preview = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'SAVE10' }, quote: 'signed.quote' };
    let received;
    const { state, cart, triggerWatches } = loadPayment({ preview: async () => ({ data: preview }), checkout: async (payload) => { received = payload; return { data: { ps_order_id: 89 } }; } });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    cart.lines[0].qty = 2;
    triggerWatches();
    assert.equal(state.canConfirm(), false);
    await state.applyVoucher();
    state.voucherTender.value = 'card';
    await state.handleConfirm();
    assert.equal(received.payment_method, 'card');
    assert.equal(received.amount_tendered, 13.14);
    assert.equal(received.cart_data.card_amount, 13.14);
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

const couponPreview = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'SAVE10' }, quote: 'signed.quote' };

async function assertUncertainCouponOutcomeBlocksEveryCheckout(couponCheckout) {
    let previewCalls = 0;
    let couponCalls = 0;
    let ordinaryCalls = 0;
    const { state, cart, emitted } = loadPayment({
        preview: async () => { previewCalls += 1; return { data: couponPreview }; },
        couponCheckout: async (payload) => { couponCalls += 1; return couponCheckout(payload); },
        checkout: async () => { ordinaryCalls += 1; return { data: { ps_order_id: 99 } }; },
    });
    const cartBefore = JSON.stringify(cart);
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    await state.handleConfirm();
    assert.equal(couponCalls, 1);
    assert.equal(ordinaryCalls, 0);
    assert.equal(state.step.value, 'error');
    assert.equal(state.voucherReconciliationRequired.value, true);
    assert.equal(emitted.length, 0);
    assert.equal(JSON.stringify(cart), cartBefore);

    await state.retryCheckout();
    assert.equal(couponCalls, 1);
    assert.equal(ordinaryCalls, 0);
    state.returnToSelection();
    state.method.value = 'cash';
    state.cashInput.value = '30,00';
    assert.equal(state.canConfirm(), false);
    await state.handleConfirm();
    state.method.value = 'card';
    assert.equal(state.canConfirm(), false);
    await state.handleConfirm();
    state.method.value = 'mixed';
    state.card2.value = '10,00';
    assert.equal(state.canConfirm(), false);
    await state.handleConfirm();
    state.method.value = 'voucher';
    state.voucherCode.value = 'NEXT';
    await state.applyVoucher();
    assert.equal(previewCalls, 1);
    assert.equal(couponCalls, 1);
    assert.equal(ordinaryCalls, 0);
    assert.equal(state.canConfirm(), false);
    assert.equal(emitted.length, 0);
    assert.equal(JSON.stringify(cart), cartBefore);
}

async function testFetchFailureRequiresGlobalReconciliationGuard() {
    await assertUncertainCouponOutcomeBlocksEveryCheckout(async () => {
        throw new TypeError('Failed to fetch');
    });
}

async function test409RequiresGlobalReconciliationGuard() {
    await assertUncertainCouponOutcomeBlocksEveryCheckout(async () => {
        throw new Error('HTTP 409: coupon_reconcile_required');
    });
}

async function testAnUncertainCouponChargeSaysWhatToDoInOneShortMessage() {
    const body = JSON.stringify({ error: 'Coupon checkout outcome is uncertain: PrestaShop order service unavailable: PS module returned unexpected HTTP 200: página de error de la tienda (9390 bytes)', code: 'coupon_reconcile_required', retry: false });
    const { state } = loadPayment({
        preview: async () => ({ data: couponPreview }),
        couponCheckout: async () => { throw new Error('HTTP 409: ' + body); },
        checkout: async () => ({}),
    });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    await state.handleConfirm();
    assert.equal(state.voucherReconciliationRequired.value, true);
    assert.match(state.errorMsg.value, /^No se ha podido confirmar el cobro con cupón en la tienda: el pedido puede haberse creado\. No vuelvas a cobrar esta venta; revisa el pedido en PrestaShop\./);
    assert.match(state.errorMsg.value, /página de error de la tienda/, 'the technical detail stays, short');
    assert.doesNotMatch(state.errorMsg.value, /HTTP 409|\{"error"/);
}

async function testAnAmountVoucherShowsItsBalanceAndWhatIsLeft() {
    const withBalance = { ...couponPreview, discount_amount: 36, before_discount_total: 36, total_paid: 0, voucher: { balance: 64, used: 36, left: 28 } };
    const { state } = loadPayment({ preview: async () => ({ data: withBalance }), checkout: async () => ({}) });
    state.method.value = 'voucher';
    state.voucherCode.value = '9VZ4WTAX-2';
    await state.applyVoucher();
    assert.deepEqual(JSON.parse(JSON.stringify(state.voucherBalance.value)), { balance: 64, used: 36, left: 28 });

    const { state: percent } = loadPayment({ preview: async () => ({ data: couponPreview }), checkout: async () => ({}) });
    percent.method.value = 'voucher';
    percent.voucherCode.value = 'SAVE10';
    await percent.applyVoucher();
    assert.equal(percent.voucherBalance.value, null, 'a discount coupon has no balance');
}

function testTheBoxShowsTheBalanceLines() {
    const source = require('node:fs').readFileSync(require('node:path').resolve(__dirname, '../../public/assets/js/components/PaymentModal.js'), 'utf8');
    assert.match(source, /Saldo del vale[\s\S]{0,200}voucherBalance\.balance/);
    assert.match(source, /Queda en un vale nuevo[\s\S]{0,200}voucherBalance\.left/);
    assert.match(source, /v-if="!voucherBalance"[^>]*>Cupón aplicado como descuento/);
}

async function testCouponSagaFailureRequiresGlobalReconciliationGuard() {
    await assertUncertainCouponOutcomeBlocksEveryCheckout(async () => ({
        data: { ps_order_id: 92, total: 13.14, saga_error: true },
    }));
}

async function testStaleOrOutOfOrderPreviewCannotRestoreConfirmation() {
    const first = deferred();
    const second = deferred();
    const third = deferred();
    let previewCalls = 0;
    const { state, cart, triggerWatches } = loadPayment({
        preview: () => [first, second, third][previewCalls++].promise,
        checkout: async () => ({ data: { ps_order_id: 90 } }),
    });
    state.method.value = 'voucher';
    state.voucherCode.value = 'OLD';
    const oldPreview = state.applyVoucher();
    state.voucherCode.value = 'CHANGED';
    cart.lines[0].price = 30;
    cart.discount = { value: 2 };
    cart.discount_amount = 2;
    cart.total = 28;
    cart.customer = { id: 10 };
    const changedCart = JSON.stringify(cart);
    triggerWatches();
    first.resolve({ data: { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'OLD' }, quote: 'old.quote' } });
    await oldPreview;
    assert.equal(state.voucherPreview.value, null);
    assert.equal(state.canConfirm(), false);
    assert.equal(JSON.stringify(cart), changedCart);

    state.voucherCode.value = 'FIRST';
    const firstCurrentPreview = state.applyVoucher();
    state.voucherCode.value = 'SECOND';
    triggerWatches();
    const secondCurrentPreview = state.applyVoucher();
    third.resolve({ data: { operation: 'coupon_preview', cart_id: 19, before_discount_total: 28, discount_amount: 10, total_paid: 18, coupon: { code: 'SECOND' }, quote: 'second.quote' } });
    await secondCurrentPreview;
    second.resolve({ data: { operation: 'coupon_preview', cart_id: 18, before_discount_total: 28, discount_amount: 10, total_paid: 18, coupon: { code: 'FIRST' }, quote: 'first.quote' } });
    await firstCurrentPreview;
    assert.equal(previewCalls, 3);
    assert.equal(state.voucherPreview.value.coupon.code, 'SECOND');
    assert.equal(state.canConfirm(), true);
    assert.equal(JSON.stringify(cart), changedCart);
}

async function testOrdinaryCashCardAndMixedRemainUnchanged() {
    const payments = [];
    const checkout = async (payload) => { payments.push(payload); return { data: { ps_order_id: payments.length, total: 23.14, change_due: 0 } }; };

    const cash = loadPayment({ preview: async () => ({}), checkout });
    cash.state.cashInput.value = '30,00';
    await cash.state.handleConfirm();
    assert.equal(payments[0].payment_method, 'cash');
    assert.equal(payments[0].amount_tendered, 30);
    assert.equal(payments[0].cart_data.cash_amount, 23.14);

    const card = loadPayment({ preview: async () => ({}), checkout });
    card.state.method.value = 'card';
    await card.state.handleConfirm();
    assert.equal(payments[1].payment_method, 'card');
    assert.equal(payments[1].amount_tendered, 23.14);
    assert.equal(payments[1].cart_data.card_amount, 23.14);

    const mixed = loadPayment({ preview: async () => ({}), checkout });
    mixed.state.method.value = 'mixed';
    mixed.state.card2.value = '10,00';
    await mixed.state.handleConfirm();
    assert.equal(payments[2].payment_method, 'mixed');
    assert.equal(payments[2].amount_tendered, 23.14);
    assert.equal(payments[2].cart_data.cash_amount, 10);
    assert.equal(payments[2].cart_data.card_amount, 13.14);
}

async function testVoucherDoneShowsServerNetAndCashChange() {
    const preview = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 23.14, total_paid: 0, coupon: { code: 'SAVE23' }, quote: 'signed.quote' };
    const { state } = loadPayment({
        preview: async () => ({ data: preview }),
        checkout: async () => ({ data: { ps_order_id: 91, total: 0, change_due: 7 } }),
    });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE23';
    await state.applyVoucher();
    state.voucherCashInput.value = '7,00';
    await state.handleConfirm();
    assert.equal(state.completedTotal.value, 0);
    assert.equal(state.completedChange.value, 7);
    assert.equal(state.doneTotal.value, 0);
    assert.equal(state.doneChange.value, 7);
}

async function testARejectedCouponShowsTheServerSentenceNotTheRawResponse() {
    const { state } = loadPayment({
        preview: async () => { throw new Error('HTTP 422: {"error":"Este cupón ya se ha usado.","retry":false}'); },
        checkout: async () => ({}),
    });
    state.method.value = 'voucher';
    state.voucherCode.value = 'R24GCYH4';
    await state.applyVoucher();
    assert.equal(state.voucherError.value, 'Este cupón ya se ha usado.');
    assert.equal(state.canConfirm(), false);
}

async function main() {
    await testBlankOrUnverifiedCouponCannotConfirm();
    await testAppliedCouponUsesAuthoritativePayableAndNetCash();
    await testCouponPreviewInvalidatesOnCartChangeAndCardUsesPayable();
    await test409RequiresGlobalReconciliationGuard();
    await testFetchFailureRequiresGlobalReconciliationGuard();
    await testCouponSagaFailureRequiresGlobalReconciliationGuard();
    await testStaleOrOutOfOrderPreviewCannotRestoreConfirmation();
    await testOrdinaryCashCardAndMixedRemainUnchanged();
    await testVoucherDoneShowsServerNetAndCashChange();
    await testARejectedCouponShowsTheServerSentenceNotTheRawResponse();
    await testAnUncertainCouponChargeSaysWhatToDoInOneShortMessage();
    await testAnAmountVoucherShowsItsBalanceAndWhatIsLeft();
    testTheBoxShowsTheBalanceLines();
    console.log('paymentCoupons functional harness: 13 passed');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
