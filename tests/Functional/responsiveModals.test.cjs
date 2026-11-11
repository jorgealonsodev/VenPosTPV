const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const read = (file) => fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8');
const css = read('public/assets/css/app.css');
const page = read('templates/pos.html.twig');

test('small modals never get wider or taller than the screen', () => {
    [['CustomerModal.js', 'width:400px'], ['DiscountModal.js', 'width:320px'], ['CashOpenScreen.js', 'width:420px']].forEach(([file, width]) => {
        const source = read('public/assets/js/components/' + file);
        const card = source.match(new RegExp(`<div class="tpv-fit-card[^"]*" style="[^"]*${width}`));
        assert.ok(card, `${file} keeps its ${width} card and takes the shared fit class`);
    });
    assert.match(css, /\.tpv-fit-card\s*\{[^}]*max-width:\s*calc\(100vw - 24px\);[^}]*max-height:\s*calc\(100dvh - 24px\);[^}]*overflow-y:\s*auto/);
});

test('on a phone the payment modal is a full-screen sheet whose confirm button stays in view', () => {
    const start = css.indexOf('@media (max-width: 760px) {');
    const block = css.slice(start, css.indexOf('\n}', start) + 2);
    assert.match(block, /\.pay-modal\s*\{[^}]*width:\s*100vw;[^}]*height:\s*100dvh/);
    assert.match(block, /\.pay-confirm\s*\{[^}]*position:\s*fixed;[^}]*bottom:/, 'pinned to the bottom of the screen, never below the fold');
    assert.match(block, /\.pay-right\s*\{[^}]*padding-bottom:\s*calc\(/, 'the content keeps clear of the pinned button');
    assert.match(block, /\.pay-key\s*\{[^}]*height:\s*46px/, 'smaller keys so the whole payment fits more phones');
    assert.match(block, /\.pay-method\s*\{[^}]*min-height:\s*46px/);
});

test('the payment result card fits the screen and scrolls', () => {
    assert.match(css, /\.pay-result\s*\{[^}]*max-width:\s*min\(460px, calc\(100vw - 24px\)\);[^}]*max-height:\s*calc\(100dvh - 24px\);[^}]*overflow-y:\s*auto/);
});

test('browsers reload the files the modal rules changed', () => {
    const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
    ['components/CustomerModal.js', 'components/DiscountModal.js', 'components/CashOpenScreen.js'].forEach((file) => {
        assert.ok(hasCacheVersionSince(page, file, '20261111'), file);
    });
    assert.match(page, /\/assets\/css\/app\.css\?v=20261111-responsive-v[3-9]/);
});
