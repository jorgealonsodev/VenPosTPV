/**
 * PrintAgentPanel — the print agent of one till in Ajustes → Cajas
 * (docs/prd/agente-impresion.md E1 steps 1-4, E5, E6, S3, S5, U1, AC12).
 *
 * Download link, «Conectar agente» with a 6-digit code and a 10-minute countdown
 * (status read every 2 s while it is shown, so the panel switches to «Conectado:
 * <equipo>» by itself), in-page confirmation before re-pairing or disconnecting,
 * the agent's status, printers, version, last use (date and IP) and a test print.
 * The code is shown only here and never stored.
 */
const PrintAgentPanel = {
    props: ['terminalId', 'terminalName'],
    setup(props) {
        const CODE_TTL_MS = 600000;
        const POLL_MS = 2000;
        const status = Vue.ref(null);
        const loadError = Vue.ref('');
        const actionError = Vue.ref('');
        const busy = Vue.ref(false);
        const code = Vue.ref(null); // { code, deadline }
        const now = Vue.ref(Date.now());
        const confirm = Vue.ref(''); // '' | 'repair' | 'revoke'
        const connectedText = Vue.ref('');
        const tester = usePrintJobs.createTicketTracker();
        let pairedAtBefore = null;
        let countdownTimer = null;
        let pollTimer = null;

        const time = (iso) => new Date(iso).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        const date = (iso) => new Date(iso).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const sameDay = (iso) => new Date(iso).toDateString() === new Date(Date.now()).toDateString();

        function errorText(e, fallback) {
            const match = /^HTTP (\d+): ([\s\S]*)$/.exec((e && e.message) || '');
            if (!match) return fallback;
            try {
                const body = JSON.parse(match[2]);
                if (body && body.code === 'printer_not_agent') return 'Guarda la caja con «Agente de impresión» antes de imprimir la prueba.';
                if (match[1] === '403') return 'Solo un administrador puede hacerlo.';
                return (body && body.error) || fallback;
            } catch (err) {
                return fallback;
            }
        }

        async function loadStatus() {
            try {
                status.value = await ApiClient.printAgentStatus(props.terminalId);
                loadError.value = '';
            } catch (e) {
                loadError.value = errorText(e, 'No se ha podido leer el estado del agente');
            }
            return status.value;
        }

        function stopCode() {
            if (countdownTimer !== null) clearInterval(countdownTimer);
            if (pollTimer !== null) clearInterval(pollTimer);
            countdownTimer = null;
            pollTimer = null;
        }

        const remainingMs = Vue.computed(() => (code.value ? Math.max(0, code.value.deadline - now.value) : 0));
        const codeExpired = Vue.computed(() => !!code.value && remainingMs.value <= 0);
        const formattedCode = Vue.computed(() => (code.value ? code.value.code.slice(0, 3) + ' ' + code.value.code.slice(3) : ''));
        const countdown = Vue.computed(() => {
            const seconds = Math.ceil(remainingMs.value / 1000);
            return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
        });

        function tick() {
            now.value = Date.now();
            if (code.value && now.value >= code.value.deadline) stopCode();
        }

        /** E1 step 3: a new pairing of this till (another paired_at) is the agent of this code. */
        async function pollPairing() {
            const data = await loadStatus();
            if (!code.value) return;
            if (data && data.paired && data.paired_at !== pairedAtBefore) {
                stopCode();
                code.value = null;
                connectedText.value = 'Conectado: ' + (data.hostname || 'equipo sin nombre');
            }
        }

        async function generateCode() {
            actionError.value = '';
            confirm.value = '';
            busy.value = true;
            try {
                pairedAtBefore = status.value && status.value.paired ? status.value.paired_at : null;
                const created = await ApiClient.printAgentPairingCode(props.terminalId);
                const expires = Date.parse(created.expires_at);
                // Never longer than the code lives, whatever this computer's clock says.
                const deadline = Math.min(Number.isNaN(expires) ? Infinity : expires, Date.now() + CODE_TTL_MS);
                stopCode();
                connectedText.value = '';
                code.value = { code: String(created.code), deadline };
                now.value = Date.now();
                countdownTimer = setInterval(tick, 1000);
                pollTimer = setInterval(pollPairing, POLL_MS);
            } catch (e) {
                actionError.value = errorText(e, 'No se ha podido generar el código');
            } finally {
                busy.value = false;
            }
        }

        /** E1 step 2, and step 4 when the till already has an agent. */
        async function connect() {
            if (status.value && status.value.paired && confirm.value !== 'repair') {
                confirm.value = 'repair';
                return false;
            }
            await generateCode();
            return true;
        }

        /** E5: the key stops working at once. */
        async function revoke() {
            if (confirm.value !== 'revoke') {
                confirm.value = 'revoke';
                return false;
            }
            confirm.value = '';
            actionError.value = '';
            busy.value = true;
            try {
                await ApiClient.printAgentRevoke(props.terminalId);
                stopCode();
                code.value = null;
                connectedText.value = '';
                await loadStatus();
                return true;
            } catch (e) {
                actionError.value = errorText(e, 'No se ha podido desconectar el agente');
                return false;
            } finally {
                busy.value = false;
            }
        }

        function cancelConfirm() {
            confirm.value = '';
        }

        async function testPrint() {
            actionError.value = '';
            try {
                const response = await ApiClient.printAgentTestPrint(props.terminalId);
                if (response && response.print_job_id) tester.track(response.print_job_id, { kind: 'test' });
            } catch (e) {
                actionError.value = errorText(e, 'No se ha podido enviar la prueba');
            }
        }

        const hostname = Vue.computed(() => (status.value && status.value.hostname) || 'este equipo');
        const confirmText = Vue.computed(() => {
            if (confirm.value === 'repair') return 'Esto desconectará el agente de ' + hostname.value + '. ¿Continuar?';
            if (confirm.value === 'revoke') return '¿Desconectar el agente de ' + hostname.value + '? Esta caja dejará de imprimir hasta que conectes otro.';
            return '';
        });

        const agentLine = Vue.computed(() => {
            const s = status.value;
            if (!s) return '';
            if (!s.paired) return 'Agente: sin conectar';
            if (s.connected) return 'Agente: Conectado';
            if (!s.last_seen) return 'Agente: emparejado, todavía sin conexión';
            return sameDay(s.last_seen) ? 'Sin conexión desde las ' + time(s.last_seen) : 'Sin conexión desde el ' + date(s.last_seen) + ' a las ' + time(s.last_seen);
        });
        const agentOk = Vue.computed(() => !!(status.value && status.value.paired && status.value.connected));

        const TARGETS = { ticket: 'Tickets', label: 'Etiquetas' };
        const printerLines = Vue.computed(() => {
            const printers = status.value && Array.isArray(status.value.printers) ? status.value.printers : [];
            return printers.map((p) => ({
                text: (TARGETS[p.target] || String(p.target || 'Impresora')) + ': ' + (p.name || 'sin nombre') + ' — ' + usePrintJobs.printerStateLabel(p.state),
                ok: p.state === 'ready',
                // S5: renamed or removed in the system.
                hint: p.state === 'not_found' ? 'Impresora no encontrada: elígela de nuevo desde el icono' : '',
            }));
        });
        const versionLine = Vue.computed(() => (status.value && status.value.version ? 'Versión ' + status.value.version : ''));
        const updateText = Vue.computed(() => {
            const s = status.value;
            if (!s || !s.update_available) return '';
            return 'Hay una versión nueva' + (s.latest_version ? ' (' + s.latest_version + ')' : '');
        });
        const lastUseLine = Vue.computed(() => {
            const s = status.value;
            if (!s || !s.paired || !s.last_seen) return '';
            return 'Último uso: ' + date(s.last_seen) + ' ' + time(s.last_seen) + (s.last_ip ? ' desde ' + s.last_ip : '');
        });
        const downloadUrl = Vue.computed(() => (status.value && status.value.download_url) || '');
        const downloadNote = Vue.computed(() => (downloadUrl.value ? '' : 'Descarga disponible al publicar el agente'));
        const testMessage = Vue.computed(() => usePrintJobs.ticketMessage(tester.state.job, { printing: 'Imprimiendo prueba…', sent: 'Prueba enviada' }));
        const testProblem = Vue.computed(() => !!(tester.state.job && tester.state.job.problem));

        Vue.onMounted(() => { loadStatus(); });
        Vue.onUnmounted(() => {
            stopCode();
            tester.dismiss();
        });

        return {
            status, loadError, actionError, busy, code, formattedCode, countdown, codeExpired, connectedText,
            confirm, confirmText, agentLine, agentOk, printerLines, versionLine, updateText, lastUseLine,
            downloadUrl, downloadNote, testMessage, testProblem,
            loadStatus, connect, generateCode, revoke, cancelConfirm, testPrint,
        };
    },
    template: `
        <div class="print-agent-panel">
            <div class="print-agent-title">Agente de impresión</div>
            <div v-if="loadError" class="print-agent-error">{{ loadError }}</div>

            <div v-if="status" class="print-agent-status">
                <div :class="agentOk ? 'print-agent-ok' : 'print-agent-bad'">{{ agentLine }}</div>
                <div v-for="(p, i) in printerLines" :key="i">
                    <div :class="p.ok ? '' : 'print-agent-bad'">{{ p.text }}</div>
                    <div v-if="p.hint" class="print-agent-hint">{{ p.hint }}</div>
                </div>
                <div v-if="versionLine">{{ versionLine }}
                    <template v-if="updateText"> · <a v-if="downloadUrl" :href="downloadUrl" target="_blank" rel="noopener" class="print-agent-link">{{ updateText }}</a><span v-else>{{ updateText }}</span></template>
                </div>
                <div v-if="lastUseLine" class="print-agent-hint">{{ lastUseLine }}</div>
            </div>

            <div class="print-agent-row">
                <a v-if="downloadUrl" :href="downloadUrl" target="_blank" rel="noopener" class="tpv-btn print-btn-lg">Descargar programa de impresión (Windows / Linux)</a>
                <template v-else>
                    <button class="tpv-btn print-btn-lg" disabled>Descargar programa de impresión (Windows / Linux)</button>
                    <span class="print-agent-hint">{{ downloadNote }}</span>
                </template>
            </div>

            <div v-if="code" class="print-agent-code-box">
                <template v-if="!codeExpired">
                    <div class="print-agent-hint">Escribe este código en el programa de impresión del ordenador de la caja:</div>
                    <div class="pairing-code" aria-live="polite">{{ formattedCode }}</div>
                    <div class="print-agent-hint">Caduca en <strong class="pay-num">{{ countdown }}</strong></div>
                </template>
                <div v-else class="print-agent-bad">El código ha caducado.</div>
                <button class="tpv-btn print-btn-lg" :disabled="busy" @click="generateCode">Generar otro código</button>
            </div>
            <div v-if="connectedText" class="tpv-banner tpv-banner-ok" role="status"><icon name="check" :size="18" /> {{ connectedText }}</div>

            <div v-if="confirmText" class="tpv-banner tpv-banner-warn" role="alert">
                <span class="print-notice-line print-notice-warn">{{ confirmText }}</span>
                <button v-if="confirm === 'repair'" class="tpv-btn tpv-btn-primary print-btn-lg" :disabled="busy" @click="connect">Continuar</button>
                <button v-else class="tpv-btn tpv-btn-danger print-btn-lg" :disabled="busy" @click="revoke">Desconectar</button>
                <button class="tpv-btn print-btn-lg" @click="cancelConfirm">Cancelar</button>
            </div>

            <div class="print-agent-row">
                <button v-if="!code" class="tpv-btn tpv-btn-primary print-btn-lg" :disabled="busy" @click="connect">Conectar agente</button>
                <button v-if="status && status.paired" class="tpv-btn print-btn-lg" :disabled="busy" @click="testPrint"><icon name="print" :size="18" /> Imprimir prueba</button>
                <button v-if="status && status.paired" class="tpv-btn print-btn-lg" :disabled="busy" @click="revoke">Desconectar agente</button>
            </div>
            <div v-if="testMessage" :class="testProblem ? 'print-agent-bad' : 'print-agent-hint'" role="status">{{ testMessage }}</div>
            <div v-if="actionError" class="print-agent-error" role="alert">{{ actionError }}</div>
        </div>
    `
};
