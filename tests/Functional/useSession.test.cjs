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
    cashReportX = () => Promise.resolve({ data: {} }),
}) {
    const storage = new Map([['venpostpv_session', JSON.stringify({ screen: storedScreen })]]);
    const timers = [];
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
            cashReportX,
        },
        setTimeout(fn, ms) { timers.push({ fn, ms }); return timers.length; },
        clearTimeout() {},
        setInterval() { return 1; },
        clearInterval() {},
        console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__store = useSession;`, context, {
        filename: 'useSession.js',
    });

    return { timers,  store: context.__store, storage };
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

async function testAReloadWaitsForTheServerBeforeShowingAScreen() {
    let answer;
    const { store } = loadStore({
        cashCurrent: () => new Promise((resolve) => { answer = resolve; }),
    });

    assert.equal(store.session.screen, 'checking', 'neither «Abrir caja» nor the sale screen until the server answers');

    answer({ data: { open: true, session_id: 5, terminal_id: 7, opened_at: '2026-11-05T09:00:00', opening_cash: 100 } });
    await settle();
    assert.equal(store.session.screen, 'sale');
}

async function testAStalledCheckFallsBackToAbrirCajaAndAnAnswerStillWins() {
    let answer;
    const { store, timers } = loadStore({
        cashCurrent: () => new Promise((resolve) => { answer = resolve; }),
    });
    const fallback = timers.find((t) => t.ms === 8000);
    assert.ok(fallback, 'a reload waits at most 8 s for the drawer check');

    fallback.fn();
    assert.equal(store.session.screen, 'cashOpen', 'no endless «Comprobando caja…»');

    answer({ data: { open: true, session_id: 5, terminal_id: 7, opened_at: '2026-11-05T09:00:00', opening_cash: 100 } });
    await settle();
    assert.equal(store.session.screen, 'sale', 'a late answer still opens the sale screen');
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

async function testRefreshCashTotalsReadsTheXReportByMethod() {
    const { store } = loadStore({
        cashCurrent: () => Promise.resolve({ data: { open: false } }),
        cashReportX: () => Promise.resolve({ data: {
            sales_cash: 60, sales_card: 80, sales_transfer: 25, refunds_cash: 17, refunds_card: 15, refunds_transfer: 5, net_total: 128,
            cash_in_total: 0, cash_out_total: 0, expected_cash: 143,
        } }),
    });

    await store.refreshCashTotals();

    assert.deepEqual(JSON.parse(JSON.stringify(store.session.cashTotals)), {
        sales: 60, salesCard: 80, refunds: 17, refundsCard: 15, salesTransfer: 25, refundsTransfer: 5, net: 128, cashIn: 0, cashOut: 0, expected: 143,
    });
}

async function main() {
    await testRefreshCashTotalsReadsTheXReportByMethod();
    await testCachedSaleRedirectsWhenServerSaysClosed();
    await testInitialTransportFailureDoesNotTrustCachedSale();
    await testAReloadWaitsForTheServerBeforeShowingAScreen();
    await testAStalledCheckFallsBackToAbrirCajaAndAnAnswerStillWins();
    await testCloseInvalidatesAnInFlightHydration();
    await testLogoutInvalidatesAnInFlightHydration();
    await testStaleHydrationCannotOverwriteNewOpenSession();
    await testForeignSessionConflictDoesNotEnterSale();
    await testCurrentUsersOpenSessionEntersSale();
    console.log('useSession functional harness: 8 passed');
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
