/**
 * LabelPrintModal — labels of one product from its sheet (docs/prd/agente-impresion.md L1):
 * a row per size/colour with its quantity, "Cantidad = stock", preview and
 * "Imprimir N etiquetas". LabelPrintLogic holds the rules, so tests run them without a browser.
 */
const LabelPrintLogic = {
    MAX_PER_LINE: 100,

    /** "M · Azul": the size first, like the printed label. */
    combinationText(attributes) {
        if (!attributes || attributes.length === 0) return '';
        const isSize = (a) => /talla|size|tama|n[uú]mero/i.test(a.group_name || '');
        const sizes = attributes.filter(isSize).map(a => a.attribute_name);
        const others = attributes.filter(a => !isSize(a)).map(a => a.attribute_name);
        return sizes.concat(others).filter(Boolean).join(' · ');
    },

    /** One row per combination, or the product itself when it has none. */
    rows(product) {
        if (!product) return [];
        const combos = Array.isArray(product.combinations) ? product.combinations : [];
        if (combos.length === 0) {
            return [{ key: product.id + ':0', product_id: product.id, id_product_attribute: 0, label: product.name, stock: Number(product.stock) || 0, quantity: 0 }];
        }
        return combos.map(c => ({
            key: product.id + ':' + c.id,
            product_id: product.id,
            id_product_attribute: c.id,
            label: LabelPrintLogic.combinationText(c.attributes) || ('Combinación ' + c.id),
            stock: Number(c.stock) || 0,
            quantity: 0,
        }));
    },

    clampQuantity(value) {
        const n = Math.floor(Number(value));
        if (!Number.isFinite(n) || n < 0) return 0;
        return Math.min(LabelPrintLogic.MAX_PER_LINE, n);
    },

    /** "Cantidad = stock": every row gets its stock (none below 0, at most 100). */
    quantityFromStock(rows) {
        rows.forEach(row => { row.quantity = LabelPrintLogic.clampQuantity(row.stock); });
        return rows;
    },

    total(rows) {
        return rows.reduce((sum, row) => sum + LabelPrintLogic.clampQuantity(row.quantity), 0);
    },

    items(rows) {
        return rows
            .filter(row => LabelPrintLogic.clampQuantity(row.quantity) > 0)
            .map(row => ({ product_id: row.product_id, id_product_attribute: row.id_product_attribute, quantity: LabelPrintLogic.clampQuantity(row.quantity) }));
    },

    printLabel(n) {
        return n === 1 ? 'Imprimir 1 etiqueta' : 'Imprimir ' + n + ' etiquetas';
    },

    labelsText(n) {
        return n === 1 ? '1 etiqueta' : n + ' etiquetas';
    },

    /** What the cashier is told after printing (T3: "enviadas", never "impresas" unless known). */
    statusMessage(result) {
        const n = LabelPrintLogic.labelsText(result && result.labels ? result.labels : 0);
        switch (result && result.print_status) {
            case 'queued': return { text: n + ' enviadas a la impresora', color: 'var(--green)' };
            case 'printed': return { text: n + ' enviadas a la impresora', color: 'var(--green)' };
            case 'held': return { text: 'El agente de impresión no está conectado: ' + n + ' quedan pendientes y se preguntará al volver', color: 'var(--amber)' };
            default: return { text: (result && result.error) || 'No se han podido imprimir las etiquetas', color: 'var(--red)' };
        }
    },

    printed(result) {
        return !!result && ['queued', 'printed', 'held'].includes(result.print_status);
    },

    /** The reason of an HTTP error from ApiClient ("HTTP 422: {json}"). */
    errorMessage(err) {
        const text = (err && err.message) || '';
        const json = text.indexOf('{');
        if (json >= 0) {
            try {
                const body = JSON.parse(text.slice(json));
                if (body && body.error) return body.error;
            } catch (e) { /* not JSON */ }
        }
        return text || 'Error';
    },
};

const LabelPrintModal = {
    name: 'LabelPrintModal',
    props: {
        product: { type: Object, default: null },
    },
    emits: ['close', 'notify'],
    data() {
        return {
            rows: LabelPrintLogic.rows(this.product),
            previews: [],
            errors: [],
            loadingPreview: false,
            printing: false,
            message: '',
        };
    },
    computed: {
        total() { return LabelPrintLogic.total(this.rows); },
        printLabel() { return LabelPrintLogic.printLabel(this.total); },
    },
    methods: {
        step(row, delta) { row.quantity = LabelPrintLogic.clampQuantity(row.quantity + delta); this.previews = []; },
        setQuantity(row, value) { row.quantity = LabelPrintLogic.clampQuantity(value); this.previews = []; },
        fromStock() { LabelPrintLogic.quantityFromStock(this.rows); this.previews = []; },
        async preview() {
            this.loadingPreview = true;
            this.message = '';
            try {
                const result = await ApiClient.labelPreview(LabelPrintLogic.items(this.rows));
                this.previews = result.previews || [];
                this.errors = result.errors || [];
            } catch (err) {
                this.message = LabelPrintLogic.errorMessage(err);
            } finally {
                this.loadingPreview = false;
            }
        },
        async print() {
            if (this.total === 0 || this.printing) return;
            this.printing = true;
            this.message = '';
            try {
                const result = await ApiClient.labelPrint(LabelPrintLogic.items(this.rows));
                this.errors = result.errors || [];
                const status = LabelPrintLogic.statusMessage(result);
                this.$emit('notify', status.text, status.color);
                if (LabelPrintLogic.printed(result) && this.errors.length === 0) {
                    this.$emit('close');
                }
            } catch (err) {
                this.message = LabelPrintLogic.errorMessage(err);
            } finally {
                this.printing = false;
            }
        },
        errorFor(row) {
            const e = this.errors.find(x => x.product_id === row.product_id && x.id_product_attribute === row.id_product_attribute);
            return e ? e.reason : '';
        },
    },
    template: `
        <div class="combinations-modal-backdrop" @click.self="$emit('close')">
            <div class="combinations-modal label-modal" role="dialog" aria-modal="true" aria-labelledby="label-modal-title">
                <div class="combinations-modal-header">
                    <h3 id="label-modal-title" style="margin:0; font-size:17px; color:var(--text); font-weight:700;">Etiquetas · {{ product.name }}</h3>
                    <button class="tpv-step" aria-label="Cerrar" @click="$emit('close')"><icon name="close" :size="18" /></button>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:10px;">
                    <span class="tpv-label">Talla / color</span>
                    <button class="tpv-btn" @click="fromStock">Cantidad = stock</button>
                </div>

                <div style="display:flex; flex-direction:column; gap:8px;">
                    <div v-for="row in rows" :key="row.key" class="label-row">
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:15px; font-weight:600; color:var(--text);">{{ row.label }}</div>
                            <div style="font-size:13px; color:var(--text2);">Stock: {{ row.stock }}</div>
                            <div v-if="errorFor(row)" style="font-size:13px; color:var(--red); margin-top:2px;">{{ errorFor(row) }}</div>
                        </div>
                        <div class="tpv-stepper">
                            <button class="tpv-step" aria-label="Una etiqueta menos" :disabled="row.quantity <= 0" @click="step(row, -1)"><icon name="minus" :size="20" /></button>
                            <input class="tpv-input label-qty" type="number" inputmode="numeric" min="0" max="100" :aria-label="'Etiquetas de ' + row.label"
                                :value="row.quantity" @input="setQuantity(row, $event.target.value)" />
                            <button class="tpv-step" aria-label="Una etiqueta más" :disabled="row.quantity >= 100" @click="step(row, 1)"><icon name="plus" :size="20" /></button>
                        </div>
                    </div>
                </div>

                <div v-if="message" class="tpv-banner tpv-banner-error" style="margin-top:12px;">{{ message }}</div>

                <div v-if="previews.length" class="label-previews">
                    <figure v-for="p in previews" :key="p.product_id + ':' + p.id_product_attribute" style="margin:0;">
                        <img :src="p.image" :alt="'Vista previa: ' + p.name + ' ' + (p.combination || '') + ' ' + p.price_text" class="label-preview-img" />
                        <figcaption style="font-size:13px; color:var(--text2); margin-top:4px;">× {{ p.quantity }}</figcaption>
                    </figure>
                </div>

                <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap;">
                    <button class="tpv-btn" style="flex:1;" :disabled="total === 0 || loadingPreview" @click="preview">
                        {{ loadingPreview ? 'Cargando…' : 'Vista previa' }}
                    </button>
                    <button class="tpv-btn tpv-btn-primary" style="flex:2;" :disabled="total === 0 || printing" @click="print">
                        {{ printing ? 'Imprimiendo…' : printLabel }}
                    </button>
                </div>
            </div>
        </div>
    `
};
