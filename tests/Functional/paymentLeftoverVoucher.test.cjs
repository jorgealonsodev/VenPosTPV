// A voucher worth more than the sale leaves a new one with the rest: the payment
// done screen shows «Saldo pendiente del vale: CODE — X €» with the coupon slip
// actions (print, PDF, email, WhatsApp) and waits for the cashier.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/PaymentModal.js'), 'utf8');
const preview = { operation: 'coupon_preview', cart_id: 17, before_discount_total: 36, discount_amount: 36, total_paid: 0, coupon: { code: 'ABCD1234' }, quote: 'signed.quote' };
const LEFTOVER = { id: 31, code: 'ABCD1234-2', amount: 14, expires_at: '2027-06-30 23:59:59' };

function loadPayment({ printerType = 'escpos', leftover = LEFTOVER, couponPrint = async () => ({ data: { print_status: 'printed' } }), mail = true } = {}) {
    const calls = [];
    const cart = {
        lines: [{ id: 1, product_id: 1, qty: 1, price: 36 }], subtotal: 36,
        discount: null, discount_amount: 0, total: 36, iva: 6.25, customer: { id: 9, email: 'ana@example.com', phone: '600111222' },
    };
    const sale = { order_id: 7, ps_order_id: 88, reference: 'XKBKNABJK', total: 0, change_due: 0, saga_error: false };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart },
        useSession: { session: { printerType }, hydrateCashSession: async () => {} },
        ApiClient: {
            couponPreview: async () => ({ data: preview }),
            couponCheckout: async () => ({ data: leftover ? { ...sale, leftover_voucher: leftover } : sale }),
            checkout: async () => ({ data: sale }),
            ticketReprintSale: async () => ({}),
            ticketReprint: async () => ({}),
            ticketSalePdf() {},
            ticketPdf() {},
            get: async (url) => { calls.push(['get', url]); return { data: { enabled: mail } }; },
            post: async () => ({}),
            couponPrint: async (id) => { calls.push(['print', id]); return couponPrint(id); },
            couponPdf: (id) => { calls.push(['pdf', id]); },
            couponEmail: async (id, email) => { calls.push(['email', id, email]); return { data: { sent: true } }; },
        },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const emitted = [];
    const modal = context.__payment;
    const state = modal.setup({ total: 36 }, { emit: (name, value) => emitted.push([name, value]) });
    return { state, emitted, calls, modal };
}

async function payWithVoucher(state) {
    state.method.value = 'voucher';
    state.voucherCode.value = 'ABCD1234';
    await state.applyVoucher();
    await state.handleConfirm();
}

test('the done screen shows the voucher rest and waits for the cashier', async () => {
    const { state, emitted } = loadPayment();
    await payWithVoucher(state);

    assert.equal(state.step.value, 'done');
    assert.deepEqual(JSON.parse(JSON.stringify(state.leftoverVoucher.value)), LEFTOVER);
    assert.equal(emitted.length, 0, 'the sale does not close on its own while the voucher is shown');
    state.finish();
    assert.equal(emitted[0][0], 'complete');
});

test('the template shows the line with print, PDF, email and WhatsApp of the coupon slip', () => {
    const { modal } = loadPayment();
    assert.match(modal.template, /<div v-if="leftoverVoucher"[^>]*>[\s\S]*Saldo pendiente del vale: \{\{ leftoverVoucher\.code \}\} — \{\{ eur\(leftoverVoucher\.amount\) \}\}/);
    assert.match(modal.template, /@click="printLeftover"[^>]*>Imprimir vale<\/button>/);
    assert.match(modal.template, /@click="openLeftoverPdf"[^>]*>PDF<\/button>/);
    assert.match(modal.template, /v-if="leftoverEmailEnabled" @click="leftoverEmailOpen = !leftoverEmailOpen"[^>]*>Email<\/button>/);
    assert.match(modal.template, /@click="leftoverWhatsappOpen = !leftoverWhatsappOpen"[^>]*>WhatsApp<\/button>/);
    assert.match(modal.template, /<whatsapp-share v-if="leftoverWhatsappOpen" kind="coupon" :doc-id="leftoverVoucher\.id" :initial-phone="ticketPhone" \/>/);
    assert.match(modal.template, /<button v-if="leftoverVoucher && printerType !== 'pdf' && printerType !== 'none' && printerType !== 'agent'" @click="finish"/);
});

test('the slip actions use the issued coupon of the rest', async () => {
    const { state, calls } = loadPayment();
    await payWithVoucher(state);

    await state.printLeftover();
    state.openLeftoverPdf();
    assert.equal(state.leftoverEmailEnabled.value, true);
    assert.equal(state.leftoverEmail.value, 'ana@example.com');
    await state.sendLeftoverEmail();

    assert.deepEqual(calls.filter(([kind]) => kind !== 'get'), [['print', 31], ['pdf', 31], ['email', 31, 'ana@example.com']]);
    assert.equal(state.leftoverStatus.value, 'Vale enviado a ana@example.com');
});

test('a till without printer opens the PDF of the voucher', async () => {
    const { state, calls } = loadPayment({ couponPrint: async () => ({ data: { fallback: 'pdf' } }) });
    await payWithVoucher(state);

    await state.printLeftover();

    assert.deepEqual(calls.filter(([kind]) => kind !== 'get'), [['print', 31], ['pdf', 31]]);
    assert.equal(state.leftoverStatus.value, 'Esta caja no tiene impresora: se abre el PDF.');
});

test('a rest the till could not keep shows only its code', async () => {
    const { state, calls } = loadPayment({ leftover: { ...LEFTOVER, id: null } });
    await payWithVoucher(state);

    assert.equal(state.leftoverVoucher.value.code, 'ABCD1234-2');
    assert.equal(state.leftoverPrintable.value, false);
    await state.printLeftover();
    state.openLeftoverPdf();
    assert.deepEqual(calls.filter(([kind]) => kind !== 'get'), []);
});

test('a sale without rest closes on its own as before', async () => {
    const { state, emitted } = loadPayment({ leftover: null });
    await payWithVoucher(state);

    assert.equal(state.leftoverVoucher.value, null);
    assert.equal(emitted[0][0], 'complete');
});
