const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// «Cupones» (sale screen header): the till's coupons, newest first, with their state.
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/components/CouponListModal.js'), 'utf8');
const SALE = fs.readFileSync(path.join(root, 'public/assets/js/components/SaleScreen.js'), 'utf8');
const APP = fs.readFileSync(path.join(root, 'public/assets/js/app.js'), 'utf8');
const TEMPLATE = fs.readFileSync(path.join(root, 'templates/pos.html.twig'), 'utf8');

function ref(value) { return { value }; }

function load(answers) {
    const calls = [];
    const opened = [];
    let mounted = null;
    const timers = [];
    const context = {
        Vue: { ref, onMounted(fn) { mounted = fn; } },
        ApiClient: {
            listCoupons: async (q, page) => {
                calls.push({ q, page });
                const answer = answers.shift();
                if (answer instanceof Error) throw answer;
                return answer;
            },
            couponPrint: async (id) => { calls.push({ print: id }); return { data: { fallback: 'pdf' } }; },
            couponPdf: (id) => { opened.push(id); },
        },
        setTimeout: (fn) => { timers.push(fn); return timers.length; },
        clearTimeout: () => {},
        Promise, Number, String, JSON, Object, Array, RegExp,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = CouponListModal;`, context, { filename: 'CouponListModal.js' });
    const state = context.__modal.setup({}, { emit() {} });
    return { state, calls, opened, mount: () => mounted(), runTimers: () => timers.splice(0).forEach((f) => f()) };
}

const coupon = (over) => ({ id: 1, code: 'TPV-AAAA', type: 'amount', value: 20, expires_at: '2027-01-31', created_at: '2026-11-03 10:15:00', cashier: 'Ana García', terminal: 'Tienda', status: 'available', uses_left: 1, ...over });
const flush = () => new Promise((r) => setImmediate(r));

test('the list loads on open and shows the main data of each coupon', async () => {
    const { state, calls, mount } = load([{ data: [coupon(), coupon({ id: 2, type: 'percentage', value: 10, status: 'available', uses_left: 3 })], has_more: false, status_checked: true }]);
    mount();
    await flush();

    assert.deepEqual(calls, [{ q: '', page: 1 }]);
    assert.equal(state.coupons.value.length, 2);
    assert.equal(state.formatValue(state.coupons.value[0]), '20,00 €');
    assert.equal(state.formatValue(state.coupons.value[1]), '10 %');
    assert.equal(state.formatDate('2027-01-31'), '31/01/2027');
    assert.equal(state.formatDate('2026-11-03 10:15:00'), '03/11/2026 10:15');
    assert.equal(state.status(state.coupons.value[0]).label, 'Disponible');
    assert.equal(state.usesText(state.coupons.value[0]), '', 'a single use is not repeated');
    assert.equal(state.usesText(state.coupons.value[1]), '3 usos');
    assert.equal(state.statusChecked.value, true);
});

test('every state has its label, and an unknown one says it was not checked', () => {
    const { state } = load([]);
    const labels = ['available', 'used', 'expired', 'disabled', 'deleted', 'unknown', 'bogus'].map((s) => state.status({ status: s }).label);
    assert.deepEqual(labels, ['Disponible', 'Usado', 'Caducado', 'Desactivado', 'Borrado en la tienda', 'Sin comprobar', 'Sin comprobar']);
});

test('searching asks for page 1 of that code, and «Ver más» appends the next page', async () => {
    const { state, calls, mount, runTimers } = load([
        { data: [coupon()], has_more: true, status_checked: false },
        { data: [coupon({ id: 5, code: 'TPV-BBBB' })], has_more: true, status_checked: true },
        { data: [coupon({ id: 6, code: 'TPV-BBBC' })], has_more: false, status_checked: true },
    ]);
    mount();
    await flush();
    assert.equal(state.statusChecked.value, false, 'the shop did not answer: the notice shows');

    state.query.value = ' bbb ';
    state.onSearch();
    runTimers();
    await flush();
    await state.load(true);

    assert.deepEqual(calls, [{ q: '', page: 1 }, { q: 'bbb', page: 1 }, { q: 'bbb', page: 2 }]);
    assert.deepEqual(state.coupons.value.map((c) => c.code), ['TPV-BBBB', 'TPV-BBBC']);
    assert.equal(state.hasMore.value, false);
});

test('an error shows the server sentence, never the raw response', async () => {
    const { state, mount } = load([new Error('HTTP 403: {"error":"Acceso denegado"}')]);
    mount();
    await flush();
    assert.equal(state.error.value, 'Acceso denegado');
});

test('printing without a printer opens the PDF', async () => {
    const { state, opened } = load([]);
    await state.printSlip(coupon({ id: 7 }));
    assert.deepEqual(opened, [7]);
    assert.equal(state.rowMessage.value[7], 'Sin impresora: se abre el PDF.');
});

test('the «Cupones» button sits next to «Crear cupón» for admins and cashiers, and the modal is loaded', () => {
    assert.match(SALE, /@click="showCouponList = true"[\s\S]{0,400}Cupones/);
    assert.match(SALE, /<coupon-list-modal v-if="showCouponList"/);
    assert.ok(SALE.indexOf('Crear cupón') < SALE.indexOf('> Cupones'), 'after «Crear cupón»');
    assert.match(APP, /app\.component\('coupon-list-modal', CouponListModal\)/);
    assert.ok(TEMPLATE.indexOf('CouponListModal.js') < TEMPLATE.indexOf('/assets/js/app.js'), 'loaded before app.js');
});
