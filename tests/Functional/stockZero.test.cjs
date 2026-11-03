const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadCart() {
    const source = fs.readFileSync(
        path.resolve(__dirname, '../../public/assets/js/stores/useCart.js'),
        'utf8'
    );

    const storage = new Map();
    const context = {
        window: { TPV_BASE: '', addEventListener() {} },
        localStorage: {
            getItem: (key) => (storage.has(key) ? storage.get(key) : null),
            setItem: (key, value) => storage.set(key, String(value)),
            removeItem: (key) => storage.delete(key),
        },
        Vue: { reactive: (value) => value },
        Date,
        Math,
        JSON,
        Number,
        parseFloat,
        parseInt,
        console,
    };
    context.globalThis = context;

    vm.runInNewContext(`${source}\nglobalThis.__cart = useCart;`, context, { filename: 'useCart.js' });

    return context.__cart;
}

// PRD V4: "Si el stock local de una línea es 0 o menos, se avisa al cajero y se permite vender."
function testProductWithoutLocalStockCanBeAdded() {
    const useCart = loadCart();
    assert.equal(useCart.add({ id: 7, name: 'Taza', price: 10, stock: 0 }), true);
    assert.equal(useCart.add({ id: 8, name: 'Gorra', price: 5, stock: -2 }), true);
    assert.equal(useCart.cart.lines.length, 2);
}

function testSaleScreenWarnsInsteadOfRefusing() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SaleScreen.js'), 'utf8');
    assert.ok(!source.includes("notify('Sin stock disponible'"), 'no longer refuses products without stock');
    assert.ok(source.includes('Sin stock en el TPV: se vende igualmente'), 'warns the cashier when adding');
}

testProductWithoutLocalStockCanBeAdded();
testSaleScreenWarnsInsteadOfRefusing();
console.log('stockZero.test.cjs OK');
