const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Ajustes → Tienda → «Métodos de pago»: the payment window offers only the methods
// the shop chose (all of them until it chooses), for every till.
const root = path.resolve(__dirname, '../..');
const PAYMENT = fs.readFileSync(path.join(root, 'public/assets/js/components/PaymentModal.js'), 'utf8');
const SETTINGS = fs.readFileSync(path.join(root, 'public/assets/js/components/SettingsModal.js'), 'utf8');
const REFUND = fs.readFileSync(path.join(root, 'public/assets/js/components/RefundScreen.js'), 'utf8');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function payment(paymentMethods) {
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart: { lines: [], subtotal: 10, discount: null, discount_amount: 0, total: 10, iva: 1.74, customer: null } },
        useSession: { session: { paymentMethods }, hydrateCashSession: async () => {} },
        ApiClient: {}, setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error, Array,
    };
    context.globalThis = context;
    vm.runInNewContext(`${PAYMENT}\nglobalThis.__p = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    return context.__p.setup({ total: 10 }, { emit() {} });
}

test('the payment window shows only the methods the shop offers, and opens on the first one', () => {
    const state = payment(['card', 'transfer']);

    assert.equal(state.methods.map((m) => m.id).join(','), 'card,transfer');
    assert.equal(state.method.value, 'card', 'cash is off: the window opens on card');
});

test('without a choice (or before it is read) every method is offered', () => {
    assert.equal(payment(null).methods.map((m) => m.id).join(','), 'cash,card,mixed,transfer,voucher');
    assert.equal(payment([]).methods.length, 5);
});

test('after a coupon, the rest is paid only with the enabled cash, card or transfer', () => {
    const state = payment(['transfer', 'voucher']);

    assert.equal(state.voucherTenders.join(','), 'transfer');
    assert.equal(state.voucherTender.value, 'transfer');
    assert.match(PAYMENT, /v-if="voucherTenders.includes\('cash'\)" @click="voucherTender = 'cash'"/);
});

function settings(api) {
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: api,
        useSession: { session: { terminalId: null, paymentMethods: null }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout: () => 0, Promise, Number, String, Math, JSON, console, Array, Object, Set,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SETTINGS}\nglobalThis.__s = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const emitted = [];
    return { state: context.__s.setup({}, { emit: (n) => emitted.push(n) }), context, emitted };
}

test('Ajustes → Tienda turns methods off and on, saves them and the session follows', async () => {
    const sent = [];
    const { state, context } = settings({
        paymentMethods: async () => ({ data: { enabled: ['cash', 'card', 'mixed', 'transfer', 'voucher'] } }),
        savePaymentMethods: async (enabled) => { sent.push(enabled.slice()); return { data: { enabled } }; },
        get: async () => ({ data: [] }),
    });
    await state.loadPaymentMethods();

    state.togglePayMethod('mixed');
    state.togglePayMethod('voucher');
    await state.savePaymentMethods();

    assert.equal(JSON.stringify(sent), JSON.stringify([['cash', 'card', 'transfer']]));
    assert.equal(context.useSession.session.paymentMethods.join(','), 'cash,card,transfer');
});

test('a choice the server refuses shows why; closing Ajustes saves a pending change', async () => {
    const sent = [];
    const { state, emitted } = settings({
        paymentMethods: async () => ({ data: { enabled: ['cash', 'card'] } }),
        savePaymentMethods: async (enabled) => {
            sent.push(enabled.slice());
            if (enabled.length === 0) throw new Error('HTTP 422: {"error":"Deja al menos un método de pago activo"}');
            return { data: { enabled } };
        },
        get: async () => ({ data: [] }),
    });
    await state.loadPaymentMethods();

    state.togglePayMethod('cash');
    state.togglePayMethod('card');
    await state.closeSettings();
    assert.equal(emitted.length, 0, 'not closed: nothing would be offered');
    assert.match(state.payMethodsError.value, /al menos un método/);

    state.togglePayMethod('transfer');
    await state.closeSettings();
    assert.deepEqual(emitted, ['close']);
    assert.equal(JSON.stringify(sent.at(-1)), JSON.stringify(['transfer']));
});

test('an exchange difference is paid only with the enabled methods', () => {
    assert.match(REFUND, /useSession\.session\.paymentMethods\.includes\(m\.code\)/);
});
