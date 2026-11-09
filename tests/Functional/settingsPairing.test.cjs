const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function ref(value) { return { value }; }

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function load(api) {
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: Object.assign({ get: async () => ({ data: [] }), post: async () => ({ data: {} }), terminalGet: async () => ({ data: {} }), terminalUpdate: async () => ({}) }, api),
        useSession: { session: { terminalId: 1 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout, clearTimeout, Promise, Number, String, Math, JSON, console, Array, Object, Date, RegExp,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return context.__modal.setup({}, { emit() {} });
}

function aboutSection() {
    const match = SOURCE.match(/<div v-if="activeSection === 'acerca'">([\s\S]*?)\n {24}<\/div>\n\n {20}<\/div>/);
    assert.ok(match, 'Acerca de section found');
    return match[1];
}

function testTheAboutSectionOffersRePairing() {
    const section = aboutSection();
    assert.ok(section.includes('Volver a emparejar con la tienda'), 'the button is in Ajustes → Acerca de');
    assert.ok(section.includes('@click="openPairing()"'));
    assert.ok(section.includes('v-model="pairing.code"'), 'the code field');
    assert.ok(section.includes('v-model="pairing.shopUrl"'), 'the shop address field');
    assert.ok(section.includes('@click="runPairing()"'));
    assert.ok(section.includes('{{ pairing.error }}'));
    assert.ok(section.includes('{{ pairing.done }}'));
}

async function testOpeningPrefillsTheCurrentShopAddress() {
    const urls = [];
    const s = load({ get: async (url) => { urls.push(url); return url === '/api/settings/prestashop' ? { data: { shop_url: 'https://shop.example.com', tpv_url: 'https://tpv.example.com' } } : { data: [] }; } });

    await s.openPairing();

    assert.ok(urls.includes('/api/settings/prestashop'));
    assert.equal(s.pairing.open, true);
    assert.equal(s.pairing.shopUrl, 'https://shop.example.com');
    assert.equal(s.pairing.code, '');
}

async function testPairingPostsTheCodeAndShowsTheResultWithoutSecrets() {
    const posts = [];
    const s = load({ post: async (url, body) => { posts.push({ url, body }); return { data: { shop_url: 'https://shop.example.com', shop_id: 1, module_version: '0.8.0', backup: '.env.bak-20261110-101500' } }; } });
    s.pairing.open = true;
    s.pairing.shopUrl = 'https://shop.example.com';
    s.pairing.code = ' 123 456 ';

    await s.runPairing();

    // Objects from the vm context: compare their JSON.
    assert.equal(JSON.stringify(posts), JSON.stringify([{ url: '/api/settings/prestashop/pair', body: { shop_url: 'https://shop.example.com', code: '123456' } }]));
    assert.equal(s.pairing.error, '');
    assert.equal(s.pairing.open, false);
    assert.equal(s.pairing.code, '', 'the code is forgotten');
    assert.equal(s.pairing.done, 'Emparejado con https://shop.example.com (TPV Connect 0.8.0). Las claves nuevas ya están en .env; la copia del anterior es .env.bak-20261110-101500.');
    assert.equal(s.pairing.running, false);
}

async function testAMalformedCodeIsRefusedWithoutARequest() {
    const s = load({ post: async () => { throw new Error('no request expected'); } });
    s.pairing.open = true;
    s.pairing.code = '12345';

    await s.runPairing();

    assert.equal(s.pairing.error, 'El código de emparejamiento tiene 6 cifras.');
}

async function testTheShopErrorIsShown() {
    const s = load({ post: async () => { throw new Error('HTTP 422: {"error":"El código de emparejamiento no es correcto o ha caducado."}'); } });
    s.pairing.open = true;
    s.pairing.code = '123456';

    await s.runPairing();

    assert.equal(s.pairing.error, 'El código de emparejamiento no es correcto o ha caducado.');
    assert.equal(s.pairing.open, true);
    assert.equal(s.pairing.running, false);
}

(async () => {
    testTheAboutSectionOffersRePairing();
    await testOpeningPrefillsTheCurrentShopAddress();
    await testPairingPostsTheCodeAndShowsTheResultWithoutSecrets();
    await testAMalformedCodeIsRefusedWithoutARequest();
    await testTheShopErrorIsShown();
    console.log('settingsPairing tests passed');
})().catch((err) => { console.error(err); process.exit(1); });
