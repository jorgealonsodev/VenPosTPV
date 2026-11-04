/**
 * CatalogPanel — category tabs + search bar + product grid/list.
 */
const CatalogPanel = {
    props: ['catalog', 'listView', 'lastAdded'],
    emits: ['setSearch', 'setCategory', 'addProduct', 'toggleView', 'notify'],
    components: { CombinationsModal, LabelPrintModal },
    data() {
        return {
            combinationsProduct: null,
            showCombinationsModal: false,
            labelProduct: null,
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
                <button @click="$emit('toggleView')" style="width:40px; height:40px; border-radius:10px; border:1px solid var(--border); background:var(--surface2); cursor:pointer; color:var(--text2); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <icon :name="listView ? 'grid' : 'list'" :size="16" />
                </button>
            </div>

            <!-- Category tabs -->
            <div style="border-bottom:1px solid var(--border); padding:0 16px; display:flex; gap:4; overflow-x:auto; flex-shrink:0;">
                <button
                    @click="$emit('setCategory', null)"
                    style="padding:10px 14px; background:transparent; border:none; cursor:pointer; font-family:inherit; font-size:13px; white-space:nowrap; transition:all .12s;"
                    :style="{ fontWeight: catalog.activeCategory === null ? 600 : 400, color: catalog.activeCategory === null ? 'var(--text)' : 'var(--text3)', borderBottom: catalog.activeCategory === null ? '2px solid var(--green)' : '2px solid transparent' }">
                    Todo
                </button>
                <button v-for="cat in catalog.categories" :key="cat.id"
                    @click="$emit('setCategory', cat.id)"
                    style="padding:10px 14px; background:transparent; border:none; cursor:pointer; font-family:inherit; font-size:13px; white-space:nowrap; transition:all .12s;"
                    :style="{ fontWeight: catalog.activeCategory === cat.id ? 600 : 400, color: catalog.activeCategory === cat.id ? 'var(--text)' : 'var(--text3)', borderBottom: catalog.activeCategory === cat.id ? '2px solid var(--green)' : '2px solid transparent' }">
                    {{ cat.name }}
                </button>
            </div>

            <!-- Products -->
            <div style="flex:1; overflow-y:auto; padding:12px; -webkit-overflow-scrolling:touch; touch-action:pan-y; min-height:0;">
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
            </div>
        </div>
    `
};
