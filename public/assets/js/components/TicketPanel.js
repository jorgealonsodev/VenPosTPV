/**
 * TicketPanel — active ticket lines with qty controls, totals, and action bar.
 */
const TicketPanel = {
    // stockByKey: local stock by "<product>:<combination>" of what the sale screen added.
    props: ['cart', 'stockByKey'],
    emits: ['remove', 'updateQty', 'showPayment', 'showDiscount', 'showCustomer', 'park', 'void'],
    setup(props) {
        const totalLines = Vue.computed(() => {
            return props.cart.lines.reduce((s, l) => s + (l.qty || 1), 0);
        });

        /** PRD V4: more units than the TPV's stock. Marked only; the sale goes ahead. */
        function outOfStock(line) {
            if (!props.stockByKey || !line) return false;
            const stock = props.stockByKey[line.product_id + ':' + (line.id_product_attribute || 0)];
            if (stock === undefined || stock === null) return false;
            return (line.qty || 1) > Number(stock);
        }

        // Spanish money format: 68,78 €
        const eur = (n) => (Number(n) || 0).toFixed(2).replace('.', ',') + ' €';

        return { totalLines, outOfStock, eur };
    },
    template: `
        <div style="display:flex; flex-direction:column; height:100%; overflow:hidden;">
            <!-- Customer bar -->
            <div style="border-bottom:1px solid var(--border); padding:10px 14px; display:flex; align-items:center; gap:8px;">
                <button @click="$emit('showCustomer')"
                    style="flex:1; display:flex; align-items:center; gap:8px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:8px 12px; cursor:pointer; text-align:left; font-family:inherit;">
                    <icon name="user" :size="14" />
                    <span style="font-size:13px; color: cart.customer && !cart.customer.default ? 'var(--text)' : 'var(--text3)'; font-weight: cart.customer && !cart.customer.default ? 500 : 400;">
                        {{ cart.customer ? cart.customer.name : 'Consumidor final' }}
                    </span>
                    <span v-if="cart.customer && !cart.customer.default" style="margin-left:auto; font-size:10px; color:var(--blue); background:var(--blue-bg); padding:2px 6px; border-radius:4px;">CLIENTE</span>
                </button>
            </div>

            <!-- Lines -->
            <div style="flex:1; overflow:auto; padding:8px 12px; display:flex; flex-direction:column; gap:4px;">
                <div v-if="cart.lines.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px;">
                    <icon name="receipt" :size="32" />
                    <span style="font-size:13px;">Ticket vacío — añade productos</span>
                </div>
                <div v-for="line in cart.lines" :key="line.id"
                    style="background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:8px 10px; display:flex; flex-direction:column; gap:6px; transition:all .12s;">
                    <div style="display:flex; align-items:flex-start; gap:6px;">
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:12px; font-weight:500; color:var(--text); line-height:1.3;">{{ line.name }}</div>
                            <div v-if="line.reference" style="font-size:10px; color:var(--text3); font-family:'IBM Plex Mono'; margin-top:2px;">{{ line.reference }}</div>
                            <span v-if="outOfStock(line)" class="tpv-tag" style="margin-top:4px; font-size:12px;" title="Se puede vender igualmente; revisa el inventario">Sin stock en el TPV</span>
                        </div>
                        <div style="font-family:'IBM Plex Mono'; font-size:13px; font-weight:600; color:var(--text); flex-shrink:0;">
                            {{ eur((line.price * (line.qty || 1) * (1 - (line.discount || 0)/100))) }}
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <button @click="$emit('updateQty', line.id, -1)"
                            style="width:26px; height:26px; border-radius:6px; border:1px solid var(--border); background:var(--surface); cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <icon name="minus" :size="12" />
                        </button>
                        <span style="font-family:'IBM Plex Mono'; font-size:13px; font-weight:600; min-width:20px; text-align:center; color:var(--text);">{{ line.qty || 1 }}</span>
                        <button @click="$emit('updateQty', line.id, 1)"
                            style="width:26px; height:26px; border-radius:6px; border:1px solid var(--border); background:var(--surface); cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <icon name="plus" :size="12" />
                        </button>
                        <span style="color:var(--text3); font-size:11px; font-family:'IBM Plex Mono';">× {{ eur(Number(line.price)) }}</span>
                        <span v-if="line.discount > 0" style="font-size:10px; background:var(--amber-bg); color:var(--amber); padding:2px 5px; border-radius:4px;">-{{ line.discount }}%</span>
                        <button @click="$emit('remove', line.id)"
                            style="margin-left:auto; width:26px; height:26px; border-radius:6px; border:none; background:transparent; cursor:pointer; color:var(--text3); display:flex; align-items:center; justify-content:center;">
                            <icon name="trash" :size="13" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div v-if="cart.lines.length > 0" style="border-top:1px solid var(--border); padding:10px 14px 8px; display:flex; flex-direction:column; gap:5px;">
                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text3);">
                    <span>{{ totalLines }} artículo{{ totalLines !== 1 ? 's' : '' }}</span>
                    <span style="font-family:'IBM Plex Mono';">{{ eur(Number(cart.subtotal)) }}</span>
                </div>
                <div v-if="cart.discount_amount > 0" style="display:flex; justify-content:space-between; font-size:12px; color:var(--amber);">
                    <span>Descuento {{ cart.discount && cart.discount.type === 'pct' ? cart.discount.value + '%' : '' }}</span>
                    <span style="font-family:'IBM Plex Mono';">-{{ eur(Number(cart.discount_amount)) }}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text3);">
                    <span>IVA incluido</span>
                    <span style="font-family:'IBM Plex Mono';">{{ eur(Number(cart.iva)) }}</span>
                </div>
            </div>

            <!-- Action bar -->
            <div style="padding:8px 12px 12px; border-top: cart.lines.length > 0 ? 'none' : '1px solid var(--border)'; display:flex; flex-direction:column; gap:8px;">
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px;">
                    <button @click="$emit('showDiscount')"
                        style="padding:9px 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; justify-content:center; gap:5px; transition:all .1s;"
                        @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background='transparent'">
                        <icon name="tag" :size="13" /> Descuento
                    </button>
                    <button @click="$emit('park')"
                        style="padding:9px 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; justify-content:center; gap:5px; transition:all .1s;"
                        @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background='transparent'">
                        <icon name="pause" :size="13" /> Aparcar
                    </button>
                    <button @click="$emit('void')"
                        style="padding:9px 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--red); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; justify-content:center; gap:5px; transition:all .1s;"
                        @mouseover="$event.currentTarget.style.background='var(--red-bg)'" @mouseout="$event.currentTarget.style.background='transparent'">
                        <icon name="close" :size="13" /> Anular
                    </button>
                </div>

                <button @click="$emit('showPayment')" :disabled="cart.lines.length === 0"
                    style="padding:0 20px; height:62px; border-radius:14px; border:none; font-family:inherit; font-size:18px; font-weight:800; display:flex; align-items:center; justify-content:space-between; transition:all .15s; letter-spacing:0.01em;"
                    :style="{ background: cart.lines.length ? 'var(--primary)' : 'var(--surface2)', color: cart.lines.length ? 'var(--on-primary)' : 'var(--text2)', cursor: cart.lines.length ? 'pointer' : 'default' }">
                    <span>Cobrar</span>
                    <span style="font-family:'IBM Plex Mono'; font-size:22px; font-weight:700;">{{ eur(Number(cart.total)) }}</span>
                </button>
            </div>
        </div>
    `
};
