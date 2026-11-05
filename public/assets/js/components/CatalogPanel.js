/**
 * CatalogPanel — category tabs + search bar + product grid/list.
 */
const CatalogPanel = {
    props: ['catalog', 'listView', 'lastAdded'],
    emits: ['setSearch', 'setCategory', 'addProduct', 'toggleView', 'notify', 'loadMore', 'setOnlyInStock'],
    components: { CombinationsModal, LabelPrintModal },
    data() {
        return {
            combinationsProduct: null,
            showCombinationsModal: false,
            labelProduct: null,
            catDrag: null,
            catDragged: false,
        };
    },
    setup(props, { emit }) {
        function stockTag(stock) {
            if (stock <= 0) return 'no-stock';
            if (stock <= 5) return 'stock-low';
            return null;
        }

        return { stockTag };
    },
    watch: {
        'catalog.activeCategory'() {
            this.$nextTick(() => {
                const active = this.$refs.catBar?.querySelector('[data-active]');
                if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            });
        },
    },
    methods: {
        async handleProductTap(p) {
            if (p.has_combinations) {
                try {
                    const result = await ApiClient.get('/api/catalog/product/' + p.id);
                    this.combinationsProduct = result.data;
                    this.showCombinationsModal = true;
                } catch (err) {
                    console.error('Failed to load combinations', err);
                    this.$emit('addProduct', p);
                }
            } else {
                this.$emit('addProduct', p);
            }
        },
        onCombinationSelect(combo) {
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
        // Category bar: drag with the mouse (touch scrolls natively), wheel and arrows.
        onCatPointerDown(e) {
            this.catDragged = false;
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            this.catDrag = { x: e.clientX, left: this.$refs.catBar.scrollLeft, id: e.pointerId };
        },
        onCatPointerMove(e) {
            if (!this.catDrag) return;
            const dx = e.clientX - this.catDrag.x;
            if (!this.catDragged && Math.abs(dx) > 5) {
                this.catDragged = true;
                this.$refs.catBar.setPointerCapture(this.catDrag.id);
                this.$refs.catBar.classList.add('dragging');
            }
            if (this.catDragged) this.$refs.catBar.scrollLeft = this.catDrag.left - dx;
        },
        onCatPointerUp() {
            if (!this.catDrag) return;
            this.catDrag = null;
            this.$refs.catBar.classList.remove('dragging');
        },
        // A drag must not also select the category under the pointer when it ends.
        onCatClickCapture(e) {
            if (this.catDragged) {
                e.stopPropagation();
                e.preventDefault();
                this.catDragged = false;
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
        <div style="display:flex; flex-direction:column; height:100%; overflow:hidden;">
            <!-- Combinations Modal -->
            <combinations-modal
                v-if="showCombinationsModal && combinationsProduct"
                :product="combinationsProduct"
                :visible="showCombinationsModal"
                @close="showCombinationsModal = false; combinationsProduct = null;"
                @select="onCombinationSelect"
                @print-labels="onPrintLabels"
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
                    <input
                        :value="catalog.search"
                        @input="$emit('setSearch', $event.target.value)"
                        placeholder="Buscar por nombre, SKU, EAN…"
                        style="width:100%; background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:9px 12px 9px 38px; color:var(--text); font-family:inherit; font-size:14px; outline:none;"
                    />
                </div>
                <button title="Escanear código" style="width:40px; height:40px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <icon name="barcode" :size="17" />
                </button>
                <button @click="$emit('setOnlyInStock', !catalog.onlyInStock)" :aria-pressed="catalog.onlyInStock ? 'true' : 'false'"
                    :title="catalog.onlyInStock ? 'Mostrando solo artículos con stock' : 'Mostrar solo artículos con stock'"
                    style="height:40px; padding:0 12px; border-radius:10px; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; white-space:nowrap; flex-shrink:0; display:flex; align-items:center; gap:6px;"
                    :style="{ border: catalog.onlyInStock ? '1px solid var(--green)' : '1px solid var(--border)', background: catalog.onlyInStock ? 'var(--green-bg)' : 'var(--surface2)', color: catalog.onlyInStock ? 'var(--green)' : 'var(--text2)' }">
                    <span aria-hidden="true">{{ catalog.onlyInStock ? '✓' : '○' }}</span> Solo con stock
                </button>
                <button @click="$emit('toggleView')" style="width:40px; height:40px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <icon :name="listView ? 'grid' : 'list'" :size="16" />
                </button>
            </div>

            <!-- Category tabs: scrollbar, mouse drag, wheel and arrows -->
            <div style="display:flex; align-items:stretch; border-bottom:1px solid var(--border); flex-shrink:0;">
                <button class="cat-arrow" @click="stepCategories(-1)" aria-label="Categorías anteriores">‹</button>
                <div ref="catBar" class="cat-tabs"
                    @pointerdown="onCatPointerDown" @pointermove="onCatPointerMove" @pointerup="onCatPointerUp" @pointercancel="onCatPointerUp"
                    @click.capture="onCatClickCapture" @wheel="onCatWheel">
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
            <div @scroll="onProductsScroll" style="flex:1; overflow-y:auto; padding:12px; -webkit-overflow-scrolling:touch; touch-action:pan-y; min-height:0;">
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
                            <template v-if="p.image_url"><img :src="p.image_url" :alt="p.name" style="width:100%;height:100%;object-fit:cover;border-radius:6px 6px 0 0;" @error="$event.target.parentElement.textContent='IMG'" /></template>
                            <template v-else>IMG</template>
                        </div>
                        <div style="font-size:12px; font-weight:600; color:var(--text); line-height:1.3; text-wrap:pretty;">{{ p.name }}</div>
                        <div v-if="p.reference" style="font-size:10px; color:var(--text3); font-family:'IBM Plex Mono';">{{ p.reference }}</div>
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:auto;">
                            <span style="font-family:'IBM Plex Mono'; font-size:13px; font-weight:600; color:var(--text);">€{{ Number(p.price).toFixed(2) }}</span>
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
                            <template v-if="p.image_url"><img :src="p.image_url" :alt="p.name" style="width:100%;height:100%;object-fit:cover;border-radius:6px;" @error="$event.target.parentElement.textContent='IMG'" /></template>
                            <template v-else>IMG</template>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:13px; font-weight:500; color:var(--text);">{{ p.name }}</div>
                            <div v-if="p.reference" style="font-size:11px; color:var(--text3); font-family:'IBM Plex Mono'; margin-top:2px;">{{ p.reference }}</div>
                        </div>
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
