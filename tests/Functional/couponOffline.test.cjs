// PRD V5 / A5: "Con PrestaShop parado, un cupón se rechaza con aviso y la venta sin
// cupón se cobra." The payment screen shows the server's coupon_offline message and
// offers "Cobrar sin cupón", which drops the coupon and charges the same cart through
// the normal local-first checkout. Any other coupon failure still locks the screen.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = process.env.PAYMENT_MODAL_SOURCE || path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js');
const OFFLINE = 'Sin conexión con PrestaShop: no se puede aplicar el cupón. Puedes cobrar sin él.';
const OFFLINE_ERROR = 'HTTP 503: ' + JSON.stringify({ error: OFFLINE, code: 'coupon_offline', retry: false });
const PREVIEW = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'SAVE10' }, quote: 'signed.quote' };

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function loadPayment({ preview, couponCheckout, checkout }) {
    const source = fs.readFileSync(SOURCE, 'utf8');
    const cart = {
        lines: [{ id: 1, product_id: 1, qty: 1, price: 23.14 }], subtotal: 23.14,
        discount: null, discount_amount: 0, total: 23.14, iva: 4.01, customer: { local_id: 3, id: null, name: 'Marta Ruiz' },
    };
    const calls = { preview: [], coupon: [], checkout: [] };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart },
        useSession: { session: {}, hydrateCashSession: async () => {} },
        ApiClient: {
            couponPreview: async (p) => { calls.preview.push(p); return preview(p); },
            couponCheckout: async (p) => { calls.coupon.push(p); return couponCheckout(p); },
            checkout: async (p) => { calls.checkout.push(p); return checkout(p); },
            ticketPdf() {}, ticketSalePdf() {}, ticketReprint: async () => {}, ticketReprintSale: async () => {},
        },
        crypto: { randomUUID: (() => { let n = 0; return () => 'attempt-' + (++n); })() },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const emitted = [];
    const state = context.__payment.setup({ total: 23.14 }, { emit: (name, value) => emitted.push([name, value]) });

    return { state, calls, emitted };
}

const localSale = async () => ({ data: { order_id: 41, ps_order_id: null, reference: 'A26000041', total: 23.14, change_due: 1.86 } });

async function testPreviewOfflineShowsTheWarningAndOffersChargingWithoutTheCoupon() {
    const { state, calls } = loadPayment({ preview: async () => { throw new Error(OFFLINE_ERROR); }, couponCheckout: async () => ({}), checkout: localSale });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();

    assert.equal(state.voucherError.value, OFFLINE);
    assert.equal(state.couponOffline.value, true);
    assert.equal(state.voucherReconciliationRequired.value, false, 'nothing was booked: no reconciliation');

    await state.chargeWithoutCoupon();
    assert.equal(state.method.value, 'cash', 'back to the normal tender, coupon removed');
    assert.equal(state.voucherCode.value, '');
    assert.equal(state.voucherPreview.value, null);
    assert.equal(state.couponOffline.value, false);
    assert.equal(calls.checkout.length, 0, 'at preview time the cashier still confirms the amount');
    state.cashInput.value = '25,00';
    await state.handleConfirm();
    assert.equal(calls.checkout.length, 1);
    assert.equal(calls.checkout[0].cart_data.coupon, undefined);
    assert.equal(calls.coupon.length, 0, 'no coupon checkout at all');
}

async function testCheckoutOfflineByCardChargesTheSameCartLocallyWithoutTheCoupon() {
    const { state, calls, emitted } = loadPayment({ preview: async () => ({ data: PREVIEW }), couponCheckout: async () => { throw new Error(OFFLINE_ERROR); }, checkout: localSale });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    state.voucherTender.value = 'card';
    await state.handleConfirm();

    assert.equal(state.step.value, 'error');
    assert.equal(state.errorMsg.value, OFFLINE);
    assert.equal(state.couponOffline.value, true);
    assert.equal(state.voucherReconciliationRequired.value, false);
    assert.equal(state.errorRetry.value, false, 'the coupon attempt itself is not retried');
    assert.match(calls.coupon[0].request_id, /^attempt-/, 'coupon checkouts carry a request_id too');

    await state.chargeWithoutCoupon();
    assert.equal(calls.checkout.length, 1, 'retried locally at once');
    const sale = calls.checkout[0];
    assert.equal(sale.payment_method, 'card');
    assert.equal(sale.amount_tendered, 23.14, 'the full total, without the discount');
    assert.equal(sale.cart_data.coupon, undefined);
    assert.equal(sale.cart_data.card_amount, 23.14);
    assert.deepEqual(sale.cart_data.customer, { local_id: 3, id: null, name: 'Marta Ruiz' });
    assert.notEqual(sale.request_id, calls.coupon[0].request_id, 'a new payment attempt');
    assert.equal(state.step.value, 'done');
    state.finish(); // without a printer the sale completes on «Terminar»
    assert.equal(emitted[0][0], 'complete');
}

async function testCheckoutOfflineByCashThatNoLongerCoversTheTotalGoesBackToTheKeypad() {
    const { state, calls } = loadPayment({ preview: async () => ({ data: PREVIEW }), couponCheckout: async () => { throw new Error(OFFLINE_ERROR); }, checkout: localSale });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    await state.handleConfirm();
    await state.chargeWithoutCoupon();

    assert.equal(calls.checkout.length, 0, '13,14 € no longer pays 23,14 €');
    assert.equal(state.step.value, 'select');
    assert.equal(state.method.value, 'cash');
    assert.equal(state.cashInput.value, '13,14');
    state.cashInput.value = '30,00';
    await state.handleConfirm();
    assert.equal(calls.checkout.length, 1);
    assert.equal(calls.checkout[0].amount_tendered, 30);
}

async function testAnUncertainCouponFailureStillLocksAndOffersNoChargeWithoutCoupon() {
    const { state, calls } = loadPayment({ preview: async () => ({ data: PREVIEW }), couponCheckout: async () => { throw new Error('HTTP 409: {"code":"coupon_reconcile_required"}'); }, checkout: localSale });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    state.voucherTender.value = 'card';
    await state.handleConfirm();

    assert.equal(state.voucherReconciliationRequired.value, true);
    assert.equal(state.couponOffline.value, false);
    await state.chargeWithoutCoupon();
    assert.equal(calls.checkout.length, 0);
}

(async () => {
    await testPreviewOfflineShowsTheWarningAndOffersChargingWithoutTheCoupon();
    await testCheckoutOfflineByCardChargesTheSameCartLocallyWithoutTheCoupon();
    await testCheckoutOfflineByCashThatNoLongerCoversTheTotalGoesBackToTheKeypad();
    await testAnUncertainCouponFailureStillLocksAndOffersNoChargeWithoutCoupon();
    console.log('couponOffline: 4 tests OK');
})().catch((error) => { console.error(error); process.exitCode = 1; });
