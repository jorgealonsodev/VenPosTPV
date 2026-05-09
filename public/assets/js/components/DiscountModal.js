/**
 * DiscountModal — percentage or amount discount input.
 */
const DiscountModal = {
    emits: ['apply', 'close'],
    setup(props, { emit }) {
        const type = Vue.ref('pct');
        const val = Vue.ref('');

        function apply() {
            if (val.value) {
                emit('apply', type.value, parseFloat(val.value));
            }
        }

        return { type, val, apply };
    },
    template: `
        <div class="modal-backdrop" style="backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);" @click.self="$emit('close')">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:32px; width:320px; display:flex; flex-direction:column; gap:20px;" @click.stop>
                <div style="font-size:16px; font-weight:600;">Descuento global</div>
                <div style="display:flex; gap:8px;">
                    <button v-for="t in [{id:'pct',label:'Porcentaje %'},{id:'amt',label:'Importe €'}]" :key="t.id"
                        @click="type = t.id"
                        style="flex:1; padding:9px; border-radius:8px; border:1px solid; cursor:pointer; font-family:inherit; font-size:13px; transition:all .1s;"
                        :style="{ borderColor: type===t.id ? 'var(--amber)' : 'var(--border)', background: type===t.id ? 'var(--amber-bg)' : 'transparent', color: type===t.id ? 'var(--amber)' : 'var(--text2)' }">
                        {{ t.label }}
                    </button>
                </div>
                <input v-model="val" autofocus type="number" :placeholder="type==='pct'?'10':'5.00'"
                    style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:13px 16px; color:var(--text); font-family:'IBM Plex Mono'; font-size:22px; outline:none;" />
                <div style="display:flex; gap:8px;">
                    <button @click="$emit('close')"
                        style="flex:1; padding:12px; border-radius:10px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px;">
                        Cancelar
                    </button>
                    <button @click="apply"
                        style="flex:2; padding:12px; border-radius:10px; border:none; background:var(--amber); color:#000; cursor:pointer; font-family:inherit; font-size:14px; font-weight:700;">
                        Aplicar descuento
                    </button>
                </div>
            </div>
        </div>
    `
};
