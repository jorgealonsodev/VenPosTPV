const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// «Transferencia»: the cashier saw the transfer done; the whole total is charged now,
// with no cash and no drawer (docs/pago-transferencia.md).
const root = path.resolve(__dirname, '../..');
const SOURCE = fs.readFileSync(path.join(root, 'public/assets/js/components/PaymentModal.js'), 'utf8');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function load({ preview } = {}) {
    const sent = [];
    const answer = async (payload) => { sent.push(payload); return { data: { order_id: 5, payment_method: payload.payment_method } }; };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart: { lines: [{ id: 1, product_id: 1, qty: 1, price: 23.14 }], subtotal: 23.14, discount: null, discount_amount: 0, total: 23.14, iva: 4.01, customer: { id: 9 } } },
        useSession: { session: {}, hydrateCashSession: async () => {} },
        ApiClient: { checkout: answer, couponCheckout: answer, couponPreview: preview, ticketPdf() {}, ticketReprint: async () => {} },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const state = context.__payment.setup({ total: 23.14 }, { emit() {} });
    return { state, sent };
}

test('«Transferencia» is a payment method next to the others', () => {
    const { state } = load();
    assert.equal(state.methods.map((m) => m.id).join(','), 'cash,card,mixed,transfer,voucher');
    assert.equal(state.methods.find((m) => m.id === 'transfer').label, 'Transferencia');
});

test('a transfer charges the whole total, with nothing in cash or card', async () => {
    const { state, sent } = load();
    state.method.value = 'transfer';
    assert.equal(state.canConfirm(), true, 'nothing to type: the cashier confirms');

    await state.handleConfirm();

    assert.equal(sent.length, 1);
    assert.equal(sent[0].payment_method, 'transfer');
    assert.equal(sent[0].amount_tendered, 23.14);
    assert.equal(sent[0].cart_data.cash_amount, 0);
    assert.equal(sent[0].cart_data.card_amount, 0);
});

test('with a coupon, the rest can be paid by transfer', async () => {
    const preview = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 23.14, discount_amount: 10, total_paid: 13.14, coupon: { code: 'SAVE10' }, quote: 'signed.quote' };
    const { state, sent } = load({ preview: async () => ({ data: preview }) });
    state.method.value = 'voucher';
    state.voucherCode.value = 'SAVE10';
    await state.applyVoucher();
    state.voucherTender.value = 'transfer';
    assert.equal(state.canConfirm(), true);

    await state.handleConfirm();

    assert.equal(sent[0].payment_method, 'transfer');
    assert.equal(sent[0].amount_tendered, 13.14);
    assert.equal(sent[0].cart_data.cash_amount, 0);
    assert.equal(sent[0].cart_data.card_amount, 0);
});

test('the window shows a transfer panel and its own confirm button', () => {
    assert.match(SOURCE, /<template v-if="method === 'transfer'">/);
    assert.match(SOURCE, /Registrar cobro por transferencia/);
    assert.match(SOURCE, /@click="voucherTender = 'transfer'"/);
});
