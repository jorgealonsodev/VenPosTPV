/**
 * CustomerModal — customer search and select, plus new customers (PRD C1, C2).
 *
 * The search reads the till's local copy of the customers (/api/customers), so it
 * works without PrestaShop. A new customer is stored at the till at once with a
 * local id and uploaded to PrestaShop before the sale that uses it. The selected
 * customer carries `local_id` (local copy) and `id` (PrestaShop id, null until it
 * is uploaded); the server takes the PrestaShop id from its copy.
 */
const CustomerModal = {
    emits: ['select', 'close'],
    setup(props, { emit }) {
        const DEFAULT_CUSTOMER = { name: 'Consumidor final', email: '—', phone: '—', default: true };
        const q = Vue.ref('');
        const results = Vue.ref([]);
        const loading = Vue.ref(false);
        const searchError = Vue.ref('');
        const mode = Vue.ref('search'); // search | create
        const form = Vue.ref({ firstname: '', lastname: '', email: '', phone: '' });
        const formError = Vue.ref('');
        const saving = Vue.ref(false);
        let searchSeq = 0;
        let debounce = null;

        async function search() {
            const seq = ++searchSeq;
            loading.value = true;
            searchError.value = '';
            try {
                const response = await ApiClient.customerSearch(q.value.trim());
                if (seq !== searchSeq) return;
                results.value = response && Array.isArray(response.data) ? response.data : [];
            } catch (err) {
                if (seq !== searchSeq) return;
                results.value = [];
                searchError.value = 'No se pudo buscar clientes.';
            } finally {
                if (seq === searchSeq) loading.value = false;
            }
        }

        function scheduleSearch() {
            if (debounce) clearTimeout(debounce);
            debounce = setTimeout(search, 200);
        }

        Vue.watch(() => q.value, scheduleSearch);

        const filtered = Vue.computed(() => {
            const query = q.value.trim().toLowerCase();
            const list = results.value.slice();
            if (!query || DEFAULT_CUSTOMER.name.toLowerCase().includes(query)) list.push(DEFAULT_CUSTOMER);
            return list;
        });

        function select(customer) {
            emit('select', customer.default ? { name: DEFAULT_CUSTOMER.name, default: true } : customer);
        }

        function startCreate() {
            mode.value = 'create';
            formError.value = '';
            const words = q.value.trim().split(/\s+/).filter(Boolean);
            const typed = q.value.trim();
            form.value = {
                firstname: typed.includes('@') ? '' : (words[0] || ''),
                lastname: typed.includes('@') ? '' : words.slice(1).join(' '),
                email: typed.includes('@') ? typed : '',
                phone: '',
            };
        }

        async function create() {
            if (saving.value) return;
            formError.value = '';
            const f = form.value;
            if (!f.firstname.trim() || !f.lastname.trim() || !f.email.trim()) {
                formError.value = 'Nombre, apellidos y email son obligatorios.';
                return;
            }
            saving.value = true;
            try {
                const response = await ApiClient.customerCreate({
                    firstname: f.firstname.trim(),
                    lastname: f.lastname.trim(),
                    email: f.email.trim(),
                    phone: f.phone.trim(),
                });
                if (!response || !response.data) throw new Error('Respuesta inesperada del servidor');
                emit('select', response.data);
            } catch (err) {
                const msg = String((err && err.message) || '');
                const json = msg.indexOf('{') >= 0 ? msg.slice(msg.indexOf('{')) : '';
                let serverError = '';
                try { serverError = json ? (JSON.parse(json).error || '') : ''; } catch (e) { serverError = ''; }
                formError.value = serverError || 'No se pudo guardar el cliente.';
            } finally {
                saving.value = false;
            }
        }

        search();

        return { q, results, loading, searchError, filtered, mode, form, formError, saving, search, select, startCreate, create };
    },
    template: `
        <div class="modal-backdrop" style="backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);">
            <div class="tpv-fit-card" style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:28px; width:400px; display:flex; flex-direction:column; gap:16px;" @click.stop>
                <template v-if="mode === 'search'">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <div style="font-size:16px; font-weight:600;">Seleccionar cliente</div>
                        <span style="display:flex; align-items:center; gap:8px;">
                            <button @click="startCreate" style="padding:6px 12px; border-radius:8px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-weight:600; font-size:12px;">+ Nuevo cliente</button>
                            <button @click="$emit('close')" aria-label="Cerrar" style="background:none; border:none; color:var(--text); font-size:20px; cursor:pointer; line-height:1; padding:4px 8px;">✕</button>
                        </span>
                    </div>
                    <input v-model="q" autofocus placeholder="Nombre, email o teléfono…"
                        style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:11px 14px; color:var(--text); font-family:inherit; font-size:14px; outline:none;" />
                    <div v-if="searchError" style="color:var(--red); font-size:12px;">{{ searchError }}</div>
                    <div style="display:flex; flex-direction:column; gap:6px; max-height:260px; overflow:auto;">
                        <button v-for="c in filtered" :key="c.default ? 'default' : c.local_id" @click="select(c)"
                            style="padding:12px 14px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); cursor:pointer; display:flex; align-items:center; justify-content:space-between; text-align:left; transition:all .1s;"
                            @mouseover="$event.currentTarget.style.borderColor='var(--border2)'" @mouseout="$event.currentTarget.style.borderColor='var(--border)'">
                            <div>
                                <div style="font-weight:600; color:var(--text); font-size:14px;">{{ c.name }}</div>
                                <div style="color:var(--text3); font-size:12px; margin-top:2px;">{{ c.email }}<span v-if="c.phone && !c.default"> · {{ c.phone }}</span></div>
                            </div>
                            <span v-if="c.default" style="font-size:10px; background:var(--surface3); color:var(--text3); padding:3px 8px; border-radius:6px;">Por defecto</span>
                            <span v-else-if="!c.synced" style="font-size:10px; background:var(--amber-bg); color:var(--amber); padding:3px 8px; border-radius:6px;">Pendiente de subir</span>
                        </button>
                        <div v-if="loading" style="color:var(--text3); font-size:12px;">Buscando…</div>
                    </div>
                </template>
                <template v-else>
                    <div style="font-size:16px; font-weight:600;">Nuevo cliente</div>
                    <div style="font-size:12px; color:var(--text3);">Se guarda en el TPV al momento y se sube a PrestaShop antes que su venta.</div>
                    <input v-model="form.firstname" placeholder="Nombre" style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:11px 14px; color:var(--text); font-family:inherit; font-size:14px; outline:none;" />
                    <input v-model="form.lastname" placeholder="Apellidos" style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:11px 14px; color:var(--text); font-family:inherit; font-size:14px; outline:none;" />
                    <input v-model="form.email" type="email" placeholder="Email" style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:11px 14px; color:var(--text); font-family:inherit; font-size:14px; outline:none;" />
                    <input v-model="form.phone" inputmode="tel" placeholder="Teléfono (opcional)" style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:11px 14px; color:var(--text); font-family:inherit; font-size:14px; outline:none;" />
                    <div v-if="formError" style="color:var(--red); font-size:12px;">{{ formError }}</div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button @click="mode = 'search'" style="padding:10px 16px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); color:var(--text2); cursor:pointer;">Volver</button>
                        <button @click="create" :disabled="saving" style="padding:10px 16px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-weight:600;">{{ saving ? 'Guardando…' : 'Guardar y seleccionar' }}</button>
                    </div>
                </template>
            </div>
        </div>
    `
};
