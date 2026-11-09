/**
 * CouponCreationModal — creates a server-generated TPV coupon, then hands its slip
 * over: printed on the till's ticket printer, as a PDF, by email, or by WhatsApp
 * (WhatsAppShare: a link to the slip's PDF).
 */

// Page-session ambiguity guard. After a 409 or a lost response the coupon may
// already exist in PrestaShop, so the lock must outlive this modal instance:
// closing and reopening the modal reuses the same loaded component module.
// It is intentionally memory-only (a full page reload clears it) because there
// is no recovery UI or persisted reconciliation record yet.
const couponReconciliationGuard = Vue.ref({ required: false, message: '' });

const CouponCreationModal = {
    emits: ['close'],
    components: typeof WhatsAppShare !== 'undefined' ? { 'whatsapp-share': WhatsAppShare } : {},
    setup(props, { emit }) {
        function defaultExpiry() {
            const date = new Date();
            date.setFullYear(date.getFullYear() + 1);
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return date.getFullYear() + '-' + month + '-' + day;
        }

        const discountType = Vue.ref('amount');
        const value = Vue.ref('');
        const expiresAt = Vue.ref(defaultExpiry());
        const quantity = Vue.ref(1);
        const quantityPerUser = Vue.ref(1);
        const pending = Vue.ref(false);
        const error = Vue.ref(couponReconciliationGuard.value.message || '');
        const createdCode = Vue.ref('');
        const reconciliationRequired = Vue.ref(couponReconciliationGuard.value.required);
        const copied = Vue.ref(false);
        // The slip of the created coupon goes by its local id (issued_coupon); without
        // one (the till could not keep its copy) only the code is shown.
        const couponId = Vue.ref(null);
        const printable = Vue.ref(false);
        const emailEnabled = Vue.ref(false);
        const emailOpen = Vue.ref(false);
        const emailAddress = Vue.ref('');
        const whatsappOpen = Vue.ref(false);
        const slipBusy = Vue.ref(false);
        const slipMessage = Vue.ref('');
        const slipError = Vue.ref('');

        function validationError() {
            const discountValue = Number(String(value.value).replace(',', '.'));
            if (!Number.isFinite(discountValue) || discountValue <= 0) return 'Introduce un importe válido.';
            if (discountType.value === 'percentage' && discountValue > 100) return 'El porcentaje no puede superar 100.';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(expiresAt.value)) return 'Selecciona una fecha de caducidad válida.';
            if (!Number.isInteger(Number(quantity.value)) || Number(quantity.value) <= 0) return 'El número total de usos debe ser mayor que cero.';
            if (!Number.isInteger(Number(quantityPerUser.value)) || Number(quantityPerUser.value) <= 0) return 'Los usos por cliente deben ser mayores que cero.';
            return '';
        }

        function payload() {
            return {
                discount_type: discountType.value,
                value: Number(String(value.value).replace(',', '.')),
                expires_at: expiresAt.value,
                quantity: Number(quantity.value),
                quantity_per_user: Number(quantityPerUser.value),
            };
        }

        function requiresReconciliation(message) {
            return /HTTP 409\b|network unavailable|failed to fetch|networkerror|network error/i.test(message || '');
        }

        // ApiClient rejects with 'HTTP <status>: <body>'. Show the cashier the
        // backend sentence only, decoded, never the status wrapper or raw JSON.
        function cleanErrorMessage(raw) {
            let text = String(raw || '').trim();
            const wrapped = text.match(/^HTTP \d{3}:\s*([\s\S]*)$/);
            if (wrapped) text = wrapped[1].trim();
            if (text.startsWith('{')) {
                try {
                    const parsed = JSON.parse(text);
                    if (parsed && typeof parsed.error === 'string' && parsed.error.trim()) {
                        return parsed.error.trim();
                    }
                } catch (e) {
                    // Not JSON: keep the plain body text.
                }
            }
            return text;
        }

        function lockReconciliation(message) {
            const detail = cleanErrorMessage(message);
            couponReconciliationGuard.value = { required: true, message: detail };
            reconciliationRequired.value = true;
            error.value = detail;
        }

        async function submit() {
            if (pending.value || reconciliationRequired.value || couponReconciliationGuard.value.required) return;
            error.value = validationError();
            if (error.value) return;

            pending.value = true;
            try {
                const response = await ApiClient.createCoupon(payload());
                const data = response && response.data;
                if (!data || !data.code) throw new Error('La respuesta no incluye el código creado.');
                createdCode.value = data.code;
                couponId.value = Number.isInteger(data.local_id) && data.local_id > 0 ? data.local_id : null;
                printable.value = couponId.value !== null && data.printable !== false;
                error.value = '';
                if (printable.value) emailEnabled.value = await mailEnabled();
            } catch (err) {
                const message = (err && err.message) || 'No se pudo crear el cupón.';
                if (requiresReconciliation(message)) {
                    lockReconciliation(message);
                } else {
                    error.value = cleanErrorMessage(message);
                }
            } finally {
                pending.value = false;
            }
        }

        // Same check as the payment screen: no email button without a mail account.
        async function mailEnabled() {
            if (typeof ApiClient.ticketEmailEnabled !== 'function') return false;
            try {
                const response = await ApiClient.ticketEmailEnabled();
                return !!(response && response.data && response.data.enabled);
            } catch (e) {
                return false;
            }
        }

        async function slipAction(action) {
            if (!printable.value || slipBusy.value) return;
            slipBusy.value = true;
            slipMessage.value = '';
            slipError.value = '';
            try {
                slipMessage.value = await action();
            } catch (err) {
                slipError.value = cleanErrorMessage((err && err.message) || 'No se pudo completar la acción.');
            } finally {
                slipBusy.value = false;
            }
        }

        function openPdf() {
            if (!printable.value) return;
            ApiClient.couponPdf(couponId.value);
        }

        function printSlip() {
            return slipAction(async () => {
                const response = await ApiClient.couponPrint(couponId.value);
                const data = (response && response.data) || {};
                if (data.fallback === 'pdf') {
                    ApiClient.couponPdf(couponId.value);
                    return 'Esta caja no tiene impresora: se abre el PDF.';
                }
                return data.print_status === 'queued' ? 'Enviado a la impresora de la caja.' : 'Cupón impreso.';
            });
        }

        function sendEmail() {
            const address = String(emailAddress.value || '').trim();
            if (!address) {
                slipError.value = 'Introduce el email del cliente.';
                slipMessage.value = '';
                return Promise.resolve();
            }
            return slipAction(async () => {
                await ApiClient.couponEmail(couponId.value, address);
                emailOpen.value = false;
                return 'Cupón enviado a ' + address;
            });
        }

        async function copyCode() {
            copied.value = false;
            if (!createdCode.value || !navigator.clipboard || !navigator.clipboard.writeText) return;
            try {
                await navigator.clipboard.writeText(createdCode.value);
                copied.value = true;
            } catch (e) {
                // The readonly field remains available for manual selection.
            }
        }

        function reset() {
            discountType.value = 'amount';
            value.value = '';
            expiresAt.value = defaultExpiry();
            quantity.value = 1;
            quantityPerUser.value = 1;
            pending.value = false;
            error.value = '';
            createdCode.value = '';
            // The ambiguity lock is page-session state: reset never releases it.
            reconciliationRequired.value = couponReconciliationGuard.value.required;
            if (reconciliationRequired.value) error.value = couponReconciliationGuard.value.message;
            copied.value = false;
            couponId.value = null;
            printable.value = false;
            emailEnabled.value = false;
            emailOpen.value = false;
            emailAddress.value = '';
            whatsappOpen.value = false;
            slipBusy.value = false;
            slipMessage.value = '';
            slipError.value = '';
        }

        return { discountType, value, expiresAt, quantity, quantityPerUser, pending, error, createdCode, reconciliationRequired, copied, couponId, printable, emailEnabled, emailOpen, emailAddress, whatsappOpen, slipBusy, slipMessage, slipError, printSlip, openPdf, sendEmail, submit, copyCode, reset, validationError, close: () => emit('close') };
    },
    template: `
        <div class="modal-backdrop" style="backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);" @click.self="!pending && $emit('close')">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:28px; width:400px; max-width:92vw; display:flex; flex-direction:column; gap:16px;" @click.stop>
                <div style="font-size:17px; font-weight:600; color:var(--text);">Crear cupón</div>
                <template v-if="!createdCode">
                    <div style="display:flex; gap:8px;">
                        <button v-for="option in [{ id: 'amount', label: 'Importe €' }, { id: 'percentage', label: 'Porcentaje %' }]" :key="option.id" @click="discountType = option.id" :disabled="pending || reconciliationRequired"
                            style="flex:1; padding:9px; border-radius:8px; border:1px solid; cursor:pointer; font-family:inherit; font-size:13px;" :style="{ borderColor: discountType === option.id ? 'var(--amber)' : 'var(--border)', background: discountType === option.id ? 'var(--amber-bg)' : 'transparent', color: discountType === option.id ? 'var(--amber)' : 'var(--text2)' }">
                            {{ option.label }}
                        </button>
                    </div>
                    <label style="font-size:12px; color:var(--text2);">{{ discountType === 'amount' ? 'Importe (€)' : 'Porcentaje (%)' }}
                        <input v-model="value" type="number" min="0.01" step="0.01" :disabled="pending || reconciliationRequired" autofocus style="width:100%; box-sizing:border-box; margin-top:5px; background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:10px 12px; color:var(--text); font-family:'IBM Plex Mono'; font-size:16px; outline:none;" />
                    </label>
                    <label style="font-size:12px; color:var(--text2);">Caducidad
                        <input v-model="expiresAt" type="date" :disabled="pending || reconciliationRequired" style="width:100%; box-sizing:border-box; margin-top:5px; background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:9px 12px; color:var(--text); font-family:inherit; outline:none;" />
                    </label>
                    <div style="display:flex; gap:10px;">
                        <label style="flex:1; font-size:12px; color:var(--text2);">Usos totales<input v-model="quantity" type="number" min="1" step="1" :disabled="pending || reconciliationRequired" style="width:100%; box-sizing:border-box; margin-top:5px; background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:9px 10px; color:var(--text); font-family:'IBM Plex Mono'; outline:none;" /></label>
                        <label style="flex:1; font-size:12px; color:var(--text2);">Usos por cliente<input v-model="quantityPerUser" type="number" min="1" step="1" :disabled="pending || reconciliationRequired" style="width:100%; box-sizing:border-box; margin-top:5px; background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:9px 10px; color:var(--text); font-family:'IBM Plex Mono'; outline:none;" /></label>
                    </div>
                </template>
                <div v-else style="padding:13px; border-radius:10px; background:var(--green-bg); border:1px solid var(--green);">
                    <div style="font-size:12px; color:var(--text2); margin-bottom:5px;">Código creado</div>
                    <input :value="createdCode" readonly @focus="$event.target.select()" style="width:100%; box-sizing:border-box; background:transparent; border:0; color:var(--green); font-family:'IBM Plex Mono'; font-weight:700; font-size:17px; outline:none;" />
                    <button @click="copyCode" style="margin-top:8px; border:0; background:transparent; color:var(--green); cursor:pointer; font-family:inherit; font-size:12px; padding:0;">{{ copied ? 'Copiado' : 'Copiar código' }}</button>
                </div>
                <template v-if="createdCode">
                    <div v-if="printable" style="display:flex; flex-direction:column; gap:8px;">
                        <div style="display:flex; gap:8px;">
                            <button @click="printSlip" :disabled="slipBusy" style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid var(--border2); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:13px;">Imprimir ticket</button>
                            <button @click="openPdf" :disabled="slipBusy" style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid var(--border2); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:13px;">PDF</button>
                            <button v-if="emailEnabled" @click="emailOpen = !emailOpen" :disabled="slipBusy" style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid var(--border2); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:13px;">Enviar por email</button>
                            <button @click="whatsappOpen = !whatsappOpen" :disabled="slipBusy" style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid var(--border2); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:13px;">WhatsApp</button>
                        </div>
                        <whatsapp-share v-if="whatsappOpen" kind="coupon" :doc-id="couponId" />
                        <div v-if="emailEnabled && emailOpen" style="display:flex; gap:8px;">
                            <input v-model="emailAddress" type="email" placeholder="email@cliente.com" :disabled="slipBusy" @keyup.enter="sendEmail" style="flex:1; min-width:0; box-sizing:border-box; background:var(--surface2); border:1px solid var(--border2); border-radius:8px; padding:9px 12px; color:var(--text); font-family:inherit; font-size:13px; outline:none;" />
                            <button @click="sendEmail" :disabled="slipBusy" style="padding:9px 14px; border-radius:8px; border:0; background:var(--amber); color:#000; cursor:pointer; font-family:inherit; font-size:13px; font-weight:700;">{{ slipBusy ? 'Enviando…' : 'Enviar' }}</button>
                        </div>
                        <div v-if="slipMessage" style="color:var(--green); font-size:12px; line-height:1.4;">{{ slipMessage }}</div>
                        <div v-if="slipError" style="color:var(--red); font-size:12px; line-height:1.4;">{{ slipError }}</div>
                    </div>
                    <div v-else style="color:var(--text2); font-size:12px; line-height:1.45;">El cupón está creado, pero este TPV no ha podido guardarlo para imprimirlo: anota o copia el código.</div>
                </template>
                <div v-if="reconciliationRequired" style="padding:11px 13px; border-radius:10px; background:var(--red-bg); border:1px solid var(--red); color:var(--red); font-size:12px; line-height:1.45;">
                    Creación sin confirmar: no se puede volver a enviar desde este TPV.
                </div>
                <div v-if="error" style="color:var(--red); font-size:12px; line-height:1.4;">{{ error }}</div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button @click="$emit('close')" :disabled="pending" style="padding:10px 16px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">{{ createdCode ? 'Cerrar' : 'Cancelar' }}</button>
                    <button v-if="!createdCode" @click="submit" :disabled="pending || reconciliationRequired" style="padding:10px 16px; border-radius:8px; border:0; background:var(--amber); color:#000; cursor:pointer; font-family:inherit; font-size:13px; font-weight:700;" :style="pending || reconciliationRequired ? 'opacity:.55; cursor:not-allowed;' : ''">{{ pending ? 'Creando…' : 'Crear cupón' }}</button>
                </div>
            </div>
        </div>
    `,
};
