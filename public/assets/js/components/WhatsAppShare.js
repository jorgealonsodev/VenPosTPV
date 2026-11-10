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
        // The field shows the number with its country code (+34 …) as soon as it is a phone.
        function withCountryCode(raw) {
            const digits = VenPosPhone.normalize(raw);
            return digits === null ? String(raw || '') : VenPosPhone.display(digits);
        }
        const phone = Vue.ref(withCountryCode(props.initialPhone));

        function formatPhone() {
            phone.value = withCountryCode(phone.value);
        }
        const status = Vue.ref('');
        const busy = Vue.ref(false);
        // The wa.me link, shown to tap when the browser blocks the new window.
        const link = Vue.ref('');
        const pickerOpen = Vue.ref(false);
        const customerQuery = Vue.ref('');
        const customers = Vue.ref([]);
        // A search has answered: an empty list then says nobody was found.
        const searched = Vue.ref(false);
        let searchTimer = null;
        let searchSeq = 0;

        function togglePicker() {
            pickerOpen.value = !pickerOpen.value;
            customers.value = [];
            searched.value = false;
        }

        function hasPhone(customer) {
            return !!customer && VenPosPhone.normalize(customer.phone) !== null;
        }

        // The till's local customers matching the name or phone typed. All are listed;
        // one without a usable phone says so and cannot be picked.
        function searchCustomers() {
            const q = String(customerQuery.value || '').trim();
            if (searchTimer !== null) clearTimeout(searchTimer);
            if (q.length < 2) {
                customers.value = [];
                searched.value = false;
                return Promise.resolve();
            }
            const seq = ++searchSeq;
            return new Promise((resolve) => {
                searchTimer = setTimeout(async () => {
                    try {
                        const r = await ApiClient.customerSearch(q);
                        if (seq !== searchSeq) return;
                        const found = (r && Array.isArray(r.data)) ? r.data.filter(Boolean) : [];
                        // Those with a phone first.
                        customers.value = [...found.filter(hasPhone), ...found.filter((c) => !hasPhone(c))].slice(0, 8);
                        searched.value = true;
                    } catch (e) {
                        if (seq === searchSeq) {
                            customers.value = [];
                            searched.value = true;
                        }
                    } finally {
                        resolve();
                    }
                }, 250);
            });
        }

        function pickCustomer(customer) {
            if (!hasPhone(customer)) return;
            phone.value = withCountryCode(customer.phone);
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

        return { phone, formatPhone, withCountryCode, status, busy, link, pickerOpen, customerQuery, customers, searched, hasPhone, togglePicker, searchCustomers, pickCustomer, openWhatsApp };
    },
    template: `
        <div class="whatsapp-share" style="display:flex; flex-direction:column; gap:8px;">
            <div style="display:flex; gap:8px;">
                <input v-model="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="Teléfono del cliente" aria-label="Teléfono del cliente" class="tpv-input" style="flex:1; min-width:0;" @blur="formatPhone" @keydown.enter.prevent="openWhatsApp" />
                <button type="button" class="tpv-btn" :aria-expanded="pickerOpen ? 'true' : 'false'" @click="togglePicker">
                    Elegir cliente
                </button>
            </div>
            <div v-if="pickerOpen" style="display:flex; flex-direction:column; gap:6px;">
                <input v-model="customerQuery" type="text" autocomplete="off" placeholder="Nombre o teléfono del cliente" aria-label="Buscar cliente" class="tpv-input" @input="searchCustomers" />
                <button v-for="c in customers" :key="c.local_id || c.id || c.phone" type="button" class="tpv-row" style="min-height:48px; text-align:left;" :disabled="!hasPhone(c)" :style="hasPhone(c) ? '' : 'opacity:.6; cursor:not-allowed;'" @click="pickCustomer(c)">
                    <span style="flex:1; min-width:0;">
                        <span style="display:block; font-weight:600; color:var(--text);">{{ c.name }}</span>
                        <span style="display:block; font-size:13px; color:var(--text2);">{{ hasPhone(c) ? withCountryCode(c.phone) : 'sin teléfono' }}</span>
                    </span>
                </button>
                <div v-if="searched && !customers.length" style="font-size:13px; color:var(--text2);">Ningún cliente con ese nombre o teléfono.</div>
            </div>
            <button type="button" class="tpv-btn" style="min-height:44px;" :disabled="busy || !phone" @click="openWhatsApp">{{ busy ? 'Preparando…' : 'Abrir WhatsApp' }}</button>
            <div v-if="status" role="status" style="font-size:13px; color:var(--text2);">{{ status }}</div>
            <a v-if="link" :href="link" target="_blank" rel="noopener noreferrer" style="font-size:13px; color:var(--green); word-break:break-all;">Si no se abre, toca aquí para abrir WhatsApp</a>
        </div>
    `,
};
