// PRD I3: the administrator discards an incident PrestaShop will never accept (the
// sale stays in the TPV), sees how many operations go with it, keeps a trace of the
// recently discarded ones, and reads each error as a plain Spanish headline with the
// raw text below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const SOURCE = process.env.INCIDENTS_SCREEN_SOURCE || path.join(ROOT, 'public/assets/js/components/IncidentsScreen.js');

function ref(value) { return { value }; }
function computed(fn) { return { get value() { return fn(); } }; }

function load(api) {
    const source = fs.readFileSync(SOURCE, 'utf8');
    const context = {
        Vue: { ref, computed, reactive: (v) => v, watch() {}, onMounted(fn) { context.__mounted.push(fn); }, onUnmounted() {} },
        __mounted: [],
        ApiClient: api,
        Number, String, Math, JSON, Object, Array, Promise, Error, RegExp, console,
    };
    context.globalThis = context;
    vm.runInNewContext(`${source}\nglobalThis.__component = IncidentsScreen;`, context, { filename: 'IncidentsScreen.js' });
    return { component: context.__component, context };
}

async function mount(api) {
    const { component, context } = load(api);
    const emitted = [];
    const state = component.setup({}, { emit: (name, ...args) => emitted.push([name, ...args]) });
    for (const fn of context.__mounted) await fn();
    return { component, state, emitted };
}

const SALE = { id: 11, type: 'sale', order_id: 3, sequence: 1, reference: 'T26000006', attempts: 1, dependants: 2, waiting_behind: 2,
    last_error: 'PrestaShop rejected the order (HTTP 422): {"products.0.id_product":"Product not found"}', created_at: '2026-11-03 09:00:00' };
const CUSTOMER = { id: 20, type: 'customer', order_id: null, sequence: 1, attempts: 1, dependants: 0, waiting_behind: 0,
    last_error: 'HTTP 422: Validation error: "email"', created_at: '2026-11-03 09:00:00' };

function api(rows, discarded = []) {
    const calls = [];
    return {
        calls,
        incidentsList: async () => { calls.push(['list']); return { data: rows }; },
        incidentsDiscarded: async () => { calls.push(['discarded']); return { data: discarded }; },
        incidentDiscard: async (id, reason) => { calls.push(['discard', id, reason]); return { data: { discarded: [id] } }; },
        incidentRetry: async (id) => { calls.push(['retry', id]); return { data: { retried: [id] } }; },
        incidentRetryOrder: async () => ({}),
        ticketSalePdf() {}, refundPdf() {},
    };
}

function testHeadlinesInPlainSpanish() {
    const { component } = load(api([]));
    const state = component.setup({}, { emit() {} });
    const h = (type, last_error) => state.headline({ type, last_error });
    assert.equal(h('sale', SALE.last_error), 'El producto ya no existe en PrestaShop');
    assert.equal(h('customer', 'HTTP 422: Validation error: "email"'), 'PrestaShop rechazó el cliente: revisa sus datos');
    assert.equal(h('customer', 'PrestaShop rejected the customer (HTTP 400): bad'), 'PrestaShop rechazó el cliente: revisa sus datos');
    assert.equal(h('sale', 'HTTP 422: {"address.dni":"required"}'), 'Falta el DNI en la dirección del cliente');
    assert.equal(h('customer', 'HTTP 422: The DNI field is invalid'), 'Falta el DNI en la dirección del cliente');
    assert.equal(h('sale', 'PrestaShop rejected the order (HTTP 401): Unauthorized'), 'La clave de conexión con PrestaShop no tiene permiso');
    assert.equal(h('customer', 'HTTP 403: Forbidden'), 'La clave de conexión con PrestaShop no tiene permiso');
    assert.equal(h('sale', 'Failed 5 times in a row. Recording the PrestaShop answer failed: deadlock'), 'El TPV no pudo guardar la respuesta de PrestaShop');
    assert.equal(h('refund', 'HTTP 404: order not found'), 'PrestaShop rechazó la operación');
    assert.equal(h('sale', null), 'PrestaShop rechazó la operación');
    assert.equal(h('sale', 'HTTP 4031 bytes'), 'PrestaShop rechazó la operación', 'only the status code 403 counts');
}

function testTheRawErrorStaysBelowTheHeadlineInSmallMutedMonospace() {
    const { component } = load(api([]));
    assert.match(component.template, /\{\{ headline\(row\) \}\}/);
    const raw = component.template.match(/<div[^>]*>\{\{ row\.last_error \|\| 'Sin detalle del error' \}\}<\/div>/);
    assert.ok(raw, 'the raw error is still shown');
    assert.match(raw[0], /font-size:1[23]px/);
    assert.match(raw[0], /color:var\(--text3\)/);
    assert.match(raw[0], /IBM Plex Mono/);
}

async function testDiscardAsksFirstNamingWhatStaysAndWhatGoesWithIt() {
    const fake = api([SALE, CUSTOMER]);
    const { state, emitted, component } = await mount(fake);
    state.askDiscard(SALE);
    assert.equal(state.confirming.value.id, 11);
    assert.equal(state.confirmText.value,
        '¿Descartar esta incidencia? La venta queda en el TPV (caja y ticket no cambian) pero no se subirá a PrestaShop, y 2 operaciones que dependen de ella.');
    state.discardReason.value = '  Producto borrado ';
    await state.confirmDiscard();
    assert.deepEqual(fake.calls.filter((c) => c[0] === 'discard'), [['discard', 11, 'Producto borrado']]);
    assert.equal(state.confirming.value, null);
    assert.ok(emitted.some(([n]) => n === 'changed'), 'the indicator refreshes');
    assert.ok(fake.calls.filter((c) => c[0] === 'list').length >= 2, 'the list reloads');

    state.askDiscard(CUSTOMER);
    assert.equal(state.confirmText.value,
        '¿Descartar esta incidencia? El cliente queda en el TPV pero no se subirá a PrestaShop.');
    state.cancelDiscard();
    assert.equal(state.confirming.value, null);
    assert.equal(fake.calls.filter((c) => c[0] === 'discard').length, 1, 'cancel discards nothing');

    state.askDiscard({ ...SALE, id: 12, type: 'refund', dependants: 1 });
    assert.equal(state.confirmText.value,
        '¿Descartar esta incidencia? La devolución queda en el TPV (caja y abono no cambian) pero no se subirá a PrestaShop, y 1 operación que depende de ella.');

    assert.match(component.template, /Descartar/);
    assert.match(component.template, /class="tpv-btn tpv-btn-danger"[^>]*@click="confirmDiscard"|@click="confirmDiscard"[^>]*class="tpv-btn tpv-btn-danger"/);
    assert.match(component.template, /role="dialog"/);
    assert.match(component.template, /aria-modal="true"/);
}

async function testAnEmptyReasonIsSentAsNone() {
    const fake = api([SALE]);
    const { state } = await mount(fake);
    state.askDiscard(SALE);
    await state.confirmDiscard();
    assert.deepEqual(fake.calls.filter((c) => c[0] === 'discard'), [['discard', 11, null]]);
}

async function testADiscardOfARowNoLongerAnIncidentReloadsAndSaysSo() {
    const fake = api([SALE]);
    fake.incidentDiscard = async () => { throw new Error('HTTP 409: {"code":"not_incident"}'); };
    const { state } = await mount(fake);
    state.askDiscard(SALE);
    await state.confirmDiscard();
    assert.equal(state.message.value, 'Esa incidencia ya no está pendiente: se reintentó, se subió o se descartó');
    assert.equal(state.confirming.value, null);
}

async function testRecentlyDiscardedAreListedReadOnlyWithWhoWhenAndWhy() {
    const discarded = [{ id: 9, type: 'sale', reference: 'T26000002', discarded_by: 'Ana', discarded_at: '2026-11-03 12:40:00', discard_reason: 'Producto borrado', last_error: 'x' }];
    const fake = api([], discarded);
    const { state, component } = await mount(fake);
    assert.equal(state.showDiscarded.value, false, 'collapsed at first');
    await state.toggleDiscarded();
    assert.equal(state.showDiscarded.value, true);
    assert.deepEqual(state.discarded.value.map((r) => r.id), [9]);
    assert.ok(fake.calls.some((c) => c[0] === 'discarded'));
    assert.match(component.template, /Descartadas recientes/);
    assert.match(component.template, /row\.discarded_by/);
    assert.match(component.template, /row\.discarded_at/);
    assert.match(component.template, /row\.discard_reason/);
    assert.match(component.template, /aria-expanded/);
}

async function testRetryIsUnchanged() {
    const fake = api([SALE]);
    const { state } = await mount(fake);
    await state.retry(SALE);
    assert.deepEqual(fake.calls, [['list'], ['retry', 11], ['list']]);
    assert.equal(state.message.value, 'Se volverá a subir en el próximo minuto');
}

function testApiClientPostsTheDiscardAsJsonAndNeverQueuesIt() {
    const source = fs.readFileSync(path.join(ROOT, 'public/assets/js/api.js'), 'utf8');
    assert.match(source, /incidentDiscard: \(id, reason\) => request\('\/api\/incidents\/' \+ encodeURIComponent\(id\) \+ '\/discard', \{ method: 'POST', body: JSON\.stringify\(\{ reason: reason \|\| null \}\), queueOnNetworkFailure: false \}\)/);
    assert.match(source, /incidentsDiscarded: \(\) => request\('\/api\/incidents\/discarded', \{ method: 'GET' \}\)/);
    const page = fs.readFileSync(path.join(ROOT, 'templates/pos.html.twig'), 'utf8');
    assert.match(page, /IncidentsScreen\.js\?v=20261103-incident-discard-v1/);
}

(async () => {
    testHeadlinesInPlainSpanish();
    testTheRawErrorStaysBelowTheHeadlineInSmallMutedMonospace();
    await testDiscardAsksFirstNamingWhatStaysAndWhatGoesWithIt();
    await testAnEmptyReasonIsSentAsNone();
    await testADiscardOfARowNoLongerAnIncidentReloadsAndSaysSo();
    await testRecentlyDiscardedAreListedReadOnlyWithWhoWhenAndWhy();
    await testRetryIsUnchanged();
    testApiClientPostsTheDiscardAsJsonAndNeverQueuesIt();
    console.log('incidentDiscard: 8 tests OK');
})().catch((error) => { console.error(error); process.exitCode = 1; });
