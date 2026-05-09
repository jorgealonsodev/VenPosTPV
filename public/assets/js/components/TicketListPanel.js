/**
 * TicketListPanel — tabbed panel with Aparcados/Cobrados/Devueltos.
 * Emits: back, notify, restore
 */
const TicketListPanel = {
    emits: ['back', 'notify', 'restore'],
    setup(props, { emit }) {
        const activeTab = Vue.ref('aparcados');
        const selectedOrder = Vue.ref(null);
        const parkedTickets = Vue.ref([]);
        const paidOrders = Vue.ref([]);
        const refundedOrders = Vue.ref([]);
        const loading = Vue.ref(false);
        const confirmRestore = Vue.ref(null);

        function loadParked() {
            const data = JSON.parse(localStorage.getItem('venpostpv_parked') || '[]');
            parkedTickets.value = data;
        }

        async function loadPaid() {
            loading.value = true;
            try {
                const result = await ApiClient.searchOrders('');
                paidOrders.value = result && result.data ? result.data : [];
            } catch (err) {
                emit('notify', 'Error al cargar cobrados: ' + (err.message || 'Error'), 'var(--red)');
                paidOrders.value = [];
            } finally {
                loading.value = false;
            }
        }

        async function loadRefunded() {
            loading.value = true;
            try {
                const result = await ApiClient.searchOrders('', { refunded: '1' });
                refundedOrders.value = result && result.data ? result.data : [];
            } catch (err) {
                emit('notify', 'Error al cargar devueltos: ' + (err.message || 'Error'), 'var(--red)');
                refundedOrders.value = [];
            } finally {
                loading.value = false;
            }
        }

        function selectOrder(order) {
            selectedOrder.value = order;
        }

        function backToList() {
            selectedOrder.value = null;
        }

        function askRestore(ticket) {
            confirmRestore.value = ticket;
        }

        function doRestore() {
            if (!confirmRestore.value) return;
            emit('restore', confirmRestore.value);
            const data = JSON.parse(localStorage.getItem('venpostpv_parked') || '[]');
            const updated = data.filter(t => t.id !== confirmRestore.value.id);
            localStorage.setItem('venpostpv_parked', JSON.stringify(updated));
            confirmRestore.value = null;
            loadParked();
        }

        function cancelRestore() {
            confirmRestore.value = null;
        }

        Vue.watch(activeTab, (tab) => {
            selectedOrder.value = null;
            if (tab === 'aparcados') loadParked();
            if (tab === 'cobrados') loadPaid();
            if (tab === 'devueltos') loadRefunded();
        });

        Vue.onMounted(() => {
            loadParked();
            if (activeTab.value === 'cobrados') loadPaid();
            if (activeTab.value === 'devueltos') loadRefunded();
        });

        return {
            activeTab, selectedOrder, parkedTickets, paidOrders, refundedOrders,
            loading, confirmRestore,
            selectOrder, backToList, askRestore, doRestore, cancelRestore
        };
    },
    template: `
        <div style="display:flex; flex-direction:column; height:100%; overflow:hidden; background:var(--bg);">
            <!-- Header -->
            <div style="height:52px; min-height:52px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <button @click="$emit('back')"
                    style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; gap:4px;">
                    <icon name="arrow_back" :size="14" /> Volver
                </button>
                <div style="font-weight:700; font-size:15px; letter-spacing:-0.01em; color:var(--text);">Tickets</div>
            </div>

            <!-- Tabs -->
            <div v-if="!selectedOrder" style="display:flex; border-bottom:1px solid var(--border); background:var(--surface); flex-shrink:0;">
                <button v-for="tab in ['aparcados','cobrados','devueltos']" :key="tab"
                    @click="activeTab = tab"
                    style="flex:1; padding:10px 6px; border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; text-transform:capitalize; border-bottom:3px solid transparent;"
                    :style="activeTab === tab ? 'color:var(--text); border-bottom-color:var(--green);' : 'color:var(--text3);'">
                    {{ tab }}
                </button>
            </div>

            <!-- Content -->
            <div v-if="selectedOrder" style="flex:1; overflow:hidden;">
                <order-detail-view :order="selectedOrder" @back="backToList" />
            </div>
            <div v-else style="flex:1; overflow:auto; padding:12px; display:flex; flex-direction:column; gap:8px;">
                <!-- Aparcados -->
                <template v-if="activeTab === 'aparcados'">
                    <div v-if="parkedTickets.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px;">
                        <icon name="pause" :size="32" />
                        <span style="font-size:13px;">No hay tickets aparcados</span>
                    </div>
                    <div v-for="ticket in parkedTickets" :key="ticket.id"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:6px;">
                        <!-- Header: hora + total -->
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:600; color:var(--text); font-size:14px;">
                                {{ new Date(ticket.id).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) }}
                                <span style="font-weight:400; color:var(--text3); font-size:12px; margin-left:4px;">
                                    {{ new Date(ticket.id).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' }) }}
                                </span>
                            </span>
                            <span style="color:var(--text); font-size:14px; font-weight:700; font-family:'IBM Plex Mono';">€{{ Number(ticket.total || 0).toFixed(2) }}</span>
                        </div>
                        <!-- Líneas del ticket -->
                        <div style="border-top:1px solid var(--border); margin-top:4px; padding-top:6px; display:flex; flex-direction:column; gap:3px;">
                            <div v-for="line in ticket.lines" :key="line.id || line.product_id"
                                style="display:flex; justify-content:space-between; align-items:baseline; gap:8px;">
                                <span style="font-size:12px; color:var(--text2); flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <span style="color:var(--text3); margin-right:4px;">×{{ line.qty || line.quantity || 1 }}</span>{{ line.name }}
                                </span>
                                <span style="font-size:12px; color:var(--text3); font-family:'IBM Plex Mono'; flex-shrink:0;">
                                    €{{ Number((line.price || 0) * (line.qty || line.quantity || 1)).toFixed(2) }}
                                </span>
                            </div>
                        </div>
                        <div style="display:flex; gap:8px; margin-top:4px;">
                            <button @click="askRestore(ticket)"
                                style="flex:1; padding:7px 10px; border-radius:8px; border:1px solid var(--green); background:transparent; color:var(--green); cursor:pointer; font-family:inherit; font-size:12px; font-weight:600;">
                                Restaurar
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Cobrados -->
                <template v-if="activeTab === 'cobrados'">
                    <div v-if="loading" style="text-align:center; color:var(--text3); font-size:13px; padding:20px;">Cargando...</div>
                    <div v-else-if="paidOrders.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px;">
                        <icon name="receipt" :size="32" />
                        <span style="font-size:13px;">No hay tickets cobrados</span>
                    </div>
                    <div v-for="order in paidOrders" :key="order.ps_order_id" @click="selectOrder(order)"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px; transition:all .1s;"
                        onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background='var(--surface)'">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:600; color:var(--text); font-size:14px;">#{{ order.ps_order_id }} — {{ order.reference }}</span>
                            <span style="color:var(--text2); font-size:12px; font-family:'IBM Plex Mono';">€{{ Number(order.total || 0).toFixed(2) }}</span>
                        </div>
                        <div style="color:var(--text3); font-size:12px;">
                            <span>{{ order.created_at }}</span>
                            <span v-if="order.payment_method"> — {{ order.payment_method }}</span>
                        </div>
                    </div>
                </template>

                <!-- Devueltos -->
                <template v-if="activeTab === 'devueltos'">
                    <div v-if="loading" style="text-align:center; color:var(--text3); font-size:13px; padding:20px;">Cargando...</div>
                    <div v-else-if="refundedOrders.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px;">
                        <icon name="undo" :size="32" />
                        <span style="font-size:13px;">No hay tickets devueltos</span>
                    </div>
                    <div v-for="order in refundedOrders" :key="order.ps_order_id" @click="selectOrder(order)"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px; transition:all .1s;"
                        onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background='var(--surface)'">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:600; color:var(--text); font-size:14px;">#{{ order.ps_order_id }} — {{ order.reference }}</span>
                            <span style="color:var(--red); font-size:12px; font-family:'IBM Plex Mono';">Devuelto: €{{ Number(order.refunded_amount || 0).toFixed(2) }}</span>
                        </div>
                        <div style="color:var(--text3); font-size:12px;">
                            <span>{{ order.created_at }}</span>
                            <span v-if="order.payment_method"> — {{ order.payment_method }}</span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Restore Confirmation -->
            <div v-if="confirmRestore" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:28px 32px; max-width:380px; width:90%; text-align:center;">
                    <div style="font-size:18px; font-weight:700; color:var(--text); margin-bottom:10px;">Restaurar ticket</div>
                    <div style="color:var(--text2); font-size:14px; margin-bottom:24px;">
                        ¿Reemplazar el ticket actual por el ticket aparcado?
                    </div>
                    <div style="display:flex; gap:12px; justify-content:center;">
                        <button @click="cancelRestore"
                            style="padding:8px 22px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px;">
                            Cancelar
                        </button>
                        <button @click="doRestore"
                            style="padding:8px 22px; border-radius:8px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                            Restaurar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
