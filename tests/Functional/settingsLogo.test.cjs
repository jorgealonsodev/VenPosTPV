const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

// FileReader stub: returns a data URI built from the fake file's type and name.
class FakeFileReader {
    readAsDataURL(file) { setTimeout(() => this.onload({ target: { result: 'data:' + file.type + ';base64,' + Buffer.from(file.name).toString('base64') } }), 0); }
}

function load() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: { get: async () => ({ data: [] }), terminalGet: async () => ({ data: {} }), terminalUpdate: async () => ({}) },
        useSession: { session: { terminalId: 1 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        FileReader: FakeFileReader, setTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const state = context.__modal.setup({}, { emit() {} });
    state.terminal.value = { id: 1, ticket_logo: null };
    return state;
}

const wait = () => new Promise((r) => setTimeout(r, 5));
const file = (name, type, size = 1000) => ({ name, type, size });

async function testButtonDropAndPasteAllSetTheLogo() {
    const s = load();
    s.handleLogoUpload({ target: { files: [file('a.png', 'image/png')] } });
    await wait();
    assert.match(s.terminal.value.ticket_logo, /^data:image\/png;base64,/);

    s.terminal.value.ticket_logo = null;
    let prevented = false;
    s.handleLogoDrop({ preventDefault: () => { prevented = true; }, dataTransfer: { files: [file('b.jpg', 'image/jpeg')] } });
    await wait();
    assert.ok(prevented, 'drop must not navigate the page to the image');
    assert.match(s.terminal.value.ticket_logo, /^data:image\/jpeg;base64,/);

    s.terminal.value.ticket_logo = null;
    s.handleLogoPaste({ clipboardData: { items: [{ kind: 'string', type: 'text/plain', getAsFile: () => null }, { kind: 'file', type: 'image/png', getAsFile: () => file('c.png', 'image/png') }] } });
    await wait();
    assert.match(s.terminal.value.ticket_logo, /^data:image\/png;base64,/);
}

async function testUnsupportedOrTooLargeImagesAreRejectedWithAMessage() {
    const s = load();
    s.handleLogoDrop({ preventDefault() {}, dataTransfer: { files: [file('x.svg', 'image/svg+xml')] } });
    await wait();
    assert.equal(s.terminal.value.ticket_logo, null, 'SVG cannot be printed by the receipt printer');
    assert.match(s.logoError.value, /PNG|JPG/);

    s.handleLogoUpload({ target: { files: [file('big.png', 'image/png', 3 * 1024 * 1024)] } });
    await wait();
    assert.equal(s.terminal.value.ticket_logo, null);
    assert.match(s.logoError.value, /2 MB/);
}

(async () => {
    await testButtonDropAndPasteAllSetTheLogo();
    await testUnsupportedOrTooLargeImagesAreRejectedWithAMessage();
    console.log('settingsLogo tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
