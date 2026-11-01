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
        cart.iva = Math.round((total * IVA_RATE / (1 + IVA_RATE)) * 100) / 100;
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
                qty: 1,
                discount: 0,
            });
        }
        recalc();
        save();
        return true;
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
        clear,
        applyDiscount,
        setCustomer,
        restore,
    };
})();
