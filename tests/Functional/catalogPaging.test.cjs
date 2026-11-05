const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

// A fake catalog of 120 products answered by /api/catalog/search with limit/offset.
function loadCatalog(total = 120, stored = {}) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/stores/useCatalog.js'), 'utf8');
    const requests = [];
    const pending = [];
    const context = {
        Vue: { reactive: (value) => value },
        URLSearchParams,
        String,
        Number,
        Math,
        console,
        localStorage: {
            getItem: (k) => (k in stored ? stored[k] : null),
            setItem: (k, v) => { stored[k] = String(v); },
        },
        ApiClient: {
            get(url) {
                requests.push(url);
                const params = new URLSearchParams(url.split('?')[1] || '');
                if (url.startsWith('/api/catalog/categories')) {
                    return Promise.resolve({ data: [] });
                }
                const limit = Number(params.get('limit') || 50);
                const offset = Number(params.get('offset') || 0);
                const q = params.get('q') || '';
                const pool = Array.from({ length: q ? 3 : total }, (_, i) => ({ id: (q ? 1000 : 1) + i, name: q + 'P' + i }));
                return new Promise((resolve) => pending.push(() => resolve({ data: pool.slice(offset, offset + limit) })));
            },
        },
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__catalog = useCatalog;`, context, { filename: 'useCatalog.js' });

    const flush = async () => {
        while (pending.length) {
            pending.shift()();
            await new Promise((r) => setImmediate(r));
        }
    };

    return { store: context.__catalog, requests, flush };
}

test('the first page shows 50 products and says there are more', async () => {
    const { store, flush } = loadCatalog();
    await flush();

    assert.equal(store.catalog.products.length, 50);
    assert.equal(store.catalog.hasMore, true);
});

test('loadMore appends the next pages until the catalog ends', async () => {
    const { store, requests, flush } = loadCatalog(120);
    await flush();

    store.loadMore();
    await flush();
    assert.equal(store.catalog.products.length, 100);
    assert.ok(requests.some((u) => u.includes('offset=50')));

    store.loadMore();
    await flush();
    assert.equal(store.catalog.products.length, 120);
    assert.equal(store.catalog.hasMore, false);
    assert.equal(new Set(store.catalog.products.map((p) => p.id)).size, 120, 'no product repeated');

    const before = requests.length;
    store.loadMore();
    await flush();
    assert.equal(requests.length, before, 'nothing left to ask for');
});

test('a new search replaces the list and a late page of the old one is ignored', async () => {
    const { store, flush } = loadCatalog(120);
    await flush();

    store.loadMore(); // still pending
    store.setSearch('abc');
    await flush();

    assert.deepEqual(store.catalog.products.map((p) => p.id), [1000, 1001, 1002]);
    assert.equal(store.catalog.hasMore, false);
});

test('loadMore while a page is loading does not ask twice', async () => {
    const { store, requests, flush } = loadCatalog(120);
    await flush();

    store.loadMore();
    store.loadMore();
    assert.equal(requests.filter((u) => u.includes('offset=50')).length, 1);
    await flush();
});

test('only in stock is off by default and sends in_stock=1 when switched on, and is remembered', async () => {
    const stored = {};
    const { store, requests, flush } = loadCatalog(120, stored);
    await flush();
    assert.equal(store.catalog.onlyInStock, false);
    assert.ok(!requests.some((u) => u.includes('in_stock')));

    store.setOnlyInStock(true);
    await flush();
    assert.ok(requests[requests.length - 1].includes('in_stock=1'));

    store.loadMore();
    await flush();
    assert.ok(requests[requests.length - 1].includes('in_stock=1'), 'next pages keep the filter');

    const again = loadCatalog(120, stored);
    await again.flush();
    assert.equal(again.store.catalog.onlyInStock, true, 'remembered on this computer');
    assert.ok(again.requests.some((u) => u.includes('in_stock=1')));
});

test('a search looks in the whole catalog: no category nor stock filter', async () => {
    const { store, requests, flush } = loadCatalog(120);
    await flush();
    store.setCategory(7);
    store.setOnlyInStock(true);
    await flush();
    assert.ok(requests[requests.length - 1].includes('category=7'));
    assert.ok(requests[requests.length - 1].includes('in_stock=1'));

    store.setSearch('8412345678901');
    await flush();
    const last = requests[requests.length - 1];
    assert.ok(last.includes('q=8412345678901'));
    assert.ok(!last.includes('category='), 'search ignores the chosen category');
    assert.ok(!last.includes('in_stock'), 'search ignores the stock filter');

    store.setSearch('');
    await flush();
    assert.ok(requests[requests.length - 1].includes('category=7'), 'clearing the search brings the filters back');
});

test('the category search button limits a search to the chosen category', async () => {
    const { store, requests, flush } = loadCatalog(120);
    await flush();
    assert.equal(store.catalog.searchInCategory, false);
    store.setCategory(7);
    store.setSearchInCategory(true);
    store.setSearch('azul');
    await flush();
    const last = requests[requests.length - 1];
    assert.ok(last.includes('q=azul') && last.includes('category=7'));
    assert.ok(!last.includes('in_stock'), 'the stock filter still does not apply to a search');

    store.setCategory(null);
    await flush();
    assert.ok(!requests[requests.length - 1].includes('category='), 'with "Todo" it searches everything');
});
