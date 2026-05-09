/**
 * CombinationsModal — shows product combinations for selection.
 */
const CombinationsModal = {
    name: 'CombinationsModal',
    props: {
        product: { type: Object, default: null },
        visible: { type: Boolean, default: false }
    },
    emits: ['close', 'select'],
    methods: {
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
