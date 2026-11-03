/**
 * PrintHistoryPanel — «Historial de impresión» in Ajustes (docs/prd/agente-impresion.md
 * S4, AC13): the print jobs of the last 30 days, newest first, filtered by till and
 * state, 50 at a time («Cargar más» follows next_cursor). Never the job's data.
 */
const PrintHistoryPanel = {
    props: ['tills'],
    setup() {
        const terminalFilter = Vue.ref('');
        const statusFilter = Vue.ref('');
        const items = Vue.ref([]);
        const nextCursor = Vue.ref(null);
        const loading = Vue.ref(false);
        const error = Vue.ref('');
        const statusOptions = [
            { value: '', label: 'Todos' },
            { value: 'pending', label: 'Pendiente' },
            { value: 'done', label: 'Enviado' },
            { value: 'failed', label: 'Fallido' },
            { value: 'expired', label: 'Caducado' },
            { value: 'cancelled', label: 'Cancelado' },
        ];

        function when(iso) {
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return '';
            return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' '
                + d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        }

        const rows = Vue.computed(() => items.value.map((job) => ({
            id: job.id,
            date: when(job.created_at),
            till: job.terminal_name || ('Caja ' + job.terminal_id),
            type: usePrintJobs.jobTypeLabel(job.type),
            status: usePrintJobs.jobStatusLabel(job.status),
            failed: job.status === 'failed' || job.status === 'expired' || job.status === 'cancelled',
            error: job.error_code || job.error ? usePrintJobs.reasonText(job.error_code, job.error) : '',
        })));
        const hasMore = Vue.computed(() => nextCursor.value !== null && nextCursor.value !== undefined);

        async function fetchPage(cursor) {
            const params = {};
            if (terminalFilter.value) params.terminal_id = terminalFilter.value;
            if (statusFilter.value) params.status = statusFilter.value;
            if (cursor) params.cursor = cursor;
            params.limit = 50;
            loading.value = true;
            error.value = '';
            try {
                const page = await ApiClient.printJobs(params);
                return { items: (page && Array.isArray(page.items)) ? page.items : [], next: page ? page.next_cursor : null };
            } catch (e) {
                error.value = 'No se ha podido cargar el historial';
                return null;
            } finally {
                loading.value = false;
            }
        }

        async function reload() {
            const page = await fetchPage(null);
            if (!page) return;
            items.value = page.items;
            nextCursor.value = page.next;
        }

        async function loadMore() {
            if (!hasMore.value || loading.value) return;
            const page = await fetchPage(nextCursor.value);
            if (!page) return;
            items.value = items.value.concat(page.items);
            nextCursor.value = page.next;
        }

        Vue.onMounted(() => { reload(); });

        return { terminalFilter, statusFilter, statusOptions, rows, hasMore, loading, error, reload, loadMore };
    },
    template: `
        <div class="print-history">
            <div class="print-history-filters">
                <label class="print-history-filter">
                    <span>Caja</span>
                    <select v-model="terminalFilter" @change="reload" class="tpv-input">
                        <option value="">Todas</option>
                        <option v-for="t in (tills || [])" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
                    </select>
                </label>
                <label class="print-history-filter">
                    <span>Estado</span>
                    <select v-model="statusFilter" @change="reload" class="tpv-input">
                        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
            </div>
            <div v-if="error" class="print-agent-error" role="alert">{{ error }}</div>
            <div v-if="!loading && rows.length === 0 && !error" class="print-agent-hint">No hay trabajos de impresión en los últimos 30 días.</div>
            <div v-if="rows.length" class="print-history-scroll">
                <table class="print-history-table">
                    <thead><tr><th>Fecha</th><th>Caja</th><th>Tipo</th><th>Estado</th><th>Error</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id">
                            <td class="pay-num">{{ r.date }}</td>
                            <td>{{ r.till }}</td>
                            <td>{{ r.type }}</td>
                            <td :class="r.failed ? 'print-agent-bad' : ''">{{ r.status }}</td>
                            <td>{{ r.error }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button v-if="hasMore" class="tpv-btn" :disabled="loading" @click="loadMore">Cargar más</button>
        </div>
    `
};
