const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function load(api) {
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: Object.assign({ get: async () => ({ data: [] }), terminalGet: async () => ({ data: {} }), terminalUpdate: async () => ({}) }, api),
        useSession: { session: { terminalId: 1 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout, clearTimeout, Promise, Number, String, Math, JSON, console, Array, Object, Date,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return context.__modal.setup({}, { emit() {} });
}

const STATUS = {
    complete: false,
    steps: [
        { id: 'migrations', description: 'Actualizar la base de datos (migraciones)', status: 'done', reason: null, attempted_at: null, next_attempt_at: null },
        { id: 'free-item-products', description: 'Productos genéricos del artículo libre en PrestaShop', status: 'failed', reason: 'Sin conexión con PrestaShop.', attempted_at: '2026-11-09T10:00:00+01:00', next_attempt_at: '2026-11-09T10:15:00+01:00' },
    ],
};

function aboutSection() {
    const match = SOURCE.match(/<div v-if="activeSection === 'acerca'">([\s\S]*?)\n {24}<\/div>\n\n {20}<\/div>/);
    assert.ok(match, 'Acerca de section found');
    return match[1];
}

function testTheAboutSectionShowsTheInstallationStatus() {
    const section = aboutSection();
    assert.ok(section.includes('Estado de la instalación'), 'title shown in Ajustes → Acerca de');
    assert.ok(section.includes('v-for="step in provisioning.steps"'), 'one row per step');
    assert.ok(section.includes('{{ step.description }}'), 'the step description');
    assert.ok(section.includes('{{ provisioningLabel(step) }}'), 'its state');
    assert.ok(section.includes('{{ step.reason }}'), 'the reason of a pending step');
    assert.ok(section.includes('{{ provisioningError }}'), 'a load error');
    assert.ok(/@click="activeSection = 'acerca'; loadProvisioning\(\)"/.test(SOURCE), 'opening Acerca de loads the status');
}

async function testTheStatusIsLoadedFromTheAdminEndpoint() {
    const urls = [];
    const s = load({ get: async (url) => { urls.push(url); return url === '/api/settings/provisioning' ? { data: STATUS } : { data: [] }; } });

    await s.loadProvisioning();

    assert.ok(urls.includes('/api/settings/provisioning'));
    assert.equal(s.provisioning.value.steps.length, 2);
    assert.equal(s.provisioningError.value, '');
    assert.equal(s.provisioningLabel(STATUS.steps[0]), 'Hecho');
    assert.equal(s.provisioningLabel(STATUS.steps[1]), 'Pendiente');
    assert.equal(s.provisioningLabel({ status: 'pending', reason: null }), 'Pendiente');
    assert.equal(s.provisioningLabel({ status: 'blocked', reason: 'Las migraciones han fallado.' }), 'Bloqueado');
    assert.equal(s.provisioningLabel({ status: 'failed', reason: 'Sin conexión con PrestaShop.' }), 'Pendiente');
    assert.equal(s.provisioningLoading.value, false);
}

async function testALoadErrorIsShownInsteadOfTheSteps() {
    const s = load({ get: async (url) => { if (url === '/api/settings/provisioning') throw new Error('HTTP 403: {"error":"Acceso denegado — solo administradores"}'); return { data: [] }; } });

    await s.loadProvisioning();

    assert.equal(s.provisioning.value, null);
    assert.equal(s.provisioningError.value, 'Acceso denegado — solo administradores');
    assert.equal(s.provisioningLoading.value, false);
}

(async () => {
    testTheAboutSectionShowsTheInstallationStatus();
    await testTheStatusIsLoadedFromTheAdminEndpoint();
    await testALoadErrorIsShownInsteadOfTheSteps();
    console.log('settingsProvisioning tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
