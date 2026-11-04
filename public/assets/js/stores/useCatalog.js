/**
 * Catalog store — reactive catalog with products, categories, and search.
 */
const useCatalog = (function () {
    const catalog = Vue.reactive({
        products: [],
        categories: [],
        search: '',
        activeCategory: null,
        loading: false,
        // Paging: 50 products at a time; the list asks for more at its end.
        loadingMore: false,
        hasMore: false,
    });

    const PAGE_SIZE = 50;
    // Each new search/category bumps this, so a page of an older list is dropped.
    let listVersion = 0;

    function searchUrl(offset) {
        const params = new URLSearchParams();
        if (catalog.search) params.set('q', catalog.search);
        if (catalog.activeCategory) params.set('category', String(catalog.activeCategory));
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
        setSearch,
        setCategory,
    };
})();
