/**
 * CatalogPanel — category tabs + search bar + product grid/list.
 *
 * «Modo edición» (administrators only, props.isAdmin; the server checks the role
 * again): the same products, but a tap (or a size/colour in the variants window,
 * or a scan) opens ProductEditModal instead of adding to the ticket.
 */
// Scan mode: a scanned code goes straight to the ticket. Remembered per device.
const SCAN_TO_CART_KEY = 'tpv.catalog.scanToCart';

const CatalogPanel = {
    props: ['catalog', 'listView', 'lastAdded', 'isAdmin'],
    emits: ['setSearch', 'setCategory', 'addProduct', 'toggleView', 'notify', 'loadMore', 'setOnlyInStock', 'setSearchInCategory'],
    components: { CombinationsModal, LabelPrintModal, ProductEditModal },
    data() {
        return {
            combinationsProduct: null,
            showCombinationsModal: false,
            labelProduct: null,
            drag: null,
            dragged: false,
            editMode: false,
            editItem: null,
            scanToCart: (() => { try { return localStorage.getItem(SCAN_TO_CART_KEY) === '1'; } catch (e) { return false; } })(),
        };
    },
    setup(props, { emit }) {
        function stockTag(stock) {
            if (stock <= 0) return 'no-stock';
            if (stock <= 5) return 'stock-low';
            return null;
        }

        // PrestaShop sells it without VAT: its price with and without VAT are the same
        // (a product without tax rule in the shop). The ticket would say «IVA 0 %».
        function noVat(p) {
            if (!p || p.net_price === null || p.net_price === undefined) return false;
            const price = Number(p.price);
            const net = Number(p.net_price);
            return price > 0 && Math.abs(price - net) < 0.005;
        }

        return { stockTag, noVat };
    },
    watch: {
        // Only an administrator edits: a session that is not one leaves the mode.
        isAdmin(admin) {
            if (!admin) {
                this.editMode = false;
                this.editItem = null;
            }
        },
        'catalog.activeCategory'() {
            this.$nextTick(() => {
                const active = this.$refs.catBar?.querySelector('[data-active]');
                if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            });
        },
    },
    mounted() {
        document.addEventListener('keydown', this.onGlobalKey);
    },
    beforeUnmount() {
        document.removeEventListener('keydown', this.onGlobalKey);
    },
    methods: {
        async handleProductTap(p) {
            if (this.editMode && !p.has_combinations) {
                this.editItem = { productId: p.id, attributeId: 0, title: p.name };
                return;
            }
            if (p.has_combinations) {
                try {
                    const result = await ApiClient.get('/api/catalog/product/' + p.id);
                    this.combinationsProduct = result.data;
                    this.showCombinationsModal = true;
                } catch (err) {
                    console.error('Failed to load combinations', err);
                    if (this.editMode) {
                        this.$emit('notify', 'No se han podido cargar las tallas y colores', 'var(--red)');
                    } else {
                        this.$emit('addProduct', p);
                    }
                }
            } else {
                this.$emit('addProduct', p);
            }
        },
        onCombinationSelect(combo) {
            if (this.editMode) {
                const label = this.formatAttributes(combo.attributes);
                const product = this.combinationsProduct;
                this.editItem = { productId: product.id, attributeId: combo.id, title: product.name + (label ? ' · ' + label : '') };
                this.showCombinationsModal = false;
                this.combinationsProduct = null;
                return;
            }
            this.$emit('addProduct', {
                ...this.combinationsProduct,
                selected_combination_id: combo.id,
                reference: combo.reference || this.combinationsProduct.reference,
                price: this.combinationsProduct.price + combo.price_impact,
                combination_label: this.formatAttributes(combo.attributes),
            });
            this.showCombinationsModal = false;
            this.combinationsProduct = null;
        },
        // PRD L1: labels from the product sheet (its sizes/colours).
        onPrintLabels(product) {
            this.labelProduct = product;
            this.showCombinationsModal = false;
            this.combinationsProduct = null;
        },
        // «Editar producto» in the variants window: the product's own EAN and reference.
        onEditProduct() {
            const product = this.combinationsProduct;
            if (!this.editMode || !product) return;
            this.editItem = { productId: product.id, attributeId: 0, title: product.name };
            this.showCombinationsModal = false;
            this.combinationsProduct = null;
        },
        toggleEditMode() {
            if (!this.isAdmin) {
                this.editMode = false;
                return;
            }
            this.editMode = !this.editMode;
            if (!this.editMode) this.editItem = null;
        },
        // After a save the grid shows the product as the till now sells it (stock,
        // local EAN and reference), without reloading the list.
        async onEditSaved(item) {
            const id = item && item.id_product;
            if (!id) return;
            try {
                const res = await ApiClient.get('/api/catalog/product/' + id);
                const fresh = res.data || {};
                const shown = (this.catalog.products || []).find((p) => p.id === id);
                if (shown) {
                    shown.stock = fresh.stock;
                    shown.ean13 = fresh.ean13;
                    shown.reference = fresh.reference;
                }
            } catch (e) {
                // The next search or sync shows it.
            }
        },
        toggleScanToCart() {
            this.scanToCart = !this.scanToCart;
            try { localStorage.setItem(SCAN_TO_CART_KEY, this.scanToCart ? '1' : '0'); } catch (e) { /* not remembered */ }
        },
        // Scan mode on: the scanner types the code and Enter. The product whose EAN
        // or reference is exactly that code (or the only product found, for the EAN
        // of a size) goes straight to the ticket; a product with variants opens the
        // variants window, as when it is tapped. A partial or unknown code adds
        // nothing and keeps the text so the cashier sees what was read.
        async onSearchEnter() {
            if (!this.scanToCart) return;
            const code = String(this.catalog.search || '').trim();
            if (code === '') return;
            let found = [];
            try {
                const res = await ApiClient.get('/api/catalog/search?' + new URLSearchParams({ q: code, limit: '5' }).toString());
                found = res.data || [];
            } catch (e) {
                found = [];
            }
            const product = found.find((p) => p.ean13 === code || p.reference === code)
                || (found.length === 1 && !this.isPartialMatch(found[0], code) ? found[0] : null);
            if (!product) {
                this.$emit('notify', 'No hay ningún producto con el código ' + code, 'var(--red)');
                return;
            }
            await this.handleProductTap(product);
            this.$emit('setSearch', '');
            const input = this.$refs.searchInput;
            if (input) input.focus();
        },
        // The only product found for a code it merely contains in its own EAN or
        // reference (a code cut short): not a scan of that product.
        isPartialMatch(product, code) {
            return [product.ean13, product.reference].some((value) => value && value !== code && value.includes(code));
        },
        // The search box's clear button: empties the search and keeps the box ready
        // for the next search or scan.
        clearSearch() {
            this.$emit('setSearch', '');
            const input = this.$refs.searchInput;
            if (input) input.focus();
        },
        // On the sale screen, typing or scanning with no field focused goes to the
        // search box, replacing the previous search. Not while a window covers it
        // (payment, customer…) nor when another field has the focus.
        onGlobalKey(e) {
            if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
            const active = document.activeElement;
            if (active && (active.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName))) return;
            const input = this.$refs.searchInput;
            if (!input || input.offsetParent === null) return;
            const box = input.getBoundingClientRect();
            const top = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            if (!top || !input.parentElement.contains(top)) return;
            input.focus();
            input.select();
        },
        // Mouse drag scrolls the category bar (x) and the product list (y), like a
        // finger does on a touch screen (touch scrolls natively). A drag never
        // clicks the tab or product it ends on.
        onDragDown(e, refName, axis) {
            this.dragged = false;
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            const el = this.$refs[refName];
            this.drag = { el, axis, start: axis === 'x' ? e.clientX : e.clientY, scroll: axis === 'x' ? el.scrollLeft : el.scrollTop, id: e.pointerId };
        },
        onDragMove(e) {
            const d = this.drag;
            if (!d) return;
            const delta = (d.axis === 'x' ? e.clientX : e.clientY) - d.start;
            if (!this.dragged && Math.abs(delta) > 5) {
                this.dragged = true;
                d.el.setPointerCapture(d.id);
                d.el.classList.add('dragging');
            }
            if (!this.dragged) return;
            if (d.axis === 'x') d.el.scrollLeft = d.scroll - delta;
            else d.el.scrollTop = d.scroll - delta;
        },
        onDragUp() {
            if (!this.drag) return;
            this.drag.el.classList.remove('dragging');
            this.drag = null;
        },
        onDragClickCapture(e) {
            if (this.dragged) {
                e.stopPropagation();
                e.preventDefault();
                this.dragged = false;
            }
        },
        onCatWheel(e) {
            const bar = this.$refs.catBar;
            if (bar.scrollWidth <= bar.clientWidth || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;
            e.preventDefault();
            bar.scrollLeft += e.deltaY;
        },
        // The arrows move to the first category hidden on that side.
        stepCategories(dir) {
            const bar = this.$refs.catBar;
            const tabs = [...bar.querySelectorAll('[data-cat]')];
            const view = bar.getBoundingClientRect();
            const target = dir > 0
                ? tabs.find((t) => t.getBoundingClientRect().right > view.right + 1)
                : [...tabs].reverse().find((t) => t.getBoundingClientRect().left < view.left - 1);
            if (target) target.scrollIntoView({ behavior: 'smooth', inline: dir > 0 ? 'start' : 'end', block: 'nearest' });
        },
        // Near the end of the list, ask for the next 50 products.
        onProductsScroll(event) {
            const el = event.currentTarget;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 300) {
                this.$emit('loadMore');
            }
        },
        onLabelNotify(text, color) {
            this.$emit('notify', text, color);
        },
        formatAttributes(attributes) {
            if (!attributes || attributes.length === 0) return '';
            return attributes.map(a => a.group_name + ': ' + a.attribute_name).join(' / ');
        },
    },
    template: `
        <div style="display:flex; flex-direction:column; height:100%; overflow:hidden;" :class="{ 'catalog-edit-mode': editMode }">
            <!-- Combinations Modal -->
            <combinations-modal
                v-if="showCombinationsModal && combinationsProduct"
                :product="combinationsProduct"
                :visible="showCombinationsModal"
                :edit-mode="editMode"
                @close="showCombinationsModal = false; combinationsProduct = null;"
                @select="onCombinationSelect"
                @edit-product="onEditProduct"
                @print-labels="onPrintLabels"
            />
            <product-edit-modal
                v-if="editItem"
                :key="editItem.productId + ':' + editItem.attributeId"
                :product-id="editItem.productId"
                :attribute-id="editItem.attributeId"
                :title="editItem.title"
                @close="editItem = null"
                @saved="onEditSaved"
                @notify="onLabelNotify"
            />
            <label-print-modal
                v-if="labelProduct"
                :product="labelProduct"
                @close="labelProduct = null"
                @notify="onLabelNotify"
            />

            <!-- Search bar -->
            <div style="padding:12px 16px; border-bottom:1px solid var(--border); display:flex; gap:10px;">
                <div style="flex:1; position:relative;">
                    <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text3); pointer-events:none;">
                        <icon name="search" :size="16" />
                    </span>
                    <input ref="searchInput"
                        :value="catalog.search"
                        @input="$emit('setSearch', $event.target.value)"
                        @keydown.enter.prevent="onSearchEnter"
                        placeholder="Buscar por nombre, SKU, EAN…"
                        style="width:100%; background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:9px 48px 9px 38px; color:var(--text); font-family:inherit; font-size:14px; outline:none;"
                    />
                    <!-- Clears the search with one tap (touch screens have no quick select-all). -->
                    <button v-if="catalog.search" type="button" class="catalog-search-clear" @click="clearSearch"
                        title="Borrar búsqueda" aria-label="Borrar búsqueda"
                        style="position:absolute; right:2px; top:50%; transform:translateY(-50%); width:40px; height:36px; border:none; border-radius:8px; background:transparent; color:var(--text3); cursor:pointer; display:flex; align-items:center; justify-content:center; touch-action:manipulation;">
                        <icon name="close" :size="18" />
                    </button>
                </div>
                <button v-if="isAdmin" @click="toggleEditMode" :aria-pressed="editMode ? 'true' : 'false'"
                    class="catalog-edit-toggle" :class="{ 'is-on': editMode }"
                    :title="editMode ? 'Modo edición activo: pulsa para volver a vender' : 'Modo edición: cambiar stock, EAN o referencia'"
                    aria-label="Modo edición">
                    <icon name="note" :size="17" />
                </button>
                <button @click="toggleScanToCart" :aria-pressed="scanToCart ? 'true' : 'false'"
                    :title="scanToCart ? 'Escanear y añadir: lo escaneado entra directo en el ticket (pulsa para solo buscar)' : 'Escanear y añadir al ticket'"
                    aria-label="Escanear y añadir al ticket"
                    style="width:40px; height:40px; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0;"
                    :style="{ border: scanToCart ? '1px solid var(--green)' : '1px solid var(--border)', background: scanToCart ? 'var(--green-bg)' : 'var(--surface2)', color: scanToCart ? 'var(--green)' : 'var(--text2)' }">
                    <icon name="barcode" :size="17" />
                </button>
                <button @click="$emit('setSearchInCategory', !catalog.searchInCategory)" :aria-pressed="catalog.searchInCategory ? 'true' : 'false'"
                    :title="catalog.searchInCategory ? 'Buscando solo en la categoría elegida (pulsa para buscar en todo)' : 'Buscar solo en la categoría elegida'"
                    aria-label="Buscar solo en la categoría elegida"
                    style="width:40px; height:40px; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0;"
                    :style="{ border: catalog.searchInCategory ? '1px solid var(--green)' : '1px solid var(--border)', background: catalog.searchInCategory ? 'var(--green-bg)' : 'var(--surface2)', color: catalog.searchInCategory ? 'var(--green)' : 'var(--text2)' }">
                    <icon name="tag" :size="17" />
                </button>
                <button @click="$emit('setOnlyInStock', !catalog.onlyInStock)" :aria-pressed="catalog.onlyInStock ? 'true' : 'false'"
                    :title="catalog.onlyInStock ? 'Solo artículos con stock (pulsa para ver todos)' : 'Solo artículos con stock'"
                    :aria-label="'Solo artículos con stock'"
                    style="width:40px; height:40px; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0;"
                    :style="{ border: catalog.onlyInStock ? '1px solid var(--green)' : '1px solid var(--border)', background: catalog.onlyInStock ? 'var(--green-bg)' : 'var(--surface2)', color: catalog.onlyInStock ? 'var(--green)' : 'var(--text2)' }">
                    <icon name="box" :size="17" />
                </button>
                <button @click="$emit('toggleView')" style="width:40px; height:40px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <icon :name="listView ? 'grid' : 'list'" :size="16" />
                </button>
            </div>

            <div v-if="editMode" class="catalog-edit-banner" role="status">
                <span><strong>Modo edición</strong> · pulsa un artículo para cambiar su stock, EAN o referencia. No se añade nada al ticket.</span>
                <button type="button" class="tpv-btn" @click="toggleEditMode">Salir</button>
            </div>

            <!-- Category tabs: scrollbar, mouse drag, wheel and arrows -->
            <div style="display:flex; align-items:stretch; border-bottom:1px solid var(--border); flex-shrink:0;">
                <button class="cat-arrow" @click="stepCategories(-1)" aria-label="Categorías anteriores">‹</button>
                <div ref="catBar" class="cat-tabs"
                    @pointerdown="onDragDown($event, 'catBar', 'x')" @pointermove="onDragMove" @pointerup="onDragUp" @pointercancel="onDragUp"
                    @click.capture="onDragClickCapture" @wheel="onCatWheel">
                    <button data-cat
                        @click="$emit('setCategory', null)"
                        style="padding:10px 14px; background:transparent; border:none; cursor:pointer; font-family:inherit; font-size:13px; white-space:nowrap; transition:all .12s; flex-shrink:0;"
                        :style="{ fontWeight: catalog.activeCategory === null ? 600 : 400, color: catalog.activeCategory === null ? 'var(--text)' : 'var(--text3)', borderBottom: catalog.activeCategory === null ? '2px solid var(--green)' : '2px solid transparent' }">
                        Todo
                    </button>
                    <button v-for="cat in catalog.categories" :key="cat.id" data-cat :data-active="catalog.activeCategory === cat.id ? 1 : null"
                        @click="$emit('setCategory', cat.id)"
                        style="padding:10px 14px; background:transparent; border:none; cursor:pointer; font-family:inherit; font-size:13px; white-space:nowrap; transition:all .12s; flex-shrink:0;"
                        :style="{ fontWeight: catalog.activeCategory === cat.id ? 600 : 400, color: catalog.activeCategory === cat.id ? 'var(--text)' : 'var(--text3)', borderBottom: catalog.activeCategory === cat.id ? '2px solid var(--green)' : '2px solid transparent' }">
                        {{ cat.name }}
                    </button>
                </div>
                <button class="cat-arrow" @click="stepCategories(1)" aria-label="Categorías siguientes">›</button>
            </div>

            <!-- Products -->
            <div ref="productList" class="product-list" @scroll="onProductsScroll"
                @pointerdown="onDragDown($event, 'productList', 'y')" @pointermove="onDragMove" @pointerup="onDragUp" @pointercancel="onDragUp"
                @click.capture="onDragClickCapture" @dragstart.prevent
                style="flex:1; overflow-y:auto; padding:12px; -webkit-overflow-scrolling:touch; touch-action:pan-y; min-height:0;">
                <div v-if="catalog.products.length === 0 && !catalog.loading" style="text-align:center; color:var(--text3); margin-top:48px; font-size:14px;">
                    Sin resultados
                </div>
                <div v-if="catalog.loading" style="text-align:center; color:var(--text3); margin-top:48px; font-size:14px;">
                    Cargando…
                </div>

                <!-- Grid view -->
                <div v-if="!listView" style="display:grid; grid-template-columns:repeat(5, 1fr); gap:8px;">
                    <button v-for="p in catalog.products" :key="p.id"
                        @click="handleProductTap(p)"
                        class="product-card"
                        :class="{ 'no-stock': stockTag(p.stock) === 'no-stock', 'animate-pulse-green': lastAdded === p.id }"
                    >
                        <div style="width:100%; aspect-ratio:1/1; border-radius:8px; background:var(--surface2); display:flex; align-items:center; justify-content:center; font-size:10px; color:var(--text3); letter-spacing:0.05em; font-family:'IBM Plex Mono'; border:1px dashed var(--border); overflow:hidden;">
                            <template v-if="p.image_url"><img draggable="false" :src="p.image_url" :alt="p.name" style="width:100%;height:100%;object-fit:cover;border-radius:6px 6px 0 0;" @error="$event.target.parentElement.textContent='IMG'" /></template>
                            <template v-else>IMG</template>
                        </div>
                        <div style="font-size:12px; font-weight:600; color:var(--text); line-height:1.3; text-wrap:pretty;">{{ p.name }}</div>
                        <div v-if="p.reference" style="font-size:10px; color:var(--text3); font-family:'IBM Plex Mono';">{{ p.reference }}</div>
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:auto;">
                            <span style="font-family:'IBM Plex Mono'; font-size:13px; font-weight:600; color:var(--text);">€{{ Number(p.price).toFixed(2) }}</span>
                            <span v-if="noVat(p)" title="Este producto no tiene IVA en PrestaShop" style="font-size:9px; background:var(--red-bg); color:var(--red); padding:2px 6px; border-radius:4px; font-weight:700;">SIN IVA</span>
                            <span v-if="p.has_combinations" style="font-size:9px; background:var(--surface3); color:var(--text3); padding:2px 6px; border-radius:4px; font-weight:600;">VARS</span>
                            <span v-else-if="stockTag(p.stock) === 'stock-low'" style="font-size:9px; background:var(--amber-bg); color:var(--amber); padding:2px 6px; border-radius:4px; font-weight:600;">BAJO</span>
                            <span v-else-if="stockTag(p.stock) === 'no-stock'" style="font-size:9px; background:var(--red-bg); color:var(--red); padding:2px 6px; border-radius:4px; font-weight:600;">AGOTADO</span>
                        </div>
                    </button>
                </div>

                <!-- List view -->
                <div v-else style="display:flex; flex-direction:column; gap:4px;">
                    <button v-for="p in catalog.products" :key="p.id"
                        @click="handleProductTap(p)"
                        style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:14px; text-align:left; font-family:inherit; transition:all .1s;"
                        :style="{ cursor: stockTag(p.stock) === 'no-stock' ? 'not-allowed' : 'pointer', opacity: stockTag(p.stock) === 'no-stock' ? 0.45 : 1, background: lastAdded === p.id ? 'var(--green-bg)' : 'var(--surface)', borderColor: lastAdded === p.id ? 'var(--green-dim)' : 'var(--border)' }"
                        @mouseover="!$event.currentTarget.classList.contains('no-stock') && ($event.currentTarget.style.background='var(--surface2)')"
                        @mouseout="!$event.currentTarget.classList.contains('no-stock') && ($event.currentTarget.style.background='var(--surface)')"
                    >
                        <div style="width:36px; height:36px; border-radius:6px; background:var(--surface2); border:1px dashed var(--border); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:9px; color:var(--text3); font-family:'IBM Plex Mono'; overflow:hidden;">
                            <template v-if="p.image_url"><img draggable="false" :src="p.image_url" :alt="p.name" style="width:100%;height:100%;object-fit:cover;border-radius:6px;" @error="$event.target.parentElement.textContent='IMG'" /></template>
                            <template v-else>IMG</template>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:13px; font-weight:500; color:var(--text);">{{ p.name }}</div>
                            <div v-if="p.reference" style="font-size:11px; color:var(--text3); font-family:'IBM Plex Mono'; margin-top:2px;">{{ p.reference }}</div>
                        </div>
                        <span v-if="noVat(p)" title="Este producto no tiene IVA en PrestaShop" style="font-size:10px; background:var(--red-bg); color:var(--red); padding:2px 7px; border-radius:4px; font-weight:700; flex-shrink:0;">SIN IVA</span>
                        <span v-if="p.has_combinations" style="font-size:10px; background:var(--surface3); color:var(--text3); padding:2px 7px; border-radius:4px; font-weight:600; flex-shrink:0;">VARS</span>
                        <span v-else-if="stockTag(p.stock) === 'stock-low'" style="font-size:10px; background:var(--amber-bg); color:var(--amber); padding:2px 7px; border-radius:4px; font-weight:600; flex-shrink:0;">BAJO</span>
                        <span v-else-if="stockTag(p.stock) === 'no-stock'" style="font-size:10px; background:var(--red-bg); color:var(--red); padding:2px 7px; border-radius:4px; font-weight:600; flex-shrink:0;">AGOTADO</span>
                        <span style="font-family:'IBM Plex Mono'; font-size:14px; font-weight:600; color:var(--text); flex-shrink:0;">€{{ Number(p.price).toFixed(2) }}</span>
                    </button>
                </div>

                <div v-if="catalog.hasMore && !catalog.loading" style="text-align:center; margin:14px 0 4px;">
                    <button @click="$emit('loadMore')" :disabled="catalog.loadingMore"
                        style="padding:10px 18px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text2); font-family:inherit; font-size:13px; cursor:pointer;">
                        {{ catalog.loadingMore ? 'Cargando…' : 'Ver más productos' }}
                    </button>
                </div>
            </div>
        </div>
    `
};
