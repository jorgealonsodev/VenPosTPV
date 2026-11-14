// fiscal-journal S5, A3: while the database is behind the fiscal journal the server
// answers 423 `fiscal_journal_behind` to every request that would register something.
// One place shows it: whatever call gets that answer, the till shows a persistent
// notice saying what happened, that nothing can be registered and where an
// administrator recovers the documents. The per-screen handling keeps its error as is.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');

const ROOT = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');
const MESSAGE = 'Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).';
const LOCKED = { error: MESSAGE, code: 'fiscal_journal_behind', retry: false };

function respond(status, body) {
    return { ok: status >= 200 && status < 300, status, json: async () => body, text: async () => JSON.stringify(body) };
}

function load(answer) {
    const calls = [];
    const context = {
        Vue: { reactive: (v) => v, computed: (fn) => ({ get value() { return fn(); } }) },
        window: { TPV_BASE: '', addEventListener() {}, location: {} },
        navigator: { onLine: true },
        fetch: async (url, opts) => { calls.push([opts.method, url]); return answer(url, opts); },
        Promise, Error, JSON, URLSearchParams, console, Object, String, Array,
    };
    context.globalThis = context;
    vm.runInNewContext(
        `${read('public/assets/js/components/FiscalJournalBlockNotice.js')}\n${read('public/assets/js/api.js')}\nglobalThis.__api = ApiClient; globalThis.__block = useFiscalJournalBlock; globalThis.__notice = FiscalJournalBlockNotice;`,
        context,
        { filename: 'fiscal-journal-block.js' },
    );
    return { api: context.__api, block: context.__block, notice: context.__notice, calls };
}

test('any call answered 423 fiscal_journal_behind shows the notice and keeps its own error', async () => {
    const { api, block } = load(() => respond(423, LOCKED));
    assert.equal(block.state.blocked, false);

    const error = await api.cashMovement({ type: 'out', amount: 5 }).catch((e) => e);

    assert.equal(block.state.blocked, true);
    assert.equal(error.status, 423);
    assert.deepEqual(error.body, LOCKED);
    assert.equal(error.message, 'HTTP 423: ' + JSON.stringify(LOCKED), 'the per-screen handling reads the same error as before');
});

test('the timer calls that swallow their errors still show it', async () => {
    const { api, block } = load(() => respond(423, LOCKED));
    await api.syncNow().catch(() => {});
    assert.equal(block.state.blocked, true);
});

test('other errors do not show it', async () => {
    for (const [status, body] of [[423, { error: 'Locked', code: 'other' }], [500, { error: 'Internal server error' }], [409, { code: 'device_not_linked' }]]) {
        const { api, block } = load(() => respond(status, body));
        await api.post('/api/cash/open', {}).catch(() => {});
        assert.equal(block.state.blocked, false, String(status));
    }
});

test('it stays until the journal status says the database is complete again', async () => {
    let guard = 'behind_journal';
    const { api, block } = load((url) => (url === '/api/fiscal/journal'
        ? respond(200, { data: { status: 'ok', guard: { state: guard, missing: null } } })
        : respond(423, LOCKED)));
    await api.cashOpen(100).catch(() => {});

    await api.get('/api/fiscal/journal');
    assert.equal(block.state.blocked, true);
    guard = 'ok';
    await api.get('/api/fiscal/journal');
    assert.equal(block.state.blocked, false);
});

test('a completed recovery clears it, a dry run does not', async () => {
    let status = 'pending';
    const { api, block } = load((url) => (url === '/api/fiscal/journal/replay'
        ? respond(200, { data: { status, applied: status === 'done' ? 3 : 0, documents: [], message: '' } })
        : respond(423, LOCKED)));
    await api.cashMovement({}).catch(() => {});

    await api.post('/api/fiscal/journal/replay', { apply: false });
    assert.equal(block.state.blocked, true);
    status = 'done';
    await api.post('/api/fiscal/journal/replay', { apply: true });
    assert.equal(block.state.blocked, false);
});

test('a change accepted again (recovered from another till) clears it; the allowed calls do not', async () => {
    let locked = true;
    const { api, block } = load((url) => {
        if (url.indexOf('/api/auth/') === 0 || url.indexOf('/api/device/') === 0) return respond(200, { data: {} });
        return locked ? respond(423, LOCKED) : respond(200, { data: {} });
    });
    await api.syncNow().catch(() => {});
    await api.post('/api/auth/login', { pin: '1234' });
    await api.post('/api/device/restore', { token: 't' });
    assert.equal(block.state.blocked, true, 'login and device calls pass even while blocked');

    locked = false;
    await api.syncNow();
    assert.equal(block.state.blocked, false);
});

test('the notice says what happened, that nothing can be registered and where to recover', () => {
    const { notice, block } = load(() => respond(200, {}));
    const template = notice.template.replace(/\s+/g, ' ');
    assert.match(template, /v-if="blocked"/);
    assert.match(template, /role="alert"/);
    assert.match(template, /la base de datos está por detrás del diario fiscal/);
    assert.match(template, /No se puede registrar nada/);
    assert.match(template, /hasta recuperar los documentos/);
    assert.match(template, /Ajustes → VERI\*FACTU → Diario fiscal → «Recuperar documentos»/);
    assert.match(template, /solo administradores/);

    const state = notice.setup();
    assert.equal(state.blocked.value, false);
    block.lock();
    assert.equal(state.blocked.value, true);
});

test('the notice is on every screen and the page loads the new scripts', () => {
    const app = read('public/assets/js/app.js');
    assert.match(app, /<fiscal-journal-block-notice \/>\s*<login-screen/, 'before the screens, outside their v-if chain');
    assert.match(app, /app\.component\('fiscal-journal-block-notice', FiscalJournalBlockNotice\)/);

    const page = read('templates/pos.html.twig');
    for (const file of ['api.js', 'app.js', 'components/FiscalJournalBlockNotice.js', 'components/IncidentsScreen.js', 'stores/useSession.js']) {
        assert.ok(hasCacheVersionSince(page, file, '20261114'), file);
    }
    assert.doesNotMatch(page, /api\.js\?v=20261114-journal-block-v1"/, 'api.js changed again: its cache version too');
    assert.ok(page.indexOf('components/FiscalJournalBlockNotice.js') < page.indexOf('assets/js/app.js'));
});

// Final verification gap 6: the notice lived only in page memory. A reload of a
// blocked till shows it at once: the journal status the till asks for on load
// (GET /api/fiscal/journal, the guard state for any logged-in user) sets it.
test('a journal status saying the till is blocked shows the notice at once', async () => {
    for (const state of ['behind_journal', 'behind_shop', 'journal_missing']) {
        const { api, block } = load(() => respond(200, { data: { guard: { state } } }));
        await api.fiscalJournalStatus();
        assert.equal(block.state.blocked, true, state);
    }
    const { api, block } = load(() => respond(403, { error: 'Acceso denegado — solo administradores' }));
    await api.fiscalJournalStatus().catch(() => {});
    assert.equal(block.state.blocked, false, 'an older server that refuses it changes nothing');
});

function loadSession(serverSession, answer) {
    const calls = [];
    const context = {
        Vue: { reactive: (v) => v, computed: (fn) => ({ get value() { return fn(); } }) },
        window: { TPV_BASE: '', addEventListener() {}, location: {}, __SESSION__: serverSession },
        navigator: { onLine: true },
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        document: { documentElement: { setAttribute() {} } },
        fetch: async (url, opts) => { calls.push([opts.method, url]); return answer(url, opts); },
        setTimeout() { return 1; }, clearTimeout() {}, setInterval() { return 1; }, clearInterval() {},
        Promise, Error, JSON, URLSearchParams, console, Object, String, Array, Number,
    };
    context.globalThis = context;
    vm.runInNewContext(
        `${read('public/assets/js/components/FiscalJournalBlockNotice.js')}\n${read('public/assets/js/api.js')}\n${read('public/assets/js/stores/useSession.js')}\nglobalThis.__block = useFiscalJournalBlock; globalThis.__session = useSession;`,
        context,
        { filename: 'fiscal-journal-block-session.js' },
    );
    return { block: context.__block, session: context.__session, calls };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('on page load a logged-in till asks the journal status and shows the notice when blocked', async () => {
    const { block, calls } = loadSession({ user_id: 3, role: 'cashier', terminal_id: 1 }, (url) => (url === '/api/fiscal/journal'
        ? respond(200, { data: { guard: { state: 'behind_journal' } } })
        : respond(200, { data: { open: false } })));
    await settle();

    assert.ok(calls.some(([method, url]) => method === 'GET' && url === '/api/fiscal/journal'), JSON.stringify(calls));
    assert.equal(block.state.blocked, true);
});

test('a page without a logged-in user does not ask', async () => {
    const { block, calls } = loadSession(null, () => respond(200, { data: { guard: { state: 'behind_journal' } } }));
    await settle();

    assert.ok(!calls.some(([, url]) => url === '/api/fiscal/journal'), JSON.stringify(calls));
    assert.equal(block.state.blocked, false);
});

test('logging in asks again, so a till blocked before the login shows it', async () => {
    const { block, session, calls } = loadSession(null, (url) => (url === '/api/fiscal/journal'
        ? respond(200, { data: { guard: { state: 'behind_shop' } } })
        : respond(200, { data: {} })));
    session.login('Ana', '****');
    await settle();

    assert.ok(calls.some(([, url]) => url === '/api/fiscal/journal'));
    assert.equal(block.state.blocked, true);
});
