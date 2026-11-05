const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '../../public/assets/js/components');
const source = fs.readFileSync(path.join(ROOT, 'CombinationsModal.js'), 'utf8');

function load() {
    const context = { console };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = CombinationsModal;`, context, { filename: 'CombinationsModal.js' });
    return context.__modal;
}

const PRODUCT = { id: 1, name: 'Aletas', image_url: 'https://shop.example.com/img/p/5/0/50-home_default.jpg' };
const plain = (value) => JSON.parse(JSON.stringify(value));

test('a size or colour with its own photo shows that photo', () => {
    const combo = { id: 10, image_url: 'https://shop.example.com/img/p/5/5/55-home_default.jpg', attributes: [{ group_name: 'Color', attribute_name: 'Negro', color: '#000000' }] };
    assert.deepEqual(plain(load().visual(PRODUCT, combo)), { image: combo.image_url });
});

test('a colour without a photo shows its colour', () => {
    const combo = { id: 11, image_url: null, attributes: [{ group_name: 'Talla', attribute_name: 'M', color: null }, { group_name: 'Color', attribute_name: 'Rojo', color: '#E84C3D' }] };
    assert.deepEqual(plain(load().visual(PRODUCT, combo)), { color: '#E84C3D' });
});

test('anything else shows the product photo, or nothing when there is none', () => {
    const size = { id: 12, image_url: null, attributes: [{ group_name: 'Talla', attribute_name: '42/43', color: null }] };
    assert.deepEqual(plain(load().visual(PRODUCT, size)), { image: PRODUCT.image_url });
    assert.equal(load().visual({ id: 2, name: 'Sin foto' }, size), null);
    assert.equal(load().visual(PRODUCT, { id: 13, attributes: [{ color: 'red;background:url(x)' }] }).image, PRODUCT.image_url, 'only a hex colour is painted');
});

test('the variants window and the Etiquetas variant list both draw it', () => {
    assert.match(source, /class="combo-visual"/);
    const labels = fs.readFileSync(path.join(ROOT, 'LabelsScreen.js'), 'utf8');
    assert.match(labels, /function comboVisual\(combo\) \{ return CombinationsModal\.visual\(choosing\.value, combo\); \}/);
    assert.match(labels, /<span v-else class="combo-swatch" :style="\{ background: comboVisual\(combo\)\.color \}"/);
    assert.match(labels, /return \{\s*comboVisual,/);
});
