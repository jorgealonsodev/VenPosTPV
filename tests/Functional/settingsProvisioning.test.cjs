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

const CRON_JOBS = [
    { id: 'outbox', label: 'Subida de ventas', daily_at: null, last_attempt_at: '2026-11-14T12:00:10+01:00', last_success_at: '2026-11-14T12:00:10+01:00', last_error: null },
    { id: 'catalog', label: 'Catálogo completo', daily_at: '03:00', last_attempt_at: '2026-11-14T03:00:10+01:00', last_success_at: null, last_error: 'Sync failed: shop unreachable' },
];

function testTheAboutSectionShowsTheScheduledTask() {
    const section = aboutSection();
    assert.ok(section.includes('La tarea programada no se ha ejecutado nunca. Crea en el panel del hosting una tarea cada minuto con <code>tpv:cron --env=prod</code>.'), 'never run: how to create it');
    assert.ok(section.includes('La tarea programada no se ejecuta desde {{ formatDateTime(provisioning.cron.last_run_at) }}.'), 'stopped: since when');
    assert.ok(section.includes('Tarea programada en marcha'), 'running');
    assert.ok(section.includes('v-for="job in (provisioning.cron && provisioning.cron.jobs) || []"'), 'one row per job');
    assert.ok(section.includes('{{ job.label }}') && section.includes('{{ cronSchedule(job) }}'), 'its name and schedule');
    assert.ok(section.includes("{{ job.last_success_at ? formatDateTime(job.last_success_at) : 'todavía no' }}"), 'its last success');
    assert.ok(/<div v-if="job\.last_error" style="[^"]*color:var\(--red\)[^"]*">\{\{ job\.last_error \}\}<\/div>/.test(section), 'its error in red');
}

async function testTheThreeStatesOfTheScheduledTask() {
    const s = load({ get: async () => ({ data: STATUS }) });

    assert.equal(s.cronState(undefined), 'never', 'an older server without «cron»');
    assert.equal(s.cronState({ last_run_at: null, running: false, jobs: CRON_JOBS }), 'never');
    assert.equal(s.cronState({ last_run_at: '2026-11-14T12:00:10+01:00', running: false, jobs: CRON_JOBS }), 'stopped');
    assert.equal(s.cronState({ last_run_at: '2026-11-14T12:00:10+01:00', running: true, jobs: CRON_JOBS }), 'running');
    assert.equal(s.formatDateTime('2026-11-14T12:00:10+01:00'), '14/11/2026 12:00', 'the shop time it last ran');
}

async function testEachJobShowsItsScheduleAndItsError() {
    const cron = { last_run_at: '2026-11-14T12:00:10+01:00', running: true, jobs: CRON_JOBS };
    const s = load({ get: async (url) => (url === '/api/settings/provisioning' ? { data: Object.assign({}, STATUS, { cron }) } : { data: [] }) });

    await s.loadProvisioning();

    const jobs = s.provisioning.value.cron.jobs;
    assert.equal(s.cronSchedule(jobs[0]), 'cada minuto');
    assert.equal(s.cronSchedule(jobs[1]), 'cada noche, 03:00');
    assert.equal(jobs[1].last_error, 'Sync failed: shop unreachable');
    assert.equal(jobs[1].last_success_at, null);
}

(async () => {
    testTheAboutSectionShowsTheInstallationStatus();
    await testTheStatusIsLoadedFromTheAdminEndpoint();
    await testALoadErrorIsShownInsteadOfTheSteps();
    testTheAboutSectionShowsTheScheduledTask();
    await testTheThreeStatesOfTheScheduledTask();
    await testEachJobShowsItsScheduleAndItsError();
    console.log('settingsProvisioning tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
