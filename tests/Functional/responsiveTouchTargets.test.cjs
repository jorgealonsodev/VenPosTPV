const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const read = (file) => fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8');
const ticket = read('public/assets/js/components/TicketPanel.js');
const css = read('public/assets/css/app.css');
const page = read('templates/pos.html.twig');

/** The body of the `@media <query> {` block. */
function block(query) {
    const start = css.indexOf(`@media ${query} {`);
    if (start === -1) return '';
    let depth = 0;
    for (let i = start; i < css.length; i++) {
        if (css[i] === '{') depth++;
        if (css[i] === '}' && --depth === 0) return css.slice(start, i + 1);
    }
    return '';
}

test('ticket controls have class hooks the touch rules can reach', () => {
    assert.equal((ticket.match(/class="ticket-qty-btn"/g) || []).length, 2, 'minus and plus');
    assert.match(ticket, /<button @click="\$emit\('remove', line\.id\)"\s+class="ticket-remove-btn"/);
    assert.match(ticket, /<div class="ticket-actions"/);
    assert.match(ticket, /<button @click="\$emit\('showCustomer'\)"\s+class="ticket-customer-btn"/);
});

test('on touch screens and narrow widths the sale controls are at least 44px', () => {
    const touch = block('(pointer: coarse), (max-width: 1023px)');
    assert.match(touch, /\.ticket-qty-btn,\s*\.ticket-remove-btn\s*\{[^}]*width:\s*44px !important;[^}]*height:\s*44px !important/);
    assert.match(touch, /\.ticket-actions > button\s*\{[^}]*min-height:\s*44px/);
    assert.match(touch, /\.ticket-customer-btn\s*\{[^}]*min-height:\s*44px/);
    assert.match(touch, /\.catalog-toolbar > button\s*\{[^}]*width:\s*44px !important;[^}]*height:\s*44px !important/);
    assert.match(touch, /button\.cat-arrow\s*\{[^}]*width:\s*44px/);
    assert.match(touch, /\.cat-tabs > button\s*\{[^}]*min-height:\s*44px/);
});

test('on a phone the idle search is a magnifier button that opens the search box', () => {
    const phone = css.slice(css.indexOf('/* Phone: one pane at a time'));
    const idle = phone.match(/\.catalog-search-box:not\(:focus-within\):not\(\.has-text\)\s*\{([^}]*)\}/);
    assert.ok(idle, 'collapsed only while it is neither focused nor filled');
    assert.match(idle[1], /flex:\s*0 0 44px !important/);
    assert.match(phone, /\.catalog-search-box:not\(:focus-within\):not\(\.has-text\) input::placeholder\s*\{[^}]*color:\s*transparent/);
    assert.match(css, /\.catalog-search-box:focus-within,\s*\.catalog-search-box\.has-text\s*\{[^}]*flex:\s*1 0 100% !important/, 'tapping it opens the full-width box');
});

test('browsers reload the files the touch rules changed', () => {
    const { hasCacheVersionSince } = require('./support/cacheVersion.cjs');
    assert.ok(hasCacheVersionSince(page, 'components/TicketPanel.js', '20261111'));
    assert.match(page, /\/assets\/css\/app\.css\?v=20261111-responsive-v[2-9]/);
});
