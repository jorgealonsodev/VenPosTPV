/**
 * SyncStatusPanel — modal-like overlay showing sync details, including the uploads to
 * PrestaShop still to do and the incidents (PRD I1); administrators open the incidents
 * screen from here (I3).
 */
const SyncStatusPanel = {
    props: ['data', 'open', 'outbox', 'isAdmin'],
    emits: ['close', 'sync-now', 'show-incidents'],
    setup(props, { emit }) {
        const isSyncing = Vue.ref(false);

        function onBackdropClick(e) {
            if (e.target === e.currentTarget) {
                emit('close');
            }
        }

        function onKeydown(e) {
            if (e.key === 'Escape') {
                emit('close');
            }
        }

        Vue.watch(() => props.open, (isOpen) => {
            if (isOpen) {
                document.addEventListener('keydown', onKeydown);
            } else {
                document.removeEventListener('keydown', onKeydown);
            }
        });

        Vue.onUnmounted(() => {
            document.removeEventListener('keydown', onKeydown);
        });

        const stateLabelMap = {
            ok: 'Sincronizado',
            syncing: 'Sincronizando',
            delayed: 'Desactualizado',
            offline: 'Sin conexión',
            error: 'Error',
            unknown: 'Desconocido',
        };

        const stateColorMap = {
            ok: 'var(--green)',
            syncing: 'var(--blue)',
            delayed: 'var(--amber)',
            offline: 'var(--red)',
            error: 'var(--red)',
            unknown: 'var(--text3)',
        };

        const stateLabel = Vue.computed(() => stateLabelMap[props.data?.state] || stateLabelMap.unknown);
        const stateColor = Vue.computed(() => stateColorMap[props.data?.state] || stateColorMap.unknown);

        function formatDate(iso) {
            if (!iso) return '—';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }

        function handleSyncNow() {
            isSyncing.value = true;
            emit('sync-now');
            setTimeout(() => { isSyncing.value = false; }, 3000);
        }

        return { onBackdropClick, stateLabel, stateColor, formatDate, isSyncing, handleSyncNow };
    },
    template: `
        <div v-if="open" @click="onBackdropClick" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:flex-start; justify-content:flex-end; z-index:900; padding:60px 16px 16px;">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; width:320px; max-width:90vw; box-shadow:0 8px 32px rgba(0,0,0,0.35);" @click.stop>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                    <div style="font-weight:700; font-size:15px; color:var(--text);">Estado de sincronización</div>
                    <button @click="$emit('close')" style="background:transparent; border:none; color:var(--text3); cursor:pointer; font-size:18px; line-height:1; padding:0; width:28px; height:28px; display:flex; align-items:center; justify-content:center; border-radius:6px;">&#10005;</button>
                </div>

                <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px; padding:10px 12px; background:var(--surface2); border-radius:10px;">
                    <div style="width:12px; height:12px; border-radius:50%;" :style="{ background: stateColor }"></div>
                    <div style="font-weight:600; font-size:14px; color:var(--text);">{{ stateLabel }}</div>
                </div>

                <div style="margin-bottom:16px;">
                    <button
                        @click="handleSyncNow"
                        :disabled="isSyncing"
                        style="width:100%; padding:10px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; transition:opacity 0.2s;"
                        :style="isSyncing ? 'opacity:0.6; cursor:not-allowed;' : ''"
                    >
                        <span v-if="isSyncing">&#8635; Sincronizando...</span>
                        <span v-else>&#8635; Sincronizar ahora</span>
                    </button>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:12px; color:var(--text3); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.03em;">PrestaShop</div>
                    <div style="font-size:13px; color:var(--text2); display:flex; align-items:center; gap:6px;">
                        <span v-if="data && data.ps_online">&#10003; En línea</span>
                        <span v-else>&#10007; Fuera de línea</span>
                        <span v-if="data && data.latency_ms" style="color:var(--text3);">({{ data.latency_ms }} ms)</span>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:12px; color:var(--text3); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.03em;">Última sincronización</div>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <div v-for="type in ['catalog', 'stock', 'prices']" :key="type" style="display:flex; align-items:center; justify-content:space-between; font-size:13px; color:var(--text2); padding:4px 8px; background:var(--surface2); border-radius:6px;">
                            <span style="text-transform:capitalize;">{{ type }}</span>
                            <span style="display:flex; align-items:center; gap:6px;">
                                <span>{{ formatDate(data && data.last_syncs ? data.last_syncs[type] : null) }}</span>
                                <span v-if="data && data.freshness && data.freshness[type]" style="color:var(--green); font-size:12px;">&#10003;</span>
                                <span v-else style="color:var(--amber); font-size:12px;">&#9888;</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="font-size:12px; color:var(--text3); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.03em;">Ventas y devoluciones</div>
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <div style="flex:1; padding:10px; background:var(--surface2); border-radius:10px; text-align:center;">
                        <div style="font-size:12px; color:var(--text3); margin-bottom:2px;">Pendientes de subir</div>
                        <div style="font-size:18px; font-weight:700; color:var(--text);">{{ outbox ? outbox.pending : '—' }}</div>
                    </div>
                    <div style="flex:1; padding:10px; background:var(--surface2); border-radius:10px; text-align:center;">
                        <div style="font-size:12px; color:var(--text3); margin-bottom:2px;">Incidencias</div>
                        <div style="font-size:18px; font-weight:700;" :style="{ color: outbox && outbox.incidents > 0 ? 'var(--red)' : 'var(--text)' }">{{ outbox ? outbox.incidents : '—' }}</div>
                    </div>
                </div>
                <button v-if="isAdmin" @click="$emit('show-incidents')" class="tpv-btn" style="width:100%; margin-bottom:14px;">
                    &#9888; Ver incidencias
                </button>

                <div style="display:flex; gap:10px;">
                    <div style="flex:1; padding:10px; background:var(--surface2); border-radius:10px; text-align:center;">
                        <div style="font-size:12px; color:var(--text3); margin-bottom:2px;">Pendientes</div>
                        <div style="font-size:18px; font-weight:700; color:var(--text);">{{ data && data.pending_ops !== undefined ? data.pending_ops : '—' }}</div>
                    </div>
                    <div style="flex:1; padding:10px; background:var(--surface2); border-radius:10px; text-align:center;">
                        <div style="font-size:12px; color:var(--text3); margin-bottom:2px;">En proceso</div>
                        <div style="font-size:18px; font-weight:700; color:var(--text);">{{ data && data.queued_messages !== undefined ? data.queued_messages : '—' }}</div>
                    </div>
                </div>
            </div>
        </div>
    `
};
