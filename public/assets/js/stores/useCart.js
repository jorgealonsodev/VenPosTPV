/**
 * Cart store — reactive cart with localStorage-first + server sync fallback.
 */
const useCart = (function () {
    const STORAGE_KEY = 'venpostpv_cart';
    const cart = Vue.reactive({
        lines: [],
        subtotal: 0,
        discount: null,
        discount_amount: 0,
        iva: 0,
        total: 0,
        customer: { name: 'Consumidor final', default: true },
    });

    const IVA_RATE = 0.21;

    /** Flat delivery fee the customer pays on top of the goods. */
    const DELIVERY_FEE = 10;
    const DELIVERY_LINE_ID = 'line_delivery';

    function load() {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            try {
                const data = JSON.parse(raw);
                cart.lines = data.lines || [];
                cart.discount = data.discount || null;
                cart.customer = data.customer || { name: 'Consumidor final', default: true };
            } catch (e) {
                // ignore
            }
        }
        recalc();
    }

    function save() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            lines: cart.lines,
            discount: cart.discount,
            customer: cart.customer,
        }));
    }

    function recalc() {
        let sub = 0;
        cart.lines.forEach(line => {
            const price = parseFloat(line.price) || 0;
            const qty = parseInt(line.qty, 10) || 1;
            const disc = parseFloat(line.discount) || 0;
            sub += price * qty * (1 - disc / 100);
        });
        cart.subtotal = Math.round(sub * 100) / 100;

        let discAmt = 0;
        if (cart.discount) {
            const val = parseFloat(cart.discount.value) || 0;
            if (cart.discount.type === 'pct') {
                discAmt = cart.subtotal * val / 100;
            } else {
                discAmt = Math.min(val, cart.subtotal);
            }
        }
        cart.discount_amount = Math.round(discAmt * 100) / 100;
        const total = Math.max(0, cart.subtotal - cart.discount_amount);
        cart.total = Math.round(total * 100) / 100;

        // VAT is what each line carries, `price - net_price`, taken from the shop's
        // own prices and scaled by the discount, because VAT is owed on the amount
        // the customer actually pays. The fixed rate is only a fallback for a line
        // whose net price the catalog could not resolve.
        let vat = 0;
        let linesWithNet = 0;
        cart.lines.forEach(line => {
            const net = line.net_price === null || line.net_price === undefined
                ? null
                : parseFloat(line.net_price);
            if (net === null || !Number.isFinite(net)) {
                return;
            }
            const price = parseFloat(line.price) || 0;
            const qty = parseInt(line.qty, 10) || 1;
            const disc = parseFloat(line.discount) || 0;
            vat += (price - net) * qty * (1 - disc / 100);
            linesWithNet += 1;
        });

        if (linesWithNet > 0 && cart.subtotal > 0) {
            cart.iva = Math.round(vat * (total / cart.subtotal) * 100) / 100;
        } else {
            cart.iva = Math.round((total * IVA_RATE / (1 + IVA_RATE)) * 100) / 100;
        }
    }

    function add(product) {
        if (product.stock !== undefined && product.stock <= 0) {
            return false;
        }
        // For combinations, use a compound key so each variant is a separate line
        const combinationId = product.selected_combination_id || 0;
        const idx = cart.lines.findIndex(l =>
            l.product_id === product.id && (l.id_product_attribute || 0) === combinationId
        );
        if (idx >= 0) {
            cart.lines[idx].qty = (cart.lines[idx].qty || 1) + 1;
        } else {
            cart.lines.push({
                id: 'line_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
                product_id: product.id,
                id_product_attribute: combinationId,
                name: product.combination_label
                    ? product.name + ' (' + product.combination_label + ')'
                    : product.name,
                reference: product.reference || '',
                price: product.price,
                net_price: product.net_price === undefined || product.net_price === null
                    ? null
                    : Number(product.net_price),
                qty: 1,
                discount: 0,
            });
        }
        recalc();
        save();
        return true;
    }

    /**
     * Adds the flat delivery fee as its own line, so it appears on the ticket, takes
     * part in the total and carries its own VAT. Idempotent: one delivery per sale.
     */
    function addDelivery() {
        if (cart.lines.some(line => line.id === DELIVERY_LINE_ID)) {
            return false;
        }

        cart.lines.push({
            id: DELIVERY_LINE_ID,
            kind: 'delivery',
            product_id: 0,
            id_product_attribute: 0,
            name: 'Entrega a domicilio',
            reference: '',
            price: DELIVERY_FEE,
            net_price: Math.round((DELIVERY_FEE / (1 + IVA_RATE)) * 100) / 100,
            qty: 1,
            discount: 0,
        });

        recalc();
        save();

        return true;
    }

    function removeDelivery() {
        if (!cart.lines.some(line => line.id === DELIVERY_LINE_ID)) {
            return false;
        }

        cart.lines = cart.lines.filter(line => line.id !== DELIVERY_LINE_ID);
        recalc();
        save();

        return true;
    }

    function hasDelivery() {
        return cart.lines.some(line => line.id === DELIVERY_LINE_ID);
    }

    function remove(lineId) {
        cart.lines = cart.lines.filter(l => l.id !== lineId);
        recalc();
        save();
    }

    function updateQty(lineId, delta) {
        const idx = cart.lines.findIndex(l => l.id === lineId);
        if (idx < 0) return;
        const newQty = (cart.lines[idx].qty || 1) + delta;
        if (newQty <= 0) {
            remove(lineId);
        } else {
            cart.lines[idx].qty = newQty;
            recalc();
            save();
        }
    }

    function clear() {
        cart.lines = [];
        cart.discount = null;
        cart.customer = { name: 'Consumidor final', default: true };
        recalc();
        save();
    }

    function subtotalOf() {
        let sub = 0;
        cart.lines.forEach(line => {
            const price = parseFloat(line.price) || 0;
            const qty = parseInt(line.qty, 10) || 1;
            const disc = parseFloat(line.discount) || 0;
            sub += price * qty * (1 - disc / 100);
        });

        return Math.round(sub * 100) / 100;
    }

    function applyDiscount(type, value) {
        const normalizedType = type === 'amt' ? 'amt' : 'pct';
        let normalized = parseFloat(value);

        if (!Number.isFinite(normalized) || normalized <= 0) {
            cart.discount = null;
            recalc();
            save();
            return;
        }

        if (normalizedType === 'pct') {
            normalized = Math.min(normalized, 100);
        } else {
            normalized = Math.min(normalized, subtotalOf());
            if (normalized <= 0) {
                cart.discount = null;
                recalc();
                save();
                return;
            }
        }

        cart.discount = { type: normalizedType, value: normalized };
        recalc();
        save();
    }

    function setCustomer(customer) {
        cart.customer = customer;
        save();
    }

    /**
     * Re-prices every line from the catalog, because a line keeps the price
     * snapshot taken when it was added and a parked ticket keeps the snapshot
     * taken when it was parked. Once the catalog carries the authoritative
     * tax-included price, a frozen snapshot is a stale basis and the checkout
     * rejects the sale.
     *
     * `resolveProduct(productId)` must return the catalog product (with its
     * combinations) or null when the product is unknown; a null product and a
     * failing resolver both leave the line untouched so the checkout, not the
     * cart, decides about an unknown or unreachable product.
     *
     * @returns {Promise<boolean>} whether any line price changed
     */
    async function reprice(resolveProduct) {
        const resolver = resolveProduct || defaultProductResolver;
        const cache = new Map();
        let changed = false;

        for (const line of cart.lines) {
            const productId = Number(line.product_id);
            if (!Number.isInteger(productId) || productId <= 0) {
                continue;
            }

            let product = cache.get(productId);
            if (product === undefined) {
                try {
                    product = await resolver(productId);
                } catch (e) {
                    product = null;
                }
                cache.set(productId, product);
            }

            if (!product) {
                continue;
            }

            const price = catalogUnitPrice(product, Number(line.id_product_attribute) || 0);
            if (price === null) {
                continue;
            }

            const net = catalogUnitNet(product, Number(line.id_product_attribute) || 0);
            if (net !== null && Math.abs(net - (Number(line.net_price) || 0)) > 0.001) {
                line.net_price = net;
                changed = true;
            }

            if (Math.abs(price - (Number(line.price) || 0)) > 0.001) {
                line.price = price;
                changed = true;
            }
        }

        if (changed) {
            recalc();
            save();
        }

        return changed;
    }

    /**
     * Mirrors CatalogPanel.onCombinationSelect: the line price is the catalog
     * product price plus the impact of the selected combination.
     */
    function catalogUnitPrice(product, combinationId) {
        const basePrice = Number(product.price);
        if (!Number.isFinite(basePrice)) {
            return null;
        }

        if (combinationId <= 0) {
            return basePrice;
        }

        const combinations = Array.isArray(product.combinations) ? product.combinations : [];
        const combination = combinations.find((c) => Number(c.id) === combinationId);
        if (!combination) {
            return basePrice;
        }

        const impact = Number(combination.price_impact) || 0;

        return Math.round((basePrice + impact) * 100) / 100;
    }

    /**
     * Tax-excluded price of the line, with reductions applied, so the VAT of a line
     * is `price - net_price`. Returns null when the catalog does not know it.
     */
    function catalogUnitNet(product, combinationId) {
        if (combinationId > 0) {
            const combinations = Array.isArray(product.combinations) ? product.combinations : [];
            const combination = combinations.find((c) => Number(c.id) === combinationId);
            if (combination && combination.net_price !== undefined && combination.net_price !== null) {
                return Number(combination.net_price);
            }
        }

        return product.net_price === undefined || product.net_price === null
            ? null
            : Number(product.net_price);
    }

    /**
     * Default resolver: reads the product from the POS API. It stays lazy so this
     * module keeps working without the API client (tests, offline screens).
     */
    async function defaultProductResolver(productId) {
        if (typeof ApiClient === 'undefined' || !ApiClient || typeof ApiClient.get !== 'function') {
            return null;
        }

        const response = await ApiClient.get('/api/catalog/product/' + productId);

        return response && response.data ? response.data : null;
    }

    function restore(lines, customer, discount = null) {
        cart.lines = Array.isArray(lines)
            ? lines.map(l => ({ ...l }))
            : [];
        cart.discount = discount && typeof discount === 'object'
            ? { type: discount.type || 'pct', value: Number(discount.value || 0) }
            : null;
        cart.customer = customer && typeof customer === 'object'
            ? { ...customer }
            : { name: 'Consumidor final', default: true };
        recalc();
        save();
    }

    load();

    return {
        cart,
        add,
        remove,
        updateQty,
        addDelivery,
        removeDelivery,
        hasDelivery,
        clear,
        applyDiscount,
        setCustomer,
        restore,
        reprice,
    };
})();
