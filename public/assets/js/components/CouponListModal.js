/**
 * CouponListModal — «Cupones»: the coupons created from the till (GET /api/coupons),
 * newest first, 25 at a time, with their state in PrestaShop. Search by part of the
 * code; each coupon can print its slip again or open its PDF.
 */
const COUPON_STATUS = {
    available: { label: 'Disponible', color: 'var(--green)', bg: 'var(--green-bg)' },
    used: { label: 'Usado', color: 'var(--text3)', bg: 'var(--surface2)' },
    expired: { label: 'Caducado', color: 'var(--red)', bg: 'var(--red-bg)' },
    disabled: { label: 'Desactivado', color: 'var(--text3)', bg: 'var(--surface2)' },
    deleted: { label: 'Borrado en la tienda', color: 'var(--text3)', bg: 'var(--surface2)' },
    unknown: { label: 'Sin comprobar', color: 'var(--amber)', bg: 'var(--amber-bg)' },
};

const CouponListModal = {
    emits: ['close'],
    setup(props, { emit }) {
        const coupons = Vue.ref([]);
        const query = Vue.ref('');
        const page = Vue.ref(1);
        const hasMore = Vue.ref(false);
        const statusChecked = Vue.ref(true);
        const loading = Vue.ref(false);
        const error = Vue.ref('');
        const copiedCode = Vue.ref('');
        const rowMessage = Vue.ref({});
        let searchTimer = null;
        // Answers of an older search never overwrite a newer one.
        let requestId = 0;

        function formatValue(c) {
            const value = Number(c.value) || 0;
            return c.type === 'percentage'
                ? value.toLocaleString('es-ES', { maximumFractionDigits: 2 }) + ' %'
                : value.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        }

        function formatDate(text) {
            const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(text || ''));
            if (!m) return '';
            return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
        }

        function status(c) {
            return COUPON_STATUS[c.status] || COUPON_STATUS.unknown;
        }

        function usesText(c) {
            if (c.status !== 'available' || !(c.uses_left > 1)) return '';
            return c.uses_left + ' usos';
        }

        function cleanError(raw, fallback) {
            const text = String(raw && raw.message ? raw.message : raw || '');
            const json = text.replace(/^HTTP \d+:\s*/, '');
            try {
                const parsed = JSON.parse(json);
                if (parsed && typeof parsed.error === 'string') return parsed.error;
            } catch (e) { /* not JSON */ }
            return fallback || 'No se han podido cargar los cupones.';
        }

        function load(append) {
            const id = ++requestId;
            const nextPage = append ? page.value + 1 : 1;
            loading.value = true;
            error.value = '';
            return ApiClient.listCoupons(query.value.trim(), nextPage)
                .then((response) => {
                    if (id !== requestId) return;
                    const data = response && Array.isArray(response.data) ? response.data : [];
                    coupons.value = append ? coupons.value.concat(data) : data;
                    page.value = nextPage;
                    hasMore.value = !!(response && response.has_more);
                    statusChecked.value = !(response && response.status_checked === false);
                })
                .catch((e) => {
                    if (id === requestId) error.value = cleanError(e);
                })
                .finally(() => {
                    if (id === requestId) loading.value = false;
                });
        }

        function onSearch() {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(() => load(false), 300);
        }

        function copyCode(code) {
            const done = () => { copiedCode.value = code; };
            if (typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done, () => {});
            }
        }

        function printSlip(c) {
            rowMessage.value = { ...rowMessage.value, [c.id]: 'Imprimiendo…' };
            return ApiClient.couponPrint(c.id)
                .then((response) => {
                    const data = response && response.data ? response.data : {};
                    if (data.fallback === 'pdf') {
                        ApiClient.couponPdf(c.id);
                        rowMessage.value = { ...rowMessage.value, [c.id]: 'Sin impresora: se abre el PDF.' };
                        return;
                    }
                    rowMessage.value = { ...rowMessage.value, [c.id]: 'Enviado a la impresora.' };
                })
                .catch((e) => {
                    rowMessage.value = { ...rowMessage.value, [c.id]: cleanError(e, 'No se ha podido imprimir.') };
                });
        }

        function openPdf(c) {
            ApiClient.couponPdf(c.id);
        }

        Vue.onMounted(() => load(false));

        return {
            coupons, query, hasMore, statusChecked, loading, error, copiedCode, rowMessage,
            formatValue, formatDate, status, usesText, load, onSearch, copyCode, printSlip, openPdf,
            close: () => emit('close'),
        };
    },
    template: `
        <div class="modal-backdrop" style="backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);" @click.self="$emit('close')">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:24px; width:760px; max-width:94vw; max-height:88vh; display:flex; flex-direction:column; gap:14px;" @click.stop>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="font-size:17px; font-weight:600; color:var(--text); flex:1;">Cupones</div>
                    <button @click="$emit('close')" aria-label="Cerrar" style="min-height:36px; min-width:36px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-size:16px;">✕</button>
                </div>
                <input v-model="query" @input="onSearch" type="search" placeholder="Buscar por código" aria-label="Buscar por código"
                    style="width:100%; box-sizing:border-box; background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:10px 12px; color:var(--text); font-family:'IBM Plex Mono'; font-size:14px; outline:none;" />
                <div v-if="!statusChecked" style="padding:9px 12px; border-radius:8px; background:var(--amber-bg); border:1px solid var(--amber); color:var(--amber); font-size:12px; line-height:1.4;">
                    No se ha podido consultar la tienda: el estado de los cupones puede no estar al día.
                </div>
                <div v-if="error" style="color:var(--red); font-size:13px;">{{ error }}</div>
                <div style="overflow-y:auto; flex:1; min-height:120px; display:flex; flex-direction:column; gap:8px;">
                    <div v-for="c in coupons" :key="c.id" style="border:1px solid var(--border); border-radius:12px; padding:12px 14px; display:flex; flex-wrap:wrap; align-items:center; gap:10px 16px;">
                        <div style="flex:1 1 220px; min-width:0;">
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <span style="font-family:'IBM Plex Mono'; font-weight:700; font-size:15px; color:var(--text);">{{ c.code }}</span>
                                <button @click="copyCode(c.code)" style="border:0; background:transparent; color:var(--text3); cursor:pointer; font-family:inherit; font-size:11px; padding:0;">{{ copiedCode === c.code ? 'Copiado' : 'Copiar' }}</button>
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-top:3px;">
                                Creado {{ formatDate(c.created_at) }}<template v-if="c.cashier"> · {{ c.cashier }}</template><template v-if="c.terminal"> · {{ c.terminal }}</template>
                            </div>
                        </div>
                        <div style="min-width:90px; text-align:right;">
                            <div style="font-family:'IBM Plex Mono'; font-weight:700; font-size:16px; color:var(--text);">{{ formatValue(c) }}</div>
                            <div style="font-size:12px; color:var(--text3);">Caduca {{ formatDate(c.expires_at) }}</div>
                        </div>
                        <div style="min-width:120px; display:flex; flex-direction:column; align-items:flex-start; gap:3px;">
                            <span :style="{ color: status(c).color, background: status(c).bg, border: '1px solid ' + status(c).color }" style="font-size:12px; font-weight:600; padding:3px 9px; border-radius:999px;">{{ status(c).label }}</span>
                            <span v-if="usesText(c)" style="font-size:11px; color:var(--text3);">{{ usesText(c) }}</span>
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button @click="printSlip(c)" style="min-height:36px; padding:6px 10px; border-radius:8px; border:1px solid var(--border2); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:12px;">Imprimir</button>
                            <button @click="openPdf(c)" style="min-height:36px; padding:6px 10px; border-radius:8px; border:1px solid var(--border2); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:12px;">PDF</button>
                        </div>
                        <div v-if="rowMessage[c.id]" style="flex-basis:100%; font-size:12px; color:var(--text2);">{{ rowMessage[c.id] }}</div>
                    </div>
                    <div v-if="!loading && !error && coupons.length === 0" style="color:var(--text3); font-size:13px; text-align:center; padding:24px 0;">
                        {{ query.trim() ? 'Ningún cupón tiene ese código.' : 'Todavía no se ha creado ningún cupón desde el TPV.' }}
                    </div>
                    <div v-if="loading" style="color:var(--text3); font-size:13px; text-align:center; padding:10px 0;">Cargando…</div>
                    <button v-if="hasMore && !loading" @click="load(true)" style="align-self:center; padding:8px 18px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">Ver más</button>
                </div>
            </div>
        </div>
    `,
};
