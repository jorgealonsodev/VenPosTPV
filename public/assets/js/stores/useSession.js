/**
 * Session store — reactive session state with localStorage persistence.
 */
const useSession = (function () {
    const STORAGE_KEY = 'venpostpv_session';
    // Copy of this computer's device token (S10). The cookie is HttpOnly; this copy
    // only lets the page put the cookie back if the browser cleared it.
    const DEVICE_KEY = 'venpostpv_device_token';
    const session = Vue.reactive({
        user: null,
        cashierName: 'Cajero',
        role: 'cashier',
        sessionId: null,
        terminalId: null,
        terminalName: null,
        ticketLetter: null,
        // false: this computer has no till yet; the POS shows the link screen.
        deviceLinked: true,
        openingCash: 0,
        cashOpenedAt: null,
        cashTotals: null,
        online: navigator.onLine !== false,
        screen: 'login',
        storeName: 'VenPosTPV',
        theme: 'dark',
        syncState: 'unknown',
        syncData: null,
        // Uploads to PrestaShop still to do and incidents (PRD I1): { pending, incidents, blocked }.
        outbox: null,
        dataphoneMode: 'integrated',
        printerType: 'pdf',
        // PRD agente-impresion C4: { paired, connected, lastSeen, offline } of this till's print agent.
        printAgent: null,
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
                // A persisted screen is never authority for the cash boundary.
                // Only hydrateCashSession may transition an authenticated user to sale;
                // until it answers a neutral screen shows, so a reload with the drawer
                // open does not flash «Abrir caja».
                session.screen = 'checking';
                if (s.device_linked === false) {
                    session.deviceLinked = false;
                    session.terminalId = null;
                    session.screen = 'deviceLink';
                }
            }
            if (s.terminal_name) session.terminalName = s.terminal_name;
            if (s.ticket_letter) session.ticketLetter = s.ticket_letter;
            if (s.device_token) rememberDeviceToken(s.device_token);
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

    function rememberDeviceToken(token) {
        try {
            if (token) localStorage.setItem(DEVICE_KEY, String(token));
        } catch (e) { /* storage unavailable: the cookie still works */ }
    }

    function forgetDeviceToken() {
        try {
            if (typeof localStorage.removeItem === 'function') localStorage.removeItem(DEVICE_KEY);
        } catch (e) { /* ignore */ }
    }

    // The cookie is gone but this browser kept the token: put the cookie back and
    // reload, so the computer goes straight to its till again.
    async function restoreDeviceCookie() {
        let token = null;
        try { token = localStorage.getItem(DEVICE_KEY); } catch (e) { token = null; }
        if (!token) return false;
        try {
            await ApiClient.post('/api/device/restore', { token });
        } catch (e) {
            // 404: the till was moved to another computer; keep the link screen.
            if (e && e.message && e.message.indexOf('HTTP 404') === 0) forgetDeviceToken();
            return false;
        }
        if (window.location && typeof window.location.reload === 'function') window.location.reload();
        return true;
    }

    function save() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            cashierName: session.cashierName,
            storeName: session.storeName,
            theme: session.theme,
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

    // Only the most recent server response may set the cash UI state.
    let cashHydrationGeneration = 0;

    function toCashTotals(data) {
        return {
            sales: Number(data.sales_cash || 0),
            salesCard: Number(data.sales_card || 0),
            refunds: Number(data.refunds_cash || 0),
            refundsCard: Number(data.refunds_card || 0),
            net: Number(data.net_total || 0),
            cashIn: Number(data.cash_in_total || 0),
            cashOut: Number(data.cash_out_total || 0),
            expected: Number(data.expected_cash || 0),
        };
    }

    // Totals change with every sale and refund; read them fresh before closing.
    async function refreshCashTotals() {
        try {
            const response = await ApiClient.cashReportX();
            if (response && response.data) {
                session.cashTotals = toCashTotals(response.data);
                save();
            }
        } catch (e) {
            // Keep the last known totals; the close screen still lets the cashier count.
        }
    }

    async function hydrateCashSession() {
        // Without a till there is no drawer to ask for (the API answers 409).
        if (!session.deviceLinked) return null;
        const generation = ++cashHydrationGeneration;

        try {
            const response = await ApiClient.cashCurrent();
            const data = response && response.data ? response.data : { open: false };
            if (generation !== cashHydrationGeneration) return null;

            if (!data.open) {
                session.sessionId = null;
                // Preserve terminalId from window.__SESSION__ — SettingsModal needs it
                // even when no cash session is open.
                session.cashOpenedAt = null;
                session.openingCash = 0;
                session.cashTotals = null;
                session.screen = session.user ? 'cashOpen' : 'login';
                save();
                return false;
            }

            session.sessionId = data.session_id;
            session.terminalId = data.terminal_id;
            session.cashOpenedAt = data.opened_at;
            session.openingCash = Number(data.opening_cash || 0);
            session.cashTotals = toCashTotals(data);
            session.screen = 'sale';
            startSyncPolling();
            startStockSyncTimer();
            startStockPushTimer();
            save();
            return true;
        } catch (e) {
            // Keep the existing screen on a transport failure: the cash state is unknown.
            // Still waiting after a reload: «Abrir caja», as before (never the sale).
            if (generation === cashHydrationGeneration && session.screen === 'checking') {
                session.screen = session.user ? 'cashOpen' : 'login';
            }
            return null;
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

    /** Reads the outbox counts; a failed read keeps the last ones. */
    async function refreshOutbox() {
        if (typeof ApiClient.outboxStatus !== 'function') return session.outbox;
        try {
            const response = await ApiClient.outboxStatus();
            if (response && response.data) {
                session.outbox = {
                    pending: Number(response.data.pending || 0),
                    incidents: Number(response.data.incidents || 0),
                    // Part of pending that waits for an incident: it does not upload on its own.
                    blocked: Number(response.data.blocked || 0),
                };
            }
        } catch (e) {
            // Keep the last known counts.
        }
        return session.outbox;
    }

    // C4: more than 2 minutes without the agent connecting (server time of its last request).
    const PRINT_AGENT_OFFLINE_MS = 120000;

    /** The print agent of this till, only when its printer is the agent; a failed read keeps the last state. */
    async function refreshPrintAgent() {
        if (session.printerType !== 'agent' || !session.terminalId || typeof ApiClient.printAgentStatus !== 'function') {
            session.printAgent = null;
            return null;
        }
        try {
            const data = await ApiClient.printAgentStatus(session.terminalId);
            if (data && typeof data === 'object') {
                const seen = data.last_seen ? Date.parse(data.last_seen) : NaN;
                session.printAgent = {
                    paired: !!data.paired,
                    connected: !!data.connected,
                    lastSeen: data.last_seen || null,
                    offline: !data.connected && (Number.isNaN(seen) || Date.now() - seen > PRINT_AGENT_OFFLINE_MS),
                };
            }
        } catch (e) {
            // Keep the last known state.
        }
        return session.printAgent;
    }

    /** The permanent notice of the sale screen (C4), or '' while the agent is connected. */
    function printAgentNotice() {
        const agent = session.printAgent;
        if (session.printerType !== 'agent' || !agent || !agent.offline) return '';
        if (!agent.lastSeen) return 'La impresora no está conectada. Conéctala desde Ajustes → Cajas.';
        const since = new Date(agent.lastSeen).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        return 'La impresora no está conectada desde las ' + since + '. Puedes seguir cobrando: el ticket se ofrece en PDF.';
    }

    async function doPoll() {
        refreshOutbox();
        refreshPrintAgent();
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
        // Invalidate an in-flight hydration before opening a new session.
        ++cashHydrationGeneration;

        try {
            const response = await ApiClient.cashOpen(amount);
            const data = response.data;
            session.sessionId = data.session_id;
            session.terminalId = data.terminal_id;
            session.cashOpenedAt = data.opened_at;
            session.openingCash = Number(data.opening_cash || 0);
        } catch (err) {
            if (err && err.message && err.message.startsWith('HTTP 409')) {
                const hasCurrentUsersSession = await hydrateCashSession();
                if (hasCurrentUsersSession) {
                    return;
                }
            }
            throw err;
        }
        await hydrateCashSession();
    }

    /**
     * Prints the X report of the open till ('x') or the Z report of a close ('z' +
     * its session id) on this till's printer. Answers 'queued' (print agent, the job
     * is followed by usePrintJobs), 'printed', or 'pdf' when the till has no printer
     * and the PDF was opened instead. Rejects when the server refuses.
     */
    async function printCashReport(kind, sessionId) {
        const response = kind === 'z' ? await ApiClient.cashZPrint(sessionId) : await ApiClient.cashReportXPrint();
        const data = (response && response.data) || {};
        if (data.print_status === 'queued') {
            if (typeof usePrintJobs !== 'undefined') usePrintJobs.trackResponse(data, { kind: 'cash_report' });
            return 'queued';
        }
        if (data.print_status === 'printed') return 'printed';
        if (data.fallback === 'pdf') {
            if (kind === 'z') ApiClient.cashZPdf(sessionId);
            else ApiClient.cashReportXPdf();
            return 'pdf';
        }
        return 'unknown';
    }

    /**
     * Closes the till, then prints its Z report. The close is done once the server
     * answers: a printing problem never undoes it, it only comes back as z_print
     * 'failed' (the Z stays in Ajustes → Cierres de caja).
     */
    async function closeCash(countedCash) {
        // A close wins over any response that was already hydrating the old session.
        ++cashHydrationGeneration;
        const response = await ApiClient.cashClose(countedCash);
        session.screen = 'cashOpen';
        session.sessionId = null;
        // terminalId stays: it is this computer's till, not the closed session's.
        session.cashOpenedAt = null;
        session.openingCash = 0;
        session.cashTotals = null;
        save();

        const closed = (response && response.data) || {};
        let zPrint = 'skipped';
        if (closed.session_id) {
            try {
                zPrint = await printCashReport('z', closed.session_id);
            } catch (err) {
                zPrint = 'failed';
            }
        }
        return { ...closed, z_print: zPrint };
    }

    function toggleTheme() {
        session.theme = session.theme === 'dark' ? 'light' : 'dark';
        applyTheme();
        save();
    }

    async function logout() {
        // Do this before awaiting logout so a stale hydration cannot restore the sale screen.
        ++cashHydrationGeneration;
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
        session.outbox = null;
        session.printAgent = null;
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
    if (session.user && !session.deviceLinked) restoreDeviceCookie();
    hydrateCashSession();

    return {
        session,
        login,
        openCash,
        closeCash,
        printCashReport,
        hydrateCashSession,
        refreshCashTotals,
        refreshOutbox,
        refreshPrintAgent,
        printAgentNotice,
        rememberDeviceToken,
        restoreDeviceCookie,
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
