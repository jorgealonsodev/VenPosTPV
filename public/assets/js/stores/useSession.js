/**
 * Session store — reactive session state with localStorage persistence.
 */
const useSession = (function () {
    const STORAGE_KEY = 'venpostpv_session';
    const session = Vue.reactive({
        user: null,
        cashierName: 'Cajero',
        role: 'cashier',
        sessionId: null,
        terminalId: null,
        openingCash: 0,
        cashOpenedAt: null,
        cashTotals: null,
        online: navigator.onLine !== false,
        screen: 'login',
        storeName: 'VenPosTPV',
        theme: 'dark',
        syncState: 'unknown',
        syncData: null,
        dataphoneMode: 'integrated',
        printerType: 'pdf',
        stockSyncIntervalSeconds: 60,
        stockPushIntervalSeconds: 0,
    });

    function load() {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            try {
                const data = JSON.parse(raw);
                if (data.cashierName) session.cashierName = data.cashierName;
                if (data.storeName) session.storeName = data.storeName;
                if (data.theme) session.theme = data.theme;
                if (data.screen) session.screen = data.screen;
            } catch (e) {
                // ignore
            }
        }
        // Inject server session if available
        if (window.__SESSION__) {
            const s = window.__SESSION__;
            if (s.user_id !== undefined && s.user_id !== null) {
                session.user = { id: s.user_id };
                session.role = s.role || 'cashier';
                session.sessionId = s.cash_session_id || null;
                session.terminalId = s.terminal_id || null;
                session.cashOpenedAt = s.cash_opened_at || null;
                session.openingCash = Number(s.opening_cash || 0);
            }
            if (s.cashier_name) session.cashierName = s.cashier_name;
            if (s.store_name) session.storeName = s.store_name;
            if (s.dataphone_mode) session.dataphoneMode = s.dataphone_mode;
            if (s.printer_type) session.printerType = s.printer_type;
            if (s.stock_sync_interval_seconds !== undefined) {
                session.stockSyncIntervalSeconds = Number(s.stock_sync_interval_seconds);
            }
            if (s.stock_push_interval_seconds !== undefined) {
                session.stockPushIntervalSeconds = Number(s.stock_push_interval_seconds);
            }
        }
        applyTheme();
    }

    function save() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            cashierName: session.cashierName,
            storeName: session.storeName,
            theme: session.theme,
            screen: session.screen,
        }));
    }

    function applyTheme() {
        document.documentElement.setAttribute('data-theme', session.theme);
    }

    function login(user, pin) {
        session.user = { name: user, pin };
        session.cashierName = user === 'Maria' ? 'María García'
            : user === 'Carlos' ? 'Carlos Ruiz'
            : user === 'Pablo' ? 'Pablo Torres'
            : user;
        session.screen = 'cashOpen';
        save();
    }

    async function hydrateCashSession() {
        try {
            const response = await ApiClient.cashCurrent();
            const data = response && response.data ? response.data : { open: false };
            if (!data.open) {
                session.sessionId = null;
                // Preserve terminalId from window.__SESSION__ — SettingsModal needs it
                // even when no cash session is open.
                session.cashOpenedAt = null;
                session.cashTotals = null;
                // Don't downgrade screen if we already confirmed the session is open
                if (session.screen !== 'sale') {
                    session.screen = session.user ? 'cashOpen' : 'login';
                }
                return;
            }

            session.sessionId = data.session_id;
            session.terminalId = data.terminal_id;
            session.cashOpenedAt = data.opened_at;
            session.openingCash = Number(data.opening_cash || 0);
            session.cashTotals = {
                sales: Number(data.sales_cash || 0),
                refunds: Number(data.refunds_cash || 0),
                cashIn: Number(data.cash_in_total || 0),
                cashOut: Number(data.cash_out_total || 0),
                expected: Number(data.expected_cash || 0),
            };
            session.screen = 'sale';
            startSyncPolling();
            startStockSyncTimer();
            startStockPushTimer();
        } catch (e) {
            // Don't downgrade screen if we already reached 'sale'
            if (session.screen !== 'sale') {
                session.screen = session.user ? 'cashOpen' : 'login';
            }
        }
    }

    let syncPollTimer = null;
    let syncPollInterval = 10000;
    let pollingStarted = false;
    let stockSyncTimer = null;
    let stockPushTimer = null;

    function startSyncPolling() {
        if (pollingStarted) return;
        if (syncPollTimer) {
            clearTimeout(syncPollTimer);
        }
        pollingStarted = true;
        syncPollInterval = 10000;
        doPoll();
    }

    async function doPoll() {
        try {
            const response = await ApiClient.syncStatus();
            if (response && response.state) {
                session.syncState = response.state;
                session.syncData = response;
                syncPollInterval = 10000;
            }
        } catch (e) {
            session.syncState = 'offline';
            syncPollInterval = Math.min(syncPollInterval * 2, 60000);
        }

        syncPollTimer = setTimeout(doPoll, syncPollInterval);
    }

    function startStockSyncTimer() {
        stopStockSyncTimer();
        const seconds = Number(session.stockSyncIntervalSeconds || 0);
        if (seconds <= 0) return;
        // Sync immediately on start, then every N seconds
        ApiClient.syncNow().catch(() => {});
        stockSyncTimer = setInterval(() => {
            if (!session.user || !session.online) return;
            ApiClient.syncNow().catch(() => {});
        }, seconds * 1000);
    }

    function stopStockSyncTimer() {
        if (stockSyncTimer) {
            clearInterval(stockSyncTimer);
            stockSyncTimer = null;
        }
    }

    function startStockPushTimer() {
        stopStockPushTimer();
        const seconds = Number(session.stockPushIntervalSeconds || 0);
        if (seconds <= 0) return;
        stockPushTimer = setInterval(() => {
            if (!session.user || !session.online) return;
            ApiClient.post('/api/sync/push-stock', {}).catch(() => {});
        }, seconds * 1000);
    }

    function stopStockPushTimer() {
        if (stockPushTimer) {
            clearInterval(stockPushTimer);
            stockPushTimer = null;
        }
    }

    async function openCash(amount) {
        try {
            const response = await ApiClient.cashOpen(amount);
            const data = response.data;
            session.sessionId = data.session_id;
            session.terminalId = data.terminal_id;
            session.cashOpenedAt = data.opened_at;
            session.openingCash = Number(data.opening_cash || 0);
        } catch (err) {
            // 409 means a session is already open for this terminal — recover gracefully.
            // We call hydrateCashSession() to sync state from server, but we ALWAYS
            // force screen='sale' afterwards because the server confirmed the session is open.
            if (err && err.message && err.message.startsWith('HTTP 409')) {
                try {
                    await hydrateCashSession();
                } catch (hydrateErr) {
                    // hydration failed — but we still know the session is open
                }
                // Force sale screen regardless of hydration outcome
                session.screen = 'sale';
                startSyncPolling();
                save();
                return;
            }
            throw err;
        }
        await hydrateCashSession();
        save();
    }

    async function closeCash(countedCash) {
        await ApiClient.cashClose(countedCash);
        session.screen = 'cashOpen';
        session.sessionId = null;
        session.terminalId = null;
        session.openingCash = 0;
        session.cashTotals = null;
        save();
    }

    function toggleTheme() {
        session.theme = session.theme === 'dark' ? 'light' : 'dark';
        applyTheme();
        save();
    }

    async function logout() {
        try {
            await ApiClient.authLogout();
        } catch (e) {
            // proceed even if request fails (e.g. offline)
        }
        // Stop sync polling
        if (syncPollTimer) {
            clearTimeout(syncPollTimer);
            syncPollTimer = null;
        }
        pollingStarted = false;
        stopStockSyncTimer();
        stopStockPushTimer();
        // Clear session state
        session.user = null;
        session.cashierName = 'Cajero';
        session.role = 'cashier';
        session.sessionId = null;
        session.terminalId = null;
        session.openingCash = 0;
        session.cashOpenedAt = null;
        session.cashTotals = null;
        session.screen = 'login';
        session.syncState = 'unknown';
        session.syncData = null;
        // Remove screen from localStorage so it does not rehydrate
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                const data = JSON.parse(raw);
                delete data.screen;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            }
        } catch (e) { /* ignore */ }
        // Navigate to login page
        window.location.href = (window.TPV_BASE || '') + '/app/login';
    }

    window.addEventListener('online', () => { session.online = true; });
    window.addEventListener('offline', () => { session.online = false; });

    load();
    hydrateCashSession();

    return {
        session,
        login,
        openCash,
        closeCash,
        hydrateCashSession,
        toggleTheme,
        logout,
        save,
        startSyncPolling,
        startStockSyncTimer,
        stopStockSyncTimer,
        startStockPushTimer,
        stopStockPushTimer,
    };
})();
