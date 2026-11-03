// S10: tills and devices — link screen, cookie restore from localStorage, and the
// Tienda / Cajas split in Settings.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = (rel) => fs.readFileSync(path.resolve(__dirname, '../../public/assets/js', rel), 'utf8');
const settle = async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); };
const fakeVue = () => ({
    reactive: (v) => v,
    ref: (value) => ({ value }),
    computed: (fn) => ({ get value() { return fn(); } }),
    onMounted() {},
});

function loadSession({ serverSession, storage = {}, post = () => Promise.resolve({}) }) {
    const store = new Map(Object.entries(storage));
    const calls = { cashCurrent: 0, posts: [], reloads: 0 };
    const window = {
        __SESSION__: serverSession, TPV_BASE: '', addEventListener() {},
        location: { reload() { calls.reloads++; } },
    };
    const context = {
        window,
        navigator: { onLine: true },
        localStorage: {
            getItem: (k) => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => store.set(k, v),
            removeItem: (k) => store.delete(k),
        },
        document: { documentElement: { setAttribute() {} } },
        Vue: fakeVue(),
        ApiClient: {
            cashCurrent: () => { calls.cashCurrent++; return Promise.resolve({ data: { open: false } }); },
            post: (url, body) => { calls.posts.push([url, body]); return post(url, body); },
            syncStatus: () => Promise.resolve({}),
        },
        setTimeout() { return 1; }, clearTimeout() {}, setInterval() { return 1; }, clearInterval() {},
        console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${read('stores/useSession.js')}\nglobalThis.__store = useSession;`, context, { filename: 'useSession.js' });

    return { store: context.__store, storage: store, calls };
}

async function testAnUnlinkedComputerShowsTheLinkScreenAndAsksForNoDrawer() {
    const { store, calls } = loadSession({ serverSession: { user_id: 1, role: 'admin', device_linked: false, terminal_id: null } });
    await settle();

    assert.equal(store.session.screen, 'deviceLink');
    assert.equal(store.session.deviceLinked, false);
    assert.equal(calls.cashCurrent, 0, '/api/cash/current would answer device_not_linked');
    assert.equal(calls.posts.length, 0, 'nothing to restore without a stored token');
}

async function testTheCookieIsPutBackFromTheTokenKeptInLocalStorage() {
    const token = 'c'.repeat(64);
    const { calls } = loadSession({
        serverSession: { user_id: 1, role: 'cashier', device_linked: false },
        storage: { venpostpv_device_token: token },
    });
    await settle();

    assert.deepEqual(JSON.parse(JSON.stringify(calls.posts)), [['/api/device/restore', { token }]]);
    assert.equal(calls.reloads, 1, 'reload so the computer goes straight to its till');
}

async function testATokenOfAMovedTillIsForgotten() {
    const { storage, calls } = loadSession({
        serverSession: { user_id: 1, role: 'cashier', device_linked: false },
        storage: { venpostpv_device_token: 'd'.repeat(64) },
        post: () => Promise.reject(new Error('HTTP 404: {"code":"device_not_linked"}')),
    });
    await settle();

    assert.equal(calls.reloads, 0);
    assert.equal(storage.has('venpostpv_device_token'), false);
}

async function testAnIssuedTokenIsMirroredAndALinkedComputerKeepsItsTill() {
    const token = 'e'.repeat(64);
    const { store, storage, calls } = loadSession({
        serverSession: { user_id: 1, device_linked: true, terminal_id: 4, terminal_name: 'Caja 2', ticket_letter: 'B', device_token: token },
    });
    await settle();

    assert.equal(storage.get('venpostpv_device_token'), token);
    assert.equal(store.session.terminalId, 4);
    assert.equal(store.session.terminalName, 'Caja 2');
    assert.equal(store.session.screen, 'cashOpen');
    assert.equal(calls.cashCurrent, 1);
}

function loadLinkScreen({ role, tills = [], post }) {
    const calls = { posts: [], gets: [], reloads: 0, remembered: [], logout: 0 };
    const window = { TPV_BASE: '/tpv', location: { href: '', reload() { calls.reloads++; } } };
    const context = {
        window,
        Vue: fakeVue(),
        useSession: {
            session: { role },
            rememberDeviceToken: (t) => calls.remembered.push(t),
            logout: () => { calls.logout++; },
        },
        ApiClient: {
            get: (url) => { calls.gets.push(url); return Promise.resolve({ data: { linked: false, tills } }); },
            post: (url, body) => { calls.posts.push([url, body]); return post(url, body); },
            authLogout: () => Promise.resolve({}),
        },
        Promise, JSON, Number, String, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${read('components/DeviceLinkScreen.js')}\nglobalThis.__c = DeviceLinkScreen;`, context, { filename: 'DeviceLinkScreen.js' });
    const state = context.__c.setup({}, { emit() {} });

    return { state, calls, window };
}

async function testACashierIsSentToLogInAsAdministrator() {
    const { state, calls, window } = loadLinkScreen({ role: 'cashier', post: () => Promise.resolve({}) });
    await state.load();
    assert.equal(state.isAdmin.value, false);
    assert.equal(calls.gets.length, 0, 'a cashier gets no till list');

    await state.loginAsAdmin();
    assert.equal(window.location.href, '/tpv/app/login?admin=1');
}

async function testAnAdminLinksAFreeTillAndMovingATakenOneNeedsConfirmation() {
    const tills = [
        { id: 3, name: 'Caja 1', ticket_letter: 'A', device: { linked_at: '2026-11-03 09:00:00', last_seen_at: '2026-11-03 10:00:00' }, this_device: false },
        { id: 4, name: 'Caja 2', ticket_letter: 'B', device: null, this_device: false },
    ];
    const { state, calls } = loadLinkScreen({ role: 'admin', tills, post: (url, body) => Promise.resolve({ data: { terminal_id: body.terminal_id, token: 'f'.repeat(64) } }) });
    await state.load();
    assert.equal(state.tills.value.length, 2);

    await state.choose(tills[0]);
    assert.equal(calls.posts.length, 0, 'a till on another computer is never taken without "Pasar la caja a este equipo"');
    assert.equal(state.confirmingId.value, 3);

    await state.confirmMove(tills[0]);
    assert.deepEqual(JSON.parse(JSON.stringify(calls.posts[0])), ['/api/device/link', { terminal_id: 3, move: true }]);
    assert.deepEqual(calls.remembered, ['f'.repeat(64)]);
    assert.equal(calls.reloads, 1);

    await state.choose(tills[1]);
    assert.deepEqual(JSON.parse(JSON.stringify(calls.posts[1])), ['/api/device/link', { terminal_id: 4, move: false }]);
}

async function testAServerSideConflictAlsoAsksForTheMove() {
    const { state } = loadLinkScreen({
        role: 'admin',
        post: () => Promise.reject(new Error('HTTP 409: {"code":"till_has_device","error":"Esta caja ya está en otro equipo"}')),
    });
    const ok = await state.choose({ id: 7, device: null });
    assert.equal(ok, false);
    assert.equal(state.confirmingId.value, 7);
}

async function testANewTillIsCreatedAndLinked() {
    const { state, calls } = loadLinkScreen({
        role: 'admin',
        post: (url) => Promise.resolve(url === '/api/terminal' ? { data: { id: 9 } } : { data: { terminal_id: 9, token: 'a'.repeat(64) } }),
    });
    state.newTill.name = 'Caja 3';
    state.newTill.ticket_letter = '1';
    assert.equal(await state.createAndLink(), false);
    assert.equal(state.error.value, 'La letra de caja debe ser una letra de la A a la Z');

    state.newTill.ticket_letter = 'c';
    await state.createAndLink();
    assert.deepEqual(JSON.parse(JSON.stringify(calls.posts)), [
        ['/api/terminal', { name: 'Caja 3', ticket_letter: 'C' }],
        ['/api/device/link', { terminal_id: 9, move: false }],
    ]);
}

function loadSettings() {
    const calls = { updates: [], posts: [], reloads: 0 };
    const window = { location: { reload() { calls.reloads++; } } };
    const context = {
        window,
        Vue: fakeVue(),
        ApiClient: {
            get: () => Promise.resolve({ data: [] }),
            terminalGet: () => Promise.resolve({ data: {} }),
            terminalUpdate: (id, body) => { calls.updates.push([id, body]); return Promise.resolve({}); },
            post: (url, body) => { calls.posts.push([url, body]); return Promise.resolve({ data: { token: 'b'.repeat(64) } }); },
        },
        useSession: { session: { terminalId: 1 }, startStockSyncTimer() {}, startStockPushTimer() {}, rememberDeviceToken() {} },
        FileReader: function () {}, setTimeout, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${read('components/SettingsModal.js')}\nglobalThis.__m = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const state = context.__m.setup({}, { emit() {} });
    state.terminal.value = {
        id: 1, name: 'Caja 1', ticket_letter: 'a', dataphone_mode: 'integrated', printer_type: 'pdf', ticket_width_px: null,
        store_name: 'Moda', legal_name: 'Moda SL', tax_id: 'B12345674', store_address: 'Calle 1', ticket_header: 'H', ticket_footer: 'F',
        ticket_logo: null, refund_window_days: 30, ticket_code: 'qr',
    };

    return { state, calls };
}

const SHOP_KEYS = ['store_name', 'legal_name', 'tax_id', 'store_address', 'ticket_header', 'ticket_footer', 'ticket_logo', 'refund_window_days', 'ticket_code'];

async function testTiendaSavesOnlyShopDataAndCajasOnlyTillData() {
    const { state, calls } = loadSettings();

    await state.saveShop();
    await state.saveTill();

    assert.deepEqual(Object.keys(calls.updates[0][1]).sort(), [...SHOP_KEYS].sort());
    const till = calls.updates[1][1];
    SHOP_KEYS.forEach((key) => assert.equal(key in till, false, key + ' is shop data'));
    assert.equal(till.name, 'Caja 1');
    assert.equal(till.ticket_letter, 'A');
    assert.equal(till.dataphone_mode, 'integrated');
    assert.equal(till.printer_type, 'pdf');
}

async function testCajasMovesATillHereOnlyAfterConfirming() {
    const { state, calls } = loadSettings();
    const other = { id: 2, name: 'Caja 2', device: { linked_at: 'x' }, this_device: false };

    await state.moveTillHere(other);
    assert.equal(calls.posts.length, 0);
    assert.equal(state.confirmMoveId.value, 2);

    await state.moveTillHere(other);
    assert.deepEqual(JSON.parse(JSON.stringify(calls.posts[0])), ['/api/device/link', { terminal_id: 2, move: true }]);
    assert.equal(calls.reloads, 1);
}

async function testCajasCreatesATill() {
    const { state, calls } = loadSettings();
    state.newTill.name = ' Caja 3 ';
    state.newTill.ticket_letter = 'd';

    assert.equal(await state.createTill(), true);
    assert.deepEqual(JSON.parse(JSON.stringify(calls.posts[0])), ['/api/terminal', { name: 'Caja 3', ticket_letter: 'D' }]);
}

(async () => {
    await testAnUnlinkedComputerShowsTheLinkScreenAndAsksForNoDrawer();
    await testTheCookieIsPutBackFromTheTokenKeptInLocalStorage();
    await testATokenOfAMovedTillIsForgotten();
    await testAnIssuedTokenIsMirroredAndALinkedComputerKeepsItsTill();
    await testACashierIsSentToLogInAsAdministrator();
    await testAnAdminLinksAFreeTillAndMovingATakenOneNeedsConfirmation();
    await testAServerSideConflictAlsoAsksForTheMove();
    await testANewTillIsCreatedAndLinked();
    await testTiendaSavesOnlyShopDataAndCajasOnlyTillData();
    await testCajasMovesATillHereOnlyAfterConfirming();
    await testCajasCreatesATill();
    console.log('deviceLink tests passed: 11');
})().catch((err) => { console.error(err); process.exit(1); });
