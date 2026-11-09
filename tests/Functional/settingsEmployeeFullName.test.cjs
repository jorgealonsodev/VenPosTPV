const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Ajustes → Empleados: besides the user name, «Nombre y apellidos», printed on the ticket as the cashier.
const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');
const TEMPLATE = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');

function ref(value) { return { value }; }

function load() {
    const sent = [];
    const context = {
        Vue: { ref, reactive: (v) => v, onMounted() {}, computed: (fn) => ({ get value() { return fn(); } }) },
        ApiClient: {
            get: async () => ({ data: [] }),
            post: async (url, body) => { sent.push({ method: 'post', url, body }); return {}; },
            put: async (url, body) => { sent.push({ method: 'put', url, body }); return {}; },
            terminalGet: async () => ({ data: {} }),
        },
        useSession: { session: { terminalId: 99 }, startStockSyncTimer() {}, startStockPushTimer() {} },
        setTimeout: () => 0, Promise, Number, String, Math, JSON, console, Array, Object,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    const state = context.__modal.setup({}, { emit() {} });
    return { state, sent };
}

test('a new employee is created with the full name', async () => {
    const { state, sent } = load();
    Object.assign(state.createForm, { name: 'ana', full_name: '  Ana García López ', pin: '12345678', role: 'cashier' });

    await state.handleCreate();

    const call = sent.find((c) => c.url === '/api/employees');
    assert.equal(call.method, 'post');
    assert.equal(call.body.name, 'ana');
    assert.equal(call.body.full_name, 'Ana García López');
    assert.equal(state.createForm.full_name, '', 'the form is emptied after creating');
});

test('without a full name the employee is created as before', async () => {
    const { state, sent } = load();
    Object.assign(state.createForm, { name: 'ana', pin: '12345678', role: 'cashier' });

    await state.handleCreate();

    const call = sent.find((c) => c.url === '/api/employees');
    assert.deepEqual(Object.keys(call.body).sort(), ['name', 'pin', 'role']);
});

test('editing loads and saves the full name', async () => {
    const { state, sent } = load();
    state.startEdit({ id: 5, name: 'juan', full_name: 'Juan Pérez', code: null, role: 'cashier', active: true });
    assert.equal(state.editForm.full_name, 'Juan Pérez');

    state.editForm.full_name = 'Juan Pérez García';
    await state.handleSave(5);

    const call = sent.find((c) => c.url === '/api/employees/5');
    assert.equal(call.body.full_name, 'Juan Pérez García');
    assert.equal(call.body.name, 'juan');

    state.startEdit({ id: 6, name: 'luis', code: null, role: 'cashier', active: true });
    assert.equal(state.editForm.full_name, '', 'an employee without a full name (or before the migration) edits as empty');
});

test('the forms show «Nombre de usuario» and «Nombre y apellidos», and the list shows the full name', () => {
    assert.ok(source.includes('v-model="createForm.full_name"'));
    assert.ok(source.includes('v-model="editForm.full_name"'));
    assert.ok(!/placeholder="Nombre"/.test(source), 'the user name field is labelled «Nombre de usuario»');
    assert.equal((source.match(/placeholder="Nombre de usuario"/g) || []).length, 2);
    assert.equal((source.match(/placeholder="Nombre y apellidos"/g) || []).length, 2);
    assert.ok(source.includes('{{ emp.full_name }}'));
});

test('the SettingsModal.js version was bumped', () => {
    const match = TEMPLATE.match(/components\/SettingsModal\.js\?v=(20261109-[a-z0-9-]+)/);
    assert.ok(match, 'SettingsModal.js has a 20261109- version');
});
