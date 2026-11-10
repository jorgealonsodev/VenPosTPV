/**
 * TicketListPanel — tabbed panel with Aparcados/Cobrados/Devueltos.
 * Emits: back, notify, restore
 */
const TicketListPanel = {
    props: ['activeCartCount'],
    emits: ['back', 'notify', 'restore'],
    setup(props, { emit }) {
        const activeTab = Vue.ref('aparcados');
        const selectedOrder = Vue.ref(null);
        const { state: parkedState, refresh: refreshParked, removeTicket } = useParkedTickets;
        const paidOrders = Vue.ref([]);
        // PRD S6: the ticket code (A26000123) or PrestaShop's reference finds the same sale.
        const paidQuery = Vue.ref('');
        const refundedOrders = Vue.ref([]);
        const loading = Vue.ref(false);
        const confirmDelete = Vue.ref(null);

        const parkedTickets = Vue.computed(() => parkedState.tickets || []);

        async function loadParked() {
            await refreshParked();
        }

        async function loadPaid() {
            loading.value = true;
            try {
                const result = await ApiClient.searchOrders(paidQuery.value.trim().toUpperCase());
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

        function searchPaid() {
            return loadPaid();
        }

        function selectOrder(order) {
            selectedOrder.value = order;
        }

        // A sale not uploaded to PrestaShop yet has no ps_order_id (PRD D1): its
        // local order_id keys it and its ticket code names it.
        function orderKey(order) {
            return order && order.order_id ? 'sale-' + order.order_id : 'ps-' + (order && order.ps_order_id);
        }

        function orderLabel(order) {
            if (!order) return '';
            return order.ps_order_id ? '#' + order.ps_order_id + ' — ' + (order.reference || '') : (order.reference || 'Venta ' + order.order_id);
        }

        function backToList() {
            selectedOrder.value = null;
        }

        function handleRestoreClick(ticket) {
            emit('restore', ticket);
        }

        function askDelete(ticket) {
            confirmDelete.value = ticket;
        }

        async function doDelete() {
            if (!confirmDelete.value) return;
            await removeTicket(confirmDelete.value);
            confirmDelete.value = null;
            emit('notify', 'Ticket aparcado eliminado', 'var(--text2)');
        }

        function cancelDelete() {
            confirmDelete.value = null;
        }

        function formatTicketTime(ticket) {
            if (ticket.created_at) {
                const parsed = new Date(String(ticket.created_at).replace(' ', 'T'));
                if (!Number.isNaN(parsed.getTime())) {
                    return parsed.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
                }
            }
            if (typeof ticket.id === 'number' && ticket.id > 1000000000000) {
                return new Date(ticket.id).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
            }
            return '';
        }

        function formatTicketDate(ticket) {
            if (ticket.created_at) {
                const parsed = new Date(String(ticket.created_at).replace(' ', 'T'));
                if (!Number.isNaN(parsed.getTime())) {
                    return parsed.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
                }
            }
            if (typeof ticket.id === 'number' && ticket.id > 1000000000000) {
                return new Date(ticket.id).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
            }
            return '';
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
            activeTab, selectedOrder, parkedTickets, paidOrders, refundedOrders, paidQuery, searchPaid,
            loading, confirmDelete,
            selectOrder, backToList, handleRestoreClick, askDelete, doDelete, cancelDelete,
            formatTicketTime, formatTicketDate, orderKey, orderLabel, paymentLabel: (code) => VenPosPaymentMethods.label(code),
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
                <span v-if="parkedTickets.length > 0" style="margin-left:auto; font-size:11px; font-weight:600; background:var(--amber-bg); color:var(--amber); padding:2px 8px; border-radius:999px;">
                    {{ parkedTickets.length }} en espera
                </span>
            </div>

            <!-- Tabs -->
            <div v-if="!selectedOrder" style="display:flex; border-bottom:1px solid var(--border); background:var(--surface); flex-shrink:0;">
                <button v-for="tab in ['aparcados','cobrados','devueltos']" :key="tab"
                    @click="activeTab = tab"
                    style="flex:1; padding:10px 6px; border:none; background:transparent; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; text-transform:capitalize; border-bottom:3px solid transparent;"
                    :style="activeTab === tab ? 'color:var(--text); border-bottom-color:var(--green);' : 'color:var(--text3);'">
                    {{ tab }}
                    <span v-if="tab === 'aparcados' && parkedTickets.length > 0" style="margin-left:4px; font-size:11px; color:var(--amber);">({{ parkedTickets.length }})</span>
                </button>
            </div>

            <!-- Content -->
            <div v-if="selectedOrder" style="flex:1; overflow:hidden;">
                <order-detail-view :order="selectedOrder" @back="backToList" @notify="(msg, color) => $emit('notify', msg, color)" />
            </div>
            <div v-else style="flex:1; overflow:auto; padding:12px; display:flex; flex-direction:column; gap:8px;">
                <!-- Aparcados -->
                <template v-if="activeTab === 'aparcados'">
                    <div v-if="parkedTickets.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px;">
                        <icon name="pause" :size="32" />
                        <span style="font-size:13px;">No hay tickets aparcados</span>
                    </div>
                    <div v-for="ticket in parkedTickets" :key="ticket.client_ref || ticket.id"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:6px;">
                        <!-- Header: hora + nota + total -->
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; min-width:0;">
                                <span style="font-weight:600; color:var(--text); font-size:13px;">
                                    {{ formatTicketTime(ticket) }}
                                    <span style="font-weight:400; color:var(--text3); font-size:11px; margin-left:2px;">
                                        {{ formatTicketDate(ticket) }}
                                    </span>
                                </span>
                                <span v-if="ticket.note" style="font-size:11px; font-weight:600; background:var(--amber-bg); color:var(--amber); padding:2px 7px; border-radius:5px;">
                                    {{ ticket.note }}
                                </span>
                            </div>
                            <span style="color:var(--text); font-size:14px; font-weight:700; font-family:'IBM Plex Mono'; flex-shrink:0;">€{{ Number(ticket.total || 0).toFixed(2) }}</span>
                        </div>
                        <!-- Meta: cliente + cajero -->
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; color:var(--text3);">
                            <span>Cliente: <strong style="color:var(--text2); font-weight:500;">{{ ticket.customer && ticket.customer.name ? ticket.customer.name : 'Consumidor final' }}</strong></span>
                            <span v-if="ticket.cashier_name">Cajero: {{ ticket.cashier_name }}</span>
                        </div>
                        <!-- Líneas del ticket -->
                        <div style="border-top:1px solid var(--border); margin-top:4px; padding-top:6px; display:flex; flex-direction:column; gap:3px;">
                            <div v-for="line in ticket.lines" :key="line.id || line.product_id"
                                style="display:flex; justify-content:space-between; align-items:baseline; gap:8px;">
                                <span style="font-size:12px; color:var(--text2); flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <span style="color:var(--text3); margin-right:4px;">×{{ line.qty || line.quantity || 1 }}</span>{{ line.name }}
                                </span>
                                <span style="font-size:12px; color:var(--text3); font-family:'IBM Plex Mono'; flex-shrink:0;">
                                    €{{ Number((line.price || 0) * (line.qty || line.quantity || 1) * (1 - (line.discount || 0) / 100)).toFixed(2) }}
                                </span>
                            </div>
                            <div v-if="ticket.discount && ticket.discount.value > 0" style="font-size:11px; color:var(--amber); display:flex; justify-content:space-between;">
                                <span>Descuento aplicado</span>
                                <span>{{ ticket.discount.type === 'pct' ? ('-' + ticket.discount.value + '%') : ('-€' + Number(ticket.discount.value).toFixed(2)) }}</span>
                            </div>
                        </div>
                        <div style="display:flex; gap:8px; margin-top:4px;">
                            <button @click="handleRestoreClick(ticket)"
                                style="flex:1; padding:7px 10px; border-radius:8px; border:1px solid var(--green); background:transparent; color:var(--green); cursor:pointer; font-family:inherit; font-size:12px; font-weight:600;">
                                {{ activeCartCount && activeCartCount > 0 ? 'Intercambiar con actual' : 'Restaurar' }}
                            </button>
                            <button @click="askDelete(ticket)"
                                title="Eliminar ticket aparcado"
                                style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--red); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; justify-content:center;">
                                <icon name="trash" :size="13" />
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Cobrados -->
                <template v-if="activeTab === 'cobrados'">
                    <form @submit.prevent="searchPaid" style="display:flex; gap:8px;">
                        <input class="tpv-input" style="flex:1; min-width:0;" v-model="paidQuery" placeholder="Código del ticket o referencia web" aria-label="Buscar ticket por código" />
                        <button type="submit" class="tpv-btn" :disabled="loading"><icon name="search" :size="16" /> Buscar</button>
                    </form>
                    <div v-if="loading" style="text-align:center; color:var(--text3); font-size:13px; padding:20px;">Cargando...</div>
                    <div v-else-if="paidOrders.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px;">
                        <icon name="receipt" :size="32" />
                        <span style="font-size:13px;">No hay tickets cobrados</span>
                    </div>
                    <div v-for="order in paidOrders" :key="orderKey(order)" @click="selectOrder(order)"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px; transition:all .1s;"
                        @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background='var(--surface)'">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:600; color:var(--text); font-size:14px;">{{ orderLabel(order) }}</span>
                            <span style="color:var(--text2); font-size:12px; font-family:'IBM Plex Mono';">€{{ Number(order.total || 0).toFixed(2) }}</span>
                        </div>
                        <div style="color:var(--text3); font-size:12px;">
                            <span>{{ order.created_at }}</span>
                            <span v-if="order.payment_method"> — {{ paymentLabel(order.payment_method) }}</span>
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
                    <div v-for="order in refundedOrders" :key="orderKey(order)" @click="selectOrder(order)"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px; transition:all .1s;"
                        @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background='var(--surface)'">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:600; color:var(--text); font-size:14px;">{{ orderLabel(order) }}</span>
                            <span style="color:var(--red); font-size:12px; font-family:'IBM Plex Mono';">Devuelto: €{{ Number(order.refunded_amount || 0).toFixed(2) }}</span>
                        </div>
                        <div style="color:var(--text3); font-size:12px;">
                            <span>{{ order.created_at }}</span>
                            <span v-if="order.payment_method"> — {{ paymentLabel(order.payment_method) }}</span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Delete Confirmation -->
            <div v-if="confirmDelete" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:28px 32px; max-width:380px; width:90%; text-align:center;">
                    <div style="font-size:18px; font-weight:700; color:var(--text); margin-bottom:10px;">Eliminar ticket aparcado</div>
                    <div style="color:var(--text2); font-size:14px; margin-bottom:24px;">
                        ¿Seguro que deseas descartar este ticket en espera?
                    </div>
                    <div style="display:flex; gap:12px; justify-content:center;">
                        <button @click="cancelDelete"
                            style="padding:8px 22px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px;">
                            Cancelar
                        </button>
                        <button @click="doDelete"
                            style="padding:8px 22px; border-radius:8px; border:none; background:var(--danger); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
