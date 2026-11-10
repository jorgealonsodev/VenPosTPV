/**
 * IncidentsScreen — uploads PrestaShop rejected for good (PRD S4, I3), administrators only
 * (the server answers 403 to anyone else). The data is corrected in PrestaShop or in the
 * TPV; here each row is retried, alone or with the rest of its sale, in order. A row that
 * can never upload (a deleted product) is discarded after a confirmation, together with
 * what waits behind it; the local sale and the cash do not change, and the recently
 * discarded rows stay listed (who, when, why) so nothing disappears without a trace.
 * Emits: back, changed (after a retry or a discard, so the sync indicator refreshes).
 */

/**
 * A plain Spanish headline for PrestaShop's (English, technical) error. The raw text is
 * still shown below it. The first matching rule wins.
 */
const INCIDENT_HEADLINES = [
    { test: (type, error) => /Failed \d+ times in a row\./.test(error), text: 'El TPV no pudo guardar la respuesta de PrestaShop' },
    { test: (type, error) => /HTTP 40[13]\b/.test(error), text: 'La clave de conexión con PrestaShop no tiene permiso' },
    { test: (type, error) => /Product not found/i.test(error), text: 'El producto ya no existe en PrestaShop' },
    { test: (type, error) => /\bdni\b/i.test(error), text: 'Falta el DNI en la dirección del cliente' },
    { test: (type, error) => type === 'customer' && /HTTP 4\d\d\b/.test(error), text: 'PrestaShop rechazó el cliente: revisa sus datos' },
];

function incidentHeadline(type, error) {
    const text = String(error || '');
    const rule = INCIDENT_HEADLINES.find((r) => r.test(type, text));
    return rule ? rule.text : 'PrestaShop rechazó la operación';
}

/** What stays in the TPV when a row of this type is discarded, and how to refer to it. */
const DISCARD_NOUNS = {
    sale: { stays: 'La venta queda en el TPV (caja y ticket no cambian)', of: 'de ella' },
    refund: { stays: 'La devolución queda en el TPV (caja y abono no cambian)', of: 'de ella' },
    restock: { stays: 'La reposición de stock queda en el TPV', of: 'de ella' },
    customer: { stays: 'El cliente queda en el TPV', of: 'de él' },
};

const IncidentsScreen = {
    emits: ['back', 'changed'],
    setup(props, { emit }) {
        const incidents = Vue.ref([]);
        const loading = Vue.ref(false);
        const busy = Vue.ref(false);
        const message = Vue.ref('');
        const error = Vue.ref('');
        const confirming = Vue.ref(null);
        const discardReason = Vue.ref('');
        const discarded = Vue.ref([]);
        const showDiscarded = Vue.ref(false);
        const discardedError = Vue.ref('');

        const typeLabels = { sale: 'Venta', refund: 'Devolución', restock: 'Reposición de stock', customer: 'Cliente' };

        async function load() {
            loading.value = true;
            error.value = '';
            try {
                const response = await ApiClient.incidentsList();
                incidents.value = response && Array.isArray(response.data) ? response.data : [];
            } catch (err) {
                error.value = 'No se pudieron cargar las incidencias: ' + (err.message || 'Error');
            } finally {
                loading.value = false;
            }
        }

        async function loadDiscarded() {
            discardedError.value = '';
            try {
                const response = await ApiClient.incidentsDiscarded();
                discarded.value = response && Array.isArray(response.data) ? response.data : [];
            } catch (err) {
                discardedError.value = 'No se pudieron cargar las descartadas: ' + (err.message || 'Error');
            }
        }

        async function toggleDiscarded() {
            showDiscarded.value = !showDiscarded.value;
            if (showDiscarded.value) await loadDiscarded();
        }

        function headline(row) {
            return incidentHeadline(row.type, row.last_error);
        }

        function typeLabel(row) {
            return typeLabels[row.type] || row.type;
        }

        function documentLabel(row) {
            const parts = [];
            if (row.credit_note_number) parts.push('Abono ' + row.credit_note_number);
            if (row.reference) parts.push('Ticket ' + row.reference);
            if (row.ps_reference) parts.push('Pedido web ' + row.ps_reference);
            return parts.join(' · ') || 'Sin ticket';
        }

        /** The sale has more incidents than this one: offer retrying them all, in order. */
        function saleHasSeveral(row) {
            if (!row.order_id) return false;
            return incidents.value.filter((r) => r.order_id === row.order_id).length > 1;
        }

        function notIncident(err) {
            return err && err.message && err.message.indexOf('HTTP 409') === 0;
        }

        const confirmText = Vue.computed(() => {
            const row = confirming.value;
            if (!row) return '';
            const noun = DISCARD_NOUNS[row.type] || { stays: 'La operación queda en el TPV', of: 'de ella' };
            const count = Number(row.dependants) || 0;
            const extra = count > 0
                ? ', y ' + count + (count === 1 ? ' operación que depende ' : ' operaciones que dependen ') + noun.of
                : '';
            return '¿Descartar esta incidencia? ' + noun.stays + ' pero no se subirá a PrestaShop' + extra + '.';
        });

        function askDiscard(row) {
            discardReason.value = '';
            confirming.value = row;
        }

        function cancelDiscard() {
            confirming.value = null;
        }

        async function confirmDiscard() {
            const row = confirming.value;
            if (!row || busy.value) return;
            const reason = discardReason.value.trim() || null;
            busy.value = true;
            message.value = '';
            error.value = '';
            try {
                await ApiClient.incidentDiscard(row.id, reason);
                message.value = 'Incidencia descartada: no se subirá a PrestaShop';
                emit('changed');
            } catch (err) {
                if (notIncident(err)) {
                    message.value = 'Esa incidencia ya no está pendiente: se reintentó, se subió o se descartó';
                    emit('changed');
                } else {
                    error.value = 'No se pudo descartar: ' + (err.message || 'Error');
                }
            } finally {
                busy.value = false;
                confirming.value = null;
            }
            await load();
            if (showDiscarded.value) await loadDiscarded();
        }

        async function run(call, okText) {
            if (busy.value) return;
            busy.value = true;
            message.value = '';
            error.value = '';
            try {
                await call();
                message.value = okText;
                emit('changed');
            } catch (err) {
                if (notIncident(err)) {
                    message.value = 'Esa incidencia ya se reintentó o se subió';
                    emit('changed');
                } else {
                    error.value = 'No se pudo reintentar: ' + (err.message || 'Error');
                }
            } finally {
                busy.value = false;
            }
            await load();
        }

        function retry(row) {
            return run(() => ApiClient.incidentRetry(row.id), 'Se volverá a subir en el próximo minuto');
        }

        function retryOrder(row) {
            return run(() => ApiClient.incidentRetryOrder(row.order_id), 'La venta y sus devoluciones se volverán a subir, en orden');
        }

        function viewTicket(row) {
            if (row.order_id) ApiClient.ticketSalePdf(row.order_id);
        }

        function viewCreditNote(row) {
            if (row.refund_id) ApiClient.refundPdf(row.refund_id);
        }

        Vue.onMounted(load);

        return {
            incidents, loading, busy, message, error,
            load, typeLabel, documentLabel, saleHasSeveral, retry, retryOrder, viewTicket, viewCreditNote,
            headline, confirming, confirmText, discardReason, askDiscard, cancelDiscard, confirmDiscard,
            discarded, showDiscarded, discardedError, toggleDiscarded,
        };
    },
    template: `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden; background:var(--bg);">
            <header style="height:64px; min-height:64px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px; flex-shrink:0;">
                <button class="tpv-btn" @click="$emit('back')"><icon name="arrow_back" :size="18" /> Volver</button>
                <div style="font-weight:700; font-size:17px; color:var(--text);">Incidencias de sincronización</div>
                <button class="tpv-btn" style="margin-left:auto;" @click="load" :disabled="loading">&#8635; Actualizar</button>
            </header>

            <div style="flex:1; overflow:auto; padding:16px; display:flex; flex-direction:column; gap:12px;">
                <div class="tpv-banner tpv-banner-info">
                    <span style="color:var(--text2);">
                        PrestaShop rechazó estas operaciones. Corrige el dato en PrestaShop o en el TPV y pulsa Reintentar.
                        Si nunca se podrá subir (por ejemplo, un producto borrado), pulsa Descartar. La venta y la caja del TPV no cambian.
                    </span>
                </div>
                <div v-if="message" class="tpv-banner tpv-banner-ok" role="status"><span>{{ message }}</span></div>
                <div v-if="error" class="tpv-banner tpv-banner-error" role="alert"><span>{{ error }}</span></div>

                <div v-if="loading && incidents.length === 0" style="text-align:center; color:var(--text3); font-size:14px; padding:20px;">Cargando...</div>
                <div v-else-if="incidents.length === 0" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text3); gap:10px; padding:40px;">
                    <span style="font-size:32px;" aria-hidden="true">&#10003;</span>
                    <span style="font-size:15px;">No hay incidencias</span>
                </div>

                <div v-for="row in incidents" :key="row.id"
                    style="background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span class="tpv-tag">{{ typeLabel(row) }}</span>
                        <span style="font-weight:700; font-size:15px; color:var(--text);">{{ documentLabel(row) }}</span>
                        <span style="margin-left:auto; font-size:13px; color:var(--text3); font-family:'IBM Plex Mono';">{{ row.created_at }}</span>
                    </div>
                    <div style="font-size:15px; font-weight:600; color:var(--red);">{{ headline(row) }}</div>
                    <div style="font-size:12px; color:var(--text3); font-family:'IBM Plex Mono'; word-break:break-word;">{{ row.last_error || 'Sin detalle del error' }}</div>
                    <div style="font-size:13px; color:var(--text3);">
                        Intentos: {{ row.attempts }}
                        <span v-if="row.waiting_behind > 0"> · {{ row.waiting_behind }} operación{{ row.waiting_behind !== 1 ? 'es' : '' }} de esta venta esperan detrás</span>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button class="tpv-btn tpv-btn-primary" @click="retry(row)" :disabled="busy">&#8635; Reintentar</button>
                        <button v-if="saleHasSeveral(row) && row.sequence === 1" class="tpv-btn" @click="retryOrder(row)" :disabled="busy">Reintentar la venta y sus devoluciones</button>
                        <button v-if="row.order_id" class="tpv-btn" @click="viewTicket(row)"><icon name="receipt" :size="18" /> Ver ticket</button>
                        <button v-if="row.refund_id" class="tpv-btn" @click="viewCreditNote(row)"><icon name="receipt" :size="18" /> Ver abono</button>
                        <button class="tpv-btn" style="margin-left:auto; color:var(--red);" @click="askDiscard(row)" :disabled="busy">Descartar</button>
                    </div>
                </div>

                <section style="margin-top:8px; display:flex; flex-direction:column; gap:8px;">
                    <button class="tpv-btn" style="align-self:flex-start;" @click="toggleDiscarded" :aria-expanded="showDiscarded ? 'true' : 'false'" aria-controls="incidents-discarded">
                        <span aria-hidden="true">{{ showDiscarded ? '▾' : '▸' }}</span> Descartadas recientes
                    </button>
                    <div v-if="showDiscarded" id="incidents-discarded" style="display:flex; flex-direction:column; gap:8px;">
                        <div v-if="discardedError" class="tpv-banner tpv-banner-error" role="alert"><span>{{ discardedError }}</span></div>
                        <div v-else-if="discarded.length === 0" style="color:var(--text3); font-size:14px; padding:8px 0;">No se ha descartado ninguna incidencia</div>
                        <div v-for="row in discarded" :key="'d' + row.id"
                            style="background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 16px; display:flex; flex-direction:column; gap:4px;">
                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                <span class="tpv-tag">{{ typeLabel(row) }}</span>
                                <span style="font-weight:600; font-size:14px; color:var(--text);">{{ documentLabel(row) }}</span>
                            </div>
                            <div style="font-size:13px; color:var(--text2);">
                                Descartada por {{ row.discarded_by || 'usuario desconocido' }} el {{ row.discarded_at }}<span v-if="row.discard_reason"> · Motivo: {{ row.discard_reason }}</span>
                            </div>
                            <div style="font-size:12px; color:var(--text3); font-family:'IBM Plex Mono'; word-break:break-word;">{{ row.last_error || 'Sin detalle del error' }}</div>
                        </div>
                    </div>
                </section>
            </div>

            <div v-if="confirming" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; padding:16px; z-index:1000;"
               >
                <div role="dialog" aria-modal="true" aria-labelledby="incident-discard-title"
                    style="background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:14px; padding:20px; width:100%; max-width:480px; display:flex; flex-direction:column; gap:14px;">
                    <div id="incident-discard-title" style="font-weight:700; font-size:17px;">Descartar incidencia</div>
                    <p style="margin:0; font-size:15px; line-height:1.45; color:var(--text);">{{ confirmText }}</p>
                    <label style="display:flex; flex-direction:column; gap:6px; font-size:14px; color:var(--text2);">
                        Motivo (opcional)
                        <input class="tpv-input" v-model="discardReason" maxlength="255" placeholder="Por ejemplo: producto borrado en PrestaShop" />
                    </label>
                    <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
                        <button class="tpv-btn" @click="cancelDiscard" :disabled="busy">Cancelar</button>
                        <button class="tpv-btn tpv-btn-danger" @click="confirmDiscard" :disabled="busy">Descartar</button>
                    </div>
                </div>
            </div>
        </div>
    `
};
