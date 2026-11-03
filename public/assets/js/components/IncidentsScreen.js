/**
 * IncidentsScreen — uploads PrestaShop rejected for good (PRD S4, I3), administrators only
 * (the server answers 403 to anyone else). The data is corrected in PrestaShop or in the
 * TPV; here each row is only retried, alone or with the rest of its sale, in order.
 * Emits: back, changed (after a retry, so the sync indicator refreshes).
 */
const IncidentsScreen = {
    emits: ['back', 'changed'],
    setup(props, { emit }) {
        const incidents = Vue.ref([]);
        const loading = Vue.ref(false);
        const busy = Vue.ref(false);
        const message = Vue.ref('');
        const error = Vue.ref('');

        const typeLabels = { sale: 'Venta', refund: 'Devolución', restock: 'Reposición de stock' };

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
                        PrestaShop rechazó estas operaciones. Corrige el dato en PrestaShop o en el TPV (por ejemplo, un producto borrado) y pulsa Reintentar.
                        La venta y la caja del TPV no cambian.
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
                    <div style="font-size:14px; color:var(--red); font-family:'IBM Plex Mono'; word-break:break-word;">{{ row.last_error || 'Sin detalle del error' }}</div>
                    <div style="font-size:13px; color:var(--text3);">
                        Intentos: {{ row.attempts }}
                        <span v-if="row.waiting_behind > 0"> · {{ row.waiting_behind }} operación{{ row.waiting_behind !== 1 ? 'es' : '' }} de esta venta esperan detrás</span>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button class="tpv-btn tpv-btn-primary" @click="retry(row)" :disabled="busy">&#8635; Reintentar</button>
                        <button v-if="saleHasSeveral(row) && row.sequence === 1" class="tpv-btn" @click="retryOrder(row)" :disabled="busy">Reintentar la venta y sus devoluciones</button>
                        <button v-if="row.order_id" class="tpv-btn" @click="viewTicket(row)"><icon name="receipt" :size="18" /> Ver ticket</button>
                        <button v-if="row.refund_id" class="tpv-btn" @click="viewCreditNote(row)"><icon name="receipt" :size="18" /> Ver abono</button>
                    </div>
                </div>
            </div>
        </div>
    `
};
