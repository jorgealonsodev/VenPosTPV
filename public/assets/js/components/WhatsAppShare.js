/**
 * WhatsAppShare — «WhatsApp» next to «Enviar por email»: a phone field (typed, or
 * «Elegir cliente» from the till's customers), and «Abrir WhatsApp», which asks the
 * server for an expiring link to the document's PDF plus a short message and opens
 * wa.me with them; the cashier presses Send in WhatsApp. Nothing is attached and no
 * WhatsApp account of the shop is needed.
 *
 * Props: kind ('ticket' | 'invoice' | 'coupon'), docId (sale local id, invoice id or
 * coupon local id), initialPhone, consent (invoice only: the customer accepted it
 * electronically, RD 1619/2012 art. 9.2). Emits 'shared' with {url, message}.
 */
const WhatsAppShare = {
    props: ['kind', 'docId', 'initialPhone', 'consent'],
    emits: ['shared'],
    setup(props, { emit }) {
        const phone = Vue.ref(String(props.initialPhone || ''));
        const status = Vue.ref('');
        const busy = Vue.ref(false);
        // The wa.me link, shown to tap when the browser blocks the new window.
        const link = Vue.ref('');
        const pickerOpen = Vue.ref(false);
        const customerQuery = Vue.ref('');
        const customers = Vue.ref([]);
        let searchTimer = null;
        let searchSeq = 0;

        function togglePicker() {
            pickerOpen.value = !pickerOpen.value;
            customers.value = [];
        }

        // The till's local customers matching the name or phone typed; only those
        // with a usable phone are offered.
        function searchCustomers() {
            const q = String(customerQuery.value || '').trim();
            if (searchTimer !== null) clearTimeout(searchTimer);
            if (q.length < 2) {
                customers.value = [];
                return Promise.resolve();
            }
            const seq = ++searchSeq;
            return new Promise((resolve) => {
                searchTimer = setTimeout(async () => {
                    try {
                        const r = await ApiClient.customerSearch(q);
                        if (seq !== searchSeq) return;
                        const found = (r && Array.isArray(r.data)) ? r.data : [];
                        customers.value = found.filter((c) => c && VenPosPhone.normalize(c.phone) !== null).slice(0, 6);
                    } catch (e) {
                        if (seq === searchSeq) customers.value = [];
                    } finally {
                        resolve();
                    }
                }, 250);
            });
        }

        function pickCustomer(customer) {
            phone.value = String(customer.phone || '');
            pickerOpen.value = false;
            customers.value = [];
            ++searchSeq;
        }

        async function openWhatsApp() {
            if (busy.value) return;
            link.value = '';
            const digits = VenPosPhone.normalize(phone.value);
            if (digits === null) {
                status.value = 'Número de teléfono no válido';
                return;
            }
            const forInvoice = props.kind === 'invoice';
            if (forInvoice && !props.consent) {
                status.value = 'Marca que el cliente acepta recibir la factura por email o WhatsApp';
                return;
            }
            busy.value = true;
            status.value = '';
            try {
                const r = await ApiClient.whatsappLink(props.kind, props.docId, forInvoice ? { consent: true } : {});
                const url = VenPosPhone.whatsappUrl(digits, r && r.data ? r.data.message : '');
                link.value = url;
                // Not window.open(url, '_blank', 'noopener'): with 'noopener' the browser
                // always returns null, so a blocked popup could not be told apart. The
                // opener is cut by hand instead.
                const opened = window.open(url, '_blank');
                if (opened) {
                    try { opened.opener = null; } catch (e) { /* cross-origin already */ }
                    status.value = 'Abriendo WhatsApp…';
                } else {
                    status.value = 'El navegador ha bloqueado la ventana: toca el enlace para abrir WhatsApp.';
                }
                emit('shared', r && r.data);
            } catch (e) {
                const match = /^HTTP \d+: ([\s\S]*)$/.exec((e && e.message) || '');
                let text = 'No se pudo preparar el enlace de WhatsApp';
                if (match) { try { text = JSON.parse(match[1]).error || text; } catch (err) { /* keep default */ } }
                status.value = text;
            } finally {
                busy.value = false;
            }
        }

        return { phone, status, busy, link, pickerOpen, customerQuery, customers, togglePicker, searchCustomers, pickCustomer, openWhatsApp };
    },
    template: `
        <div class="whatsapp-share" style="display:flex; flex-direction:column; gap:8px;">
            <div style="display:flex; gap:8px;">
                <input v-model="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="Teléfono del cliente" aria-label="Teléfono del cliente" class="tpv-input" style="flex:1; min-width:0;" @keydown.enter.prevent="openWhatsApp" />
                <button type="button" class="tpv-btn" :aria-expanded="pickerOpen ? 'true' : 'false'" @click="togglePicker">
                    Elegir cliente
                </button>
            </div>
            <div v-if="pickerOpen" style="display:flex; flex-direction:column; gap:6px;">
                <input v-model="customerQuery" type="text" autocomplete="off" placeholder="Nombre o teléfono del cliente" aria-label="Buscar cliente" class="tpv-input" @input="searchCustomers" />
                <button v-for="c in customers" :key="c.local_id || c.id || c.phone" type="button" class="tpv-row" style="min-height:48px; text-align:left;" @click="pickCustomer(c)">
                    <span style="flex:1; min-width:0;">
                        <span style="display:block; font-weight:600; color:var(--text);">{{ c.name }}</span>
                        <span style="display:block; font-size:13px; color:var(--text2);">{{ c.phone }}</span>
                    </span>
                </button>
            </div>
            <button type="button" class="tpv-btn" style="min-height:44px;" :disabled="busy || !phone" @click="openWhatsApp">{{ busy ? 'Preparando…' : 'Abrir WhatsApp' }}</button>
            <div v-if="status" role="status" style="font-size:13px; color:var(--text2);">{{ status }}</div>
            <a v-if="link" :href="link" target="_blank" rel="noopener noreferrer" style="font-size:13px; color:var(--green); word-break:break-all;">Si no se abre, toca aquí para abrir WhatsApp</a>
        </div>
    `,
};
