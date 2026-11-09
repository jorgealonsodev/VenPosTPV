const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

// Every place that sends by email offers «WhatsApp» next to it: the sale detail
// (ticket and full invoice), the coupon slip and the payment screen.
const read = (file) => fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components', file), 'utf8');
const detail = read('OrderDetailView.js');
const coupon = read('CouponCreationModal.js');
const payment = read('PaymentModal.js');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function mountDetail(order, { emailEnabled = true, invoice = null } = {}) {
    const context = {
        Vue: { ref, computed },
        WhatsAppShare: { name: 'fake' },
        ApiClient: {
            get: async (url) => (url.includes('by-sale') ? { data: invoice } : { data: { enabled: emailEnabled } }),
            post: async () => ({ data: {} }),
            customerSearch: async () => ({ data: [] }),
        },
        JSON, String, Error, console, setTimeout: (fn) => { fn(); return 1; }, clearTimeout() {},
    };
    context.globalThis = context;
    vm.runInNewContext(`${detail}\nglobalThis.__view = OrderDetailView;`, context, { filename: 'OrderDetailView.js' });
    return { view: context.__view, state: context.__view.setup({ order }, { emit: () => {} }) };
}

test('the sale detail registers the WhatsApp panel only where it is loaded', () => {
    assert.equal(mountDetail({ order_id: 1 }).view.components['whatsapp-share'].name, 'fake');
    const context = { Vue: { ref, computed }, ApiClient: {}, JSON, String, console };
    context.globalThis = context;
    vm.runInNewContext(`${detail}\nglobalThis.__view = OrderDetailView;`, context);
    assert.deepEqual(Object.keys(context.__view.components), [], 'old harnesses without the panel still load');
});

test('the PDF window offers «WhatsApp» for a TPV sale, also without a mail account', async () => {
    const { state } = mountDetail({ order_id: 42, reference: 'R26000012', customer_phone: '600111222' }, { emailEnabled: false });
    await state.openPdfChoice();

    assert.equal(state.pdfWhatsappEnabled.value, true);
    assert.equal(state.pdfWhatsapp.value, false, 'closed until the cashier taps it');
    assert.equal(state.pdfPhone.value, '600111222', 'the sale customer phone prefills it');

    const online = mountDetail({ ps_order_id: 900 });
    await online.state.openPdfChoice();
    assert.equal(online.state.pdfWhatsappEnabled.value, false, 'online orders are only downloaded');
});

test('the PDF window wires the panel for the ticket or the invoice with its consent', () => {
    assert.match(detail, /<template v-if="pdfWhatsappEnabled">\s*<button[^>]*@click="pdfWhatsapp = !pdfWhatsapp"[^>]*>WhatsApp<\/button>/);
    assert.match(detail, /<whatsapp-share v-if="pdfWhatsapp"[^>]*:kind="pdfDoc === 'invoice' \? 'invoice' : 'ticket'"[^>]*:doc-id="pdfDoc === 'invoice' \? invoice\.id : order\.order_id"[^>]*:initial-phone="pdfPhone"[^>]*:consent="pdfConsent"[^>]*@shared="onWhatsappShared"/);
    assert.match(detail, /El cliente acepta recibir la factura por email o WhatsApp/);
});

test('sharing an invoice by WhatsApp reloads it, so the stored consent shows', async () => {
    const { state } = mountDetail({ order_id: 42 }, { invoice: { id: 8, number: 'F26000004', email_consent_at: null } });
    await state.loadInvoice();
    state.invoice.value = { id: 8, number: 'F26000004', email_consent_at: null };
    state.pdfDoc.value = 'invoice';

    await state.onWhatsappShared();

    assert.equal(state.invoice.value.number, 'F26000004');
});

test('the coupon slip has «WhatsApp» next to «Enviar por email» with the panel for its local id', () => {
    assert.match(coupon, /Enviar por email<\/button>\s*<button @click="whatsappOpen = !whatsappOpen"[^>]*>WhatsApp<\/button>/);
    assert.match(coupon, /<whatsapp-share v-if="whatsappOpen"[^>]*kind="coupon"[^>]*:doc-id="couponId"/);
    assert.match(coupon, /components: typeof WhatsAppShare !== 'undefined' \? \{ 'whatsapp-share': WhatsAppShare \} : \{\}/);
});

test('the payment screen offers «WhatsApp» next to the email of the ticket, prefilled with the customer phone', () => {
    assert.match(payment, /@click="whatsappOpen = !whatsappOpen"[^>]*>[\s\S]*?WhatsApp/);
    assert.match(payment, /<whatsapp-share v-if="whatsappOpen"[^>]*kind="ticket"[^>]*:doc-id="lastSaleId"[^>]*:initial-phone="ticketPhone"/);
    assert.match(payment, /ticketPhone\.value = String\(\(useCart\.cart\.customer && useCart\.cart\.customer\.phone\) \|\| ''\)/);
    assert.match(payment, /components: typeof WhatsAppShare !== 'undefined' \? \{ 'whatsapp-share': WhatsAppShare \} : \{\}/);
});
