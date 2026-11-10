const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// A product PrestaShop sells without VAT (its price with and without VAT are the
// same) is marked «SIN IVA» in the catalog, so the cashier sees it before selling.
const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/CatalogPanel.js'), 'utf8');

function load() {
    const context = { CombinationsModal: {}, LabelPrintModal: {}, ProductEditModal: {}, localStorage: { getItem: () => null }, Number, Math, JSON };
    context.globalThis = context;
    vm.runInNewContext(`${SOURCE}\nglobalThis.__panel = CatalogPanel;`, context, { filename: 'CatalogPanel.js' });
    return context.__panel.setup({}, { emit() {} });
}

test('a product whose price with and without VAT are equal has no VAT', () => {
    const { noVat } = load();
    assert.equal(noVat({ price: 270, net_price: 270 }), true);
    assert.equal(noVat({ price: 0.18, net_price: 0.18 }), true);
});

test('a product with VAT, or whose net price is unknown, is not marked', () => {
    const { noVat } = load();
    assert.equal(noVat({ price: 12.1, net_price: 10 }), false);
    assert.equal(noVat({ price: 12.1, net_price: null }), false);
    assert.equal(noVat({ price: 12.1 }), false);
    assert.equal(noVat({ price: 0, net_price: 0 }), false, 'a free product is not a VAT problem');
});

test('the grid and the list show «SIN IVA» on those products', () => {
    assert.equal((SOURCE.match(/<span v-if="noVat\(p\)"[^>]*>SIN IVA<\/span>/g) || []).length, 2);
});
