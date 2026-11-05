const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/SettingsModal.js'), 'utf8');

function load() {
    const context = new Proxy({ console, Math }, { has: () => true, get: (t, k) => (k in t ? t[k] : (k === Symbol.unscopables ? undefined : {})) });
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__modal = SettingsModal;`, context, { filename: 'SettingsModal.js' });
    return context.__modal;
}

const START = { w: 720, h: 600 };
const VIEW = { w: 1600, h: 1000 };
const plain = (v) => JSON.parse(JSON.stringify(v));

test('dragging a corner outwards grows the centred window by twice the drag', () => {
    const size = load().resizedSize;
    assert.deepEqual(plain(size(START, 50, 40, 1, 1, VIEW)), { w: 820, h: 680 }, 'bottom-right');
    assert.deepEqual(plain(size(START, -50, -40, -1, -1, VIEW)), { w: 820, h: 680 }, 'top-left');
    assert.deepEqual(plain(size(START, -50, 40, -1, 1, VIEW)), { w: 820, h: 680 }, 'bottom-left');
    assert.deepEqual(plain(size(START, 50, -40, 1, -1, VIEW)), { w: 820, h: 680 }, 'top-right');
});

test('it never gets smaller than usable nor bigger than the screen', () => {
    const size = load().resizedSize;
    assert.deepEqual(plain(size(START, -1000, -1000, 1, 1, VIEW)), { w: 560, h: 420 });
    assert.deepEqual(plain(size(START, 2000, 2000, 1, 1, VIEW)), { w: 1568, h: 968 }, '16 px margin on each side');
});

test('the four corners have a touch-sized handle', () => {
    for (const corner of ['nw', 'ne', 'sw', 'se']) {
        assert.ok(source.includes(`class="settings-resize settings-resize-${corner}"`), corner);
    }
    assert.match(source, /@pointerdown\.prevent="startResize\(\$event, -1, -1\)"/);
});
