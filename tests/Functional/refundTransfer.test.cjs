const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// A ticket paid by bank transfer is paid back by transfer (the admin makes it from
// the bank) or in cash, as the cashier chooses (docs/pago-transferencia.md).
const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');

function ref(value) { return { value }; }

function load() {
    const calls = [];
    const context = {
        Vue: { ref },
        ApiClient: {
            refundOrder: async (id, request) => { calls.push(request); return { data: { refunded_amount: 24.18 } }; },
            exchangeOrder: async (id, request) => { calls.push(request); return { data: {} }; },
            refundRetry: async () => ({ data: {} }), refundPdf() {}, searchOrders: async () => ({ data: [] }),
        },
        useSession: { session: { online: true } }, Number, Math, JSON, parseInt, Boolean, Promise, Error, Array, String, RegExp, console,
        decodeURIComponent, atob,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__screen = RefundScreen;`, context, { filename: 'RefundScreen.js' });
    const state = context.__screen.setup({}, { emit() {} });
    return { state, calls };
}

const plain = (value) => JSON.parse(JSON.stringify(value));

const TRANSFER_ORDER = {
    order_id: 7, ps_order_id: 42, reference: 'A26000042', payment_method: 'transfer', tender: { cash: 0, card: 0, transfer: 60 },
    lines: [
        { name: 'Camisa M', product_id: 3, id_product_attribute: 7, qty: 1, paid_total: 24.18, refundable_qty: 1 },
        { name: 'Calcetines', product_id: 4, id_product_attribute: 0, qty: 2, paid_total: 35.82, refundable_qty: 2 },
    ],
};

function pickFirstLine(state) {
    state.selectOrder(TRANSFER_ORDER);
    state.toggleLine(0);
    state.setReason('size');
}

test('a transfer ticket goes back by transfer by default, with no card confirmation', async () => {
    const { state, calls } = load();
    pickFirstLine(state);

    assert.equal(state.isTransferSale(), true);
    assert.deepEqual(plain(state.estimatedSplit()), { cash: 0, card: 0, transfer: 24.18 });
    assert.equal(state.validationError(), '', 'nothing to confirm in the dataphone');

    await state.confirmRefund();

    assert.equal(calls[0].transfer_refund, 'transfer');
});

test('the cashier can pay a transfer back in cash instead', async () => {
    const { state, calls } = load();
    pickFirstLine(state);

    state.setTransferRefund('cash');
    assert.deepEqual(plain(state.estimatedSplit()), { cash: 24.18, card: 0, transfer: 0 });

    await state.confirmRefund();

    assert.equal(calls[0].transfer_refund, 'cash');
});

test('choosing another ticket starts again with «Por transferencia»', () => {
    const { state } = load();
    pickFirstLine(state);
    state.setTransferRefund('cash');

    state.selectOrder({ ...TRANSFER_ORDER, order_id: 8 });

    assert.equal(state.transferRefund.value, 'transfer');
});

test('a ticket not paid by transfer sends no transfer choice', async () => {
    const { state, calls } = load();
    state.selectOrder({ ...TRANSFER_ORDER, payment_method: 'cash', tender: { cash: 60, card: 0 } });
    state.toggleLine(0);
    state.setReason('size');

    assert.equal(state.isTransferSale(), false);
    await state.confirmRefund();

    assert.equal('transfer_refund' in calls[0], false);
});

test('an exchange balance can be paid by transfer', () => {
    const { state } = load();
    assert.ok(state.paymentMethods.some((m) => m.code === 'transfer' && m.label === 'Transferencia'));
});

test('the screen shows the transfer share and the two choices', () => {
    assert.match(SOURCE, /Por transferencia \(la hace el administrador desde el banco\)/);
    assert.match(SOURCE, /@click="setTransferRefund\('transfer'\)"/);
    assert.match(SOURCE, /@click="setTransferRefund\('cash'\)"/);
});
