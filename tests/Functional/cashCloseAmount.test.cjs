const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// «Cerrar caja»: the counted cash is entered per coin and note, or as one total amount.

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/components/CashCloseScreen.js'), 'utf8');

function screen() {
    const context = {
        Vue: { reactive: (v) => v, ref: (value) => ({ value }), computed: (fn) => ({ get value() { return fn(); } }) },
        useSession: { printCashReport: async () => 'printed' },
        Number, String, Math, JSON, Promise, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__screen = CashCloseScreen;`, context, { filename: 'CashCloseScreen.js' });
    const emitted = [];
    const state = context.__screen.setup({ session: { expected: 100 }, parkedCount: 0, outbox: null }, { emit: (...args) => emitted.push(args) });

    return { state, emitted };
}

test('the screen offers both ways of counting', () => {
    assert.ok(source.includes('Por monedas y billetes'));
    assert.ok(source.includes('Importe total'));
});

test('counting per coin and note works as before', () => {
    const { state } = screen();
    assert.equal(state.countMode.value, 'denoms');
    state.counted[50] = 2;
    state.counted[0.5] = 3;
    assert.equal(state.total.value, 101.5);
    assert.equal(state.diff.value, 1.5);
});

test('a typed total accepts comma or dot and is what the close sends', () => {
    const { state, emitted } = screen();
    state.counted[50] = 2;
    state.setCountMode('amount');
    state.amountInput.value = '123,45';
    assert.equal(state.total.value, 123.45);
    assert.equal(state.amountError.value, '');
    state.amountInput.value = '99.5';
    assert.equal(state.total.value, 99.5);
    assert.equal(Number(state.diff.value.toFixed(2)), -0.5);
    state.confirmClose();
    assert.deepEqual(emitted.at(-1), ['close', '99.50']);
});

test('an unusable total is explained and the close is not sent', () => {
    const { state, emitted } = screen();
    state.setCountMode('amount');
    for (const bad of ['', 'abc', '12,345', '-5']) {
        state.amountInput.value = bad;
        assert.ok(state.amountError.value !== '', bad);
        state.confirmClose();
    }
    assert.equal(emitted.length, 0);
    assert.equal(state.amountError.value, 'Escribe un importe válido, por ejemplo 245,50.');
});
