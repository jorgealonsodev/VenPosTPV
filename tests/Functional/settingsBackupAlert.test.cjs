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
        setTimeout, clearTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return context.__modal.setup({}, { emit() {} });
}

const ACCOUNT = { host: 'smtp.example.com', port: 587, encryption: 'tls', username: 'u', from_email: 'tienda@example.com', from_name: 'Tienda', has_password: true, configured: true };

async function testTheEmailSectionShowsTheBackupAlertField() {
    const match = SOURCE.match(/<div v-if="activeSection === 'email'">([\s\S]*?)<!-- ==================== SINCRONIZACIÓN/);
    assert.ok(match, 'email section found');
    assert.ok(match[1].includes('Avisar de copias de seguridad fallidas a:'), 'label shown in Ajustes → Email');
    assert.ok(match[1].includes('v-model="mail.backup_alert_email"'), 'field bound to the setting');
}

async function testTheAlertAddressIsLoadedAndSavedWithTheAccount() {
    let sent = null;
    const s = load({
        get: async (url) => (url === '/api/settings/email' ? { data: { ...ACCOUNT, backup_alert_email: 'admin@example.com' } } : { data: [] }),
        put: async (url, body) => { sent = { url, body }; return { data: { ...ACCOUNT, backup_alert_email: body.backup_alert_email } }; },
    });

    await s.loadMail();
    assert.equal(s.mail.backup_alert_email, 'admin@example.com');

    s.mail.backup_alert_email = ' otro@example.com ';
    await s.saveMail();
    assert.equal(sent.url, '/api/settings/email');
    assert.equal(sent.body.backup_alert_email, 'otro@example.com');
    assert.equal(sent.body.host, 'smtp.example.com', 'the existing fields are still sent');
    assert.equal(s.mail.backup_alert_email, 'otro@example.com');
}

async function testAnEmptyAlertAddressIsSentAsEmpty() {
    let sent = null;
    const s = load({
        get: async () => ({ data: { ...ACCOUNT } }),
        put: async (url, body) => { sent = body; return { data: { ...ACCOUNT, backup_alert_email: '' } }; },
    });
    await s.loadMail();
    assert.equal(s.mail.backup_alert_email, '', 'an older server without the field shows it empty');
    await s.saveMail();
    assert.equal(sent.backup_alert_email, '');
}

(async () => {
    await testTheEmailSectionShowsTheBackupAlertField();
    await testTheAlertAddressIsLoadedAndSavedWithTheAccount();
    await testAnEmptyAlertAddressIsSentAsEmpty();
    console.log('settingsBackupAlert tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
