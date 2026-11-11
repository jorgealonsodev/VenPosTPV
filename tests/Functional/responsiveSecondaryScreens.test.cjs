// Refunds, labels, cash close, reports and settings on tablet and phone
// (odd/tasks/responsive-pos.md S7-S9): structure of the templates and of app.css.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const read = (file) => fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8');
const component = (name) => read('public/assets/js/components/' + name);
const css = read('public/assets/css/app.css');
const page = read('templates/pos.html.twig');
const refund = component('RefundScreen.js');
const cashClose = component('CashCloseScreen.js');
const reports = component('ReportsScreen.js');
const settings = component('SettingsModal.js');

// The section this task owns: from its heading to the end of the stylesheet.
const sectionStart = css.indexOf('Secondary screens on tablet and phone');
const section = sectionStart < 0 ? '' : css.slice(css.lastIndexOf('/*', sectionStart));

/** Body of the first `@media <query> { … }` block of the section, braces matched. */
function media(query) {
    const start = section.indexOf('@media ' + query + ' {');
    if (start < 0) return '';
    let depth = 0;
    for (let i = section.indexOf('{', start); i < section.length; i++) {
        if (section[i] === '{') depth++;
        if (section[i] === '}' && --depth === 0) return section.slice(start, i + 1);
    }
    return '';
}

const phone = media('(max-width: 767px)');
const tablet = media('(max-width: 1023px)');
const touch = media('(pointer: coarse), (max-width: 1023px)');
/** The section without its media blocks: what also applies to the desktop. */
const always = [phone, tablet, touch].reduce((text, block) => (block ? text.replace(block, '') : text), section);

test('on a phone the two-column screens stack into one scrolling column', () => {
    // Refunds and labels share the split panels.
    assert.match(phone, /\.tpv-split\s*\{[^}]*flex-direction:\s*column;[^}]*overflow-y:\s*auto/);
    assert.match(phone, /\.tpv-split-main\s*\{[^}]*width:\s*100%;[^}]*overflow:\s*visible/);
    assert.match(phone, /\.tpv-split-side-body\s*\{[^}]*overflow:\s*visible/);
    assert.match(phone, /\.tpv-split-side-foot\s*\{[^}]*position:\s*sticky;[^}]*bottom:\s*0/, 'the action stays in view');
    assert.match(refund, /<header class="refund-header" style="height:64px;/);
    assert.match(phone, /\.refund-header > div\s*\{[^}]*text-overflow:\s*ellipsis/, 'a long order label never widens the header');
    assert.match(phone, /\.refund-header-context\s*\{[^}]*flex-shrink:\s*100/);
    assert.match(phone, /\.label-row > \.tpv-stepper\s*\{[^}]*flex:\s*1 0 100%/, 'label quantities drop below the product name');
    // Cash close: count and summary one below the other, with phone-sized padding.
    assert.match(cashClose, /<div class="cash-close-screen" style="width:100%; height:100%;/);
    assert.match(cashClose, /<div class="cash-close-grid" style="display:grid; gap:20px; max-width:900px;">/);
    assert.match(phone, /\.cash-close-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/);
    assert.match(phone, /\.cash-close-screen\s*\{[^}]*padding:\s*16px !important/);
    assert.match(phone, /\.cash-close-notice\s*\{[^}]*flex-wrap:\s*wrap/);
});

test('on a tablet the split screens and the cash close keep their two columns', () => {
    assert.match(css, /@media \(max-width: 1023px\) \{\s*\.tpv-split-main \{ width: 55%; \}\s*\.tpv-split-side \{ width: 45%; \}\s*\}/);
    assert.doesNotMatch(tablet, /\.tpv-split|\.cash-close-grid/);
    assert.doesNotMatch(touch, /\.tpv-split|\.cash-close-grid/);
});

test('each report table scrolls sideways inside its own wrapper', () => {
    const tables = reports.match(/<table\b/g).length;
    const wrapped = reports.match(/<div (?:v-else )?class="reports-table-scroll">\s*<table (?:class="reports-table)/g) || [];
    assert.ok(tables >= 10);
    assert.equal(wrapped.length, tables, 'every table has its wrapper');
    assert.equal((reports.match(/<\/table>\s*<\/div>/g) || []).length, tables);
    assert.match(always, /\.reports-table-scroll\s*\{[^}]*flex-shrink:\s*0;[^}]*overflow-x:\s*auto/);
    assert.match(tablet, /\.reports-body\s*\{[^}]*overflow-x:\s*hidden/, 'the page itself does not pan sideways');
});

test('on a phone the settings window is a full-screen sheet with its sections as a row of tabs', () => {
    assert.match(settings, /<div class="settings-backdrop"\s+style="position:fixed; inset:0;/);
    assert.match(settings, /<div ref="modalBox" class="settings-modal" :class="\{ 'is-sized': modalStyle\.width \}" :style="modalStyle" style="position:relative;/);
    assert.match(settings, /<div class="settings-body" style="flex:1; display:flex; overflow:hidden;">/);
    assert.match(settings, /<div class="settings-nav" style="width:180px;/);
    assert.match(settings, /<div class="settings-content" style="flex:1; overflow-y:auto; padding:20px 24px;">/);

    assert.match(phone, /\.settings-backdrop\s*\{[^}]*padding:\s*0 !important/);
    assert.match(phone, /\.settings-backdrop > \.settings-modal\s*\{[^}]*width:\s*100vw !important;[^}]*height:\s*100dvh !important;[^}]*max-height:\s*none !important/, 'the saved pixel size does not apply');
    assert.match(phone, /\.settings-resize\s*\{[^}]*display:\s*none/, 'no resize handles');
    assert.match(phone, /\.settings-body\s*\{[^}]*flex-direction:\s*column !important/);
    assert.match(phone, /\.settings-nav\s*\{[^}]*width:\s*auto !important;[^}]*flex-direction:\s*row !important;[^}]*overflow-x:\s*auto !important/);
    assert.match(phone, /\.settings-content input:not\(\[type="checkbox"\]\)[^{]*,\s*\.settings-content select,\s*\.settings-content textarea\s*\{[^}]*width:\s*100% !important;[^}]*max-width:\s*none !important/);
    // A size saved on a bigger screen never exceeds a tablet either.
    assert.match(tablet, /\.settings-modal\.is-sized\s*\{[^}]*max-width:\s*calc\(100vw - 32px\) !important;[^}]*max-height:\s*calc\(100dvh - 32px\) !important/);
});

test('the controls of these screens are at least 44px on touch', () => {
    assert.match(settings, /<button @click="closeSettings" class="settings-close"/);
    assert.match(touch, /\.settings-close\s*\{[^}]*min-width:\s*44px;[^}]*min-height:\s*44px/);
    assert.match(cashClose, /<button class="cash-close-back" @click="\$emit\('back'\)"/);
    assert.match(touch, /\.cash-close-back\s*\{[^}]*width:\s*44px !important;[^}]*height:\s*44px !important/);
    assert.match(touch, /\.cash-close-discard\s*\{[^}]*min-height:\s*44px/);
    assert.match(touch, /\.cash-close-denoms input\s*\{[^}]*min-height:\s*44px/);
    assert.match(touch, /\.cash-count-modes button\s*\{[^}]*min-height:\s*44px/);
    assert.match(touch, /\.reports-chip\s*\{[^}]*min-height:\s*44px/);
    assert.match(touch, /\.reports-custom \.tpv-input,\s*\.reports-selects \.tpv-input\s*\{[^}]*min-height:\s*44px/);
});

test('the coin and note counts open the numeric keyboard and stay number inputs', () => {
    assert.match(cashClose, /<input type="number" inputmode="numeric" min="0" v-model="counted\[d\.v\]" placeholder="0"/);
    assert.match(cashClose, /<input id="cash-count-amount" class="tpv-input" type="text" inputmode="decimal"/, 'the total amount is unchanged');
});

test('the desktop layout of these screens is the one it had', () => {
    // Base rules and inline sizes are still there; the new rules live in media queries.
    assert.match(css, /\.tpv-split-main \{ width: 62%;/);
    assert.match(css, /\.tpv-split-side \{ width: 38%;/);
    assert.match(css, /\.reports-table \{ width: 100%; max-width: 960px;/);
    assert.match(css, /\.settings-resize \{ position: absolute; width: 24px; height: 24px;/);
    assert.match(settings, /border-radius:16px; width:100%; max-width:720px; max-height:90vh;/);
    assert.match(settings, /maxWidth: 'none', maxHeight: 'none'/);
    assert.match(cashClose, /overflow:auto; padding:32px; display:flex; flex-direction:column; gap:24px;/);
    assert.match(cashClose, /width:36px; height:36px;/);
    // The two grids moved from the inline style to the stylesheet with the same columns.
    assert.match(always, /\.cash-close-grid\s*\{\s*grid-template-columns:\s*1fr 1fr;\s*\}/);
    assert.match(always, /\.cash-close-denoms\s*\{\s*grid-template-columns:\s*repeat\(3, 1fr\);\s*\}/);
    const selectors = (always.replace(/\/\*[\s\S]*?\*\//g, '').match(/[^{}]+\{/g) || []).map((rule) => rule.replace('{', '').trim());
    // `.cash-close-screen` is the one deliberate exception: it gives the screen a height so it can scroll at any width.
    assert.deepEqual(selectors, ['.reports-table-scroll', '.cash-close-grid', '.cash-close-denoms', '.cash-close-screen'], 'nothing else applies outside a media query');
    // The sale screen rules are not in this section.
    assert.doesNotMatch(section, /\.(pos|layout|catalog|ticket|pay)-|\.tpv-fit-card/);
});

test('heights follow the dynamic viewport, after a vh fallback', () => {
    assert.match(phone, /height:\s*100vh !important;\s*height:\s*100dvh !important;/);
    assert.match(tablet, /max-height:\s*calc\(100vh - 32px\) !important;\s*max-height:\s*calc\(100dvh - 32px\) !important;/);
    assert.match(tablet, /\.settings-modal:not\(\.is-sized\)\s*\{\s*max-height:\s*90vh !important;\s*max-height:\s*90dvh !important;/);
    const dvh = section.match(/[a-z-]+:[^;{}]*\ddvh[^;{}]*;/g) || [];
    assert.ok(dvh.length >= 3);
    dvh.forEach((line) => {
        const fallback = line.replace(/dvh/g, 'vh');
        assert.ok(section.includes(fallback), 'vh fallback for ' + line);
        assert.ok(section.indexOf(fallback) < section.indexOf(line), 'the fallback comes first: ' + line);
    });
});

test('browsers reload the files these rules changed', () => {
    const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
    ['components/RefundScreen.js', 'components/CashCloseScreen.js', 'components/ReportsScreen.js', 'components/SettingsModal.js'].forEach((file) => {
        assert.ok(hasCacheVersionSince(page, file, '20261111'), file);
    });
    assert.match(page, /\/assets\/css\/app\.css\?v=20261111-responsive-v[4-9]/);
});

test('on a phone every search button is a magnifier, like the sale screen search', () => {
    const labels = component('LabelsScreen.js');
    const tickets = component('TicketListPanel.js');
    const count = (source) => (source.match(/class="tpv-btn[^"]* tpv-search-btn"/g) || []).length;
    assert.equal(count(refund), 3, 'ticket search and both product searches');
    assert.equal(count(labels), 1);
    assert.equal(count(tickets), 1);
    [refund, labels, tickets].forEach((source) => {
        const buttons = source.match(/<button[^>]*tpv-search-btn[\s\S]*?<\/button>/g) || [];
        buttons.forEach((button) => {
            assert.match(button, /aria-label="(Buscar|Añadir)"/, 'the icon-only button keeps a name');
            assert.match(button, /<span[^>]*class="tpv-search-label">(Buscar|Añadir)<\/span>/, 'the text is in a span the phone rule can hide');
        });
    });
    assert.match(refund, /<div class="refund-search" style="display:flex; gap:8px;">/);
    assert.match(refund, /<button class="tpv-btn refund-no-ticket" @click="openNoTicket"/);

    const phone = css.slice(css.lastIndexOf('@media (max-width: 767px) {', css.indexOf('.tpv-search-label')));
    assert.match(phone, /\.tpv-search-label\s*\{[^}]*display:\s*none/, 'icon only, so the box keeps the row');
    assert.match(phone, /\.tpv-search-btn\s*\{[^}]*flex:\s*0 0 48px/);
    assert.match(phone, /\.refund-no-ticket\s*\{[^}]*flex:\s*1 0 100%/, '«Sin ticket» drops to its own row');
});

test('the cash close screen has a height of its own, so a tall count scrolls', () => {
    assert.match(css, /\.cash-close-screen\s*\{[^}]*height:\s*100vh !important;[^}]*height:\s*100dvh !important/);
});
