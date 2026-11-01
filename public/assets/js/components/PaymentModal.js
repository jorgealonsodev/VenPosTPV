/**
 * PaymentModal — cash/card/mixed/voucher payment methods with numpad and change display.
 */
const PaymentModal = {
    props: ['total'],
    emits: ['complete', 'close', 'error'],
    setup(props, { emit }) {
        const method = Vue.ref('cash');
        const cashInput = Vue.ref('');
        const card1 = Vue.ref('');
        const card2 = Vue.ref('');
        const step = Vue.ref('select'); // select | done | error
        const errorMsg = Vue.ref('');
        const errorRetry = Vue.ref(false);
        const lastOrderId = Vue.ref(null);

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

        function canConfirm() {
            if (method.value === 'cash') return cashParsed.value >= props.total;
            if (method.value === 'card') return true;
            if (method.value === 'mixed') return (cardParsed.value + card2Parsed.value) >= props.total - 0.01;
            if (method.value === 'voucher') return true;
            return false;
        }

        async function handleConfirm() {
            const amountTendered = method.value === 'cash'
                ? cashParsed.value
                : (method.value === 'mixed'
                    ? cardParsed.value + card2Parsed.value
                    : props.total);

            const payload = {
                payment_method: method.value,
                amount_tendered: amountTendered,
                cart_data: {
                    lines: useCart.cart.lines,
                    subtotal: useCart.cart.subtotal,
                    discount: useCart.cart.discount,
                    discount_amount: useCart.cart.discount_amount,
                    total: useCart.cart.total,
                    iva: useCart.cart.iva,
                    customer: useCart.cart.customer,
                    cash_amount: method.value === 'cash' ? props.total : (method.value === 'mixed' ? card2Parsed.value : 0),
                    card_amount: method.value === 'mixed' ? cardParsed.value : (method.value === 'card' ? props.total : 0),
                },
            };

            try {
                const response = await ApiClient.checkout(payload);
                if (response && response.data) {
                    lastOrderId.value = response.data.ps_order_id || null;
                    const pt = printerType.value;
                    if (pt === 'pdf' && lastOrderId.value) {
                        ApiClient.ticketPdf(lastOrderId.value);
                    } else if (pt === 'escpos' && lastOrderId.value) {
                        ApiClient.ticketReprint(lastOrderId.value).catch(() => {
                            // ESC/POS print failure is non-blocking
                        });
                    }
                    step.value = 'done';
                    setTimeout(() => {
                        emit('complete', {
                            ...response.data,
                            payment_method: method.value,
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
                const retry = msg.includes('502') || msg.includes('Unavailable') || msg.includes('network');
                errorMsg.value = msg;
                errorRetry.value = retry;
                step.value = 'error';
            }
        }

        function downloadTicket() {
            if (lastOrderId.value) {
                ApiClient.ticketPdf(lastOrderId.value);
            }
        }

        function retryCheckout() {
            step.value = 'select';
            errorMsg.value = '';
            handleConfirm();
        }

        function appendNum(k) {
            if (k === 'backspace') {
                if (method.value === 'mixed') {
                    // simple toggle not implemented for MVP
                } else {
                    cashInput.value = cashInput.value.slice(0, -1);
                }
                return;
            }
            if (k === 'decimal') {
                if (method.value === 'mixed') return;
                if (!cashInput.value.includes(',')) cashInput.value += ',';
                return;
            }
            if (k.startsWith('preset:')) {
                const val = k.split(':')[1];
                cashInput.value = val;
                return;
            }
            if (method.value === 'mixed') return;
            cashInput.value += k;
        }

        function exacto() {
            cashInput.value = props.total.toFixed(2).replace('.', ',');
        }

        return { method, cashInput, card1, card2, step, errorMsg, errorRetry, lastOrderId, methods, cashParsed, change, cardParsed, card2Parsed, dataphoneMode, printerType, canConfirm, handleConfirm, retryCheckout, appendNum, exacto, downloadTicket };
    },
    template: `
        <div class="modal-backdrop" @click.self="$emit('close')">
            <!-- Done state -->
            <div v-if="step === 'done'" style="background:var(--surface); border:1px solid var(--green-dim); border-radius:24px; padding:56px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:16px;">
                <div style="width:72px; height:72px; border-radius:50%; background:var(--green); display:flex; align-items:center; justify-content:center;">
                    <icon name="check" :size="36" />
                </div>
                <div style="font-size:24px; font-weight:700;">Cobro completado</div>
                <div style="font-family:'IBM Plex Mono'; font-size:32px; color:var(--green);">{{ Number(total).toFixed(2) }}€</div>
                <div v-if="method === 'cash' && change > 0" style="color:var(--text2);">
                    Cambio: <span style="color:var(--amber); font-weight:600; font-family:'IBM Plex Mono';">{{ Number(change).toFixed(2) }}€</span>
                </div>
                <div v-if="printerType !== 'none'" style="color:var(--text3); font-size:13px;">
                    {{ printerType === 'escpos' ? 'Ticket enviado a impresora' : 'Abriendo ticket PDF...' }}
                </div>
                <button v-if="lastOrderId && printerType === 'pdf'" @click="downloadTicket"
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
                    <button v-if="errorRetry" @click="retryCheckout"
                        style="padding:12px 24px; border-radius:10px; border:1px solid var(--green-dim); background:var(--green-bg); color:var(--green); cursor:pointer; font-weight:600;">
                        Reintentar
                    </button>
                    <button @click="step = 'select'"
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
                                @input="appendNum"
                            />
                            <button @click="exacto"
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
                                <div style="font-size:13px; color:var(--text2);">Introduce el código del vale regalo</div>
                                <input placeholder="TRJ-XXXX-XXXX"
                                    style="background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:13px 16px; color:var(--text); font-family:'IBM Plex Mono'; font-size:17px; outline:none; letter-spacing:0.05em;" />
                                <div style="font-size:12px; color:var(--text3);">El sistema validará el saldo disponible automáticamente.</div>
                            </div>
                        </template>

                        <template v-if="method === 'mixed'">
                            <div style="flex:1; display:flex; flex-direction:column; gap:12px;">
                                <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em;">
                                    Total: <span style="font-family:'IBM Plex Mono'; color:var(--text);">{{ Number(total).toFixed(2) }}€</span>
                                </div>
                                <div>
                                    <div style="font-size:12px; color:var(--text2); margin-bottom:6px;">Tarjeta</div>
                                    <input v-model="card1" placeholder="0,00"
                                        style="width:100%; background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:12px 16px; font-family:'IBM Plex Mono'; font-size:20px; color:var(--text); outline:none;" />
                                </div>
                                <div>
                                    <div style="font-size:12px; color:var(--text2); margin-bottom:6px;">Efectivo</div>
                                    <input v-model="card2" placeholder="0,00"
                                        style="width:100%; background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:12px 16px; font-family:'IBM Plex Mono'; font-size:20px; color:var(--text); outline:none;" />
                                </div>
                                <div style="font-size:12px; color: (cardParsed+card2Parsed) >= total ? 'var(--green)' : 'var(--red)'; font-family:'IBM Plex Mono';">
                                    Total cubierto: {{ (cardParsed+card2Parsed).toFixed(2) }}€ {{ (cardParsed+card2Parsed) >= total ? '✓' : '(faltan ' + (total - cardParsed - card2Parsed).toFixed(2) + '€)' }}
                                </div>
                            </div>
                        </template>

                        <button @click="handleConfirm" :disabled="!canConfirm()"
                            style="margin-top:auto; padding:16px; border-radius:12px; border:none; font-family:inherit; font-size:16px; font-weight:700; transition:all .15s;"
                            :style="{ background: canConfirm() ? 'var(--green)' : 'var(--surface3)', color: canConfirm() ? '#000' : 'var(--text3)', cursor: canConfirm() ? 'pointer' : 'default' }">
                            {{ method === 'card' ? (dataphoneMode === 'independent' ? 'Registrar cobro con tarjeta' : 'Confirmar cobro con tarjeta') : 'Cobrar ' + Number(total).toFixed(2) + '€' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
