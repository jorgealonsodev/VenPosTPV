/**
 * ProductEditModal — «Modo edición» of the sale screen (administrators): the stock,
 * EAN and reference of a product or of one of its sizes/colours.
 *
 * - Stock is shared with the web shop: the new quantity is written to PrestaShop
 *   first and then to the till (PUT /api/catalog/edit/{id}/{attr}/stock).
 * - Price with VAT and the product's VAT rate, EAN and reference are written to
 *   PrestaShop too (PUT …/price, PUT …/override with module 0.8.4); emptying the
 *   EAN or reference clears it in the web. A value kept only at the till from
 *   before moves to the web on «Guardar»; «Restablecer» drops it (DELETE …/override). The checks
 * mirror src/Service/Catalog/CatalogEditService.php with the same messages; the
 * server validates again.
 *
 * props: productId, attributeId (0 = the product), title.
 */
const ProductEditModal = (function () {
    function validEanCheckDigit(digits) {
        const body = digits.slice(0, -1).split('').reverse();
        let sum = 0;
        body.forEach((d, i) => { sum += Number(d) * (i % 2 === 0 ? 3 : 1); });
        return (10 - (sum % 10)) % 10 === Number(digits.slice(-1));
    }

    /**
     * @returns {{ error: string } | { ean13: string|null, reference: string|null, quantity: number|null }}
     *          quantity null = the stock is not changed
     */
    function validate(input) {
        const ean = String(input.ean13 == null ? '' : input.ean13).trim();
        // The EAN the item already has is kept as it is, even if its check digit is wrong.
        if (ean !== '' && ean !== String(input.currentEan == null ? '' : input.currentEan)) {
            if (!/^(\d{8}|\d{13})$/.test(ean)) return { error: 'El EAN debe tener 8 o 13 cifras' };
            if (!validEanCheckDigit(ean)) return { error: 'El EAN «' + ean + '» no es válido: el dígito de control no cuadra' };
        }
        const reference = String(input.reference == null ? '' : input.reference).trim();
        if (reference.length > 64) return { error: 'La referencia admite hasta 64 caracteres' };
        let quantity = null;
        if (input.stockEditable) {
            const raw = String(input.quantity == null ? '' : input.quantity).trim();
            if (!/^\d{1,6}$/.test(raw)) return { error: 'El stock debe ser un número entero igual o mayor que 0' };
            quantity = Number(raw);
        }
        let price = null;
        let vatRate = null;
        if (input.priceEditable) {
            const rawPrice = String(input.price == null ? '' : input.price).trim().replace(',', '.');
            if (!/^\d{1,5}(\.\d{1,2})?$/.test(rawPrice)) return { error: 'El precio debe ser un importe con hasta 2 decimales, por ejemplo 270,00' };
            price = Number(rawPrice);
            vatRate = Number(input.vatRate);
            if (!VAT_RATES.includes(vatRate)) return { error: 'Elige el IVA: 21, 10, 4 o 0 %' };
        }
        return { ean13: ean === '' ? null : ean, reference: reference === '' ? null : reference, quantity, price, vatRate };
    }

    // The VAT rates PrestaShop can apply (CatalogEditService::VAT_RATES).
    const VAT_RATES = [21, 10, 4, 0];

    function errorText(err, fallback) {
        return (err && err.body && err.body.error) || fallback;
    }

    const component = {
        name: 'ProductEditModal',
        props: {
            productId: { type: Number, required: true },
            attributeId: { type: Number, default: 0 },
            title: { type: String, default: '' },
        },
        emits: ['close', 'saved', 'notify'],
        data() {
            return { item: null, loading: true, saving: false, error: '', quantity: '', ean13: '', reference: '', price: '', vatRate: 21, vatRates: VAT_RATES };
        },
        computed: {
            base() {
                return '/api/catalog/edit/' + encodeURIComponent(this.productId) + '/' + encodeURIComponent(this.attributeId);
            },
            hasLocal() {
                return !!(this.item && this.item.local);
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            fill(item) {
                this.item = item;
                this.quantity = String(item.stock.quantity);
                // What the till uses now (a value kept only in the TPV before, else the web's).
                this.ean13 = (item.effective && item.effective.ean13) || '';
                this.reference = (item.effective && item.effective.reference) || '';
                const price = item.price || {};
                this.price = price.price == null ? '' : Number(price.price).toFixed(2).replace('.', ',');
                this.vatRate = price.vat_rate == null ? 21 : Number(price.vat_rate);
            },
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const res = await ApiClient.get(this.base);
                    this.fill(res.data);
                } catch (err) {
                    this.error = errorText(err, 'No se ha podido cargar el artículo');
                } finally {
                    this.loading = false;
                }
            },
            async save() {
                if (!this.item || this.saving) return;
                const checked = validate({ ean13: this.ean13, currentEan: (this.item.effective && this.item.effective.ean13) || '', reference: this.reference, quantity: this.quantity, stockEditable: this.item.stock.editable,
                    price: this.price, vatRate: this.vatRate, priceEditable: !!this.item.price });
                if (checked.error) {
                    this.error = checked.error;
                    return;
                }
                this.error = '';
                this.saving = true;
                let item = this.item;
                try {
                    // Saved in the web when it differs from the web, or to move a TPV-only value there.
                    const synced = item.synced || {};
                    if (item.local || checked.ean13 !== (synced.ean13 || null) || checked.reference !== (synced.reference || null)) {
                        item = (await ApiClient.catalogSaveOverride(this.productId, this.attributeId, { ean13: checked.ean13, reference: checked.reference })).data;
                    }
                    if (checked.quantity !== null && checked.quantity !== item.stock.quantity) {
                        item = (await ApiClient.catalogSetStock(this.productId, this.attributeId, checked.quantity)).data;
                    }
                    const current = item.price || {};
                    if (checked.price !== null && (checked.price !== Number(current.price) || checked.vatRate !== (current.vat_rate == null ? null : Number(current.vat_rate)))) {
                        item = (await ApiClient.catalogSetPrice(this.productId, this.attributeId, checked.price, checked.vatRate)).data;
                    }
                    this.fill(item);
                    this.$emit('saved', item);
                    this.$emit('notify', 'Cambios guardados', 'var(--green)');
                    this.$emit('close');
                } catch (err) {
                    // A part may already be saved (the local EAN before a refused stock): show it.
                    if (item !== this.item) this.fill(item);
                    this.error = errorText(err, 'No se han podido guardar los cambios');
                } finally {
                    this.saving = false;
                }
            },
            async reset() {
                if (!this.hasLocal || this.saving) return;
                this.saving = true;
                this.error = '';
                try {
                    const item = (await ApiClient.catalogResetOverride(this.productId, this.attributeId)).data;
                    this.fill(item);
                    this.$emit('saved', item);
                    this.$emit('notify', 'Datos locales eliminados: vuelven los de la web', 'var(--green)');
                } catch (err) {
                    this.error = errorText(err, 'No se ha podido restablecer');
                } finally {
                    this.saving = false;
                }
            },
        },
        template: `
        <div class="modal-backdrop product-edit-backdrop">
            <div class="product-edit-modal" role="dialog" aria-modal="true" aria-labelledby="product-edit-title" @click.stop>
                <div style="display:flex; align-items:flex-start; gap:8px;">
                    <div style="flex:1; min-width:0;">
                        <div class="product-edit-kicker">Modo edición</div>
                        <div id="product-edit-title" style="font-size:18px; font-weight:700;">{{ (item && item.name) || title }}</div>
                        <div v-if="item && item.combination" style="font-size:13px; color:var(--text2);">{{ item.combination }}</div>
                    </div>
                    <button type="button" class="tpv-btn" aria-label="Cerrar" @click="$emit('close')"><icon name="close" :size="16" /></button>
                </div>

                <div v-if="loading" style="color:var(--text3);">Cargando…</div>
                <template v-else-if="item">
                    <div class="product-edit-field">
                        <label class="tpv-label" for="product-edit-stock">Stock (tienda y web)</label>
                        <input v-if="item.stock.editable" id="product-edit-stock" v-model="quantity" type="text" inputmode="numeric" autocomplete="off"
                            class="tpv-input product-edit-input pay-num" />
                        <div v-else class="product-edit-note">{{ item.stock.quantity }} uds · {{ item.stock.reason }}</div>
                        <div class="product-edit-note">El stock se guarda también en la web.</div>
                    </div>

                    <div v-if="item.price" class="product-edit-field">
                        <label class="tpv-label" for="product-edit-price">Precio con IVA (tienda y web)</label>
                        <div style="display:flex; gap:8px;">
                            <input id="product-edit-price" v-model="price" type="text" inputmode="decimal" autocomplete="off"
                                class="tpv-input product-edit-input pay-num" style="flex:1; min-width:0;" placeholder="0,00" />
                            <select v-model.number="vatRate" class="tpv-input product-edit-input" aria-label="IVA" style="width:110px;">
                                <option v-for="r in vatRates" :key="r" :value="r">IVA {{ r }} %</option>
                            </select>
                        </div>
                        <div v-if="item.price.vat_rate === 0" class="product-edit-note" style="color:var(--red);">Ahora este artículo no tiene IVA en la web.</div>
                        <div class="product-edit-note">El precio se guarda también en la web.{{ item.id_product_attribute ? ' El IVA es del artículo: se aplica a todas sus tallas y colores.' : '' }}</div>
                    </div>

                    <div class="product-edit-field">
                        <label class="tpv-label" for="product-edit-ean">EAN (tienda y web)</label>
                        <input id="product-edit-ean" v-model="ean13" type="text" inputmode="numeric" autocomplete="off" maxlength="13"
                            class="tpv-input product-edit-input pay-num" placeholder="Sin EAN" />
                        <div v-if="item.local && item.local.ean13" class="product-edit-note">Ahora solo en el TPV; en la web: <span class="pay-num">{{ item.synced.ean13 || '—' }}</span>. Al guardar pasa a la web.</div>
                    </div>

                    <div class="product-edit-field">
                        <label class="tpv-label" for="product-edit-reference">Referencia (tienda y web)</label>
                        <input id="product-edit-reference" v-model="reference" type="text" autocomplete="off" maxlength="64"
                            class="tpv-input product-edit-input" placeholder="Sin referencia" />
                        <div v-if="item.local && item.local.reference" class="product-edit-note">Ahora solo en el TPV; en la web: <span class="pay-num">{{ item.synced.reference || '—' }}</span>. Al guardar pasa a la web.</div>
                    </div>

                    <div v-if="!item.overrides_available" class="product-edit-note" role="status">
                        El EAN y la referencia locales necesitan actualizar la base de datos del TPV.
                    </div>
                    <div class="product-edit-note">Todo se guarda también en la web. Vaciar el EAN o la referencia los borra también allí.</div>
                </template>

                <div v-if="error" role="alert" style="color:var(--red); font-size:14px;">{{ error }}</div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="tpv-btn tpv-btn-warn" style="flex:1; min-height:52px;" :disabled="!hasLocal || saving" @click="reset">Restablecer</button>
                    <button type="button" class="tpv-btn" style="flex:1; min-height:52px;" @click="$emit('close')">Cancelar</button>
                    <button type="button" class="tpv-btn tpv-btn-primary" style="flex:2; min-height:52px; font-weight:700;" :disabled="!item || saving" @click="save">
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    `,
    };

    component.validate = validate;
    component.VAT_RATES = VAT_RATES;
    component.validEanCheckDigit = validEanCheckDigit;

    return component;
})();
