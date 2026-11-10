/**
 * InvoiceModal — redeem a ticket for a full invoice (docs/prd/factura-completa.md F3):
 * pick the customer (search or create), fill the tax data that are missing (F1, the
 * missing fields marked), check the summary (ticket, date, amount, customer, VAT box)
 * and confirm. The server issues the F3 invoice; nothing is stored before «Emitir».
 */
const InvoiceModal = {
    props: ['order'],
    emits: ['close', 'issued'],
    setup(props, { emit }) {
        const TAX_FIELDS = ['tax_name', 'country', 'tax_id_type', 'tax_id', 'address', 'postcode', 'city', 'province'];
        const DOCUMENT_TYPES = [
            { code: '02', label: 'NIF-IVA' },
            { code: '03', label: 'Pasaporte' },
            { code: '04', label: 'Documento oficial del país de residencia' },
            { code: '05', label: 'Certificado de residencia' },
            { code: '06', label: 'Otro documento probatorio' },
        ];
        const step = Vue.ref('customer'); // customer | edit | summary
        const q = Vue.ref('');
        const results = Vue.ref([]);
        const searching = Vue.ref(false);
        const customer = Vue.ref(null);
        const creating = Vue.ref(false);
        const form = Vue.ref({});
        const missing = Vue.ref([]);
        const error = Vue.ref('');
        const busy = Vue.ref(false);
        const summary = Vue.ref(null);
        const emailConsent = Vue.ref(false);
        // One payment attempt: a repeated «Emitir» answers the same invoice.
        const requestId = 'inv-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        let searchSeq = 0;
        let debounce = null;

        function serverError(err, fallback) {
            return (err && err.body && err.body.error) || fallback;
        }

        async function search() {
            const seq = ++searchSeq;
            searching.value = true;
            try {
                const r = await ApiClient.customerSearch(String(q.value || '').trim());
                if (seq !== searchSeq) return;
                results.value = r && Array.isArray(r.data) ? r.data : [];
            } catch (err) {
                if (seq === searchSeq) results.value = [];
            } finally {
                if (seq === searchSeq) searching.value = false;
            }
        }

        function scheduleSearch() {
            if (debounce) clearTimeout(debounce);
            debounce = setTimeout(search, 200);
        }

        function formFrom(c) {
            const values = {};
            for (const field of TAX_FIELDS) values[field] = (c && c[field]) || '';
            if (!values.country) values.country = 'ES';
            if (values.country === 'ES') values.tax_id_type = '';
            return values;
        }

        async function pick(c) {
            customer.value = c;
            creating.value = false;
            error.value = '';
            if (!c.tax_complete) {
                form.value = formFrom(c);
                missing.value = Array.isArray(c.tax_missing) ? c.tax_missing.slice() : [];
                step.value = 'edit';
                return;
            }
            await loadSummary();
        }

        function startCreate() {
            customer.value = null;
            creating.value = true;
            error.value = '';
            missing.value = [];
            form.value = { firstname: '', lastname: '', email: '', phone: '', ...formFrom(null) };
            step.value = 'edit';
        }

        function isMissing(field) {
            return missing.value.indexOf(field) >= 0;
        }

        async function saveTaxData() {
            if (busy.value) return;
            busy.value = true;
            error.value = '';
            try {
                let target = customer.value;
                if (creating.value) {
                    const f = form.value;
                    const created = await ApiClient.customerCreate({ firstname: String(f.firstname || '').trim(), lastname: String(f.lastname || '').trim(), email: String(f.email || '').trim(), phone: String(f.phone || '').trim() });
                    target = created && created.data;
                    if (!target) throw new Error('Respuesta inesperada del servidor');
                    customer.value = target;
                    creating.value = false;
                }
                const body = {};
                for (const field of TAX_FIELDS) body[field] = String(form.value[field] || '').trim();
                if (body.country.toUpperCase() === 'ES') delete body.tax_id_type;
                const saved = await ApiClient.put('/api/customers/' + encodeURIComponent(target.local_id), body);
                customer.value = (saved && saved.data) || target;
                if (!customer.value.tax_complete) {
                    missing.value = Array.isArray(customer.value.tax_missing) ? customer.value.tax_missing.slice() : [];
                    error.value = 'Faltan datos fiscales: completa los campos marcados.';
                    return;
                }
                missing.value = [];
                await loadSummary();
            } catch (err) {
                error.value = serverError(err, 'No se pudieron guardar los datos del cliente.');
            } finally {
                busy.value = false;
            }
        }

        async function loadSummary() {
            const order = props.order || {};
            busy.value = true;
            error.value = '';
            try {
                const r = await ApiClient.get('/api/invoices/from-sale/' + encodeURIComponent(order.order_id) + '/preview?customer_id=' + encodeURIComponent(customer.value.local_id));
                summary.value = (r && r.data) || null;
                step.value = 'summary';
            } catch (err) {
                const body = (err && err.body) || {};
                error.value = serverError(err, 'No se puede emitir la factura.');
                if (Array.isArray(body.missing) && body.missing.length && body.missing[0] !== 'customer') {
                    form.value = formFrom(customer.value);
                    missing.value = body.missing.slice();
                    step.value = 'edit';
                }
            } finally {
                busy.value = false;
            }
        }

        async function confirm() {
            const order = props.order || {};
            if (busy.value || !customer.value) return;
            busy.value = true;
            error.value = '';
            try {
                const r = await ApiClient.post('/api/invoices/from-sale/' + encodeURIComponent(order.order_id), {
                    customer_id: customer.value.local_id,
                    email_consent: !!emailConsent.value,
                    request_id: requestId,
                });
                if (!r || !r.data || !r.data.number) throw new Error('Respuesta inesperada del servidor');
                emit('issued', r.data);
            } catch (err) {
                error.value = serverError(err, 'No se pudo emitir la factura.');
            } finally {
                busy.value = false;
            }
        }

        function back() {
            error.value = '';
            step.value = 'customer';
        }

        function money(value) {
            return Number(value || 0).toFixed(2).replace('.', ',') + ' €';
        }

        function rate(value) {
            const n = Number(value || 0);
            return (Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',')) + ' %';
        }

        function date(value) {
            const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
            return m ? m[3] + '/' + m[2] + '/' + m[1] : String(value || '');
        }

        Vue.watch(() => q.value, scheduleSearch);
        search();

        return {
            DOCUMENT_TYPES, step, q, results, searching, customer, creating, form, missing, error, busy, summary, emailConsent,
            search, pick, startCreate, isMissing, saveTaxData, loadSummary, confirm, back, money, rate, date,
        };
    },
    template: `
        <div class="combinations-modal-backdrop">
            <div class="combinations-modal invoice-modal" role="dialog" aria-label="Factura completa">
                <div class="combinations-modal-header">
                    <h3 style="margin:0; font-size:16px; color:var(--text); font-weight:600;">Factura completa · {{ order.reference }}</h3>
                    <button @click="$emit('close')" aria-label="Cerrar" style="margin-left:auto; background:none; border:none; color:var(--text); font-size:20px; cursor:pointer; line-height:1; padding:4px 8px;">✕</button>
                </div>

                <div v-if="step === 'customer'" style="display:flex; flex-direction:column; gap:10px;">
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input v-model="q" class="tpv-input" style="flex:1; min-width:0;" placeholder="Nombre, NIF, email o teléfono…" aria-label="Buscar cliente" />
                        <button class="tpv-btn" @click="startCreate">+ Nuevo cliente</button>
                    </div>
                    <div v-if="searching" style="font-size:12px; color:var(--text3);">Buscando…</div>
                    <div style="display:flex; flex-direction:column; gap:6px; max-height:300px; overflow:auto;">
                        <button v-for="c in results" :key="c.local_id" class="tpv-row" style="min-height:48px; text-align:left;" @click="pick(c)">
                            <span style="flex:1; min-width:0;">
                                <span style="display:block; font-weight:600; color:var(--text);">{{ c.tax_name || c.name }}</span>
                                <span style="display:block; font-size:12px; color:var(--text2);">{{ c.tax_id || 'Sin NIF' }} · {{ c.email }}</span>
                            </span>
                            <span v-if="!c.tax_complete" style="font-size:11px; color:var(--amber);">Faltan datos</span>
                        </button>
                        <div v-if="!searching && !results.length" style="font-size:13px; color:var(--text3);">No hay clientes con esa búsqueda.</div>
                    </div>
                </div>

                <div v-else-if="step === 'edit'" style="display:flex; flex-direction:column; gap:8px;">
                    <div style="font-size:13px; color:var(--text2);">{{ creating ? 'Nuevo cliente con sus datos fiscales' : 'Datos fiscales de ' + (customer && customer.name) }}</div>
                    <template v-if="creating">
                        <div class="invoice-form-row">
                            <input v-model="form.firstname" class="tpv-input" placeholder="Nombre" aria-label="Nombre" />
                            <input v-model="form.lastname" class="tpv-input" placeholder="Apellidos" aria-label="Apellidos" />
                        </div>
                        <div class="invoice-form-row">
                            <input v-model="form.email" class="tpv-input" inputmode="email" placeholder="Email" aria-label="Email" />
                            <input v-model="form.phone" class="tpv-input" inputmode="tel" placeholder="Teléfono" aria-label="Teléfono" />
                        </div>
                    </template>
                    <input v-model="form.tax_name" class="tpv-input" :class="{ 'invoice-missing': isMissing('tax_name') }" maxlength="120" placeholder="Razón social o nombre completo" aria-label="Razón social o nombre completo" />
                    <div class="invoice-form-row">
                        <input v-model="form.country" class="tpv-input" style="max-width:80px;" maxlength="2" placeholder="País" aria-label="País (código de dos letras)" />
                        <select v-if="String(form.country || '').toUpperCase() !== 'ES'" v-model="form.tax_id_type" class="tpv-input" :class="{ 'invoice-missing': isMissing('tax_id_type') }" aria-label="Tipo de documento">
                            <option value="">Tipo de documento</option>
                            <option v-for="t in DOCUMENT_TYPES" :key="t.code" :value="t.code">{{ t.code }} · {{ t.label }}</option>
                        </select>
                        <input v-model="form.tax_id" class="tpv-input" :class="{ 'invoice-missing': isMissing('tax_id') }" maxlength="20" :placeholder="String(form.country || '').toUpperCase() === 'ES' ? 'NIF / NIE / CIF' : 'Número de documento'" aria-label="NIF o documento" />
                    </div>
                    <input v-model="form.address" class="tpv-input" :class="{ 'invoice-missing': isMissing('address') }" maxlength="255" placeholder="Domicilio" aria-label="Domicilio" />
                    <div class="invoice-form-row">
                        <input v-model="form.postcode" class="tpv-input" :class="{ 'invoice-missing': isMissing('postcode') }" style="max-width:120px;" maxlength="12" placeholder="Código postal" aria-label="Código postal" />
                        <input v-model="form.city" class="tpv-input" :class="{ 'invoice-missing': isMissing('city') }" maxlength="100" placeholder="Población" aria-label="Población" />
                        <input v-model="form.province" class="tpv-input" maxlength="100" placeholder="Provincia" aria-label="Provincia" />
                    </div>
                    <div style="display:flex; gap:8px; margin-top:4px;">
                        <button class="tpv-btn" style="flex:1;" @click="back">Volver</button>
                        <button class="tpv-btn" style="flex:2;" :disabled="busy" @click="saveTaxData">{{ busy ? 'Guardando…' : 'Guardar y continuar' }}</button>
                    </div>
                </div>

                <div v-else-if="step === 'summary' && summary" style="display:flex; flex-direction:column; gap:8px; font-size:13px; color:var(--text);">
                    <div>Ticket <b>{{ summary.replaced_reference }}</b> del {{ date(summary.replaced_date) }} · <b>{{ money(summary.total) }}</b></div>
                    <div>Cliente: <b>{{ summary.recipient.name }}</b> · {{ summary.recipient.tax_id }}</div>
                    <div style="color:var(--text2);">{{ summary.recipient.address }}, {{ summary.recipient.postcode }} {{ summary.recipient.city }}</div>
                    <table class="invoice-vat-table">
                        <thead><tr><th>Base</th><th>IVA</th><th>Cuota</th></tr></thead>
                        <tbody>
                            <tr v-for="row in summary.tax_breakdown" :key="row.rate"><td>{{ money(row.base) }}</td><td>{{ rate(row.rate) }}</td><td>{{ money(row.vat) }}</td></tr>
                        </tbody>
                    </table>
                    <div style="color:var(--text2);">Se emitirá la factura {{ summary.next_number || 'siguiente' }} con fecha de hoy, que sustituye al ticket (el ticket no se anula).</div>
                    <div v-for="w in summary.warnings" :key="w" style="color:var(--amber);">{{ w }}</div>
                    <label style="display:flex; gap:8px; align-items:center; min-height:44px; cursor:pointer;">
                        <input type="checkbox" v-model="emailConsent" style="width:20px; height:20px;" />
                        El cliente acepta recibir la factura por email
                    </label>
                    <div style="display:flex; gap:8px;">
                        <button class="tpv-btn" style="flex:1;" @click="back">Cambiar cliente</button>
                        <button class="tpv-btn" style="flex:2; min-height:48px;" :disabled="busy" @click="confirm">{{ busy ? 'Emitiendo…' : 'Emitir factura' }}</button>
                    </div>
                </div>

                <div v-if="error" role="alert" style="color:var(--red); font-size:13px; margin-top:8px;">{{ error }}</div>
            </div>
        </div>
    `
};
