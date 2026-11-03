/**
 * usePrintJobs — the print agent's jobs seen from the till (docs/prd/agente-impresion.md
 * C1-C5, T3). A sale, reprint or credit note on an agent till answers
 * print_status 'queued' + print_job_id (and drawer_job_id for a cash sale); this
 * store follows those jobs with GET /api/print-jobs/{id}:
 *
 * - ticket: every second for 20 s, then every 5 s until the 2-minute life of a
 *   ticket (T6), so a ticket the agent prints late still clears the warning (C5).
 *   Not done in 15 s (claimed or not): "La impresora no está conectada". Failed: "La impresora
 *   tiene un problema: <motivo>". Done means handed to the queue or device (T3):
 *   "Ticket enviado", never "Ticket impreso", because the printer does not confirm.
 * - drawer: every second; not opened in 5 s: the amber "Ábrelo con la llave" warning.
 *
 * Giving the PDF never cancels the job (C5). The state is global, so the warning
 * stays on the sale screen after the payment screen closes (C2).
 */
const usePrintJobs = (function () {
    const POLL_MS = 1000;
    const SLOW_POLL_MS = 5000;
    const FAST_MS = 20000;
    const OFFLINE_MS = 15000;
    const TICKET_LIFE_MS = 120000;
    const DRAWER_WARN_MS = 5000;
    const DRAWER_LIFE_MS = 15000;
    const DRAWER_WARNING = 'El cajón no se ha abierto. Ábrelo con la llave.';

    // error_code of a job (agent/agent-core PrintError, server codes) in plain Spanish.
    const REASONS = {
        printer_not_found: 'impresora no encontrada, elígela de nuevo desde el icono',
        printer_not_configured: 'no hay impresora elegida en el programa de impresión',
        printer_open_failed: 'no se puede abrir la impresora',
        printer_unreachable: 'la impresora no responde en la red',
        spooler_unavailable: 'la cola de impresión del ordenador no responde',
        spooler_rejected: 'la cola de impresión ha rechazado el ticket',
        spooler_write_failed: 'no se ha podido enviar a la cola de impresión',
        device_write_failed: 'no se ha podido escribir en la impresora',
        network_write_failed: 'no se ha podido enviar por la red',
        unsupported_destination: 'la impresora elegida no es válida',
        paper_out: 'sin papel',
        paper_low: 'poco papel',
        cover_open: 'tapa abierta',
        job_expired: 'el ticket ha caducado sin imprimirse',
        terminal_not_resolved: 'este equipo no tiene caja asignada',
        print_job_failed: 'no se ha podido preparar el ticket',
        cancelled: 'el trabajo se ha cancelado',
        no_answer: 'el programa de impresión no ha respondido',
        retry_failed: 'no se ha podido reintentar',
    };

    const PRINTER_STATES = {
        ready: 'Lista',
        paper_low: 'Poco papel',
        paper_out: 'Sin papel',
        cover_open: 'Tapa abierta',
        offline: 'Desconectada',
        not_found: 'No encontrada',
        paused: 'En pausa',
        error: 'Error',
        not_configured: 'Sin elegir',
        unknown: 'Desconocido',
    };
    const JOB_STATUSES = {
        pending: 'Pendiente', claimed: 'Pendiente', done: 'Enviado', failed: 'Fallido', expired: 'Caducado', cancelled: 'Cancelado',
    };
    const JOB_TYPES = { ticket: 'Ticket', drawer: 'Cajón', label: 'Etiqueta' };

    function reasonText(code, error) {
        if (code && REASONS[code]) return REASONS[code];
        const text = error ? String(error).replace(/\s+/g, ' ').trim() : '';
        return text ? text.slice(0, 160) : 'error desconocido';
    }

    const printerStateLabel = (state) => PRINTER_STATES[state] || PRINTER_STATES.unknown;
    const jobStatusLabel = (status) => JOB_STATUSES[status] || String(status || '');
    const jobTypeLabel = (type) => JOB_TYPES[type] || String(type || '');

    /** The line the till shows for a followed ticket (C1); labels name another document (the test print). */
    function ticketMessage(job, labels) {
        if (!job) return '';
        const words = labels || { printing: 'Imprimiendo ticket…', sent: 'Ticket enviado' };
        if (job.phase === 'printing') return words.printing;
        if (job.phase === 'sent') return words.sent;
        if (job.phase === 'offline') return 'La impresora no está conectada';
        if (job.phase === 'failed') return 'La impresora tiene un problema: ' + job.reason;
        return '';
    }

    const pickedUp = (job) => !!job && (job.status !== 'pending' || !!job.claimed_at);

    /**
     * Follows one ticket job at a time; a new one replaces it. Payment and refunds
     * share the global tracker; Ajustes uses its own for the test print.
     */
    function createTicketTracker() {
        const state = Vue.reactive({ job: null });
        let generation = 0;
        let timer = null;

        function stop() {
            generation += 1;
            if (timer !== null) clearTimeout(timer);
            timer = null;
        }

        function schedule(gen, ms) {
            timer = setTimeout(() => { poll(gen); }, ms);
        }

        function context(job) {
            return { kind: job.kind, saleId: job.saleId, orderId: job.orderId, refundId: job.refundId };
        }

        function start(jobId, ctx, phase, reason) {
            stop();
            const c = ctx || {};
            state.job = {
                jobId: jobId || null,
                kind: c.kind || 'sale',
                saleId: c.saleId || null,
                orderId: c.orderId || null,
                refundId: c.refundId || null,
                phase,
                reason: reason || '',
                problem: phase === 'offline' || phase === 'failed',
                retrying: false,
                startedAt: Date.now(),
            };
            return generation;
        }

        function setPhase(job, phase, reason) {
            job.phase = phase;
            job.reason = reason || '';
            job.problem = phase === 'offline' || phase === 'failed';
        }

        function track(jobId, ctx) {
            const gen = start(jobId, ctx, 'printing');
            schedule(gen, POLL_MS);
        }

        /** The server could not queue it (print_status 'failed'): a problem at once. */
        function fail(ctx, code) {
            start(null, ctx, 'failed', reasonText(code));
        }

        async function poll(gen) {
            const job = state.job;
            if (gen !== generation || !job) return;
            let remote = null;
            try {
                remote = await ApiClient.printJob(job.jobId);
            } catch (e) {
                remote = null; // unknown: counts as not picked up
            }
            if (gen !== generation) return;
            const elapsed = Date.now() - job.startedAt;

            if (remote && remote.status === 'done') { setPhase(job, 'sent'); return; }
            if (remote && (remote.status === 'failed' || remote.status === 'cancelled')) {
                setPhase(job, 'failed', reasonText(remote.status === 'cancelled' ? 'cancelled' : remote.error_code, remote.error));
                return;
            }
            if (remote && remote.status === 'expired') {
                if (pickedUp(remote)) setPhase(job, 'failed', reasonText('job_expired'));
                else setPhase(job, 'offline');
                return;
            }

            // Not done in 15 s: not connected, even when claimed. A stopped agent's last
            // long poll may claim it into a dead connection (C1). The job is left
            // alone: if it prints later the notice clears (C5).
            if (elapsed >= OFFLINE_MS) setPhase(job, 'offline');
            if (elapsed >= TICKET_LIFE_MS) {
                if (job.phase === 'printing') setPhase(job, 'failed', reasonText('no_answer'));
                return;
            }
            schedule(gen, elapsed < FAST_MS ? POLL_MS : SLOW_POLL_MS);
        }

        /** C2: a reprint of the same sale (only sales and reprints have one). */
        function canRetry() {
            const job = state.job;
            // Only after a real failure: a ticket waiting for an offline agent still prints
            // when it comes back (C5), so retrying then would print it twice.
            return !!job && job.phase === 'failed' && !job.retrying && (job.kind === 'sale' || job.kind === 'reprint') && !!(job.saleId || job.orderId);
        }

        async function retry() {
            if (!canRetry()) return false;
            const job = state.job;
            job.retrying = true;
            try {
                const response = job.saleId ? await ApiClient.ticketReprintSale(job.saleId) : await ApiClient.ticketReprint(job.orderId);
                const data = response && response.data ? response.data : {};
                if (data.print_status === 'queued' && data.print_job_id) {
                    track(data.print_job_id, context(job));
                    return true;
                }
                if (data.printed) { setPhase(job, 'sent'); return true; }
                if (data.fallback === 'pdf') { downloadPdf(); return true; }
                setPhase(job, 'failed', reasonText('retry_failed'));
            } catch (e) {
                setPhase(job, 'failed', reasonText('retry_failed'));
            } finally {
                job.retrying = false;
            }
            return false;
        }

        /** C5: the PDF is handed over; the queued job is left alone and still followed. */
        function downloadPdf() {
            const job = state.job;
            if (!job) return;
            if (job.kind === 'credit_note') {
                if (job.refundId) ApiClient.refundPdf(job.refundId);
            } else if (job.saleId) {
                ApiClient.ticketSalePdf(job.saleId);
            } else if (job.orderId) {
                ApiClient.ticketPdf(job.orderId);
            }
        }

        function dismiss() {
            stop();
            state.job = null;
        }

        return { state, track, fail, retry, canRetry, downloadPdf, dismiss };
    }

    const ticket = createTicketTracker();

    const drawer = Vue.reactive({ job: null });
    let drawerGeneration = 0;

    function trackDrawer(jobId, ctx) {
        const gen = ++drawerGeneration;
        drawer.job = { jobId, phase: 'waiting', change: ctx && ctx.change !== undefined ? ctx.change : null, startedAt: Date.now() };
        setTimeout(() => { pollDrawer(gen); }, POLL_MS);
    }

    function drawerFailed(ctx) {
        drawerGeneration += 1;
        drawer.job = { jobId: null, phase: 'not_opened', change: ctx && ctx.change !== undefined ? ctx.change : null, startedAt: Date.now() };
    }

    async function pollDrawer(gen) {
        const job = drawer.job;
        if (gen !== drawerGeneration || !job) return;
        let remote = null;
        try {
            remote = await ApiClient.printJob(job.jobId);
        } catch (e) {
            remote = null;
        }
        if (gen !== drawerGeneration) return;
        if (remote && remote.status === 'done') { job.phase = 'opened'; return; }
        if (remote && (remote.status === 'failed' || remote.status === 'expired' || remote.status === 'cancelled')) {
            job.phase = 'not_opened';
            return;
        }
        const elapsed = Date.now() - job.startedAt;
        if (elapsed >= DRAWER_WARN_MS) job.phase = 'not_opened';
        if (elapsed >= DRAWER_LIFE_MS) return;
        setTimeout(() => { pollDrawer(gen); }, POLL_MS);
    }

    function drawerWarning() {
        return drawer.job && drawer.job.phase === 'not_opened' ? DRAWER_WARNING : '';
    }

    function dismissDrawer() {
        drawerGeneration += 1;
        drawer.job = null;
    }

    /**
     * Follows the jobs of a checkout, reprint or refund response. Returns true when
     * a ticket of the print agent is followed; other printers are left as before.
     */
    function trackResponse(data, ctx) {
        if (!data) return false;
        let followed = false;
        if (data.print_status === 'queued' && data.print_job_id) {
            ticket.track(data.print_job_id, ctx);
            followed = true;
        } else if (data.print_status === 'failed' && data.print_error) {
            ticket.fail(ctx, data.print_error);
            followed = true;
        }
        if (data.drawer_job_id) trackDrawer(data.drawer_job_id, ctx);
        else if (followed && data.drawer_status === 'failed') drawerFailed(ctx);
        return followed;
    }

    return {
        ticket: ticket.state,
        drawer,
        trackResponse,
        trackDrawer,
        retry: ticket.retry,
        canRetry: ticket.canRetry,
        downloadPdf: ticket.downloadPdf,
        dismissTicket: ticket.dismiss,
        dismissDrawer,
        drawerWarning,
        createTicketTracker,
        ticketMessage,
        reasonText,
        printerStateLabel,
        jobStatusLabel,
        jobTypeLabel,
        DRAWER_WARNING,
    };
})();
