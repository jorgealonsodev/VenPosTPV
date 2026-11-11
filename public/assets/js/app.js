/**
 * VenPosTPV Vue 3 App Entry
 * Screen routing: login → (deviceLink when this computer has no till) → cashOpen → sale
 */
const { createApp, ref, computed, watch, reactive } = Vue;

const app = createApp({
    setup() {
        const { session, login, openCash, closeCash } = useSession;

        function onLogin(user) {
            login(user, '****');
        }

        async function onOpenCash(amount) {
            try {
                await openCash(amount);
            } catch (err) {
                window.alert(err.message || 'No se pudo abrir caja');
            }
        }

        async function onCashClose(countedCash) {
            let result;
            try {
                result = await closeCash(countedCash);
            } catch (err) {
                window.alert(err.message || 'No se pudo cerrar caja');
                return;
            }
            // The till is closed either way; only the printout of its Z failed.
            if (result && result.z_print === 'failed') {
                window.alert('La caja se ha cerrado, pero no se ha podido imprimir el informe Z. Un administrador puede imprimirlo o descargarlo en Ajustes → Cierres de caja.');
            }
        }

        return {
            session,
            onLogin,
            onOpenCash,
            onCashClose,
        };
    },
    template: `
        <div class="app-root">
            <login-screen v-if="session.screen === 'login'" :store-name="session.storeName" @login="onLogin" />
            <device-link-screen v-else-if="session.screen === 'deviceLink'" />
            <cash-open-screen v-else-if="session.screen === 'cashOpen'" :cashier-name="session.cashierName" @open="onOpenCash" />
            <div v-else-if="session.screen === 'checking'" class="app-checking" role="status" aria-live="polite">Comprobando caja…</div>
            <!-- A problem printing the Z of the close just made (print agent) stays visible here. -->
            <print-status-notice v-if="session.screen === 'cashOpen'" placement="sale" />
            <sale-screen v-else-if="session.screen === 'sale'" :cashier-name="session.cashierName" :store-name="session.storeName" @cash-close="onCashClose" />
        </div>
    `
});

app.component('icon', Icon);
app.component('numpad', Numpad);
app.component('login-screen', LoginScreen);
app.component('device-link-screen', DeviceLinkScreen);
app.component('cash-open-screen', CashOpenScreen);
app.component('sale-screen', SaleScreen);
app.component('catalog-panel', CatalogPanel);
app.component('combinations-modal', CombinationsModal);
app.component('ticket-panel', TicketPanel);
app.component('payment-modal', PaymentModal);
app.component('discount-modal', DiscountModal);
app.component('customer-modal', CustomerModal);
app.component('coupon-creation-modal', CouponCreationModal);
app.component('coupon-list-modal', CouponListModal);
app.component('cash-close-screen', CashCloseScreen);
app.component('refund-screen', RefundScreen);
app.component('incidents-screen', IncidentsScreen);
app.component('reports-screen', ReportsScreen);
app.component('settings-modal', SettingsModal);
app.component('sync-status-indicator', SyncStatusIndicator);
app.component('sync-status-panel', SyncStatusPanel);
app.component('ticket-list-panel', TicketListPanel);
app.component('order-detail-view', OrderDetailView);
app.component('invoice-modal', InvoiceModal);
app.component('print-status-notice', PrintStatusNotice);
app.component('print-agent-panel', PrintAgentPanel);
app.component('print-history-panel', PrintHistoryPanel);
app.component('label-print-modal', LabelPrintModal);
app.component('labels-screen', LabelsScreen);
app.component('label-pending-prompt', LabelPendingPrompt);

app.mount('#app');
