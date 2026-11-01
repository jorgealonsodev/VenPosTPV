/**
 * Numpad component — reusable numpad with digits, clear, backspace, presets.
 */
const Numpad = {
    props: ['presets', 'showDecimal', 'presetColumns'],
    emits: ['input', 'clear'],
    setup(props, { emit }) {
        const keys = ['7','8','9','4','5','6','1','2','3',',','0','←'];

        function onKey(k) {
            if (k === '←') {
                emit('input', 'backspace');
            } else if (k === ',') {
                emit('input', 'decimal');
            } else {
                emit('input', k);
            }
        }

        const presetColumns = Vue.computed(() => Number(props.presetColumns) > 0 ? Number(props.presetColumns) : 3);

        return { keys, onKey, presetColumns };
    },
    template: `
        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:7px;">
            <button v-for="k in keys" :key="k" class="numpad-btn" @click="onKey(k)">
                {{ k }}
            </button>
        </div>
        <div v-if="presets && presets.length" :style="{ display: 'grid', gridTemplateColumns: 'repeat(' + presetColumns + ', 1fr)', gap: '6px', marginTop: '8px' }">
            <button v-for="p in presets" :key="p.value" class="numpad-btn"
                style="font-size:13px;"
                @click="$emit('input', 'preset:' + p.value)">
                {{ p.label }}
            </button>
        </div>
    `
};
