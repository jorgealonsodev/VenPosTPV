/**
 * CashCloseScreen — denomination counting and session summary.
 */
const CashCloseScreen = {
    props: ['session'],
    emits: ['back', 'close'],
    setup(props) {
        const counted = Vue.reactive({});
        const denoms = [
            { v: 500, label: '500€' }, { v: 200, label: '200€' }, { v: 100, label: '100€' },
            { v: 50, label: '50€' }, { v: 20, label: '20€' }, { v: 10, label: '10€' },
            { v: 5, label: '5€' }, { v: 2, label: '2€' }, { v: 1, label: '1€' },
            { v: 0.50, label: '50¢' }, { v: 0.20, label: '20¢' }, { v: 0.10, label: '10¢' },
            { v: 0.05, label: '5¢' }, { v: 0.02, label: '2¢' }, { v: 0.01, label: '1¢' },
        ];

        const total = Vue.computed(() => {
            return denoms.reduce((s, d) => s + d.v * (parseInt(counted[d.v]) || 0), 0);
        });

        const expected = Vue.computed(() => {
            if (props.session.expected !== undefined && props.session.expected !== null) {
                return Number(props.session.expected || 0);
            }

            return (props.session.openingCash || 0) + (props.session.sales || 0) - (props.session.refunds || 0);
        });

        const diff = Vue.computed(() => total.value - expected.value);

        return { counted, denoms, total, expected, diff };
    },
    template: `
        <div style="width:100%; height:100%; background:var(--bg); overflow:auto; padding:32px; display:flex; flex-direction:column; gap:24px;">
            <div style="display:flex; align-items:center; gap:16px;">
                <button @click="$emit('back')" style="background:var(--surface2); border:none; border-radius:8px; width:36px; height:36px; cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center;">
                    <icon name="arrow_back" :size="16" />
                </button>
                <div>
                    <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em;">Cierre de caja</div>
                    <div style="font-size:20px; font-weight:700;">Arqueo y cierre de jornada</div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; max-width:900px;">
                <!-- Conteo -->
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:24px;">
                    <div style="font-size:13px; font-weight:600; margin-bottom:16px;">Conteo de caja</div>
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px;">
                        <div v-for="d in denoms" :key="d.v" style="background:var(--surface2); border-radius:10px; padding:10px 12px; display:flex; flex-direction:column; gap:4px;">
                            <div style="font-size:11px; color:var(--text3); font-family:'IBM Plex Mono';">{{ d.label }}</div>
                            <input type="number" min="0" v-model="counted[d.v]" placeholder="0"
                                style="background:transparent; border:none; border-bottom:1px solid var(--border2); color:var(--text); font-family:'IBM Plex Mono'; font-size:14px; outline:none; width:100%; padding:2px 0;" />
                            <div v-if="counted[d.v] > 0" style="font-size:10px; color:var(--text3); font-family:'IBM Plex Mono';">= €{{ (d.v * parseInt(counted[d.v])).toFixed(2) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Resumen -->
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:24px; display:flex; flex-direction:column; gap:14px;">
                        <div style="font-size:13px; font-weight:600;">Resumen de sesión</div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Fondo inicial</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--text2); font-weight:500;">€{{ Number(session.openingCash || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Ventas (efectivo)</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--green); font-weight:500;">€{{ Number(session.sales || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Devoluciones</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--red); font-weight:500;">€{{ Number(session.refunds || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Efectivo esperado</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--text); font-weight:500;">€{{ Number(expected).toFixed(2) }}</span>
                        </div>
                    </div>

                    <div style="background:var(--surface); border-radius:16px; padding:24px;"
                        :style="{ border: '1px solid ' + (Math.abs(diff) < 1 ? 'var(--green-dim)' : 'var(--amber)') }">
                        <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:8px;">Total contado</div>
                        <div style="font-family:'IBM Plex Mono'; font-size:36px; font-weight:700;"
                            :style="{ color: Math.abs(diff) < 1 ? 'var(--green)' : 'var(--amber)' }">
                            €{{ Number(total).toFixed(2) }}
                        </div>
                        <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                            <span style="font-size:12px; color:var(--text3);">Diferencia:</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:14px;"
                                :style="{ color: Math.abs(diff) < 0.01 ? 'var(--green)' : diff > 0 ? 'var(--blue)' : 'var(--red)' }">
                                {{ diff >= 0 ? '+' : '' }}€{{ Number(diff).toFixed(2) }}
                            </span>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <button style="padding:14px; border-radius:12px; border:1px solid var(--border); background:var(--surface2); color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px; display:flex; align-items:center; justify-content:center; gap:8px;">
                            <icon name="print" :size="16" /> Imprimir informe X
                        </button>
                        <button @click="$emit('close', Number(total).toFixed(2))" style="padding:14px; border-radius:12px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:15px; font-weight:700;">
                            Cerrar caja y generar informe Z →
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
