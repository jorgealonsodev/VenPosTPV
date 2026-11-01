const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadApi({ online = true, fetchImpl }) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/api.js'), 'utf8');
    const listeners = {};
    const context = {
        window: { TPV_BASE: '', addEventListener: (name, callback) => { listeners[name] = callback; }, location: {} },
        navigator: { onLine: online }, fetch: fetchImpl, Promise, Error, JSON, URLSearchParams, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });
    return { api: context.__api, listeners };
}

async function testCreateCouponPostsThePublicContractWithoutOfflineQueueing() {
    let request;
    const { api } = loadApi({ online: true, fetchImpl: async (url, options) => {
        request = { url, options };
        return { ok: true, status: 201, json: async () => ({ data: { code: 'TPV-2026' } }) };
    } });
    const payload = { discount_type: 'amount', value: 10, expires_at: '2027-05-03', quantity: 1, quantity_per_user: 1 };
    const response = await api.createCoupon(payload);
    assert.equal(request.url, '/api/coupons');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.queueOnNetworkFailure, false);
    assert.deepEqual(JSON.parse(request.options.body), payload);
    assert.equal(response.data.code, 'TPV-2026');
}

async function testCreateCouponOfflineNeitherFetchesNorReplaysButOrdinaryPostsStillReplay() {
    let calls = 0;
    const { api, listeners } = loadApi({ online: false, fetchImpl: async () => { calls += 1; return { ok: true, status: 200, json: async () => ({}) }; } });
    await assert.rejects(() => api.createCoupon({}), /not sent or queued/);
    const queued = await api.cashOpen(10);
    assert.deepEqual(JSON.parse(JSON.stringify(queued)), { offline: true, queued: true });
    listeners.online();
    await Promise.resolve();
    assert.equal(calls, 1);
}

async function main() {
    await testCreateCouponPostsThePublicContractWithoutOfflineQueueing();
    await testCreateCouponOfflineNeitherFetchesNorReplaysButOrdinaryPostsStillReplay();
    console.log('apiCouponCreation functional harness: 2 passed');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
