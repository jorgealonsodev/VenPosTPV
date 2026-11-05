const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function loadPayment(total = 37.5, api = {}) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js'), 'utf8');
    const cart = { lines: [{ id: 1, product_id: 1, qty: 1, price: total }], subtotal: total, discount: null, discount_amount: 0, total, iva: 0, customer: null };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart },
        useSession: { session: {}, hydrateCashSession: async () => {} },
        ApiClient: api,
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    return context.__payment.setup({ total }, { emit() {} });
}

test('each banknote button adds its amount to what the customer handed over', () => {
    const state = loadPayment();
    state.method.value = 'cash';
    state.appendNum('preset:20');
    state.appendNum('preset:20');
    state.appendNum('preset:10');
    assert.equal(state.cashInput.value, '50');
    assert.equal(state.cashParsed.value, 50);
});

test('a banknote adds to an amount typed with decimals', () => {
    const state = loadPayment();
    state.method.value = 'cash';
    state.appendNum('2');
    state.appendNum('decimal');
    state.appendNum('5');
    state.appendNum('preset:10');
    assert.equal(state.cashInput.value, '12,50');
});

test('in a mixed payment the buttons add to the cash part', () => {
    const state = loadPayment();
    state.method.value = 'mixed';
    state.appendNum('preset:20');
    state.appendNum('preset:5');
    assert.equal(state.card2.value, '25');
});

test('the ticket is emailed for the last sale, and a refusal is shown', async () => {
    const posts = [];
    const state = loadPayment(10, {
        async post(url, body) {
            posts.push([url, body]);
            if (body.email === 'mal') throw new Error('HTTP 422: {"error":"El email del cliente no es válido."}');
            return { data: { sent: true } };
        },
    });
    state.lastSaleId.value = 15;
    state.ticketEmail.value = 'cliente@example.com';
    await state.sendTicketEmail();
    assert.equal(JSON.stringify(posts[0]), JSON.stringify(['/api/ticket/sale/15/email', { email: 'cliente@example.com' }]));
    assert.match(state.emailStatus.value, /enviado a cliente@example.com/);

    state.ticketEmail.value = 'mal';
    await state.sendTicketEmail();
    assert.equal(state.emailStatus.value, 'El email del cliente no es válido.');
});
