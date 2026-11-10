/**
 * ProductEditModal — «Modo edición» of the sale screen (administrators): the stock,
 * EAN and reference of a product or of one of its sizes/colours.
 *
 * - Stock is shared with the web shop: the new quantity is written to PrestaShop
 *   first and then to the till (PUT /api/catalog/edit/{id}/{attr}/stock).
 * - EAN and reference stay at the till only (PUT …/override); the sync never
 *   overwrites them. «Restablecer» removes them (DELETE …/override).
 * The values the web has (synced) are shown next to the local ones. The checks
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
        if (ean !== '') {
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
        return { ean13: ean === '' ? null : ean, reference: reference === '' ? null : reference, quantity };
    }

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
            return { item: null, loading: true, saving: false, error: '', quantity: '', ean13: '', reference: '' };
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
                this.ean13 = (item.local && item.local.ean13) || '';
                this.reference = (item.local && item.local.reference) || '';
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
                const checked = validate({ ean13: this.ean13, reference: this.reference, quantity: this.quantity, stockEditable: this.item.stock.editable });
                if (checked.error) {
                    this.error = checked.error;
                    return;
                }
                this.error = '';
                this.saving = true;
                let item = this.item;
                try {
                    const local = item.local || {};
                    if (checked.ean13 !== (local.ean13 || null) || checked.reference !== (local.reference || null)) {
                        item = (await ApiClient.catalogSaveOverride(this.productId, this.attributeId, { ean13: checked.ean13, reference: checked.reference })).data;
                    }
                    if (checked.quantity !== null && checked.quantity !== item.stock.quantity) {
                        item = (await ApiClient.catalogSetStock(this.productId, this.attributeId, checked.quantity)).data;
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

                    <div class="product-edit-field">
                        <label class="tpv-label" for="product-edit-ean">EAN (solo en el TPV)</label>
                        <input id="product-edit-ean" v-model="ean13" type="text" inputmode="numeric" autocomplete="off" maxlength="13"
                            class="tpv-input product-edit-input pay-num" :placeholder="item.synced.ean13 || 'Sin EAN'" />
                        <div class="product-edit-note">En la web: <span class="pay-num">{{ item.synced.ean13 || '—' }}</span></div>
                    </div>

                    <div class="product-edit-field">
                        <label class="tpv-label" for="product-edit-reference">Referencia (solo en el TPV)</label>
                        <input id="product-edit-reference" v-model="reference" type="text" autocomplete="off" maxlength="64"
                            class="tpv-input product-edit-input" :placeholder="item.synced.reference || 'Sin referencia'" />
                        <div class="product-edit-note">En la web: <span class="pay-num">{{ item.synced.reference || '—' }}</span></div>
                    </div>

                    <div v-if="!item.overrides_available" class="product-edit-note" role="status">
                        El EAN y la referencia locales necesitan actualizar la base de datos del TPV.
                    </div>
                    <div class="product-edit-note">Vacío = se usa el valor de la web. El precio siempre es el de la web.</div>
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
    component.validEanCheckDigit = validEanCheckDigit;

    return component;
})();
