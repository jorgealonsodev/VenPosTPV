/**
 * docs/prd/agente-impresion.md S3, S4, E1 in Ajustes: the printer type «Agente de
 * impresión» (value agent), its name in the till list, the agent panel of the till
 * and the «Historial de impresión» section. The other printer types keep their
 * options and their raw name in the list.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function load(saved) {
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: {
            get: async () => ({ data: [] }),
            terminalGet: async () => ({ data: {} }),
            terminalUpdate: async (id, body) => { saved.push([id, body]); return {}; },
        },
        useSession: { session: { terminalId: 1 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return { state: context.__modal.setup({}, { emit() {} }), context };
}

async function testAgentPrinterTypeIsOfferedNamedAndSaved() {
    assert.match(source, /<option value="agent">Agente de impresión<\/option>/);
    const saved = [];
    const { state, context } = load(saved);
    assert.equal(state.printerTypeLabel('agent'), 'Agente de impresión');
    state.terminal.value = { id: 1, name: 'Caja 1', printer_type: 'agent', dataphone_mode: 'integrated' };
    await state.saveTill();
    assert.equal(saved[0][1].printer_type, 'agent');
    assert.equal(context.useSession.session.printerType, 'agent', 'this computer\'s till prints through the agent at once');
}

function testOtherPrinterTypesAreUnchanged() {
    for (const option of ['<option value="none">Sin impresora</option>', '<option value="pdf">PDF</option>', '<option value="escpos">ESC/POS (red)</option>', '<option value="system">Impresora del sistema (USB)</option>']) {
        assert.ok(source.includes(option), option);
    }
    const { state } = load([]);
    assert.deepEqual(['none', 'pdf', 'escpos', 'system'].map(state.printerTypeLabel), ['none', 'pdf', 'escpos', 'system'], 'the list keeps their names');
}

function testAgentPanelAndHistoryAreWired() {
    assert.match(source, /<print-agent-panel v-if="terminal\.printer_type === 'agent'"/);
    assert.match(source, /<print-history-panel :tills="tills"/);
    assert.match(source, /Historial de impresión/);
    assert.ok(!source.includes('La impresión la hace el servidor: una segunda caja'), 'the help text no longer says the server always prints');
    assert.match(source, /Con «Agente de impresión» imprime el ordenador de la caja/);
}

(async () => {
    await testAgentPrinterTypeIsOfferedNamedAndSaved();
    testOtherPrinterTypesAreUnchanged();
    testAgentPanelAndHistoryAreWired();
    console.log('settingsPrintAgent: ok');
})().catch((err) => { console.error(err); process.exit(1); });
