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

function testPercentageDiscountIsClampedTo100() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Abrigo', price: 100, stock: 5 });

    useCart.applyDiscount('pct', 150);

    assert.equal(useCart.cart.discount.type, 'pct');
    assert.equal(useCart.cart.discount.value, 100);
    assert.equal(useCart.cart.discount_amount, 100);
    assert.equal(useCart.cart.total, 0);
}

function testNegativeDiscountIsIgnored() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Abrigo', price: 100, stock: 5 });

    useCart.applyDiscount('pct', -20);

    assert.equal(useCart.cart.discount, null);
    assert.equal(useCart.cart.discount_amount, 0);
    assert.equal(useCart.cart.total, 100);
}

function testAmountDiscountNeverExceedsSubtotal() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Camisa', price: 10, stock: 3 });

    useCart.applyDiscount('amt', 50);

    assert.equal(useCart.cart.discount.type, 'amt');
    assert.equal(useCart.cart.discount.value, 10);
    assert.equal(useCart.cart.discount_amount, 10);
    assert.equal(useCart.cart.total, 0);
}

function testZeroDiscountClearsPreviousDiscount() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Camisa', price: 40, stock: 3 });
    useCart.applyDiscount('pct', 10);

    useCart.applyDiscount('pct', 0);

    assert.equal(useCart.cart.discount, null);
    assert.equal(useCart.cart.total, 40);
}

function testTicketLineKeepsTheProductImage() {
    const useCart = loadCart();
    useCart.add({ id: 7, name: 'Red', price: 32, stock: 5, image_url: 'https://shop.example.com/img/p/1/2/12-home_default.jpg' });
    useCart.add({ id: 8, name: 'Sin foto', price: 5, stock: 5 });

    assert.equal(useCart.cart.lines[0].image_url, 'https://shop.example.com/img/p/1/2/12-home_default.jpg');
    assert.equal(useCart.cart.lines[1].image_url, null);
}

function main() {
    testPercentageDiscountIsClampedTo100();
    testNegativeDiscountIsIgnored();
    testAmountDiscountNeverExceedsSubtotal();
    testZeroDiscountClearsPreviousDiscount();
    testTicketLineKeepsTheProductImage();
    console.log('cartDiscounts functional harness: 5 passed');
}

try {
    main();
} catch (error) {
    console.error(error);
    process.exitCode = 1;
}
