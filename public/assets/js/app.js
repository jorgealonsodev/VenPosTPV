/**
 * VenPosTPV Vue 3 App Entry
 * Screen routing: login → cashOpen → sale
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
            try {
                await closeCash(countedCash);
            } catch (err) {
                window.alert(err.message || 'No se pudo cerrar caja');
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
        <div style="width:100vw; height:100vh; overflow:hidden; position:relative;">
            <login-screen v-if="session.screen === 'login'" :store-name="session.storeName" @login="onLogin" />
            <cash-open-screen v-else-if="session.screen === 'cashOpen'" :cashier-name="session.cashierName" @open="onOpenCash" />
            <sale-screen v-else-if="session.screen === 'sale'" :cashier-name="session.cashierName" :store-name="session.storeName" @cash-close="onCashClose" />
        </div>
    `
});

app.component('icon', Icon);
app.component('numpad', Numpad);
app.component('login-screen', LoginScreen);
app.component('cash-open-screen', CashOpenScreen);
app.component('sale-screen', SaleScreen);
app.component('catalog-panel', CatalogPanel);
app.component('combinations-modal', CombinationsModal);
app.component('ticket-panel', TicketPanel);
app.component('payment-modal', PaymentModal);
app.component('discount-modal', DiscountModal);
app.component('customer-modal', CustomerModal);
app.component('coupon-creation-modal', CouponCreationModal);
app.component('cash-close-screen', CashCloseScreen);
app.component('refund-screen', RefundScreen);
app.component('settings-modal', SettingsModal);
app.component('sync-status-indicator', SyncStatusIndicator);
app.component('sync-status-panel', SyncStatusPanel);
app.component('ticket-list-panel', TicketListPanel);
app.component('order-detail-view', OrderDetailView);

app.mount('#app');
