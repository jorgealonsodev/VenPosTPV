/**
 * CombinationsModal — shows product combinations for selection.
 */
const CombinationsModal = {
    name: 'CombinationsModal',
    props: {
        product: { type: Object, default: null },
        visible: { type: Boolean, default: false }
    },
    emits: ['close', 'select', 'printLabels'],
    methods: {
        visual(combo) {
            return CombinationsModal.visual(this.product, combo);
        },
        formatAttributes(attributes) {
            if (!attributes || attributes.length === 0) return '—';
            return attributes.map(a => a.group_name + ': ' + a.attribute_name).join(' / ');
        },
        formatPrice(price) {
            return '€' + Number(price).toFixed(2);
        }
    },
    template: `
        <div class="combinations-modal-backdrop" @click.self="$emit('close')">
            <div class="combinations-modal">
                <div class="combinations-modal-header">
                    <h3 style="margin:0; font-size:16px; color:var(--text); font-weight:600;">{{ product.name }}</h3>
                    <button class="tpv-btn" style="margin-left:auto; margin-right:8px;" @click="$emit('printLabels', product)" title="Imprimir etiquetas de este producto">
                        <icon name="tag" :size="16" /> Imprimir etiquetas
                    </button>
                    <button @click="$emit('close')" style="background:none; border:none; color:var(--text); font-size:20px; cursor:pointer; line-height:1; padding:4px 8px;">✕</button>
                </div>
                <div class="combinations-modal-list">
                    <button
                        v-for="combo in product.combinations"
                        :key="combo.id"
                        class="combination-btn"
                        :disabled="combo.stock <= 0"
                        @click="$emit('select', combo)"
                    >
                        <span class="combo-visual" v-if="visual(combo)">
                            <img v-if="visual(combo).image" :src="visual(combo).image" alt="" loading="lazy" @error="$event.target.remove()" />
                            <span v-else class="combo-swatch" :style="{ background: visual(combo).color }"></span>
                        </span>
                        <span class="combo-label">{{ formatAttributes(combo.attributes) }}</span>
                        <span class="combo-price">{{ formatPrice(product.price + combo.price_impact) }}</span>
                        <span class="combo-stock" :class="{ 'out': combo.stock <= 0 }">
                            {{ combo.stock <= 0 ? 'Sin stock' : combo.stock + ' uds' }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    `
};

/**
 * What a size or colour shows next to its name: its own photo; else, for a colour,
 * the colour itself; else the product photo; null when there is nothing to show.
 * Only hex colours are painted (they go into a style attribute).
 *
 * @returns {{image: string}|{color: string}|null}
 */
CombinationsModal.visual = function (product, combo) {
    if (combo && typeof combo.image_url === 'string' && combo.image_url !== '') return { image: combo.image_url };
    const colour = ((combo && combo.attributes) || []).map((a) => a && a.color).find((c) => typeof c === 'string' && /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(c));
    if (colour) return { color: colour };
    if (product && typeof product.image_url === 'string' && product.image_url !== '') return { image: product.image_url };
    return null;
};
