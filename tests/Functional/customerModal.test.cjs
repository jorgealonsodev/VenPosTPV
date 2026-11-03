// PRD C1, C2: the customer modal searches the till's local copy (/api/customers) and
// creates new customers locally; the selected customer carries local_id and the
// PrestaShop id (null until uploaded).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = process.env.CUSTOMER_MODAL_SOURCE || path.resolve(__dirname, '../../public/assets/js/components/CustomerModal.js');
const ANA = { local_id: 1, id: 5, name: 'Ana Martínez', firstname: 'Ana', lastname: 'Martínez', email: 'ana@x.es', phone: '', synced: true };

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function load({ search = async () => ({ data: [ANA] }), create = async (c) => ({ data: { local_id: 9, id: null, name: c.firstname + ' ' + c.lastname, email: c.email, phone: c.phone, synced: false, existing: false } }) } = {}) {
    const source = fs.readFileSync(SOURCE, 'utf8');
    const calls = { search: [], create: [] };
    const watchers = [];
    const context = {
        Vue: { ref, computed, watch(src, cb) { watchers.push({ src, cb }); } },
        ApiClient: {
            customerSearch: async (q) => { calls.search.push(q); return search(q); },
            customerCreate: async (c) => { calls.create.push(c); return create(c); },
        },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        JSON, String, Array, Promise, Error, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = CustomerModal;`, context, { filename: 'CustomerModal.js' });
    const emitted = [];
    const state = context.__modal.setup({}, { emit: (name, value) => emitted.push([name, value]) });
    const typed = async (q) => { state.q.value = q; watchers.forEach(({ src, cb }) => cb(src())); await new Promise((r) => setImmediate(r)); };

    return { state, calls, emitted, typed };
}

async function testSearchReadsTheLocalCopyAndKeepsTheDefaultCustomer() {
    const { state, calls, emitted, typed } = load();
    await new Promise((r) => setImmediate(r));
    assert.deepEqual(calls.search, [''], 'opens with the local list');
    assert.deepEqual(state.filtered.value.map((c) => c.name), ['Ana Martínez', 'Consumidor final']);

    await typed('mart');
    assert.deepEqual(calls.search, ['', 'mart']);
    assert.deepEqual(state.filtered.value.map((c) => c.name), ['Ana Martínez'], 'the default only when it matches');

    state.select(ANA);
    state.select({ name: 'Consumidor final', email: '—', default: true });
    assert.deepEqual(emitted[0], ['select', ANA]);
    assert.deepEqual([emitted[1][0], { ...emitted[1][1] }], ['select', { name: 'Consumidor final', default: true }], 'the default carries no customer id');
}

async function testANewCustomerIsCreatedLocallyAndSelected() {
    const { state, calls, emitted, typed } = load({ search: async () => ({ data: [] }) });
    await typed('Marta Ruiz López');
    state.startCreate();
    assert.equal(state.mode.value, 'create');
    assert.deepEqual({ ...state.form.value }, { firstname: 'Marta', lastname: 'Ruiz López', email: '', phone: '' }, 'prefilled from the search');

    await state.create();
    assert.equal(calls.create.length, 0, 'email is required by PrestaShop');
    assert.equal(state.formError.value, 'Nombre, apellidos y email son obligatorios.');

    state.form.value.email = ' marta@x.es ';
    state.form.value.phone = '611 222 333';
    await state.create();
    assert.deepEqual(calls.create.map((c) => ({ ...c })), [{ firstname: 'Marta', lastname: 'Ruiz López', email: 'marta@x.es', phone: '611 222 333' }]);
    assert.equal(emitted[0][0], 'select');
    assert.equal(emitted[0][1].local_id, 9);
    assert.equal(emitted[0][1].id, null, 'no PrestaShop id until uploaded');
}

async function testAServerValidationErrorIsShown() {
    const { state, emitted } = load({ create: async () => { throw new Error('HTTP 422: {"error":"El nombre no puede llevar números ni símbolos."}'); } });
    state.startCreate();
    state.form.value = { firstname: 'R2', lastname: 'D2', email: 'r@x.es', phone: '' };
    await state.create();
    assert.equal(state.formError.value, 'El nombre no puede llevar números ni símbolos.');
    assert.equal(emitted.length, 0);
}

(async () => {
    await testSearchReadsTheLocalCopyAndKeepsTheDefaultCustomer();
    await testANewCustomerIsCreatedLocallyAndSelected();
    await testAServerValidationErrorIsShown();
    console.log('customerModal: 3 tests OK');
})().catch((error) => { console.error(error); process.exitCode = 1; });
