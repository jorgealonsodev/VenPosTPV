/**
 * SyncStatusIndicator — 5-state colored circle with tooltip, plus the uploads to
 * PrestaShop still to do and the incidents (PRD I1) as small counters beside it.
 */
const SyncStatusIndicator = {
    props: ['state', 'data', 'outbox'],
    emits: ['click'],
    setup(props) {
        const colorMap = {
            ok: 'var(--green)',
            syncing: 'var(--blue)',
            delayed: 'var(--amber)',
            offline: 'var(--red)',
            error: 'var(--red)',
            unknown: 'var(--text3)',
        };

        const labelMap = {
            ok: 'Sincronizado',
            syncing: 'Sincronizando...',
            delayed: 'Datos desactualizados',
            offline: 'Sin conexión',
            error: 'Error de sincronización',
            unknown: 'Estado desconocido',
        };

        const circleColor = Vue.computed(() => colorMap[props.state] || colorMap.unknown);
        const pendingCount = Vue.computed(() => Number((props.outbox && props.outbox.pending) || 0));
        const incidentCount = Vue.computed(() => Number((props.outbox && props.outbox.incidents) || 0));
        const tooltip = Vue.computed(() => {
            let text = labelMap[props.state] || labelMap.unknown;
            if (props.state === 'ok' && props.data && props.data.latency_ms) {
                text = 'Sincronizado (' + props.data.latency_ms + ' ms)';
            }
            if (pendingCount.value > 0) {
                text += ' · ' + pendingCount.value + (pendingCount.value === 1 ? ' pendiente' : ' pendientes') + ' de subir a PrestaShop';
            }
            if (incidentCount.value > 0) {
                text += ' · ' + incidentCount.value + (incidentCount.value === 1 ? ' incidencia' : ' incidencias');
            }
            return text;
        });

        return { circleColor, tooltip, pendingCount, incidentCount };
    },
    template: `
        <button type="button"
            @click="$emit('click')"
            :title="tooltip"
            :aria-label="tooltip"
            style="min-width:44px; min-height:44px; padding:0 6px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; gap:6px; font-family:inherit;"
        >
            <span
                style="width:28px; height:28px; min-width:28px; border-radius:50%; display:flex; align-items:center; justify-content:center;"
                :style="{ background: circleColor }"
            >
                <span v-if="state === 'ok'" style="color:#fff; font-size:13px;">&#10003;</span>
                <span v-else-if="state === 'syncing'" style="color:#fff; font-size:12px;">&#8635;</span>
                <span v-else-if="state === 'delayed'" style="color:#000; font-size:13px; font-weight:700;">!</span>
                <span v-else-if="state === 'offline'" style="color:#fff; font-size:12px;">&#10007;</span>
                <span v-else-if="state === 'error'" style="color:#fff; font-size:13px; font-weight:700;">&#9888;</span>
                <span v-else style="color:#fff; font-size:12px;">?</span>
            </span>
            <span v-if="pendingCount > 0" class="tpv-sync-count tpv-sync-count-pending">&#8593; {{ pendingCount }}</span>
            <span v-if="incidentCount > 0" class="tpv-sync-count tpv-sync-count-incident">&#9888; {{ incidentCount }}</span>
        </button>
    `
};
