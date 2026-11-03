/**
 * docs/prd/agente-impresion.md E1 steps 1-4, E5, E6, S3, S5, U1, AC12: the print
 * agent of a till in Ajustes → Cajas (components/PrintAgentPanel.js).
 */
process.env.TZ = 'Europe/Madrid';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

const UNPAIRED = { paired: false, connected: false, last_seen: null, last_ip: null, version: null, hostname: null, os: null, paired_at: null, printers: [], update_available: false, latest_version: null, download_url: null };
const PAIRED = {
    paired: true, connected: true, last_seen: '2026-11-03T17:00:00+01:00', last_ip: '192.168.1.20', version: '0.1.0', hostname: 'PC-CAJA1', os: 'windows',
    paired_at: '2026-11-01T10:00:00+01:00', printers: [], update_available: false, latest_version: '0.1.0', download_url: 'https://tpv.example/agente/windows',
};

function load(initialStatus, extraApi = {}) {
    const root = path.resolve(__dirname, '../../public/assets/js');
    const clock = makeClock(Date.parse('2026-11-03T17:00:00+01:00'));
    const server = { status: { ...initialStatus }, jobs: new Map() };
    const calls = [];
    const hooks = { mounted: [], unmounted: [] };
    const api = {
        printAgentStatus: async (id) => { calls.push(['status', id]); return { ...server.status }; },
        printAgentPairingCode: async (id) => { calls.push(['pairingCode', id]); return { code: '482913', expires_at: new Date(clock.now + 600000).toISOString() }; },
        printAgentRevoke: async (id) => { calls.push(['revoke', id]); server.status = { ...UNPAIRED }; return {}; },
        printAgentTestPrint: async (id) => { calls.push(['testPrint', id]); return { print_status: 'queued', print_job_id: 50 }; },
        printJob: async (id) => ({ ...(server.jobs.get(id) || { status: 'pending', claimed_at: null }) }),
        ...extraApi,
    };
    const context = {
        ...sandboxGlobals(clock),
        Vue: { ref, computed, reactive: (v) => v, onMounted: (fn) => hooks.mounted.push(fn), onUnmounted: (fn) => hooks.unmounted.push(fn) },
        ApiClient: api,
    };
    context.globalThis = context;
    vm.runInNewContext(fs.readFileSync(path.join(root, 'stores/usePrintJobs.js'), 'utf8') + '\nglobalThis.__jobs = usePrintJobs;', context, { filename: 'usePrintJobs.js' });
    vm.runInNewContext(fs.readFileSync(path.join(root, 'components/PrintAgentPanel.js'), 'utf8') + '\nglobalThis.__panel = PrintAgentPanel;', context, { filename: 'PrintAgentPanel.js' });
    const state = context.__panel.setup({ terminalId: 3, terminalName: 'Caja 1' });
    hooks.mounted.forEach((fn) => fn());
    return { state, clock, server, calls, hooks };
}

const count = (calls, name) => calls.filter((c) => c[0] === name).length;

async function testPairingCodeCountsDownAndSwitchesToConnected() {
    const { state, clock, server, calls } = load(UNPAIRED);
    await clock.advance(1);
    assert.equal(state.agentLine.value, 'Agente: sin conectar');
    await state.connect();
    assert.equal(count(calls, 'pairingCode'), 1);
    assert.equal(state.formattedCode.value, '482 913');
    assert.equal(state.countdown.value, '10:00');
    await clock.advance(61000);
    assert.equal(state.countdown.value, '8:59', 'the countdown runs');
    const polled = count(calls, 'status');
    assert.ok(polled >= 30, 'status asked every 2 s while the code is shown: ' + polled);
    server.status = { ...PAIRED, paired_at: '2026-11-03T17:01:05+01:00' };
    await clock.advance(2000);
    assert.equal(state.formattedCode.value, '', 'the code goes away');
    assert.equal(state.connectedText.value, 'Conectado: PC-CAJA1', 'AC12: without reloading');
    const after = count(calls, 'status');
    await clock.advance(10000);
    assert.equal(count(calls, 'status'), after, 'polling stops once connected');
}

async function testAnExpiredCodeOffersAnotherOne() {
    const { state, clock, calls } = load(UNPAIRED);
    await clock.advance(1);
    await state.connect();
    await clock.advance(600000);
    assert.equal(state.codeExpired.value, true);
    assert.equal(state.countdown.value, '0:00');
    const polled = count(calls, 'status');
    await clock.advance(10000);
    assert.equal(count(calls, 'status'), polled, 'no polling for a dead code');
    await state.generateCode();
    assert.equal(count(calls, 'pairingCode'), 2, '«Generar otro código»');
    assert.equal(state.codeExpired.value, false);
    assert.equal(state.countdown.value, '10:00');
}

async function testRepairingAsksBeforeDisconnectingTheCurrentAgent() {
    const { state, clock, calls } = load(PAIRED);
    await clock.advance(1);
    await state.connect();
    assert.equal(count(calls, 'pairingCode'), 0, 'nothing happens before the answer');
    assert.equal(state.confirmText.value, 'Esto desconectará el agente de PC-CAJA1. ¿Continuar?');
    state.cancelConfirm();
    assert.equal(state.confirmText.value, '');
    await state.connect();
    await state.connect();
    assert.equal(count(calls, 'pairingCode'), 1, '«Continuar»');
    assert.equal(state.formattedCode.value, '482 913');
}

async function testDisconnectAsksAndRevokes() {
    const { state, clock, calls } = load(PAIRED);
    await clock.advance(1);
    await state.revoke();
    assert.equal(count(calls, 'revoke'), 0);
    assert.equal(state.confirmText.value, '¿Desconectar el agente de PC-CAJA1? Esta caja dejará de imprimir hasta que conectes otro.');
    await state.revoke();
    assert.equal(count(calls, 'revoke'), 1);
    assert.equal(state.agentLine.value, 'Agente: sin conectar', 'the status is read again');
}

async function testStatusLines() {
    const status = {
        ...PAIRED, connected: false, last_seen: '2026-11-03T17:05:00+01:00', version: '0.1.0', update_available: true, latest_version: '0.2.0',
        printers: [
            { target: 'ticket', name: 'EPSON TM-T20', state: 'ready', detail: null },
            { target: 'label', name: 'Zebra', state: 'not_found', detail: null },
            { target: 'ticket', name: 'X', state: 'paused', detail: null },
        ],
    };
    const { state, clock } = load(status);
    await clock.advance(1);
    assert.equal(state.agentLine.value, 'Sin conexión desde las 17:05');
    assert.deepEqual(state.printerLines.value.map((p) => p.text), ['Tickets: EPSON TM-T20 — Lista', 'Etiquetas: Zebra — No encontrada', 'Tickets: X — En pausa']);
    assert.equal(state.printerLines.value[1].hint, 'Impresora no encontrada: elígela de nuevo desde el icono');
    assert.equal(state.versionLine.value, 'Versión 0.1.0');
    assert.equal(state.updateText.value, 'Hay una versión nueva (0.2.0)');
    assert.equal(state.lastUseLine.value, 'Último uso: 03/11/2026 17:05 desde 192.168.1.20');
    assert.equal(state.downloadUrl.value, 'https://tpv.example/agente/windows');

    const connected = load(PAIRED);
    await connected.clock.advance(1);
    assert.equal(connected.state.agentLine.value, 'Agente: Conectado');
    assert.equal(connected.state.updateText.value, '');

    const unpublished = load(UNPAIRED);
    await unpublished.clock.advance(1);
    assert.equal(unpublished.state.downloadUrl.value, '');
    assert.equal(unpublished.state.downloadNote.value, 'Descarga disponible al publicar el agente');
}

async function testTestPrintIsFollowed() {
    const { state, clock, server, calls } = load(PAIRED);
    await clock.advance(1);
    await state.testPrint();
    assert.equal(count(calls, 'testPrint'), 1);
    assert.equal(state.testMessage.value, 'Imprimiendo prueba…');
    server.jobs.set(50, { status: 'done', claimed_at: 'x' });
    await clock.advance(1000);
    assert.equal(state.testMessage.value, 'Prueba enviada');
}

async function testTestPrintOnANonAgentTillExplainsWhy() {
    const { state, clock } = load(PAIRED, {
        printAgentTestPrint: async () => { throw new Error('HTTP 409: {"error":"La impresora de esta caja no es el agente de impresión","code":"printer_not_agent"}'); },
    });
    await clock.advance(1);
    await state.testPrint();
    assert.equal(state.actionError.value, 'Guarda la caja con «Agente de impresión» antes de imprimir la prueba.');
}

async function testLeavingThePanelStopsItsTimers() {
    const { state, clock, calls, hooks } = load(UNPAIRED);
    await clock.advance(1);
    await state.connect();
    hooks.unmounted.forEach((fn) => fn());
    const polled = count(calls, 'status');
    await clock.advance(10000);
    assert.equal(count(calls, 'status'), polled);
}

(async () => {
    await testPairingCodeCountsDownAndSwitchesToConnected();
    await testAnExpiredCodeOffersAnotherOne();
    await testRepairingAsksBeforeDisconnectingTheCurrentAgent();
    await testDisconnectAsksAndRevokes();
    await testStatusLines();
    await testTestPrintIsFollowed();
    await testTestPrintOnANonAgentTillExplainsWhy();
    await testLeavingThePanelStopsItsTimers();
    console.log('printAgentPanel: ok');
})().catch((err) => { console.error(err); process.exit(1); });
