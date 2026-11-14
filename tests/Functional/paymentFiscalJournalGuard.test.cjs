// fiscal-journal S5, D7: while the database is behind the fiscal journal the server
// refuses every sale with HTTP 423 before taking a number or calling PrestaShop. The
// payment screen shows the server's message as is, offers no retry and, for a coupon
// sale, does not lock the screen for reconciliation (nothing reached the shop).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js');
const MESSAGE = 'Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).';
const LOCKED = 'HTTP 423: ' + JSON.stringify({ error: MESSAGE, code: 'fiscal_journal_behind', retry: false });
const PREVIEW = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'SAVE10' }, quote: 'signed.quote' };

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function locked() {
    const error = new Error(LOCKED);
    error.status = 423;
    error.body = { error: MESSAGE, code: 'fiscal_journal_behind', retry: false };
    return error;
}

function loadPayment({ couponCheckout, checkout }) {
    const cart = {
        lines: [{ id: 1, product_id: 1, qty: 1, price: 23.14 }], subtotal: 23.14,
        discount: null, discount_amount: 0, total: 23.14, iva: 4.01, customer: null,
    };
    const calls = { coupon: [], checkout: [] };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart },
        useSession: { session: {}, hydrateCashSession: async () => {} },
        ApiClient: {
            couponPreview: async () => ({ data: PREVIEW }),
            couponCheckout: async (p) => { calls.coupon.push(p); return couponCheckout(p); },
            checkout: async (p) => { calls.checkout.push(p); return checkout(p); },
            ticketPdf() {}, ticketSalePdf() {}, ticketReprint: async () => {}, ticketReprintSale: async () => {},
        },
        crypto: { randomUUID: (() => { let n = 0; return () => 'attempt-' + (++n); })() },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${fs.readFileSync(SOURCE, 'utf8')}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const state = context.__payment.setup({ total: 23.14 }, { emit() {} });

    return { state, calls };
}

test('a blocked till shows the server message for a sale and offers no retry', async () => {
    const { state, calls } = loadPayment({ couponCheckout: async () => ({}), checkout: async () => { throw locked(); } });
    state.cashInput.value = '25,00';
    await state.handleConfirm();

    assert.equal(calls.checkout.length, 1);
    assert.equal(state.step.value, 'error');
    assert.equal(state.errorMsg.value, MESSAGE);
    assert.equal(state.errorRetry.value, false);
});

test('a blocked coupon sale shows the message and needs no reconciliation: the shop was never called', async () => {
    const { state, calls } = loadPayment({ couponCheckout: async () => { throw locked(); }, checkout: async () => ({}) });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    state.voucherTender.value = 'card';
    await state.handleConfirm();

    assert.equal(calls.coupon.length, 1);
    assert.equal(state.step.value, 'error');
    assert.equal(state.errorMsg.value, MESSAGE);
    assert.equal(state.voucherReconciliationRequired.value, false);
    assert.equal(state.couponOffline.value, false, 'charging without the coupon would be refused too');
    assert.equal(state.errorRetry.value, false);
});
