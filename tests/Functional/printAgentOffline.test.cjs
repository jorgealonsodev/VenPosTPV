/**
 * docs/prd/agente-impresion.md C4: on an agent till the sale screen shows a
 * permanent notice while the agent has not connected for more than 2 minutes. The
 * status is read with the outbox poll of useSession; other printers never ask.
 */
process.env.TZ = 'Europe/Madrid';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { makeClock, sandboxGlobals } = require('./support/fakeClock.cjs');

function loadSession(printerType, agentStatus) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/stores/useSession.js'), 'utf8');
    const clock = makeClock(Date.parse('2026-11-03T17:10:00+01:00'));
    const asked = [];
    const status = { current: agentStatus };
    const context = {
        ...sandboxGlobals(clock),
        window: { __SESSION__: { user_id: 1, terminal_id: 7, printer_type: printerType }, TPV_BASE: '', addEventListener() {}, location: {} },
        navigator: { onLine: true },
        localStorage: { getItem: () => null, setItem() {} },
        document: { documentElement: { setAttribute() {} } },
        Vue: { reactive: (v) => v },
        ApiClient: {
            cashCurrent: async () => ({ data: { open: true, session_id: 3, terminal_id: 7, opened_at: '2026-11-03 09:00:00', opening_cash: 50 } }),
            syncStatus: async () => ({ state: 'ok' }),
            outboxStatus: async () => ({ data: { pending: 0, incidents: 0, blocked: 0 } }),
            syncNow: async () => ({}),
            post: async () => ({}),
            printAgentStatus: async (id) => {
                asked.push(id);
                if (status.current instanceof Error) throw status.current;
                return status.current;
            },
        },
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__store = useSession;`, context, { filename: 'useSession.js' });
    return { store: context.__store, clock, asked, status };
}

async function testOfflineMoreThanTwoMinutesShowsTheNotice() {
    const { store, clock, asked } = loadSession('agent', { paired: true, connected: false, last_seen: '2026-11-03T17:05:00+01:00' });
    await clock.advance(1);
    assert.deepEqual(asked, [7], 'asked with the outbox poll');
    assert.equal(store.session.printAgent.offline, true);
    assert.equal(store.printAgentNotice(), 'La impresora no está conectada desde las 17:05. Puedes seguir cobrando: el ticket se ofrece en PDF.');
}

async function testRecentlySeenIsNotOfflineYet() {
    const { store, clock } = loadSession('agent', { paired: true, connected: false, last_seen: '2026-11-03T17:09:00+01:00' });
    await clock.advance(1);
    assert.equal(store.session.printAgent.offline, false, '1 minute without connecting is not 2');
    assert.equal(store.printAgentNotice(), '');
}

async function testNoticeLeavesWhenTheAgentComesBack() {
    const { store, clock, status, asked } = loadSession('agent', { paired: false, connected: false, last_seen: null });
    await clock.advance(1);
    assert.equal(store.printAgentNotice(), 'La impresora no está conectada. Conéctala desde Ajustes → Cajas.');
    status.current = { paired: true, connected: true, last_seen: '2026-11-03T17:10:05+01:00', hostname: 'PC-CAJA1' };
    await clock.advance(10000);
    assert.equal(asked.length, 2, 'the same 10-second cadence as the outbox');
    assert.equal(store.printAgentNotice(), '');
}

async function testAFailedReadKeepsTheLastState() {
    const { store, clock, status } = loadSession('agent', { paired: true, connected: false, last_seen: '2026-11-03T17:00:00+01:00' });
    await clock.advance(1);
    status.current = new Error('HTTP 503');
    await clock.advance(10000);
    assert.equal(store.session.printAgent.offline, true);
}

async function testOtherPrintersNeverAsk() {
    const { store, clock, asked } = loadSession('escpos', null);
    await clock.advance(30000);
    assert.deepEqual(asked, []);
    assert.equal(store.printAgentNotice(), '');
}

(async () => {
    await testOfflineMoreThanTwoMinutesShowsTheNotice();
    await testRecentlySeenIsNotOfflineYet();
    await testNoticeLeavesWhenTheAgentComesBack();
    await testAFailedReadKeepsTheLastState();
    await testOtherPrintersNeverAsk();
    console.log('printAgentOffline: ok');
})().catch((err) => { console.error(err); process.exit(1); });
