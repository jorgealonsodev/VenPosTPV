/**
 * OrderDetailView — order detail (items, qty, subtotal, IVA, total, payment method) with
 * reprint and PDF. A TPV sale goes by its local order_id, also before it is uploaded to
 * PrestaShop; an online order (no local id) by its ps_order_id.
 *
 * A TPV sale can be redeemed for a full invoice («Factura completa», InvoiceModal;
 * docs/prd/factura-completa.md F3). Once redeemed the detail shows «Sustituido por
 * F26000004» and the PDF window downloads or emails the A4 invoice, by email only
 * with the customer's consent (F6). A TPV sale's ticket or invoice can also go by
 * WhatsApp (WhatsAppShare: a link to the PDF), with the same consent for the invoice.
 */
const OrderDetailView = {
    props: ['order', 'startWithChoice'],
    emits: ['back', 'notify'],
    components: typeof WhatsAppShare !== 'undefined' ? { 'whatsapp-share': WhatsAppShare } : {},
    setup(props, { emit }) {
        const title = Vue.computed(() => {
            const order = props.order || {};
            return order.reference || (order.ps_order_id ? '#' + order.ps_order_id : 'Venta ' + (order.order_id || ''));
        });
        const canPrint = Vue.computed(() => !!(props.order && (props.order.order_id || props.order.ps_order_id)));

        async function reprint() {
            const order = props.order || {};
            const saleId = order.order_id || null;
            const psOrderId = order.ps_order_id || null;
            if (!saleId && !psOrderId) return;
            try {
                const result = saleId ? await ApiClient.ticketReprintSale(saleId) : await ApiClient.ticketReprint(psOrderId);
                if (result && result.data && result.data.print_status === 'queued' && typeof usePrintJobs !== 'undefined') {
                    // Print agent: followed until sent; a problem stays on the sale screen (C1, C2).
                    usePrintJobs.trackResponse(result.data, { kind: 'reprint', saleId, orderId: psOrderId });
                    emit('notify', 'Imprimiendo ticket…', 'var(--blue)');
                } else if (result && result.data && result.data.fallback === 'pdf') {
                    // No printer: the cashier chooses PDF, email or WhatsApp.
                    openPdfChoice('ticket');
                    emit('notify', 'No hay impresora: elige cómo entregar el ticket', 'var(--blue)');
                } else {
                    emit('notify', 'Ticket reimpreso', 'var(--green)');
                }
            } catch (err) {
                emit('notify', 'Error al reimprimir: ' + (err.message || 'Error'), 'var(--red)');
            }
        }

        function pdf() {
            const order = props.order || {};
            if (order.order_id) {
                ApiClient.ticketSalePdf(order.order_id);
            } else if (order.ps_order_id) {
                ApiClient.ticketPdf(order.ps_order_id);
            }
        }

        // The full invoice that replaced this ticket, if any.
        const invoice = Vue.ref(null);
        const showInvoice = Vue.ref(false);
        const canInvoice = Vue.computed(() => !!(props.order && props.order.order_id) && !invoice.value);

        async function loadInvoice() {
            const order = props.order || {};
            if (!order.order_id) return;
            try {
                const r = await ApiClient.get('/api/invoices/by-sale/' + encodeURIComponent(order.order_id));
                invoice.value = r && r.data && r.data.number ? r.data : null;
            } catch (e) {
                invoice.value = null;
            }
        }

        function onInvoiceIssued(issued) {
            invoice.value = issued;
            showInvoice.value = false;
            emit('notify', 'Factura ' + issued.number + ' emitida', 'var(--green)');
            // No A4 printer yet (F6b): the invoice is downloaded or emailed from here.
            openPdfChoice('invoice');
        }

        // PDF button: a window to download the ticket (or the invoice) or send it by email.
        const pdfDoc = Vue.ref('ticket');
        const pdfConsent = Vue.ref(false);
        const pdfChoice = Vue.ref(false);
        const pdfEmailEnabled = Vue.ref(false);
        const pdfEmail = Vue.ref('');
        const pdfEmailStatus = Vue.ref('');
        const pdfEmailSending = Vue.ref(false);
        const pdfCustomers = Vue.ref([]);
        // «WhatsApp»: any TPV sale, with or without a mail account.
        const pdfWhatsappEnabled = Vue.ref(false);
        const pdfWhatsapp = Vue.ref(false);
        const pdfPhone = Vue.ref('');
        let pdfSearchTimer = null;
        let pdfSearchSeq = 0;

        // kind 'invoice' opens it for the full invoice; anything else (the click event) the ticket.
        // channel 'whatsapp' opens it with the WhatsApp panel already shown.
        async function openPdfChoice(kind, channel) {
            pdfDoc.value = kind === 'invoice' && invoice.value ? 'invoice' : 'ticket';
            pdfConsent.value = !!(pdfDoc.value === 'invoice' && invoice.value.email_consent_at);
            pdfEmail.value = '';
            pdfEmailStatus.value = '';
            pdfCustomers.value = [];
            pdfEmailEnabled.value = false;
            pdfWhatsapp.value = false;
            pdfPhone.value = saleCustomerPhone();
            pdfWhatsappEnabled.value = !!(props.order && props.order.order_id);
            pdfWhatsapp.value = channel === 'whatsapp' && pdfWhatsappEnabled.value;
            pdfChoice.value = true;
            // Only a TPV sale (local order_id) can be emailed, and only with a mail account.
            if (!(props.order && props.order.order_id)) return;
            try {
                const r = await ApiClient.get('/api/ticket/email-enabled');
                pdfEmailEnabled.value = !!(r && r.data && r.data.enabled);
            } catch (e) {
                pdfEmailEnabled.value = false;
            }
        }

        function saleCustomerPhone() {
            const order = props.order || {};
            return String(order.customer_phone || (order.customer && order.customer.phone) || '');
        }

        // The invoice consent given for WhatsApp is now stored with the invoice.
        async function onWhatsappShared() {
            if (pdfDoc.value === 'invoice') await loadInvoice();
        }

        // Existing customers (the till's local copy) matching what is typed in the
        // email field: name, email or phone. Only those with an email are offered.
        function searchPdfCustomers() {
            const q = String(pdfEmail.value || '').trim();
            if (pdfSearchTimer !== null) clearTimeout(pdfSearchTimer);
            if (q.length < 2) {
                pdfCustomers.value = [];
                return Promise.resolve();
            }
            const seq = ++pdfSearchSeq;
            return new Promise((resolve) => {
                pdfSearchTimer = setTimeout(async () => {
                    try {
                        const r = await ApiClient.customerSearch(q);
                        if (seq !== pdfSearchSeq) return;
                        const found = (r && Array.isArray(r.data)) ? r.data : [];
                        pdfCustomers.value = found.filter((c) => c && typeof c.email === 'string' && c.email.includes('@')).slice(0, 6);
                    } catch (e) {
                        if (seq === pdfSearchSeq) pdfCustomers.value = [];
                    } finally {
                        resolve();
                    }
                }, 250);
            });
        }

        function pickPdfCustomer(customer) {
            pdfEmail.value = customer.email;
            pdfCustomers.value = [];
            ++pdfSearchSeq;
        }

        function downloadPdf() {
            if (pdfDoc.value === 'invoice' && invoice.value) {
                window.open((window.TPV_BASE || '') + '/api/invoices/' + encodeURIComponent(invoice.value.id) + '/pdf', '_blank');
            } else {
                pdf();
            }
            pdfChoice.value = false;
        }

        async function sendPdfEmail() {
            const order = props.order || {};
            const email = String(pdfEmail.value || '').trim();
            if (!order.order_id || !email || pdfEmailSending.value) return;
            const forInvoice = pdfDoc.value === 'invoice' && invoice.value;
            // RD 1619/2012 art. 9.2: the invoice goes by email only with the customer's consent.
            if (forInvoice && !pdfConsent.value) {
                pdfEmailStatus.value = 'Marca que el cliente acepta recibir la factura por email';
                return;
            }
            pdfEmailSending.value = true;
            pdfEmailStatus.value = '';
            try {
                if (forInvoice) {
                    await ApiClient.post('/api/invoices/' + invoice.value.id + '/email', { email, consent: true });
                    pdfEmailStatus.value = 'Factura enviada a ' + email;
                    // The consent is now stored with the invoice.
                    await loadInvoice();
                } else {
                    await ApiClient.post('/api/ticket/sale/' + order.order_id + '/email', { email });
                    pdfEmailStatus.value = 'Ticket enviado a ' + email;
                }
            } catch (e) {
                const match = /^HTTP \d+: ([\s\S]*)$/.exec((e && e.message) || '');
                let text = forInvoice ? 'No se pudo enviar la factura' : 'No se pudo enviar el ticket';
                if (match) { try { text = JSON.parse(match[1]).error || text; } catch (err) { /* keep default */ } }
                pdfEmailStatus.value = text;
            } finally {
                pdfEmailSending.value = false;
            }
        }

        const totalQty = Vue.computed(() => {
            if (!props.order || !props.order.lines) return 0;
            return props.order.lines.reduce((sum, line) => sum + (line.qty || line.quantity || 1), 0);
        });

        loadInvoice();

        // Opened from «Reimprimir» on the sale screen without a printer: show the choice at once.
        if (props.startWithChoice) openPdfChoice('ticket');

        return { invoice, showInvoice, canInvoice, loadInvoice, onInvoiceIssued, pdfDoc, pdfConsent, totalQty, title, canPrint, reprint, pdf, pdfChoice, pdfEmailEnabled, pdfEmail, pdfEmailStatus, pdfEmailSending, openPdfChoice, downloadPdf, sendPdfEmail, pdfCustomers, searchPdfCustomers, pickPdfCustomer, pdfWhatsappEnabled, pdfWhatsapp, pdfPhone, onWhatsappShared };
    },
    template: `
        <div style="display:flex; flex-direction:column; height:100%; overflow:hidden;">
            <!-- Header -->
            <div style="border-bottom:1px solid var(--border); padding:10px 14px; display:flex; align-items:center; gap:10px; flex-shrink:0;">
                <button @click="$emit('back')"
                    style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; gap:4px;">
                    <icon name="arrow_back" :size="14" /> Volver
                </button>
                <div style="font-weight:700; font-size:15px; color:var(--text);">Detalle del ticket</div>
            </div>

            <!-- Info -->
            <div style="padding:12px 14px; border-bottom:1px solid var(--border); display:flex; flex-direction:column; gap:4px; flex-shrink:0;">
                <div style="font-size:14px; font-weight:600; color:var(--text);">
                    {{ title }}
                </div>
                <div v-if="order.ps_reference" style="font-size:12px; color:var(--text2);">Pedido web: {{ order.ps_reference }}</div>
                <div v-if="invoice" style="font-size:12px; color:var(--blue); font-weight:600;">Sustituido por {{ invoice.number }} · {{ invoice.recipient && invoice.recipient.name }}</div>
                <div style="font-size:12px; color:var(--text3); display:flex; gap:10px; flex-wrap:wrap;">
                    <span v-if="order.created_at">{{ order.created_at }}</span>
                    <span v-if="order.payment_method">{{ order.payment_method }}</span>
                </div>
            </div>

            <!-- Lines -->
            <div style="flex:1; overflow:auto; padding:8px 12px; display:flex; flex-direction:column; gap:4px;">
                <div v-for="(line, idx) in order.lines" :key="idx"
                    style="background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:8px 10px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:13px; color:var(--text); font-weight:500;">{{ line.name || 'Producto' }}</div>
                    <div style="font-family:'IBM Plex Mono'; font-size:13px; color:var(--text2);">
                        {{ (line.qty || line.quantity || 1) }} uds
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div style="border-top:1px solid var(--border); padding:10px 14px 8px; display:flex; flex-direction:column; gap:5px; flex-shrink:0;">
                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text3);">
                    <span>{{ totalQty }} artículo{{ totalQty !== 1 ? 's' : '' }}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text3);">
                    <span>Subtotal</span>
                    <span style="font-family:'IBM Plex Mono';">€{{ Number(order.subtotal || order.total || 0).toFixed(2) }}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text3);">
                    <span>IVA</span>
                    <span style="font-family:'IBM Plex Mono';">€{{ Number(order.iva || order.tax || 0).toFixed(2) }}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:700; color:var(--text);">
                    <span>TOTAL</span>
                    <span style="font-family:'IBM Plex Mono';">€{{ Number(order.total || 0).toFixed(2) }}</span>
                </div>
                <div v-if="order.payment_method" style="display:flex; justify-content:space-between; font-size:12px; color:var(--text2); margin-top:4px;">
                    <span>Método de pago</span>
                    <span style="text-transform:capitalize;">{{ order.payment_method }}</span>
                </div>
                <div v-if="canPrint" style="display:flex; gap:8px; margin-top:8px;">
                    <button class="tpv-btn" style="flex:1;" @click="reprint"><icon name="receipt" :size="16" /> Reimprimir</button>
                    <button class="tpv-btn" style="flex:1;" @click="openPdfChoice"><icon name="receipt" :size="16" /> PDF</button>
                </div>
                <div v-if="order.order_id" class="order-share-actions" style="display:flex; gap:8px; margin-top:8px;">
                    <button class="tpv-btn" style="flex:1;" @click="openPdfChoice('ticket', 'email')"><icon name="mail" :size="16" /> Enviar por email</button>
                    <button class="tpv-btn" style="flex:1;" @click="openPdfChoice('ticket', 'whatsapp')">WhatsApp</button>
                </div>
                <div v-if="canInvoice || invoice" style="display:flex; gap:8px; margin-top:8px;">
                    <button v-if="canInvoice" class="tpv-btn" style="flex:1;" @click="showInvoice = true"><icon name="receipt" :size="16" /> Factura completa</button>
                    <button v-else class="tpv-btn" style="flex:1;" @click="openPdfChoice('invoice')"><icon name="receipt" :size="16" /> Factura {{ invoice.number }}</button>
                </div>
            </div>

            <!-- PDF: download, send by email or by WhatsApp -->
            <div v-if="pdfChoice" class="combinations-modal-backdrop" @click.self="pdfChoice = false">
                <div class="combinations-modal" role="dialog" :aria-label="pdfDoc === 'invoice' ? 'Factura en PDF' : 'Ticket en PDF'" style="max-width:420px;">
                    <div class="combinations-modal-header">
                        <h3 style="margin:0; font-size:16px; color:var(--text); font-weight:600;">{{ pdfDoc === 'invoice' ? 'Factura ' + invoice.number : 'Ticket ' + title }}</h3>
                        <button @click="pdfChoice = false" aria-label="Cerrar" style="margin-left:auto; background:none; border:none; color:var(--text); font-size:20px; cursor:pointer; line-height:1; padding:4px 8px;">✕</button>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:12px; padding:4px 0 8px;">
                        <button class="tpv-btn" style="min-height:48px;" @click="downloadPdf"><icon name="receipt" :size="16" /> Descargar PDF</button>
                        <template v-if="pdfEmailEnabled">
                            <div style="font-size:13px; color:var(--text2);">O enviarlo por email:</div>
                            <div style="display:flex; gap:8px;">
                                <input v-model="pdfEmail" type="text" inputmode="email" autocomplete="off" placeholder="email o nombre del cliente" aria-label="Email o nombre del cliente" class="tpv-input" style="flex:1; min-width:0;" @input="searchPdfCustomers" @keydown.enter.prevent="sendPdfEmail" />
                                <button class="tpv-btn" :disabled="pdfEmailSending || !pdfEmail || (pdfDoc === 'invoice' && !pdfConsent)" @click="sendPdfEmail">{{ pdfEmailSending ? 'Enviando…' : 'Enviar' }}</button>
                            </div>
                            <div v-if="pdfCustomers.length" style="display:flex; flex-direction:column; gap:6px;">
                                <button v-for="c in pdfCustomers" :key="c.local_id || c.id || c.email" class="tpv-row" style="min-height:48px; text-align:left;" @click="pickPdfCustomer(c)">
                                    <span style="flex:1; min-width:0;">
                                        <span style="display:block; font-weight:600; color:var(--text);">{{ c.name }}</span>
                                        <span style="display:block; font-size:13px; color:var(--text2);">{{ c.email }}</span>
                                    </span>
                                </button>
                            </div>
                            <div v-if="pdfEmailStatus" style="font-size:13px; color:var(--text2);">{{ pdfEmailStatus }}</div>
                        </template>
                        <div v-else style="font-size:12px; color:var(--text3);">{{ order && order.order_id ? 'Para enviarlo por email, configura la cuenta de correo en Ajustes → Email.' : 'Los pedidos online solo se pueden descargar.' }}</div>
                        <label v-if="pdfDoc === 'invoice' && (pdfEmailEnabled || pdfWhatsappEnabled)" style="display:flex; gap:8px; align-items:center; min-height:44px; font-size:13px; color:var(--text2); cursor:pointer;">
                            <input type="checkbox" v-model="pdfConsent" :disabled="!!(invoice && invoice.email_consent_at)" style="width:20px; height:20px;" />
                            El cliente acepta recibir la factura por email o WhatsApp
                        </label>
                        <template v-if="pdfWhatsappEnabled">
                            <button class="tpv-btn" style="min-height:48px;" :aria-expanded="pdfWhatsapp ? 'true' : 'false'" @click="pdfWhatsapp = !pdfWhatsapp">WhatsApp</button>
                            <whatsapp-share v-if="pdfWhatsapp" :key="pdfDoc" :kind="pdfDoc === 'invoice' ? 'invoice' : 'ticket'" :doc-id="pdfDoc === 'invoice' ? invoice.id : order.order_id" :initial-phone="pdfPhone" :consent="pdfConsent" @shared="onWhatsappShared" />
                        </template>
                    </div>
                </div>
            </div>

            <invoice-modal v-if="showInvoice" :order="order" @close="showInvoice = false" @issued="onInvoiceIssued" />
        </div>
    `
};
