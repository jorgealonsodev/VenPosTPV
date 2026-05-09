/**
 * SaleScreen — main 3-zone layout shell with header, catalog, ticket, and action bar.
 */
const SaleScreen = {
    props: ['cashierName', 'storeName'],
    emits: ['cashClose', 'notify'],
    setup(props, { emit }) {
        const { session, toggleTheme, logout } = useSession;
        const { cart, add, remove, updateQty, clear, applyDiscount, setCustomer } = useCart;
        const { catalog, setSearch, setCategory } = useCatalog;

        const showPayment = Vue.ref(false);
        const showDiscount = Vue.ref(false);
        const showCustomer = Vue.ref(false);
        const showCashClose = Vue.ref(false);
        const showRefund = Vue.ref(false);
        const showSettings = Vue.ref(false);
        const showLogoutConfirm = Vue.ref(false);
        const showSyncPanel = Vue.ref(false);
        const showTickets = Vue.ref(false);
        const notification = Vue.ref(null);
        const listView = Vue.ref(false);
        const lastAdded = Vue.ref(null);
        const ticketCount = Vue.ref(127);
        const lastCompletedOrder = Vue.ref(null);

        const timeStr = Vue.ref('');
        function updateTime() {
            const now = new Date();
            timeStr.value = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        }
        updateTime();
        setInterval(updateTime, 30000);

        function notify(msg, color) {
            notification.value = { msg, color };
            setTimeout(() => { notification.value = null; }, 2000);
        }

        function addProduct(p) {
            if (p.stock !== undefined && p.stock <= 0) {
                notify('Sin stock disponible', 'var(--red)');
                return;
            }
            const ok = add(p);
            if (ok) {
                lastAdded.value = p.id;
                setTimeout(() => { lastAdded.value = null; }, 400);
            }
        }

        function handlePaymentComplete(orderData) {
            showPayment.value = false;
            clear();
            ticketCount.value++;
            lastCompletedOrder.value = orderData;
            const reference = orderData && orderData.reference ? orderData.reference : '#' + ticketCount.value;
            notify('Ticket ' + reference + ' completado', 'var(--green)');
        }

        async function handleReprint() {
            if (!lastCompletedOrder.value || !lastCompletedOrder.value.ps_order_id) {
                notify('No hay ticket para reimprimir', 'var(--red)');
                return;
            }
            try {
                const result = await ApiClient.ticketReprint(lastCompletedOrder.value.ps_order_id);
                if (result && result.data && result.data.printed) {
                    notify('Ticket reimpreso', 'var(--green)');
                } else if (result && result.data && result.data.fallback === 'pdf') {
                    ApiClient.ticketPdf(lastCompletedOrder.value.ps_order_id);
                    notify('Descargando ticket PDF', 'var(--blue)');
                } else {
                    notify('Reimpresion completada', 'var(--green)');
                }
            } catch (err) {
                notify('Error al reimprimir: ' + (err.message || 'Error'), 'var(--red)');
            }
        }

        function handlePark() {
            if (!cart.lines.length) return;
            // Save to localStorage as parked ticket
            const parked = JSON.parse(localStorage.getItem('venpostpv_parked') || '[]');
            parked.push({ id: Date.now(), lines: cart.lines, customer: cart.customer, total: cart.total });
            localStorage.setItem('venpostpv_parked', JSON.stringify(parked));
            clear();
            notify('Ticket aparcado', 'var(--amber)');
        }

        function handleVoid() {
            if (!cart.lines.length) return;
            clear();
            notify('Ticket anulado', 'var(--red)');
        }

        function handleDiscount(type, value) {
            applyDiscount(type, value);
            showDiscount.value = false;
            notify('Descuento aplicado', 'var(--amber)');
        }

        function handleCustomer(c) {
            setCustomer(c);
            showCustomer.value = false;
            notify('Cliente: ' + c.name, 'var(--blue)');
        }

        function handleCashClose() {
            showCashClose.value = true;
        }

        function handleCashCloseBack() {
            showCashClose.value = false;
        }

        function handleCashCloseDone(countedCash) {
            showCashClose.value = false;
            emit('cashClose', countedCash);
        }

        function handleRefund() {
            showRefund.value = true;
        }

        function toggleTickets() {
            showTickets.value = !showTickets.value;
        }

        function handleRestoreTicket(ticket) {
            clear();
            useCart.restore(ticket.lines, ticket.customer);
            showTickets.value = false;
            notify('Ticket restaurado', 'var(--green)');
        }

        function handleRefundBack() {
            showRefund.value = false;
        }

        function handleRefundNotify(msg, color) {
            notification.value = { msg, color };
            setTimeout(() => { notification.value = null; }, 2000);
        }

        function handleLogout() {
            if (cart.lines.length > 0) {
                showLogoutConfirm.value = true;
            } else {
                logout();
            }
        }

        function handleLogoutConfirmed() {
            showLogoutConfirm.value = false;
            logout();
        }

        async function handleSyncNow() {
            try {
                await ApiClient.syncNow();
                notify('Sincronización iniciada', 'var(--blue)');
                setTimeout(async () => {
                    try {
                        const response = await ApiClient.syncStatus();
                        if (response && response.state) {
                            session.syncState = response.state;
                            session.syncData = response;
                        }
                    } catch (e) {
                        session.syncState = 'offline';
                    }
                }, 3000);
            } catch (err) {
                notify('Error al iniciar sincronización: ' + (err.message || 'Error'), 'var(--red)');
            }
        }

        const hasOpenSession = Vue.computed(() => {
            return !!(session.value && session.value.sessionId);
        });

        return {
            session, cart, catalog,
            showPayment, showDiscount, showCustomer, showCashClose, showRefund, showSettings,
            showLogoutConfirm, showSyncPanel, showTickets,
            notification, listView, lastAdded, ticketCount, timeStr, lastCompletedOrder,
            hasOpenSession,
            toggleTheme,
            setSearch, setCategory,
            addProduct, remove, updateQty,
            handlePaymentComplete, handlePark, handleVoid, handleReprint,
            handleDiscount, handleCustomer,
            handleCashClose, handleCashCloseDone, handleCashCloseBack,
            handleRefund, handleRefundBack, handleRefundNotify,
            handleLogout, handleLogoutConfirmed,
            handleSyncNow,
            toggleTickets, handleRestoreTicket,
        };
    },
    template: `
        <div v-if="showCashClose">
            <cash-close-screen :session="{ openingCash: session.openingCash || 0, sales: (session.cashTotals && session.cashTotals.sales) || 0, refunds: (session.cashTotals && session.cashTotals.refunds) || 0, expected: (session.cashTotals && session.cashTotals.expected) || null }" @back="handleCashCloseBack" @close="handleCashCloseDone" />
        </div>
        <div v-else-if="showRefund">
            <refund-screen :cashier-name="cashierName" @back="handleRefundBack" @notify="handleRefundNotify" />
        </div>
        <div v-else style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <!-- Header -->
            <header style="height:52px; min-height:52px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <div style="font-weight:700; font-size:15px; letter-spacing:-0.01em; color:var(--text); white-space:nowrap;">{{ storeName || 'VenPosTPV' }}</div>
                <div style="width:1px; height:22px; background:var(--border);"></div>
                <div style="display:flex; align-items:center; gap:7px; color:var(--text2); font-size:13px;">
                    <icon name="user" :size="14" /> {{ (cashierName || 'Cajero').split(' ')[0] }}
                </div>
                <div style="display:flex; align-items:center; gap:6px; color:var(--text3); font-size:12px;">
                    <icon name="receipt" :size="13" />
                    <span>Ticket #{{ ticketCount + 1 }}</span>
                </div>
                <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                    <sync-status-indicator
                        :state="session.syncState"
                        :data="session.syncData"
                        @click="showSyncPanel = true"
                    />
                    <button @click="toggleTheme" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 8px; color:var(--text3); font-size:11px; cursor:pointer; font-family:inherit; display:flex; align-items:center; gap:4px;">
                        <icon :name="session.theme === 'dark' ? 'sun' : 'moon'" :size="13" />
                    </button>
                    <button v-if="session.role === 'admin'" @click="showSettings = true"
                        title="Gestión de empleados"
                        style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 8px; color:var(--text3); font-size:15px; cursor:pointer; font-family:inherit; line-height:1;">
                        ⚙
                    </button>
                    <div style="color:var(--text3); font-size:13px; font-family:'IBM Plex Mono';">{{ timeStr }}</div>
                    <button @click="toggleTickets"
                        style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;"
                        :style="showTickets ? 'background:var(--surface2); border-color:var(--green); color:var(--green);' : ''">
                        <icon name="receipt" :size="13" /> Tickets
                    </button>
                    <button v-if="lastCompletedOrder" @click="handleReprint"
                        style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="receipt" :size="13" /> Reimprimir
                    </button>
                    <button @click="handleRefund" :disabled="!hasOpenSession"
                        style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;"
                        :style="!hasOpenSession ? 'opacity:0.5; cursor:not-allowed;' : ''">
                        <icon name="undo" :size="13" /> Devoluciones
                    </button>
                    <button @click="handleCashClose" style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="cashier" :size="13" /> Cerrar caja
                    </button>
                    <button @click="handleLogout" title="Cambiar usuario / Cerrar sesión"
                        style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text3); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="logout" :size="13" /> Salir
                    </button>
                </div>
            </header>

            <!-- Main -->
            <div style="flex:1; display:flex; overflow:hidden;">
                <!-- Catalog -->
                <div style="width:62%; display:flex; flex-direction:column; overflow:hidden; border-right:1px solid var(--border);">
                    <catalog-panel
                        :catalog="catalog"
                        :list-view="listView"
                        @set-search="setSearch"
                        @set-category="setCategory"
                        @add-product="addProduct"
                        @toggle-view="listView = !listView"
                        :last-added="lastAdded"
                    />
                </div>

                <!-- Ticket -->
                <div style="width:38%; display:flex; flex-direction:column; overflow:hidden; background:var(--surface);">
                    <ticket-list-panel v-if="showTickets"
                        @back="showTickets = false"
                        @notify="notify"
                        @restore="handleRestoreTicket"
                    />
                    <ticket-panel v-else
                        :cart="cart"
                        @remove="remove"
                        @update-qty="updateQty"
                        @show-payment="showPayment = true"
                        @show-discount="showDiscount = true"
                        @show-customer="showCustomer = true"
                        @park="handlePark"
                        @void="handleVoid"
                    />
                </div>
            </div>

            <!-- Payment Modal -->
            <payment-modal v-if="showPayment" :total="cart.total" @complete="handlePaymentComplete" @close="showPayment = false" />

            <!-- Discount Modal -->
            <discount-modal v-if="showDiscount" @apply="handleDiscount" @close="showDiscount = false" />

            <!-- Customer Modal -->
            <customer-modal v-if="showCustomer" @select="handleCustomer" @close="showCustomer = false" />

            <!-- Toast -->
            <div v-if="notification" class="toast" :style="{ border: '1px solid ' + notification.color, color: notification.color }">
                {{ notification.msg }}
            </div>

            <!-- Sync Status Panel -->
            <sync-status-panel :data="session.syncData" :open="showSyncPanel" @close="showSyncPanel = false" @sync-now="handleSyncNow" />

            <!-- Settings Modal -->
            <settings-modal v-if="showSettings" @close="showSettings = false" />

            <!-- Logout Confirmation -->
            <div v-if="showLogoutConfirm" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:28px 32px; max-width:380px; width:90%; text-align:center;">
                    <div style="font-size:18px; font-weight:700; color:var(--text); margin-bottom:10px;">¿Cerrar sesión?</div>
                    <div style="color:var(--text2); font-size:14px; margin-bottom:24px;">
                        Hay líneas en el ticket activo.<br>Al salir se perderán si no se aparcaron antes.
                    </div>
                    <div style="display:flex; gap:12px; justify-content:center;">
                        <button @click="showLogoutConfirm = false"
                            style="padding:8px 22px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px;">
                            Cancelar
                        </button>
                        <button @click="handleLogoutConfirmed"
                            style="padding:8px 22px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                            Salir igualmente
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
