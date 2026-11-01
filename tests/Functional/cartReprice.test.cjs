/**
 * Functional harness for cart re-pricing.
 *
 * Parked tickets and the localStorage cart store a price snapshot taken when the
 * line was added. Once the catalog carries authoritative tax-included prices, a
 * frozen snapshot is a stale basis and the checkout rejects the sale, so the cart
 * must re-price its lines from the catalog before payment.
 */
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

async function testStaleSnapshotPriceIsReplacedByTheCatalogPrice() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Camiseta', price: 23.90, stock: 5 });

    const changed = await useCart.reprice(async () => ({ id: 1, price: 23.14 }));

    assert.equal(changed, true);
    assert.equal(useCart.cart.lines[0].price, 23.14);
    assert.equal(useCart.cart.total, 23.14);
}

async function testCombinationLineUsesTheCatalogCombinationImpact() {
    const useCart = loadCart();
    useCart.add({
        id: 1,
        name: 'Camiseta',
        price: 23.14,
        stock: 5,
        selected_combination_id: 7,
    });

    await useCart.reprice(async () => ({
        id: 1,
        price: 23.14,
        combinations: [{ id: 7, price_impact: 2.82 }],
    }));

    // Same arithmetic CatalogPanel.onCombinationSelect sends to the checkout.
    assert.equal(useCart.cart.lines[0].price, 25.96);
    assert.equal(useCart.cart.total, 25.96);
}

async function testUnknownProductKeepsTheLineSoTheCheckoutCanRejectIt() {
    const useCart = loadCart();
    useCart.add({ id: 99, name: 'Desconocido', price: 10, stock: 5 });

    const changed = await useCart.reprice(async () => null);

    assert.equal(changed, false);
    assert.equal(useCart.cart.lines[0].price, 10);
}

async function testUnchangedPricesReportNoChange() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Camiseta', price: 23.14, stock: 5 });

    const changed = await useCart.reprice(async () => ({ id: 1, price: 23.14 }));

    assert.equal(changed, false);
    assert.equal(useCart.cart.total, 23.14);
}

async function testResolvingFailureLeavesTheCartUntouched() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Camiseta', price: 23.90, stock: 5 });

    const changed = await useCart.reprice(async () => {
        throw new Error('network down');
    });

    assert.equal(changed, false);
    assert.equal(useCart.cart.lines[0].price, 23.90);
}

async function testVatComesFromTheShopPricesNotFromTheFixedRate() {
    const useCart = loadCart();
    // 10% VAT: the fixed 21% fallback would report 1.74 instead of 0.91.
    useCart.add({ id: 1, name: 'Libro', price: 10, net_price: 9.09, stock: 5 });

    assert.equal(useCart.cart.total, 10);
    assert.equal(useCart.cart.iva, 0.91);
}

async function testVatFallsBackToTheStandardRateWithoutANetPrice() {
    const useCart = loadCart();
    useCart.add({ id: 2, name: 'Sin neto', price: 10, stock: 5 });

    assert.equal(useCart.cart.iva, 1.74);
}

async function testRepriceAlsoRefreshesTheNetPrice() {
    const useCart = loadCart();
    useCart.add({ id: 1, name: 'Libro', price: 10, net_price: 9.09, stock: 5 });

    await useCart.reprice(async () => ({ id: 1, price: 12, net_price: 10.91, combinations: [] }));

    assert.equal(useCart.cart.lines[0].price, 12);
    assert.equal(useCart.cart.lines[0].net_price, 10.91);
    assert.equal(useCart.cart.iva, 1.09);
}

async function main() {
    await testStaleSnapshotPriceIsReplacedByTheCatalogPrice();
    await testCombinationLineUsesTheCatalogCombinationImpact();
    await testUnknownProductKeepsTheLineSoTheCheckoutCanRejectIt();
    await testUnchangedPricesReportNoChange();
    await testResolvingFailureLeavesTheCartUntouched();
    await testVatComesFromTheShopPricesNotFromTheFixedRate();
    await testVatFallsBackToTheStandardRateWithoutANetPrice();
    await testRepriceAlsoRefreshesTheNetPrice();
    console.log('cartReprice functional harness: 8 passed');
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
