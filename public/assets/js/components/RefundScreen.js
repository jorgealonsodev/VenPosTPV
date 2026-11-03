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
            if (loading.value || confirmModal.value || noTicket.value) return undefined;
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

        function addNoTicketLine(product, combo) {
            const attributeId = combo ? Number(combo.id) : 0;
            const existing = noTicketLines.value.find((l) => l.product_id === Number(product.id) && l.id_product_attribute === attributeId);
            if (existing) {
                existing.quantity += 1;
                return;
            }
            const label = combo && Array.isArray(combo.attributes) && combo.attributes.length
                ? ' (' + combo.attributes.map((a) => a.group_name + ': ' + a.attribute_name).join(' / ') + ')'
                : '';
            noTicketLines.value.push({
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
            const line = noTicketLines.value[idx];
            if (line) {
                line.quantity = Math.max(1, parseInt(qty, 10) || 1);
            }
        }

        function removeNoTicketLine(idx) {
            noTicketLines.value.splice(idx, 1);
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
            const total = estimatedTotal();
            const order = selectedOrder.value || {};
            const tender = order.tender || {};
            let cash = Number(tender.cash || 0);
            let card = Number(tender.card || 0);
            if (cash + card <= 0) {
                cash = order.payment_method === 'card' ? 0 : 1;
                card = 1 - cash;
            }
            const cashPart = round2(total * cash / (cash + card));
            return { cash: cashPart, card: round2(total - cashPart) };
        }

        function needsCardConfirmation() {
            if (noTicket.value) return refundMethod.value === 'card' && noTicketTotal() > 0;
            return estimatedSplit().card > 0;
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
            setReason,
            estimatedTotal,
            estimatedSplit,
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
            removeNoTicketLine,
            setMethod,
            noTicketTotal,
            retryNoTicket,
        };
    },
    template: `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <header style="height:52px; min-height:52px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <button @click="$emit('back')" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px; display:flex; align-items:center; gap:4px;">
                    <icon name="arrow_back" :size="14" /> Volver
                </button>
                <div style="font-weight:700; font-size:15px; letter-spacing:-0.01em; color:var(--text);">Devoluciones</div>
                <div v-if="!session.online" style="margin-left:auto; display:flex; align-items:center; gap:5px; color:var(--red); font-size:12px; background:var(--red-bg); padding:4px 10px; border-radius:8px;">
                    <icon name="wifi_off" :size="13" /> Sin conexión
                </div>
            </header>

            <div v-if="noTicket" style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-weight:700; color:var(--text); font-size:16px;">Devolución sin ticket</div>
                        <div style="color:var(--text2); font-size:12px;">Se devuelve el precio actual de cada producto.</div>
                    </div>
                    <button @click="closeNoTicket" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Volver a buscar ticket</button>
                </div>

                <div v-for="p in pendingNoTicket" :key="p.id" style="background:var(--red-bg); border:1px solid var(--red); border-radius:10px; padding:12px; display:flex; align-items:center; gap:12px; color:var(--text); font-size:13px;">
                    <div style="flex:1;">
                        Devolución sin ticket #{{ p.id }} de {{ Number(p.total).toFixed(2) }} € sin confirmar por PrestaShop. Entrega el dinero solo cuando se confirme.
                        <div v-if="p.voucher_unknown" style="font-size:12px; margin-top:4px;">No se sabe si el vale se creó: compruébalo en PrestaShop. No se creará otro automáticamente. {{ p.error }}</div>
                    </div>
                    <button v-if="!p.voucher_unknown" @click="retryNoTicket(p.id)" :disabled="loading" style="padding:8px 14px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Reintentar</button>
                </div>

                <div v-if="lastVoucher" style="background:var(--surface); border:2px solid var(--green); border-radius:10px; padding:12px; color:var(--text); font-size:14px;">
                    Vale para el cliente: <strong style="font-family:'IBM Plex Mono';">{{ lastVoucher.code }}</strong> · {{ lastVoucher.amount.toFixed(2) }} € · válido 1 año, admite uso parcial
                </div>

                <div style="display:flex; gap:8px;">
                    <input v-model="productQuery" @keyup.enter="searchProducts" placeholder="Buscar producto por nombre, referencia o EAN..." style="flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                    <button @click="searchProducts" :disabled="loading" style="padding:8px 16px; border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Buscar</button>
                </div>
                <div v-if="productResults.length" style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:8px; display:flex; flex-direction:column; gap:4px; max-height:200px; overflow:auto;">
                    <button v-for="p in productResults" :key="p.id" @click="pickProduct(p)" style="display:flex; justify-content:space-between; gap:8px; padding:6px 8px; border:none; border-radius:6px; background:transparent; color:var(--text); cursor:pointer; font-family:inherit; font-size:13px; text-align:left;">
                        <span>{{ p.name }} <span v-if="p.has_combinations" style="font-size:10px; color:var(--text3);">VARS</span></span>
                        <span style="color:var(--text2);">{{ Number(p.price).toFixed(2) }} €</span>
                    </button>
                </div>
                <div v-if="combinationChoice" style="background:var(--surface); border:1px solid var(--primary); border-radius:10px; padding:8px; display:flex; flex-direction:column; gap:4px;">
                    <div style="color:var(--text2); font-size:12px; font-weight:600;">Elige la variante de {{ combinationChoice.name }}</div>
                    <button v-for="c in combinationChoice.combinations" :key="c.id" @click="pickCombination(c)" style="display:flex; justify-content:space-between; padding:6px 8px; border:1px solid var(--border); border-radius:6px; background:transparent; color:var(--text); cursor:pointer; font-family:inherit; font-size:13px;">
                        <span>{{ (c.attributes || []).map(a => a.group_name + ': ' + a.attribute_name).join(' / ') || '—' }}</span>
                        <span style="color:var(--text2);">{{ (Number(combinationChoice.price) + Number(c.price_impact || 0)).toFixed(2) }} €</span>
                    </button>
                    <button @click="pickCombination(null)" style="padding:4px 8px; border:none; background:transparent; color:var(--text3); cursor:pointer; font-family:inherit; font-size:12px;">Cancelar</button>
                </div>

                <div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div v-if="noTicketLines.length === 0" style="color:var(--text3); font-size:13px;">Añade los productos que trae el cliente.</div>
                    <div v-for="(line, idx) in noTicketLines" :key="line.product_id + ':' + line.id_product_attribute" style="display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid var(--border);">
                        <div style="flex:1; color:var(--text); font-size:13px;">{{ line.name }}</div>
                        <div style="color:var(--text2); font-size:12px;">{{ line.unitPrice.toFixed(2) }} €</div>
                        <input type="number" :value="line.quantity" @input="updateNoTicketQty(idx, $event.target.value)" min="1" style="width:60px; padding:4px 6px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; text-align:center;" />
                        <label style="display:flex; align-items:center; gap:4px; color:var(--text2); font-size:12px; cursor:pointer;">
                            <input type="checkbox" v-model="line.restock" /> Reponer stock
                        </label>
                        <button @click="removeNoTicketLine(idx)" style="background:transparent; border:none; color:var(--text3); cursor:pointer; font-size:16px;">✕</button>
                    </div>
                </div>

                <div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div style="color:var(--text2); font-size:12px; font-weight:600;">Motivo</div>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        <button v-for="r in reasons" :key="r.code" @click="setReason(r.code)" :style="{ background: reasonCode === r.code ? 'var(--primary)' : 'transparent', color: reasonCode === r.code ? '#fff' : 'var(--text2)' }" style="padding:6px 10px; border-radius:8px; border:1px solid var(--border); cursor:pointer; font-family:inherit; font-size:12px;">{{ r.label }}</button>
                    </div>
                    <input v-if="reasonCode === 'other'" v-model="reasonText" placeholder="Describe el motivo" maxlength="255" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                    <div style="color:var(--text2); font-size:12px; font-weight:600;">Cómo se devuelve</div>
                    <div style="display:flex; gap:6px;">
                        <button v-for="m in methods" :key="m.code" @click="setMethod(m.code)" :style="{ background: refundMethod === m.code ? 'var(--primary)' : 'transparent', color: refundMethod === m.code ? '#fff' : 'var(--text2)' }" style="padding:6px 14px; border-radius:8px; border:1px solid var(--border); cursor:pointer; font-family:inherit; font-size:13px;">{{ m.label }}</button>
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px;">
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Total devolución: {{ noTicketTotal().toFixed(2) }} €</div>
                </div>

                <label v-if="needsCardConfirmation()" style="display:flex; align-items:center; gap:8px; color:var(--text); font-size:13px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer;">
                    <input type="checkbox" v-model="cardConfirmed" style="width:18px; height:18px;" />
                    He hecho la devolución de {{ noTicketTotal().toFixed(2) }} € en el datáfono
                </label>

                <button @click="openConfirm" :disabled="loading" style="padding:10px 16px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                    <span v-if="loading">Procesando...</span>
                    <span v-else>Confirmar devolución</span>
                </button>
            </div>

            <div v-else-if="!selectedOrder" style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; gap:8px;">
                    <input v-model="searchQuery" @keyup.enter="handleSearch" placeholder="Buscar o escanear: nº de pedido o referencia..." style="flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                    <button @click="handleSearch" :disabled="loading" style="padding:8px 16px; border-radius:8px; border:none; background:var(--primary); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                        <span v-if="loading">...</span>
                        <span v-else>Buscar</span>
                    </button>
                    <button @click="openNoTicket" :disabled="loading" style="padding:8px 16px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text); cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Sin ticket</button>
                </div>
                <div v-if="searchResults.length === 0 && !loading" style="color:var(--text3); font-size:13px; text-align:center; margin-top:40px;">
                    No hay resultados. Introduce un nº de pedido o referencia y pulsa Buscar.
                </div>
                <div v-for="order in searchResults" :key="order.ps_order_id" @click="selectOrder(order)" style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; display:flex; flex-direction:column; gap:4px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; color:var(--text); font-size:14px;">#{{ order.ps_order_id }} — {{ order.reference }}</span>
                        <span style="color:var(--text2); font-size:12px;">{{ Number(order.total).toFixed(2) }} €</span>
                    </div>
                    <div style="color:var(--text3); font-size:12px; display:flex; gap:10px;">
                        <span>{{ order.payment_method }}</span>
                        <span>{{ order.created_at }}</span>
                        <span v-if="order.refunded_amount > 0" style="color:var(--red);">Devuelto: {{ Number(order.refunded_amount).toFixed(2) }} €</span>
                    </div>
                </div>
            </div>

            <div v-else style="flex:1; display:flex; flex-direction:column; padding:16px; gap:12px; overflow:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-weight:700; color:var(--text); font-size:16px;">Pedido #{{ selectedOrder.ps_order_id }}</div>
                        <div style="color:var(--text2); font-size:12px;">{{ selectedOrder.reference }} — {{ selectedOrder.created_at }}</div>
                    </div>
                    <button @click="backToSearch" style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 10px; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                        Volver a resultados
                    </button>
                </div>

                <div v-if="selectedOrder.pending_refund" style="background:var(--red-bg); border:1px solid var(--red); border-radius:10px; padding:12px; display:flex; align-items:center; gap:12px; color:var(--text); font-size:13px;">
                    <div style="flex:1;">Devolución #{{ selectedOrder.pending_refund.id }} de {{ Number(selectedOrder.pending_refund.total).toFixed(2) }} € sin confirmar por PrestaShop. Reinténtala antes de hacer otra; entrega el dinero solo cuando se confirme.</div>
                    <button @click="retryPending" :disabled="loading" style="padding:8px 14px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Reintentar</button>
                </div>

                <div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div v-for="(line, idx) in selectedLines" :key="idx" style="display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid var(--border);" :style="{ opacity: line.refundable_ok && line.refundable > 0 ? 1 : 0.5 }">
                        <input type="checkbox" :checked="line.checked" :disabled="!line.refundable_ok || line.refundable === 0" @change="toggleLine(idx)" style="width:18px; height:18px; cursor:pointer;" />
                        <div style="flex:1; color:var(--text); font-size:13px;">
                            {{ line.name }}
                            <div v-if="!line.refundable_ok" style="color:var(--text3); font-size:11px;">Venta anterior a las devoluciones: no se puede devolver desde el TPV</div>
                            <div v-else-if="line.refundable === 0" style="color:var(--text3); font-size:11px;">Ya devuelto</div>
                            <div v-else-if="line.refundable < line.sold" style="color:var(--text3); font-size:11px;">Quedan {{ line.refundable }} de {{ line.sold }}</div>
                        </div>
                        <div v-if="line.unitPaid !== null" style="color:var(--text2); font-size:12px;">{{ line.sold }} uds × {{ line.unitPaid.toFixed(2) }} €</div>
                        <template v-if="line.checked">
                            <input type="number" :value="line.refundQty" @input="updateRefundQty(idx, $event.target.value)" min="1" :max="line.refundable" style="width:60px; padding:4px 6px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; text-align:center;" />
                            <label style="display:flex; align-items:center; gap:4px; color:var(--text2); font-size:12px; cursor:pointer;">
                                <input type="checkbox" v-model="line.restock" /> Reponer stock
                            </label>
                        </template>
                    </div>
                </div>

                <div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div style="color:var(--text2); font-size:12px; font-weight:600;">Motivo</div>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        <button v-for="r in reasons" :key="r.code" @click="setReason(r.code)" :style="{ background: reasonCode === r.code ? 'var(--primary)' : 'transparent', color: reasonCode === r.code ? '#fff' : 'var(--text2)' }" style="padding:6px 10px; border-radius:8px; border:1px solid var(--border); cursor:pointer; font-family:inherit; font-size:12px;">{{ r.label }}</button>
                    </div>
                    <input v-if="reasonCode === 'other'" v-model="reasonText" placeholder="Describe el motivo" maxlength="255" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px;" />
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; gap:12px;">
                    <div style="color:var(--text2); font-size:13px;">
                        <div v-if="estimatedSplit().cash > 0">Efectivo de caja: {{ estimatedSplit().cash.toFixed(2) }} €</div>
                        <div v-if="estimatedSplit().card > 0">A tarjeta (datáfono): {{ estimatedSplit().card.toFixed(2) }} €</div>
                    </div>
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Total devolución: {{ estimatedTotal().toFixed(2) }} €</div>
                </div>

                <label v-if="needsCardConfirmation()" style="display:flex; align-items:center; gap:8px; color:var(--text); font-size:13px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer;">
                    <input type="checkbox" v-model="cardConfirmed" style="width:18px; height:18px;" />
                    He hecho la devolución de {{ estimatedSplit().card.toFixed(2) }} € en el datáfono
                </label>

                <button @click="openConfirm" :disabled="loading" style="padding:10px 16px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;">
                    <span v-if="loading">Procesando...</span>
                    <span v-else>Confirmar devolución</span>
                </button>
            </div>

            <div v-if="confirmModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index:1000;">
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px; max-width:360px; width:90%; display:flex; flex-direction:column; gap:14px;">
                    <div style="font-weight:700; color:var(--text); font-size:16px;">Confirmar devolución</div>
                    <div v-if="noTicket" style="color:var(--text2); font-size:13px;">Se va a devolver aproximadamente <strong>{{ noTicketTotal().toFixed(2) }} €</strong> sin ticket ({{ (methods.find(m => m.code === refundMethod) || {}).label }}). El importe exacto es el precio actual del catálogo.</div>
                    <div v-else style="color:var(--text2); font-size:13px;">Se va a devolver aproximadamente <strong>{{ estimatedTotal().toFixed(2) }} €</strong> del pedido <strong>#{{ selectedOrder.ps_order_id }}</strong>. El importe exacto lo calcula el sistema con lo que se pagó.</div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button @click="cancelConfirm" style="padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">Cancelar</button>
                        <button @click="confirmRefund" :disabled="loading" style="padding:8px 14px; border-radius:8px; border:none; background:var(--red); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">Confirmar</button>
                    </div>
                </div>
            </div>
        </div>
    `
};
