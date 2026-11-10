const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadEnvironment({
    initialParkedStorage = '[]',
    parkedTicketsList = () => Promise.resolve({ data: [] }),
    parkedTicketCreate = (payload) => Promise.resolve({ data: { id: 101, ...payload } }),
    parkedTicketDelete = () => Promise.resolve({ data: { deleted: true } }),
    parkedTicketsClear = () => Promise.resolve({ data: { deleted_count: 1 } }),
    catalogProducts = {},
} = {}) {
    const cartSource = fs.readFileSync(
        path.resolve(__dirname, '../../public/assets/js/stores/useCart.js'),
        'utf8'
    );
    const parkedSource = fs.readFileSync(
        path.resolve(__dirname, '../../public/assets/js/stores/useParkedTickets.js'),
        'utf8'
    );

    const storage = new Map([
        ['venpostpv_parked', initialParkedStorage],
    ]);

    const context = {
        window: {
            __SESSION__: { user_id: 2, terminal_id: 1, cashier_name: 'María García' },
            TPV_BASE: '',
            addEventListener() {},
        },
        navigator: { onLine: true },
        localStorage: {
            getItem: (key) => (storage.has(key) ? storage.get(key) : null),
            setItem: (key, value) => storage.set(key, String(value)),
            removeItem: (key) => storage.delete(key),
        },
        Vue: {
            reactive: (value) => value,
            computed: (fn) => ({
                get value() {
                    return fn();
                },
            }),
        },
        ApiClient: {
            parkedTicketsList,
            parkedTicketCreate,
            parkedTicketDelete,
            parkedTicketsClear,
            get: (requestPath) => {
                const match = String(requestPath).match(/\/api\/catalog\/product\/(\d+)$/);
                const productId = match ? Number(match[1]) : 0;

                return Promise.resolve({ data: catalogProducts[productId] || null });
            },
        },
        Date,
        Math,
        JSON,
        Promise,
        parseFloat,
        parseInt,
        Number,
        String,
        Array,
        Object,
        console,
    };
    context.globalThis = context;

    vm.runInNewContext(
        `${cartSource}\n${parkedSource}\nglobalThis.__cart = useCart;\nglobalThis.__parked = useParkedTickets;`,
        context,
        { filename: 'parked-harness.js' }
    );

    return {
        useCart: context.__cart,
        useParkedTickets: context.__parked,
        storage,
    };
}

async function testParkPersistsToLocalStorageAndServerWithOptionalNote() {
    const createdPayloads = [];
    const { useCart, useParkedTickets, storage } = loadEnvironment({
        parkedTicketCreate: async (payload) => {
            createdPayloads.push(payload);
            return {
                data: {
                    id: 55,
                    ...payload,
                },
            };
        },
    });

    useCart.add({ id: 10, name: 'Abrigo Gris', price: 100, stock: 5 });
    useCart.applyDiscount('pct', 10);
    useCart.setCustomer({ id: 4, name: 'Elena Ruiz', default: false });

    const parked = await useParkedTickets.parkCart(useCart.cart, 'Probador 2', 'María García');
    useCart.clear();

    assert.equal(parked.id, 55);
    assert.equal(parked.note, 'Probador 2');
    assert.equal(parked.total, 90);
    assert.equal(createdPayloads.length, 1);
    assert.equal(createdPayloads[0].note, 'Probador 2');
    assert.equal(useParkedTickets.state.tickets.length, 1);

    const rawLocal = JSON.parse(storage.get('venpostpv_parked'));
    assert.equal(rawLocal.length, 1);
    assert.equal(rawLocal[0].note, 'Probador 2');
    assert.equal(rawLocal[0].discount.type, 'pct');
    assert.equal(rawLocal[0].discount.value, 10);
}

async function testParkFallsBackToLocalStorageWhenServerFailsAndSyncsOnRefresh() {
    let serverOnline = false;
    const serverRecords = [];

    const { useCart, useParkedTickets, storage } = loadEnvironment({
        parkedTicketCreate: async (payload) => {
            if (!serverOnline) {
                throw new Error('Network offline');
            }
            const record = { id: 88, ...payload };
            serverRecords.push(record);
            return { data: record };
        },
        parkedTicketsList: async () => {
            if (!serverOnline) {
                throw new Error('Network offline');
            }
            return { data: serverRecords };
        },
    });

    useCart.add({ id: 20, name: 'Pantalón Vaquero', price: 45, stock: 4 });
    const offlineTicket = await useParkedTickets.parkCart(useCart.cart, 'Reserva caja', 'María García');

    assert.equal(offlineTicket.synced, false);
    assert.equal(useParkedTickets.state.tickets.length, 1);
    assert.equal(JSON.parse(storage.get('venpostpv_parked')).length, 1);

    serverOnline = true;
    await useParkedTickets.refresh();

    assert.equal(serverRecords.length, 1);
    assert.equal(serverRecords[0].note, 'Reserva caja');
    assert.equal(useParkedTickets.state.tickets.length, 1);
    assert.equal(useParkedTickets.state.tickets[0].id, 88);
}

async function testRestoreSwapsActiveCartByAutoParkingCurrentCartAndPreservingDiscount() {
    const { useCart, useParkedTickets } = loadEnvironment({
        initialParkedStorage: JSON.stringify([
            {
                id: 11,
                client_ref: 'parked_first',
                note: 'Probador 1',
                cashier_name: 'María García',
                customer: { id: 8, name: 'Lucía Martín', default: false },
                lines: [{ id: 'l_a', product_id: 100, name: 'Vestido Azul', price: 80, qty: 1, discount: 0 }],
                discount: { type: 'amt', value: 15 },
                total: 65,
                created_at: '2026-11-01 19:00:00',
            },
        ]),
    });

    // Cashier already has an active cart with its own item and discount
    useCart.add({ id: 200, name: 'Camisa Blanca', price: 40, stock: 3 });
    useCart.applyDiscount('pct', 5);

    const targetTicket = useParkedTickets.state.tickets[0];
    const swapResult = await useParkedTickets.restoreWithSwap(targetTicket, useCart, 'María García');

    assert.equal(swapResult.swapped, true);
    assert.equal(swapResult.autoParked.lines[0].name, 'Camisa Blanca');
    assert.equal(swapResult.autoParked.discount.value, 5);

    // Active cart now holds the restored ticket with its discount and customer intact
    assert.equal(useCart.cart.lines.length, 1);
    assert.equal(useCart.cart.lines[0].name, 'Vestido Azul');
    assert.equal(useCart.cart.customer.name, 'Lucía Martín');
    assert.equal(useCart.cart.discount.type, 'amt');
    assert.equal(useCart.cart.discount.value, 15);
    assert.equal(useCart.cart.total, 65);

    // Parked list now contains only the auto-parked previous cart
    assert.equal(useParkedTickets.state.tickets.length, 1);
    assert.equal(useParkedTickets.state.tickets[0].lines[0].name, 'Camisa Blanca');
}

async function testClearAllEmptiesBothServerAndLocalStorageForZCloseDiscard() {
    let clearedOnServer = false;
    const { useParkedTickets, storage } = loadEnvironment({
        initialParkedStorage: JSON.stringify([
            {
                id: 21,
                client_ref: 'parked_z1',
                note: 'Pendiente Z',
                lines: [{ id: 'l1', product_id: 1, name: 'Bufanda', price: 19, qty: 1, discount: 0 }],
                total: 19,
            },
        ]),
        parkedTicketsClear: async () => {
            clearedOnServer = true;
            return { data: { deleted_count: 1 } };
        },
    });

    assert.equal(useParkedTickets.state.tickets.length, 1);
    await useParkedTickets.clearAll();

    assert.equal(clearedOnServer, true);
    assert.equal(useParkedTickets.state.tickets.length, 0);
    assert.equal(JSON.parse(storage.get('venpostpv_parked')).length, 0);
}

async function testRestoreReplacesTheParkedPriceSnapshotWithTheCatalogPrice() {
    const { useCart, useParkedTickets } = loadEnvironment({
        initialParkedStorage: JSON.stringify([
            {
                id: 31,
                client_ref: 'parked_stale_basis',
                note: 'Probador 3',
                lines: [{ id: 'l_stale', product_id: 100, name: 'Vestido Azul', price: 80, qty: 1, discount: 0 }],
                discount: null,
                total: 80,
                created_at: '2026-11-01 19:30:00',
            },
        ]),
        catalogProducts: { 100: { id: 100, price: 96.8, combinations: [] } },
    });

    await useParkedTickets.restoreWithSwap(useParkedTickets.state.tickets[0], useCart, 'María García');

    assert.equal(useCart.cart.lines.length, 1);
    assert.equal(useCart.cart.lines[0].price, 96.8, 'the frozen parked price must be replaced by the catalog price');
    assert.equal(useCart.cart.total, 96.8);
}

async function testRestoreKeepsTheParkedPriceWhenTheCatalogCannotBeRead() {
    const { useCart, useParkedTickets } = loadEnvironment({
        initialParkedStorage: JSON.stringify([
            {
                id: 32,
                client_ref: 'parked_offline',
                note: 'Probador 4',
                lines: [{ id: 'l_offline', product_id: 555, name: 'Pañuelo', price: 12, qty: 1, discount: 0 }],
                discount: null,
                total: 12,
                created_at: '2026-11-01 19:35:00',
            },
        ]),
        catalogProducts: {},
    });

    await useParkedTickets.restoreWithSwap(useParkedTickets.state.tickets[0], useCart, 'María García');

    assert.equal(useCart.cart.lines[0].price, 12, 'an unreadable product must keep the parked price for the checkout to judge');
    assert.equal(useCart.cart.total, 12);
}

async function testRestoreShowsTheProductImagesAgain() {
    const { useCart, useParkedTickets } = loadEnvironment({
        initialParkedStorage: JSON.stringify([
            {
                id: 33,
                client_ref: 'parked_images',
                note: 'Probador 5',
                lines: [
                    { id: 'l_kept', product_id: 200, id_product_attribute: 0, name: 'Gorro', price: 10, qty: 1, discount: 0, image_url: 'https://shop.test/img/kept.jpg' },
                    { id: 'l_old', product_id: 300, id_product_attribute: 7, name: 'Bañador (Talla S)', price: 36, qty: 1, discount: 0 },
                ],
                discount: null,
                total: 46,
                created_at: '2026-11-10 19:00:00',
            },
        ]),
        catalogProducts: {
            200: { id: 200, price: 10, image_url: 'https://shop.test/img/200.jpg', combinations: [] },
            300: { id: 300, price: 36, image_url: 'https://shop.test/img/300.jpg', combinations: [{ id: 7, price: 36, image_url: 'https://shop.test/img/300-7.jpg' }] },
        },
    });

    assert.equal(useParkedTickets.state.tickets[0].lines[0].image_url, 'https://shop.test/img/kept.jpg', 'a parked line keeps its image');

    await useParkedTickets.restoreWithSwap(useParkedTickets.state.tickets[0], useCart, 'María García');

    assert.equal(useCart.cart.lines[0].image_url, 'https://shop.test/img/kept.jpg');
    assert.equal(useCart.cart.lines[1].image_url, 'https://shop.test/img/300-7.jpg', 'a ticket parked without images gets the combination image from the catalog');
}

async function main() {
    await testParkPersistsToLocalStorageAndServerWithOptionalNote();
    await testParkFallsBackToLocalStorageWhenServerFailsAndSyncsOnRefresh();
    await testRestoreSwapsActiveCartByAutoParkingCurrentCartAndPreservingDiscount();
    await testClearAllEmptiesBothServerAndLocalStorageForZCloseDiscard();
    await testRestoreReplacesTheParkedPriceSnapshotWithTheCatalogPrice();
    await testRestoreKeepsTheParkedPriceWhenTheCatalogCannotBeRead();
    await testRestoreShowsTheProductImagesAgain();
    console.log('parkedTickets functional harness: 7 passed');
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
