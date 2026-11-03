/**
 * RefundScreen — order search, line selection and refund confirmation.
 *
 * Amounts shown here are an estimate of what the server will refund: the server
 * computes the real amounts from what each sale line paid, so the request only
 * carries line indexes, quantities and the restock choice.
 */
const REFUND_REASONS = [
    { code: 'size', label: 'Talla o color' },
    { code: 'defective', label: 'Defectuoso' },
    { code: 'dislike', label: 'No le gusta' },
    { code: 'billing_error', label: 'Error de cobro' },
    { code: 'not_as_described', label: 'No coincide con la web' },
    { code: 'gift_duplicate', label: 'Regalo / duplicado' },
    { code: 'late', label: 'Llegó tarde' },
    { code: 'other', label: 'Otro' },
];

const RefundScreen = {
    props: ['cashierName'],
    emits: ['back', 'notify'],
    setup(props, { emit }) {
        const { session } = useSession;

        const searchQuery = Vue.ref('');
        const searchResults = Vue.ref([]);
        const selectedOrder = Vue.ref(null);
        const selectedLines = Vue.ref([]);
        const reasonCode = Vue.ref('');
        const reasonText = Vue.ref('');
        const cardConfirmed = Vue.ref(false);
        const loading = Vue.ref(false);
        const confirmModal = Vue.ref(false);

        // Same half-up rounding as PHP round(): 10.075 must give 10.08, not 10.07.
        const round2 = (n) => Math.round(Number(((Number(n) || 0) * 100).toPrecision(12))) / 100;

        async function handleSearch() {
            if (!searchQuery.value || searchQuery.value.length < 2) {
                emit('notify', 'Introduce al menos 2 caracteres', 'var(--red)');
                return;
            }
            loading.value = true;
            try {
                const result = await ApiClient.searchOrders(searchQuery.value);
                searchResults.value = result && Array.isArray(result.data) ? result.data : [];
            } catch (err) {
                emit('notify', 'Error en búsqueda: ' + (err.message || 'Error'), 'var(--red)');
                searchResults.value = [];
            } finally {
                loading.value = false;
            }
        }

        function selectOrder(order) {
            selectedOrder.value = order;
            reasonCode.value = '';
            reasonText.value = '';
            cardConfirmed.value = false;
            selectedLines.value = (order.lines || []).map((line, idx) => {
                const sold = Number(line.qty || line.quantity || 1);
                const refundable = line.refundable_qty !== undefined ? Number(line.refundable_qty) : sold;
                const paid = line.paid_total !== undefined ? Number(line.paid_total) : null;
                return {
                    index: idx,
                    name: line.name || 'Producto',
                    sold,
                    refundable,
                    unitPaid: paid !== null && sold > 0 ? paid / sold : null,
                    refundable_ok: Boolean(line.product_id) && paid !== null,
                    checked: false,
                    refundQty: Math.max(1, refundable),
                    restock: true,
                };
            });
        }

        function backToSearch() {
            selectedOrder.value = null;
            selectedLines.value = [];
            reasonCode.value = '';
            reasonText.value = '';
            cardConfirmed.value = false;
            confirmModal.value = false;
        }

        function toggleLine(idx) {
            const line = selectedLines.value[idx];
            if (line && line.refundable_ok && line.refundable > 0) {
                line.checked = !line.checked;
            }
        }

        function updateRefundQty(idx, qty) {
            const line = selectedLines.value[idx];
            if (line) {
                line.refundQty = Math.max(1, Math.min(line.refundable, parseInt(qty, 10) || 1));
            }
        }

        function setReason(code) {
            reasonCode.value = code;
            // A defective item normally must not go back on sale; the cashier can still tick it.
            const restock = code !== 'defective';
            selectedLines.value.forEach((line) => { line.restock = restock; });
        }

        function estimatedTotal() {
            return round2(selectedLines.value
                .filter((l) => l.checked && l.unitPaid !== null)
                .reduce((sum, l) => sum + l.unitPaid * l.refundQty, 0));
        }

        function estimatedSplit() {
            const total = estimatedTotal();
            const order = selectedOrder.value || {};
            const tender = order.tender || {};
            let cash = Number(tender.cash || 0);
            let card = Number(tender.card || 0);
            if (cash + card <= 0) {
                cash = order.payment_method === 'card' ? 0 : 1;
                card = 1 - cash;
            }
            const cashPart = round2(total * cash / (cash + card));
            return { cash: cashPart, card: round2(total - cashPart) };
        }

        function needsCardConfirmation() {
            return estimatedSplit().card > 0;
        }

        function validationError() {
            if (!selectedLines.value.some((l) => l.checked)) return 'Selecciona al menos una línea';
            if (!reasonCode.value) return 'Elige el motivo de la devolución';
            if (reasonCode.value === 'other' && !reasonText.value.trim()) return 'Escribe el motivo';
            if (needsCardConfirmation() && !cardConfirmed.value) return 'Confirma que has hecho la devolución en el datáfono';
            return '';
        }

        function buildRequest() {
            return {
                lines: selectedLines.value
                    .filter((l) => l.checked)
                    .map((l) => ({ line_index: l.index, quantity: l.refundQty, restock: Boolean(l.restock) })),
                reason_code: reasonCode.value,
                reason_text: reasonCode.value === 'other' ? reasonText.value.trim() : '',
                card_confirmed: needsCardConfirmation() ? Boolean(cardConfirmed.value) : false,
            };
        }

        function openConfirm() {
            const error = validationError();
            if (error) {
                emit('notify', error, 'var(--red)');
                return;
            }
            confirmModal.value = true;
        }

        function errorMessage(err) {
            const msg = (err && err.message) || 'Error';
            const match = /^HTTP (\d+): (.*)$/s.exec(msg);
            let detail = msg;
            let status = 0;
            if (match) {
                status = Number(match[1]);
                try {
                    const body = JSON.parse(match[2]);
                    detail = body.error || match[2];
                } catch (e) {
                    detail = match[2];
                }
            }
            if (status === 404) return 'Pedido no encontrado';
            if (status === 409) return 'No se ha devuelto nada nuevo: ' + detail;
            if (status === 422) return 'No se puede devolver: ' + detail;
            if (status === 502) return 'PrestaShop no responde; no se ha devuelto nada. Inténtalo de nuevo: ' + detail;
            return 'Error en devolución: ' + detail;
        }

        async function confirmRefund() {
            confirmModal.value = false;
            if (!selectedOrder.value || loading.value) return;

            const request = buildRequest();
            loading.value = true;
            try {
                const result = await ApiClient.refundOrder(selectedOrder.value.ps_order_id, request);
                const data = (result && result.data) || {};
                const parts = ['Devolución de ' + Number(data.refunded_amount || 0).toFixed(2) + ' €'];
                if (Number(data.cash_amount) > 0) parts.push(Number(data.cash_amount).toFixed(2) + ' € en efectivo');
                if (Number(data.card_amount) > 0) parts.push(Number(data.card_amount).toFixed(2) + ' € a tarjeta');
                const warn = data.saga_error || (Array.isArray(data.restock_failed) && data.restock_failed.length > 0);
                emit('notify', parts.join(' · ') + (warn ? ' — revisar stock/caja' : ''), warn ? 'var(--orange, var(--red))' : 'var(--green)');
                backToSearch();
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        function cancelConfirm() {
            confirmModal.value = false;
        }

        return {
            session,
            reasons: REFUND_REASONS,
            searchQuery,
            searchResults,
            selectedOrder,
            selectedLines,
            reasonCode,
            reasonText,
            cardConfirmed,
            loading,
            confirmModal,
            handleSearch,
            selectOrder,
            backToSearch,
            toggleLine,
            updateRefundQty,
            setReason,
            estimatedTotal,
            estimatedSplit,
            needsCardConfirmation,
            validationError,
            buildRequest,
            openConfirm,
            confirmRefund,
            cancelConfirm,
            errorMessage,
        };
    },
    template: `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <header style="height:52px; min-height:52px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <button @click="$emit('back')" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; gap:4px;">
                    <icon name="arrow_back" :size="14" /> Volver
                </button>
                <div style="font-weight:700; font-size:15px; letter-spacing:-0.01em; color:var(--text);">Devoluciones</div>
                <div v-if="!session.online" style="margin-left:auto; display:flex; align-items:center; gap:5px; color:var(--red); font-size:12px; background:var(--red-bg); padding:4px 10px; border-radius:8px;">
                    <icon name="wifi_off" :size="13" /> Sin conexión
                </div>
            </header>

            <div v-if="!selectedOrder" style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; gap:8px;">
                    <input v-model="searchQuery" @keyup.enter="handleSearch" placeholder="Buscar por nº de pedido o referencia..." style="flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                    <button @click="handleSearch" :disabled="loading" style="padding:8px 16px; border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                        <span v-if="loading">...</span>
                        <span v-else>Buscar</span>
                    </button>
                </div>
                <div v-if="searchResults.length === 0 && !loading" style="color:var(--text3); font-size:13px; text-align:center; margin-top:40px;">
                    No hay resultados. Introduce un nº de pedido o referencia y pulsa Buscar.
                </div>
                <div v-for="order in searchResults" :key="order.ps_order_id" @click="selectOrder(order)" style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; color:var(--text); font-size:14px;">#{{ order.ps_order_id }} — {{ order.reference }}</span>
                        <span style="color:var(--text2); font-size:12px;">{{ Number(order.total).toFixed(2) }} €</span>
                    </div>
                    <div style="color:var(--text3); font-size:12px; display:flex; gap:10px;">
                        <span>{{ order.payment_method }}</span>
                        <span>{{ order.created_at }}</span>
                        <span v-if="order.refunded_amount > 0" style="color:var(--red);">Devuelto: {{ Number(order.refunded_amount).toFixed(2) }} €</span>
                    </div>
                </div>
            </div>

            <div v-else style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-weight:700; color:var(--text); font-size:16px;">Pedido #{{ selectedOrder.ps_order_id }}</div>
                        <div style="color:var(--text2); font-size:12px;">{{ selectedOrder.reference }} — {{ selectedOrder.created_at }}</div>
                    </div>
                    <button @click="backToSearch" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        Volver a resultados
                    </button>
                </div>

                <div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div v-for="(line, idx) in selectedLines" :key="idx" style="display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid var(--border);" :style="{ opacity: line.refundable_ok && line.refundable > 0 ? 1 : 0.5 }">
                        <input type="checkbox" :checked="line.checked" :disabled="!line.refundable_ok || line.refundable === 0" @change="toggleLine(idx)" style="width:18px; height:18px; cursor:pointer;" />
                        <div style="flex:1; color:var(--text); font-size:13px;">
                            {{ line.name }}
                            <div v-if="!line.refundable_ok" style="color:var(--text3); font-size:11px;">Venta anterior a las devoluciones: no se puede devolver desde el TPV</div>
                            <div v-else-if="line.refundable === 0" style="color:var(--text3); font-size:11px;">Ya devuelto</div>
                            <div v-else-if="line.refundable < line.sold" style="color:var(--text3); font-size:11px;">Quedan {{ line.refundable }} de {{ line.sold }}</div>
                        </div>
                        <div v-if="line.unitPaid !== null" style="color:var(--text2); font-size:12px;">{{ line.sold }} uds × {{ line.unitPaid.toFixed(2) }} €</div>
                        <template v-if="line.checked">
                            <input type="number" :value="line.refundQty" @input="updateRefundQty(idx, $event.target.value)" min="1" :max="line.refundable" style="width:60px; padding:4px 6px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; text-align:center;" />
                            <label style="display:flex; align-items:center; gap:4px; color:var(--text2); font-size:12px; cursor:pointer;">
                                <input type="checkbox" v-model="line.restock" /> Reponer stock
                            </label>
                        </template>
                    </div>
                </div>

                <div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div style="color:var(--text2); font-size:12px; font-weight:600;">Motivo</div>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        <button v-for="r in reasons" :key="r.code" @click="setReason(r.code)" :style="{ background: reasonCode === r.code ? 'var(--primary)' : 'transparent', color: reasonCode === r.code ? '#fff' : 'var(--text2)' }" style="padding:6px 10px; border-radius:8px; border:1px solid var(--border); cursor:pointer; font-family:inherit; font-size:12px;">{{ r.label }}</button>
                    </div>
                    <input v-if="reasonCode === 'other'" v-model="reasonText" placeholder="Describe el motivo" maxlength="255" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; gap:12px;">
                    <div style="color:var(--text2); font-size:13px;">
                        <div v-if="estimatedSplit().cash > 0">Efectivo de caja: {{ estimatedSplit().cash.toFixed(2) }} €</div>
                        <div v-if="estimatedSplit().card > 0">A tarjeta (datáfono): {{ estimatedSplit().card.toFixed(2) }} €</div>
                    </div>
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Total devolución: {{ estimatedTotal().toFixed(2) }} €</div>
                </div>

                <label v-if="needsCardConfirmation()" style="display:flex; align-items:center; gap:8px; color:var(--text); font-size:13px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer;">
                    <input type="checkbox" v-model="cardConfirmed" style="width:18px; height:18px;" />
                    He hecho la devolución de {{ estimatedSplit().card.toFixed(2) }} € en el datáfono
                </label>

                <button @click="openConfirm" :disabled="loading" style="padding:10px 16px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                    <span v-if="loading">Procesando...</span>
                    <span v-else>Confirmar devolución</span>
                </button>
            </div>

            <div v-if="confirmModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px; max-width:360px; width:90%; display:flex; flex-direction:column; gap:14px;">
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Confirmar devolución</div>
                    <div style="color:var(--text2); font-size:13px;">Se va a devolver aproximadamente <strong>{{ estimatedTotal().toFixed(2) }} €</strong> del pedido <strong>#{{ selectedOrder.ps_order_id }}</strong>. El importe exacto lo calcula el sistema con lo que se pagó.</div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button @click="cancelConfirm" style="padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">Cancelar</button>
                        <button @click="confirmRefund" :disabled="loading" style="padding:8px 14px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Confirmar</button>
                    </div>
                </div>
            </div>
        </div>
    `
};
