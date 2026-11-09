/**
 * docs/prd/articulo-libre.md phase 1 on the screen: A1 (dialog rules and the
 * «Libre» line), A2.1 (what the line sends), A9 (parked tickets, reprice, coupon).
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../../public/assets/js');
function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function environment({ parkedTicketCreate = (payload) => Promise.resolve({ data: { id: 101, ...payload } }), catalog = {} } = {}) {
    const storage = new Map([['venpostpv_parked', '[]']]);
    const context = {
        window: { __SESSION__: { user_id: 2, terminal_id: 1, cashier_name: 'María' }, TPV_BASE: '', addEventListener() {} },
        localStorage: {
            getItem: (key) => (storage.has(key) ? storage.get(key) : null),
            setItem: (key, value) => storage.set(key, String(value)),
            removeItem: (key) => storage.delete(key),
        },
        Vue: { ref, computed, reactive: (v) => v, watch() {}, onMounted() {} },
        ApiClient: {
            parkedTicketsList: () => Promise.resolve({ data: [] }),
            parkedTicketCreate,
            parkedTicketDelete: () => Promise.resolve({ data: { deleted: true } }),
            get: (requestPath) => {
                const match = String(requestPath).match(/\/api\/catalog\/product\/(\d+)$/);
                return Promise.resolve({ data: catalog[match ? Number(match[1]) : 0] || null });
            },
        },
        Date, Math, JSON, Promise, Number, String, Array, Object, parseFloat, parseInt, console,
    };
    context.globalThis = context;
    const source = ['stores/useCart.js', 'stores/useParkedTickets.js']
        .map((file) => fs.readFileSync(path.join(ROOT, file), 'utf8')).join('\n');
    vm.runInNewContext(`${source}\nglobalThis.__cart = useCart;\nglobalThis.__parked = useParkedTickets;`, context, { filename: 'free-item-harness.js' });
    return { context, useCart: context.__cart, useParkedTickets: context.__parked, storage };
}

const SETTINGS = { enabled: true, role: 'all', max_price: 1000, vat_rates: [21, 10], allowed: true };

function modal(context, settings = SETTINGS) {
    vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'components/FreeItemModal.js'), 'utf8') + '\nglobalThis.__c = FreeItemModal;', context, { filename: 'FreeItemModal.js' });
    const emitted = [];
    const state = context.__c.setup({ settings }, { emit: (...args) => emitted.push(args) });
    return { state, emitted };
}

function testAddFreeCreatesASeparateLineEveryTime() {
    const { useCart } = environment();
    useCart.addFree({ concept: 'Arreglo bajo', price: 10, vatRate: 10, qty: 1 });
    useCart.addFree({ concept: 'Arreglo bajo', price: 10, vatRate: 10, qty: 2 });

    assert.equal(useCart.cart.lines.length, 2, 'two free items with the same concept are never merged');
    const line = useCart.cart.lines[0];
    assert.equal(line.free_item, true);
    assert.equal(line.concept, 'Arreglo bajo');
    assert.equal(line.name, 'Arreglo bajo');
    assert.equal(line.product_id, 0);
    assert.equal(line.id_product_attribute, 0);
    assert.equal(line.price, 10);
    assert.equal(line.vat_rate, 10);
    assert.equal(line.qty, 1);
    assert.equal(line.discount, 0);
    assert.equal(line.image_url, null);
    assert.ok(Math.abs(line.net_price - 10 / 1.1) < 1e-6);
    assert.notEqual(useCart.cart.lines[0].id, useCart.cart.lines[1].id);
    assert.equal(useCart.cart.lines[1].qty, 2);
    assert.equal(useCart.cart.total, 30);
}

function testFreeLineVatUsesItsOwnRate() {
    const { useCart } = environment();
    useCart.addFree({ concept: 'Arreglo', price: 10, vatRate: 10, qty: 1 });

    assert.equal(useCart.cart.iva, 0.91, 'a 10 % line of 10,00 carries 0,91 of VAT, never the 21 % fallback (1,74)');
}

async function testRepriceLeavesTheFreeLineAlone() {
    let asked = 0;
    const { useCart } = environment();
    useCart.addFree({ concept: 'Arreglo', price: 12.5, vatRate: 21, qty: 1 });
    const changed = await useCart.reprice(async () => { asked += 1; return { price: 99, net_price: 80 }; });

    assert.equal(changed, false);
    assert.equal(asked, 0);
    assert.equal(useCart.cart.lines[0].price, 12.5);
}

async function testParkAndRestoreKeepTheFreeLine() {
    const payloads = [];
    const { useCart, useParkedTickets } = environment({
        parkedTicketCreate: async (payload) => { payloads.push(payload); return { data: { id: 7, ...payload } }; },
    });
    useCart.add({ id: 5, name: 'Camisa', price: 20, net_price: 16.53 });
    useCart.addFree({ concept: 'Arreglo bajo', price: 10, vatRate: 10, qty: 3 });

    await useParkedTickets.parkCart(useCart.cart, 'Probador', 'María');
    const sent = payloads[0].lines.find((l) => l.free_item);
    assert.deepEqual(
        { free_item: sent.free_item, concept: sent.concept, price: sent.price, vat_rate: sent.vat_rate, qty: sent.qty },
        { free_item: true, concept: 'Arreglo bajo', price: 10, vat_rate: 10, qty: 3 }
    );
    const catalogLine = payloads[0].lines.find((l) => !l.free_item);
    assert.equal(catalogLine.free_item, undefined, 'a catalog line gets no free item fields');
    assert.equal(catalogLine.vat_rate, undefined);

    useCart.clear();
    await useParkedTickets.restoreWithSwap(useParkedTickets.state.tickets[0], useCart, 'María');
    const restored = useCart.cart.lines.find((l) => l.free_item);
    assert.equal(restored.concept, 'Arreglo bajo');
    assert.equal(restored.name, 'Arreglo bajo');
    assert.equal(restored.price, 10);
    assert.equal(restored.vat_rate, 10);
    assert.equal(restored.qty, 3);
    assert.equal(restored.product_id, 0);
    assert.ok(Math.abs(restored.net_price - 10 / 1.1) < 1e-6, 'the net price is recomputed from the line rate');
}

function testModalRejectsWhatTheServerRejects() {
    const { context, useCart } = environment();
    const { state, emitted } = modal(context);
    assert.equal(state.vatRate.value, 21, 'the first configured rate is the default');
    assert.equal(state.qty.value, '1');

    const cases = [
        [{ concept: '   ', price: '5' }, 'El concepto es obligatorio'],
        [{ concept: 'x'.repeat(101), price: '5' }, 'El concepto no puede tener más de 100 caracteres'],
        [{ concept: 'Arreglo <b>', price: '5' }, 'El concepto no puede llevar < > ; = # { }'],
        [{ concept: 'Arreglo 😀', price: '5' }, 'El concepto tiene caracteres que no se pueden imprimir'],
        [{ concept: 'Arreglo', price: 'abc' }, 'El precio debe estar entre 0,01 € y 1000,00 €'],
        [{ concept: 'Arreglo', price: '0' }, 'El precio debe estar entre 0,01 € y 1000,00 €'],
        [{ concept: 'Arreglo', price: '1000,01' }, 'El precio debe estar entre 0,01 € y 1000,00 €'],
        [{ concept: 'Arreglo', price: '1,234' }, 'El precio no puede tener más de 2 decimales'],
        [{ concept: 'Arreglo', price: '5', qty: '0' }, 'La cantidad debe ser un número entero entre 1 y 999'],
        [{ concept: 'Arreglo', price: '5', qty: '1000' }, 'La cantidad debe ser un número entero entre 1 y 999'],
        [{ concept: 'Arreglo', price: '5', qty: '1,5' }, 'La cantidad debe ser un número entero entre 1 y 999'],
    ];
    for (const [input, message] of cases) {
        state.concept.value = input.concept;
        state.price.value = input.price;
        state.qty.value = input.qty || '1';
        state.add();
        assert.equal(state.error.value, message, JSON.stringify(input));
    }
    assert.equal(useCart.cart.lines.length, 0);
    assert.equal(emitted.length, 0);
}

function testModalAddsTheNormalizedLineAndCloses() {
    const { context, useCart } = environment();
    const { state, emitted } = modal(context);
    state.concept.value = '  Arreglo   de  bajo «señora» €  ';
    state.price.value = '12,5';
    state.setRate(10);
    state.qty.value = '2';
    state.add();

    assert.equal(state.error.value, '');
    assert.equal(useCart.cart.lines.length, 1);
    const line = useCart.cart.lines[0];
    assert.equal(line.concept, 'Arreglo de bajo «señora» €');
    assert.equal(line.price, 12.5);
    assert.equal(line.vat_rate, 10);
    assert.equal(line.qty, 2);
    assert.deepEqual(emitted.at(-1), ['close']);
}

function testModalKeypadTypesIntoTheActiveField() {
    const { context } = environment();
    const { state } = modal(context);
    state.focus('price');
    ['1', '2', 'decimal', '5', '0', '0'].forEach((k) => state.onKey(k));
    assert.equal(state.price.value, '12,50', 'a third decimal is not typed');
    state.onKey('backspace');
    assert.equal(state.price.value, '12,5');

    state.focus('qty');
    state.onKey('backspace');
    ['9', '9', '9', '9'].forEach((k) => state.onKey(k));
    assert.equal(state.qty.value, '999', 'the quantity keeps three digits');
}

function testModalHonoursTheConfiguredMaximum() {
    const { context } = environment();
    const { state } = modal(context, { ...SETTINGS, max_price: 50, vat_rates: [10, 21] });
    assert.equal(state.vatRate.value, 10);
    state.concept.value = 'Arreglo';
    state.price.value = '50,01';
    state.add();
    assert.equal(state.error.value, 'El precio debe estar entre 0,01 € y 50,00 €');
}

async function testPaymentRefusesACouponWithAFreeItem() {
    const source = fs.readFileSync(path.join(ROOT, 'components/PaymentModal.js'), 'utf8');
    let previews = 0;
    const cart = {
        lines: [{ id: 'line_1', free_item: true, concept: 'Arreglo', name: 'Arreglo', product_id: 0, id_product_attribute: 0, qty: 1, price: 10, vat_rate: 10, discount: 0 }],
        subtotal: 10, discount: null, discount_amount: 0, total: 10, iva: 0.91, customer: { name: 'Consumidor final', default: true },
    };
    const context = {
        Vue: { ref, computed, watch() {} },
        useCart: { cart },
        useSession: { session: {}, hydrateCashSession: async () => {} },
        ApiClient: { couponPreview: async () => { previews += 1; return {}; }, checkout: async () => ({}) },
        setTimeout(fn) { fn(); return 1; }, clearTimeout() {},
        Number, String, Math, parseFloat, JSON, console, Promise, Error,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__payment = PaymentModal;`, context, { filename: 'PaymentModal.js' });
    const state = context.__payment.setup({ total: 10 }, { emit() {} });

    assert.equal(state.hasFreeItem.value, true);
    state.method.value = 'voucher';
    state.voucherCode.value = 'VERANO10';
    await state.applyVoucher();
    assert.equal(state.voucherError.value, 'No se puede usar un cupón con un artículo libre');
    assert.equal(previews, 0);
    assert.equal(state.canConfirm(), false);
}

async function main() {
    testAddFreeCreatesASeparateLineEveryTime();
    testFreeLineVatUsesItsOwnRate();
    await testRepriceLeavesTheFreeLineAlone();
    await testParkAndRestoreKeepTheFreeLine();
    testModalRejectsWhatTheServerRejects();
    testModalAddsTheNormalizedLineAndCloses();
    testModalKeypadTypesIntoTheActiveField();
    testModalHonoursTheConfiguredMaximum();
    await testPaymentRefusesACouponWithAFreeItem();
    console.log('freeItem functional harness: 9 passed');
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
