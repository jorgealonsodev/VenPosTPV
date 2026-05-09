/**
 * SyncStatusIndicator — 5-state colored circle with tooltip.
 */
const SyncStatusIndicator = {
    props: ['state', 'data'],
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
        const tooltip = Vue.computed(() => {
            if (props.state === 'ok' && props.data && props.data.latency_ms) {
                return 'Sincronizado (' + props.data.latency_ms + ' ms)';
            }
            return labelMap[props.state] || labelMap.unknown;
        });

        return { circleColor, tooltip };
    },
    template: `
        <div
            @click="$emit('click')"
            :title="tooltip"
            style="width:28px; height:28px; min-width:28px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:transform 0.15s ease;"
            :style="{ background: circleColor }"
            onmouseover="this.style.transform='scale(1.08)'"
            onmouseout="this.style.transform='scale(1)'"
        >
            <span v-if="state === 'ok'" style="color:#fff; font-size:13px;">&#10003;</span>
            <span v-else-if="state === 'syncing'" style="color:#fff; font-size:12px;">&#8635;</span>
            <span v-else-if="state === 'delayed'" style="color:#000; font-size:13px; font-weight:700;">!</span>
            <span v-else-if="state === 'offline'" style="color:#fff; font-size:12px;">&#10007;</span>
            <span v-else-if="state === 'error'" style="color:#fff; font-size:13px; font-weight:700;">&#9888;</span>
            <span v-else style="color:#fff; font-size:12px;">?</span>
        </div>
    `
};
