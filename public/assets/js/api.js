/**
 * API client wrapper with session auth, 401 redirect, and offline queue.
 *
 * Only idempotent requests may be queued and replayed: /api/checkout carries the
 * payment attempt's request_id (PaymentModal), so a retry plus the queued replay of
 * the same attempt return one sale. Refunds and exchanges are never queued.
 */
const ApiClient = (function () {
    const BASE_URL = window.TPV_BASE || '';
    const offlineQueue = [];
    let isOnline = navigator.onLine !== undefined ? navigator.onLine : true;

    window.addEventListener('online', () => { isOnline = true; flushQueue(); });
    window.addEventListener('offline', () => { isOnline = false; });

    async function request(path, options = {}) {
        const url = BASE_URL + path;
        const opts = {
            ...options,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                ...(options.headers || {}),
            },
            credentials: 'same-origin',
        };

        if (!isOnline && options.method && options.method !== 'GET') {
            if (options.queueOnNetworkFailure === false) {
                return Promise.reject(new Error('Network unavailable; request was not sent or queued. Reconnect and try again.'));
            }
            offlineQueue.push({ url, opts });
            return Promise.resolve({ offline: true, queued: true });
        }

        let response;
        try {
            response = await fetch(url, opts);
        } catch (networkErr) {
            // True network failure (offline, DNS, etc.) — queue for retry
            if (options.method && options.method !== 'GET' && options.queueOnNetworkFailure !== false) {
                offlineQueue.push({ url, opts });
            }
            throw networkErr;
        }

        if (response.status === 401) {
            const isAuthFlow = path === '/api/auth/login'
                || path === '/api/auth/employee-lookup'
                || path === '/api/auth/employees-public'
                // an expired or forged ticket token is not a lost session
                || path.indexOf('/api/ticket/verify?') === 0;

            if (!isAuthFlow) {
                window.location.href = (window.TPV_BASE || '') + '/app/login';
            }

            return Promise.reject(new Error('Unauthorized'));
        }
        if (!response.ok) {
            const text = await response.text();
            // HTTP errors are NOT network failures — never queue them
            return Promise.reject(new Error('HTTP ' + response.status + ': ' + text));
        }
        return response.json();
    }

    /** Replays the queue in order; whatever still fails stays queued for the next time. */
    async function flushQueue() {
        const items = offlineQueue.splice(0, offlineQueue.length);
        for (const item of items) {
            try {
                await fetch(item.url, item.opts);
            } catch (e) {
                offlineQueue.push(item);
            }
        }
    }

    return {
        get: (path) => request(path, { method: 'GET' }),
        post: (path, body) => request(path, { method: 'POST', body: JSON.stringify(body) }),
        put: (path, body) => request(path, { method: 'PUT', body: JSON.stringify(body) }),
        del: (path) => request(path, { method: 'DELETE' }),
        isOnline: () => isOnline,
        pendingCount: () => offlineQueue.length,
        flush: () => flushQueue(),
        checkout: (payload) => request('/api/checkout', { method: 'POST', body: JSON.stringify(payload) }),
        couponPreview: (payload) => request('/api/checkout/coupon-preview', { method: 'POST', body: JSON.stringify(payload), queueOnNetworkFailure: false }),
        couponCheckout: (payload) => request('/api/checkout', { method: 'POST', body: JSON.stringify(payload), queueOnNetworkFailure: false }),
        createCoupon: (payload) => request('/api/coupons', { method: 'POST', body: JSON.stringify(payload), queueOnNetworkFailure: false }),
        // PRD C1, C2: the till's local customer copy; a new customer is stored locally and
        // uploaded before its sale. Never queued offline: the server itself stores it.
        customerSearch: (query) => request('/api/customers?q=' + encodeURIComponent(query || ''), { method: 'GET' }),
        customerCreate: (customer) => request('/api/customers', { method: 'POST', body: JSON.stringify(customer), queueOnNetworkFailure: false }),
        cashOpen: (openingCash) => request('/api/cash/open', { method: 'POST', body: JSON.stringify({ opening_cash: openingCash }) }),
        cashCurrent: () => request('/api/cash/current', { method: 'GET' }),
        cashMovement: (payload) => request('/api/cash/movement', { method: 'POST', body: JSON.stringify(payload) }),
        cashClose: (countedCash) => request('/api/cash/close', { method: 'POST', body: JSON.stringify({ counted_cash: countedCash }) }),
        cashReportX: () => request('/api/cash/report-x', { method: 'GET' }),
        ticketPdf: (orderId) => {
            window.open(BASE_URL + '/api/ticket/' + encodeURIComponent(orderId) + '/pdf', '_blank');
        },
        ticketReprint: (orderId) => request('/api/ticket/reprint', { method: 'POST', body: JSON.stringify({ ps_order_id: orderId }) }),
        ticketReprintGift: (orderId) => request('/api/ticket/reprint', { method: 'POST', body: JSON.stringify({ ps_order_id: orderId, gift_mode: true }) }),
        // A sale by its local order_id: works before it is uploaded to PrestaShop.
        ticketSalePdf: (orderId) => {
            window.open(BASE_URL + '/api/ticket/sale/' + encodeURIComponent(orderId) + '/pdf', '_blank');
        },
        ticketReprintSale: (orderId) => request('/api/ticket/reprint', { method: 'POST', body: JSON.stringify({ order_id: orderId }) }),
        ticketVerify: (token) => request('/api/ticket/verify?token=' + encodeURIComponent(token)),
        // A TPV sale goes by refund.order_id (also before it is uploaded); ps_order_id
        // names an online order. Never queued offline: a replayed POST would be a second refund.
        refundOrder: (psOrderId, refund) => request('/api/refund', { method: 'POST', body: JSON.stringify({ ...refund, ps_order_id: psOrderId }), queueOnNetworkFailure: false }),
        refundPdf: (refundId) => {
            window.open(BASE_URL + '/api/refund/' + encodeURIComponent(refundId) + '/pdf', '_blank');
        },
        // Never queued offline: a replayed POST would be a second refund.
        refundNoTicket: (refund) => request('/api/refund/no-ticket', { method: 'POST', body: JSON.stringify(refund), queueOnNetworkFailure: false }),
        refundPendingNoTicket: () => request('/api/refund/pending-no-ticket', { method: 'GET' }),
        // Exchanges move money and goods: never queued offline either.
        exchangeOrder: (psOrderId, exchange) => request('/api/exchange', { method: 'POST', body: JSON.stringify({ ...exchange, ps_order_id: psOrderId }), queueOnNetworkFailure: false }),
        exchangeRetry: (exchangeId) => request('/api/exchange/' + encodeURIComponent(exchangeId) + '/retry', { method: 'POST', body: JSON.stringify({}), queueOnNetworkFailure: false }),
        // Gives an open exchange credit back as a PrestaShop voucher; never replayed.
        exchangeVoucher: (exchangeId) => request('/api/exchange/' + encodeURIComponent(exchangeId) + '/voucher', { method: 'POST', body: JSON.stringify({}), queueOnNetworkFailure: false }),
        // Same catalog endpoints the sale screen uses (useCatalog / CatalogPanel).
        searchCatalog: (query) => {
            const qs = new URLSearchParams();
            if (query) qs.set('q', query);
            qs.set('limit', '20');
            return request('/api/catalog/search?' + qs.toString(), { method: 'GET' });
        },
        catalogProduct: (productId) => request('/api/catalog/product/' + encodeURIComponent(productId), { method: 'GET' }),
        refundRetry: (refundId) => request('/api/refund/' + encodeURIComponent(refundId) + '/retry', { method: 'POST', body: JSON.stringify({}) }),
        searchOrders: (query, params = {}) => {
            const qs = new URLSearchParams();
            if (query) qs.set('q', query);
            Object.entries(params).forEach(([k, v]) => qs.set(k, v));
            return request('/api/orders/search?' + qs.toString(), { method: 'GET' });
        },
        authLogout: () => request('/api/auth/logout', { method: 'POST', body: JSON.stringify({}) }),
        syncStatus: () => request('/api/sync/status', { method: 'GET' }),
        // PRD I1-I3: uploads to PrestaShop still to do and incidents; retries are admin-only and never queued.
        outboxStatus: () => request('/api/sync/outbox-status', { method: 'GET' }),
        incidentsList: () => request('/api/incidents', { method: 'GET' }),
        incidentRetry: (id) => request('/api/incidents/' + encodeURIComponent(id) + '/retry', { method: 'POST', body: JSON.stringify({}), queueOnNetworkFailure: false }),
        incidentRetryOrder: (orderId) => request('/api/incidents/order/' + encodeURIComponent(orderId) + '/retry', { method: 'POST', body: JSON.stringify({}), queueOnNetworkFailure: false }),
        syncStock: () => request('/api/sync/stock', { method: 'POST', body: JSON.stringify({}) }),
        syncNow: () => request('/api/sync/now', { method: 'POST', body: JSON.stringify({}) }),
        terminalGet: (id) => request('/api/terminal/' + encodeURIComponent(id), { method: 'GET' }),
        terminalUpdate: (id, body) => request('/api/terminal/' + encodeURIComponent(id), { method: 'PUT', body: JSON.stringify(body) }),
        openDrawer: () => request('/api/drawer/open', { method: 'POST' }),
        parkedTicketsList: () => request('/api/parked-tickets', { method: 'GET' }),
        parkedTicketCreate: (payload) => request('/api/parked-tickets', { method: 'POST', body: JSON.stringify(payload) }),
        parkedTicketDelete: (identifier) => request('/api/parked-tickets/' + encodeURIComponent(identifier), { method: 'DELETE' }),
        parkedTicketsClear: () => request('/api/parked-tickets/clear', { method: 'POST', body: JSON.stringify({}) }),
    };
})();
