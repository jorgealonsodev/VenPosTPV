// docs/prd/agente-impresion.md L1, L2, L8, L9, AC15: label rules of the POS, run
// without a browser (LabelPrintModal.js and LabelsScreen.js in a VM).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
const vm = require('node:vm');

const plain = (v) => JSON.parse(JSON.stringify(v));
const ROOT = path.resolve(__dirname, '../../public/assets/js/components');

function load(storage = new Map(), { throwingStorage = false } = {}) {
    const localStorage = throwingStorage
        ? { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }, removeItem() { throw new Error('denied'); } }
        : {
            getItem: (k) => (storage.has(k) ? storage.get(k) : null),
            setItem: (k, v) => storage.set(k, String(v)),
            removeItem: (k) => storage.delete(k),
        };
    const context = {
        Vue: { reactive: (v) => v, ref: (v) => ({ value: v }), computed: (f) => ({ get value() { return f(); } }) },
        localStorage, JSON, Math, Number, Array, String, console,
    };
    context.globalThis = context;
    const source = fs.readFileSync(path.join(ROOT, 'LabelPrintModal.js'), 'utf8')
        + '\n' + fs.readFileSync(path.join(ROOT, 'LabelsScreen.js'), 'utf8')
        + '\nglobalThis.__labels = { LabelPrintLogic, LabelList, LabelPendingLogic, LabelsScreen, LabelPrintModal, LabelPendingPrompt };';
    vm.runInNewContext(source, context, { filename: 'labels.js' });
    return context.__labels;
}

const product = {
    id: 7, name: 'Camiseta', stock: 0,
    combinations: [
        { id: 71, stock: 4, attributes: [{ group_name: 'Color', attribute_name: 'Azul' }, { group_name: 'Talla', attribute_name: 'M' }] },
        { id: 72, stock: -2, attributes: [{ group_name: 'Color', attribute_name: 'Azul' }, { group_name: 'Talla', attribute_name: 'L' }] },
        { id: 73, stock: 250, attributes: [{ group_name: 'Color', attribute_name: 'Rojo' }, { group_name: 'Talla', attribute_name: 'S' }] },
    ],
};

// L1: a row per size/colour, "M · Azul" with the size first, quantity 0 to start.
function testModalRowsPerCombination() {
    const { LabelPrintLogic } = load();
    const rows = LabelPrintLogic.rows(product);
    assert.deepEqual(plain(rows.map(r => [r.label, r.stock, r.quantity])), [['M · Azul', 4, 0], ['L · Azul', -2, 0], ['S · Rojo', 250, 0]]);
    const single = LabelPrintLogic.rows({ id: 8, name: 'Gorra', stock: 3, combinations: [] });
    assert.deepEqual(plain(single.map(r => [r.product_id, r.id_product_attribute, r.label, r.stock])), [[8, 0, 'Gorra', 3]]);
}

// L1 "Cantidad = stock": each row its stock, never below 0 and at most 100 (L8).
function testQuantityFromStock() {
    const { LabelPrintLogic } = load();
    const rows = LabelPrintLogic.quantityFromStock(LabelPrintLogic.rows(product));
    assert.deepEqual(plain(rows.map(r => r.quantity)), [4, 0, 100]);
    assert.equal(LabelPrintLogic.total(rows), 104);
    assert.equal(LabelPrintLogic.printLabel(104), 'Imprimir 104 etiquetas');
    assert.equal(LabelPrintLogic.printLabel(1), 'Imprimir 1 etiqueta');
    assert.deepEqual(JSON.parse(JSON.stringify(LabelPrintLogic.items(rows))), [
        { product_id: 7, id_product_attribute: 71, quantity: 4 },
        { product_id: 7, id_product_attribute: 73, quantity: 100 },
    ]);
}

function testQuantitiesAreClamped() {
    const { LabelPrintLogic } = load();
    assert.deepEqual(plain(['5', -3, 'abc', 101, 2.7].map(LabelPrintLogic.clampQuantity)), [5, 0, 0, 100, 2]);
}

// AC15: scan 3 products, change quantities, print: the list empties.
function testListPersistsUntilPrintedAndEmptiesAfter() {
    const storage = new Map();
    let { LabelList } = load(storage);
    LabelList.add({ product_id: 7, id_product_attribute: 71, name: 'Camiseta', combination: 'M · Azul' });
    LabelList.add({ product_id: 8, id_product_attribute: 0, name: 'Gorra' });
    LabelList.add({ product_id: 9, id_product_attribute: 0, name: 'Toalla' });
    LabelList.add({ product_id: 7, id_product_attribute: 71, name: 'Camiseta' }); // scanned again: one more
    LabelList.setQuantity('8:0', 5);
    assert.deepEqual(plain(LabelList.state.lines.map(l => [l.key, l.quantity])), [['7:71', 2], ['8:0', 5], ['9:0', 1]]);

    // A reload of the page keeps the list.
    ({ LabelList } = load(storage));
    assert.equal(LabelList.state.lines.length, 3);
    assert.equal(LabelList.total(), 8);

    // A failed print keeps it; a sent (or held) one empties it.
    LabelList.afterPrint({ print_status: 'failed', error: 'La impresora no ha respondido' });
    assert.equal(LabelList.state.lines.length, 3);
    LabelList.afterPrint({ print_status: 'queued', labels: 8, jobs: [1] });
    assert.equal(LabelList.state.lines.length, 0);
    assert.equal(storage.has('tpv_label_list_v1'), false);
    assert.equal(load(storage).LabelList.state.lines.length, 0);
}

function testLinesKeepTheProductImage() {
    const storage = new Map();
    let { LabelList } = load(storage);
    LabelList.add({ product_id: 7, id_product_attribute: 71, name: 'Camiseta', image: 'https://shop.example.com/img/p/7/7-home_default.jpg' });
    LabelList.add({ product_id: 8, id_product_attribute: 0, name: 'Gorra' });
    ({ LabelList } = load(storage));
    assert.deepEqual(plain(LabelList.state.lines.map(l => l.image)), ['https://shop.example.com/img/p/7/7-home_default.jpg', '']);

    // Every way into the list passes the catalog image: search, variant and scan.
    const screen = fs.readFileSync(path.join(ROOT, 'LabelsScreen.js'), 'utf8');
    assert.ok(screen.includes('image: product.image_url'), 'a product picked from the search');
    assert.ok(screen.includes('image: product.image_url', screen.indexOf('function pickCombination')), 'a variant');
    assert.ok(screen.includes('image: found.image_url'), 'a scanned code');
    assert.ok(/<img v-if="line\.image"[^>]*:src="line\.image"/.test(screen), 'the row shows it');
    assert.ok(/<button v-for="p in results"[\s\S]*?<img v-if="p\.image_url"[^>]*:src="p\.image_url"[\s\S]*?<\/button>/.test(screen), 'each search result shows it too');
}

function testListWorksWithoutStorage() {
    const { LabelList } = load(new Map(), { throwingStorage: true });
    LabelList.add({ product_id: 7, id_product_attribute: 71, name: 'Camiseta' }, 3);
    assert.equal(LabelList.total(), 3);
    LabelList.clear();
    assert.equal(LabelList.total(), 0);
}

function testStatusMessages() {
    const { LabelPrintLogic } = load();
    assert.equal(LabelPrintLogic.statusMessage({ print_status: 'queued', labels: 10 }).text, '10 etiquetas enviadas a la impresora');
    assert.match(LabelPrintLogic.statusMessage({ print_status: 'held', labels: 4 }).text, /no está conectado: 4 etiquetas quedan pendientes/);
    assert.equal(LabelPrintLogic.statusMessage({ print_status: 'failed', error: 'La impresora no ha respondido: x' }).text, 'La impresora no ha respondido: x');
    assert.equal(LabelPrintLogic.errorMessage(new Error('HTTP 422: {"error":"Máximo 100 etiquetas por línea","code":"invalid_labels"}')), 'Máximo 100 etiquetas por línea');
}

// L9: ask only when the server says the agent is back with held labels.
function testPendingPrompt() {
    const { LabelPendingLogic, LabelPendingPrompt } = load();
    assert.equal(LabelPendingLogic.shouldAsk({ ask: true, labels: 37, message: 'Hay 37 etiquetas pendientes. ¿Imprimirlas?' }), true);
    assert.equal(LabelPendingLogic.shouldAsk({ ask: false, labels: 37 }), false);
    assert.equal(LabelPendingLogic.shouldAsk({ ask: true, labels: 0 }), false);
    assert.equal(LabelPendingLogic.shouldAsk(null), false);
    assert.ok(LabelPendingPrompt.template.includes('>Imprimir<') && LabelPendingPrompt.template.includes('>Descartar<'));
}

function testScreensAreWired() {
    const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');
    assert.ok(read('LabelPrintModal.js').includes('Cantidad = stock'));
    assert.ok(read('CombinationsModal.js').includes('Imprimir etiquetas'), 'the product sheet offers labels');
    assert.ok(read('CatalogPanel.js').includes('<label-print-modal'));
    const sale = read('SaleScreen.js');
    assert.ok(sale.includes('<labels-screen') && sale.includes('> Etiquetas'), 'header button "Etiquetas"');
    assert.ok(sale.includes('<label-pending-prompt'));
    const app = fs.readFileSync(path.resolve(ROOT, '../app.js'), 'utf8');
    ['label-print-modal', 'labels-screen', 'label-pending-prompt'].forEach(c => assert.ok(app.includes("'" + c + "'"), c));
    const twig = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');
    assert.ok(hasCacheVersionSince(twig, 'components/LabelPrintModal.js', '20261103') && hasCacheVersionSince(twig, 'components/LabelsScreen.js', '20261103'));
    const api = fs.readFileSync(path.resolve(ROOT, '../api.js'), 'utf8');
    ['/api/labels/preview', '/api/labels/print', '/api/labels/lookup', '/api/labels/pending'].forEach(p => assert.ok(api.includes(p), p));
}

testModalRowsPerCombination();
testQuantityFromStock();
testQuantitiesAreClamped();
testListPersistsUntilPrintedAndEmptiesAfter();
testListWorksWithoutStorage();
testLinesKeepTheProductImage();
testStatusMessages();
testPendingPrompt();
testScreensAreWired();
console.log('labels.test.cjs OK');
