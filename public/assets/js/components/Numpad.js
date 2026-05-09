/**
 * Numpad component — reusable numpad with digits, clear, backspace, presets.
 */
const Numpad = {
    props: ['presets', 'showDecimal'],
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

        return { keys, onKey };
    },
    template: `
        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:7px;">
            <button v-for="k in keys" :key="k" class="numpad-btn" @click="onKey(k)">
                {{ k }}
            </button>
        </div>
        <div v-if="presets && presets.length" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
            <button v-for="p in presets" :key="p.value" class="numpad-btn"
                style="flex:1; min-width:60px; font-size:13px;"
                @click="$emit('input', 'preset:' + p.value)">
                {{ p.label }}
            </button>
        </div>
    `
};
