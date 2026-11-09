/**
 * PaymentModal — cash/card/mixed/voucher payment methods with numpad and change display.
 */
const PaymentModal = {
    props: ['total'],
    emits: ['complete', 'close', 'error'],
    components: typeof WhatsAppShare !== 'undefined' ? { 'whatsapp-share': WhatsAppShare } : {},
    setup(props, { emit }) {
        const method = Vue.ref('cash');
        const cashInput = Vue.ref('');
        const card2 = Vue.ref('');
        // In a mixed payment the cashier types the cash part and the card covers the
        // rest of the total, so the card amount is derived instead of typed.
        const card1 = Vue.computed(() => {
            const cash = parseFloat(String(card2.value).replace(',', '.')) || 0;
            const remaining = Math.round((props.total - cash) * 100) / 100;

            return remaining > 0 ? remaining.toFixed(2).replace('.', ',') : '';
        });
        const step = Vue.ref('select'); // select | done | error
        const errorMsg = Vue.ref('');
        const errorRetry = Vue.ref(false);
        const lastOrderId = Vue.ref(null);
        // Local sale id: the ticket exists before the sale is uploaded to PrestaShop.
        const lastSaleId = Vue.ref(null);
        // Ticket by email when the till has no printer (Ajustes → Email).
        const emailEnabled = Vue.ref(false);
        const ticketEmail = Vue.ref('');
        // «WhatsApp» next to the email: a link to the ticket PDF (WhatsAppShare).
        const ticketPhone = Vue.ref('');
        const whatsappOpen = Vue.ref(false);
        const emailStatus = Vue.ref('');
        const emailSending = Vue.ref(false);
        const voucherCode = Vue.ref('');
        const voucherPreview = Vue.ref(null);
        const voucherBusy = Vue.ref(false);
        const voucherError = Vue.ref('');
        const voucherTender = Vue.ref('cash');
        const voucherCashInput = Vue.ref('');
        const voucherPreviewRevision = Vue.ref(0);
        const voucherReconciliationRequired = Vue.ref(false);
        // PRD V5: PrestaShop did not answer for the coupon; nothing was booked and the
        // same cart can be charged without it ("Cobrar sin cupón").
        const couponOffline = Vue.ref(false);
        const completedTotal = Vue.ref(null);
        const completedChange = Vue.ref(null);
        const isSubmitting = Vue.ref(false);
        // One id per payment attempt, kept on every retry of it and on the replay
        // api.js queues when the network drops: /api/checkout then returns the sale
        // it already created instead of charging twice.
        let attemptRequestId = null;

        function newRequestId() {
            if (typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }
            let id = 'pay-' + Date.now().toString(36);
            while (id.length < 36) id += Math.floor(Math.random() * 36).toString(36);
            return id;
        }

        // `icon` names an <icon> line icon (Icon.js), not an emoji.
        const methods = [
            { id: 'cash', label: 'Efectivo', icon: 'cash' },
            { id: 'card', label: 'Tarjeta', icon: 'card' },
            { id: 'mixed', label: 'Mixto', icon: 'split' },
            { id: 'voucher', label: 'Vale regalo', icon: 'gift' },
        ];
        // Keypad keys, emitting the same tokens appendNum() always received from <numpad>.
        const keypadKeys = ['7', '8', '9', '4', '5', '6', '1', '2', '3', 'decimal', '0', 'backspace'];
        const quickAmounts = ['10', '20', '50', '100'];
        /** Display-only Spanish amount: 68.78 -> "68,78 €". Internal values stay numbers. */
        const eur = (n) => (Number(n) || 0).toFixed(2).replace('.', ',') + ' €';

        const cashParsed = Vue.computed(() => {
            return parseFloat(cashInput.value.replace(',', '.')) || 0;
        });

        const change = Vue.computed(() => {
            return Math.max(0, cashParsed.value - props.total);
        });

        const cardParsed = Vue.computed(() => parseFloat(card1.value.replace(',', '.')) || 0);
        const card2Parsed = Vue.computed(() => parseFloat(card2.value.replace(',', '.')) || 0);
        const dataphoneMode = Vue.computed(() => useSession.session.dataphoneMode || 'integrated');
        const printerType = Vue.computed(() => useSession.session.printerType || 'pdf');
        const voucherPayable = Vue.computed(() => voucherPreview.value ? Number(voucherPreview.value.total_paid) : 0);
        const voucherDiscount = Vue.computed(() => voucherPreview.value ? Number(voucherPreview.value.discount_amount) : 0);
        const voucherGross = Vue.computed(() => voucherPreview.value ? Number(voucherPreview.value.before_discount_total) : Number(props.total));
        const voucherCashParsed = Vue.computed(() => parseFloat(String(voucherCashInput.value).replace(',', '.')) || 0);
        const voucherChange = Vue.computed(() => Math.max(0, voucherCashParsed.value - voucherPayable.value));
        const doneTotal = Vue.computed(() => completedTotal.value === null ? Number(props.total) : completedTotal.value);
        const doneChange = Vue.computed(() => completedChange.value === null ? change.value : completedChange.value);
        let activeVoucherSnapshot = null;

        // Print agent (docs/prd/agente-impresion.md C1-C3): the checkout queued the
        // ticket and the drawer; usePrintJobs follows them. The done screen stays until
        // the ticket is sent or the cashier moves on with "Nueva venta".
        const printJobs = typeof usePrintJobs !== 'undefined' ? usePrintJobs : null;
        const drawerWarning = Vue.computed(() => (printJobs && printerType.value === 'agent' ? printJobs.drawerWarning() : ''));
        let completedSale = null;
        let completeEmitted = false;
        let followsTicket = false;
        let followsDrawer = false;

        function finish() {
            if (completeEmitted || !completedSale) return;
            completeEmitted = true;
            emit('complete', completedSale);
        }

        function agentSettled() {
            const ticket = printJobs.ticket.job;
            const drawer = printJobs.drawer.job;
            return (!followsTicket || (ticket && ticket.phase === 'sent'))
                && (!followsDrawer || (drawer && drawer.phase === 'opened'));
        }

        function closeWhenPrinted() {
            if (completeEmitted) return;
            if (agentSettled()) {
                setTimeout(finish, 2000);
                return;
            }
            setTimeout(closeWhenPrinted, 250);
        }

        function onBackdrop() {
            // A charged sale that waits (agent printing, ticket by email) is completed, never just closed.
            if (step.value === 'done' && completedSale) {
                finish();
                return;
            }
            emit('close');
        }

        function voucherSnapshot(code = voucherCode.value.trim()) {
            return JSON.stringify({
                code,
                lines: useCart.cart.lines.map((line) => ({
                    product_id: line.product_id || line.id,
                    id_product_attribute: line.id_product_attribute || 0,
                    quantity: line.qty || line.quantity || 1,
                    price: line.price,
                    discount: line.discount || 0,
                })),
                subtotal: useCart.cart.subtotal,
                discount: useCart.cart.discount,
                discount_amount: useCart.cart.discount_amount,
                total: useCart.cart.total,
                iva: useCart.cart.iva,
                customer: useCart.cart.customer,
            });
        }

        /** The server's own message inside an ApiClient error ("HTTP 503: {...}"). */
        function serverMessage(msg) {
            const start = msg.indexOf('{');
            if (start < 0) return msg;
            try {
                const body = JSON.parse(msg.slice(start));
                return body && body.error ? body.error : msg;
            } catch (e) {
                return msg;
            }
        }

        function invalidateVoucher() {
            couponOffline.value = false;
            voucherPreviewRevision.value += 1;
            activeVoucherSnapshot = null;
            voucherPreview.value = null;
            voucherError.value = '';
            voucherCashInput.value = '';
        }

        function currentCartData() {
            return {
                lines: useCart.cart.lines,
                subtotal: useCart.cart.subtotal,
                discount: useCart.cart.discount,
                discount_amount: useCart.cart.discount_amount,
                total: useCart.cart.total,
                iva: useCart.cart.iva,
                customer: useCart.cart.customer,
            };
        }

        // docs/prd/articulo-libre.md A9: a PrestaShop coupon cannot be combined with a
        // free item; the checkout refuses it with the same message.
        const FREE_ITEM_COUPON_MESSAGE = 'No se puede usar un cupón con un artículo libre';
        const hasFreeItem = Vue.computed(() => useCart.cart.lines.some((line) => line && line.free_item === true));

        async function applyVoucher() {
            if (hasFreeItem.value) {
                invalidateVoucher();
                voucherError.value = FREE_ITEM_COUPON_MESSAGE;
                return;
            }
            if (voucherReconciliationRequired.value) {
                voucherError.value = 'El resultado del cobro anterior debe reconciliarse antes de validar otro cupón.';
                return;
            }
            const code = voucherCode.value.trim();
            invalidateVoucher();
            if (!code) {
                voucherError.value = 'Introduce un código de cupón.';
                return;
            }
            const previewRevision = voucherPreviewRevision.value;
            const previewSnapshot = voucherSnapshot(code);
            activeVoucherSnapshot = previewSnapshot;
            voucherBusy.value = true;
            try {
                const response = await ApiClient.couponPreview({ coupon_code: code, cart_data: currentCartData() });
                if (!response || !response.data || response.data.operation !== 'coupon_preview') {
                    throw new Error('La validación del cupón no devolvió una cotización válida.');
                }
                if (voucherReconciliationRequired.value || voucherPreviewRevision.value !== previewRevision) {
                    return;
                }
                if (voucherSnapshot(code) !== previewSnapshot) {
                    invalidateVoucher();
                    return;
                }
                voucherPreview.value = response.data;
                voucherCashInput.value = Number(response.data.total_paid).toFixed(2).replace('.', ',');
            } catch (err) {
                if (voucherPreviewRevision.value === previewRevision) {
                    const msg = err.message || 'No se pudo validar el cupón.';
                    couponOffline.value = msg.includes('coupon_offline');
                    voucherError.value = couponOffline.value ? serverMessage(msg) : msg;
                }
            } finally {
                if (voucherPreviewRevision.value === previewRevision) {
                    voucherBusy.value = false;
                }
            }
        }

        Vue.watch(voucherSnapshot, (nextSnapshot) => {
            if (nextSnapshot !== activeVoucherSnapshot) invalidateVoucher();
        });

        function canConfirm() {
            if (isSubmitting.value || voucherReconciliationRequired.value) return false;
            if (method.value === 'cash') return cashParsed.value >= props.total;
            if (method.value === 'card') return true;
            if (method.value === 'mixed') return (cardParsed.value + card2Parsed.value) >= props.total - 0.01;
            if (method.value === 'voucher') {
                return !hasFreeItem.value
                    && !voucherReconciliationRequired.value
                    && voucherPreview.value !== null
                    && (voucherTender.value === 'card' || voucherCashParsed.value >= voucherPayable.value);
            }
            return false;
        }

        async function handleConfirm() {
            if (voucherReconciliationRequired.value || !canConfirm()) return;
            isSubmitting.value = true;
            const isVoucher = method.value === 'voucher';
            const amountTendered = isVoucher
                ? (voucherTender.value === 'cash' ? voucherCashParsed.value : voucherPayable.value)
                : (method.value === 'cash' ? cashParsed.value : (method.value === 'mixed' ? cardParsed.value + card2Parsed.value : props.total));
            const paymentMethod = isVoucher ? voucherTender.value : method.value;
            const cartData = currentCartData();
            cartData.cash_amount = isVoucher
                ? (voucherTender.value === 'cash' ? voucherPayable.value : 0)
                : (method.value === 'cash' ? props.total : (method.value === 'mixed' ? card2Parsed.value : 0));
            cartData.card_amount = isVoucher
                ? (voucherTender.value === 'card' ? voucherPayable.value : 0)
                : (method.value === 'mixed' ? cardParsed.value : (method.value === 'card' ? props.total : 0));
            if (isVoucher) {
                cartData.coupon = {
                    code: voucherPreview.value.coupon.code,
                    preview_cart_id: voucherPreview.value.cart_id,
                    quote: voucherPreview.value.quote,
                };
            }
            const payload = { payment_method: paymentMethod, amount_tendered: amountTendered, cart_data: cartData };
            // Coupon checkouts too: a repeated attempt returns the sale it created.
            if (!attemptRequestId) attemptRequestId = newRequestId();
            payload.request_id = attemptRequestId;

            try {
                const response = isVoucher ? await ApiClient.couponCheckout(payload) : await ApiClient.checkout(payload);
                if (response && response.data) {
                    if (isVoucher && response.data.saga_error === true) {
                        throw new Error('coupon_reconcile_required: el pedido nativo existe, pero el registro local debe reconciliarse antes de cobrar de nuevo.');
                    }
                    lastOrderId.value = response.data.ps_order_id || null;
                    lastSaleId.value = response.data.order_id || null;
                    // Prefill with the customer's email; the account may be configured meanwhile.
                    const customerEmail = (useCart.cart.customer && useCart.cart.customer.email) || '';
                    ticketEmail.value = String(customerEmail).includes('@') ? String(customerEmail) : '';
                    ticketPhone.value = String((useCart.cart.customer && useCart.cart.customer.phone) || '');
                    whatsappOpen.value = false;
                    emailStatus.value = '';
                    completedTotal.value = Object.prototype.hasOwnProperty.call(response.data, 'total')
                        ? Number(response.data.total)
                        : null;
                    completedChange.value = Object.prototype.hasOwnProperty.call(response.data, 'change_due')
                        ? Number(response.data.change_due)
                        : null;
                    const pt = printerType.value;
                    if (pt === 'agent' && printJobs) {
                        completedSale = { ...response.data, payment_method: paymentMethod };
                        followsTicket = printJobs.trackResponse(response.data, {
                            kind: 'sale',
                            saleId: lastSaleId.value,
                            orderId: lastOrderId.value,
                            change: doneChange.value,
                        });
                        followsDrawer = !!response.data.drawer_job_id;
                        step.value = 'done';
                        closeWhenPrinted();
                        return;
                    }
                    if (pt === 'pdf') {
                        downloadTicket();
                    } else if (pt === 'escpos' || pt === 'system') {
                        const printing = lastSaleId.value
                            ? ApiClient.ticketReprintSale(lastSaleId.value)
                            : (lastOrderId.value ? ApiClient.ticketReprint(lastOrderId.value) : null);
                        if (printing) {
                            printing.catch(() => {
                                // ESC/POS print failure is non-blocking
                            });
                        }
                    }
                    step.value = 'done';
                    const doneSale = { ...response.data, payment_method: paymentMethod };
                    // No printer and an email account: stay open so the ticket can be
                    // emailed; "Terminar" (or the backdrop) completes the sale.
                    if ((pt === 'pdf' || pt === 'none') && lastSaleId.value && await ticketEmailEnabled()) {
                        completedSale = doneSale;
                        emailEnabled.value = true;
                        return;
                    }
                    setTimeout(() => {
                        emit('complete', doneSale);
                    }, 2000);
                } else {
                    throw new Error('Respuesta inesperada del servidor');
                }
            } catch (err) {
                const msg = err.message || 'Error al procesar el pago';
                if (msg.includes('cash_session_closed')) {
                    // The server is authoritative: leave sale rather than offering a stale checkout retry.
                    await useSession.hydrateCashSession();
                }
                // coupon_offline: the server knows PrestaShop never got the order and
                // booked nothing, so the cart may be charged without the coupon.
                const offline = isVoucher && msg.includes('coupon_offline');
                couponOffline.value = offline;
                // Any other coupon checkout failure may have reached PrestaShop even when
                // the browser cannot classify it. Lock every such outcome rather than
                // inferring safety from an English transport error message.
                const couponResultUnknown = isVoucher && !offline;
                if (couponResultUnknown) {
                    voucherReconciliationRequired.value = true;
                    invalidateVoucher();
                }
                // Safe to retry: the request_id makes a repeated attempt return the same sale.
                const retry = !isVoucher && (msg.includes('502') || msg.includes('Unavailable') || msg.includes('network')
                    || msg.includes('Failed to fetch') || msg.includes('NetworkError'));
                errorMsg.value = couponResultUnknown
                    ? msg + ' No reintentes automáticamente; reconcilia el resultado antes de volver a cobrar.'
                    : (offline ? serverMessage(msg) : msg);
                errorRetry.value = retry;
                step.value = 'error';
            } finally {
                isSubmitting.value = false;
            }
        }

        async function ticketEmailEnabled() {
            if (typeof ApiClient.get !== 'function') return false;
            try {
                const r = await ApiClient.get('/api/ticket/email-enabled');
                return !!(r && r.data && r.data.enabled);
            } catch (e) {
                return false;
            }
        }

        async function sendTicketEmail() {
            if (!lastSaleId.value || emailSending.value) return;
            emailSending.value = true;
            emailStatus.value = '';
            try {
                await ApiClient.post('/api/ticket/sale/' + lastSaleId.value + '/email', { email: ticketEmail.value });
                emailStatus.value = 'Ticket enviado a ' + ticketEmail.value;
            } catch (e) {
                const match = /^HTTP \d+: ([\s\S]*)$/.exec((e && e.message) || '');
                let text = 'No se pudo enviar el ticket';
                if (match) { try { text = JSON.parse(match[1]).error || text; } catch (err) { /* keep default */ } }
                emailStatus.value = text;
            } finally {
                emailSending.value = false;
            }
        }

        function downloadTicket() {
            if (lastSaleId.value) {
                ApiClient.ticketSalePdf(lastSaleId.value);
            } else if (lastOrderId.value) {
                ApiClient.ticketPdf(lastOrderId.value);
            }
        }

        /**
         * "Cobrar sin cupón": drops the coupon and charges the same cart through the
         * normal local-first sale, with the tender the cashier had chosen. A cash
         * amount that no longer covers the full total goes back to the keypad.
         */
        function chargeWithoutCoupon() {
            if (!couponOffline.value || voucherReconciliationRequired.value || isSubmitting.value) return;
            const tender = voucherTender.value;
            const handed = voucherCashInput.value;
            couponOffline.value = false;
            invalidateVoucher();
            voucherCode.value = '';
            errorMsg.value = '';
            // A different cart total: a new payment attempt.
            attemptRequestId = null;
            const atCheckout = step.value === 'error';
            step.value = 'select';
            method.value = tender === 'card' ? 'card' : 'cash';
            if (method.value === 'cash') cashInput.value = handed;
            if (atCheckout && canConfirm()) return handleConfirm();
            return undefined;
        }

        function retryCheckout() {
            if (voucherReconciliationRequired.value) return;
            step.value = 'select';
            errorMsg.value = '';
            // Same attempt, same request_id.
            return handleConfirm();
        }

        function returnToSelection() {
            step.value = 'select';
            if (voucherReconciliationRequired.value) {
                invalidateVoucher();
                voucherError.value = 'El resultado del cobro anterior debe reconciliarse antes de cobrar de nuevo.';
            }
        }

        // In a mixed payment the keypad types the cash part; the card amount is typed
        // directly into its field. Before this the keypad wrote to a field mixed
        // payments do not use, so every keypad button looked broken.
        function appendNum(k) {
            const target = method.value === 'mixed' ? card2 : cashInput;

            if (k === 'backspace') {
                target.value = String(target.value).slice(0, -1);
                return;
            }
            if (k === 'decimal') {
                if (!String(target.value).includes(',')) {
                    target.value = (String(target.value) || '0') + ',';
                }
                return;
            }
            if (k.startsWith('preset:')) {
                // Banknote buttons add up: 20 + 20 + 10 = 50 handed over.
                const current = Number(String(target.value || '0').replace(',', '.')) || 0;
                const sum = Math.round((current + Number(k.split(':')[1])) * 100) / 100;
                target.value = Number.isInteger(sum) ? String(sum) : sum.toFixed(2).replace('.', ',');
                return;
            }
            target.value = String(target.value) + k;
        }

        function exacto() {
            if (method.value === 'mixed') {
                // The cash part covers whatever the card does not.
                const remaining = Math.max(0, Math.round((props.total - cardParsed.value) * 100) / 100);
                card2.value = remaining > 0 ? remaining.toFixed(2).replace('.', ',') : '';

                return;
            }

            cashInput.value = props.total.toFixed(2).replace('.', ',');
        }

        return { method, cashInput, card1, card2, step, errorMsg, errorRetry, lastOrderId, lastSaleId, methods, cashParsed, change, cardParsed, card2Parsed, dataphoneMode, printerType, voucherCode, voucherPreview, voucherBusy, voucherError, voucherTender, voucherCashInput, voucherPayable, voucherDiscount, voucherGross, voucherCashParsed, voucherChange, voucherReconciliationRequired, couponOffline, chargeWithoutCoupon, completedTotal, completedChange, doneTotal, doneChange, isSubmitting, canConfirm, handleConfirm, retryCheckout, returnToSelection, appendNum, exacto, downloadTicket, emailEnabled, ticketEmail, ticketPhone, whatsappOpen, emailStatus, emailSending, sendTicketEmail, applyVoucher, invalidateVoucher, hasFreeItem, freeItemCouponMessage: FREE_ITEM_COUPON_MESSAGE, keypadKeys, quickAmounts, eur, drawerWarning, finish, onBackdrop };
    },
    template: `
        <div class="modal-backdrop" @click.self="onBackdrop">
            <!-- Done state -->
            <div v-if="step === 'done'" class="pay-result">
                <div class="pay-result-badge pay-result-badge-ok">
                    <icon name="check" :size="32" />
                </div>
                <div class="pay-result-title">Cobro completado</div>
                <div class="pay-result-amount">{{ eur(doneTotal) }}</div>
                <div v-if="(method === 'cash' || (method === 'voucher' && voucherTender === 'cash')) && doneChange > 0" class="pay-result-change">
                    Cambio: <span class="pay-num" style="color:var(--amber); font-weight:700;">{{ eur(doneChange) }}</span>
                </div>
                <div v-if="drawerWarning" class="print-drawer-warning" role="alert">{{ drawerWarning }}</div>
                <template v-if="printerType === 'agent'">
                    <print-status-notice placement="payment" />
                    <button @click="finish" class="tpv-btn tpv-btn-primary print-btn-lg" style="margin-top:4px;">Nueva venta</button>
                </template>
                <div v-else-if="printerType !== 'none'" style="color:var(--text3); font-size:13px;">
                    {{ printerType === 'escpos' ? 'Ticket enviado a impresora' : 'Abriendo ticket PDF...' }}
                </div>
                <button v-if="(lastSaleId || lastOrderId) && printerType === 'pdf'" @click="downloadTicket" class="tpv-btn" style="margin-top:8px;">
                    <icon name="print" :size="18" /> Descargar ticket PDF
                </button>
                <!-- No printer: the ticket can go by email -->
                <div v-if="lastSaleId && emailEnabled && (printerType === 'pdf' || printerType === 'none')"
                    style="display:flex; flex-direction:column; gap:6px; margin-top:10px; width:100%; max-width:340px;">
                    <div style="display:flex; gap:6px;">
                        <input v-model="ticketEmail" type="email" placeholder="email del cliente" aria-label="Email del cliente"
                            @keydown.enter.prevent="sendTicketEmail"
                            style="flex:1; min-width:0; padding:9px 12px; border-radius:9px; border:1px solid var(--border); background:var(--surface2); color:var(--text); font-family:inherit; font-size:14px; outline:none;">
                        <button @click="sendTicketEmail" :disabled="emailSending || !ticketEmail" class="tpv-btn" style="display:flex; align-items:center; gap:6px;">
                            <icon name="mail" :size="16" /> {{ emailSending ? 'Enviando…' : 'Enviar' }}
                        </button>
                        <button @click="whatsappOpen = !whatsappOpen" class="tpv-btn" :aria-expanded="whatsappOpen ? 'true' : 'false'">WhatsApp</button>
                    </div>
                    <div v-if="emailStatus" style="font-size:12px; color:var(--text2);">{{ emailStatus }}</div>
                    <whatsapp-share v-if="whatsappOpen" kind="ticket" :doc-id="lastSaleId" :initial-phone="ticketPhone" />
                    <button @click="finish" class="tpv-btn tpv-btn-primary" style="margin-top:4px;">Terminar</button>
                </div>
            </div>

            <!-- Error state -->
            <div v-else-if="step === 'error'" class="pay-result pay-result-error">
                <div class="pay-result-badge pay-result-badge-error">
                    <icon name="close" :size="28" />
                </div>
                <div class="pay-result-title" style="color:var(--red);">Error en el cobro</div>
                <div style="color:var(--text2); font-size:14px; line-height:1.45;">{{ errorMsg }}</div>
                <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:8px; margin-top:8px;">
                    <button v-if="couponOffline" @click="chargeWithoutCoupon" class="tpv-btn tpv-btn-primary">
                        Cobrar sin cupón
                    </button>
                    <button v-if="errorRetry" @click="retryCheckout" class="tpv-btn tpv-btn-primary">
                        Reintentar
                    </button>
                    <button @click="returnToSelection" class="tpv-btn">
                        Volver
                    </button>
                </div>
            </div>

            <!-- Select state -->
            <div v-else class="pay-modal">
                <!-- Header -->
                <div class="pay-head">
                    <div class="pay-title">Cobrar ticket</div>
                    <div class="pay-total pay-num">{{ eur(total) }}</div>
                    <button @click="$emit('close')" class="pay-close" aria-label="Cerrar">
                        <icon name="close" :size="20" />
                    </button>
                </div>

                <!-- Payment method -->
                <div class="pay-methods" role="radiogroup" aria-label="Método de pago">
                    <button v-for="pm in methods" :key="pm.id" @click="method = pm.id"
                        class="pay-method" :class="{ 'is-selected': method === pm.id }"
                        role="radio" :aria-checked="method === pm.id ? 'true' : 'false'">
                        <icon :name="pm.icon" :size="22" />
                        <span>{{ pm.label }}</span>
                        <span v-if="method === pm.id" class="pay-method-mark"><icon name="check" :size="13" /></span>
                    </button>
                </div>

                <div class="pay-body">
                    <!-- Left: keypad -->
                    <div v-if="method === 'cash' || method === 'mixed'" class="pay-left">
                        <div class="pay-keypad">
                            <button v-for="k in keypadKeys" :key="k" class="pay-key" @click="appendNum(k)"
                                :aria-label="k === 'backspace' ? 'Borrar' : (k === 'decimal' ? 'Coma decimal' : null)">
                                <icon v-if="k === 'backspace'" name="backspace" :size="24" />
                                <template v-else>{{ k === 'decimal' ? ',' : k }}</template>
                            </button>
                        </div>
                        <div class="pay-quick">
                            <button v-for="q in quickAmounts" :key="q" class="tpv-btn pay-quick-btn" @click="appendNum('preset:' + q)">
                                {{ q }} €
                            </button>
                        </div>
                        <button v-if="method === 'cash'" @click="exacto" class="tpv-btn pay-exact">
                            Exacto
                        </button>
                    </div>

                    <!-- Right: details -->
                    <div class="pay-right">
                        <template v-if="method === 'cash'">
                            <div>
                                <div class="tpv-label" style="margin-bottom:8px;">Entrega cliente</div>
                                <div class="pay-display pay-num" :class="{ 'is-empty': !cashInput }">
                                    {{ cashInput || '0,00' }} €
                                </div>
                            </div>
                            <div v-if="cashParsed >= total" class="pay-figure">
                                <span class="pay-figure-label">Cambio a devolver</span>
                                <span class="pay-figure-value pay-num" style="color:var(--amber);">{{ eur(change) }}</span>
                            </div>
                            <div v-else-if="cashParsed > 0" class="pay-figure">
                                <span class="pay-figure-label">Falta por cubrir</span>
                                <span class="pay-figure-value pay-num" style="color:var(--red);">{{ eur(total - cashParsed) }}</span>
                            </div>
                        </template>

                        <template v-if="method === 'card'">
                            <div class="pay-card">
                                <div class="pay-card-icon"><icon name="card" :size="32" /></div>
                                <template v-if="dataphoneMode === 'independent'">
                                    <div style="font-size:17px; font-weight:600;">Tarjeta (independiente)</div>
                                    <div style="color:var(--text2); font-size:14px;">El cobro se realiza manualmente fuera del TPV</div>
                                </template>
                                <template v-else>
                                    <div style="font-size:17px; font-weight:600;">Esperando datáfono…</div>
                                    <div style="color:var(--text2); font-size:14px;">Pase, introduce o aproxime la tarjeta en el terminal</div>
                                </template>
                                <div class="pay-num" style="font-size:28px; font-weight:700; margin-top:4px;">{{ eur(total) }}</div>
                            </div>
                        </template>

                        <template v-if="method === 'voucher'">
                            <div style="flex:1; display:flex; flex-direction:column; gap:12px;">
                                <div class="tpv-label">Cupón de descuento PrestaShop</div>
                                <div v-if="hasFreeItem" role="alert" style="color:var(--red); font-size:14px;">{{ freeItemCouponMessage }}</div>
                                <div v-else style="display:flex; gap:8px;">
                                    <input v-model="voucherCode" @keyup.enter="applyVoucher" placeholder="CÓDIGO DEL CUPÓN"
                                        :disabled="voucherBusy" class="tpv-input"
                                        style="flex:1; font-family:'IBM Plex Mono', monospace; font-size:17px; letter-spacing:0.05em;" />
                                    <button @click="applyVoucher" :disabled="voucherBusy" class="tpv-btn">
                                        {{ voucherBusy ? 'Validando…' : 'Validar y aplicar' }}
                                    </button>
                                </div>
                                <div v-if="voucherError" style="color:var(--red); font-size:13px;">{{ voucherError }}</div>
                                <button v-if="couponOffline" @click="chargeWithoutCoupon" class="tpv-btn tpv-btn-primary">
                                    Cobrar sin cupón
                                </button>
                                <div v-if="voucherPreview" class="pay-voucher">
                                    <div style="font-size:13px; color:var(--text2);">Cupón aplicado como descuento; no es saldo ni medio de pago.</div>
                                    <div class="pay-row"><span>Bruto</span><strong class="pay-num">{{ eur(voucherGross) }}</strong></div>
                                    <div class="pay-row" style="color:var(--green);"><span>Descuento cupón</span><strong class="pay-num">-{{ eur(voucherDiscount) }}</strong></div>
                                    <div class="pay-row pay-row-total"><span>Resta por cobrar</span><strong class="pay-num">{{ eur(voucherPayable) }}</strong></div>
                                    <div class="tpv-chip-grid cols-2" style="margin-top:4px;">
                                        <button @click="voucherTender = 'cash'" class="tpv-chip" :class="{ 'is-selected': voucherTender === 'cash' }">
                                            Efectivo
                                            <span v-if="voucherTender === 'cash'" class="tpv-chip-mark"><icon name="check" :size="16" /></span>
                                        </button>
                                        <button @click="voucherTender = 'card'" class="tpv-chip" :class="{ 'is-selected': voucherTender === 'card' }">
                                            Tarjeta
                                            <span v-if="voucherTender === 'card'" class="tpv-chip-mark"><icon name="check" :size="16" /></span>
                                        </button>
                                    </div>
                                    <template v-if="voucherTender === 'cash'">
                                        <input v-model="voucherCashInput" inputmode="decimal" placeholder="0,00" class="tpv-input pay-num" style="font-size:18px;" />
                                        <div v-if="voucherCashParsed < voucherPayable" style="color:var(--red); font-size:13px;">Faltan {{ eur(voucherPayable - voucherCashParsed) }}</div>
                                        <div v-else-if="voucherChange > 0" style="color:var(--amber); font-size:13px;">Cambio: {{ eur(voucherChange) }}</div>
                                    </template>
                                </div>
                                <div v-else style="font-size:13px; color:var(--text2); line-height:1.45;">Valida el código para obtener el descuento y el importe pendiente. Se invalida si cambia el carrito o cliente.</div>
                            </div>
                        </template>

                        <template v-if="method === 'mixed'">
                            <div style="flex:1; display:flex; flex-direction:column; gap:14px;">
                                <div>
                                    <div class="tpv-label" style="margin-bottom:8px;">Efectivo</div>
                                    <div style="position:relative;">
                                        <input v-model="card2" placeholder="0,00" inputmode="decimal" class="tpv-input pay-display pay-num" style="padding-right:56px;" aria-label="Importe en efectivo en euros" />
                                        <span class="pay-num" aria-hidden="true" style="position:absolute; right:20px; top:50%; transform:translateY(-50%); font-size:30px; color:var(--text2); pointer-events:none;">€</span>
                                    </div>
                                </div>
                                <div>
                                    <div class="tpv-label" style="margin-bottom:8px;">Tarjeta</div>
                                    <div class="pay-display pay-display-readonly pay-num">{{ card1 || '0,00' }} €</div>
                                </div>
                                <div class="pay-covered pay-num"
                                    :style="{ color: (cardParsed + card2Parsed) >= total ? 'var(--green)' : 'var(--red)' }">
                                    <icon v-if="(cardParsed + card2Parsed) >= total" name="check" :size="16" />
                                    Total cubierto: {{ eur(cardParsed + card2Parsed) }}{{ (cardParsed + card2Parsed) >= total ? '' : ' (faltan ' + eur(total - cardParsed - card2Parsed) + ')' }}
                                </div>
                            </div>
                        </template>

                        <button @click="handleConfirm" :disabled="!canConfirm()" class="pay-confirm">
                            {{ isSubmitting ? 'Procesando…' : (method === 'voucher' ? 'Cobrar ' + eur(voucherPayable) + ' tras cupón' : (method === 'card' ? (dataphoneMode === 'independent' ? 'Registrar cobro con tarjeta' : 'Confirmar cobro con tarjeta') : 'Cobrar ' + eur(total))) }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
