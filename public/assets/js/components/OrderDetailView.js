/**
 * OrderDetailView — read-only order detail (items, qty, subtotal, IVA, total, payment method).
 */
const OrderDetailView = {
    props: ['order'],
    emits: ['back'],
    setup(props) {
        const totalQty = Vue.computed(() => {
            if (!props.order || !props.order.lines) return 0;
            return props.order.lines.reduce((sum, line) => sum + (line.qty || line.quantity || 1), 0);
        });

        return { totalQty };
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
                    {{ order.reference || ('#' + order.ps_order_id) }}
                </div>
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
            </div>
        </div>
    `
};
