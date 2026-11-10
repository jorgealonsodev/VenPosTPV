/**
 * ReportsScreen — «Informes» (docs/prd/informes-ventas.md I2, I3, I5), administrators
 * only: the sale screen shows its button to admins and the server answers 403 to anyone
 * else. Tabs pick the report; the date, till and (payments only) cash session filters
 * are shared, so they stay when the tab changes. «Por producto» also has its list
 * (sold or «Sin ventas»), order, grouping and pages of 50 («Anterior» / «Siguiente»;
 * the API pages by cursor, so the screen keeps the cursor of each page shown, like
 * PrintHistoryPanel). «Descargar CSV» repeats the same request with format=csv (every
 * row, not only the page). Emits: back.
 */

/** The reports with a tab, in order. A new report is one more entry here plus its table. */
const REPORT_TABS = [
    { type: 'summary', label: 'Resumen' },
    { type: 'daily', label: 'Por día' },
    { type: 'hourly', label: 'Por hora' },
    { type: 'products', label: 'Por producto' },
    { type: 'categories', label: 'Por categoría' },
    { type: 'cashiers', label: 'Por cajero' },
    { type: 'payments', label: 'Por forma de pago' },
    { type: 'vat', label: 'IVA' },
];

/** Pure helpers of the screen (dates, formats, query, chart); tested in a VM. */
const ReportsLogic = (function () {
    const MAX_DAYS = 366;
    const DAY_MS = 86400000;

    const PRESETS = [
        { value: 'today', label: 'Hoy' },
        { value: 'yesterday', label: 'Ayer' },
        { value: 'week', label: 'Esta semana' },
        { value: 'month', label: 'Este mes' },
        { value: 'previous_month', label: 'Mes anterior' },
        { value: 'year', label: 'Este año' },
        { value: 'custom', label: 'Personalizado' },
    ];

    /** Same order and labels as the summary CSV (ReportCsv::SUMMARY_ROWS). */
    const SUMMARY_ROWS = [
        { key: 'gross_before_discounts', label: 'Importe antes de descuentos', kind: 'amount' },
        { key: 'discounts', label: 'Descuentos', kind: 'amount' },
        { key: 'gross_sales', label: 'Ventas brutas', kind: 'amount' },
        { key: 'refunds', label: 'Devoluciones', kind: 'amount' },
        { key: 'net_sales', label: 'Ventas netas', kind: 'amount', strong: true },
        { key: 'tickets', label: 'Tickets', kind: 'count' },
        { key: 'average_ticket', label: 'Ticket medio', kind: 'amount' },
        { key: 'units_sold', label: 'Unidades vendidas', kind: 'count' },
        { key: 'units_refunded', label: 'Unidades devueltas', kind: 'count' },
        { key: 'units_net', label: 'Unidades netas', kind: 'count' },
        { key: 'exchange_credit', label: 'Crédito de cambio', kind: 'amount' },
        { key: 'online_refunds', label: 'Devoluciones online (informativo)', kind: 'amount' },
    ];

    const PAYMENT_ROWS = [
        { key: 'sales_cash', label: 'Ventas en efectivo' },
        { key: 'sales_card', label: 'Ventas con tarjeta' },
        { key: 'sales_transfer', label: 'Ventas por transferencia' },
        { key: 'sales_transfer_pending', label: 'Transferencias pendientes de pago (incluidas en ventas por transferencia)' },
        { key: 'exchange_credit', label: 'Crédito de cambio' },
        { key: 'sales_total', label: 'Total ventas', strong: true },
        { key: 'refunds_cash', label: 'Devoluciones en efectivo' },
        { key: 'refunds_card', label: 'Devoluciones con tarjeta' },
        { key: 'refunds_transfer', label: 'Devoluciones por transferencia' },
        { key: 'refunds_voucher', label: 'Devoluciones con vale' },
        { key: 'refunds_total', label: 'Total devoluciones', strong: true },
        { key: 'exchange_vouchers', label: 'Vales de cambios cerrados (incluidos en devoluciones con vale)' },
        { key: 'cash_in_total', label: 'Entradas de caja' },
        { key: 'cash_out_total', label: 'Salidas de caja' },
        { key: 'net_total', label: 'Neto cobrado', strong: true },
    ];

    const METHOD_LABELS = {
        cash: 'Tickets en efectivo',
        card: 'Tickets con tarjeta',
        mixed: 'Tickets mixtos',
        transfer: 'Tickets por transferencia',
        exchange: 'Tickets pagados con crédito de cambio',
    };

    const EMPTY_TEXT = 'No hay ventas en este periodo';
    const UNSOLD_EMPTY_TEXT = 'Todos los productos activos del catálogo tienen ventas en este periodo';

    /** «Por producto»: its two lists, orders and groupings (defaults first, as the API). */
    const PRODUCT_VIEWS = [{ value: 'sold', label: 'Vendidos' }, { value: 'unsold', label: 'Sin ventas' }];
    const PRODUCT_SORTS = [{ value: 'amount', label: 'Importe' }, { value: 'units', label: 'Unidades' }];
    const PRODUCT_GROUPS = [{ value: 'combination', label: 'Por combinación' }, { value: 'product', label: 'Por producto' }];
    const PRODUCT_DEFAULTS = { view: 'sold', sort: 'amount', group: 'combination' };

    const pad = (n) => String(n).padStart(2, '0');

    /** A local date as AAAA-MM-DD (the shop's day, never UTC). */
    function ymd(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function addDays(date, days) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
    }

    /** {from, to} of a preset for the given today; null for «Personalizado». */
    function presetRange(preset, today) {
        const d = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const y = d.getFullYear();
        const m = d.getMonth();
        switch (preset) {
            case 'today': return { from: ymd(d), to: ymd(d) };
            case 'yesterday': { const p = addDays(d, -1); return { from: ymd(p), to: ymd(p) }; }
            case 'week': {
                // Monday to Sunday: getDay() is 0 on Sunday.
                const monday = addDays(d, -((d.getDay() + 6) % 7));
                return { from: ymd(monday), to: ymd(addDays(monday, 6)) };
            }
            case 'month': return { from: ymd(new Date(y, m, 1)), to: ymd(new Date(y, m + 1, 0)) };
            case 'previous_month': return { from: ymd(new Date(y, m - 1, 1)), to: ymd(new Date(y, m, 0)) };
            case 'year': return { from: ymd(new Date(y, 0, 1)), to: ymd(new Date(y, 11, 31)) };
            default: return null;
        }
    }

    function parseYmd(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        return match ? Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
    }

    /** The server's own messages (ReportFilter), so the screen and the API agree. */
    function rangeError(from, to) {
        const a = parseYmd(from);
        const b = parseYmd(to);
        if (a === null || b === null) return 'Indica las fechas desde y hasta (AAAA-MM-DD)';
        if (a > b) return 'La fecha desde no puede ser posterior a la fecha hasta';
        if (Math.round((b - a) / DAY_MS) + 1 > MAX_DAYS) return 'El rango máximo es de ' + MAX_DAYS + ' días';
        return '';
    }

    function group(digits) {
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /** 1234.5 → «1.234,50 €» (Intl in es-ES leaves 4-digit numbers ungrouped). */
    function formatAmount(value) {
        const n = Number(value) || 0;
        const cents = Math.round(Math.abs(n) * 100);
        const sign = n < 0 && cents > 0 ? '-' : '';
        return sign + group(String(Math.floor(cents / 100))) + ',' + pad(cents % 100) + ' €';
    }

    function formatCount(value) {
        const n = Math.round(Number(value) || 0);
        return (n < 0 ? '-' : '') + group(String(Math.abs(n)));
    }

    function formatPercent(value) {
        if (value === null || value === undefined) return '—';
        const n = Number(value);
        return (n > 0 ? '+' : '') + n.toFixed(1).replace('.', ',') + ' %';
    }

    /** AAAA-MM-DD (or the start of a datetime) → dd/mm/aaaa. */
    function formatDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return match ? match[3] + '/' + match[2] + '/' + match[1] : String(value || '');
    }

    function formatTime(value) {
        const match = /[T ](\d{2}:\d{2})/.exec(String(value || ''));
        return match ? match[1] : '';
    }

    /** 9 → «09:00». */
    function formatHour(hour) {
        return pad(Number(hour) || 0) + ':00';
    }

    /** 21 → «21 %», 5.26 → «5,26 %». */
    function formatRate(rate) {
        const n = Number(rate) || 0;
        return (Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',')) + ' %';
    }

    /** The name of a «Por producto» row; free items are grouped by name. */
    function productName(row) {
        return row && row.free_item ? 'Artículo libre: ' + row.name : String((row && row.name) || '');
    }

    /** True when the report is filtered by a cash session (payments only). */
    function bySession(type, filters) {
        return type === 'payments' && !!filters.sessionId;
    }

    /**
     * The query string of a report: by session, or by dates and till. «Por producto»
     * adds its options when they are not the default, and the page cursor (never in
     * a CSV, which has every row).
     */
    function query(type, filters, format, products) {
        const parts = [];
        if (bySession(type, filters)) {
            parts.push('session_id=' + encodeURIComponent(filters.sessionId));
        } else {
            parts.push('from=' + encodeURIComponent(filters.from), 'to=' + encodeURIComponent(filters.to));
            if (filters.terminalId) parts.push('terminal_id=' + encodeURIComponent(filters.terminalId));
        }
        if (type === 'products' && products) {
            ['view', 'sort', 'group'].forEach((key) => {
                if (products[key] && products[key] !== PRODUCT_DEFAULTS[key]) parts.push(key + '=' + encodeURIComponent(products[key]));
            });
            if (format !== 'csv' && products.cursor) parts.push('cursor=' + encodeURIComponent(products.cursor));
        }
        if (format === 'csv') parts.push('format=csv');
        return parts.join('&');
    }

    /** Bars of the daily chart: height in % of the largest day, negative days apart. */
    function bars(days) {
        const list = Array.isArray(days) ? days : [];
        const max = list.reduce((acc, d) => Math.max(acc, Math.abs(Number(d.net_sales) || 0)), 0);
        return list.map((d) => {
            const net = Number(d.net_sales) || 0;
            return { date: d.date, net, negative: net < 0, percent: max > 0 ? Math.round(Math.abs(net) / max * 100) : 0 };
        });
    }

    /** Nothing happened in the period: no tickets, no refunds, no money moved. */
    function isEmpty(type, data) {
        if (!data) return false;
        const zero = (v) => !(Number(v) || 0);
        if (type === 'summary') {
            return zero(data.tickets) && zero(data.refunds) && zero(data.exchange_credit) && zero(data.online_refunds);
        }
        if (type === 'daily') {
            const t = data.totals || {};
            return zero(t.tickets) && zero(t.refunds);
        }
        if (type === 'payments') {
            const tickets = Object.values(data.tickets_by_method || {}).reduce((a, n) => a + (Number(n) || 0), 0);
            return tickets === 0 && ['sales_total', 'refunds_total', 'exchange_credit', 'cash_in_total', 'cash_out_total'].every((k) => zero(data[k]));
        }
        if (type === 'hourly') return zero((data.totals || {}).tickets) && zero((data.totals || {}).gross_sales);
        // «Sin ventas» is a list of products, not of sales: it has its own empty text.
        if (type === 'products') return data.view !== 'unsold' && !(data.rows || []).length;
        if (type === 'categories' || type === 'cashiers' || type === 'vat') return !(data.rows || []).length;
        return false;
    }

    /** The yellow notices of a response (approximate lines, sales without lines). */
    function notices(response) {
        const list = [];
        if (!response) return list;
        const approximate = Number(response.approximate_amount) || 0;
        const missing = Number(response.missing_lines) || 0;
        if (approximate > 0) {
            list.push(formatAmount(approximate) + ' de este informe proceden de ventas antiguas completadas con los datos actuales del catálogo: son importes aproximados.');
        }
        if (missing > 0) {
            list.push(missing === 1
                ? '1 venta del periodo todavía no tiene líneas: cuenta como ticket, pero no en importes ni unidades (falta ejecutar tpv:sales:backfill-lines en el servidor).'
                : missing + ' ventas del periodo todavía no tienen líneas: cuentan como tickets, pero no en importes ni unidades (falta ejecutar tpv:sales:backfill-lines en el servidor).');
        }
        return list;
    }

    /** The message of a failed request: the server's own {error} when it sent one. */
    function errorText(err) {
        if (err && err.body && typeof err.body.error === 'string' && err.body.error) return err.body.error;
        if (err && err.status === 403) return 'Solo un administrador puede ver los informes';
        if (err && err.status) return 'No se ha podido cargar el informe (error ' + err.status + ').';
        return 'No se ha podido cargar el informe. Comprueba la conexión y vuelve a intentarlo.';
    }

    /** filename="…" of a Content-Disposition header, or null. */
    function fileName(disposition) {
        const match = /filename="?([^";]+)"?/.exec(String(disposition || ''));
        return match ? match[1] : null;
    }

    return {
        MAX_DAYS, PRESETS, SUMMARY_ROWS, PAYMENT_ROWS, METHOD_LABELS, EMPTY_TEXT, UNSOLD_EMPTY_TEXT,
        PRODUCT_VIEWS, PRODUCT_SORTS, PRODUCT_GROUPS, PRODUCT_DEFAULTS,
        now: () => new Date(),
        ymd, presetRange, rangeError, formatAmount, formatCount, formatPercent, formatDate, formatTime, formatHour, formatRate, productName,
        bySession, query, bars, isEmpty, notices, errorText, fileName,
    };
})();

const ReportsScreen = {
    emits: ['back'],
    setup(props, { emit }) {
        const L = ReportsLogic;
        const tabs = REPORT_TABS;
        const activeType = Vue.ref(REPORT_TABS[0].type);
        const initial = L.presetRange('today', L.now());
        // Shared by every tab: changing the tab never resets them.
        const filters = Vue.reactive({ preset: 'today', from: initial.from, to: initial.to, terminalId: '', sessionId: '' });
        const tills = Vue.ref([]);
        const sessions = Vue.ref([]);
        let sessionsLoaded = false;
        const loading = Vue.ref(false);
        const error = Vue.ref('');
        // {type, body}: a response belongs to the tab that asked for it.
        const response = Vue.ref(null);
        const downloading = Vue.ref(false);
        const csvError = Vue.ref('');
        let requestSeq = 0;
        // «Por producto»: its options, kept when the tab changes, and the cursor that
        // opened each page shown so far (page 1 starts with none).
        const productOptions = Vue.reactive({ ...L.PRODUCT_DEFAULTS });
        const productCursors = Vue.ref([null]);
        const productQuery = () => ({ ...productOptions, cursor: productCursors.value[productCursors.value.length - 1] });

        const data = Vue.computed(() => (response.value && response.value.type === activeType.value ? response.value.body.data : null));
        const isEmpty = Vue.computed(() => !loading.value && !error.value && L.isEmpty(activeType.value, data.value));
        const notices = Vue.computed(() => (response.value && response.value.type === activeType.value ? L.notices(response.value.body) : []));
        const chart = Vue.computed(() => {
            const list = L.bars(data.value && data.value.days);
            return { bars: list, hasNegative: list.some((b) => b.negative), showLabels: list.length <= 31 };
        });
        const sessionMode = Vue.computed(() => L.bySession(activeType.value, filters));
        const rangeLabel = Vue.computed(() => (filters.from === filters.to
            ? L.formatDate(filters.from)
            : 'Del ' + L.formatDate(filters.from) + ' al ' + L.formatDate(filters.to)));
        const methodRows = Vue.computed(() => {
            const methods = (data.value && data.value.tickets_by_method) || {};
            return Object.keys(methods).map((key) => ({ key, label: L.METHOD_LABELS[key] || 'Tickets ' + key, count: methods[key] }));
        });

        const productPage = Vue.computed(() => {
            const page = (data.value && data.value.page) || {};
            return {
                number: productCursors.value.length,
                hasPrev: productCursors.value.length > 1,
                hasNext: page.next_cursor !== null && page.next_cursor !== undefined,
                total: Number(page.total_rows) || 0,
            };
        });

        /** Loads the report from its first page (a filter or option changed). */
        function load() {
            productCursors.value = [null];
            return fetchReport();
        }

        /** Loads the report again on the page it is on (also «Reintentar»). */
        async function fetchReport() {
            const type = activeType.value;
            csvError.value = '';
            if (!L.bySession(type, filters)) {
                const invalid = L.rangeError(filters.from, filters.to);
                if (invalid) {
                    error.value = invalid;
                    response.value = null;
                    return;
                }
            }
            const seq = ++requestSeq;
            loading.value = true;
            error.value = '';
            try {
                const body = await ApiClient.get('/api/reports/' + type + '?' + L.query(type, filters, 'json', productQuery()));
                if (seq !== requestSeq) return;
                response.value = { type, body: body || { data: null } };
            } catch (err) {
                if (seq !== requestSeq) return;
                response.value = null;
                error.value = L.errorText(err);
            } finally {
                if (seq === requestSeq) loading.value = false;
            }
        }

        async function loadTills() {
            try {
                const resp = await ApiClient.get('/api/device');
                tills.value = (resp && resp.data && resp.data.tills) || [];
            } catch (e) {
                tills.value = [];
            }
        }

        /** The last closed cash sessions (the Z list), for «Por forma de pago». */
        async function loadSessions() {
            if (sessionsLoaded) return;
            try {
                const resp = await ApiClient.get('/api/cash/z');
                sessions.value = (resp && Array.isArray(resp.data)) ? resp.data : [];
                sessionsLoaded = true;
            } catch (e) {
                sessions.value = [];
            }
        }

        async function selectTab(type) {
            activeType.value = type;
            if (type === 'payments') await loadSessions();
            await load();
        }

        async function setPreset(preset) {
            filters.preset = preset;
            const range = L.presetRange(preset, L.now());
            if (!range) return; // «Personalizado»: the dates are picked, then «Aplicar».
            filters.from = range.from;
            filters.to = range.to;
            await load();
        }

        function retry() {
            return fetchReport();
        }

        async function nextPage() {
            if (!productPage.value.hasNext || loading.value) return;
            productCursors.value = [...productCursors.value, String(data.value.page.next_cursor)];
            await fetchReport();
        }

        async function prevPage() {
            if (!productPage.value.hasPrev || loading.value) return;
            productCursors.value = productCursors.value.slice(0, -1);
            await fetchReport();
        }

        /** Changes a «Por producto» option (view, sort or group) and goes back to page 1. */
        function setProductOption(key, value) {
            productOptions[key] = value;
            return load();
        }

        function sessionLabel(s) {
            const opened = L.formatDate(s.opened_at) + ' ' + L.formatTime(s.opened_at);
            const closed = L.formatDate(s.closed_at) === L.formatDate(s.opened_at)
                ? L.formatTime(s.closed_at)
                : L.formatDate(s.closed_at) + ' ' + L.formatTime(s.closed_at);
            return (s.terminal_name || 'Caja') + ' · ' + opened + ' – ' + closed;
        }

        function cell(row, value) {
            return row.kind === 'count' ? L.formatCount(value) : L.formatAmount(value);
        }

        function difference(row, value) {
            const text = cell(row, value);
            return (Number(value) || 0) > 0 ? '+' + text : text;
        }

        /** Same request with format=csv, sent with the session cookie and saved as a file. */
        async function downloadCsv() {
            const type = activeType.value;
            csvError.value = '';
            if (!L.bySession(type, filters)) {
                const invalid = L.rangeError(filters.from, filters.to);
                if (invalid) {
                    csvError.value = invalid;
                    return;
                }
            }
            const base = window.TPV_BASE || '';
            downloading.value = true;
            try {
                const res = await fetch(base + '/api/reports/' + type + '?' + L.query(type, filters, 'csv', productOptions), {
                    credentials: 'same-origin',
                    headers: { Accept: 'text/csv' },
                });
                if (res.status === 401) {
                    window.location.href = base + '/app/login';
                    return;
                }
                if (!res.ok) {
                    let body = null;
                    try { body = JSON.parse(await res.text()); } catch (e) { body = null; }
                    csvError.value = (body && body.error) || 'No se ha podido descargar el CSV (error ' + res.status + ').';
                    return;
                }
                const blob = await res.blob();
                const name = L.fileName(res.headers.get('Content-Disposition')) || 'informe-' + type + '.csv';
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = name;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (e) {
                csvError.value = 'No se ha podido descargar el CSV. Comprueba la conexión y vuelve a intentarlo.';
            } finally {
                downloading.value = false;
            }
        }

        Vue.onMounted(async () => {
            await loadTills();
            await load();
        });

        return {
            ReportsLogic: L, tabs, activeType, filters, tills, sessions, loading, error, data, isEmpty, notices, chart,
            sessionMode, rangeLabel, methodRows, downloading, csvError, productOptions, productPage,
            load, retry, selectTab, setPreset, downloadCsv, sessionLabel, cell, difference, nextPage, prevPage, setProductOption,
            formatAmount: L.formatAmount, formatCount: L.formatCount, formatPercent: L.formatPercent, formatDate: L.formatDate,
            formatHour: L.formatHour, formatRate: L.formatRate, productName: L.productName,
            back: () => emit('back'),
        };
    },
    template: `
        <div class="reports-screen">
            <header class="reports-header">
                <button class="tpv-btn" @click="back"><icon name="arrow_back" :size="18" /> Volver</button>
                <h1 class="reports-title">Informes</h1>
                <button class="tpv-btn tpv-btn-primary" style="margin-left:auto;" @click="downloadCsv" :disabled="downloading || loading || !!error">
                    {{ downloading ? 'Descargando…' : 'Descargar CSV' }}
                </button>
            </header>

            <div class="reports-body">
                <div class="reports-tabs" role="tablist" aria-label="Informe">
                    <button v-for="tab in tabs" :key="tab.type" role="tab" class="reports-tab"
                        :class="{ active: activeType === tab.type }" :aria-selected="activeType === tab.type ? 'true' : 'false'"
                        @click="selectTab(tab.type)">{{ tab.label }}</button>
                </div>

                <div class="reports-filters">
                    <div class="reports-presets" role="group" aria-label="Periodo">
                        <button v-for="p in ReportsLogic.PRESETS" :key="p.value" class="reports-chip"
                            :class="{ active: filters.preset === p.value }" :aria-pressed="filters.preset === p.value ? 'true' : 'false'"
                            :disabled="sessionMode" @click="setPreset(p.value)">{{ p.label }}</button>
                    </div>
                    <div v-if="filters.preset === 'custom' && !sessionMode" class="reports-custom">
                        <label>Desde <input type="date" class="tpv-input" v-model="filters.from" /></label>
                        <label>Hasta <input type="date" class="tpv-input" v-model="filters.to" /></label>
                        <button class="tpv-btn" @click="load">Aplicar</button>
                        <span class="reports-hint">Máximo {{ ReportsLogic.MAX_DAYS }} días</span>
                    </div>
                    <div class="reports-selects">
                        <label>Caja
                            <select class="tpv-input" v-model="filters.terminalId" :disabled="sessionMode" @change="load">
                                <option value="">Todas las cajas</option>
                                <option v-for="t in tills" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
                            </select>
                        </label>
                        <label v-if="activeType === 'payments'">Sesión de caja
                            <select class="tpv-input" v-model="filters.sessionId" @change="load">
                                <option value="">Por fechas</option>
                                <option v-for="s in sessions" :key="s.session_id" :value="String(s.session_id)">{{ sessionLabel(s) }}</option>
                            </select>
                        </label>
                        <span class="reports-range">{{ sessionMode ? 'Por sesión de caja: no se aplican las fechas ni la caja' : rangeLabel }}</span>
                    </div>
                </div>

                <div v-if="activeType === 'products'" class="reports-options">
                    <div class="reports-presets" role="group" aria-label="Lista">
                        <button v-for="v in ReportsLogic.PRODUCT_VIEWS" :key="v.value" class="reports-chip"
                            :class="{ active: productOptions.view === v.value }" :aria-pressed="productOptions.view === v.value ? 'true' : 'false'"
                            @click="setProductOption('view', v.value)">{{ v.label }}</button>
                    </div>
                    <div v-if="productOptions.view === 'sold'" class="reports-selects">
                        <label>Ordenar por
                            <select class="tpv-input" :value="productOptions.sort" @change="setProductOption('sort', $event.target.value)">
                                <option v-for="o in ReportsLogic.PRODUCT_SORTS" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </label>
                        <label>Agrupar
                            <select class="tpv-input" :value="productOptions.group" @change="setProductOption('group', $event.target.value)">
                                <option v-for="o in ReportsLogic.PRODUCT_GROUPS" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div v-if="csvError" class="tpv-banner tpv-banner-error" role="alert"><span>{{ csvError }}</span></div>

                <div v-if="loading" class="reports-state" role="status" aria-live="polite">Cargando informe…</div>
                <div v-else-if="error" class="tpv-banner tpv-banner-error reports-error" role="alert">
                    <span>{{ error }}</span>
                    <button class="tpv-btn" @click="retry">&#8635; Reintentar</button>
                </div>
                <template v-else-if="data">
                    <div v-for="(n, i) in notices" :key="i" class="tpv-banner tpv-banner-warn" role="status"><span>{{ n }}</span></div>
                    <div v-if="isEmpty" class="reports-state">{{ ReportsLogic.EMPTY_TEXT }}</div>

                    <!-- Resumen, with the previous period of the same length -->
                    <template v-else-if="activeType === 'summary'">
                        <div v-if="data.pending_upload && (data.pending_upload.sales || data.pending_upload.refunds)" class="tpv-banner tpv-banner-info" role="status">
                            <span>Pendientes de subir a PrestaShop: {{ data.pending_upload.sales }} ventas y {{ data.pending_upload.refunds }} devoluciones. Ya cuentan en el informe.</span>
                        </div>
                        <table class="reports-table">
                            <thead><tr>
                                <th>Concepto</th>
                                <th class="num">Este periodo</th>
                                <th class="num">Periodo anterior<br><span class="reports-sub">{{ data.previous.from === data.previous.to ? formatDate(data.previous.from) : formatDate(data.previous.from) + ' – ' + formatDate(data.previous.to) }}</span></th>
                                <th class="num">Diferencia</th>
                                <th class="num">%</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in ReportsLogic.SUMMARY_ROWS" :key="row.key" :class="{ strong: row.strong }">
                                    <td>{{ row.label }}</td>
                                    <td class="num">{{ cell(row, data[row.key]) }}</td>
                                    <td class="num muted">{{ cell(row, data.previous[row.key]) }}</td>
                                    <td class="num">{{ difference(row, data.comparison[row.key] && data.comparison[row.key].difference) }}</td>
                                    <td class="num">{{ formatPercent(data.comparison[row.key] && data.comparison[row.key].percent) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <!-- Por día: bar chart of net sales, then the table -->
                    <template v-else-if="activeType === 'daily'">
                        <div class="reports-chart" role="img" aria-label="Ventas netas por día">
                            <div v-for="bar in chart.bars" :key="bar.date" class="reports-bar-col" :title="formatDate(bar.date) + ': ' + formatAmount(bar.net)">
                                <div class="reports-bar-pos"><div v-if="!bar.negative && bar.percent > 0" class="reports-bar" :style="{ height: bar.percent + '%' }"></div></div>
                                <div v-if="chart.hasNegative" class="reports-bar-neg"><div v-if="bar.negative" class="reports-bar negative" :style="{ height: bar.percent + '%' }"></div></div>
                                <div v-if="chart.showLabels" class="reports-bar-label">{{ bar.date.slice(8, 10) }}</div>
                            </div>
                        </div>
                        <table class="reports-table">
                            <thead><tr>
                                <th>Fecha</th><th class="num">Tickets</th><th class="num">Unidades</th>
                                <th class="num">Ventas brutas</th><th class="num">Devoluciones</th><th class="num">Ventas netas</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="day in data.days" :key="day.date">
                                    <td>{{ formatDate(day.date) }}</td>
                                    <td class="num">{{ formatCount(day.tickets) }}</td>
                                    <td class="num">{{ formatCount(day.units) }}</td>
                                    <td class="num">{{ formatAmount(day.gross_sales) }}</td>
                                    <td class="num">{{ formatAmount(day.refunds) }}</td>
                                    <td class="num" :class="{ negative: day.net_sales < 0 }">{{ formatAmount(day.net_sales) }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="strong">
                                <td>Total</td>
                                <td class="num">{{ formatCount(data.totals.tickets) }}</td>
                                <td class="num">{{ formatCount(data.totals.units) }}</td>
                                <td class="num">{{ formatAmount(data.totals.gross_sales) }}</td>
                                <td class="num">{{ formatAmount(data.totals.refunds) }}</td>
                                <td class="num">{{ formatAmount(data.totals.net_sales) }}</td>
                            </tr></tfoot>
                        </table>
                    </template>

                    <!-- Por hora: 24 slots, every day of the range added up -->
                    <template v-else-if="activeType === 'hourly'">
                        <table class="reports-table">
                            <thead><tr><th>Hora</th><th class="num">Tickets</th><th class="num">Ventas brutas</th></tr></thead>
                            <tbody>
                                <tr v-for="h in data.hours" :key="h.hour" :class="{ muted: !h.tickets }">
                                    <td>{{ formatHour(h.hour) }}</td>
                                    <td class="num">{{ formatCount(h.tickets) }}</td>
                                    <td class="num">{{ formatAmount(h.gross_sales) }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="strong">
                                <td>Total</td>
                                <td class="num">{{ formatCount(data.totals.tickets) }}</td>
                                <td class="num">{{ formatAmount(data.totals.gross_sales) }}</td>
                            </tr></tfoot>
                        </table>
                    </template>

                    <!-- Por producto: sold (with order, grouping and pages) or «Sin ventas» -->
                    <template v-else-if="activeType === 'products' && data.view === 'unsold'">
                        <div v-if="!data.rows.length" class="reports-state">{{ ReportsLogic.UNSOLD_EMPTY_TEXT }}</div>
                        <table v-else class="reports-table">
                            <thead><tr><th>Referencia</th><th>Nombre</th><th>Categoría</th></tr></thead>
                            <tbody>
                                <tr v-for="p in data.rows" :key="p.product_id">
                                    <td>{{ p.reference || '—' }}</td>
                                    <td>{{ p.name }}</td>
                                    <td>{{ p.category_name || 'Sin categoría' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                    <template v-else-if="activeType === 'products'">
                        <table class="reports-table">
                            <thead><tr>
                                <th>Referencia</th><th>Nombre</th>
                                <th class="num">Vendidas</th><th class="num">Devueltas</th><th class="num">Netas</th>
                                <th class="num">Ventas</th><th class="num">Devoluciones</th><th class="num">Importe neto</th><th class="num">%</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in data.rows" :key="(row.product_id || 'libre') + ':' + (row.id_product_attribute === null ? '' : row.id_product_attribute) + ':' + row.name">
                                    <td>{{ row.reference || '—' }}</td>
                                    <td>{{ productName(row) }}</td>
                                    <td class="num">{{ formatCount(row.units_sold) }}</td>
                                    <td class="num">{{ formatCount(row.units_refunded) }}</td>
                                    <td class="num" :class="{ negative: row.units_net < 0 }">{{ formatCount(row.units_net) }}</td>
                                    <td class="num">{{ formatAmount(row.sales_amount) }}</td>
                                    <td class="num">{{ formatAmount(row.refunds_amount) }}</td>
                                    <td class="num" :class="{ negative: row.net_amount < 0 }">{{ formatAmount(row.net_amount) }}</td>
                                    <td class="num">{{ row.percent === null ? '—' : formatPercent(row.percent).replace('+', '') }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="strong">
                                <td>Total</td><td></td>
                                <td class="num">{{ formatCount(data.totals.units_sold) }}</td>
                                <td class="num">{{ formatCount(data.totals.units_refunded) }}</td>
                                <td class="num">{{ formatCount(data.totals.units_net) }}</td>
                                <td class="num">{{ formatAmount(data.totals.sales_amount) }}</td>
                                <td class="num">{{ formatAmount(data.totals.refunds_amount) }}</td>
                                <td class="num">{{ formatAmount(data.totals.net_amount) }}</td>
                                <td class="num">{{ data.totals.net_amount ? '100,0 %' : '—' }}</td>
                            </tr></tfoot>
                        </table>
                    </template>

                    <!-- Por categoría: the category stored in each sale -->
                    <template v-else-if="activeType === 'categories'">
                        <table class="reports-table">
                            <thead><tr>
                                <th>Categoría</th>
                                <th class="num">Vendidas</th><th class="num">Devueltas</th><th class="num">Netas</th>
                                <th class="num">Ventas</th><th class="num">Devoluciones</th><th class="num">Importe neto</th><th class="num">%</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in data.rows" :key="row.category_id === null ? 'none' : row.category_id">
                                    <td>{{ row.name }}</td>
                                    <td class="num">{{ formatCount(row.units_sold) }}</td>
                                    <td class="num">{{ formatCount(row.units_refunded) }}</td>
                                    <td class="num" :class="{ negative: row.units_net < 0 }">{{ formatCount(row.units_net) }}</td>
                                    <td class="num">{{ formatAmount(row.sales_amount) }}</td>
                                    <td class="num">{{ formatAmount(row.refunds_amount) }}</td>
                                    <td class="num" :class="{ negative: row.net_amount < 0 }">{{ formatAmount(row.net_amount) }}</td>
                                    <td class="num">{{ row.percent === null ? '—' : formatPercent(row.percent).replace('+', '') }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="strong">
                                <td>Total</td>
                                <td class="num">{{ formatCount(data.totals.units_sold) }}</td>
                                <td class="num">{{ formatCount(data.totals.units_refunded) }}</td>
                                <td class="num">{{ formatCount(data.totals.units_net) }}</td>
                                <td class="num">{{ formatAmount(data.totals.sales_amount) }}</td>
                                <td class="num">{{ formatAmount(data.totals.refunds_amount) }}</td>
                                <td class="num">{{ formatAmount(data.totals.net_amount) }}</td>
                                <td class="num">{{ data.totals.net_amount ? '100,0 %' : '—' }}</td>
                            </tr></tfoot>
                        </table>
                    </template>

                    <!-- Por cajero: refunds by who made them -->
                    <template v-else-if="activeType === 'cashiers'">
                        <table class="reports-table">
                            <thead><tr>
                                <th>Empleado</th><th class="num">Tickets</th><th class="num">Ventas brutas</th><th class="num">Ticket medio</th>
                                <th class="num">Devoluciones</th><th class="num">Ventas netas</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in data.rows" :key="row.user_id">
                                    <td>{{ row.name }}</td>
                                    <td class="num">{{ formatCount(row.tickets) }}</td>
                                    <td class="num">{{ formatAmount(row.gross_sales) }}</td>
                                    <td class="num">{{ formatAmount(row.average_ticket) }}</td>
                                    <td class="num">{{ formatAmount(row.refunds) }}</td>
                                    <td class="num" :class="{ negative: row.net_sales < 0 }">{{ formatAmount(row.net_sales) }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="strong">
                                <td>Total</td>
                                <td class="num">{{ formatCount(data.totals.tickets) }}</td>
                                <td class="num">{{ formatAmount(data.totals.gross_sales) }}</td>
                                <td class="num">{{ formatAmount(data.totals.average_ticket) }}</td>
                                <td class="num">{{ formatAmount(data.totals.refunds) }}</td>
                                <td class="num">{{ formatAmount(data.totals.net_sales) }}</td>
                            </tr></tfoot>
                        </table>
                    </template>

                    <!-- Por forma de pago: same rules as the Z -->
                    <template v-else-if="activeType === 'payments'">
                        <table class="reports-table">
                            <thead><tr><th>Concepto</th><th class="num">Importe</th></tr></thead>
                            <tbody>
                                <tr v-for="row in ReportsLogic.PAYMENT_ROWS" :key="row.key" :class="{ strong: row.strong }">
                                    <td>{{ row.label }}</td>
                                    <td class="num">{{ formatAmount(data[row.key]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <table class="reports-table">
                            <thead><tr><th>Tickets por forma de pago</th><th class="num">Tickets</th></tr></thead>
                            <tbody>
                                <tr v-for="m in methodRows" :key="m.key">
                                    <td>{{ m.label }}</td>
                                    <td class="num">{{ formatCount(m.count) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                    <!-- IVA: base, VAT and total per rate of sales, refunds and net -->
                    <template v-else-if="activeType === 'vat'">
                        <table class="reports-table reports-table-wide">
                            <thead>
                                <tr>
                                    <th rowspan="2">Tipo de IVA</th>
                                    <th class="num" colspan="3">Ventas</th><th class="num" colspan="3">Devoluciones</th><th class="num" colspan="3">Neto</th>
                                </tr>
                                <tr>
                                    <th class="num">Base</th><th class="num">Cuota</th><th class="num">Total</th>
                                    <th class="num">Base</th><th class="num">Cuota</th><th class="num">Total</th>
                                    <th class="num">Base</th><th class="num">Cuota</th><th class="num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in data.rows" :key="row.rate">
                                    <td>{{ formatRate(row.rate) }}</td>
                                    <td class="num" :class="{ negative: row.sales.base < 0 }">{{ formatAmount(row.sales.base) }}</td>
                                    <td class="num" :class="{ negative: row.sales.vat < 0 }">{{ formatAmount(row.sales.vat) }}</td>
                                    <td class="num" :class="{ negative: row.sales.total < 0 }">{{ formatAmount(row.sales.total) }}</td>
                                    <td class="num" :class="{ negative: row.refunds.base < 0 }">{{ formatAmount(row.refunds.base) }}</td>
                                    <td class="num" :class="{ negative: row.refunds.vat < 0 }">{{ formatAmount(row.refunds.vat) }}</td>
                                    <td class="num" :class="{ negative: row.refunds.total < 0 }">{{ formatAmount(row.refunds.total) }}</td>
                                    <td class="num" :class="{ negative: row.net.base < 0 }">{{ formatAmount(row.net.base) }}</td>
                                    <td class="num" :class="{ negative: row.net.vat < 0 }">{{ formatAmount(row.net.vat) }}</td>
                                    <td class="num" :class="{ negative: row.net.total < 0 }">{{ formatAmount(row.net.total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="strong">
                                <td>Total</td>
                                <td class="num" :class="{ negative: data.totals.sales.base < 0 }">{{ formatAmount(data.totals.sales.base) }}</td>
                                    <td class="num" :class="{ negative: data.totals.sales.vat < 0 }">{{ formatAmount(data.totals.sales.vat) }}</td>
                                    <td class="num" :class="{ negative: data.totals.sales.total < 0 }">{{ formatAmount(data.totals.sales.total) }}</td>
                                    <td class="num" :class="{ negative: data.totals.refunds.base < 0 }">{{ formatAmount(data.totals.refunds.base) }}</td>
                                    <td class="num" :class="{ negative: data.totals.refunds.vat < 0 }">{{ formatAmount(data.totals.refunds.vat) }}</td>
                                    <td class="num" :class="{ negative: data.totals.refunds.total < 0 }">{{ formatAmount(data.totals.refunds.total) }}</td>
                                    <td class="num" :class="{ negative: data.totals.net.base < 0 }">{{ formatAmount(data.totals.net.base) }}</td>
                                    <td class="num" :class="{ negative: data.totals.net.vat < 0 }">{{ formatAmount(data.totals.net.vat) }}</td>
                                    <td class="num" :class="{ negative: data.totals.net.total < 0 }">{{ formatAmount(data.totals.net.total) }}</td>
                            </tr></tfoot>
                        </table>
                    </template>

                    <div v-if="activeType === 'products' && (productPage.hasPrev || productPage.hasNext)" class="reports-pager">
                        <button class="tpv-btn" :disabled="!productPage.hasPrev || loading" @click="prevPage">Anterior</button>
                        <span class="reports-hint">Página {{ productPage.number }} · {{ formatCount(productPage.total) }} filas</span>
                        <button class="tpv-btn" :disabled="!productPage.hasNext || loading" @click="nextPage">Siguiente</button>
                    </div>
                </template>
            </div>
        </div>
    `
};
