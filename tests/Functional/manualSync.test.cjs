// T8 bug 2 (PRD S4): only the header sync button retries every pending upload now
// ({"manual": true}); the POS timer keeps calling /api/sync/now without it, so the
// growing wait between retries still applies while a POS is open.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');

const ROOT = path.resolve(__dirname, '../..');

function loadApiClient() {
    const calls = [];
    const window = { TPV_BASE: '', addEventListener() {}, location: {} };
    const context = {
        window,
        navigator: { onLine: true },
        fetch: (url, opts) => {
            calls.push({ url, opts });
            return Promise.resolve({ status: 200, ok: true, json: () => Promise.resolve({ data: {} }) });
        },
        JSON, Promise, Error, Object, Array, String, Number, URLSearchParams, encodeURIComponent, console,
    };
    context.globalThis = context;
    const source = fs.readFileSync(path.join(ROOT, 'public/assets/js/api.js'), 'utf8');
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });
    return { api: context.__api, calls };
}

async function testManualSyncAsksForEveryPendingUpload() {
    const { api, calls } = loadApiClient();
    await api.syncNow(true);
    assert.equal(calls[0].url, '/api/sync/now');
    assert.equal(calls[0].opts.method, 'POST');
    assert.deepEqual(JSON.parse(calls[0].opts.body), { manual: true });
}

async function testTimerSyncSendsTheSameBodyAsBefore() {
    const { api, calls } = loadApiClient();
    await api.syncNow();
    assert.deepEqual(JSON.parse(calls[0].opts.body), {}, 'without manual the body is {} as before');
}

function testOnlyTheHeaderButtonIsManual() {
    const sale = fs.readFileSync(path.join(ROOT, 'public/assets/js/components/SaleScreen.js'), 'utf8');
    const handler = sale.match(/async function handleSyncNow\(\) \{[\s\S]*?\n        \}\n/);
    assert.ok(handler, 'SaleScreen.handleSyncNow is present');
    assert.match(handler[0], /ApiClient\.syncNow\(true\)/);

    const session = fs.readFileSync(path.join(ROOT, 'public/assets/js/stores/useSession.js'), 'utf8');
    const timer = session.match(/function startStockSyncTimer\(\) \{[\s\S]*?\n    \}\n/);
    assert.ok(timer, 'useSession.startStockSyncTimer is present');
    assert.doesNotMatch(timer[0], /syncNow\(true\)/, 'the timer never forces the retries');
    assert.match(timer[0], /ApiClient\.syncNow\(\)/);
}

function testThePageLoadsTheNewVersions() {
    const page = fs.readFileSync(path.join(ROOT, 'templates/pos.html.twig'), 'utf8');
    // api.js also changed in a concurrent task, which may own its version; it only has to be new.
    assert.ok(hasCacheVersionSince(page, 'api.js', '20261103'), 'api.js');
    assert.doesNotMatch(page, /js\/api\.js\?v=20261103-sync-visibility-v1"/);
    // Later tasks bump SaleScreen again; it only has to be newer than before the manual flag.
    assert.ok(hasCacheVersionSince(page, 'components/SaleScreen.js', '20261103'), 'SaleScreen.js');
    assert.doesNotMatch(page, /SaleScreen\.js\?v=20261103-sync-manual-v2"/);
}

(async () => {
    await testManualSyncAsksForEveryPendingUpload();
    await testTimerSyncSendsTheSameBodyAsBefore();
    testOnlyTheHeaderButtonIsManual();
    testThePageLoadsTheNewVersions();
    console.log('manualSync: OK');
})().catch((err) => { console.error(err); process.exit(1); });
