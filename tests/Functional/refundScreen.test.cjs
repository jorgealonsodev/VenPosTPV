const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

function load(refundOrder, refundRetry = async () => ({ data: {} }), opened = []) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/RefundScreen.js'), 'utf8');
    const context = {
        Vue: { ref }, ApiClient: { refundOrder, refundRetry, refundPdf: (id) => opened.push(id), searchOrders: async () => ({ data: [] }) },
        useSession: { session: { online: true } }, Number, Math, JSON, parseInt, Boolean, Promise, Error, Array, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__screen = RefundScreen;`, context, { filename: 'RefundScreen.js' });
    const emitted = [];
    const state = context.__screen.setup({}, { emit: (...args) => emitted.push(args) });
    return { state, emitted };
}

const ORDER = {
    ps_order_id: 42, reference: 'REF42', payment_method: 'mixed', tender: { cash: 25, card: 35 },
    lines: [
        { name: 'Camisa M', product_id: 3, id_product_attribute: 7, qty: 1, paid_total: 24.18, refundable_qty: 1 },
        { name: 'Calcetines', product_id: 4, id_product_attribute: 0, qty: 2, paid_total: 35.82, refundable_qty: 1 },
        { name: 'Venta antigua', qty: 1, price: 5, line_total: 5 },
    ],
};

async function testRequestCarriesOnlyIndexesQuantitiesRestockReasonAndConfirmation() {
    const calls = [];
    const { state, emitted } = load(async (id, request) => { calls.push([id, request]); return { data: { refunded_amount: 24.18, cash_amount: 10.08, card_amount: 14.1 } }; });
    state.selectOrder(ORDER);

    assert.equal(state.selectedLines.value[1].refundable, 1, 'refundable quantity comes from the server');
    state.toggleLine(2);
    assert.equal(state.selectedLines.value[2].checked, false, 'legacy lines without product ids cannot be selected');

    state.toggleLine(0);
    assert.equal(state.validationError(), 'Elige el motivo de la devolución');
    state.setReason('defective');
    assert.equal(state.selectedLines.value[0].restock, false, 'defective unticks restock by default');
    state.selectedLines.value[0].restock = true; // the cashier can still restock
    assert.deepEqual(JSON.parse(JSON.stringify(state.estimatedSplit())), { cash: 10.08, card: 14.1 });
    assert.equal(state.validationError(), 'Confirma que has hecho la devolución en el datáfono');
    state.cardConfirmed.value = true;
    assert.equal(state.validationError(), '');

    await state.confirmRefund();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], 42);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0][1])), {
        lines: [{ line_index: 0, quantity: 1, restock: true }],
        reason_code: 'defective', reason_text: '', card_confirmed: true,
    });
    assert.match(emitted.at(-1)[1], /24\.18 €/);
}

async function testOtherReasonNeedsTextAndQuantityIsCappedAtRefundable() {
    const { state } = load(async () => ({ data: {} }));
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    state.toggleLine(1);
    state.updateRefundQty(1, '5');
    assert.equal(state.selectedLines.value[1].refundQty, 1);
    state.setReason('other');
    assert.equal(state.validationError(), 'Escribe el motivo');
    state.reasonText.value = '  Talla equivocada ';
    assert.equal(state.validationError(), '', 'cash-only refunds need no card confirmation');
    assert.equal(state.buildRequest().reason_text, 'Talla equivocada');
    assert.equal(state.buildRequest().card_confirmed, false);
}

async function testServerErrorsAreShownInSpanishWithDetail() {
    const { state, emitted } = load(async () => { throw new Error('HTTP 422: {"error":"Refund validation failed: Only 0 unit(s) of line 1 are left to refund","retry":false}'); });
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    state.toggleLine(0);
    state.setReason('size');
    await state.confirmRefund();
    assert.equal(emitted.at(-1)[1], 'No se puede devolver: Refund validation failed: Only 0 unit(s) of line 1 are left to refund');
    assert.ok(state.selectedOrder.value, 'stays on the order after an error');
}

async function testPendingRefundBlocksNewOnesAndCanBeRetried() {
    const retried = [];
    const { state, emitted } = load(async () => { throw new Error('must not create a new refund'); }, async (id) => { retried.push(id); return { data: { refunded_amount: 24.18, cash_amount: 24.18 } }; });
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 }, pending_refund: { id: 5, total: 24.18 } });
    state.toggleLine(1);
    state.setReason('size');
    assert.equal(state.validationError(), 'Hay una devolución pendiente de confirmar: reinténtala primero');
    await state.retryPending();
    assert.deepEqual(retried, [5]);
    assert.match(emitted.at(-1)[1], /24\.18 € en efectivo/);
    assert.equal(state.selectedOrder.value, null);
}

async function testCreditNotePdfOpensWhenNotPrinted() {
    const opened = [];
    const { state, emitted } = load(async () => ({ data: { refund_id: 9, credit_note_number: 'R2026-000009', refunded_amount: 24.18, cash_amount: 24.18, print_status: 'pdf' } }), undefined, opened);
    state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    state.toggleLine(0);
    state.setReason('size');
    await state.confirmRefund();
    assert.deepEqual(opened, [9]);
    assert.match(emitted.at(-1)[1], /^Abono R2026-000009: 24\.18 €/);

    const printed = [];
    const second = load(async () => ({ data: { refund_id: 10, print_status: 'printed' } }), undefined, printed);
    second.state.selectOrder({ ...ORDER, tender: { cash: 60, card: 0 } });
    second.state.toggleLine(0);
    second.state.setReason('size');
    await second.state.confirmRefund();
    assert.deepEqual(printed, [], 'no PDF when the printer printed it');
}

(async () => {
    await testCreditNotePdfOpensWhenNotPrinted();
    await testPendingRefundBlocksNewOnesAndCanBeRetried();
    await testRequestCarriesOnlyIndexesQuantitiesRestockReasonAndConfirmation();
    await testOtherReasonNeedsTextAndQuantityIsCappedAtRefundable();
    await testServerErrorsAreShownInSpanishWithDetail();
    console.log('refundScreen tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
