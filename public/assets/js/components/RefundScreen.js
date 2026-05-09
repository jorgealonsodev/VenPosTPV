/**
 * RefundScreen — order search, detail, line selection, and refund confirmation.
 */
const RefundScreen = {
    props: ['cashierName'],
    emits: ['back', 'notify'],
    setup(props, { emit }) {
        const { session } = useSession;

        const searchQuery = Vue.ref('');
        const searchResults = Vue.ref([]);
        const selectedOrder = Vue.ref(null);
        const selectedLines = Vue.ref([]);
        const refundReason = Vue.ref('');
        const loading = Vue.ref(false);
        const confirmModal = Vue.ref(false);

        async function handleSearch() {
            if (!searchQuery.value || searchQuery.value.length < 2) {
                emit('notify', 'Introduce al menos 2 caracteres', 'var(--red)');
                return;
            }
            loading.value = true;
            try {
                const result = await ApiClient.searchOrders(searchQuery.value);
                if (result && result.data) {
                    searchResults.value = result.data;
                } else {
                    searchResults.value = [];
                }
            } catch (err) {
                emit('notify', 'Error en búsqueda: ' + (err.message || 'Error'), 'var(--red)');
                searchResults.value = [];
            } finally {
                loading.value = false;
            }
        }

        function selectOrder(order) {
            selectedOrder.value = order;
            selectedLines.value = [];
            if (order.lines && order.lines.length) {
                order.lines.forEach((line, idx) => {
                    selectedLines.value.push({
                        index: idx,
                        id_product: line.product_id || line.id_product || 0,
                        quantity: line.qty || line.quantity || 1,
                        amount: line.line_total || line.amount || 0,
                        name: line.name || 'Producto',
                        checked: false,
                        refundQty: line.qty || line.quantity || 1,
                    });
                });
            }
        }

        function backToSearch() {
            selectedOrder.value = null;
            selectedLines.value = [];
            refundReason.value = '';
            confirmModal.value = false;
        }

        function toggleLine(idx) {
            const line = selectedLines.value[idx];
            if (line) {
                line.checked = !line.checked;
            }
        }

        function updateRefundQty(idx, qty) {
            const line = selectedLines.value[idx];
            if (line) {
                const max = line.quantity;
                const val = Math.max(1, Math.min(max, parseInt(qty, 10) || 1));
                line.refundQty = val;
                line.amount = (line.amount / line.quantity) * val;
            }
        }

        function selectedRefundTotal() {
            let total = 0;
            selectedLines.value.forEach((line) => {
                if (line.checked) {
                    total += line.amount;
                }
            });
            return total.toFixed(2);
        }

        function cashImpactWarning() {
            if (!selectedOrder.value) return '';
            const paymentMethod = selectedOrder.value.payment_method || '';
            if (paymentMethod === 'card') return 'Pago original con tarjeta: sin impacto en caja';
            if (paymentMethod === 'mixed') return 'Pago original mixto: impacto parcial en caja';
            return 'Se devolverá efectivo de la caja';
        }

        function openConfirm() {
            const checked = selectedLines.value.filter((l) => l.checked);
            if (!checked.length) {
                emit('notify', 'Selecciona al menos una línea', 'var(--red)');
                return;
            }
            confirmModal.value = true;
        }

        async function confirmRefund() {
            confirmModal.value = false;
            if (!selectedOrder.value) return;

            const lines = selectedLines.value
                .filter((l) => l.checked)
                .map((l) => ({
                    id_product: l.id_product,
                    quantity: l.refundQty,
                    amount: parseFloat(l.amount.toFixed(2)),
                }));

            loading.value = true;
            try {
                const result = await ApiClient.refundOrder(selectedOrder.value.ps_order_id, lines, refundReason.value);
                if (result && result.data) {
                    emit('notify', 'Devolución procesada (Albarán #' + result.data.order_slip_id + ')', 'var(--green)');
                    backToSearch();
                } else {
                    emit('notify', 'Devolución procesada', 'var(--green)');
                }
            } catch (err) {
                const msg = err.message || 'Error';
                if (msg.includes('409') || msg.includes('already fully refunded')) {
                    emit('notify', 'Pedido ya totalmente devuelto', 'var(--red)');
                } else if (msg.includes('404') || msg.includes('not found')) {
                    emit('notify', 'Pedido no encontrado', 'var(--red)');
                } else {
                    emit('notify', 'Error en devolución: ' + msg, 'var(--red)');
                }
            } finally {
                loading.value = false;
            }
        }

        function cancelConfirm() {
            confirmModal.value = false;
        }

        return {
            session,
            searchQuery,
            searchResults,
            selectedOrder,
            selectedLines,
            refundReason,
            loading,
            confirmModal,
            handleSearch,
            selectOrder,
            backToSearch,
            toggleLine,
            updateRefundQty,
            selectedRefundTotal,
            cashImpactWarning,
            openConfirm,
            confirmRefund,
            cancelConfirm,
        };
    },
    template: `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <!-- Header -->
            <header style="height:52px; min-height:52px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <button @click="$emit('back')" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; gap:4px;">
                    <icon name="arrow_back" :size="14" /> Volver
                </button>
                <div style="font-weight:700; font-size:15px; letter-spacing:-0.01em; color:var(--text);">Devoluciones</div>
                <div v-if="!session.online" style="margin-left:auto; display:flex; align-items:center; gap:5px; color:var(--red); font-size:12px; background:var(--red-bg); padding:4px 10px; border-radius:8px;">
                    <icon name="wifi_off" :size="13" /> Sin conexión
                </div>
            </header>

            <!-- Search or Detail -->
            <div v-if="!selectedOrder" style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; gap:8px;">
                    <input v-model="searchQuery" @keyup.enter="handleSearch" placeholder="Buscar por ID, referencia..." style="flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                    <button @click="handleSearch" :disabled="loading" style="padding:8px 16px; border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                        <span v-if="loading">...</span>
                        <span v-else>Buscar</span>
                    </button>
                </div>
                <div v-if="searchResults.length === 0 && !loading" style="color:var(--text3); font-size:13px; text-align:center; margin-top:40px;">
                    No hay resultados. Introduce un ID o referencia y pulsa Buscar.
                </div>
                <div v-for="order in searchResults" :key="order.ps_order_id" @click="selectOrder(order)" style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; color:var(--text); font-size:14px;">#{{ order.ps_order_id }} — {{ order.reference }}</span>
                        <span style="color:var(--text2); font-size:12px;">{{ order.total.toFixed(2) }} €</span>
                    </div>
                    <div style="color:var(--text3); font-size:12px; display:flex; gap:10px;">
                        <span>{{ order.payment_method }}</span>
                        <span>{{ order.created_at }}</span>
                        <span v-if="order.refunded_amount > 0" style="color:var(--red);">Devuelto: {{ order.refunded_amount.toFixed(2) }} €</span>
                    </div>
                </div>
            </div>

            <!-- Order Detail -->
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
                    <div v-for="(line, idx) in selectedLines" :key="idx" style="display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid var(--border);">
                        <input type="checkbox" :checked="line.checked" @change="toggleLine(idx)" style="width:18px; height:18px; cursor:pointer;" />
                        <div style="flex:1; color:var(--text); font-size:13px;">{{ line.name }}</div>
                        <div style="color:var(--text2); font-size:12px;">{{ line.quantity }} uds × {{ (line.amount / line.quantity).toFixed(2) }} €</div>
                        <input v-if="line.checked" type="number" :value="line.refundQty" @input="updateRefundQty(idx, $event.target.value)" min="1" :max="line.quantity" style="width:60px; padding:4px 6px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; text-align:center;" />
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px;">
                    <div style="color:var(--text2); font-size:13px;">{{ cashImpactWarning() }}</div>
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Total devolución: {{ selectedRefundTotal() }} €</div>
                </div>

                <input v-model="refundReason" placeholder="Motivo de la devolución (opcional)" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />

                <button @click="openConfirm" :disabled="loading" style="padding:10px 16px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                    <span v-if="loading">Procesando...</span>
                    <span v-else>Confirmar devolución</span>
                </button>
            </div>

            <!-- Confirm Modal -->
            <div v-if="confirmModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px; max-width:360px; width:90%; display:flex; flex-direction:column; gap:14px;">
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Confirmar devolución</div>
                    <div style="color:var(--text2); font-size:13px;">Se va a procesar una devolución de <strong>{{ selectedRefundTotal() }} €</strong> para el pedido <strong>#{{ selectedOrder.ps_order_id }}</strong>.</div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button @click="cancelConfirm" style="padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">Cancelar</button>
                        <button @click="confirmRefund" style="padding:8px 14px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Confirmar</button>
                    </div>
                </div>
            </div>
        </div>
    `
};
