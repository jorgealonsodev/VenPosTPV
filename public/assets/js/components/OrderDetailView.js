/**
 * OrderDetailView — order detail (items, qty, subtotal, IVA, total, payment method) with
 * reprint and PDF. A TPV sale goes by its local order_id, also before it is uploaded to
 * PrestaShop; an online order (no local id) by its ps_order_id.
 */
const OrderDetailView = {
    props: ['order'],
    emits: ['back', 'notify'],
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
                    pdf();
                    emit('notify', 'Descargando ticket PDF', 'var(--blue)');
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

        // PDF button: a window to download the ticket or send it by email.
        const pdfChoice = Vue.ref(false);
        const pdfEmailEnabled = Vue.ref(false);
        const pdfEmail = Vue.ref('');
        const pdfEmailStatus = Vue.ref('');
        const pdfEmailSending = Vue.ref(false);

        async function openPdfChoice() {
            pdfEmail.value = '';
            pdfEmailStatus.value = '';
            pdfEmailEnabled.value = false;
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

        function downloadPdf() {
            pdf();
            pdfChoice.value = false;
        }

        async function sendPdfEmail() {
            const order = props.order || {};
            const email = String(pdfEmail.value || '').trim();
            if (!order.order_id || !email || pdfEmailSending.value) return;
            pdfEmailSending.value = true;
            pdfEmailStatus.value = '';
            try {
                await ApiClient.post('/api/ticket/sale/' + order.order_id + '/email', { email });
                pdfEmailStatus.value = 'Ticket enviado a ' + email;
            } catch (e) {
                const match = /^HTTP \d+: ([\s\S]*)$/.exec((e && e.message) || '');
                let text = 'No se pudo enviar el ticket';
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

        return { totalQty, title, canPrint, reprint, pdf, pdfChoice, pdfEmailEnabled, pdfEmail, pdfEmailStatus, pdfEmailSending, openPdfChoice, downloadPdf, sendPdfEmail };
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
            </div>

            <!-- PDF: download or send by email -->
            <div v-if="pdfChoice" class="combinations-modal-backdrop" @click.self="pdfChoice = false">
                <div class="combinations-modal" role="dialog" aria-label="Ticket en PDF" style="max-width:420px;">
                    <div class="combinations-modal-header">
                        <h3 style="margin:0; font-size:16px; color:var(--text); font-weight:600;">Ticket {{ title }}</h3>
                        <button @click="pdfChoice = false" aria-label="Cerrar" style="margin-left:auto; background:none; border:none; color:var(--text); font-size:20px; cursor:pointer; line-height:1; padding:4px 8px;">✕</button>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:12px; padding:4px 0 8px;">
                        <button class="tpv-btn" style="min-height:48px;" @click="downloadPdf"><icon name="receipt" :size="16" /> Descargar PDF</button>
                        <template v-if="pdfEmailEnabled">
                            <div style="font-size:13px; color:var(--text2);">O enviarlo por email:</div>
                            <div style="display:flex; gap:8px;">
                                <input v-model="pdfEmail" type="email" inputmode="email" placeholder="email del cliente" aria-label="Email del cliente" class="tpv-input" style="flex:1; min-width:0;" @keydown.enter.prevent="sendPdfEmail" />
                                <button class="tpv-btn" :disabled="pdfEmailSending || !pdfEmail" @click="sendPdfEmail">{{ pdfEmailSending ? 'Enviando…' : 'Enviar' }}</button>
                            </div>
                            <div v-if="pdfEmailStatus" style="font-size:13px; color:var(--text2);">{{ pdfEmailStatus }}</div>
                        </template>
                        <div v-else style="font-size:12px; color:var(--text3);">{{ order && order.order_id ? 'Para enviarlo por email, configura la cuenta de correo en Ajustes → Email.' : 'Los pedidos online solo se pueden descargar.' }}</div>
                    </div>
                </div>
            </div>
        </div>
    `
};
