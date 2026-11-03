/**
 * PrintStatusNotice — the ticket and drawer of the print agent (docs/prd/agente-impresion.md
 * C1-C3, C5), from usePrintJobs.
 *
 * placement 'payment': inside the done step of the payment screen, the ticket line
 * and, on a problem, «Reintentar» and «Descargar PDF» (48 px buttons).
 * placement 'sale': on the sale screen once the payment screen has closed, only
 * problems: the ticket warning with «Reimprimir último ticket» and «Descargar PDF»,
 * and the drawer warning with the change. Giving the PDF never cancels the job.
 */
const PrintStatusNotice = {
    props: { placement: { type: String, default: 'sale' } },
    setup(props) {
        const store = usePrintJobs;
        const job = Vue.computed(() => store.ticket.job);
        const message = Vue.computed(() => store.ticketMessage(job.value));
        const showTicket = Vue.computed(() => !!job.value && (props.placement === 'payment' || job.value.problem));
        const canRetry = Vue.computed(() => store.canRetry());
        const drawerText = Vue.computed(() => (props.placement === 'sale' ? store.drawerWarning() : ''));
        const drawerChange = Vue.computed(() => {
            const drawer = store.drawer.job;
            const change = drawer ? Number(drawer.change) : 0;
            return change > 0 ? change.toFixed(2).replace('.', ',') + ' €' : '';
        });

        return {
            job, message, showTicket, canRetry, drawerText, drawerChange,
            retry: () => store.retry(),
            downloadPdf: () => store.downloadPdf(),
            dismissTicket: () => store.dismissTicket(),
            dismissDrawer: () => store.dismissDrawer(),
        };
    },
    template: `
        <div v-if="showTicket || drawerText" class="print-notice-stack" :class="'is-' + placement">
            <div v-if="showTicket" class="tpv-banner print-notice"
                :class="job.problem ? 'tpv-banner-error' : 'tpv-banner-info'"
                :role="job.problem ? 'alert' : 'status'">
                <span class="print-notice-line">
                    <icon :name="job.phase === 'sent' ? 'check' : 'print'" :size="20" />
                    <span :class="job.problem ? 'print-notice-error' : ''">{{ message }}</span>
                </span>
                <div v-if="job.problem" class="print-notice-actions">
                    <button v-if="canRetry" class="tpv-btn tpv-btn-primary print-btn-lg" :disabled="job.retrying" @click="retry">
                        <icon name="refresh" :size="18" /> {{ placement === 'sale' ? 'Reimprimir último ticket' : 'Reintentar' }}
                    </button>
                    <button class="tpv-btn print-btn-lg" @click="downloadPdf">
                        <icon name="print" :size="18" /> Descargar PDF
                    </button>
                    <button v-if="placement === 'sale'" class="tpv-btn" @click="dismissTicket">Cerrar aviso</button>
                </div>
            </div>
            <div v-if="drawerText" class="tpv-banner tpv-banner-warn print-notice" role="alert">
                <span class="print-notice-line print-notice-warn">
                    {{ drawerText }}<template v-if="drawerChange"> Cambio: <strong class="pay-num">{{ drawerChange }}</strong></template>
                </span>
                <button class="tpv-btn" @click="dismissDrawer">Entendido</button>
            </div>
        </div>
    `
};
