const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/CatalogPanel.js'), 'utf8');

function loadPanel() {
    // The child components it registers are other files: stand-ins are enough here.
    const context = new Proxy({ console }, { has: () => true, get: (t, k) => (k in t ? t[k] : (k === Symbol.unscopables ? undefined : {})) });
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__panel = CatalogPanel;`, context, { filename: 'CatalogPanel.js' });
    return context.__panel;
}

test('the clear button empties the search and keeps the focus in the box', () => {
    const panel = loadPanel();
    const emitted = [];
    let focused = false;
    const self = {
        $emit: (...args) => emitted.push(args),
        $refs: { searchInput: { focus: () => { focused = true; } } },
    };

    panel.methods.clearSearch.call(self);

    assert.deepEqual(emitted, [['setSearch', '']]);
    assert.ok(focused, 'ready for the next search or scan');
});

test('the clear button only shows while there is text, with a touch-sized target', () => {
    const button = source.match(/<button v-if="catalog\.search"[^>]*class="catalog-search-clear"[\s\S]*?<\/button>/);
    assert.ok(button, 'the button is in the search box and hidden when it is empty');
    assert.ok(button[0].includes('@click="clearSearch"'));
    assert.ok(button[0].includes('aria-label="Borrar búsqueda"'));
    assert.match(button[0], /width:40px; height:36px/);
});
