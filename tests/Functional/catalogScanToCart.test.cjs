const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/CatalogPanel.js'), 'utf8');

const SHIRT = { id: 1, name: 'Camiseta', ean13: '8400000000011', reference: 'CAM-1', has_combinations: false };
const CAP = { id: 2, name: 'Gorro', ean13: '8400000000028', reference: 'GOR-1', has_combinations: false };
const SWIMSUIT = { id: 3, name: 'Bañador', ean13: '', reference: 'BAN', has_combinations: true };

/**
 * The panel with a fake API: /api/catalog/search answers the products whose name,
 * reference or EAN contains q (a size's EAN finds its product), and
 * /api/catalog/product/{id} answers the product with its combinations.
 */
function mount({ stored = {}, catalog = [SHIRT, CAP, SWIMSUIT], comboCodes = { 3: ['8400000000035'] } } = {}) {
    const requests = [];
    const context = new Proxy({
        console,
        String,
        URLSearchParams,
        encodeURIComponent,
        localStorage: {
            getItem: (k) => (k in stored ? stored[k] : null),
            setItem: (k, v) => { stored[k] = String(v); },
        },
        ApiClient: {
            get(url) {
                requests.push(url);
                if (url.startsWith('/api/catalog/product/')) {
                    const id = Number(url.split('/').pop());
                    return Promise.resolve({ data: { ...catalog.find((p) => p.id === id), combinations: [{ id: 30, ean13: '8400000000035' }] } });
                }
                const q = new URLSearchParams(url.split('?')[1]).get('q');
                const found = catalog.filter((p) => [p.name, p.reference, p.ean13, ...(comboCodes[p.id] || [])].some((v) => v && v.includes(q)));
                return Promise.resolve({ data: found });
            },
        },
    }, { has: () => true, get: (t, k) => (k in t ? t[k] : (k === Symbol.unscopables ? undefined : {})) });
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__panel = CatalogPanel;`, context, { filename: 'CatalogPanel.js' });
    const panel = context.__panel;

    const emitted = [];
    let focused = 0;
    const vmThis = {
        ...panel.data(),
        catalog: { search: '' },
        $emit: (...args) => emitted.push(args),
        $refs: { searchInput: { focus: () => { focused++; } } },
    };
    for (const [name, fn] of Object.entries(panel.methods)) vmThis[name] = fn.bind(vmThis);

    return { vm: vmThis, emitted, requests, stored, focused: () => focused };
}

async function scan(view, code) {
    view.vm.catalog.search = code;
    await view.vm.onSearchEnter();
}

test('the scan mode is off by default and the button remembers it on this device', () => {
    const view = mount();
    assert.equal(view.vm.scanToCart, false);

    view.vm.toggleScanToCart();
    assert.equal(view.vm.scanToCart, true);
    assert.equal(view.stored['tpv.catalog.scanToCart'], '1');
    assert.equal(mount({ stored: view.stored }).vm.scanToCart, true, 'still on after reloading');
});

test('with the mode on, a scanned product goes straight to the ticket and the search empties', async () => {
    const view = mount({ stored: { 'tpv.catalog.scanToCart': '1' } });

    await scan(view, '8400000000011');

    assert.deepEqual(view.emitted, [['addProduct', SHIRT], ['setSearch', '']]);
    assert.ok(view.focused() > 0, 'ready for the next scan');
});

test('a product with variants opens the variants window instead', async () => {
    const view = mount({ stored: { 'tpv.catalog.scanToCart': '1' } });

    await scan(view, '8400000000035');

    assert.equal(view.vm.showCombinationsModal, true);
    assert.equal(view.vm.combinationsProduct.id, 3);
    assert.ok(!view.emitted.some(([name]) => name === 'addProduct'), 'nothing added until a variant is chosen');
    assert.deepEqual(view.emitted, [['setSearch', '']]);
});

test('an unknown code adds nothing, keeps the text and says so', async () => {
    const view = mount({ stored: { 'tpv.catalog.scanToCart': '1' } });

    await scan(view, '9999999999999');

    assert.deepEqual(view.emitted, [['notify', 'No hay ningún producto con el código 9999999999999', 'var(--red)']]);
});

test('when several products match, only the exact code is added', async () => {
    const view = mount({ stored: { 'tpv.catalog.scanToCart': '1' } });

    await scan(view, '84000000000');
    assert.deepEqual(view.emitted[0], ['notify', 'No hay ningún producto con el código 84000000000', 'var(--red)'], 'a partial code is no scan');

    const exact = mount({ stored: { 'tpv.catalog.scanToCart': '1' } });
    await scan(exact, 'GOR-1');
    assert.deepEqual(exact.emitted, [['addProduct', CAP], ['setSearch', '']]);
});

test('with the mode off, Enter only searches as before', async () => {
    const view = mount();

    await scan(view, '8400000000011');

    assert.deepEqual(view.emitted, []);
    assert.deepEqual(view.requests, []);
});

test('the barcode button switches the mode and Enter in the search box scans', () => {
    assert.match(source, /@keydown\.enter\.prevent="onSearchEnter"/);
    const button = source.match(/<button @click="toggleScanToCart"[\s\S]*?<\/button>/);
    assert.ok(button, 'the barcode button toggles the mode');
    assert.ok(button[0].includes('name="barcode"'));
    assert.ok(button[0].includes(':aria-pressed'));
});
