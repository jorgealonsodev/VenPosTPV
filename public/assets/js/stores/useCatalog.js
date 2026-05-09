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
    });

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
        catalog.loading = true;
        try {
            const params = new URLSearchParams();
            if (catalog.search) params.set('q', catalog.search);
            if (catalog.activeCategory) params.set('category', String(catalog.activeCategory));
            params.set('limit', '50');
            const res = await ApiClient.get('/api/catalog/search?' + params.toString());
            catalog.products = res.data || [];
        } catch (e) {
            catalog.products = [];
        } finally {
            catalog.loading = false;
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
        setSearch,
        setCategory,
    };
})();
