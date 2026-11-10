/**
 * CashCloseScreen — denomination counting and session summary, with parked tickets warning
 * and the uploads to PrestaShop still to do (PRD I2, A6): a warning, never a block.
 * «Imprimir informe X» prints the open till without closing it (its PDF when the till
 * has no printer); closing prints the Z report (useSession.closeCash).
 */
const CashCloseScreen = {
    props: ['session', 'parkedCount', 'outbox'],
    emits: ['back', 'close', 'discardParked'],
    setup(props, { emit }) {
        const counted = Vue.reactive({});
        const denoms = [
            { v: 500, label: '500€' }, { v: 200, label: '200€' }, { v: 100, label: '100€' },
            { v: 50, label: '50€' }, { v: 20, label: '20€' }, { v: 10, label: '10€' },
            { v: 5, label: '5€' }, { v: 2, label: '2€' }, { v: 1, label: '1€' },
            { v: 0.50, label: '50¢' }, { v: 0.20, label: '20¢' }, { v: 0.10, label: '10¢' },
            { v: 0.05, label: '5¢' }, { v: 0.02, label: '2¢' }, { v: 0.01, label: '1¢' },
        ];

        // The counted cash: per coin and note, or typed as one total amount.
        const countMode = Vue.ref('denoms');
        const amountInput = Vue.ref('');
        const triedClose = Vue.ref(false);

        function setCountMode(mode) {
            countMode.value = mode === 'amount' ? 'amount' : 'denoms';
            triedClose.value = false;
        }

        /** The typed amount (comma or dot, at most 2 decimals), or null when unusable. */
        const typedAmount = Vue.computed(() => {
            const text = String(amountInput.value || '').trim().replace(',', '.');
            if (!/^\d+(\.\d{1,2})?$/.test(text)) return null;
            return Math.round(Number(text) * 100) / 100;
        });

        const amountError = Vue.computed(() => (countMode.value === 'amount' && typedAmount.value === null)
            ? 'Escribe un importe válido, por ejemplo 245,50.'
            : '');

        const total = Vue.computed(() => {
            if (countMode.value === 'amount') {
                return typedAmount.value === null ? 0 : typedAmount.value;
            }
            return denoms.reduce((s, d) => s + d.v * (parseInt(counted[d.v]) || 0), 0);
        });

        function confirmClose() {
            triedClose.value = true;
            if (amountError.value) return;
            emit('close', Number(total.value).toFixed(2));
        }

        const expected = Vue.computed(() => {
            if (props.session.expected !== undefined && props.session.expected !== null) {
                return Number(props.session.expected || 0);
            }

            return (props.session.openingCash || 0) + (props.session.sales || 0) - (props.session.refunds || 0);
        });

        const diff = Vue.computed(() => total.value - expected.value);

        function handleDiscardParked() {
            emit('discardParked');
        }

        const pendingUploadsText = Vue.computed(() => {
            const pending = Number((props.outbox && props.outbox.pending) || 0);
            if (pending <= 0) return '';
            return pending === 1
                ? 'Hay 1 operación pendiente de subir a PrestaShop'
                : 'Hay ' + pending + ' operaciones pendientes de subir a PrestaShop';
        });

        const incidentsText = Vue.computed(() => {
            const incidents = Number((props.outbox && props.outbox.incidents) || 0);
            if (incidents <= 0) return '';
            return incidents + ' en incidencia: un administrador debe revisarlas en Incidencias';
        });

        // Uploads behind an incident wait for an administrator; the rest go up on their own.
        const blockedUploadsText = Vue.computed(() => {
            const blocked = Number((props.outbox && props.outbox.blocked) || 0);
            return blocked > 0 ? blocked + ' esperando a una incidencia' : '';
        });
        const uploadsOnTheirOwn = Vue.computed(() => {
            const pending = Number((props.outbox && props.outbox.pending) || 0);
            return pending - Number((props.outbox && props.outbox.blocked) || 0) > 0;
        });

        const printingX = Vue.ref(false);
        const xStatus = Vue.ref('');
        const xFailed = Vue.ref(false);
        const X_MESSAGES = { queued: 'Imprimiendo informe X…', printed: 'Informe X impreso', pdf: 'Informe X descargado en PDF' };

        function serverError(err) {
            const match = /^HTTP \d+: ([\s\S]*)$/.exec((err && err.message) || '');
            if (match) {
                try { return JSON.parse(match[1]).error || ''; } catch (e) { return ''; }
            }
            return (err && err.message) || '';
        }

        async function printX() {
            if (printingX.value) return;
            printingX.value = true;
            xStatus.value = '';
            xFailed.value = false;
            try {
                const outcome = await useSession.printCashReport('x');
                xStatus.value = X_MESSAGES[outcome] || 'Informe X enviado';
            } catch (err) {
                xFailed.value = true;
                const detail = serverError(err);
                xStatus.value = 'No se ha podido imprimir el informe X' + (detail ? ': ' + detail : '');
            } finally {
                printingX.value = false;
            }
        }

        return { counted, denoms, total, countMode, amountInput, amountError, triedClose, setCountMode, confirmClose, expected, diff, handleDiscardParked, pendingUploadsText, blockedUploadsText, uploadsOnTheirOwn, incidentsText, printingX, xStatus, xFailed, printX };
    },
    template: `
        <div style="width:100%; height:100%; background:var(--bg); overflow:auto; padding:32px; display:flex; flex-direction:column; gap:24px;">
            <div style="display:flex; align-items:center; gap:16px;">
                <button @click="$emit('back')" style="background:var(--surface2); border:none; border-radius:8px; width:36px; height:36px; cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center;">
                    <icon name="arrow_back" :size="16" />
                </button>
                <div>
                    <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em;">Cierre de caja</div>
                    <div style="font-size:20px; font-weight:700;">Arqueo y cierre de jornada</div>
                </div>
            </div>

            <!-- Parked Tickets Warning Banner -->
            <div v-if="parkedCount && parkedCount > 0"
                style="max-width:900px; background:var(--amber-bg); border:1px solid var(--amber); border-radius:14px; padding:14px 18px; display:flex; align-items:center; justify-content:space-between; gap:14px;">
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <div style="font-size:13px; font-weight:700; color:var(--amber);">
                        Aviso: Quedan {{ parkedCount }} ticket{{ parkedCount !== 1 ? 's' : '' }} aparcado{{ parkedCount !== 1 ? 's' : '' }} sin cobrar
                    </div>
                    <div style="font-size:12px; color:var(--text2);">
                        No bloquean stock ni impiden el Cierre Z. Puedes conservarlos para el siguiente turno o descartarlos ahora.
                    </div>
                </div>
                <button @click="handleDiscardParked"
                    style="flex-shrink:0; padding:8px 14px; border-radius:8px; border:1px solid var(--amber); background:var(--surface); color:var(--amber); cursor:pointer; font-family:inherit; font-size:12px; font-weight:600;">
                    Descartar aparcados ({{ parkedCount }})
                </button>
            </div>

            <!-- Uploads to PrestaShop still to do: they upload on their own, the close goes ahead -->
            <div v-if="pendingUploadsText || incidentsText" class="tpv-banner tpv-banner-warn" role="status" style="max-width:900px;">
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <div v-if="pendingUploadsText" style="font-size:14px; font-weight:700; color:var(--amber);">{{ pendingUploadsText }}</div>
                    <div v-if="blockedUploadsText" style="font-size:13px; font-weight:600; color:var(--amber);">{{ blockedUploadsText }}</div>
                    <div v-if="incidentsText" style="font-size:14px; font-weight:700; color:var(--amber);">{{ incidentsText }}</div>
                    <div style="font-size:13px; color:var(--text2);">
                        <span v-if="uploadsOnTheirOwn">Se suben solas cuando PrestaShop responde. </span>Puedes cerrar la caja igualmente.
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; max-width:900px;">
                <!-- Conteo -->
                <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:24px;">
                    <div style="font-size:13px; font-weight:600; margin-bottom:12px;">Conteo de caja</div>
                    <div class="cash-count-modes" role="tablist">
                        <button type="button" role="tab" :aria-selected="countMode === 'denoms'" :class="{ active: countMode === 'denoms' }" @click="setCountMode('denoms')">Por monedas y billetes</button>
                        <button type="button" role="tab" :aria-selected="countMode === 'amount'" :class="{ active: countMode === 'amount' }" @click="setCountMode('amount')">Importe total</button>
                    </div>
                    <div v-if="countMode === 'amount'" style="display:flex; flex-direction:column; gap:8px;">
                        <label for="cash-count-amount" style="font-size:12px; color:var(--text3);">Efectivo contado en la caja (€)</label>
                        <input id="cash-count-amount" class="tpv-input" type="text" inputmode="decimal" autocomplete="off" v-model="amountInput" placeholder="0,00"
                            style="font-family:'IBM Plex Mono'; font-size:22px;" />
                        <div v-if="amountError && (amountInput !== '' || triedClose)" role="alert" style="font-size:12px; color:var(--red);">{{ amountError }}</div>
                    </div>
                    <div v-else style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px;">
                        <div v-for="d in denoms" :key="d.v" style="background:var(--surface2); border-radius:10px; padding:10px 12px; display:flex; flex-direction:column; gap:4px;">
                            <div style="font-size:11px; color:var(--text3); font-family:'IBM Plex Mono';">{{ d.label }}</div>
                            <input type="number" min="0" v-model="counted[d.v]" placeholder="0"
                                style="background:transparent; border:none; border-bottom:1px solid var(--border2); color:var(--text); font-family:'IBM Plex Mono'; font-size:14px; outline:none; width:100%; padding:2px 0;" />
                            <div v-if="counted[d.v] > 0" style="font-size:10px; color:var(--text3); font-family:'IBM Plex Mono';">= €{{ (d.v * parseInt(counted[d.v])).toFixed(2) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Resumen -->
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:24px; display:flex; flex-direction:column; gap:14px;">
                        <div style="font-size:13px; font-weight:600;">Resumen de sesión</div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Fondo inicial</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--text2); font-weight:500;">€{{ Number(session.openingCash || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Ventas (efectivo)</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--green); font-weight:500;">€{{ Number(session.sales || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Devoluciones (efectivo)</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--red); font-weight:500;">€{{ Number(session.refunds || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text3); font-size:12px;">Ventas con tarjeta</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:13px; color:var(--text3);">€{{ Number(session.salesCard || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text3); font-size:12px;">Devoluciones a tarjeta</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:13px; color:var(--text3);">€{{ Number(session.refundsCard || 0).toFixed(2) }}</span>
                        </div>
                        <div v-if="session.salesTransfer || session.refundsTransfer" style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text3); font-size:12px;">Ventas por transferencia</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:13px; color:var(--text3);">€{{ Number(session.salesTransfer || 0).toFixed(2) }}</span>
                        </div>
                        <div v-if="session.salesTransfer || session.refundsTransfer" style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text3); font-size:12px;">Devoluciones por transferencia</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:13px; color:var(--text3);">€{{ Number(session.refundsTransfer || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Neto (ventas − devoluciones)</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--text); font-weight:500;">€{{ Number(session.net || 0).toFixed(2) }}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:var(--text2); font-size:13px;">Efectivo esperado</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:15px; color:var(--text); font-weight:500;">€{{ Number(expected).toFixed(2) }}</span>
                        </div>
                    </div>

                    <div style="background:var(--surface); border-radius:16px; padding:24px;"
                        :style="{ border: '1px solid ' + (Math.abs(diff) < 1 ? 'var(--green-dim)' : 'var(--amber)') }">
                        <div style="font-size:12px; color:var(--text3); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:8px;">Total contado</div>
                        <div style="font-family:'IBM Plex Mono'; font-size:36px; font-weight:700;"
                            :style="{ color: Math.abs(diff) < 1 ? 'var(--green)' : 'var(--amber)' }">
                            €{{ Number(total).toFixed(2) }}
                        </div>
                        <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                            <span style="font-size:12px; color:var(--text3);">Diferencia:</span>
                            <span style="font-family:'IBM Plex Mono'; font-size:14px;"
                                :style="{ color: Math.abs(diff) < 0.01 ? 'var(--green)' : diff > 0 ? 'var(--blue)' : 'var(--red)' }">
                                {{ diff >= 0 ? '+' : '' }}€{{ Number(diff).toFixed(2) }}
                            </span>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <button @click="printX" :disabled="printingX" style="padding:14px; border-radius:12px; border:1px solid var(--border); background:var(--surface2); color:var(--text2); cursor:pointer; font-family:inherit; font-size:14px; display:flex; align-items:center; justify-content:center; gap:8px;">
                            <icon name="print" :size="16" /> {{ printingX ? 'Imprimiendo…' : 'Imprimir informe X' }}
                        </button>
                        <div v-if="xStatus" :role="xFailed ? 'alert' : 'status'" style="font-size:13px; text-align:center;" :style="{ color: xFailed ? 'var(--red)' : 'var(--text2)' }">{{ xStatus }}</div>
                        <button @click="confirmClose" style="padding:14px; border-radius:12px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:15px; font-weight:700;">
                            Cerrar caja y generar informe Z →
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};
