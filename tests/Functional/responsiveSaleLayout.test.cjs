const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const read = (file) => fs.readFileSync(path.resolve(__dirname, '../../public/assets', file), 'utf8');
const sale = read('js/components/SaleScreen.js');
const catalog = read('js/components/CatalogPanel.js');
const app = read('js/app.js');
const css = read('css/app.css');

/** The body of the first `@media (max-width: <px>)` block that mentions `needle`. */
function mediaBlock(px, needle) {
    const opener = `@media (max-width: ${px}px) {`;
    let from = 0;
    for (;;) {
        const start = css.indexOf(opener, from);
        if (start === -1) return '';
        let depth = 0;
        let end = start;
        for (let i = start; i < css.length; i++) {
            if (css[i] === '{') depth++;
            if (css[i] === '}' && --depth === 0) { end = i; break; }
        }
        const block = css.slice(start, end + 1);
        if (block.includes(needle)) return block;
        from = end;
    }
}

test('header actions collapse into a menu below the width where the row fits', () => {
    const toggle = sale.match(/<button[^>]*class="pos-header-menu-toggle"[^>]*>/);
    assert.ok(toggle, 'one header button opens the menu');
    assert.ok(toggle[0].includes('@click="showHeaderMenu = !showHeaderMenu"'));
    assert.ok(toggle[0].includes(':aria-expanded='), 'the open state is announced');
    assert.match(sale, /class="pos-header-actions" :class="\{ 'is-open': showHeaderMenu \}"/);

    const block = mediaBlock(1599, '.pos-header-actions');
    assert.match(block, /\.pos-header-menu-toggle\s*\{[^}]*display:\s*inline-flex/);
    assert.match(block, /\.pos-header-actions\s*\{[^}]*display:\s*none/);
    assert.match(block, /\.pos-header-actions\.is-open\s*\{[^}]*overflow-y:\s*auto/, 'a long menu scrolls instead of being clipped');
});

test('every header action lives inside the collapsible group', () => {
    const group = sale.slice(sale.indexOf('class="pos-header-actions"'), sale.indexOf('</header>'));
    ['handleSyncNow', 'toggleTheme', 'showCouponCreation = true', 'showCouponList = true', 'showReports = true',
        'showSettings = true', 'toggleTickets', 'handleReprint', 'showLabels = true', 'handleRefund', 'handleCashClose', 'handleLogout',
    ].forEach((action) => assert.ok(group.includes(`@click="${action}"`), `${action} is reachable from the menu`));
});

test('the sale layout is class-driven so the breakpoints can reach it', () => {
    assert.match(sale, /<div class="pos-main" :data-pane="mobilePane">/);
    assert.match(sale, /<div class="layout-catalog">/);
    assert.match(sale, /<div class="layout-ticket">/);
    assert.doesNotMatch(sale, /style="width:62%/);
    assert.doesNotMatch(sale, /style="width:38%/);
});

test('on a phone the catalog and the ticket are shown one at a time', () => {
    const block = mediaBlock(767, '.pos-main');
    assert.match(block, /\.pos-main\[data-pane="catalog"\] \.layout-ticket\s*\{[^}]*display:\s*none/);
    assert.match(block, /\.pos-main\[data-pane="ticket"\] \.layout-catalog\s*\{[^}]*display:\s*none/);
    assert.match(block, /\.pos-mobile-bar\s*\{[^}]*display:\s*flex/);

    const bar = sale.match(/<button[^>]*class="pos-mobile-bar"[\s\S]*?<\/button>/);
    assert.ok(bar, 'the catalog has a bottom bar');
    assert.ok(bar[0].includes(`@click="mobilePane = 'ticket'"`), 'it opens the ticket');
    assert.ok(bar[0].includes('{{ cartUnits }}'), 'it shows how many items are in the ticket');
    assert.ok(bar[0].includes('{{ eur(cart.total) }}'), 'it shows the total');
    assert.match(sale, /class="pos-pane-back"[^>]*@click="mobilePane = 'catalog'"/, 'the ticket has a way back');
});

test('product cards get fewer columns instead of getting narrower', () => {
    assert.match(catalog, /<div v-if="!listView" class="catalog-grid">/);
    assert.doesNotMatch(catalog, /repeat\(5, 1fr\)/);
    assert.match(css, /\.catalog-grid\s*\{[^}]*grid-template-columns:\s*repeat\(5, 1fr\)/, 'desktop keeps five columns');
    assert.match(mediaBlock(1023, '.catalog-grid'), /\.catalog-grid\s*\{[^}]*repeat\(3, 1fr\)/);
    assert.match(mediaBlock(767, '.catalog-grid'), /\.catalog-grid\s*\{[^}]*auto-fill/);
});

test('the app follows the dynamic viewport height', () => {
    assert.match(app, /<div class="app-root">/);
    assert.match(css, /\.app-root\s*\{[^}]*height:\s*100vh;[^}]*height:\s*100dvh/);
});

test('on a phone or tablet the search box takes the whole row while searching', () => {
    assert.match(catalog, /<div class="catalog-toolbar"/);
    assert.match(catalog, /<div class="catalog-search-box" :class="\{ 'has-text': !!catalog\.search \}"/);

    const block = mediaBlock(1023, '.catalog-search-box');
    assert.match(block, /\.catalog-toolbar\s*\{[^}]*flex-wrap:\s*wrap/, 'the tool buttons drop to a second row instead of squeezing the box');
    const expanded = block.match(/\.catalog-search-box:focus-within,\s*\.catalog-search-box\.has-text\s*\{([^}]*)\}/);
    assert.ok(expanded, 'it expands on focus and stays expanded while there is text');
    assert.match(expanded[1], /flex:\s*1 0 100%/);
    assert.doesNotMatch(block, /\.catalog-search-box[^{]*\{[^}]*transition/, 'at once, with no animation');
    assert.match(block, /\.catalog-search-box input\s*\{[^}]*font-size:\s*16px/, 'readable, and iOS does not zoom the page on focus');
});

test('browsers reload the files the responsive layout changed', () => {
    const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
    const page = fs.readFileSync(path.resolve(__dirname, '../../templates/pos.html.twig'), 'utf8');
    ['components/SaleScreen.js', 'components/CatalogPanel.js', 'app.js'].forEach((file) => {
        assert.ok(hasCacheVersionSince(page, file, '20261111'), `${file} has a version from the responsive change or later`);
    });
    const cssVersion = /\/assets\/css\/app\.css\?v=(\d{8})-/.exec(page);
    assert.ok(cssVersion && cssVersion[1] >= '20261111', 'app.css has a version from the responsive change or later');
});
