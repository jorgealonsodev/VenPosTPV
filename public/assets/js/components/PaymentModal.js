/**
 * PaymentModal — cash/card/mixed/voucher payment methods with numpad and change display.
 */
const PaymentModal = {
    props: ['total'],
    emits: ['complete', 'close', 'error'],
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

        const methods = [
            { id: 'cash', label: 'Efectivo', icon: '💵' },
            { id: 'card', label: 'Tarjeta', icon: '💳' },
            { id: 'mixed', label: 'Mixto', icon: '🔀' },
            { id: 'voucher', label: 'Vale regalo', icon: '🎁' },
        ];

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

        async function applyVoucher() {
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
                return !voucherReconciliationRequired.value
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
                    completedTotal.value = Object.prototype.hasOwnProperty.call(response.data, 'total')
                        ? Number(response.data.total)
                        : null;
                    completedChange.value = Object.prototype.hasOwnProperty.call(response.data, 'change_due')
                        ? Number(response.data.change_due)
                        : null;
                    const pt = printerType.value;
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
                    setTimeout(() => {
                        emit('complete', {
                            ...response.data,
                            payment_method: paymentMethod,
                        });
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
                target.value = k.split(':')[1];
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

        return { method, cashInput, card1, card2, step, errorMsg, errorRetry, lastOrderId, lastSaleId, methods, cashParsed, change, cardParsed, card2Parsed, dataphoneMode, printerType, voucherCode, voucherPreview, voucherBusy, voucherError, voucherTender, voucherCashInput, voucherPayable, voucherDiscount, voucherGross, voucherCashParsed, voucherChange, voucherReconciliationRequired, couponOffline, chargeWithoutCoupon, completedTotal, completedChange, doneTotal, doneChange, isSubmitting, canConfirm, handleConfirm, retryCheckout, returnToSelection, appendNum, exacto, downloadTicket, applyVoucher, invalidateVoucher };
    },
    template: `
        <div class="modal-backdrop" @click.self="$emit('close')">
            <!-- Done state -->
            <div v-if="step === 'done'" style="background:var(--surface); border:1px solid var(--green-dim); border-radius:24px; padding:56px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:16px;">
                <div style="width:72px; height:72px; border-radius:50%; background:var(--green); display:flex; align-items:center; justify-content:center;">
                    <icon name="check" :size="36" />
                </div>
                <div style="font-size:24px; font-weight:700;">Cobro completado</div>
                <div style="font-family:'IBM Plex Mono'; font-size:32px; color:var(--green);">{{ Number(doneTotal).toFixed(2) }}€</div>
                <div v-if="(method === 'cash' || (method === 'voucher' && voucherTender === 'cash')) && doneChange > 0" style="color:var(--text2);">
                    Cambio: <span style="color:var(--amber); font-weight:600; font-family:'IBM Plex Mono';">{{ Number(doneChange).toFixed(2) }}€</span>
                </div>
                <div v-if="printerType !== 'none'" style="color:var(--text3); font-size:13px;">
                    {{ printerType === 'escpos' ? 'Ticket enviado a impresora' : 'Abriendo ticket PDF...' }}
                </div>
                <button v-if="(lastSaleId || lastOrderId) && printerType === 'pdf'" @click="downloadTicket"
                    style="margin-top:8px; padding:10px 20px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-weight:600;">
                    Descargar ticket PDF
                </button>
            </div>

            <!-- Error state -->
            <div v-else-if="step === 'error'" style="background:var(--surface); border:1px solid var(--red); border-radius:24px; padding:48px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:16px; max-width:420px;">
                <div style="width:64px; height:64px; border-radius:50%; background:var(--red-bg); display:flex; align-items:center; justify-content:center;">
                    <icon name="close" :size="28" style="color:var(--red);" />
                </div>
                <div style="font-size:20px; font-weight:700; color:var(--red);">Error en el cobro</div>
                <div style="color:var(--text2); font-size:14px;">{{ errorMsg }}</div>
                <div style="display:flex; gap:12px; margin-top:8px;">
                    <button v-if="couponOffline" @click="chargeWithoutCoupon"
                        style="padding:12px 24px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-weight:600;">
                        Cobrar sin cupón
                    </button>
                    <button v-if="errorRetry" @click="retryCheckout"
                        style="padding:12px 24px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-weight:600;">
                        Reintentar
                    </button>
                    <button @click="returnToSelection"
                        style="padding:12px 24px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); color:var(--text2); cursor:pointer;">
                        Volver
                    </button>
                </div>
            </div>

            <!-- Select state -->
            <div v-else style="background:var(--surface); border:1px solid var(--border); border-radius:24px; width:680px; max-height:90vh; overflow:auto; display:flex; flex-direction:column; gap:0;">
                <!-- Header -->
                <div style="padding:24px 28px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                    <div>
                        <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em;">Cobrar ticket</div>
                        <div style="font-size:28px; font-weight:700; font-family:'IBM Plex Mono'; margin-top:2px;">{{ Number(total).toFixed(2) }}€</div>
                    </div>
                    <button @click="$emit('close')" style="background:var(--surface2); border:none; border-radius:8px; width:36px; height:36px; cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center;">
                        <icon name="close" :size="16" />
                    </button>
                </div>

                <div style="display:flex; gap:0;">
                    <!-- Left: methods + numpad -->
                    <div style="flex:0 0 280px; padding:20px 24px; border-right:1px solid var(--border); display:flex; flex-direction:column; gap:16px;">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <button v-for="pm in methods" :key="pm.id" @click="method = pm.id"
                                style="padding:12px 10px; border-radius:10px; border:1px solid; cursor:pointer; font-family:inherit; font-size:12px; display:flex; flex-direction:column; align-items:center; gap:4px; transition:all .1s;"
                                :style="{ borderColor: method === pm.id ? 'var(--green-dim)' : 'var(--border)', background: method === pm.id ? 'var(--green-bg)' : 'var(--surface2)', color: method === pm.id ? 'var(--green)' : 'var(--text2)', fontWeight: method === pm.id ? 600 : 400 }">
                                <span style="font-size:18px;">{{ pm.icon }}</span>
                                {{ pm.label }}
                            </button>
                        </div>

                        <template v-if="method === 'cash' || method === 'mixed'">
                            <numpad
                                :presets="[{value:'10',label:'10€'},{value:'20',label:'20€'},{value:'50',label:'50€'},{value:'100',label:'100€'}]"
                                :preset-columns="2"
                                @input="appendNum"
                            />
                            <button v-if="method === 'cash'" @click="exacto"
                                style="padding:10px 16px; border-radius:8px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                                Exacto
                            </button>
                        </template>
                    </div>

                    <!-- Right: details -->
                    <div style="flex:1; padding:20px 24px; display:flex; flex-direction:column; gap:16px;">
                        <template v-if="method === 'cash'">
                            <div>
                                <div style="font-size:11px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:8px;">Entrega cliente</div>
                                <div style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:14px 16px; font-family:'IBM Plex Mono'; font-size:26px; font-weight:500; min-height:56px; color: cashInput ? 'var(--text)' : 'var(--text3)';">
                                    {{ cashInput || '0,00' }} €
                                </div>
                            </div>
                            <div v-if="cashParsed >= total" style="background:var(--amber-bg); border:1px solid var(--amber); border-radius:10px; padding:12px 16px;">
                                <div style="font-size:11px; color:var(--amber); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:4px;">Cambio a devolver</div>
                                <div style="font-family:'IBM Plex Mono'; font-size:28px; font-weight:700; color:var(--amber);">{{ Number(change).toFixed(2) }}€</div>
                            </div>
                            <div v-else-if="cashParsed > 0" style="background:var(--red-bg); border:1px solid var(--red); border-radius:10px; padding:12px 16px;">
                                <div style="font-size:11px; color:var(--red); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:4px;">Falta por cubrir</div>
                                <div style="font-family:'IBM Plex Mono'; font-size:28px; font-weight:700; color:var(--red);">{{ (total - cashParsed).toFixed(2) }}€</div>
                            </div>
                        </template>

                        <template v-if="method === 'card'">
                            <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; text-align:center;">
                                <div style="font-size:48px;">💳</div>
                                <template v-if="dataphoneMode === 'independent'">
                                    <div style="font-size:16px; font-weight:600;">Tarjeta (independiente)</div>
                                    <div style="color:var(--text3); font-size:13px;">El cobro se realiza manualmente fuera del TPV</div>
                                </template>
                                <template v-else>
                                    <div style="font-size:16px; font-weight:600;">Esperando datáfono…</div>
                                    <div style="color:var(--text3); font-size:13px;">Pase, introduce o aproxime la tarjeta en el terminal</div>
                                </template>
                                <div style="font-family:'IBM Plex Mono'; font-size:22px; color:var(--blue); margin-top:8px;">{{ Number(total).toFixed(2) }}€</div>
                            </div>
                        </template>

                        <template v-if="method === 'voucher'">
                            <div style="flex:1; display:flex; flex-direction:column; gap:12px;">
                                <div style="font-size:13px; color:var(--text2);">Cupón de descuento PrestaShop</div>
                                <div style="display:flex; gap:8px;">
                                    <input v-model="voucherCode" @keyup.enter="applyVoucher" placeholder="CÓDIGO DEL CUPÓN"
                                        :disabled="voucherBusy"
                                        style="flex:1; background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:13px 16px; color:var(--text); font-family:'IBM Plex Mono'; font-size:17px; outline:none; letter-spacing:0.05em;" />
                                    <button @click="applyVoucher" :disabled="voucherBusy"
                                        style="padding:0 14px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); font-weight:600; cursor:pointer;">
                                        {{ voucherBusy ? 'Validando…' : 'Validar y aplicar' }}
                                    </button>
                                </div>
                                <div v-if="voucherError" style="color:var(--red); font-size:12px;">{{ voucherError }}</div>
                                <button v-if="couponOffline" @click="chargeWithoutCoupon"
                                    style="padding:10px 14px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); font-weight:600; cursor:pointer;">
                                    Cobrar sin cupón
                                </button>
                                <div v-if="voucherPreview" style="background:var(--green-bg); border:1px solid var(--green-dim); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:7px;">
                                    <div style="font-size:12px; color:var(--text2);">Cupón aplicado como descuento; no es saldo ni medio de pago.</div>
                                    <div style="display:flex; justify-content:space-between;"><span>Bruto</span><strong>{{ voucherGross.toFixed(2) }}€</strong></div>
                                    <div style="display:flex; justify-content:space-between; color:var(--green);"><span>Descuento cupón</span><strong>-{{ voucherDiscount.toFixed(2) }}€</strong></div>
                                    <div style="display:flex; justify-content:space-between; font-family:'IBM Plex Mono'; font-size:18px;"><span>Resta por cobrar</span><strong>{{ voucherPayable.toFixed(2) }}€</strong></div>
                                    <div style="display:flex; gap:8px; margin-top:4px;">
                                        <button @click="voucherTender = 'cash'" :style="{ flex:1, padding:'8px', borderRadius:'8px', border:'1px solid var(--border)', background:voucherTender === 'cash' ? 'var(--green-bg)' : 'var(--surface2)', color:'var(--text)', cursor:'pointer' }">Efectivo</button>
                                        <button @click="voucherTender = 'card'" :style="{ flex:1, padding:'8px', borderRadius:'8px', border:'1px solid var(--border)', background:voucherTender === 'card' ? 'var(--green-bg)' : 'var(--surface2)', color:'var(--text)', cursor:'pointer' }">Tarjeta</button>
                                    </div>
                                    <template v-if="voucherTender === 'cash'">
                                        <input v-model="voucherCashInput" inputmode="decimal" placeholder="0,00"
                                            style="background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:10px; color:var(--text); font-family:'IBM Plex Mono';" />
                                        <div v-if="voucherCashParsed < voucherPayable" style="color:var(--red); font-size:12px;">Faltan {{ (voucherPayable - voucherCashParsed).toFixed(2) }}€</div>
                                        <div v-else-if="voucherChange > 0" style="color:var(--amber); font-size:12px;">Cambio: {{ voucherChange.toFixed(2) }}€</div>
                                    </template>
                                </div>
                                <div v-else style="font-size:12px; color:var(--text3);">Valida el código para obtener el descuento y el importe pendiente. Se invalida si cambia el carrito o cliente.</div>
                            </div>
                        </template>

                        <template v-if="method === 'mixed'">
                            <div style="flex:1; display:flex; flex-direction:column; gap:12px;">
                                <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em;">
                                    Total: <span style="font-family:'IBM Plex Mono'; color:var(--text);">{{ Number(total).toFixed(2) }}€</span>
                                </div>
                                <div>
                                    <div style="font-size:12px; color:var(--text2); margin-bottom:6px;">Efectivo</div>
                                    <input v-model="card2" placeholder="0,00" inputmode="decimal"
                                        style="width:100%; background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:12px 16px; font-family:'IBM Plex Mono'; font-size:20px; color:var(--text); outline:none;" />
                                </div>
                                <div>
                                    <div style="font-size:12px; color:var(--text2); margin-bottom:6px;">Tarjeta</div>
                                    <div style="width:100%; background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:12px 16px; font-family:'IBM Plex Mono'; font-size:20px; color:var(--text2);">
                                        {{ card1 || '0,00' }} €
                                    </div>
                                </div>
                                <div style="font-size:12px; font-family:'IBM Plex Mono';"
                                    :style="{ color: (cardParsed + card2Parsed) >= total ? 'var(--green)' : 'var(--red)' }">
                                    Total cubierto: {{ (cardParsed+card2Parsed).toFixed(2) }}€ {{ (cardParsed+card2Parsed) >= total ? '✓' : '(faltan ' + (total - cardParsed - card2Parsed).toFixed(2) + '€)' }}
                                </div>
                            </div>
                        </template>

                        <button @click="handleConfirm" :disabled="!canConfirm()"
                            style="margin-top:auto; padding:16px; border-radius:12px; border:none; font-family:inherit; font-size:16px; font-weight:700; transition:all .15s;"
                            :style="{ background: canConfirm() ? 'var(--green)' : 'var(--surface3)', color: canConfirm() ? '#000' : 'var(--text3)', cursor: canConfirm() ? 'pointer' : 'default' }">
                            {{ isSubmitting ? 'Procesando…' : (method === 'voucher' ? 'Cobrar ' + voucherPayable.toFixed(2) + '€ tras cupón' : (method === 'card' ? (dataphoneMode === 'independent' ? 'Registrar cobro con tarjeta' : 'Confirmar cobro con tarjeta') : 'Cobrar ' + Number(total).toFixed(2) + '€')) }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
