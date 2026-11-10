const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// «Tickets → Pendientes de pago»: sales paid by transfer not received yet. An admin
// marks one received, and the sale's detail opens to print or send the ticket.
const root = path.resolve(__dirname, '../..');
const SOURCE = fs.readFileSync(path.join(root, 'public/assets/js/components/TicketListPanel.js'), 'utf8');
const API = fs.readFileSync(path.join(root, 'public/assets/js/api.js'), 'utf8');

function ref(value) { return { value }; }

const PENDING = [
    { id: 4, order_id: 70, reference: 'A26000070', amount: 45.5, created_at: '2026-11-10 12:00:00', cashier: 'Ana', terminal: 'Caja 1' },
    { id: 5, order_id: 71, reference: 'A26000071', amount: 10, created_at: '2026-11-10 12:30:00', cashier: 'Ana', terminal: 'Caja 1' },
];

function load({ role = 'admin', markFails = false } = {}) {
    const calls = [];
    const notes = [];
    const mounted = [];
    const context = {
        Vue: { ref, computed: (fn) => ({ get value() { return fn(); } }), watch() {}, onMounted: (fn) => mounted.push(fn) },
        useParkedTickets: { state: { tickets: [] }, refresh: async () => {}, removeTicket: async () => {} },
        useSession: { session: { role } },
        ApiClient: {
            pendingTransfers: async () => { calls.push('list'); return { data: PENDING.map((t) => ({ ...t })) }; },
            markTransferReceived: async (id) => { calls.push('received:' + id); if (markFails) throw new Error('HTTP 409'); return { data: { id, status: 'received' } }; },
            searchOrders: async (q) => { calls.push('search:' + q); return { data: [{ order_id: 70, reference: 'A26000070', total: 45.5 }] }; },
        },
        Number, String, Math, JSON, Promise, Error, Array, Object, Date,
    };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__panel = TicketListPanel;`, context, { filename: 'TicketListPanel.js' });
    const state = context.__panel.setup({}, { emit: (name, msg) => notes.push([name, msg]) });
    return { state, calls, notes, mount: () => Promise.all(mounted.map((fn) => fn())) };
}

const flush = () => new Promise((r) => setImmediate(r));

test('the panel has a «Pendientes de pago» tab, loaded from the start for its count', async () => {
    const { state, calls, mount } = load();
    await mount();
    await flush();

    assert.equal(state.TABS.map((t) => t.label).join('|'), 'Aparcados|Cobrados|Devueltos|Pendientes de pago');
    assert.ok(calls.includes('list'));
    assert.equal(state.pendingTransfers.value.length, 2);
    assert.match(SOURCE, /tab\.id === 'pendientes' && pendingTransfers\.length > 0/);
});

test('an admin marks one received; it leaves the list and its sale opens to send the ticket', async () => {
    const { state, calls, mount } = load();
    await mount();
    await flush();

    await state.markTransferReceived(state.pendingTransfers.value[0]);

    assert.ok(calls.includes('received:4'));
    assert.equal(state.pendingTransfers.value.map((t) => t.id).join(','), '5');
    assert.ok(calls.includes('search:A26000070'));
    assert.equal(state.selectedOrder.value.order_id, 70, 'the detail offers print, PDF, email and WhatsApp');
});

test('a cashier sees the list but cannot mark it received', async () => {
    const { state, calls, mount } = load({ role: 'cashier' });
    await mount();
    await flush();

    await state.markTransferReceived(state.pendingTransfers.value[0]);

    assert.equal(calls.some((c) => c.startsWith('received:')), false);
    assert.match(SOURCE, /<button v-if="isAdmin" @click="markTransferReceived\(t\)"/);
});

test('a failed mark reloads the list and opens nothing', async () => {
    const { state, calls, notes, mount } = load({ markFails: true });
    await mount();
    await flush();

    await state.markTransferReceived(state.pendingTransfers.value[0]);

    assert.equal(calls.filter((c) => c === 'list').length, 2);
    assert.equal(state.selectedOrder.value, null);
    assert.match(notes.at(-1)[1], /No se ha podido marcar como recibida/);
});

test('marking received is never queued offline', () => {
    assert.match(API, /markTransferReceived: \(id\) => request\('\/api\/transfers\/' \+ encodeURIComponent\(id\) \+ '\/received', \{ method: 'POST', body: JSON\.stringify\(\{\}\), queueOnNetworkFailure: false \}\)/);
});
