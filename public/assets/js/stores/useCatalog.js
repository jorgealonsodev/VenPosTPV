/**
 * Catalog store — reactive catalog with products, categories, and search.
 */
const useCatalog = (function () {
    // "Solo con stock" is remembered per computer; storage may be unavailable.
    const IN_STOCK_KEY = 'tpv.catalog.onlyInStock';
    function readOnlyInStock() {
        try { return localStorage.getItem(IN_STOCK_KEY) === '1'; } catch (e) { return false; }
    }

    const catalog = Vue.reactive({
        products: [],
        categories: [],
        search: '',
        activeCategory: null,
        loading: false,
        // Paging: 50 products at a time; the list asks for more at its end.
        loadingMore: false,
        hasMore: false,
        onlyInStock: readOnlyInStock(),
        // Off: a search looks in the whole catalog. On: only in the chosen category.
        searchInCategory: false,
    });

    const PAGE_SIZE = 50;
    // Each new search/category bumps this, so a page of an older list is dropped.
    let listVersion = 0;

    function searchUrl(offset) {
        const params = new URLSearchParams();
        // A search (a name, a reference or a scanned code) looks in the whole
        // catalog, or only in the chosen category when that button is on; the
        // stock filter only shapes browsing.
        if (catalog.search) {
            params.set('q', catalog.search);
            if (catalog.searchInCategory && catalog.activeCategory) params.set('category', String(catalog.activeCategory));
        } else {
            if (catalog.activeCategory) params.set('category', String(catalog.activeCategory));
            if (catalog.onlyInStock) params.set('in_stock', '1');
        }
        params.set('limit', String(PAGE_SIZE));
        if (offset > 0) params.set('offset', String(offset));
        return '/api/catalog/search?' + params.toString();
    }

    async function loadCategories() {
        catalog.loading = true;
        try {
            const res = await ApiClient.get('/api/catalog/categories');
            catalog.categories = res.data || [];
        } catch (e) {
            catalog.categories = [];
        } finally {
            catalog.loading = false;
        }
    }

    async function loadProducts() {
        const version = ++listVersion;
        catalog.loading = true;
        catalog.loadingMore = false;
        try {
            const res = await ApiClient.get(searchUrl(0));
            if (version !== listVersion) return;
            const page = res.data || [];
            catalog.products = page;
            catalog.hasMore = page.length === PAGE_SIZE;
        } catch (e) {
            if (version !== listVersion) return;
            catalog.products = [];
            catalog.hasMore = false;
        } finally {
            if (version === listVersion) catalog.loading = false;
        }
    }

    async function loadMore() {
        if (!catalog.hasMore || catalog.loading || catalog.loadingMore) return;
        const version = listVersion;
        catalog.loadingMore = true;
        try {
            const res = await ApiClient.get(searchUrl(catalog.products.length));
            if (version !== listVersion) return;
            const page = res.data || [];
            const known = new Set(catalog.products.map((p) => p.id));
            catalog.products = catalog.products.concat(page.filter((p) => !known.has(p.id)));
            catalog.hasMore = page.length === PAGE_SIZE;
        } catch (e) {
            // Keep what is shown; scrolling again retries.
        } finally {
            if (version === listVersion) catalog.loadingMore = false;
        }
    }

    function setSearch(q) {
        catalog.search = q;
        loadProducts();
    }

    function setOnlyInStock(on) {
        catalog.onlyInStock = on === true;
        try { localStorage.setItem(IN_STOCK_KEY, catalog.onlyInStock ? '1' : '0'); } catch (e) { /* not remembered */ }
        loadProducts();
    }

    function setSearchInCategory(on) {
        catalog.searchInCategory = on === true;
        if (catalog.search) loadProducts();
    }

    function setCategory(catId) {
        catalog.activeCategory = catId;
        loadProducts();
    }

    // Load on init
    loadCategories();
    loadProducts();

    return {
        catalog,
        loadCategories,
        loadProducts,
        loadMore,
        setOnlyInStock,
        setSearchInCategory,
        setSearch,
        setCategory,
    };
})();
