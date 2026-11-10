/**
 * FreeItemModal — docs/prd/articulo-libre.md A1.2: a product that is not in the
 * catalog, sold with a typed concept, a tax-included price, a VAT rate and a
 * quantity. The checks mirror src/Service/Checkout/FreeItemLine.php with the same
 * messages; the server validates again when the ticket is charged.
 *
 * props.settings: GET /api/checkout/free-item-settings data
 * ({ enabled, role, max_price, vat_rates, allowed }).
 */
const FreeItemModal = (function () {
    /** PrestaShop refuses these in a product name. */
    const FORBIDDEN = /[<>;=#{}]/;
    /** PHP trim() characters, so the concept is trimmed exactly like the server does. */
    const EDGE_SPACES = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;
    /** Windows-1252 characters outside Latin-1 (0x80-0x9F), what the ticket printer prints. */
    const CP1252_EXTRA = new Set('€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ');
    const MAX_QTY_DIGITS = 3;
    const MAX_PRICE_DIGITS = 7;

    function normalizeConcept(value) {
        return String(value || '').replace(/[ \t\r\n]+/g, ' ').replace(EDGE_SPACES, '');
    }

    function printable(text) {
        for (const ch of text) {
            const code = ch.codePointAt(0);
            const latin1 = (code >= 0x20 && code <= 0x7E) || (code >= 0xA0 && code <= 0xFF);
            if (!latin1 && !CP1252_EXTRA.has(ch)) {
                return false;
            }
        }
        return true;
    }

    function formatMax(max) {
        return (Number(max) || 0).toFixed(2).replace('.', ',');
    }

    /**
     * @returns {{ error: string } | { concept: string, price: number, qty: number }}
     */
    function validate(input, maxPrice) {
        const concept = normalizeConcept(input.concept);
        if (concept === '') {
            return { error: 'El concepto es obligatorio' };
        }
        if (Array.from(concept).length > 100) {
            return { error: 'El concepto no puede tener más de 100 caracteres' };
        }
        if (FORBIDDEN.test(concept)) {
            return { error: 'El concepto no puede llevar < > ; = # { }' };
        }
        if (!printable(concept)) {
            return { error: 'El concepto tiene caracteres que no se pueden imprimir' };
        }

        const range = 'El precio debe estar entre 0,01 € y ' + formatMax(maxPrice) + ' €';
        const rawPrice = String(input.price === undefined || input.price === null ? '' : input.price).trim().replace(',', '.');
        if (!/^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(rawPrice)) {
            return { error: range };
        }
        const price = Number(rawPrice);
        if (Math.abs(Math.round(price * 100) / 100 - price) > 1e-9) {
            return { error: 'El precio no puede tener más de 2 decimales' };
        }
        if (price < 0.01 || price > Number(maxPrice)) {
            return { error: range };
        }

        const rawQty = String(input.qty === undefined || input.qty === null ? '' : input.qty).trim();
        const qty = /^\d+$/.test(rawQty) ? Number(rawQty) : 0;
        if (qty < 1 || qty > 999) {
            return { error: 'La cantidad debe ser un número entero entre 1 y 999' };
        }

        return { concept, price: Math.round(price * 100) / 100, qty };
    }

    return {
        props: ['settings'],
        emits: ['close'],
        setup(props, { emit }) {
            const settings = props.settings || {};
            const rates = Array.isArray(settings.vat_rates) ? settings.vat_rates.map(Number) : [];
            const maxPrice = Number(settings.max_price) || 0;

            const concept = Vue.ref('');
            const price = Vue.ref('');
            const vatRate = Vue.ref(rates.length ? rates[0] : null);
            const qty = Vue.ref('1');
            const active = Vue.ref('price');
            const error = Vue.ref('');

            function focus(field) {
                active.value = field;
            }

            function setRate(rate) {
                vatRate.value = Number(rate);
            }

            function stepQty(delta) {
                const current = /^\d+$/.test(qty.value) ? Number(qty.value) : 0;
                qty.value = String(Math.min(999, Math.max(1, current + delta)));
            }

            /** On-screen keypad: digits, 'decimal' and 'backspace' into the active field. */
            function onKey(key) {
                const target = active.value === 'qty' ? qty : price;
                const value = String(target.value || '');
                if (key === 'backspace') {
                    target.value = value.slice(0, -1);
                    return;
                }
                if (active.value === 'qty') {
                    if (/^\d$/.test(key) && value.length < MAX_QTY_DIGITS) {
                        target.value = value + key;
                    }
                    return;
                }
                const separator = value.search(/[.,]/);
                if (key === 'decimal') {
                    if (separator < 0) {
                        target.value = (value === '' ? '0' : value) + ',';
                    }
                    return;
                }
                if (!/^\d$/.test(key)) {
                    return;
                }
                if (separator >= 0 ? value.length - separator > 2 : value.length >= MAX_PRICE_DIGITS) {
                    return;
                }
                target.value = value + key;
            }

            function add() {
                const result = validate({ concept: concept.value, price: price.value, qty: qty.value }, maxPrice);
                if (result.error) {
                    error.value = result.error;
                    return;
                }
                if (vatRate.value === null || !rates.includes(Number(vatRate.value))) {
                    error.value = 'El tipo de IVA ' + (vatRate.value === null ? '' : vatRate.value) + ' % no está configurado';
                    return;
                }
                error.value = '';
                useCart.addFree({ concept: result.concept, price: result.price, vatRate: Number(vatRate.value), qty: result.qty });
                emit('close');
            }

            const maxLabel = formatMax(maxPrice) + ' €';

            return { rates, concept, price, vatRate, qty, active, error, maxLabel, focus, setRate, stepQty, onKey, add };
        },
        template: `
        <div class="modal-backdrop" style="backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);">
            <div class="free-item-modal" role="dialog" aria-modal="true" aria-labelledby="free-item-title" @click.stop>
                <div id="free-item-title" style="font-size:18px; font-weight:700;">Artículo libre</div>
                <div class="free-item-body">
                    <div class="free-item-fields">
                        <label class="tpv-label" for="free-item-concept">Concepto</label>
                        <input id="free-item-concept" v-model="concept" type="text" autocomplete="off" autofocus
                            class="tpv-input free-item-input" placeholder="Ej. Arreglo de bajo" @focus="focus('concept')" />

                        <label class="tpv-label" for="free-item-price">Precio con IVA (máx. {{ maxLabel }})</label>
                        <input id="free-item-price" v-model="price" type="text" inputmode="decimal" autocomplete="off"
                            class="tpv-input free-item-input pay-num" :class="{ 'is-active': active === 'price' }"
                            placeholder="0,00" @focus="focus('price')" />

                        <div class="tpv-label">Tipo de IVA</div>
                        <div style="display:flex; gap:8px;" role="radiogroup" aria-label="Tipo de IVA">
                            <button v-for="r in rates" :key="r" type="button" @click="setRate(r)"
                                class="tpv-chip free-item-rate" :class="{ 'is-selected': vatRate === r }"
                                role="radio" :aria-checked="vatRate === r ? 'true' : 'false'">
                                {{ String(r).replace('.', ',') }} %
                            </button>
                        </div>

                        <label class="tpv-label" for="free-item-qty">Cantidad</label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button type="button" class="tpv-btn free-item-step" aria-label="Menos" @click="stepQty(-1)">
                                <icon name="minus" :size="16" />
                            </button>
                            <input id="free-item-qty" v-model="qty" type="text" inputmode="numeric" autocomplete="off"
                                class="tpv-input free-item-input pay-num" :class="{ 'is-active': active === 'qty' }"
                                style="text-align:center; flex:1;" @focus="focus('qty')" />
                            <button type="button" class="tpv-btn free-item-step" aria-label="Más" @click="stepQty(1)">
                                <icon name="plus" :size="16" />
                            </button>
                        </div>
                    </div>
                    <div class="free-item-keypad">
                        <numpad @input="onKey" />
                    </div>
                </div>
                <div v-if="error" role="alert" style="color:var(--red); font-size:14px;">{{ error }}</div>
                <div style="display:flex; gap:8px;">
                    <button type="button" @click="$emit('close')" class="tpv-btn" style="flex:1; min-height:52px;">Cancelar</button>
                    <button type="button" @click="add" class="tpv-btn tpv-btn-primary" style="flex:2; min-height:52px; font-weight:700;">Añadir</button>
                </div>
            </div>
        </div>
    `,
    };
})();
