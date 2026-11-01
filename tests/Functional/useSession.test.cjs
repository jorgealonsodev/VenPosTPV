const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../public/assets/js/stores/useSession.js'),
    'utf8'
);

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

function loadStore({
    storedScreen = 'sale',
    cashCurrent,
    cashOpen = () => Promise.resolve({ data: {} }),
    cashClose = () => Promise.resolve({}),
    authLogout = () => Promise.resolve({}),
}) {
    const storage = new Map([['venpostpv_session', JSON.stringify({ screen: storedScreen })]]);
    const window = {
        __SESSION__: { user_id: 1, terminal_id: 7 },
        TPV_BASE: '',
        addEventListener() {},
        location: {},
    };
    const context = {
        window,
        navigator: { onLine: true },
        localStorage: {
            getItem: (key) => storage.get(key) || null,
            setItem: (key, value) => storage.set(key, value),
        },
        document: { documentElement: { setAttribute() {} } },
        Vue: { reactive: (value) => value },
        ApiClient: {
            cashCurrent,
            cashOpen,
            syncStatus: () => Promise.resolve({ state: 'ok' }),
            syncNow: () => Promise.resolve({}),
            post: () => Promise.resolve({}),
            authLogout,
            cashClose,
        },
        setTimeout() { return 1; },
        clearTimeout() {},
        setInterval() { return 1; },
        clearInterval() {},
        console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__store = useSession;`, context, {
        filename: 'useSession.js',
    });

    return { store: context.__store, storage };
}

async function testCachedSaleRedirectsWhenServerSaysClosed() {
    const { store } = loadStore({
        cashCurrent: () => Promise.resolve({ data: { open: false } }),
    });

    await settle();

    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(store.session.sessionId, null);
    assert.equal(store.session.openingCash, 0);
}

async function testInitialTransportFailureDoesNotTrustCachedSale() {
    const { store } = loadStore({
        cashCurrent: () => Promise.reject(new Error('network unavailable')),
    });

    await settle();

    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(store.session.sessionId, null);
}

async function testCloseInvalidatesAnInFlightHydration() {
    const staleCurrent = deferred();
    const { store } = loadStore({
        cashCurrent: () => staleCurrent.promise,
    });

    await store.closeCash(20);
    staleCurrent.resolve({
        data: {
            open: true,
            session_id: 12,
            terminal_id: 7,
            opened_at: '2026-05-05 10:00:00',
            opening_cash: 20,
        },
    });
    await settle();

    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(store.session.sessionId, null);
}

async function testLogoutInvalidatesAnInFlightHydration() {
    const staleCurrent = deferred();
    const { store } = loadStore({
        cashCurrent: () => staleCurrent.promise,
    });

    await store.logout();
    staleCurrent.resolve({
        data: {
            open: true,
            session_id: 12,
            terminal_id: 7,
            opened_at: '2026-05-05 10:00:00',
            opening_cash: 20,
        },
    });
    await settle();

    assert.equal(store.session.user, null);
    assert.equal(store.session.screen, 'login');
    assert.equal(store.session.sessionId, null);
}

async function testStaleHydrationCannotOverwriteNewOpenSession() {
    const staleCurrent = deferred();
    let cashCurrentCalls = 0;
    const { store } = loadStore({
        cashCurrent: () => {
            cashCurrentCalls += 1;
            return cashCurrentCalls === 1
                ? staleCurrent.promise
                : Promise.resolve({
                    data: {
                        open: true,
                        session_id: 12,
                        terminal_id: 7,
                        opened_at: '2026-05-05 10:00:00',
                        opening_cash: 20,
                    },
                });
        },
        cashOpen: () => Promise.resolve({
            data: {
                session_id: 12,
                terminal_id: 7,
                opened_at: '2026-05-05 10:00:00',
                opening_cash: 20,
            },
        }),
    });

    await store.openCash(20);
    staleCurrent.resolve({ data: { open: false } });
    await settle();

    assert.equal(store.session.screen, 'sale');
    assert.equal(store.session.sessionId, 12);
    assert.equal(store.session.openingCash, 20);
}

async function testForeignSessionConflictDoesNotEnterSale() {
    const { store } = loadStore({
        cashCurrent: () => Promise.resolve({ data: { open: false } }),
        cashOpen: () => Promise.reject(new Error('HTTP 409: Cash session already open for terminal')),
    });

    await settle();
    await assert.rejects(() => store.openCash(10), /HTTP 409/);

    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(store.session.sessionId, null);
}

async function testCurrentUsersOpenSessionEntersSale() {
    const { store } = loadStore({
        storedScreen: 'cashOpen',
        cashCurrent: () => Promise.resolve({
            data: {
                open: true,
                session_id: 9,
                terminal_id: 7,
                opened_at: '2026-05-05 10:00:00',
                opening_cash: 15,
                expected_cash: 15,
            },
        }),
    });

    await settle();

    assert.equal(store.session.screen, 'sale');
    assert.equal(store.session.sessionId, 9);
    assert.equal(store.session.terminalId, 7);
}

async function main() {
    await testCachedSaleRedirectsWhenServerSaysClosed();
    await testInitialTransportFailureDoesNotTrustCachedSale();
    await testCloseInvalidatesAnInFlightHydration();
    await testLogoutInvalidatesAnInFlightHydration();
    await testStaleHydrationCannotOverwriteNewOpenSession();
    await testForeignSessionConflictDoesNotEnterSale();
    await testCurrentUsersOpenSessionEntersSale();
    console.log('useSession functional harness: 7 passed');
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
