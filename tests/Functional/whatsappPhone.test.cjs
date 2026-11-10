const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

// The one rule that turns what the cashier types into a WhatsApp number (wa.me digits).
const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/lib/phone.js'), 'utf8');
const context = { String, encodeURIComponent };
context.globalThis = context;
vm.runInNewContext(`${source}\nglobalThis.__phone = VenPosPhone;`, context, { filename: 'phone.js' });
const { normalize, whatsappUrl, display } = context.__phone;

test('a Spanish mobile or landline of 9 digits gets +34', () => {
    assert.equal(normalize('600111222'), '34600111222');
    assert.equal(normalize('912 34 56 78'), '34912345678');
    assert.equal(normalize('(600) 11.12-22'), '34600111222');
    assert.equal(normalize('711222333'), '34711222333');
    assert.equal(normalize('811222333'), '34811222333');
});

test('a leading + or 00 keeps the country code', () => {
    assert.equal(normalize('+34 600 111 222'), '34600111222');
    assert.equal(normalize('0034600111222'), '34600111222');
    assert.equal(normalize('+44 7700 900123'), '447700900123');
    assert.equal(normalize('00 351 912 345 678'), '351912345678');
});

test('other numbers need 8 to 15 digits with their country code', () => {
    assert.equal(normalize('447700900123'), '447700900123');
    assert.equal(normalize('34600111222'), '34600111222');
    assert.equal(normalize('12345678'), '12345678');
    assert.equal(normalize('1234567'), null, 'too short');
    assert.equal(normalize('+1234567890123456'), null, 'too long');
    assert.equal(normalize('500111222'), '500111222', '9 digits not starting with 6-9 are taken as already international');
});

test('empty or non-numeric input is not a phone', () => {
    for (const bad of ['', '   ', null, undefined, 'ana', '600-ABC-222', '+', '00', '++34600111222', '6001112a2']) {
        assert.equal(normalize(bad), null, String(bad));
    }
});

test('a shop phone with two numbers or a note gives its first usable number, a mobile first', () => {
    assert.equal(normalize('615658290 / 944223344'), '34615658290');
    assert.equal(normalize('944223344 / 615658290'), '34615658290', 'the mobile, even second');
    assert.equal(normalize('+34 615 65 82 90 (móvil)'), '34615658290');
    assert.equal(normalize('944 22 33 44, 615 65 82 90'), '34615658290');
    assert.equal(normalize('615658290 y 655111222'), '34615658290');
    assert.equal(normalize('Tel: 615658290'), null, 'letters outside a note are not a phone');
});

test('the field shows the number with its country code', () => {
    assert.equal(display('34615658290'), '+34 615 65 82 90');
    assert.equal(display('447700900123'), '+447700900123');
    assert.equal(display(null), '');
});

test('the wa.me link carries the digits and the encoded message', () => {
    assert.equal(
        whatsappUrl('34600111222', 'Hola, aquí tienes tu ticket R1 de Nada Más: https://t.es/d/a.b?x=1&y=2'),
        'https://wa.me/34600111222?text=' + encodeURIComponent('Hola, aquí tienes tu ticket R1 de Nada Más: https://t.es/d/a.b?x=1&y=2'),
    );
});
