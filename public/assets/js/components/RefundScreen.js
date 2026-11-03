/**
 * RefundScreen — order search, line selection and refund confirmation.
 *
 * Amounts shown here are an estimate of what the server will refund: the server
 * computes the real amounts from what each sale line paid, so the request only
 * carries line indexes, quantities and the restock choice.
 *
 * "Sin ticket" refunds products picked from the catalog at their current price
 * (computed again on the server) by the method the cashier chooses: cash, card
 * or a PrestaShop voucher.
 *
 * "Cambiar" exchanges lines of the selected sale for new items in one operation:
 * the returned lines become credit, the new items are picked with the same catalog
 * search, and only the difference is paid (positive balance) or refunded by the
 * original method (negative balance). The server prices both sides again.
 *
 * Online orders (found in PrestaShop when no TPV sale matches) are marked "Pedido
 * online": they can be refunded or exchanged only once delivered, and no money goes
 * back at the till — the summary shows "Reembolso pendiente en la web" instead of
 * the cash/card split, because the office refunds them from the back office.
 */
const NO_TICKET_METHODS = [
    { code: 'cash', label: 'Efectivo' },
    { code: 'card', label: 'Tarjeta' },
    { code: 'voucher', label: 'Vale' },
];

const REFUND_REASONS = [
    { code: 'size', label: 'Talla o color' },
    { code: 'defective', label: 'Defectuoso' },
    { code: 'dislike', label: 'No le gusta' },
    { code: 'billing_error', label: 'Error de cobro' },
    { code: 'not_as_described', label: 'No coincide con la web' },
    { code: 'gift_duplicate', label: 'Regalo / duplicado' },
    { code: 'late', label: 'Llegó tarde' },
    { code: 'other', label: 'Otro' },
];

const RefundScreen = {
    props: ['cashierName'],
    emits: ['back', 'notify'],
    setup(props, { emit }) {
        const { session } = useSession;

        const searchQuery = Vue.ref('');
        const searchResults = Vue.ref([]);
        const selectedOrder = Vue.ref(null);
        const selectedLines = Vue.ref([]);
        const reasonCode = Vue.ref('');
        const reasonText = Vue.ref('');
        const cardConfirmed = Vue.ref(false);
        const loading = Vue.ref(false);
        const confirmModal = Vue.ref(false);

        // Refund without ticket
        const noTicket = Vue.ref(false);
        const productQuery = Vue.ref('');
        const productResults = Vue.ref([]);
        const combinationChoice = Vue.ref(null);
        const noTicketLines = Vue.ref([]);
        const refundMethod = Vue.ref('');
        const pendingNoTicket = Vue.ref([]);
        const lastVoucher = Vue.ref(null);

        // Exchange of the selected sale
        const exchangeMode = Vue.ref(false);
        const exchangeLines = Vue.ref([]);
        const exchangePayment = Vue.ref('');
        const exchangeTendered = Vue.ref('');
        const exchangeCash = Vue.ref('');
        const lastExchange = Vue.ref(null);
        // Voucher that closed an exchange whose new sale could not be completed.
        const exchangeVoucher = Vue.ref(null);
        const exchangeVoucherConfirm = Vue.ref(false);

        // Same half-up rounding as PHP round(): 10.075 must give 10.08, not 10.07.
        const round2 = (n) => Math.round(Number(((Number(n) || 0) * 100).toPrecision(12))) / 100;

        // The ticket QR encodes `<APP_URL>/api/ticket/verify?token=<JWT>`.
        function ticketToken(code) {
            const match = /[?&]token=([^&#\s]+)/.exec(code);
            if (!match) return '';
            try { return decodeURIComponent(match[1]); } catch (e) { return match[1]; }
        }

        // The verify token expires long before the refund window ends, so an expired
        // token still identifies the ticket: the claims only feed the order search.
        function unverifiedClaims(token) {
            try {
                const part = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
                const claims = JSON.parse(atob(part + '='.repeat((4 - (part.length % 4)) % 4)));
                return claims && (claims.order_id || claims.reference) ? claims : null;
            } catch (e) {
                return null;
            }
        }

        async function ticketClaims(token) {
            try {
                const result = await ApiClient.ticketVerify(token);
                if (result && result.data && (result.data.order_id || result.data.reference)) return result.data;
            } catch (e) {
                // expired or unverifiable: fall back to the token's own claims
            }
            return unverifiedClaims(token);
        }

        // Searches by order id, reference or a scanned QR payload; a single match opens directly.
        async function handleSearch() {
            const code = String(searchQuery.value || '').trim();
            if (code.length < 2) {
                emit('notify', 'Introduce al menos 2 caracteres', 'var(--red)');
                return;
            }
            loading.value = true;
            try {
                let query = code;
                const token = ticketToken(code);
                if (token) {
                    const claims = await ticketClaims(token);
                    if (!claims) {
                        searchResults.value = [];
                        emit('notify', 'No se reconoce el código del ticket', 'var(--red)');
                        return;
                    }
                    query = claims.order_id ? String(claims.order_id) : String(claims.reference);
                    searchQuery.value = claims.reference ? String(claims.reference) : query;
                }
                const result = await ApiClient.searchOrders(query);
                searchResults.value = result && Array.isArray(result.data) ? result.data : [];
                if (searchResults.value.length === 1) {
                    selectOrder(searchResults.value[0]);
                }
            } catch (err) {
                emit('notify', 'Error en búsqueda: ' + (err.message || 'Error'), 'var(--red)');
                searchResults.value = [];
            } finally {
                loading.value = false;
            }
        }

        // USB/HID scanner typing outside any field. Inside a field the keystrokes land in
        // it and that field's Enter handles them (the search box runs handleSearch).
        function handleScan(code) {
            const active = typeof document !== 'undefined' ? document.activeElement : null;
            const tag = active && active.tagName ? String(active.tagName).toUpperCase() : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return undefined;
            if (loading.value || confirmModal.value || noTicket.value || exchangeMode.value) return undefined;
            searchQuery.value = String(code || '').trim();
            return handleSearch();
        }

        const scanner = typeof useBarcode !== 'undefined' ? useBarcode : null;
        if (scanner && typeof Vue.onMounted === 'function') {
            Vue.onMounted(() => scanner.onScan(handleScan));
        }
        if (scanner && typeof Vue.onUnmounted === 'function') {
            Vue.onUnmounted(() => scanner.offScan());
        }

        function selectOrder(order) {
            selectedOrder.value = order;
            exchangeVoucher.value = null;
            exchangeVoucherConfirm.value = false;
            reasonCode.value = '';
            reasonText.value = '';
            cardConfirmed.value = false;
            selectedLines.value = (order.lines || []).map((line, idx) => {
                const sold = Number(line.qty || line.quantity || 1);
                const refundable = line.refundable_qty !== undefined ? Number(line.refundable_qty) : sold;
                const paid = line.paid_total !== undefined ? Number(line.paid_total) : null;
                return {
                    index: idx,
                    name: line.name || 'Producto',
                    sold,
                    refundable,
                    unitPaid: paid !== null && sold > 0 ? paid / sold : null,
                    refundable_ok: Boolean(line.product_id) && paid !== null,
                    checked: false,
                    refundQty: Math.max(1, refundable),
                    restock: true,
                };
            });
        }

        function backToSearch() {
            resetExchange();
            selectedOrder.value = null;
            selectedLines.value = [];
            reasonCode.value = '';
            reasonText.value = '';
            cardConfirmed.value = false;
            confirmModal.value = false;
        }

        function toggleLine(idx) {
            const line = selectedLines.value[idx];
            if (line && line.refundable_ok && line.refundable > 0) {
                line.checked = !line.checked;
            }
        }

        function updateRefundQty(idx, qty) {
            const line = selectedLines.value[idx];
            if (line) {
                line.refundQty = Math.max(1, Math.min(line.refundable, parseInt(qty, 10) || 1));
            }
        }

        // Touch steppers: the − / + buttons move the quantity one unit within the same limits.
        function stepRefundQty(idx, delta) {
            const line = selectedLines.value[idx];
            if (line) updateRefundQty(idx, line.refundQty + delta);
        }

        function setReason(code) {
            reasonCode.value = code;
            // A defective item normally must not go back on sale; the cashier can still tick it.
            const restock = code !== 'defective';
            selectedLines.value.forEach((line) => { line.restock = restock; });
            noTicketLines.value.forEach((line) => { line.restock = restock; });
        }

        function resetNoTicket() {
            productQuery.value = '';
            productResults.value = [];
            combinationChoice.value = null;
            noTicketLines.value = [];
            refundMethod.value = '';
            reasonCode.value = '';
            reasonText.value = '';
            cardConfirmed.value = false;
            confirmModal.value = false;
        }

        async function loadPendingNoTicket() {
            try {
                const result = await ApiClient.refundPendingNoTicket();
                pendingNoTicket.value = result && Array.isArray(result.data) ? result.data : [];
            } catch (e) {
                pendingNoTicket.value = [];
            }
        }

        async function openNoTicket() {
            backToSearch();
            resetNoTicket();
            lastVoucher.value = null;
            noTicket.value = true;
            await loadPendingNoTicket();
        }

        function closeNoTicket() {
            resetNoTicket();
            noTicket.value = false;
        }

        async function searchProducts() {
            const q = String(productQuery.value || '').trim();
            if (q.length < 2) {
                emit('notify', 'Introduce al menos 2 caracteres', 'var(--red)');
                return;
            }
            loading.value = true;
            try {
                const result = await ApiClient.searchCatalog(q);
                productResults.value = result && Array.isArray(result.data) ? result.data : [];
            } catch (err) {
                productResults.value = [];
                emit('notify', 'Error en búsqueda: ' + (err.message || 'Error'), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        // Products picked from the catalog go to the exchange or to the refund without ticket.
        function catalogLines() {
            return exchangeMode.value ? exchangeLines : noTicketLines;
        }

        function addNoTicketLine(product, combo) {
            const attributeId = combo ? Number(combo.id) : 0;
            const existing = catalogLines().value.find((l) => l.product_id === Number(product.id) && l.id_product_attribute === attributeId);
            if (existing) {
                existing.quantity += 1;
                return;
            }
            const label = combo && Array.isArray(combo.attributes) && combo.attributes.length
                ? ' (' + combo.attributes.map((a) => a.group_name + ': ' + a.attribute_name).join(' / ') + ')'
                : '';
            catalogLines().value.push({
                product_id: Number(product.id),
                id_product_attribute: attributeId,
                name: (product.name || 'Producto') + label,
                // Display estimate only, same as the sale screen; the server prices it.
                unitPrice: round2(Number(product.price || 0) + (combo ? Number(combo.price_impact || 0) : 0)),
                quantity: 1,
                restock: reasonCode.value !== 'defective',
            });
        }

        async function pickProduct(product) {
            if (!product) return;
            if (!product.has_combinations) {
                addNoTicketLine(product, null);
                return;
            }
            try {
                const result = await ApiClient.catalogProduct(product.id);
                combinationChoice.value = result && result.data ? result.data : null;
            } catch (err) {
                emit('notify', 'No se pudieron cargar las variantes: ' + (err.message || 'Error'), 'var(--red)');
            }
        }

        function pickCombination(combo) {
            if (combinationChoice.value && combo) {
                addNoTicketLine(combinationChoice.value, combo);
            }
            combinationChoice.value = null;
        }

        function updateNoTicketQty(idx, qty) {
            const line = catalogLines().value[idx];
            if (line) {
                line.quantity = Math.max(1, parseInt(qty, 10) || 1);
            }
        }

        function stepNoTicketQty(idx, delta) {
            const line = catalogLines().value[idx];
            if (line) updateNoTicketQty(idx, line.quantity + delta);
        }

        function removeNoTicketLine(idx) {
            catalogLines().value.splice(idx, 1);
        }

        function setMethod(code) {
            refundMethod.value = code;
            cardConfirmed.value = false;
        }

        function noTicketTotal() {
            return round2(noTicketLines.value.reduce((sum, l) => sum + l.unitPrice * l.quantity, 0));
        }

        function estimatedTotal() {
            return round2(selectedLines.value
                .filter((l) => l.checked && l.unitPaid !== null)
                .reduce((sum, l) => sum + l.unitPaid * l.refundQty, 0));
        }

        function estimatedSplit() {
            return splitByTender(estimatedTotal());
        }

        function isOnline() {
            return Boolean(selectedOrder.value && selectedOrder.value.online);
        }

        function formatDate(value) {
            const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
            return match ? match[3] + '/' + match[2] + '/' + match[1] : '';
        }

        // The refund window of an online order counts from its delivery (R3).
        function deliveredLabel(order) {
            const o = order || selectedOrder.value;
            if (!o || !o.online) return '';
            if (o.delivered_at) return 'Entregado el ' + formatDate(o.delivered_at);
            return 'Sin entregar' + (o.state ? ' (' + o.state + ')' : '');
        }

        function onlineBlockedReason() {
            const order = selectedOrder.value;
            if (!order || !order.online) return '';
            if (order.created_by_tpv) return 'Pedido de un TPV sin registro aquí: devuélvelo desde el back office de PrestaShop';
            if (!order.delivered_at) return 'Pedido online sin entregar: no se puede devolver ni cambiar';
            return '';
        }

        // Same proportional rule as the server's RefundService::splitByTender: the
        // exchange-credit share of a sale goes back as a voucher. An online order pays
        // nothing at the till: all of it is refunded from the web.
        function splitByTender(total) {
            const order = selectedOrder.value || {};
            if (order.online) return { cash: 0, card: 0, web: round2(total) };
            const tender = order.tender || {};
            let cash = Math.max(0, Number(tender.cash || 0));
            let card = Math.max(0, Number(tender.card || 0));
            const credit = Math.max(0, Number(tender.exchange_credit || 0));
            if (credit > 0) {
                const sum = cash + card + credit;
                let cashPart = round2(total * cash / sum);
                let cardPart = round2(total * card / sum);
                let voucher = round2(total - cashPart - cardPart);
                if (voucher < 0) {
                    if (cardPart >= cashPart) cardPart = round2(cardPart + voucher); else cashPart = round2(cashPart + voucher);
                    voucher = 0;
                }
                return { cash: cashPart, card: cardPart, voucher };
            }
            if (cash + card <= 0) {
                cash = order.payment_method === 'card' ? 0 : 1;
                card = 1 - cash;
            }
            const cashPart = round2(total * cash / (cash + card));
            return { cash: cashPart, card: round2(total - cashPart) };
        }

        function resetExchange() {
            exchangeMode.value = false;
            exchangeLines.value = [];
            exchangePayment.value = '';
            exchangeTendered.value = '';
            exchangeCash.value = '';
            productQuery.value = '';
            productResults.value = [];
            combinationChoice.value = null;
        }

        function startExchange() {
            if (!selectedOrder.value) return;
            resetExchange();
            cardConfirmed.value = false;
            lastExchange.value = null;
            exchangeMode.value = true;
        }

        function cancelExchange() {
            resetExchange();
            cardConfirmed.value = false;
        }

        // Estimates only: the server recomputes credit, new total and balance.
        function exchangeCredit() {
            return estimatedTotal();
        }

        function exchangeNewTotal() {
            return round2(exchangeLines.value.reduce((sum, l) => sum + l.unitPrice * l.quantity, 0));
        }

        function exchangeBalance() {
            return round2(exchangeNewTotal() - exchangeCredit());
        }

        function exchangePayoutSplit() {
            const balance = exchangeBalance();
            return balance < 0 ? splitByTender(-balance) : { cash: 0, card: 0 };
        }

        function setExchangePayment(code) {
            exchangePayment.value = code;
        }

        function cardRefundAmount() {
            if (noTicket.value) return noTicketTotal();
            return exchangeMode.value ? exchangePayoutSplit().card : estimatedSplit().card;
        }

        function needsCardConfirmation() {
            if (noTicket.value) return refundMethod.value === 'card' && noTicketTotal() > 0;
            return cardRefundAmount() > 0;
        }

        function exchangeValidationError() {
            if (!selectedLines.value.some((l) => l.checked)) return 'Selecciona al menos una línea a devolver';
            if (exchangeLines.value.length === 0) return 'Añade al menos un artículo nuevo';
            if (!reasonCode.value) return 'Elige el motivo de la devolución';
            if (reasonCode.value === 'other' && !reasonText.value.trim()) return 'Escribe el motivo';
            const balance = exchangeBalance();
            if (balance > 0) {
                if (!exchangePayment.value) return 'Elige cómo paga la diferencia';
                if (exchangePayment.value === 'cash' && round2(exchangeTendered.value) < balance) return 'El efectivo entregado no cubre la diferencia';
                const cash = round2(exchangeCash.value);
                if (exchangePayment.value === 'mixed' && (cash <= 0 || cash >= balance)) return 'El efectivo del pago mixto debe ser menor que la diferencia';
            }
            if (needsCardConfirmation() && !cardConfirmed.value) return 'Confirma que has hecho la devolución en el datáfono';
            return '';
        }

        function validationError() {
            if (noTicket.value) {
                if (noTicketLines.value.length === 0) return 'Añade al menos un producto';
                if (!reasonCode.value) return 'Elige el motivo de la devolución';
                if (!refundMethod.value) return 'Elige cómo se devuelve: efectivo, tarjeta o vale';
                if (reasonCode.value === 'other' && !reasonText.value.trim()) return 'Escribe el motivo';
                if (needsCardConfirmation() && !cardConfirmed.value) return 'Confirma que has hecho la devolución en el datáfono';
                return '';
            }
            if (onlineBlockedReason()) return onlineBlockedReason();
            if (selectedOrder.value && selectedOrder.value.open_exchange) return 'Hay un cambio pendiente: complétalo primero';
            if (exchangeMode.value) return exchangeValidationError();
            if (selectedOrder.value && selectedOrder.value.pending_refund) return 'Hay una devolución pendiente de confirmar: reinténtala primero';
            if (!selectedLines.value.some((l) => l.checked)) return 'Selecciona al menos una línea';
            if (!reasonCode.value) return 'Elige el motivo de la devolución';
            if (reasonCode.value === 'other' && !reasonText.value.trim()) return 'Escribe el motivo';
            if (needsCardConfirmation() && !cardConfirmed.value) return 'Confirma que has hecho la devolución en el datáfono';
            return '';
        }

        function buildRequest() {
            if (noTicket.value) {
                return {
                    lines: noTicketLines.value.map((l) => ({
                        product_id: l.product_id, id_product_attribute: l.id_product_attribute, quantity: l.quantity, restock: Boolean(l.restock),
                    })),
                    method: refundMethod.value,
                    reason_code: reasonCode.value,
                    reason_text: reasonCode.value === 'other' ? reasonText.value.trim() : '',
                    card_confirmed: needsCardConfirmation() ? Boolean(cardConfirmed.value) : false,
                };
            }
            return {
                lines: selectedLines.value
                    .filter((l) => l.checked)
                    .map((l) => ({ line_index: l.index, quantity: l.refundQty, restock: Boolean(l.restock) })),
                reason_code: reasonCode.value,
                reason_text: reasonCode.value === 'other' ? reasonText.value.trim() : '',
                card_confirmed: needsCardConfirmation() ? Boolean(cardConfirmed.value) : false,
            };
        }

        function buildExchangeRequest() {
            const balance = exchangeBalance();
            const lines = exchangeLines.value.map((l) => ({
                product_id: l.product_id, id_product_attribute: l.id_product_attribute, qty: l.quantity, price: l.unitPrice, name: l.name,
            }));
            const request = {
                return_lines: selectedLines.value
                    .filter((l) => l.checked)
                    .map((l) => ({ line_index: l.index, quantity: l.refundQty, restock: Boolean(l.restock) })),
                reason_code: reasonCode.value,
                reason_text: reasonCode.value === 'other' ? reasonText.value.trim() : '',
                // Same cart_data as the sale screen sends to /api/checkout.
                new_cart_data: { lines, total: exchangeNewTotal() },
                payment_method: balance > 0 ? exchangePayment.value : '',
                amount_tendered: 0,
                card_confirmed: needsCardConfirmation() ? Boolean(cardConfirmed.value) : false,
            };
            if (balance > 0 && exchangePayment.value === 'cash') request.amount_tendered = round2(exchangeTendered.value);
            if (balance > 0 && exchangePayment.value === 'mixed') {
                request.cash_amount = round2(exchangeCash.value);
                request.amount_tendered = request.cash_amount;
            }
            return request;
        }

        function exchangeDoneMessage(data) {
            const refund = data.refund || {};
            const sale = data.sale || {};
            const balance = Number(data.balance || 0);
            const parts = ['Cambio hecho'];
            if (refund.credit_note_number) parts.push('Abono ' + refund.credit_note_number);
            if (sale.reference) parts.push('Venta ' + sale.reference);
            if (balance > 0) parts.push('cobrar ' + balance.toFixed(2) + ' €');
            if (Number(refund.cash_amount) > 0) parts.push(Number(refund.cash_amount).toFixed(2) + ' € en efectivo');
            if (Number(refund.card_amount) > 0) parts.push(Number(refund.card_amount).toFixed(2) + ' € a tarjeta');
            if (Number(refund.web_refund_amount) > 0) parts.push('reembolso pendiente en la web ' + Number(refund.web_refund_amount).toFixed(2) + ' €');
            if (Number(sale.change_due) > 0) parts.push('cambio ' + Number(sale.change_due).toFixed(2) + ' €');
            return parts.join(' · ');
        }

        // Both documents are handed over; an open credit keeps the order on screen to retry.
        function handleExchangeResult(data) {
            const refund = data.refund || {};
            handOverCreditNote(refund);
            if (data.status === 'done') {
                const sale = data.sale || {};
                lastExchange.value = {
                    creditNote: refund.credit_note_number || (lastExchange.value && lastExchange.value.creditNote) || null,
                    saleOrderId: sale.ps_order_id || null,
                    saleReference: sale.reference || '',
                    balance: Number(data.balance || 0),
                    changeDue: Number(sale.change_due || 0),
                    cashBack: Number(refund.cash_amount || 0),
                    cardBack: Number(refund.card_amount || 0),
                };
                // Online order: what the office still refunds from the web.
                if (Number(refund.web_refund_amount) > 0) lastExchange.value.webBack = Number(refund.web_refund_amount);
                emit('notify', exchangeDoneMessage(data), 'var(--green)');
                backToSearch();
                return;
            }
            const unknown = data.sale_outcome_unknown
                ? ' Antes de reintentar, comprueba en PrestaShop que no se creó el pedido.'
                : '';
            emit('notify', (refund.credit_note_number ? 'Abono ' + refund.credit_note_number + ' emitido, pero la' : 'La') + ' venta nueva no se ha creado: reintenta el cambio. ' + (data.sale_error || '') + unknown, 'var(--red)');
            lastExchange.value = refund.credit_note_number ? { creditNote: refund.credit_note_number } : lastExchange.value;
            resetExchange();
            selectedOrder.value = {
                ...selectedOrder.value,
                open_exchange: { id: data.exchange_id, status: data.status, credit_applied: Number(data.credit || 0), new_total: Number(data.new_total || 0), balance: Number(data.balance || 0), error: data.sale_error || null },
            };
        }

        async function confirmExchange() {
            const request = buildExchangeRequest();
            loading.value = true;
            try {
                const result = await ApiClient.exchangeOrder(selectedOrder.value.ps_order_id, request);
                handleExchangeResult((result && result.data) || {});
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        async function retryExchange() {
            const open = selectedOrder.value && selectedOrder.value.open_exchange;
            if (!open || loading.value) return;
            loading.value = true;
            try {
                const result = await ApiClient.exchangeRetry(open.id);
                handleExchangeResult((result && result.data) || {});
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        function closeExchangeWithVoucher() {
            const open = selectedOrder.value && selectedOrder.value.open_exchange;
            if (!open || open.status !== 'credit_open' || loading.value) return;
            exchangeVoucherConfirm.value = true;
        }

        async function confirmExchangeVoucher() {
            const open = selectedOrder.value && selectedOrder.value.open_exchange;
            exchangeVoucherConfirm.value = false;
            if (!open || open.status !== 'credit_open' || loading.value) return;
            loading.value = true;
            try {
                const result = await ApiClient.exchangeVoucher(open.id);
                const data = (result && result.data) || {};
                exchangeVoucher.value = { code: data.voucher_code, amount: Number(data.voucher_amount || 0) };
                selectedOrder.value = { ...selectedOrder.value, open_exchange: null };
                emit('notify', 'Cambio #' + open.id + ' cerrado con vale ' + data.voucher_code + ' de ' + Number(data.voucher_amount || 0).toFixed(2) + ' €', 'var(--green)');
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        function openConfirm() {
            const error = validationError();
            if (error) {
                emit('notify', error, 'var(--red)');
                return;
            }
            confirmModal.value = true;
        }

        function errorMessage(err) {
            const msg = (err && err.message) || 'Error';
            const match = /^HTTP (\d+): (.*)$/s.exec(msg);
            let detail = msg;
            let status = 0;
            if (match) {
                status = Number(match[1]);
                try {
                    const body = JSON.parse(match[2]);
                    detail = body.error || match[2];
                } catch (e) {
                    detail = match[2];
                }
            }
            if (status === 404) return 'Pedido no encontrado';
            if (status === 409) return 'No se ha devuelto nada nuevo: ' + detail;
            if (status === 422) return 'No se puede devolver: ' + detail;
            if (status === 502) return 'PrestaShop no ha confirmado la devolución. No entregues dinero; queda pendiente para reintentar: ' + detail;
            return 'Error en devolución: ' + detail;
        }

        function handOverCreditNote(data) {
            // The abono is always handed over: when no printer took it, open the PDF.
            if (data.refund_id && data.print_status === 'pdf') {
                ApiClient.refundPdf(data.refund_id);
            }
        }

        function refundDoneMessage(data) {
            const parts = [(data.credit_note_number ? 'Abono ' + data.credit_note_number + ': ' : 'Devolución de ') + Number(data.refunded_amount || 0).toFixed(2) + ' €'];
            if (Number(data.cash_amount) > 0) parts.push(Number(data.cash_amount).toFixed(2) + ' € en efectivo');
            if (Number(data.card_amount) > 0) parts.push(Number(data.card_amount).toFixed(2) + ' € a tarjeta');
            if (data.voucher_code) parts.push('Vale ' + data.voucher_code);
            if (Number(data.web_refund_amount) > 0) parts.push('reembolso pendiente en la web ' + Number(data.web_refund_amount).toFixed(2) + ' €');
            const warn = data.saga_error || (Array.isArray(data.restock_failed) && data.restock_failed.length > 0);
            return { text: parts.join(' · ') + (warn ? ' — revisar stock/caja' : ''), color: warn ? 'var(--orange, var(--red))' : 'var(--green)' };
        }

        async function retryPending() {
            const pending = selectedOrder.value && selectedOrder.value.pending_refund;
            if (!pending || loading.value) return;
            loading.value = true;
            try {
                const result = await ApiClient.refundRetry(pending.id);
                const data = (result && result.data) || {};
                handOverCreditNote(data);
                const done = refundDoneMessage(data);
                emit('notify', done.text, done.color);
                backToSearch();
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        async function retryNoTicket(refundId) {
            if (loading.value) return;
            loading.value = true;
            try {
                const result = await ApiClient.refundRetry(refundId);
                const data = (result && result.data) || {};
                handOverCreditNote(data);
                if (data.voucher_code) lastVoucher.value = { code: data.voucher_code, amount: Number(data.refunded_amount || 0) };
                const done = refundDoneMessage(data);
                emit('notify', done.text, done.color);
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
            await loadPendingNoTicket();
        }

        async function confirmNoTicketRefund() {
            const request = buildRequest();
            loading.value = true;
            let completed = false;
            try {
                const result = await ApiClient.refundNoTicket(request);
                const data = (result && result.data) || {};
                handOverCreditNote(data);
                lastVoucher.value = data.voucher_code ? { code: data.voucher_code, amount: Number(data.refunded_amount || 0) } : null;
                const done = refundDoneMessage(data);
                emit('notify', done.text, done.color);
                completed = true;
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
            if (completed) resetNoTicket();
            await loadPendingNoTicket();
        }

        async function confirmRefund() {
            confirmModal.value = false;
            if (noTicket.value) {
                if (!loading.value) await confirmNoTicketRefund();
                return;
            }
            if (!selectedOrder.value || loading.value) return;
            if (exchangeMode.value) {
                await confirmExchange();
                return;
            }

            const request = buildRequest();
            loading.value = true;
            try {
                const result = await ApiClient.refundOrder(selectedOrder.value.ps_order_id, request);
                const data = (result && result.data) || {};
                handOverCreditNote(data);
                const done = refundDoneMessage(data);
                emit('notify', done.text, done.color);
                backToSearch();
            } catch (err) {
                emit('notify', errorMessage(err), 'var(--red)');
            } finally {
                loading.value = false;
            }
        }

        function cancelConfirm() {
            confirmModal.value = false;
        }

        return {
            session,
            reasons: REFUND_REASONS,
            searchQuery,
            searchResults,
            selectedOrder,
            selectedLines,
            reasonCode,
            reasonText,
            cardConfirmed,
            loading,
            confirmModal,
            handleSearch,
            selectOrder,
            backToSearch,
            toggleLine,
            updateRefundQty,
            stepRefundQty,
            setReason,
            estimatedTotal,
            estimatedSplit,
            isOnline,
            deliveredLabel,
            needsCardConfirmation,
            validationError,
            buildRequest,
            openConfirm,
            confirmRefund,
            retryPending,
            cancelConfirm,
            errorMessage,
            methods: NO_TICKET_METHODS,
            noTicket,
            productQuery,
            productResults,
            combinationChoice,
            noTicketLines,
            refundMethod,
            pendingNoTicket,
            lastVoucher,
            openNoTicket,
            closeNoTicket,
            searchProducts,
            pickProduct,
            pickCombination,
            updateNoTicketQty,
            stepNoTicketQty,
            removeNoTicketLine,
            setMethod,
            noTicketTotal,
            retryNoTicket,
            exchangeMode,
            exchangeLines,
            exchangePayment,
            exchangeTendered,
            exchangeCash,
            lastExchange,
            startExchange,
            cancelExchange,
            exchangeCredit,
            exchangeNewTotal,
            exchangeBalance,
            exchangePayoutSplit,
            setExchangePayment,
            cardRefundAmount,
            buildExchangeRequest,
            retryExchange,
            exchangeVoucher,
            exchangeVoucherConfirm,
            closeExchangeWithVoucher,
            confirmExchangeVoucher,
            ticketPdf: (orderId) => ApiClient.ticketPdf(orderId),
            // Money as the rest of the POS shows it: 46.28 -> "46.28 €".
            euros: (n) => (Number(n) || 0).toFixed(2) + ' €',
            paymentMethods: [{ code: 'cash', label: 'Efectivo' }, { code: 'card', label: 'Tarjeta' }, { code: 'mixed', label: 'Mixto' }],
        };
    },
    template: `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <header style="height:64px; min-height:64px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <button class="tpv-btn" @click="$emit('back')">
                    <icon name="arrow_back" :size="18" /> Volver
                </button>
                <div style="font-weight:700; font-size:17px; letter-spacing:-0.01em; color:var(--text);">Devoluciones</div>
                <div v-if="noTicket" style="color:var(--text2); font-size:14px;">· Sin ticket</div>
                <div v-else-if="selectedOrder" style="color:var(--text2); font-size:14px;">· Pedido #{{ selectedOrder.ps_order_id }}<span v-if="exchangeMode"> · Cambio</span></div>
                <div v-if="!session.online" style="margin-left:auto; display:flex; align-items:center; gap:6px; color:var(--red); font-size:14px; font-weight:600; background:var(--red-bg); border:1px solid var(--red); padding:8px 12px; border-radius:10px;">
                    <icon name="wifi_off" :size="16" /> Sin conexión
                </div>
            </header>

            <!-- Refund without ticket -->
            <div v-if="noTicket" class="tpv-split">
                <div class="tpv-split-main">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div>
                            <div style="font-weight:700; color:var(--text); font-size:18px;">Devolución sin ticket</div>
                            <div style="color:var(--text2); font-size:14px;">Se devuelve el precio actual de cada producto.</div>
                        </div>
                        <button class="tpv-btn" @click="closeNoTicket">Volver a buscar ticket</button>
                    </div>

                    <div v-for="p in pendingNoTicket" :key="p.id" class="tpv-banner tpv-banner-warn">
                        <div style="flex:1; min-width:220px;">
                            Devolución sin ticket #{{ p.id }} de {{ euros(p.total) }} sin confirmar por PrestaShop. Entrega el dinero solo cuando se confirme.
                            <div v-if="p.voucher_unknown" style="font-size:13px; margin-top:4px;">No se sabe si el vale se creó: compruébalo en PrestaShop. No se creará otro automáticamente. {{ p.error }}</div>
                        </div>
                        <button v-if="!p.voucher_unknown" class="tpv-btn tpv-btn-primary" @click="retryNoTicket(p.id)" :disabled="loading">Reintentar</button>
                    </div>

                    <div v-if="lastVoucher" class="tpv-banner tpv-banner-ok" style="font-size:15px;">
                        <icon name="check" :size="20" style="color:var(--green);" />
                        <div style="flex:1;">Vale para el cliente: <strong style="font-family:'IBM Plex Mono';">{{ lastVoucher.code }}</strong> · {{ euros(lastVoucher.amount) }} · válido 1 año, admite uso parcial</div>
                    </div>

                    <div style="display:flex; gap:8px;">
                        <input class="tpv-input" style="flex:1;" v-model="productQuery" @keyup.enter="searchProducts" placeholder="Buscar producto por nombre, referencia o EAN..." />
                        <button class="tpv-btn tpv-btn-primary" @click="searchProducts" :disabled="loading"><icon name="search" :size="18" /> Buscar</button>
                    </div>
                    <div v-if="productResults.length" style="display:flex; flex-direction:column; gap:8px; max-height:280px; overflow:auto;">
                        <button v-for="p in productResults" :key="p.id" class="tpv-row" style="min-height:56px; font-family:inherit; text-align:left; color:var(--text);" @click="pickProduct(p)">
                            <icon name="plus" :size="18" style="color:var(--green);" />
                            <span style="flex:1; font-size:15px;">{{ p.name }} <span v-if="p.has_combinations" style="font-size:12px; color:var(--text2); border:1px solid var(--border2); border-radius:6px; padding:1px 6px; margin-left:6px;">VARIANTES</span></span>
                            <span style="color:var(--text2); font-family:'IBM Plex Mono'; font-size:15px;">{{ euros(p.price) }}</span>
                        </button>
                    </div>
                    <div v-if="combinationChoice" class="tpv-banner tpv-banner-info" style="flex-direction:column; align-items:stretch; gap:8px;">
                        <div class="tpv-label">Elige la variante de {{ combinationChoice.name }}</div>
                        <button v-for="c in combinationChoice.combinations" :key="c.id" class="tpv-row" style="min-height:56px; font-family:inherit; color:var(--text);" @click="pickCombination(c)">
                            <span style="flex:1; text-align:left; font-size:15px;">{{ (c.attributes || []).map(a => a.group_name + ': ' + a.attribute_name).join(' / ') || '—' }}</span>
                            <span style="color:var(--text2); font-family:'IBM Plex Mono'; font-size:15px;">{{ euros(Number(combinationChoice.price) + Number(c.price_impact || 0)) }}</span>
                        </button>
                        <button class="tpv-btn" style="align-self:flex-start;" @click="pickCombination(null)">Cancelar</button>
                    </div>

                    <div class="tpv-label">Productos que trae el cliente</div>
                    <div v-if="noTicketLines.length === 0" style="color:var(--text2); font-size:15px; padding:16px; border:1px dashed var(--border2); border-radius:12px; text-align:center;">Busca y añade los productos que trae el cliente.</div>
                    <div v-for="(line, idx) in noTicketLines" :key="line.product_id + ':' + line.id_product_attribute" class="tpv-row" style="cursor:default; flex-wrap:wrap;">
                        <div style="flex:1; min-width:180px;">
                            <div style="color:var(--text); font-size:15px; font-weight:600;">{{ line.name }}</div>
                            <div style="color:var(--text2); font-size:13px; font-family:'IBM Plex Mono';">{{ euros(line.unitPrice) }} / ud</div>
                        </div>
                        <div class="tpv-stepper">
                            <button class="tpv-step" aria-label="Quitar una unidad" :disabled="line.quantity <= 1" @click="stepNoTicketQty(idx, -1)"><icon name="minus" :size="20" /></button>
                            <span class="tpv-step-value">{{ line.quantity }}</span>
                            <button class="tpv-step" aria-label="Añadir una unidad" @click="stepNoTicketQty(idx, 1)"><icon name="plus" :size="20" /></button>
                        </div>
                        <button class="tpv-toggle" :class="{ 'is-on': line.restock }" role="switch" :aria-checked="line.restock ? 'true' : 'false'" @click="line.restock = !line.restock">
                            <span class="tpv-check" :class="{ 'is-checked': line.restock }"><icon v-if="line.restock" name="check" :size="18" /></span>
                            Reponer stock
                        </button>
                        <button class="tpv-step" aria-label="Quitar producto" style="background:transparent; color:var(--red);" @click="removeNoTicketLine(idx)"><icon name="trash" :size="20" /></button>
                    </div>
                </div>

                <div class="tpv-split-side">
                    <div class="tpv-split-side-body">
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <div class="tpv-label">Motivo</div>
                            <div class="tpv-chip-grid cols-2">
                                <button v-for="r in reasons" :key="r.code" class="tpv-chip" :class="{ 'is-selected': reasonCode === r.code }" :aria-pressed="reasonCode === r.code ? 'true' : 'false'" @click="setReason(r.code)">
                                    {{ r.label }}<span v-if="reasonCode === r.code" class="tpv-chip-mark"><icon name="check" :size="18" /></span>
                                </button>
                            </div>
                            <input v-if="reasonCode === 'other'" class="tpv-input" v-model="reasonText" placeholder="Describe el motivo" maxlength="255" />
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <div class="tpv-label">Cómo se devuelve</div>
                            <div class="tpv-chip-grid cols-3">
                                <button v-for="m in methods" :key="m.code" class="tpv-chip" :class="{ 'is-selected': refundMethod === m.code }" :aria-pressed="refundMethod === m.code ? 'true' : 'false'" @click="setMethod(m.code)">
                                    {{ m.label }}<span v-if="refundMethod === m.code" class="tpv-chip-mark"><icon name="check" :size="18" /></span>
                                </button>
                            </div>
                        </div>
                        <button v-if="needsCardConfirmation()" class="tpv-toggle" :class="{ 'is-on': cardConfirmed }" role="switch" :aria-checked="cardConfirmed ? 'true' : 'false'" style="width:100%; min-height:64px; text-align:left;" @click="cardConfirmed = !cardConfirmed">
                            <span class="tpv-check" :class="{ 'is-checked': cardConfirmed }"><icon v-if="cardConfirmed" name="check" :size="18" /></span>
                            <span style="flex:1;">He hecho la devolución de {{ euros(noTicketTotal()) }} en el datáfono</span>
                        </button>
                    </div>
                    <div class="tpv-split-side-foot">
                        <div v-if="validationError()" style="color:var(--amber); font-size:14px; font-weight:600;">{{ validationError() }}</div>
                        <button class="tpv-action tpv-action-primary" @click="openConfirm" :disabled="loading">
                            <span v-if="loading">Procesando...</span>
                            <span v-else>Devolver</span>
                            <span class="tpv-action-amount">{{ euros(noTicketTotal()) }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Ticket search -->
            <div v-else-if="!selectedOrder" style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; gap:8px;">
                    <input class="tpv-input" style="flex:1;" v-model="searchQuery" @keyup.enter="handleSearch" placeholder="Buscar o escanear: nº de pedido o referencia..." />
                    <button class="tpv-btn tpv-btn-primary" @click="handleSearch" :disabled="loading">
                        <icon name="search" :size="18" />
                        <span v-if="loading">...</span>
                        <span v-else>Buscar</span>
                    </button>
                    <button class="tpv-btn" @click="openNoTicket" :disabled="loading">Sin ticket</button>
                </div>
                <div v-if="lastExchange && lastExchange.saleReference" class="tpv-banner tpv-banner-ok" style="font-size:15px;">
                    <icon name="check" :size="20" style="color:var(--green);" />
                    <div style="flex:1; min-width:220px;">
                        Cambio hecho · Abono <strong style="font-family:'IBM Plex Mono';">{{ lastExchange.creditNote }}</strong> · Venta nueva <strong style="font-family:'IBM Plex Mono';">{{ lastExchange.saleReference }}</strong>
                        <div style="font-size:14px; color:var(--text2);">
                            <span v-if="lastExchange.balance > 0">Cobrado {{ euros(lastExchange.balance) }}<span v-if="lastExchange.changeDue > 0"> · cambio {{ euros(lastExchange.changeDue) }}</span></span>
                            <span v-else-if="lastExchange.balance < 0">Devuelto {{ euros(-lastExchange.balance) }}<span v-if="lastExchange.cashBack > 0"> · {{ euros(lastExchange.cashBack) }} en efectivo</span><span v-if="lastExchange.cardBack > 0"> · {{ euros(lastExchange.cardBack) }} a tarjeta</span><span v-if="lastExchange.webBack > 0"> · {{ euros(lastExchange.webBack) }} pendiente en la web</span></span>
                            <span v-else>Sin diferencia</span>
                        </div>
                    </div>
                    <button v-if="lastExchange.saleOrderId" class="tpv-btn" @click="ticketPdf(lastExchange.saleOrderId)"><icon name="receipt" :size="18" /> Ticket venta (PDF)</button>
                </div>
                <div v-if="searchResults.length === 0 && !loading" style="color:var(--text2); font-size:15px; text-align:center; margin-top:40px;">
                    No hay resultados. Introduce un nº de pedido o referencia y pulsa Buscar.
                </div>
                <button v-for="order in searchResults" :key="order.ps_order_id" class="tpv-row" style="font-family:inherit; text-align:left; flex-direction:column; align-items:stretch; gap:6px;" @click="selectOrder(order)">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <span style="font-weight:600; color:var(--text); font-size:16px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">#{{ order.ps_order_id }} — {{ order.reference }} <span v-if="order.online" class="tpv-tag">Pedido online</span></span>
                        <span style="color:var(--text); font-size:16px; font-family:'IBM Plex Mono';">{{ euros(order.total) }}</span>
                    </div>
                    <div style="color:var(--text2); font-size:14px; display:flex; gap:12px; flex-wrap:wrap;">
                        <span v-if="order.online">{{ order.customer_name }}</span><span v-else>{{ order.payment_method }}</span>
                        <span>{{ order.created_at }}</span>
                        <span v-if="order.online" :style="order.delivered_at ? '' : 'color:var(--amber); font-weight:600;'">{{ deliveredLabel(order) }}</span>
                        <span v-if="order.refunded_amount > 0" style="color:var(--amber); font-weight:600;">Devuelto: {{ euros(order.refunded_amount) }}</span>
                    </div>
                </button>
            </div>

            <!-- Selected ticket: refund or exchange -->
            <div v-else class="tpv-split">
                <div class="tpv-split-main">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div>
                            <div style="font-weight:700; color:var(--text); font-size:18px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">Pedido #{{ selectedOrder.ps_order_id }} <span v-if="isOnline()" class="tpv-tag">Pedido online</span></div>
                            <div style="color:var(--text2); font-size:14px;">{{ selectedOrder.reference }} — {{ selectedOrder.created_at }}<span v-if="isOnline() && selectedOrder.customer_name"> — {{ selectedOrder.customer_name }}</span></div>
                            <div v-if="isOnline()" style="font-size:14px;" :style="selectedOrder.delivered_at ? 'color:var(--text2);' : 'color:var(--amber); font-weight:600;'">{{ deliveredLabel() }}</div>
                        </div>
                        <button class="tpv-btn" @click="backToSearch">Volver a resultados</button>
                    </div>

                    <div v-if="selectedOrder.open_exchange" class="tpv-banner tpv-banner-warn">
                        <div style="flex:1; min-width:220px;">
                            <span v-if="selectedOrder.open_exchange.status === 'pending'">Cambio #{{ selectedOrder.open_exchange.id }} sin confirmar por PrestaShop. No entregues dinero ni artículos hasta completarlo.</span>
                            <span v-else>Cambio #{{ selectedOrder.open_exchange.id }}: el abono está hecho, falta crear la venta nueva de {{ euros(selectedOrder.open_exchange.new_total) }}.</span>
                            <div v-if="selectedOrder.open_exchange.error" style="font-size:13px; margin-top:4px;">{{ selectedOrder.open_exchange.error }}</div>
                        </div>
                        <button class="tpv-btn tpv-btn-primary" @click="retryExchange" :disabled="loading">Reintentar cambio</button>
                        <button v-if="selectedOrder.open_exchange.status === 'credit_open'" @click="closeExchangeWithVoucher" :disabled="loading" class="tpv-btn">Cerrar con vale</button>
                    </div>

                    <div v-if="exchangeVoucherConfirm && selectedOrder.open_exchange" class="tpv-banner tpv-banner-info">
                        <div style="flex:1; min-width:220px;">Se emitirá un vale de {{ euros(selectedOrder.open_exchange.credit_applied) }} (1 año, uso parcial) y el cambio no se podrá reintentar. Comprueba antes en PrestaShop que el pedido nuevo no se creó.</div>
                        <button class="tpv-btn" @click="exchangeVoucherConfirm = false" :disabled="loading">Cancelar</button>
                        <button class="tpv-btn tpv-btn-primary" @click="confirmExchangeVoucher" :disabled="loading">Emitir vale</button>
                    </div>

                    <div v-if="exchangeVoucher" class="tpv-banner tpv-banner-ok" style="font-size:15px;">
                        <icon name="check" :size="20" style="color:var(--green);" />
                        <div style="flex:1;">Vale para el cliente: <strong style="font-family:'IBM Plex Mono';">{{ exchangeVoucher.code }}</strong> · {{ euros(exchangeVoucher.amount) }} · válido 1 año, admite uso parcial</div>
                    </div>

                    <div v-if="selectedOrder.pending_refund && !selectedOrder.open_exchange" class="tpv-banner tpv-banner-warn">
                        <div style="flex:1; min-width:220px;">Devolución #{{ selectedOrder.pending_refund.id }} de {{ euros(selectedOrder.pending_refund.total) }} sin confirmar por PrestaShop. Reinténtala antes de hacer otra; entrega el dinero solo cuando se confirme.</div>
                        <button class="tpv-btn tpv-btn-primary" @click="retryPending" :disabled="loading">Reintentar</button>
                    </div>

                    <div class="tpv-label">{{ exchangeMode ? 'Lo que devuelve el cliente' : 'Líneas del ticket' }}</div>
                    <div v-for="(line, idx) in selectedLines" :key="idx" class="tpv-row" style="flex-wrap:wrap;"
                        :class="{ 'is-selected': line.checked, 'is-disabled': !line.refundable_ok || line.refundable === 0 }"
                        role="checkbox" :aria-checked="line.checked ? 'true' : 'false'" :aria-disabled="!line.refundable_ok || line.refundable === 0 ? 'true' : 'false'"
                        tabindex="0" @click="toggleLine(idx)" @keyup.space.prevent="toggleLine(idx)" @keyup.enter="toggleLine(idx)">
                        <span class="tpv-check" :class="{ 'is-checked': line.checked }"><icon v-if="line.checked" name="check" :size="20" /></span>
                        <div style="flex:1; min-width:180px;">
                            <div style="color:var(--text); font-size:15px; font-weight:600;">{{ line.name }}</div>
                            <div v-if="!line.refundable_ok" style="color:var(--text2); font-size:13px;">Venta anterior a las devoluciones: no se puede devolver desde el TPV</div>
                            <div v-else-if="line.refundable === 0" style="color:var(--text2); font-size:13px;">Ya devuelto</div>
                            <div v-else-if="line.refundable < line.sold" style="color:var(--amber); font-size:13px; font-weight:600;">Quedan {{ line.refundable }} de {{ line.sold }}</div>
                            <div v-if="line.unitPaid !== null" style="color:var(--text2); font-size:13px; font-family:'IBM Plex Mono';">{{ line.sold }} uds × {{ euros(line.unitPaid) }}</div>
                        </div>
                        <template v-if="line.checked">
                            <div class="tpv-stepper" @click.stop @keyup.stop>
                                <button class="tpv-step" aria-label="Quitar una unidad" :disabled="line.refundQty <= 1" @click.stop="stepRefundQty(idx, -1)"><icon name="minus" :size="20" /></button>
                                <span class="tpv-step-value">{{ line.refundQty }}</span>
                                <button class="tpv-step" aria-label="Añadir una unidad" :disabled="line.refundQty >= line.refundable" @click.stop="stepRefundQty(idx, 1)"><icon name="plus" :size="20" /></button>
                            </div>
                            <button class="tpv-toggle" :class="{ 'is-on': line.restock }" role="switch" :aria-checked="line.restock ? 'true' : 'false'" @click.stop="line.restock = !line.restock" @keyup.stop>
                                <span class="tpv-check" :class="{ 'is-checked': line.restock }"><icon v-if="line.restock" name="check" :size="18" /></span>
                                Reponer stock
                            </button>
                        </template>
                    </div>

                    <template v-if="exchangeMode">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:8px;">
                            <div class="tpv-label">Artículos nuevos que se lleva</div>
                            <button class="tpv-btn" @click="cancelExchange">Cancelar cambio</button>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <input class="tpv-input" style="flex:1;" v-model="productQuery" @keyup.enter="searchProducts" placeholder="Buscar producto por nombre, referencia o EAN..." />
                            <button class="tpv-btn tpv-btn-primary" @click="searchProducts" :disabled="loading"><icon name="search" :size="18" /> Buscar</button>
                        </div>
                        <div v-if="productResults.length" style="display:flex; flex-direction:column; gap:8px; max-height:280px; overflow:auto;">
                            <button v-for="p in productResults" :key="p.id" class="tpv-row" style="min-height:56px; font-family:inherit; text-align:left; color:var(--text);" @click="pickProduct(p)">
                                <icon name="plus" :size="18" style="color:var(--green);" />
                                <span style="flex:1; font-size:15px;">{{ p.name }} <span v-if="p.has_combinations" style="font-size:12px; color:var(--text2); border:1px solid var(--border2); border-radius:6px; padding:1px 6px; margin-left:6px;">VARIANTES</span></span>
                                <span style="color:var(--text2); font-family:'IBM Plex Mono'; font-size:15px;">{{ euros(p.price) }}</span>
                            </button>
                        </div>
                        <div v-if="combinationChoice" class="tpv-banner tpv-banner-info" style="flex-direction:column; align-items:stretch; gap:8px;">
                            <div class="tpv-label">Elige la variante de {{ combinationChoice.name }}</div>
                            <button v-for="c in combinationChoice.combinations" :key="c.id" class="tpv-row" style="min-height:56px; font-family:inherit; color:var(--text);" @click="pickCombination(c)">
                                <span style="flex:1; text-align:left; font-size:15px;">{{ (c.attributes || []).map(a => a.group_name + ': ' + a.attribute_name).join(' / ') || '—' }}</span>
                                <span style="color:var(--text2); font-family:'IBM Plex Mono'; font-size:15px;">{{ euros(Number(combinationChoice.price) + Number(c.price_impact || 0)) }}</span>
                            </button>
                            <button class="tpv-btn" style="align-self:flex-start;" @click="pickCombination(null)">Cancelar</button>
                        </div>
                        <div v-if="exchangeLines.length === 0" style="color:var(--text2); font-size:15px; padding:16px; border:1px dashed var(--border2); border-radius:12px; text-align:center;">Añade lo que se lleva el cliente.</div>
                        <div v-for="(line, idx) in exchangeLines" :key="line.product_id + ':' + line.id_product_attribute" class="tpv-row" style="cursor:default; flex-wrap:wrap;">
                            <div style="flex:1; min-width:180px;">
                                <div style="color:var(--text); font-size:15px; font-weight:600;">{{ line.name }}</div>
                                <div style="color:var(--text2); font-size:13px; font-family:'IBM Plex Mono';">{{ euros(line.unitPrice) }} / ud</div>
                            </div>
                            <div class="tpv-stepper">
                                <button class="tpv-step" aria-label="Quitar una unidad" :disabled="line.quantity <= 1" @click="stepNoTicketQty(idx, -1)"><icon name="minus" :size="20" /></button>
                                <span class="tpv-step-value">{{ line.quantity }}</span>
                                <button class="tpv-step" aria-label="Añadir una unidad" @click="stepNoTicketQty(idx, 1)"><icon name="plus" :size="20" /></button>
                            </div>
                            <button class="tpv-step" aria-label="Quitar artículo" style="background:transparent; color:var(--red);" @click="removeNoTicketLine(idx)"><icon name="trash" :size="20" /></button>
                        </div>
                    </template>
                </div>

                <div class="tpv-split-side">
                    <div class="tpv-split-side-body">
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <div class="tpv-label">Motivo</div>
                            <div class="tpv-chip-grid cols-2">
                                <button v-for="r in reasons" :key="r.code" class="tpv-chip" :class="{ 'is-selected': reasonCode === r.code }" :aria-pressed="reasonCode === r.code ? 'true' : 'false'" @click="setReason(r.code)">
                                    {{ r.label }}<span v-if="reasonCode === r.code" class="tpv-chip-mark"><icon name="check" :size="18" /></span>
                                </button>
                            </div>
                            <input v-if="reasonCode === 'other'" class="tpv-input" v-model="reasonText" placeholder="Describe el motivo" maxlength="255" />
                        </div>

                        <div v-if="exchangeMode" style="display:flex; flex-direction:column; gap:8px;">
                            <div class="tpv-label">Balance del cambio</div>
                            <div style="display:flex; justify-content:space-between; color:var(--text2); font-size:15px;"><span>Abono de lo devuelto</span><span style="font-family:'IBM Plex Mono';">{{ euros(exchangeCredit()) }}</span></div>
                            <div style="display:flex; justify-content:space-between; color:var(--text2); font-size:15px;"><span>Total artículos nuevos</span><span style="font-family:'IBM Plex Mono';">{{ euros(exchangeNewTotal()) }}</span></div>
                            <div style="display:flex; justify-content:space-between; color:var(--text); font-size:18px; font-weight:700;">
                                <span v-if="exchangeBalance() > 0">A cobrar</span><span v-else-if="exchangeBalance() < 0">A devolver</span><span v-else>Sin diferencia</span>
                                <span style="font-family:'IBM Plex Mono';">{{ euros(Math.abs(exchangeBalance())) }}</span>
                            </div>
                            <div v-if="exchangeBalance() < 0" style="color:var(--text2); font-size:15px; display:flex; flex-direction:column; gap:4px;">
                                <div v-if="exchangePayoutSplit().cash > 0">Efectivo de caja: {{ euros(exchangePayoutSplit().cash) }}</div>
                                <div v-if="exchangePayoutSplit().card > 0">A tarjeta (datáfono): {{ euros(exchangePayoutSplit().card) }}</div>
                                <div v-if="exchangePayoutSplit().voucher > 0">En vale (PrestaShop): {{ euros(exchangePayoutSplit().voucher) }}</div>
                                <div v-if="exchangePayoutSplit().web > 0" style="color:var(--text); font-weight:600;">Reembolso pendiente en la web: {{ euros(exchangePayoutSplit().web) }}</div>
                                <div v-if="exchangePayoutSplit().web > 0">No se entrega dinero en caja: la oficina lo reembolsa desde la web.</div>
                            </div>
                            <template v-if="exchangeBalance() > 0">
                                <div class="tpv-label" style="margin-top:8px;">Cómo paga la diferencia</div>
                                <div class="tpv-chip-grid cols-3">
                                    <button v-for="m in paymentMethods" :key="m.code" class="tpv-chip" :class="{ 'is-selected': exchangePayment === m.code }" :aria-pressed="exchangePayment === m.code ? 'true' : 'false'" @click="setExchangePayment(m.code)">
                                        {{ m.label }}<span v-if="exchangePayment === m.code" class="tpv-chip-mark"><icon name="check" :size="18" /></span>
                                    </button>
                                </div>
                                <input v-if="exchangePayment === 'cash'" class="tpv-input" v-model="exchangeTendered" type="number" inputmode="decimal" min="0" step="0.01" placeholder="Efectivo entregado" />
                                <div v-if="exchangePayment === 'cash' && Number(exchangeTendered) > exchangeBalance()" style="color:var(--text); font-size:16px; font-weight:600;">Cambio: {{ euros(Number(exchangeTendered) - exchangeBalance()) }}</div>
                                <input v-if="exchangePayment === 'mixed'" class="tpv-input" v-model="exchangeCash" type="number" inputmode="decimal" min="0" step="0.01" placeholder="Parte en efectivo (el resto con tarjeta)" />
                            </template>
                        </div>

                        <div v-else style="display:flex; flex-direction:column; gap:8px;">
                            <div class="tpv-label">Cómo se devuelve</div>
                            <div v-if="estimatedTotal() === 0" style="color:var(--text2); font-size:15px;">Marca las líneas que devuelve el cliente.</div>
                            <div v-if="estimatedSplit().cash > 0" style="color:var(--text); font-size:15px;">Efectivo de caja: {{ estimatedSplit().cash.toFixed(2) }} €</div>
                            <div v-if="estimatedSplit().card > 0" style="color:var(--text); font-size:15px;">A tarjeta (datáfono): {{ estimatedSplit().card.toFixed(2) }} €</div>
                            <div v-if="estimatedSplit().voucher > 0" style="color:var(--text); font-size:15px;">En vale (PrestaShop): {{ estimatedSplit().voucher.toFixed(2) }} €</div>
                            <template v-if="estimatedSplit().web > 0">
                                <div style="color:var(--text); font-size:16px; font-weight:600;">Reembolso pendiente en la web: {{ euros(estimatedSplit().web) }}</div>
                                <div style="color:var(--text2); font-size:14px;">No se entrega dinero en caja: la oficina lo reembolsa desde la web.</div>
                            </template>
                        </div>

                        <button v-if="needsCardConfirmation()" class="tpv-toggle" :class="{ 'is-on': cardConfirmed }" role="switch" :aria-checked="cardConfirmed ? 'true' : 'false'" style="width:100%; min-height:64px; text-align:left;" @click="cardConfirmed = !cardConfirmed">
                            <span class="tpv-check" :class="{ 'is-checked': cardConfirmed }"><icon v-if="cardConfirmed" name="check" :size="18" /></span>
                            <span style="flex:1;">He hecho la devolución de {{ euros(cardRefundAmount()) }} en el datáfono</span>
                        </button>
                    </div>

                    <div class="tpv-split-side-foot">
                        <div v-if="validationError()" style="color:var(--amber); font-size:14px; font-weight:600;">{{ validationError() }}</div>
                        <div style="display:flex; gap:8px;">
                            <button v-if="!exchangeMode" class="tpv-action tpv-action-secondary" style="flex:1;" @click="startExchange" :disabled="loading || Boolean(selectedOrder.open_exchange)">Cambiar</button>
                            <button class="tpv-action tpv-action-primary" style="flex:2;" @click="openConfirm" :disabled="loading">
                                <span v-if="loading">Procesando...</span>
                                <span v-else-if="exchangeMode">Confirmar cambio</span>
                                <span v-else>Devolver</span>
                                <span class="tpv-action-amount">{{ exchangeMode ? euros(Math.abs(exchangeBalance())) : euros(estimatedTotal()) }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="confirmModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border2); border-radius:14px; padding:24px; max-width:440px; width:90%; display:flex; flex-direction:column; gap:16px;">
                    <div style="font-weight:700; color:var(--text); font-size:18px;">{{ exchangeMode ? 'Confirmar cambio' : 'Confirmar devolución' }}</div>
                    <div v-if="exchangeMode" style="color:var(--text2); font-size:15px; line-height:1.45;">Se devuelven artículos del pedido <strong style="color:var(--text);">#{{ selectedOrder.ps_order_id }}</strong> por unos {{ euros(exchangeCredit()) }} y se venden artículos nuevos por {{ euros(exchangeNewTotal()) }}: <strong style="color:var(--text);">{{ exchangeBalance() > 0 ? 'a cobrar ' + euros(exchangeBalance()) : (exchangeBalance() < 0 ? 'a devolver ' + euros(-exchangeBalance()) : 'sin diferencia') }}</strong>. Los importes exactos los calcula el sistema.</div>
                    <div v-else-if="noTicket" style="color:var(--text2); font-size:15px; line-height:1.45;">Se va a devolver aproximadamente <strong style="color:var(--text);">{{ euros(noTicketTotal()) }}</strong> sin ticket ({{ (methods.find(m => m.code === refundMethod) || {}).label }}). El importe exacto es el precio actual del catálogo.</div>
                    <div v-else-if="isOnline()" style="color:var(--text2); font-size:15px; line-height:1.45;">Se van a devolver artículos por aproximadamente <strong style="color:var(--text);">{{ euros(estimatedTotal()) }}</strong> del pedido online <strong style="color:var(--text);">#{{ selectedOrder.ps_order_id }}</strong>. <strong style="color:var(--text);">No entregues dinero:</strong> queda como reembolso pendiente en la web.</div>
                    <div v-else style="color:var(--text2); font-size:15px; line-height:1.45;">Se va a devolver aproximadamente <strong style="color:var(--text);">{{ euros(estimatedTotal()) }}</strong> del pedido <strong style="color:var(--text);">#{{ selectedOrder.ps_order_id }}</strong>. El importe exacto lo calcula el sistema con lo que se pagó.</div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button class="tpv-btn" @click="cancelConfirm">Cancelar</button>
                        <button class="tpv-btn tpv-btn-primary" style="min-width:140px;" @click="confirmRefund" :disabled="loading">Confirmar</button>
                    </div>
                </div>
            </div>
        </div>
    `
};
