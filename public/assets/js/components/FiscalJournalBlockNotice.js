/**
 * FiscalJournalBlockNotice — the one place that shows the till is blocked because the
 * database is behind the fiscal journal (fiscal-journal S5, A3): after a restore of an
 * older backup the server answers 423 `fiscal_journal_behind` to every request that
 * would register something. ApiClient sets the state on that answer, whichever screen
 * made the call (also the timer calls that swallow their errors), and clears it when
 * the journal status says the database is complete again, after a completed recovery,
 * or when a change is accepted again (recovered from another till). The screens keep
 * their own error handling; this notice stays on every screen until then.
 */
const useFiscalJournalBlock = (function () {
    const state = (typeof Vue !== 'undefined' && Vue.reactive) ? Vue.reactive({ blocked: false }) : { blocked: false };

    return {
        state,
        lock() { state.blocked = true; },
        unlock() { state.blocked = false; },
    };
})();

const FiscalJournalBlockNotice = {
    setup() {
        const blocked = Vue.computed(() => useFiscalJournalBlock.state.blocked);
        return { blocked };
    },
    template: `
        <div v-if="blocked" class="tpv-banner tpv-banner-error" role="alert" data-fiscal-journal-block
            style="margin:8px; flex-direction:column; align-items:flex-start; gap:4px;">
            <strong style="color:var(--red); font-size:15px;">Caja bloqueada: la base de datos está por detrás del diario fiscal.</strong>
            <span style="font-size:14px; color:var(--text);">Se ha restaurado una copia anterior a los últimos documentos emitidos. No se puede registrar nada (ventas, devoluciones, facturas, movimientos de caja ni aperturas o cierres) hasta recuperar los documentos.</span>
            <span style="font-size:14px; color:var(--text);">Para recuperarlos: Ajustes → VERI*FACTU → Diario fiscal → «Recuperar documentos» (solo administradores).</span>
        </div>
    `
};
