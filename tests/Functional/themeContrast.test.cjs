/**
 * Contrast guard for the theme tokens in public/assets/css/app.css.
 *
 * Parses the oklch() tokens of the dark (:root) and light ([data-theme="light"])
 * themes, converts them oklch -> OKLab -> linear sRGB (Björn Ottosson's matrices,
 * the ones CSS Color 4 uses), clips to the sRGB gamut and computes the WCAG 2
 * relative luminance and contrast ratio. Prints the measured table.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const CSS = fs.readFileSync(path.resolve(__dirname, '../../public/assets/css/app.css'), 'utf8');

function parseBlock(selectorRegex) {
    const match = selectorRegex.exec(CSS);
    assert.ok(match, 'theme block not found: ' + selectorRegex);
    const tokens = {};
    const re = /(--[a-z0-9-]+)\s*:\s*([^;]+);/g;
    let m;
    while ((m = re.exec(match[1])) !== null) tokens[m[1]] = m[2].trim();
    return tokens;
}

function parseOklch(value) {
    const m = /^oklch\(\s*([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)\s*\)$/.exec(value);
    if (!m) return null;
    const L = Number(m[1]) / (m[2] === '%' ? 100 : 1);
    return { L, C: Number(m[3]), h: Number(m[4]) };
}

/** oklch -> linear sRGB, clipped to [0, 1]. */
function oklchToLinearSrgb({ L, C, h }) {
    const rad = (h * Math.PI) / 180;
    const a = C * Math.cos(rad);
    const b = C * Math.sin(rad);
    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (L - 0.0894841775 * a - 1.2914855480 * b) ** 3;
    const clip = (v) => Math.min(1, Math.max(0, v));
    return [
        clip(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s),
        clip(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s),
        clip(-0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s),
    ];
}

function luminance(value) {
    const lch = parseOklch(value);
    assert.ok(lch, 'not an oklch() colour: ' + value);
    const [r, g, b] = oklchToLinearSrgb(lch);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(fg, bg) {
    const a = luminance(fg);
    const b = luminance(bg);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function testConversionMatchesKnownColours() {
    const close = (actual, expected, label) => assert.ok(Math.abs(actual - expected) < 0.002, `${label}: ${actual} != ${expected}`);
    close(luminance('oklch(100% 0 0)'), 1, 'white');
    close(luminance('oklch(0% 0 0)'), 0, 'black');
    // sRGB #ff0000 is oklch(62.8% 0.2577 29.23) -> linear (1, 0, 0) -> Y = 0.2126.
    const red = oklchToLinearSrgb(parseOklch('oklch(62.8% 0.2577 29.23)'));
    close(red[0], 1, 'red R'); close(red[1], 0, 'red G'); close(red[2], 0, 'red B');
    close(luminance('oklch(62.8% 0.2577 29.23)'), 0.2126, 'red Y');
    // sRGB #808080 is oklch(59.99% 0 0): linear 0.2159 -> Y 0.2159.
    close(luminance('oklch(59.99% 0 0)'), 0.2159, 'grey Y');
    assert.equal(ratio('oklch(100% 0 0)', 'oklch(0% 0 0)').toFixed(1), '21.0', 'white on black is 21:1');
}

const WHITE = 'oklch(100% 0 0)';

function checksFor(t) {
    const pairs = [];
    for (const fg of ['--text', '--text2', '--text3']) {
        for (const bg of ['--bg', '--surface', '--surface2']) pairs.push([fg, bg, 4.5]);
    }
    for (const fg of ['--red', '--green', '--amber']) pairs.push([fg, '--surface', 4.5]);
    // Selected chips and status tags: coloured text on its own tint.
    pairs.push(['--green', '--green-bg', 4.5]);
    pairs.push(['--red', '--red-bg', 4.5]);
    pairs.push(['--amber', '--amber-bg', 4.5]);
    pairs.push(['white', '--primary', 4.5]);
    pairs.push(['white', '--danger', 4.5]);
    // --primary is a fill: in the dark theme no colour gives both white-on-it and
    // it-on-the-surface >= 4.5, so it must reach 3:1 as a component boundary and is
    // never used as text colour (green text uses --green; see the source guard below).
    pairs.push(['--primary', '--surface', 3]);
    // Borders of interactive controls (--border2) against the surfaces they sit on.
    pairs.push(['--border2', '--surface', 3]);
    pairs.push(['--border2', '--surface2', 3]);
    pairs.push(['--border2', '--bg', 3]);
    return pairs.map(([fg, bg, min]) => {
        const fgValue = fg === 'white' ? WHITE : t[fg];
        // A missing token is reported as a failure (NaN) instead of aborting the table.
        const value = fgValue && t[bg] ? ratio(fgValue, t[bg]) : NaN;
        return { fg, bg, min, value };
    });
}

function testThemeTokensMeetContrast() {
    const dark = parseBlock(/:root\s*\{([^}]*)\}/);
    const light = { ...dark, ...parseBlock(/\[data-theme="light"\]\s*\{([^}]*)\}/) };
    const failures = [];
    const rows = [];
    for (const [name, tokens] of [['dark', dark], ['light', light]]) {
        for (const c of checksFor(tokens)) {
            const ok = c.value >= c.min;
            rows.push(`${name.padEnd(5)} ${c.fg.padEnd(10)} on ${c.bg.padEnd(10)} ${c.value.toFixed(2).padStart(6)} (min ${c.min}) ${ok ? 'ok' : 'FAIL'}`);
            if (!ok) failures.push(`${name}: ${c.fg} on ${c.bg} = ${c.value.toFixed(2)} < ${c.min}`);
        }
    }
    console.log(rows.join('\n'));
    assert.deepEqual(failures, [], 'contrast failures');
}

function testPrimaryAndRedFillsAreNotMisusedInComponents() {
    const dir = path.resolve(__dirname, '../../public/assets/js/components');
    const misuses = [];
    for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.js'))) {
        const source = fs.readFileSync(path.join(dir, file), 'utf8');
        // --primary as text colour fails 4.5:1 on the dark surface.
        if (/(^|[^-])color:\s*var\(--primary\)/m.test(source)) misuses.push(file + ': color var(--primary)');
        // A filled red button with white text must use --danger, not --red.
        if (/background:\s*var\(--red\)/.test(source)) misuses.push(file + ': background var(--red)');
        if (/blue/.test(source) && /^(RefundScreen|SettingsModal)\.js$/.test(file)) misuses.push(file + ': blue in refund/settings UI');
    }
    const css = CSS;
    if (!/accent-color:\s*var\(--primary\)/.test(css)) misuses.push('app.css: native inputs need accent-color: var(--primary)');
    assert.deepEqual(misuses, [], 'token misuse');
}

testConversionMatchesKnownColours();
testThemeTokensMeetContrast();
testPrimaryAndRedFillsAreNotMisusedInComponents();
console.log('themeContrast tests passed');
