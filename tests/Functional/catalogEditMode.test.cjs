const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
const vm = require('node:vm');
const test = require('node:test');

const root = path.resolve(__dirname, '../..');
const panelSource = fs.readFileSync(path.join(root, 'public/assets/js/components/CatalogPanel.js'), 'utf8');
const modalSource = fs.readFileSync(path.join(root, 'public/assets/js/components/ProductEditModal.js'), 'utf8');
const combosSource = fs.readFileSync(path.join(root, 'public/assets/js/components/CombinationsModal.js'), 'utf8');
const saleSource = fs.readFileSync(path.join(root, 'public/assets/js/components/SaleScreen.js'), 'utf8');
const posTemplate = fs.readFileSync(path.join(root, 'templates/pos.html.twig'), 'utf8');

const SHIRT = { id: 1, name: 'Camiseta', ean13: '8400000000011', reference: 'CAM-1', has_combinations: false, stock: 4 };
const SWIMSUIT = { id: 3, name: 'Bañador', ean13: '', reference: 'BAN', has_combinations: true, stock: 6 };

function sandbox(api) {
    const context = new Proxy({
        console, String, Number, URLSearchParams, encodeURIComponent, Promise,
        localStorage: { getItem: () => null, setItem: () => {} },
        ApiClient: api,
    }, { has: () => true, get: (t, k) => (k in t ? t[k] : (k === Symbol.unscopables ? undefined : {})) });
    context.globalThis = context;
    return context;
}

/** The catalog panel with a fake API; isAdmin is the prop the sale screen passes. */
function mountPanel({ isAdmin = true, products = [SHIRT, SWIMSUIT] } = {}) {
    const requests = [];
    const api = {
        get(url) {
            requests.push(url);
            if (url.startsWith('/api/catalog/product/')) {
                const id = Number(url.split('/').pop());
                return Promise.resolve({ data: { ...products.find((p) => p.id === id), combinations: [{ id: 30, ean13: '8400000000035', stock: 0, attributes: [] }] } });
            }
            const q = new URLSearchParams(url.split('?')[1]).get('q');
            return Promise.resolve({ data: products.filter((p) => [p.ean13, p.reference].includes(q)) });
        },
    };
    const context = sandbox(api);
    vm.runInNewContext(`${panelSource}\nglobalThis.__panel = CatalogPanel;`, context, { filename: 'CatalogPanel.js' });
    const panel = context.__panel;
    const emitted = [];
    const view = {
        ...panel.data(),
        isAdmin,
        catalog: { search: '', products: products.map((p) => ({ ...p })) },
        $emit: (...args) => emitted.push(args),
        $refs: { searchInput: { focus: () => {} } },
    };
    for (const [name, fn] of Object.entries(panel.methods)) view[name] = fn.bind(view);
    return { panel, vm: view, emitted, requests };
}

test('the edit mode button is only for administrators and the mode starts off', () => {
    const button = panelSource.match(/<button[^>]*@click="toggleEditMode"[\s\S]*?<\/button>/);
    assert.ok(button, 'there is a «Modo edición» button');
    assert.match(button[0], /v-if="isAdmin"/);
    assert.match(button[0], /:aria-pressed/);
    assert.match(button[0], /Modo edición/);

    const cashier = mountPanel({ isAdmin: false });
    assert.equal(cashier.vm.editMode, false);
    cashier.vm.toggleEditMode();
    assert.equal(cashier.vm.editMode, false, 'a cashier cannot turn it on');

    const admin = mountPanel();
    admin.vm.toggleEditMode();
    assert.equal(admin.vm.editMode, true);
    admin.vm.toggleEditMode();
    assert.equal(admin.vm.editMode, false, 'leaving it returns to selling');
});

test('in edit mode tapping a product opens the editor and adds nothing to the ticket', async () => {
    const view = mountPanel();
    view.vm.toggleEditMode();

    await view.vm.handleProductTap(SHIRT);

    assert.deepEqual({ ...view.vm.editItem }, { productId: 1, attributeId: 0, title: 'Camiseta' });
    assert.ok(!view.emitted.some(([name]) => name === 'addProduct'));
});

test('in edit mode a size or colour chosen in the variants window opens its editor', async () => {
    const view = mountPanel();
    view.vm.toggleEditMode();

    await view.vm.handleProductTap(SWIMSUIT);
    assert.equal(view.vm.showCombinationsModal, true, 'the same variants picker opens');
    view.vm.onCombinationSelect({ id: 30, attributes: [{ group_name: 'Talla', attribute_name: 'M' }] });

    assert.deepEqual({ ...view.vm.editItem }, { productId: 3, attributeId: 30, title: 'Bañador · Talla: M' });
    assert.equal(view.vm.showCombinationsModal, false);
    assert.ok(!view.emitted.some(([name]) => name === 'addProduct'));

    await view.vm.handleProductTap(SWIMSUIT);
    view.vm.onEditProduct();
    assert.deepEqual({ ...view.vm.editItem }, { productId: 3, attributeId: 0, title: 'Bañador' }, 'the product itself (EAN, reference)');
});

test('out of edit mode a tap sells as before', async () => {
    const view = mountPanel();
    view.vm.toggleEditMode();
    view.vm.toggleEditMode();

    await view.vm.handleProductTap(SHIRT);

    assert.equal(view.vm.editItem, null);
    assert.deepEqual(view.emitted, [['addProduct', SHIRT]]);
});

test('a scan in edit mode opens the editor of the scanned product', async () => {
    const view = mountPanel();
    view.vm.scanToCart = true;
    view.vm.toggleEditMode();
    view.vm.catalog.search = '8400000000011';

    await view.vm.onSearchEnter();

    assert.equal(view.vm.editItem.productId, 1);
    assert.ok(!view.emitted.some(([name]) => name === 'addProduct'));
});

test('a saved edit refreshes the product shown in the grid', async () => {
    const view = mountPanel({ products: [{ ...SHIRT }] });
    view.vm.toggleEditMode();
    await view.vm.handleProductTap(SHIRT);
    view.requests.length = 0;

    await view.vm.onEditSaved({ id_product: 1, id_product_attribute: 0, effective: { ean13: '4006381333931', reference: 'L-1' }, stock: { quantity: 9 } });

    assert.deepEqual(view.requests, ['/api/catalog/product/1']);
});

test('losing the administrator session leaves edit mode', () => {
    const view = mountPanel();
    view.vm.toggleEditMode();
    view.vm.editItem = { productId: 1, attributeId: 0, title: 'x' };

    view.panel.watch.isAdmin.call(view.vm, false);

    assert.equal(view.vm.editMode, false);
    assert.equal(view.vm.editItem, null);
});

test('edit mode is visible on the panel and the variants window lets out-of-stock sizes be edited', () => {
    assert.match(panelSource, /:class="\{ 'catalog-edit-mode': editMode \}"/);
    assert.match(panelSource, /class="catalog-edit-banner"/);
    assert.match(panelSource, /<product-edit-modal[\s\S]*?v-if="editItem"/);
    assert.match(panelSource, /:edit-mode="editMode"/);
    assert.match(combosSource, /:disabled="!editMode && combo\.stock <= 0"/);
    assert.match(combosSource, /v-if="editMode"[^>]*@click="\$emit\('editProduct'\)"/);
});

test('the sale screen tells the panel whether the user is an administrator', () => {
    const panelTag = saleSource.match(/<catalog-panel[\s\S]*?\/>/);
    assert.ok(panelTag);
    assert.match(panelTag[0], /:is-admin="session\.role === 'admin'"/);
    const modal = posTemplate.indexOf('/assets/js/components/ProductEditModal.js?v=');
    assert.ok(modal > 0 && hasCacheVersionSince(posTemplate, 'components/ProductEditModal.js', '20261109'), 'the editor is loaded');
    assert.ok(modal < posTemplate.indexOf('/assets/js/components/CatalogPanel.js'), 'before the panel that uses it');
});

function loadModal(api) {
    const context = sandbox(api);
    vm.runInNewContext(`${modalSource}\nglobalThis.__modal = ProductEditModal;`, context, { filename: 'ProductEditModal.js' });
    return context.__modal;
}

test('the editor checks the EAN like the server', () => {
    const { validate } = loadModal({});
    assert.deepEqual({ ...validate({ ean13: '4006381333931', reference: ' R ', quantity: '3', stockEditable: true }) }, { ean13: '4006381333931', reference: 'R', quantity: 3, price: null, vatRate: null });
    assert.deepEqual({ ...validate({ ean13: '96385074', reference: '', quantity: '', stockEditable: false }) }, { ean13: '96385074', reference: null, quantity: null, price: null, vatRate: null });
    assert.equal(validate({ ean13: '', reference: '', quantity: '0', stockEditable: true }).ean13, null, 'empty clears the local EAN');
    assert.equal(validate({ ean13: '4006381333932' }).error, 'El EAN «4006381333932» no es válido: el dígito de control no cuadra');
    assert.equal(validate({ ean13: '400638133393' }).error, 'El EAN debe tener 8 o 13 cifras');
    assert.equal(validate({ ean13: '', quantity: '-1', stockEditable: true }).error, 'El stock debe ser un número entero igual o mayor que 0');
    assert.equal(validate({ ean13: '', quantity: '2,5', stockEditable: true }).error, 'El stock debe ser un número entero igual o mayor que 0');
});

function mountModal(item, api) {
    const modal = loadModal(api);
    const emitted = [];
    const view = { ...modal.data(), productId: item.id_product, attributeId: item.id_product_attribute, title: '', $emit: (...args) => emitted.push(args) };
    for (const [name, fn] of Object.entries(modal.methods)) view[name] = fn.bind(view);
    Object.defineProperty(view, 'base', { get: () => modal.computed.base.call(view) });
    Object.defineProperty(view, 'hasLocal', { get: () => modal.computed.hasLocal.call(view) });
    return { view, emitted };
}

const ITEM = {
    id_product: 1, id_product_attribute: 0, name: 'Camiseta', combination: null,
    synced: { ean13: '8400000000011', reference: 'CAM-1' }, local: null,
    effective: { ean13: '8400000000011', reference: 'CAM-1' },
    stock: { quantity: 4, editable: true, reason: null }, overrides_available: true,
};

test('the editor saves only what changed: the EAN (in the web), then the stock', async () => {
    const calls = [];
    const api = {
        get: (url) => { calls.push(['get', url]); return Promise.resolve({ data: ITEM }); },
        catalogSaveOverride: (id, attr, values) => { calls.push(['override', id, attr, { ...values }]); return Promise.resolve({ data: { ...ITEM, local: { ean13: values.ean13, reference: null } } }); },
        catalogSetStock: (id, attr, qty) => { calls.push(['stock', id, attr, qty]); return Promise.resolve({ data: { ...ITEM, stock: { ...ITEM.stock, quantity: qty } } }); },
    };
    const { view, emitted } = mountModal(ITEM, api);
    await view.load();
    assert.deepEqual(calls, [['get', '/api/catalog/edit/1/0']]);
    assert.equal(view.quantity, '4');

    view.ean13 = '4006381333931';
    view.quantity = '9';
    await view.save();

    assert.deepEqual(calls.slice(1), [['override', 1, 0, { ean13: '4006381333931', reference: 'CAM-1' }], ['stock', 1, 0, 9]]);
    assert.deepEqual(emitted.map(([name]) => name), ['saved', 'notify', 'close']);
});

test('a refused stock change shows the server reason and keeps the editor open', async () => {
    const api = {
        get: () => Promise.resolve({ data: ITEM }),
        catalogSetStock: () => Promise.reject(Object.assign(new Error('HTTP 502'), { status: 502, body: { error: 'PrestaShop no ha aceptado el cambio de stock; no se ha cambiado nada' } })),
    };
    const { view, emitted } = mountModal(ITEM, api);
    await view.load();
    view.quantity = '9';

    await view.save();

    assert.equal(view.error, 'PrestaShop no ha aceptado el cambio de stock; no se ha cambiado nada');
    assert.deepEqual(emitted, []);
});

test('«Restablecer» removes the local values', async () => {
    const local = { ...ITEM, local: { ean13: '4006381333931', reference: null }, effective: { ean13: '4006381333931', reference: 'CAM-1' } };
    const calls = [];
    const api = {
        get: () => Promise.resolve({ data: local }),
        catalogResetOverride: (id, attr) => { calls.push([id, attr]); return Promise.resolve({ data: ITEM }); },
    };
    const { view, emitted } = mountModal(local, api);
    await view.load();
    assert.equal(view.ean13, '4006381333931');

    await view.reset();

    assert.deepEqual(calls, [[1, 0]]);
    assert.equal(view.ean13, '8400000000011', 'the web EAN again');
    assert.equal(emitted[0][0], 'saved');
});

const PRICED = { ...ITEM, id_product: 6479, id_product_attribute: 17775, price: { price: 270, vat_rate: 0 } };

test('the editor shows the price with VAT and the rate, and saves a new price and rate', async () => {
    const calls = [];
    const api = {
        get: () => Promise.resolve({ data: PRICED }),
        catalogSetPrice: (id, attr, price, rate) => { calls.push([id, attr, price, rate]); return Promise.resolve({ data: { ...PRICED, price: { price, vat_rate: rate } } }); },
    };
    const { view, emitted } = mountModal(PRICED, api);
    await view.load();
    assert.equal(view.price, '270,00');
    assert.equal(view.vatRate, 0);

    view.price = '270,00';
    view.vatRate = 21;
    await view.save();

    assert.deepEqual(calls, [[6479, 17775, 270, 21]]);
    assert.deepEqual(emitted.map(([name]) => name), ['saved', 'notify', 'close']);
});

test('an unchanged price is not sent, and a wrong price is refused before asking', async () => {
    const calls = [];
    const api = {
        get: () => Promise.resolve({ data: PRICED }),
        catalogSetPrice: (...args) => { calls.push(args); return Promise.resolve({ data: PRICED }); },
    };
    const { view } = mountModal(PRICED, api);
    await view.load();
    await view.save();
    assert.deepEqual(calls, [], 'nothing changed');

    view.price = '12,345';
    await view.save();
    assert.match(view.error, /hasta 2 decimales/);
    assert.deepEqual(calls, []);
});

test('the editor offers 21, 10, 4 and 0 % and no longer says the price is only the web one', () => {
    assert.match(modalSource, /Precio con IVA \(tienda y web\)/);
    assert.match(modalSource, /const VAT_RATES = \[21, 10, 4, 0\];/);
    assert.doesNotMatch(modalSource, /El precio siempre es el de la web/);
});

test('the EAN and reference start with what the till uses, and an unchanged wrong EAN of the web does not block a save', async () => {
    const calls = [];
    const api = {
        get: () => Promise.resolve({ data: ITEM }),
        catalogSaveOverride: (...args) => { calls.push(['override', ...args]); return Promise.resolve({ data: ITEM }); },
        catalogSetStock: (id, attr, qty) => { calls.push(['stock', qty]); return Promise.resolve({ data: { ...ITEM, stock: { ...ITEM.stock, quantity: qty } } }); },
    };
    const { view } = mountModal(ITEM, api);
    await view.load();
    assert.equal(view.ean13, '8400000000011', 'the web EAN, whose check digit is wrong');
    assert.equal(view.reference, 'CAM-1');

    view.quantity = '9';
    await view.save();

    assert.equal(view.error, '');
    assert.deepEqual(calls, [['stock', 9]], 'nothing else sent');
});

test('the labels say every field is saved in the web too', () => {
    assert.match(modalSource, /EAN \(tienda y web\)/);
    assert.match(modalSource, /Referencia \(tienda y web\)/);
    assert.doesNotMatch(modalSource, /solo en el TPV\)/);
});
