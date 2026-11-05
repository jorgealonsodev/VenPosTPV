/**
 * LabelsScreen — the "Etiquetas" screen (docs/prd/agente-impresion.md L2, AC15): add
 * products by scanning or searching, a quantity per line, preview and print all. The
 * list is kept (this computer's localStorage) until it is printed, then emptied.
 *
 * LabelPendingPrompt — L9: when the till's print agent is back with labels held while
 * it was away, "Hay N etiquetas pendientes. ¿Imprimirlas?" (Imprimir / Descartar).
 */
const LabelList = (function () {
    const STORAGE_KEY = 'tpv_label_list_v1';

    function load() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const lines = raw ? JSON.parse(raw) : [];
            return Array.isArray(lines) ? lines.filter(l => l && l.product_id > 0) : [];
        } catch (e) {
            return [];
        }
    }

    const state = Vue.reactive({ lines: load() });

    function save() {
        try {
            if (state.lines.length === 0) localStorage.removeItem(STORAGE_KEY);
            else localStorage.setItem(STORAGE_KEY, JSON.stringify(state.lines));
        } catch (e) {
            // Private mode or full storage: the list still works until the page reloads.
        }
    }

    function key(entry) { return entry.product_id + ':' + (entry.id_product_attribute || 0); }

    /** Adds a product (or one more of it, when it is already on the list). */
    function add(entry, quantity = 1) {
        const k = key(entry);
        const line = state.lines.find(l => l.key === k);
        if (line) {
            line.quantity = LabelPrintLogic.clampQuantity(line.quantity + quantity);
        } else {
            state.lines.push({
                key: k,
                product_id: entry.product_id,
                id_product_attribute: entry.id_product_attribute || 0,
                name: entry.name || '',
                combination: entry.combination || '',
                image: typeof entry.image === 'string' ? entry.image : '',
                stock: Number(entry.stock) || 0,
                quantity: LabelPrintLogic.clampQuantity(quantity),
            });
        }
        save();
    }

    function setQuantity(k, quantity) {
        const line = state.lines.find(l => l.key === k);
        if (line) { line.quantity = LabelPrintLogic.clampQuantity(quantity); save(); }
    }

    function remove(k) {
        const i = state.lines.findIndex(l => l.key === k);
        if (i >= 0) { state.lines.splice(i, 1); save(); }
    }

    function clear() { state.lines.splice(0, state.lines.length); save(); }

    function items() { return LabelPrintLogic.items(state.lines); }

    function total() { return LabelPrintLogic.total(state.lines); }

    /** AC15: once printed (sent, or held for the agent) the list is emptied. */
    function afterPrint(result) {
        if (LabelPrintLogic.printed(result)) clear();
    }

    return { state, add, setQuantity, remove, clear, items, total, afterPrint, reload: () => { state.lines.splice(0, state.lines.length, ...load()); } };
})();

const LabelPendingLogic = {
    POLL_MS: 30000,
    shouldAsk(response) {
        return !!response && response.ask === true && Number(response.labels) > 0;
    },
};

const LabelsScreen = {
    name: 'LabelsScreen',
    emits: ['back', 'notify'],
    setup(props, { emit }) {
        const list = LabelList.state;
        const query = Vue.ref('');
        const results = Vue.ref([]);
        const choosing = Vue.ref(null);
        const previews = Vue.ref([]);
        const errors = Vue.ref([]);
        const message = Vue.ref('');
        const busy = Vue.ref(false);
        const printing = Vue.ref(false);
        const total = Vue.computed(() => LabelPrintLogic.total(list.lines));
        const printLabel = Vue.computed(() => LabelPrintLogic.printLabel(total.value));

        function notify(text, color) { emit('notify', text, color); }

        function added(entry) {
            LabelList.add(entry, 1);
            previews.value = [];
            results.value = [];
            choosing.value = null;
            query.value = '';
        }

        /** A scanned or typed code: the exact EAN or reference; else a catalog search. */
        async function addByCode(code) {
            const text = String(code || '').trim();
            if (!text) return;
            message.value = '';
            busy.value = true;
            try {
                const found = await ApiClient.labelLookup(text);
                added({ ...found, image: found.image_url || '' });
                notify('Añadido: ' + found.name + (found.combination ? ' · ' + found.combination : ''), 'var(--green)');
            } catch (err) {
                await search(text);
            } finally {
                busy.value = false;
            }
        }

        async function search(text) {
            try {
                const response = await ApiClient.searchCatalog(text);
                results.value = (response && response.data) || [];
                if (results.value.length === 0) message.value = 'No se ha encontrado «' + text + '»';
            } catch (err) {
                message.value = LabelPrintLogic.errorMessage(err);
            }
        }

        async function pick(product) {
            if (!product.has_combinations) {
                added({ product_id: product.id, id_product_attribute: 0, name: product.name, stock: product.stock, image: product.image_url || '' });
                return;
            }
            try {
                const response = await ApiClient.catalogProduct(product.id);
                choosing.value = response.data;
            } catch (err) {
                message.value = LabelPrintLogic.errorMessage(err);
            }
        }

        function pickCombination(combo) {
            const product = choosing.value;
            added({ product_id: product.id, id_product_attribute: combo.id, name: product.name, combination: LabelPrintLogic.combinationText(combo.attributes), stock: combo.stock, image: product.image_url || '' });
        }

        function step(line, delta) { LabelList.setQuantity(line.key, line.quantity + delta); previews.value = []; }
        function setQuantity(line, value) { LabelList.setQuantity(line.key, value); previews.value = []; }
        function remove(line) { LabelList.remove(line.key); previews.value = []; }

        async function preview() {
            busy.value = true;
            message.value = '';
            try {
                const result = await ApiClient.labelPreview(LabelList.items());
                previews.value = result.previews || [];
                errors.value = result.errors || [];
            } catch (err) {
                message.value = LabelPrintLogic.errorMessage(err);
            } finally {
                busy.value = false;
            }
        }

        async function print() {
            if (total.value === 0 || printing.value) return;
            printing.value = true;
            message.value = '';
            try {
                const result = await ApiClient.labelPrint(LabelList.items());
                errors.value = result.errors || [];
                const status = LabelPrintLogic.statusMessage(result);
                notify(status.text, status.color);
                if (LabelPrintLogic.printed(result) && errors.value.length === 0) {
                    LabelList.afterPrint(result);
                    previews.value = [];
                } else if (!LabelPrintLogic.printed(result)) {
                    message.value = status.text;
                }
            } catch (err) {
                message.value = LabelPrintLogic.errorMessage(err);
            } finally {
                printing.value = false;
            }
        }

        function errorFor(line) {
            const e = errors.value.find(x => x.product_id === line.product_id && x.id_product_attribute === line.id_product_attribute);
            return e ? e.reason : '';
        }

        const scanner = typeof useBarcode !== 'undefined' ? useBarcode : null;
        if (scanner && typeof Vue.onMounted === 'function') {
            Vue.onMounted(() => scanner.onScan(addByCode));
        }
        if (scanner && typeof Vue.onUnmounted === 'function') {
            Vue.onUnmounted(() => scanner.offScan());
        }

        return {
            list, query, results, choosing, previews, errors, message, busy, printing, total, printLabel,
            addByCode, pick, pickCombination, step, setQuantity, remove, preview, print, errorFor,
            combinationText: LabelPrintLogic.combinationText,
        };
    },
    template: `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; background:var(--bg); overflow:hidden;">
            <header style="height:60px; min-height:60px; background:var(--surface); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; gap:14px;">
                <button class="tpv-btn" @click="$emit('back')"><icon name="return" :size="16" /> Volver</button>
                <h2 style="margin:0; font-size:17px; font-weight:700; color:var(--text);">Etiquetas</h2>
                <span style="margin-left:auto; color:var(--text2); font-size:14px;">{{ total === 1 ? '1 etiqueta' : total + ' etiquetas' }}</span>
            </header>

            <div class="tpv-split">
                <div class="tpv-split-main">
                    <form @submit.prevent="addByCode(query)" style="display:flex; gap:10px;">
                        <input class="tpv-input" style="flex:1;" v-model="query" placeholder="Escanea o busca por nombre, referencia o EAN"
                            aria-label="Escanea o busca un producto" autocomplete="off" />
                        <button class="tpv-btn" type="submit" :disabled="busy || !query.trim()"><icon name="search" :size="16" /> Añadir</button>
                    </form>

                    <div v-if="message" class="tpv-banner tpv-banner-error">{{ message }}</div>

                    <div v-if="choosing" style="display:flex; flex-direction:column; gap:8px;">
                        <div class="tpv-label">{{ choosing.name }}: elige talla / color</div>
                        <button v-for="combo in choosing.combinations" :key="combo.id" class="tpv-row" @click="pickCombination(combo)">
                            <span style="flex:1; font-weight:600; color:var(--text);">{{ combinationText(combo.attributes) || ('Combinación ' + combo.id) }}</span>
                            <span style="color:var(--text2); font-size:13px;">Stock: {{ combo.stock }}</span>
                        </button>
                        <button class="tpv-btn" @click="choosing = null">Cancelar</button>
                    </div>
                    <div v-else-if="results.length" style="display:flex; flex-direction:column; gap:8px;">
                        <button v-for="p in results" :key="p.id" class="tpv-row" @click="pick(p)">
                            <span class="label-row-img">
                                <img v-if="p.image_url" :src="p.image_url" :alt="p.name" loading="lazy" @error="$event.target.remove()" />
                            </span>
                            <span style="flex:1; min-width:0; text-align:left;">
                                <span style="display:block; font-weight:600; color:var(--text);">{{ p.name }}</span>
                                <span v-if="p.reference" style="display:block; font-size:13px; color:var(--text2); font-family:'IBM Plex Mono';">{{ p.reference }}</span>
                            </span>
                            <span v-if="p.has_combinations" class="tpv-tag">Tallas</span>
                        </button>
                    </div>

                    <div v-if="list.lines.length === 0 && !results.length && !choosing" style="text-align:center; color:var(--text2); margin-top:48px; font-size:15px;">
                        Escanea un producto o búscalo para añadir sus etiquetas.
                    </div>

                    <div v-for="line in list.lines" :key="line.key" class="label-row">
                        <div class="label-row-img">
                            <img v-if="line.image" :src="line.image" :alt="line.name" loading="lazy" @error="$event.target.remove()" />
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:15px; font-weight:600; color:var(--text);">{{ line.name }}</div>
                            <div v-if="line.combination" style="font-size:13px; color:var(--text2);">{{ line.combination }}</div>
                            <div v-if="errorFor(line)" style="font-size:13px; color:var(--red); margin-top:2px;">{{ errorFor(line) }}</div>
                        </div>
                        <div class="tpv-stepper">
                            <button class="tpv-step" aria-label="Una etiqueta menos" :disabled="line.quantity <= 0" @click="step(line, -1)"><icon name="minus" :size="20" /></button>
                            <input class="tpv-input label-qty" type="number" inputmode="numeric" min="0" max="100" :aria-label="'Etiquetas de ' + line.name"
                                :value="line.quantity" @input="setQuantity(line, $event.target.value)" />
                            <button class="tpv-step" aria-label="Una etiqueta más" :disabled="line.quantity >= 100" @click="step(line, 1)"><icon name="plus" :size="20" /></button>
                            <button class="tpv-step" aria-label="Quitar de la lista" @click="remove(line)"><icon name="trash" :size="18" /></button>
                        </div>
                    </div>
                </div>

                <div class="tpv-split-side">
                    <div class="tpv-split-side-body">
                        <div class="tpv-label">Vista previa</div>
                        <div v-if="!previews.length" style="color:var(--text2); font-size:14px;">Pulsa «Vista previa» para ver las etiquetas.</div>
                        <figure v-for="p in previews" :key="p.product_id + ':' + p.id_product_attribute" style="margin:0;">
                            <img :src="p.image" :alt="'Vista previa: ' + p.name + ' ' + (p.combination || '') + ' ' + p.price_text" class="label-preview-img" />
                            <figcaption style="font-size:13px; color:var(--text2); margin-top:4px;">× {{ p.quantity }}</figcaption>
                        </figure>
                    </div>
                    <div style="padding:16px; display:flex; flex-direction:column; gap:10px; border-top:1px solid var(--border);">
                        <button class="tpv-btn" :disabled="total === 0 || busy" @click="preview">Vista previa</button>
                        <button class="tpv-action tpv-action-primary" style="justify-content:center;" :disabled="total === 0 || printing" @click="print">
                            {{ printing ? 'Imprimiendo…' : printLabel }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};

const LabelPendingPrompt = {
    name: 'LabelPendingPrompt',
    props: { terminalId: { type: [Number, String], default: null } },
    emits: ['notify'],
    setup(props, { emit }) {
        const pending = Vue.ref(null);
        const answering = Vue.ref(false);
        let timer = null;

        async function check() {
            if (!props.terminalId || answering.value) return;
            try {
                const response = await ApiClient.labelPending();
                pending.value = LabelPendingLogic.shouldAsk(response) ? response : null;
            } catch (e) {
                // Offline or an older TPV: ask again on the next check.
            }
        }

        async function answer(print) {
            answering.value = true;
            try {
                const result = print ? await ApiClient.labelPendingPrint() : await ApiClient.labelPendingDiscard();
                const n = LabelPrintLogic.labelsText(result.labels || 0);
                emit('notify', print ? n + ' enviadas a la impresora' : n + ' descartadas', print ? 'var(--green)' : 'var(--amber)');
                pending.value = null;
            } catch (err) {
                emit('notify', LabelPrintLogic.errorMessage(err), 'var(--red)');
            } finally {
                answering.value = false;
            }
        }

        Vue.onMounted(() => { check(); timer = setInterval(check, LabelPendingLogic.POLL_MS); });
        Vue.onUnmounted(() => { if (timer) clearInterval(timer); });

        return { pending, answering, answer };
    },
    template: `
        <div v-if="pending" class="combinations-modal-backdrop">
            <div class="combinations-modal" role="alertdialog" aria-modal="true" aria-labelledby="label-pending-text" style="max-width:440px;">
                <p id="label-pending-text" style="margin:0 0 16px; font-size:17px; font-weight:600; color:var(--text);">{{ pending.message }}</p>
                <div style="display:flex; gap:10px;">
                    <button class="tpv-btn" style="flex:1;" :disabled="answering" @click="answer(false)">Descartar</button>
                    <button class="tpv-btn tpv-btn-primary" style="flex:1;" :disabled="answering" @click="answer(true)">Imprimir</button>
                </div>
            </div>
        </div>
    `
};
