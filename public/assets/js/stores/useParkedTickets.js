/**
 * useParkedTickets — Dual-persistence store for parked tickets (server MariaDB + localStorage fallback/cache).
 */
const useParkedTickets = (function () {
    const STORAGE_KEY = 'venpostpv_parked';
    const state = Vue.reactive({
        tickets: [],
        loading: false,
    });

    function formatNowSql() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return (
            d.getFullYear() + '-' +
            pad(d.getMonth() + 1) + '-' +
            pad(d.getDate()) + ' ' +
            pad(d.getHours()) + ':' +
            pad(d.getMinutes()) + ':' +
            pad(d.getSeconds())
        );
    }

    function normalizeTicket(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        const clientRef = raw.client_ref
            ? String(raw.client_ref)
            : 'parked_' + String(raw.id || Date.now());
        const lines = Array.isArray(raw.lines)
            ? raw.lines.map(l => ({
                id: l.id || ('line_' + Math.random().toString(36).slice(2, 7)),
                product_id: Number(l.product_id || l.id_product || 0),
                id_product_attribute: Number(l.id_product_attribute || 0),
                name: String(l.name || ''),
                reference: String(l.reference || ''),
                price: Number(l.price || 0),
                qty: Number(l.qty || l.quantity || 1),
                discount: Number(l.discount || 0),
            }))
            : [];
        if (lines.length === 0) {
            return null;
        }

        const discount = raw.discount && typeof raw.discount === 'object' && raw.discount.value > 0
            ? { type: raw.discount.type === 'amt' ? 'amt' : 'pct', value: Number(raw.discount.value) }
            : null;

        const customer = raw.customer && typeof raw.customer === 'object'
            ? { ...raw.customer }
            : { name: 'Consumidor final', default: true };

        const note = raw.note && String(raw.note).trim() !== ''
            ? String(raw.note).trim()
            : null;

        let computedTotal = Number(raw.total || 0);
        if (!computedTotal && lines.length > 0) {
            let sub = 0;
            lines.forEach(l => {
                sub += Number(l.price || 0) * Number(l.qty || 1) * (1 - Number(l.discount || 0) / 100);
            });
            let discAmt = 0;
            if (discount) {
                discAmt = discount.type === 'pct' ? sub * (discount.value / 100) : Math.min(discount.value, sub);
            }
            computedTotal = Math.round(Math.max(0, sub - discAmt) * 100) / 100;
        }

        return {
            id: raw.id !== undefined && raw.id !== null ? raw.id : clientRef,
            client_ref: clientRef,
            terminal_id: Number(raw.terminal_id || (window.__SESSION__ && window.__SESSION__.terminal_id) || 1),
            user_id: raw.user_id !== undefined ? raw.user_id : ((window.__SESSION__ && window.__SESSION__.user_id) || null),
            cashier_name: String(raw.cashier_name || (window.__SESSION__ && window.__SESSION__.cashier_name) || 'Cajero'),
            note,
            customer,
            lines,
            discount,
            total: computedTotal,
            created_at: raw.created_at ? String(raw.created_at) : formatNowSql(),
            synced: raw.synced !== undefined ? Boolean(raw.synced) : Boolean(typeof raw.id === 'number' && raw.id < 1000000000000),
        };
    }

    function loadLocal() {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            state.tickets = [];
            return;
        }
        try {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                state.tickets = parsed.map(normalizeTicket).filter(Boolean);
            } else {
                state.tickets = [];
            }
        } catch (e) {
            state.tickets = [];
        }
    }

    function saveLocal() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state.tickets));
    }

    async function refresh() {
        state.loading = true;
        loadLocal();
        const unsyncedLocal = state.tickets.filter(t => !t.synced);

        try {
            for (const pending of unsyncedLocal) {
                try {
                    const res = await ApiClient.parkedTicketCreate({
                        client_ref: pending.client_ref,
                        terminal_id: pending.terminal_id,
                        cashier_name: pending.cashier_name,
                        note: pending.note,
                        customer: pending.customer,
                        lines: pending.lines,
                        discount: pending.discount,
                        created_at: pending.created_at,
                    });
                    if (res && res.data) {
                        pending.id = res.data.id || pending.id;
                        pending.synced = true;
                    }
                } catch (syncErr) {
                    // Keep unsynced in localStorage if server is still unreachable
                }
            }

            const response = await ApiClient.parkedTicketsList();
            if (response && Array.isArray(response.data)) {
                const serverTickets = response.data.map(t => normalizeTicket({ ...t, synced: true })).filter(Boolean);
                const serverRefs = new Set(serverTickets.map(t => t.client_ref));
                const remainingUnsynced = state.tickets.filter(t => !t.synced && !serverRefs.has(t.client_ref));
                state.tickets = [...remainingUnsynced, ...serverTickets];
                saveLocal();
            }
        } catch (err) {
            // Keep localStorage tickets when offline or server unreachable
        } finally {
            state.loading = false;
        }

        return state.tickets;
    }

    async function parkCart(cartObj, note = '', cashierName = '') {
        if (!cartObj || !Array.isArray(cartObj.lines) || cartObj.lines.length === 0) {
            return null;
        }

        const clientRef = 'parked_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
        const trimmedNote = note && String(note).trim() !== '' ? String(note).trim().slice(0, 120) : null;
        const resolvedCashier = cashierName && String(cashierName).trim() !== ''
            ? String(cashierName).trim()
            : ((window.__SESSION__ && window.__SESSION__.cashier_name) || 'Cajero');

        const localEntry = normalizeTicket({
            id: clientRef,
            client_ref: clientRef,
            terminal_id: (window.__SESSION__ && window.__SESSION__.terminal_id) || 1,
            user_id: (window.__SESSION__ && window.__SESSION__.user_id) || null,
            cashier_name: resolvedCashier,
            note: trimmedNote,
            customer: cartObj.customer ? { ...cartObj.customer } : { name: 'Consumidor final', default: true },
            lines: cartObj.lines.map(l => ({ ...l })),
            discount: cartObj.discount ? { ...cartObj.discount } : null,
            total: Number(cartObj.total || 0),
            created_at: formatNowSql(),
            synced: false,
        });

        state.tickets = [localEntry, ...state.tickets.filter(t => t.client_ref !== clientRef)];
        saveLocal();

        try {
            const response = await ApiClient.parkedTicketCreate({
                client_ref: localEntry.client_ref,
                terminal_id: localEntry.terminal_id,
                cashier_name: localEntry.cashier_name,
                note: localEntry.note,
                customer: localEntry.customer,
                lines: localEntry.lines,
                discount: localEntry.discount,
                total: localEntry.total,
                created_at: localEntry.created_at,
            });
            if (response && response.data) {
                const persisted = normalizeTicket({ ...response.data, synced: true });
                if (persisted) {
                    state.tickets = state.tickets.map(t => (t.client_ref === clientRef ? persisted : t));
                    saveLocal();
                    return persisted;
                }
            }
        } catch (err) {
            // Persisted in localStorage; will sync on next refresh
        }

        return localEntry;
    }

    async function removeTicket(ticketOrRef) {
        if (!ticketOrRef) {
            return false;
        }
        const clientRef = typeof ticketOrRef === 'object' ? ticketOrRef.client_ref : String(ticketOrRef);
        const idOrRef = typeof ticketOrRef === 'object'
            ? (ticketOrRef.client_ref || String(ticketOrRef.id))
            : String(ticketOrRef);

        state.tickets = state.tickets.filter(t => t.client_ref !== clientRef && String(t.id) !== String(idOrRef));
        saveLocal();

        try {
            await ApiClient.parkedTicketDelete(idOrRef);
        } catch (err) {
            // Already removed locally
        }

        return true;
    }

    async function restoreWithSwap(ticketToRestore, cartStore = useCart, cashierName = '') {
        if (!ticketToRestore || !cartStore || !cartStore.cart) {
            return { restored: null, swapped: false, autoParked: null };
        }

        let autoParked = null;
        let swapped = false;

        if (Array.isArray(cartStore.cart.lines) && cartStore.cart.lines.length > 0) {
            const autoNote = cartStore.cart.customer && !cartStore.cart.customer.default
                ? 'Intercambio (' + cartStore.cart.customer.name + ')'
                : 'En espera (intercambio automático)';
            autoParked = await parkCart(cartStore.cart, autoNote, cashierName);
            swapped = true;
        }

        await removeTicket(ticketToRestore);
        cartStore.restore(
            ticketToRestore.lines || [],
            ticketToRestore.customer || { name: 'Consumidor final', default: true },
            ticketToRestore.discount || null
        );

        // The ticket keeps the price snapshot taken when it was parked. Re-price it
        // from the catalog before the cashier charges, otherwise a ticket parked
        // before a price basis change would be rejected by the checkout.
        if (typeof cartStore.reprice === 'function') {
            try {
                await cartStore.reprice();
            } catch (err) {
                // Keep the parked prices; the checkout still validates them.
            }
        }

        return {
            restored: ticketToRestore,
            swapped,
            autoParked,
        };
    }

    async function clearAll() {
        state.tickets = [];
        saveLocal();
        try {
            await ApiClient.parkedTicketsClear();
        } catch (err) {
            // Local storage is already cleared
        }
    }

    loadLocal();

    return {
        state,
        loadLocal,
        refresh,
        parkCart,
        removeTicket,
        restoreWithSwap,
        clearAll,
    };
})();
