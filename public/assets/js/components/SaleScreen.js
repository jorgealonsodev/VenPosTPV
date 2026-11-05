/**
 * SaleScreen — main 3-zone layout shell with header, catalog, ticket, and action bar.
 */
const SaleScreen = {
    props: ['cashierName', 'storeName'],
    emits: ['cashClose', 'notify'],
    setup(props, { emit }) {
        const { session, toggleTheme, logout } = useSession;
        const { cart, add, remove, updateQty, clear, applyDiscount, setCustomer } = useCart;
        const { catalog, setSearch, setCategory, loadMore, setOnlyInStock, setSearchInCategory } = useCatalog;
        const {
            state: parkedState,
            refresh: refreshParked,
            parkCart,
            restoreWithSwap,
            clearAll: clearAllParked,
        } = useParkedTickets;

        const showPayment = Vue.ref(false);
        const showDiscount = Vue.ref(false);
        const showCustomer = Vue.ref(false);
        const showCouponCreation = Vue.ref(false);
        const showCashClose = Vue.ref(false);
        const showRefund = Vue.ref(false);
        const showSettings = Vue.ref(false);
        const showLogoutConfirm = Vue.ref(false);
        const showSyncPanel = Vue.ref(false);
        const showTickets = Vue.ref(false);
        const showIncidents = Vue.ref(false);
        // PRD L2: the "Etiquetas" screen.
        const showLabels = Vue.ref(false);
        // Local stock of each added product/combination ("<product>:<combination>"), so
        // the ticket marks lines the TPV has no stock for (PRD V4: warn, never block).
        const stockByKey = Vue.reactive({});
        const stockNotice = Vue.ref('');
        const showParkModal = Vue.ref(false);
        const parkNote = Vue.ref('');
        const showVoidConfirm = Vue.ref(false);
        const notification = Vue.ref(null);
        const listView = Vue.ref(false);
        const lastAdded = Vue.ref(null);
        const ticketCount = Vue.ref(127);
        const lastCompletedOrder = Vue.ref(null);

        const parkedCount = Vue.computed(() => (parkedState.tickets ? parkedState.tickets.length : 0));

        const timeStr = Vue.ref('');
        function updateTime() {
            const now = new Date();
            timeStr.value = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        }
        updateTime();
        setInterval(updateTime, 30000);

        Vue.onMounted(() => {
            refreshParked();
            // The cart is restored from localStorage with the prices captured when
            // each line was added; re-price them so a stale snapshot never reaches
            // the checkout, which validates against the current catalog.
            if (typeof useCart.reprice === 'function') {
                useCart.reprice().catch(() => {
                    // A failed re-price keeps the stored prices; the checkout still validates.
                });
            }
        });

        function notify(msg, color) {
            notification.value = { msg, color };
            setTimeout(() => { notification.value = null; }, 2000);
        }

        function addProduct(p) {
            if (p.stock !== undefined && p.stock !== null && p.stock <= 0) {
                notify('Sin stock en el TPV: se vende igualmente', 'var(--orange)');
            }
            const combinationId = p.selected_combination_id || 0;
            const combination = combinationId && Array.isArray(p.combinations)
                ? p.combinations.find((c) => c && c.id === combinationId)
                : null;
            const stock = combination && combination.stock !== undefined ? combination.stock : p.stock;
            if (stock !== undefined && stock !== null) {
                stockByKey[p.id + ':' + combinationId] = Number(stock);
            }
            const ok = add(p);
            if (ok) {
                lastAdded.value = p.id;
                setTimeout(() => { lastAdded.value = null; }, 400);
            }
        }

        /** PRD V4: the products of the sale the TPV had no stock for, named for the cashier. */
        function stockWarningText(warnings, lines) {
            if (!Array.isArray(warnings) || warnings.length === 0) return '';
            const names = warnings.map((w) => {
                const line = Array.isArray(lines) ? lines[w.line_index] : null;
                return line && line.name ? line.name : 'producto ' + String(w.product_id);
            });
            return 'Sin stock en el TPV: ' + names.join(', ') + ' — revisa el inventario';
        }

        function handlePaymentComplete(orderData) {
            showPayment.value = false;
            // Named before the cart is cleared: line_index points at its lines.
            stockNotice.value = stockWarningText(orderData && orderData.stock_warnings, cart.lines);
            clear();
            ticketCount.value++;
            lastCompletedOrder.value = orderData;
            const reference = orderData && orderData.reference ? orderData.reference : '#' + ticketCount.value;
            notify('Ticket ' + reference + ' completado', 'var(--green)');
        }

        async function handleReprint() {
            const order = lastCompletedOrder.value;
            // order_id: the local sale, printable before it reaches PrestaShop.
            const saleId = order && order.order_id ? order.order_id : null;
            const psOrderId = order && order.ps_order_id ? order.ps_order_id : null;
            if (!saleId && !psOrderId) {
                notify('No hay ticket para reimprimir', 'var(--red)');
                return;
            }
            try {
                const result = saleId ? await ApiClient.ticketReprintSale(saleId) : await ApiClient.ticketReprint(psOrderId);
                if (result && result.data && result.data.print_status === 'queued' && typeof usePrintJobs !== 'undefined') {
                    // Print agent: followed until sent; a problem stays on this screen (C1, C2).
                    usePrintJobs.trackResponse(result.data, { kind: 'reprint', saleId, orderId: psOrderId });
                    notify('Imprimiendo ticket…', 'var(--blue)');
                } else if (result && result.data && result.data.printed) {
                    notify('Ticket reimpreso', 'var(--green)');
                } else if (result && result.data && result.data.fallback === 'pdf') {
                    if (saleId) {
                        ApiClient.ticketSalePdf(saleId);
                    } else {
                        ApiClient.ticketPdf(psOrderId);
                    }
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
            parkNote.value = '';
            showParkModal.value = true;
        }

        async function confirmParkTicket() {
            if (!cart.lines.length) {
                showParkModal.value = false;
                return;
            }
            const noteText = parkNote.value.trim();
            showParkModal.value = false;
            await parkCart(cart, noteText, props.cashierName || 'Cajero');
            clear();
            notify(noteText ? ('Ticket aparcado: ' + noteText) : 'Ticket aparcado', 'var(--amber)');
        }

        function handleVoid() {
            if (!cart.lines.length) return;
            showVoidConfirm.value = true;
        }

        function confirmVoid() {
            showVoidConfirm.value = false;
            if (!cart.lines.length) return;
            clear();
            notify('Ticket anulado', 'var(--red)');
        }

        function cancelVoid() {
            showVoidConfirm.value = false;
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

        async function handleCashClose() {
            await Promise.all([refreshParked(), useSession.refreshCashTotals(), useSession.refreshOutbox()]);
            showCashClose.value = true;
        }

        function handleCashCloseBack() {
            showCashClose.value = false;
        }

        function handleCashCloseDone(countedCash) {
            showCashClose.value = false;
            emit('cashClose', countedCash);
        }

        async function handleDiscardParkedOnClose() {
            await clearAllParked();
            notify('Tickets aparcados descartados', 'var(--amber)');
        }

        function handleRefund() {
            showRefund.value = true;
        }

        function toggleTickets() {
            showTickets.value = !showTickets.value;
            if (showTickets.value) {
                refreshParked();
            }
        }

        async function handleRestoreTicket(ticket) {
            const result = await restoreWithSwap(ticket, useCart, props.cashierName || 'Cajero');
            showTickets.value = false;
            if (result.swapped) {
                notify('Ticket restaurado (el anterior quedó aparcado)', 'var(--green)');
            } else {
                notify('Ticket restaurado', 'var(--green)');
            }
        }

        function refreshOutbox() {
            useSession.refreshOutbox();
        }

        function openIncidents() {
            showSyncPanel.value = false;
            showIncidents.value = true;
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

        const syncingNow = Vue.ref(false);

        /** Manual sync: uploads pending sales/refunds now and refreshes the catalog. */
        async function handleSyncNow() {
            if (syncingNow.value) return;
            syncingNow.value = true;
            try {
                const response = await ApiClient.syncNow(true);
                const data = (response && response.data) || {};
                const uploaded = data.outbox ? data.outbox.uploaded : 0;
                if (data.catalog_error) {
                    notify('Sin conexión con PrestaShop: las ventas pendientes se subirán solas', 'var(--orange)');
                } else {
                    notify(uploaded > 0 ? 'Sincronizado: ' + uploaded + ' operaciones subidas' : 'Sincronizado', 'var(--primary)');
                }
                useSession.refreshOutbox();
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
                notify('Error al sincronizar: ' + (err.message || 'Error'), 'var(--red)');
            } finally {
                syncingNow.value = false;
            }
        }

        // docs/prd/agente-impresion.md C4: permanent notice while the till's agent is offline.
        const printAgentNotice = Vue.computed(() => (typeof useSession.printAgentNotice === 'function' ? useSession.printAgentNotice() : ''));
        const hasPrintNotice = typeof usePrintJobs !== 'undefined';

        const hasOpenSession = Vue.computed(() => {
            // session is a reactive object (not a ref): read its sessionId directly.
            return !!(session && session.sessionId);
        });

        return {
            session, cart, catalog,
            showPayment, showDiscount, showCustomer, showCouponCreation, showCashClose, showRefund, showSettings,
            showLogoutConfirm, showSyncPanel, showTickets, showParkModal, parkNote, showVoidConfirm,
            showIncidents, openIncidents, refreshOutbox, stockByKey, stockNotice, showLabels,
            parkedCount,
            notification, listView, lastAdded, ticketCount, timeStr, lastCompletedOrder,
            hasOpenSession, printAgentNotice, hasPrintNotice,
            toggleTheme,
            setSearch, setCategory, loadMore, setOnlyInStock, setSearchInCategory,
            addProduct, remove, updateQty,
            handlePaymentComplete, handlePark, confirmParkTicket, handleVoid, confirmVoid, cancelVoid, handleReprint,
            handleDiscount, handleCustomer,
            handleCashClose, handleCashCloseDone, handleCashCloseBack, handleDiscardParkedOnClose,
            handleRefund, handleRefundBack, handleRefundNotify,
            handleLogout, handleLogoutConfirmed,
            handleSyncNow, syncingNow,
            toggleTickets, handleRestoreTicket,
        };
    },
    template: `
        <div v-if="showCashClose">
            <cash-close-screen
                :session="{ openingCash: session.openingCash || 0, sales: (session.cashTotals && session.cashTotals.sales) || 0, salesCard: (session.cashTotals && session.cashTotals.salesCard) || 0, refunds: (session.cashTotals && session.cashTotals.refunds) || 0, refundsCard: (session.cashTotals && session.cashTotals.refundsCard) || 0, net: (session.cashTotals && session.cashTotals.net) || 0, expected: (session.cashTotals && session.cashTotals.expected) || null }"
                :parked-count="parkedCount"
                :outbox="session.outbox"
                @back="handleCashCloseBack"
                @close="handleCashCloseDone"
                @discard-parked="handleDiscardParkedOnClose"
            />
        </div>
        <div v-else-if="showIncidents && session.role === 'admin'" style="width:100%; height:100%;">
            <incidents-screen @back="showIncidents = false" @changed="refreshOutbox" />
        </div>
        <div v-else-if="showLabels" style="width:100%; height:100%;">
            <labels-screen @back="showLabels = false" @notify="handleRefundNotify" />
        </div>
        <div v-else-if="showRefund" style="width:100%; height:100%;">
            <refund-screen :cashier-name="cashierName" @back="handleRefundBack" @notify="handleRefundNotify" />
        </div>
        <div v-else style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <!-- Header -->
            <header style="height:60px; min-height:60px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <div style="font-weight:700; font-size:15px; letter-spacing:-0.01em; color:var(--text); white-space:nowrap;">{{ storeName || 'VenPosTPV' }}</div>
                <div style="width:1px; height:22px; background:var(--border);"></div>
                <div style="display:flex; align-items:center; gap:7px; color:var(--text2); font-size:13px;">
                    <icon name="user" :size="14" /> {{ (cashierName || 'Cajero').split(' ')[0] }}
                </div>
                <div style="display:flex; align-items:center; gap:6px; color:var(--text3); font-size:12px;">
                    <icon name="receipt" :size="13" />
                    <span>Ticket #{{ ticketCount + 1 }}</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px; color:var(--text3); font-size:13px; font-family:'IBM Plex Mono';">
                    {{ timeStr }}
                </div>
                <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                    <sync-status-indicator
                        :state="session.syncState"
                        :data="session.syncData"
                        :outbox="session.outbox"
                        @click="showSyncPanel = true"
                    />
                    <button @click="handleSyncNow" :disabled="syncingNow" title="Sincronizar ahora con PrestaShop" aria-label="Sincronizar ahora"
                        :class="{ 'tpv-spin': syncingNow }"
                        style="min-height:44px; min-width:44px; background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 8px; color:var(--text2); cursor:pointer; display:flex; align-items:center; justify-content:center;">
                        <icon name="refresh" :size="16" />
                    </button>
                    <button @click="toggleTheme" style="min-height:44px; background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 8px; color:var(--text3); font-size:11px; cursor:pointer; font-family:inherit; display:flex; align-items:center; gap:4px;">
                        <icon :name="session.theme === 'dark' ? 'sun' : 'moon'" :size="13" />
                    </button>
                    <button v-if="session.role === 'admin' || session.role === 'cashier'" @click="showCouponCreation = true"
                        title="Crear cupón"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="coupon" :size="13" /> Crear cupón
                    </button>
                    <button v-if="session.role === 'admin'" @click="showSettings = true"
                        title="Ajustes: tienda, cajas, empleados y sincronización"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <span aria-hidden="true" style="font-size:15px; line-height:1;">⚙</span> Ajustes
                    </button>
                    <button @click="toggleTickets"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;"
                        :style="showTickets ? 'background:var(--surface2); border-color:var(--green); color:var(--green);' : ''">
                        <icon name="receipt" :size="13" /> Tickets
                        <span v-if="parkedCount > 0"
                            style="background:var(--amber); color:#000; font-weight:700; font-size:10px; padding:1px 6px; border-radius:999px; font-family:'IBM Plex Mono';">
                            {{ parkedCount }}
                        </span>
                    </button>
                    <button v-if="lastCompletedOrder" @click="handleReprint"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="receipt" :size="13" /> Reimprimir
                    </button>
                    <button @click="showLabels = true" title="Imprimir etiquetas de precio"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="tag" :size="13" /> Etiquetas
                    </button>
                    <button @click="handleRefund" :disabled="!hasOpenSession"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;"
                        :style="!hasOpenSession ? 'opacity:0.5; cursor:not-allowed;' : ''">
                        <icon name="undo" :size="13" /> Devoluciones
                    </button>
                    <button @click="handleCashClose" style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="cashier" :size="13" /> Cerrar caja
                    </button>
                    <button @click="handleLogout" title="Cambiar usuario / Cerrar sesión"
                        style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text3); cursor:pointer; font-family:inherit; font-size:12px;">
                        <icon name="logout" :size="13" /> Salir
                    </button>
                </div>
            </header>
            <label-pending-prompt :terminal-id="session.terminalId" @notify="handleRefundNotify" />

            <div v-if="printAgentNotice" class="tpv-banner tpv-banner-error print-agent-offline" role="alert">
                <icon name="print" :size="18" />
                <span class="print-notice-error">{{ printAgentNotice }}</span>
            </div>

            <!-- Main -->
            <div style="flex:1; display:flex; overflow:hidden;">
                <!-- Catalog -->
                <div style="width:62%; display:flex; flex-direction:column; overflow:hidden; border-right:1px solid var(--border);">
                    <catalog-panel
                        :catalog="catalog"
                        :list-view="listView"
                        @set-search="setSearch"
                        @set-category="setCategory"
                        @load-more="loadMore"
                        @set-only-in-stock="setOnlyInStock"
                        @set-search-in-category="setSearchInCategory"
                        @add-product="addProduct"
                        @notify="handleRefundNotify"
                        @toggle-view="listView = !listView"
                        :last-added="lastAdded"
                    />
                </div>

                <!-- Ticket -->
                <div style="width:38%; display:flex; flex-direction:column; overflow:hidden; background:var(--surface);">
                    <ticket-list-panel v-if="showTickets"
                        :active-cart-count="cart.lines.length"
                        @back="showTickets = false"
                        @notify="notify"
                        @restore="handleRestoreTicket"
                    />
                    <ticket-panel v-else
                        :cart="cart"
                        :stock-by-key="stockByKey"
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

            <!-- Park Ticket Optional Note Modal -->
            <div v-if="showParkModal" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:24px 28px; max-width:400px; width:92%;">
                    <div style="font-size:17px; font-weight:700; color:var(--text); margin-bottom:6px;">Aparcar ticket</div>
                    <div style="color:var(--text3); font-size:13px; margin-bottom:14px;">
                        Añade una nota opcional para identificarlo rápido (ej. "Probador 2", "Señora abrigo gris").
                    </div>
                    <input
                        type="text"
                        maxlength="120"
                        v-model="parkNote"
                        @keyup.enter="confirmParkTicket"
                        placeholder="Nota opcional (pulsa Enter para aparcar)"
                        style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface2); color:var(--text); font-family:inherit; font-size:13px; outline:none; margin-bottom:18px;"
                    />
                    <div style="display:flex; gap:10px; justify-content:flex-end;">
                        <button @click="showParkModal = false"
                            style="padding:8px 16px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">
                            Cancelar
                        </button>
                        <button @click="confirmParkTicket"
                            style="padding:8px 18px; border-radius:8px; border:none; background:var(--amber); color:#000; cursor:pointer; font-family:inherit; font-size:13px; font-weight:700;">
                            Aparcar ahora
                        </button>
                    </div>
                </div>
            </div>

            <!-- Void Ticket Confirmation -->
            <div v-if="showVoidConfirm" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:26px 30px; max-width:400px; width:92%; text-align:center;">
                    <div style="font-size:18px; font-weight:700; color:var(--text); margin-bottom:10px;">¿Anular el ticket?</div>
                    <div style="color:var(--text2); font-size:14px; margin-bottom:22px;">
                        Se eliminarán las {{ cart.lines.length }} línea{{ cart.lines.length !== 1 ? 's' : '' }} del ticket actual.
                        Si solo quieres dejarlo para después, usa <strong>Aparcar</strong>.
                    </div>
                    <div style="display:flex; gap:12px; justify-content:center;">
                        <button @click="cancelVoid"
                            style="padding:9px 22px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px;">
                            Cancelar
                        </button>
                        <button @click="confirmVoid"
                            style="padding:9px 22px; border-radius:8px; border:none; background:var(--danger); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                            Anular ticket
                        </button>
                    </div>
                </div>
            </div>

            <!-- Payment Modal -->
            <payment-modal v-if="showPayment" :total="cart.total" @complete="handlePaymentComplete" @close="showPayment = false" />

            <!-- Discount Modal -->
            <discount-modal v-if="showDiscount" @apply="handleDiscount" @close="showDiscount = false" />

            <!-- Customer Modal -->
            <customer-modal v-if="showCustomer" @select="handleCustomer" @close="showCustomer = false" />

            <!-- Coupon Creation Modal -->
            <coupon-creation-modal v-if="showCouponCreation" @close="showCouponCreation = false" />

            <!-- Toast -->
            <div v-if="notification" class="toast" :style="{ border: '1px solid ' + notification.color, color: notification.color }">
                {{ notification.msg }}
            </div>

            <!-- Sync Status Panel -->
            <sync-status-panel :data="session.syncData" :outbox="session.outbox" :is-admin="session.role === 'admin'" :open="showSyncPanel" @close="showSyncPanel = false" @sync-now="handleSyncNow" @show-incidents="openIncidents" />

            <!-- PRD V4: sold without local stock; a notice, nothing is blocked -->
            <div v-if="stockNotice" class="tpv-banner tpv-banner-warn" role="status"
                style="position:fixed; left:16px; bottom:16px; max-width:520px; z-index:950; box-shadow:0 8px 24px rgba(0,0,0,0.3);">
                <span style="flex:1; min-width:0; font-weight:600; color:var(--amber);">{{ stockNotice }}</span>
                <button class="tpv-btn" @click="stockNotice = ''">Entendido</button>
            </div>

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
                            style="padding:8px 22px; border-radius:8px; border:none; background:var(--danger); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                            Salir igualmente
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- docs/prd/agente-impresion.md C2, C3: print agent problems, also over the refund screen -->
        <print-status-notice v-if="hasPrintNotice" placement="sale" />
    `
};
