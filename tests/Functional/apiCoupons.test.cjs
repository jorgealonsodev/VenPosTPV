const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadApi({ online = true, fetchImpl }) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/api.js'), 'utf8');
    const listeners = {};
    const context = {
        window: { TPV_BASE: '', addEventListener: (name, callback) => { listeners[name] = callback; }, location: {} },
        navigator: { onLine: online },
        fetch: fetchImpl,
        Promise, Error, JSON, URLSearchParams, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__api = ApiClient;`, context, { filename: 'api.js' });
    return { api: context.__api, listeners };
}

async function testCouponRequestsRejectOfflineWithoutQueueingOrFetch() {
    let calls = 0;
    const { api, listeners } = loadApi({ online: false, fetchImpl: async () => { calls += 1; return { ok: true, status: 200, json: async () => ({}) }; } });
    await assert.rejects(() => api.couponPreview({ coupon_code: 'SAVE10' }), /not sent or queued/);
    await assert.rejects(() => api.couponCheckout({}), /not sent or queued/);
    listeners.online();
    assert.equal(calls, 0);
}

async function testOrdinaryPostsStillQueueOfflineAndReplay() {
    const calls = [];
    const { api, listeners } = loadApi({ online: false, fetchImpl: async (url) => { calls.push(url); return { ok: true, status: 200, json: async () => ({ data: {} }) }; } });
    const queued = await api.cashOpen(10);
    assert.equal(queued.offline, true);
    assert.equal(queued.queued, true);
    listeners.online();
    await Promise.resolve();
    assert.equal(calls.length, 1);
    assert.match(calls[0], /\/api\/cash\/open$/);
}

async function main() {
    await testCouponRequestsRejectOfflineWithoutQueueingOrFetch();
    await testOrdinaryPostsStillQueueOfflineAndReplay();
    console.log('apiCoupons functional harness: 2 passed');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
