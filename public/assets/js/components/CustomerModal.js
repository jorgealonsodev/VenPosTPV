/**
 * CustomerModal — customer search and select.
 */
const CustomerModal = {
    emits: ['select', 'close'],
    setup(props, { emit }) {
        const q = Vue.ref('');
        const demo = [
            { id: 1, name: 'Ana Martínez', email: 'ana@email.com', phone: '600 111 222' },
            { id: 2, name: 'Roberto Sánchez', email: 'rsanchez@empresa.es', phone: '611 333 444' },
            { id: 3, name: 'Laura Pérez', email: 'lperez@gmail.com', phone: '622 555 666' },
            { id: 4, name: 'Consumidor final', email: '—', phone: '—', default: true },
        ];

        const filtered = Vue.computed(() => {
            if (!q.value) return demo;
            const query = q.value.toLowerCase();
            return demo.filter(c =>
                c.name.toLowerCase().includes(query) ||
                c.email.toLowerCase().includes(query) ||
                c.phone.includes(query)
            );
        });

        return { q, filtered };
    },
    template: `
        <div class="modal-backdrop" style="backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);" @click.self="$emit('close')">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:28px; width:400px; display:flex; flex-direction:column; gap:16px;" @click.stop>
                <div style="font-size:16px; font-weight:600;">Seleccionar cliente</div>
                <input v-model="q" autofocus placeholder="Nombre, email o teléfono…"
                    style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:11px 14px; color:var(--text); font-family:inherit; font-size:14px; outline:none;" />
                <div style="display:flex; flex-direction:column; gap:6px; max-height:260px; overflow:auto;">
                    <button v-for="c in filtered" :key="c.id" @click="$emit('select', c)"
                        style="padding:12px 14px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); cursor:pointer; display:flex; align-items:center; justify-content:space-between; text-align:left; transition:all .1s;"
                        onmouseover="this.style.borderColor='var(--border2)'" onmouseout="this.style.borderColor='var(--border)'">
                        <div>
                            <div style="font-weight:600; color:var(--text); font-size:14px;">{{ c.name }}</div>
                            <div style="color:var(--text3); font-size:12px; margin-top:2px;">{{ c.email }}</div>
                        </div>
                        <span v-if="c.default" style="font-size:10px; background:var(--surface3); color:var(--text3); padding:3px 8px; border-radius:6px;">Por defecto</span>
                    </button>
                </div>
            </div>
        </div>
    `
};
