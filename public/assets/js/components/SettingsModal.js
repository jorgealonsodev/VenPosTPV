/**
 * SettingsModal — Settings page with sidebar navigation (admin only).
 * Sections: Tienda (shop data, the same on every till), Cajas (each till's name,
 * letter, printer and dataphone, which computer it is on, create a till, and where
 * the ticket, credit-note and invoice numbering continues),
 * Empleados, Sincronización, Cierres de caja (the closes of the shop with their
 * Z report, to print or download again), Acerca de (with «Estado de la
 * instalación»: the installation steps still pending and why, docs/provisioning.md;
 * and «Volver a emparejar con la tienda»: TPV Connect's pairing code rewrites the
 * shop keys in .env).
 *
 * It closes only with its ✕, which first saves every section changed and not saved
 * yet (closeSettings); a save that fails keeps it open on that section.
 */
const SettingsModal = {
    emits: ['close'],
    setup(props, { emit }) {
        const employees = Vue.ref([]);
        const loading = Vue.ref(false);
        const error = Vue.ref('');
        const success = Vue.ref('');

        // Sidebar navigation
        const activeSection = Vue.ref('empleados');

        // Create form
        const showCreateForm = Vue.ref(false);
        const createForm = Vue.reactive({ name: '', full_name: '', code: '', pin: '', role: 'cashier' });
        const createError = Vue.ref('');
        const creating = Vue.ref(false);

        // Edit form
        const editingId = Vue.ref(null);
        const editForm = Vue.reactive({ name: '', full_name: '', code: '', pin: '', role: 'cashier', active: true });
        const editError = Vue.ref('');
        const saving = Vue.ref(false);

        // Terminal config
        const terminal = Vue.ref(null);
        const terminalLoading = Vue.ref(false);
        const terminalSaving = Vue.ref(false);
        const terminalError = Vue.ref('');
        const systemPrinters = Vue.ref([]);

        async function loadSystemPrinters() {
            try {
                const resp = await ApiClient.get('/api/printers/system');
                systemPrinters.value = Array.isArray(resp.data) ? resp.data : [];
            } catch (e) {
                systemPrinters.value = [];
            }
        }

        // Sync section
        const syncInterval = Vue.ref(useSession.session.stockSyncIntervalSeconds ?? 60);
        const syncPushInterval = Vue.ref(useSession.session.stockPushIntervalSeconds ?? 0);
        const syncSaving = Vue.ref(false);
        const syncPushSaving = Vue.ref(false);
        const syncError = Vue.ref('');
        const syncNowRunning = Vue.ref(false);
        const syncLastResult = Vue.ref(null);

        // Acerca de → Estado de la instalación (GET /api/settings/provisioning, read-only)
        const provisioning = Vue.ref(null);
        const provisioningLoading = Vue.ref(false);
        const provisioningError = Vue.ref('');

        async function loadProvisioning() {
            provisioningLoading.value = true;
            provisioningError.value = '';
            try {
                const resp = await ApiClient.get('/api/settings/provisioning');
                provisioning.value = resp?.data || null;
            } catch (e) {
                provisioning.value = null;
                provisioningError.value = errorText(e, 'No se pudo cargar el estado de la instalación');
            } finally {
                provisioningLoading.value = false;
            }
        }

        function provisioningLabel(step) {
            if (step && step.status === 'done') return 'Hecho';
            return step && step.status === 'blocked' ? 'Bloqueado' : 'Pendiente';
        }

        // Acerca de → «Volver a emparejar con la tienda»: the code of TPV Connect's
        // «Emparejar con un TPV»; the server writes the shop's keys into .env and
        // answers no secret (POST /api/settings/prestashop/pair, admins only).
        const pairing = Vue.reactive({ open: false, shopUrl: '', code: '', running: false, error: '', done: '' });

        async function openPairing() {
            pairing.open = true;
            pairing.error = '';
            pairing.done = '';
            pairing.code = '';
            try {
                const resp = await ApiClient.get('/api/settings/prestashop');
                pairing.shopUrl = (resp && resp.data && resp.data.shop_url) || '';
            } catch (e) {
                pairing.error = errorText(e, 'No se pudo cargar la dirección de la tienda');
            }
        }

        async function runPairing() {
            const code = String(pairing.code || '').replace(/[\s-]+/g, '');
            pairing.error = '';
            if (!/^\d{6}$/.test(code)) {
                pairing.error = 'El código de emparejamiento tiene 6 cifras.';
                return;
            }
            pairing.running = true;
            try {
                const resp = await ApiClient.post('/api/settings/prestashop/pair', { shop_url: String(pairing.shopUrl || '').trim(), code });
                const data = (resp && resp.data) || {};
                pairing.done = `Emparejado con ${data.shop_url} (TPV Connect ${data.module_version}). Las claves nuevas ya están en .env; la copia del anterior es ${data.backup}.`;
                pairing.open = false;
                pairing.code = '';
            } catch (e) {
                pairing.error = errorText(e, 'No se pudo emparejar con la tienda');
            } finally {
                pairing.running = false;
            }
        }

        async function loadEmployees() {
            loading.value = true;
            error.value = '';
            try {
                const resp = await ApiClient.get('/api/employees');
                employees.value = resp.data || [];
            } catch (e) {
                error.value = 'Error al cargar empleados';
            } finally {
                loading.value = false;
            }
        }

        async function loadTerminal() {
            const terminalId = useSession.session.terminalId;
            if (!terminalId) {
                terminal.value = null;
                return;
            }
            terminalLoading.value = true;
            terminalError.value = '';
            try {
                const resp = await ApiClient.terminalGet(terminalId);
                terminal.value = resp.data || null;
                markShopSaved();
                markTillSaved();
            } catch (e) {
                terminalError.value = 'Error al cargar configuración del terminal';
            } finally {
                terminalLoading.value = false;
            }
        }

        // Shop data: saved on every till of the shop, so every till prints the same ticket header.
        const SHOP_FIELDS = ['store_name', 'legal_name', 'tax_id', 'bank_account', 'store_address', 'ticket_header', 'ticket_footer', 'ticket_logo', 'refund_window_days', 'ticket_code'];
        // What «Guardar» of Cajas sends for this computer's till.
        const TILL_FIELDS = ['name', 'ticket_letter', 'dataphone_mode', 'printer_type', 'ticket_width_px', 'printer_ip', 'printer_port', 'printer_queue',
            'label_height_mm', 'label_cut_each', 'ticket_margin_left_mm', 'ticket_margin_right_mm', 'ticket_logo_width_pct'];
        const MAIL_FIELDS = ['host', 'port', 'encryption', 'username', 'from_email', 'from_name', 'backup_alert_email'];

        // The values as last loaded or saved, per section: closing the window saves
        // the sections whose values differ (closeSettings).
        function pick(source, fields) {
            const values = {};
            fields.forEach((field) => { values[field] = source && source[field] != null ? String(source[field]) : ''; });
            return JSON.stringify(values);
        }
        const savedState = { shop: null, till: null, mail: null, edit: null, syncPull: String(syncInterval.value), syncPush: String(syncPushInterval.value), payMethods: null };

        // Tienda → «Métodos de pago» (PaymentMethodsSettings): the ways of paying the
        // payment window offers, for every till. null until read.
        const PAY_METHODS = [
            { id: 'cash', label: 'Efectivo' },
            { id: 'card', label: 'Tarjeta' },
            { id: 'mixed', label: 'Mixto (efectivo + tarjeta)' },
            { id: 'transfer', label: 'Transferencia' },
            { id: 'voucher', label: 'Vale regalo (cupón de la tienda)' },
        ];
        const payMethods = Vue.ref(null);
        const payMethodsError = Vue.ref('');
        const payMethodsSaving = Vue.ref(false);

        async function loadPaymentMethods() {
            if (typeof ApiClient.paymentMethods !== 'function') return;
            try {
                const res = await ApiClient.paymentMethods();
                payMethods.value = res && res.data && Array.isArray(res.data.enabled) ? res.data.enabled.slice() : null;
                savedState.payMethods = payMethods.value ? JSON.stringify(payMethods.value) : null;
            } catch (e) {
                payMethodsError.value = errorText(e, 'No se han podido cargar los métodos de pago');
            }
        }

        function togglePayMethod(id) {
            if (!payMethods.value) return;
            const on = new Set(payMethods.value);
            if (on.has(id)) on.delete(id); else on.add(id);
            payMethods.value = PAY_METHODS.map((m) => m.id).filter((m) => on.has(m));
            payMethodsError.value = '';
        }

        async function savePaymentMethods() {
            if (!payMethods.value) return;
            payMethodsSaving.value = true;
            payMethodsError.value = '';
            try {
                const res = await ApiClient.savePaymentMethods(payMethods.value);
                const enabled = res && res.data && Array.isArray(res.data.enabled) ? res.data.enabled.slice() : payMethods.value.slice();
                payMethods.value = enabled;
                savedState.payMethods = JSON.stringify(enabled);
                useSession.session.paymentMethods = enabled.slice();
                flash('Métodos de pago guardados para todas las cajas');
            } catch (e) {
                payMethodsError.value = errorText(e, 'No se han podido guardar los métodos de pago');
            } finally {
                payMethodsSaving.value = false;
            }
        }
        function markShopSaved() { savedState.shop = terminal.value ? pick(terminal.value, SHOP_FIELDS) : null; }
        function markTillSaved() { savedState.till = terminal.value ? pick(terminal.value, TILL_FIELDS) : null; }

        function errorText(e, fallback) {
            const match = /^HTTP (\d+): ([\s\S]*)$/.exec((e && e.message) || '');
            if (match) {
                try { return JSON.parse(match[2]).error || fallback; } catch (err) { return fallback; }
            }
            return e?.response?.data?.error || fallback;
        }

        function flash(text) {
            success.value = text;
            setTimeout(() => { success.value = ''; }, 2000);
        }

        async function saveShop() {
            if (!terminal.value) return;
            terminalSaving.value = true;
            terminalError.value = '';
            try {
                const payload = {};
                SHOP_FIELDS.forEach((field) => {
                    const value = terminal.value[field];
                    if (field === 'refund_window_days') {
                        if (value != null && value !== '') payload[field] = Number(value);
                    } else if (field === 'ticket_code') {
                        if (value) payload[field] = value;
                    } else if (field === 'ticket_logo') {
                        payload[field] = value ?? null;
                    } else {
                        payload[field] = value ?? '';
                    }
                });
                await ApiClient.terminalUpdate(terminal.value.id, payload);
                markShopSaved();
                flash('Datos de la tienda guardados en todas las cajas');
            } catch (e) {
                terminalError.value = errorText(e, 'Error al guardar los datos de la tienda');
            } finally {
                terminalSaving.value = false;
            }
        }

        const labelTestStatus = Vue.ref('');
        async function printTestLabel() {
            if (!terminal.value) return;
            labelTestStatus.value = 'Imprimiendo etiqueta de prueba…';
            try {
                await ApiClient.labelTest(terminal.value.id);
                labelTestStatus.value = 'Etiqueta de prueba enviada';
            } catch (e) {
                labelTestStatus.value = errorText(e, 'No se pudo imprimir la etiqueta de prueba');
            }
        }

        async function saveTill() {
            if (!terminal.value) return;
            terminalSaving.value = true;
            terminalError.value = '';
            try {
                const payload = {
                    dataphone_mode: terminal.value.dataphone_mode,
                    printer_type: terminal.value.printer_type,
                    ticket_width_px: terminal.value.ticket_width_px,
                };
                // Only sent when set: a till without a letter can still save its other settings.
                const ticketLetter = String(terminal.value.ticket_letter ?? '').trim();
                if (ticketLetter !== '') payload.ticket_letter = ticketLetter.toUpperCase();
                if (terminal.value.name != null) payload.name = terminal.value.name;
                if (terminal.value.printer_ip != null) payload.printer_ip = terminal.value.printer_ip;
                if (terminal.value.printer_port != null) payload.printer_port = terminal.value.printer_port;
                if (terminal.value.printer_queue != null) payload.printer_queue = terminal.value.printer_queue;
                // Labels on this till's printer (PRD L5/L6).
                if (terminal.value.label_height_mm != null && terminal.value.label_height_mm !== '') payload.label_height_mm = Number(terminal.value.label_height_mm);
                if (terminal.value.label_cut_each != null) payload.label_cut_each = !!terminal.value.label_cut_each;
                // Ticket margins and logo width: sent only when the server returned them
                // (migration V45 applied); empty means the default (2 mm, whole width).
                ['ticket_margin_left_mm', 'ticket_margin_right_mm', 'ticket_logo_width_pct'].forEach((field) => {
                    if (!(field in terminal.value)) return;
                    const value = terminal.value[field];
                    payload[field] = value === '' || value == null ? null : Number(value);
                });

                await ApiClient.terminalUpdate(terminal.value.id, payload);
                markTillSaved();
                flash('Caja guardada');
                // This computer's till: PaymentModal picks the new values up at once.
                if (terminal.value.id === useSession.session.terminalId) {
                    useSession.session.dataphoneMode = terminal.value.dataphone_mode;
                    useSession.session.printerType = terminal.value.printer_type;
                    // C4: the sale screen notice follows the new printer at once.
                    if (typeof useSession.refreshPrintAgent === 'function') useSession.refreshPrintAgent();
                }
                await loadTills();
            } catch (e) {
                terminalError.value = errorText(e, 'Error al guardar la caja');
            } finally {
                terminalSaving.value = false;
            }
        }

        // Cajas: every till of the shop and the computer it is on.
        const tills = Vue.ref([]);
        const tillsError = Vue.ref('');
        const confirmMoveId = Vue.ref(null);
        const newTill = Vue.reactive({ name: '', ticket_letter: '' });
        const creatingTill = Vue.ref(false);

        async function loadTills() {
            tillsError.value = '';
            try {
                const resp = await ApiClient.get('/api/device');
                tills.value = (resp && resp.data && resp.data.tills) || [];
            } catch (e) {
                tillsError.value = errorText(e, 'Error al cargar las cajas');
            }
        }

        async function editTill(till) {
            terminalLoading.value = true;
            terminalError.value = '';
            try {
                const resp = await ApiClient.terminalGet(till.id);
                terminal.value = resp.data || null;
                if (numbering.editing === 'ticket') numbering.editing = null;
                loadNumbering();
            } catch (e) {
                terminalError.value = 'Error al cargar la caja';
            } finally {
                terminalLoading.value = false;
            }
        }

        // "Pasar la caja a este equipo": the other computer is unlinked.
        async function moveTillHere(till) {
            if (till.device && !till.this_device && confirmMoveId.value !== till.id) {
                confirmMoveId.value = till.id;
                return false;
            }
            tillsError.value = '';
            try {
                const resp = await ApiClient.post('/api/device/link', { terminal_id: till.id, move: true });
                useSession.rememberDeviceToken(resp && resp.data && resp.data.token);
                confirmMoveId.value = null;
                // The whole POS now works on another till: start again from it.
                if (window.location && typeof window.location.reload === 'function') window.location.reload();
                return true;
            } catch (e) {
                tillsError.value = errorText(e, 'No se pudo pasar la caja a este equipo');
                return false;
            }
        }

        async function createTill() {
            tillsError.value = '';
            const name = String(newTill.name || '').trim();
            const letter = String(newTill.ticket_letter || '').trim().toUpperCase();
            if (!name) { tillsError.value = 'Indica el nombre de la caja'; return false; }
            if (!/^[A-Z]$/.test(letter)) { tillsError.value = 'La letra de caja debe ser una letra de la A a la Z'; return false; }
            creatingTill.value = true;
            try {
                await ApiClient.post('/api/terminal', { name, ticket_letter: letter });
                newTill.name = '';
                newTill.ticket_letter = '';
                flash('Caja creada');
                await loadTills();
                return true;
            } catch (e) {
                tillsError.value = errorText(e, 'Error al crear la caja');
                return false;
            } finally {
                creatingTill.value = false;
            }
        }

        // docs/prd/agente-impresion.md S3: in the till list the agent has its own name.
        function printerTypeLabel(type) {
            return type === 'agent' ? 'Agente de impresión' : type;
        }

        function tillSeen(till) {
            const seen = till.device && (till.device.last_seen_at || till.device.linked_at);
            return seen ? String(seen).slice(0, 16) : '';
        }

        const logoError = Vue.ref('');
        const logoDragOver = Vue.ref(false);
        const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        const LOGO_MAX_BYTES = 2 * 1024 * 1024;

        // One path for the button, drag & drop and paste, so the logo can be set even
        // where the browser's file dialog does not open (kiosk, fullscreen, portals).
        function setLogoFromFile(file) {
            logoError.value = '';
            if (!file) return;
            if (!LOGO_TYPES.includes(file.type)) {
                logoError.value = 'Formato no admitido: usa PNG, JPG, GIF o WebP (la impresora no imprime SVG).';
                return;
            }
            if (file.size > LOGO_MAX_BYTES) {
                logoError.value = 'La imagen supera 2 MB. Usa una más pequeña.';
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                if (terminal.value) terminal.value.ticket_logo = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        function handleLogoUpload(event) {
            const files = event && event.target && event.target.files;
            setLogoFromFile(files && files[0]);
            if (event && event.target && 'value' in event.target) event.target.value = '';
        }

        function handleLogoDrop(event) {
            event.preventDefault();
            logoDragOver.value = false;
            const files = event.dataTransfer && event.dataTransfer.files;
            setLogoFromFile(files && files[0]);
        }

        function handleLogoPaste(event) {
            const items = (event.clipboardData && event.clipboardData.items) || [];
            for (const item of Array.from(items)) {
                if (item.kind === 'file' && item.type.indexOf('image/') === 0) {
                    setLogoFromFile(item.getAsFile());
                    return;
                }
            }
        }

        function removeLogo() {
            terminal.value.ticket_logo = null;
        }

        async function saveSyncInterval() {
            if (!terminal.value) return;
            syncSaving.value = true;
            syncError.value = '';
            try {
                const seconds = parseInt(syncInterval.value, 10);
                await ApiClient.terminalUpdate(useSession.session.terminalId || terminal.value.id, { stock_sync_interval_seconds: seconds });
                savedState.syncPull = String(syncInterval.value);
                useSession.session.stockSyncIntervalSeconds = seconds;
                useSession.startStockSyncTimer();
                success.value = 'Intervalo PS → TPV guardado';
                setTimeout(() => { success.value = ''; }, 2000);
            } catch (e) {
                syncError.value = errorText(e, 'Error al guardar intervalo');
            } finally {
                syncSaving.value = false;
            }
        }

        async function saveSyncPushInterval() {
            if (!terminal.value) return;
            syncPushSaving.value = true;
            syncError.value = '';
            try {
                const seconds = parseInt(syncPushInterval.value, 10);
                await ApiClient.terminalUpdate(useSession.session.terminalId || terminal.value.id, { stock_push_interval_seconds: seconds });
                savedState.syncPush = String(syncPushInterval.value);
                useSession.session.stockPushIntervalSeconds = seconds;
                useSession.startStockPushTimer();
                success.value = 'Intervalo TPV → PS guardado';
                setTimeout(() => { success.value = ''; }, 2000);
            } catch (e) {
                syncError.value = errorText(e, 'Error al guardar intervalo push');
            } finally {
                syncPushSaving.value = false;
            }
        }

        async function syncNow() {
            syncNowRunning.value = true;
            syncLastResult.value = null;
            syncError.value = '';
            try {
                const resp = await ApiClient.post('/api/sync/now', {});
                syncLastResult.value = resp?.data?.counts ?? resp?.data ?? { ok: true };
                success.value = 'Sincronización completada';
                setTimeout(() => { success.value = ''; }, 3000);
            } catch (e) {
                syncError.value = errorText(e, 'Error al sincronizar');
            } finally {
                syncNowRunning.value = false;
            }
        }

        async function handleCreate() {
            createError.value = '';
            if (!createForm.name.trim()) { createError.value = 'El nombre es obligatorio'; return; }
            if (createForm.pin.length < 8) { createError.value = 'PIN inválido'; return; }
            creating.value = true;
            try {
                const body = { name: createForm.name, pin: createForm.pin, role: createForm.role };
                if (createForm.code.trim()) body.code = createForm.code.trim();
                // «Nombre y apellidos» is printed on the ticket as the cashier; optional.
                if ((createForm.full_name || '').trim()) body.full_name = createForm.full_name.trim();
                await ApiClient.post('/api/employees', body);
                createForm.name = '';
                createForm.full_name = '';
                createForm.code = '';
                createForm.pin = '';
                createForm.role = 'cashier';
                showCreateForm.value = false;
                success.value = 'Empleado creado';
                setTimeout(() => { success.value = ''; }, 2000);
                await loadEmployees();
            } catch (e) {
                createError.value = errorText(e, 'Error al crear empleado');
            } finally {
                creating.value = false;
            }
        }

        function startEdit(emp) {
            editingId.value = emp.id;
            editForm.name = emp.name;
            editForm.full_name = emp.full_name || '';
            editForm.code = emp.code || '';
            editForm.pin = '';
            editForm.role = emp.role;
            editForm.active = emp.active;
            editError.value = '';
            savedState.edit = JSON.stringify(editForm);
        }

        function cancelEdit() {
            editingId.value = null;
            editError.value = '';
        }

        async function handleSave(id) {
            editError.value = '';
            if (!editForm.name.trim()) { editError.value = 'El nombre es obligatorio'; return; }
            if (editForm.pin && editForm.pin.length < 8) { editError.value = 'PIN inválido'; return; }
            saving.value = true;
            try {
                const body = { name: editForm.name, full_name: (editForm.full_name || '').trim(), code: editForm.code, role: editForm.role, active: editForm.active };
                if (editForm.pin) body.pin = editForm.pin;
                await ApiClient.put('/api/employees/' + id, body);
                editingId.value = null;
                success.value = 'Empleado actualizado';
                setTimeout(() => { success.value = ''; }, 2000);
                await loadEmployees();
            } catch (e) {
                editError.value = errorText(e, 'Error al guardar');
            } finally {
                saving.value = false;
            }
        }

        async function handleDeactivate(id) {
            try {
                await ApiClient.put('/api/employees/' + id, { active: false });
                success.value = 'Empleado desactivado';
                setTimeout(() => { success.value = ''; }, 2000);
                await loadEmployees();
            } catch (e) {
                error.value = 'Error al desactivar';
            }
        }

        async function handleUnlock(id) {
            try {
                await ApiClient.post('/api/employees/' + id + '/unlock', {});
                success.value = 'Empleado desbloqueado';
                setTimeout(() => { success.value = ''; }, 2000);
                await loadEmployees();
            } catch (e) {
                error.value = 'Error al desbloquear';
            }
        }

        // Ajustes → Email: the shop's outgoing account, for tickets by email.
        const INPUT_STYLE = 'width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;';
        const mail = Vue.reactive({ host: '', port: 587, encryption: 'tls', username: '', from_email: '', from_name: '', password: '', has_password: false, configured: false, backup_alert_email: '' });
        const mailSaving = Vue.ref(false);
        const mailError = Vue.ref('');
        const mailTestTo = Vue.ref('');
        const mailTestStatus = Vue.ref('');

        function applyMail(data) {
            Object.assign(mail, { backup_alert_email: '' }, data || {}, { password: '' });
            savedState.mail = pick(mail, MAIL_FIELDS);
        }

        async function loadMail() {
            mailError.value = '';
            try {
                const resp = await ApiClient.get('/api/settings/email');
                applyMail(resp.data);
            } catch (e) {
                mailError.value = errorText(e, 'Error al cargar el email');
            }
        }

        async function saveMail() {
            mailSaving.value = true;
            mailError.value = '';
            try {
                const body = { host: mail.host, port: Number(mail.port), encryption: mail.encryption, username: mail.username, from_email: mail.from_email, from_name: mail.from_name, backup_alert_email: String(mail.backup_alert_email || '').trim() };
                if (mail.password) body.password = mail.password;
                const resp = await ApiClient.put('/api/settings/email', body);
                applyMail(resp.data);
                flash('Email guardado');
            } catch (e) {
                mailError.value = errorText(e, 'Error al guardar el email');
            } finally {
                mailSaving.value = false;
            }
        }

        async function sendMailTest() {
            mailTestStatus.value = 'Enviando…';
            try {
                await ApiClient.post('/api/settings/email/test', { to: mailTestTo.value });
                mailTestStatus.value = 'Email de prueba enviado. Revisa la bandeja de entrada (y el spam).';
            } catch (e) {
                mailTestStatus.value = errorText(e, 'No se pudo enviar el email de prueba');
            }
        }

        // Ajustes → Cajas: where each till's ticket series and the credit notes continue
        // (after restoring or reinstalling). Forward only; every change is audited.
        const numbering = Vue.reactive({ data: null, error: '', editing: null, next: '', reason: '', saving: false });

        async function loadNumbering() {
            try {
                const resp = await ApiClient.get('/api/numbering');
                numbering.data = (resp && resp.data) || null;
            } catch (e) {
                numbering.error = errorText(e, 'Error al cargar la numeración');
            }
        }

        function tillNumbering(terminalId) {
            const tillsOf = (numbering.data && numbering.data.tills) || [];
            return tillsOf.find((t) => t.terminal_id === terminalId) || null;
        }

        // kind: 'ticket' (the till being edited), 'credit' (the credit notes) or
        // 'invoice' (the shop's full invoices, «Próxima factura»).
        function startNumberingChange(kind) {
            const current = kind === 'credit'
                ? numbering.data && numbering.data.credit_notes
                : kind === 'invoice'
                    ? numbering.data && numbering.data.invoices
                    : terminal.value && tillNumbering(terminal.value.id);
            numbering.editing = kind;
            numbering.next = current ? String(current.next_number) : '';
            numbering.originalNext = numbering.next;
            numbering.reason = '';
            numbering.error = '';
        }

        function cancelNumberingChange() {
            numbering.editing = null;
            numbering.error = '';
        }

        async function saveNumberingChange() {
            const kind = numbering.editing;
            if (!kind) return false;
            const url = kind === 'credit'
                ? '/api/numbering/credit-notes'
                : kind === 'invoice'
                    ? '/api/numbering/invoices'
                    : '/api/numbering/tickets/' + encodeURIComponent(terminal.value ? terminal.value.id : '');
            numbering.saving = true;
            numbering.error = '';
            try {
                const next = String(numbering.next).trim();
                await ApiClient.put(url, { next_number: /^\d+$/.test(next) ? Number(next) : next, reason: String(numbering.reason || '').trim() });
                numbering.editing = null;
                flash('Numeración cambiada');
                await loadNumbering();
                return true;
            } catch (e) {
                numbering.error = errorText(e, 'Error al cambiar la numeración');
                return false;
            } finally {
                numbering.saving = false;
            }
        }

        // Ajustes → VERI*FACTU: the switch (QR tributario on the tickets) and the chain check.
        const verifactu = Vue.reactive({ enabled: false, available: false, unavailableReason: '', loaded: false, saving: false, error: '', chain: null, checking: false });

        async function loadVerifactu() {
            verifactu.error = '';
            try {
                const resp = await ApiClient.get('/api/fiscal/settings');
                verifactu.enabled = !!(resp.data && resp.data.enabled);
                verifactu.available = !!(resp.data && resp.data.available);
                verifactu.unavailableReason = (resp.data && resp.data.unavailable_reason) || '';
                verifactu.loaded = true;
            } catch (e) {
                verifactu.error = errorText(e, 'Error al cargar VERI*FACTU');
            }
        }

        async function saveVerifactu(enabled) {
            verifactu.saving = true;
            verifactu.error = '';
            try {
                const resp = await ApiClient.put('/api/fiscal/settings', { enabled });
                verifactu.enabled = !!(resp.data && resp.data.enabled);
                flash(verifactu.enabled ? 'VERI*FACTU activado' : 'VERI*FACTU desactivado');
            } catch (e) {
                verifactu.error = errorText(e, 'Error al guardar VERI*FACTU');
            } finally {
                verifactu.saving = false;
            }
        }

        async function checkChain() {
            verifactu.checking = true;
            verifactu.error = '';
            try {
                const resp = await ApiClient.get('/api/fiscal/chain');
                verifactu.chain = resp.data;
            } catch (e) {
                verifactu.error = errorText(e, 'No se pudo comprobar la cadena de registros');
            } finally {
                verifactu.checking = false;
            }
        }

        // Ajustes → Cierres de caja: the last 50 closes of the shop, each Z printable again.
        const cashCloses = Vue.reactive({ list: [], loading: false, error: '', printingId: null });

        async function loadCashCloses() {
            cashCloses.loading = true;
            cashCloses.error = '';
            try {
                const resp = await ApiClient.cashZList();
                cashCloses.list = (resp && resp.data) || [];
            } catch (e) {
                cashCloses.error = errorText(e, 'Error al cargar los cierres de caja');
            } finally {
                cashCloses.loading = false;
            }
        }

        const Z_MESSAGES = { queued: 'Imprimiendo informe Z…', printed: 'Informe Z impreso', pdf: 'Informe Z descargado en PDF' };

        async function printClose(sessionId) {
            cashCloses.printingId = sessionId;
            cashCloses.error = '';
            try {
                const outcome = await useSession.printCashReport('z', sessionId);
                flash(Z_MESSAGES[outcome] || 'Informe Z enviado');
            } catch (e) {
                cashCloses.error = errorText(e, 'No se ha podido imprimir el informe Z');
            } finally {
                cashCloses.printingId = null;
            }
        }

        function pdfClose(sessionId) {
            ApiClient.cashZPdf(sessionId);
        }

        /** «1.234,56 €»; signed adds «+» to a positive difference. */
        function formatMoney(value, signed) {
            const amount = Number(value || 0);
            const [whole, cents] = Math.abs(amount).toFixed(2).split('.');
            const text = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + cents + ' €';
            if (amount < 0) return '-' + text;
            return signed && amount > 0 ? '+' + text : text;
        }

        function formatDateTime(value) {
            const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
            return match ? match[3] + '/' + match[2] + '/' + match[1] + ' ' + match[4] + ':' + match[5] : String(value || '');
        }

        // The window can be enlarged from any corner (touch or mouse); its size is
        // remembered on this computer.
        const SIZE_KEY = 'tpv.settings.size';
        const modalSize = Vue.reactive((() => {
            try {
                const saved = JSON.parse(localStorage.getItem(SIZE_KEY) || 'null');
                if (saved && saved.w > 0 && saved.h > 0) return { w: saved.w, h: saved.h };
            } catch (e) { /* default size */ }
            return { w: null, h: null };
        })());
        const modalBox = Vue.ref(null);
        const modalStyle = Vue.computed(() => (modalSize.w ? { width: modalSize.w + 'px', height: modalSize.h + 'px', maxWidth: 'none', maxHeight: 'none' } : {}));

        function startResize(e, sx, sy) {
            const box = modalBox.value;
            if (!box) return;
            const rect = box.getBoundingClientRect();
            const start = { w: rect.width, h: rect.height };
            const x0 = e.clientX;
            const y0 = e.clientY;
            const move = (ev) => {
                const size = SettingsModal.resizedSize(start, ev.clientX - x0, ev.clientY - y0, sx, sy, { w: window.innerWidth, h: window.innerHeight });
                modalSize.w = size.w;
                modalSize.h = size.h;
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                try { localStorage.setItem(SIZE_KEY, JSON.stringify({ w: modalSize.w, h: modalSize.h })); } catch (err) { /* not remembered */ }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        }

        // Closing the window saves, section by section, what was changed and not saved
        // yet; a save that fails keeps the window open on its section with its error.
        const closing = Vue.ref(false);

        function pendingSaves() {
            const steps = [];
            if (terminal.value && savedState.shop !== null && pick(terminal.value, SHOP_FIELDS) !== savedState.shop) {
                steps.push(['tienda', async () => { await saveShop(); return !terminalError.value; }]);
            }
            if (payMethods.value && savedState.payMethods !== null && JSON.stringify(payMethods.value) !== savedState.payMethods) {
                steps.push(['tienda', async () => { await savePaymentMethods(); return !payMethodsError.value; }]);
            }
            if (terminal.value && savedState.till !== null && pick(terminal.value, TILL_FIELDS) !== savedState.till) {
                steps.push(['cajas', async () => { await saveTill(); return !terminalError.value; }]);
            }
            if (terminal.value && String(syncInterval.value) !== savedState.syncPull) {
                steps.push(['sincronizacion', async () => { await saveSyncInterval(); return !syncError.value; }]);
            }
            if (terminal.value && String(syncPushInterval.value) !== savedState.syncPush) {
                steps.push(['sincronizacion', async () => { await saveSyncPushInterval(); return !syncError.value; }]);
            }
            if (savedState.mail !== null && (pick(mail, MAIL_FIELDS) !== savedState.mail || mail.password)) {
                steps.push(['email', async () => { await saveMail(); return !mailError.value; }]);
            }
            if (editingId.value !== null && JSON.stringify(editForm) !== savedState.edit) {
                steps.push(['empleados', async () => { await handleSave(editingId.value); return editingId.value === null; }]);
            }
            if (showCreateForm.value && (createForm.name || createForm.full_name || createForm.code || createForm.pin)) {
                steps.push(['empleados', async () => { await handleCreate(); return !showCreateForm.value; }]);
            }
            if (numbering.editing && (String(numbering.next).trim() !== String(numbering.originalNext ?? '') || String(numbering.reason || '').trim())) {
                steps.push(['cajas', () => saveNumberingChange()]);
            }
            return steps;
        }

        async function closeSettings() {
            if (closing.value) return;
            closing.value = true;
            try {
                for (const [section, save] of pendingSaves()) {
                    if (!(await save())) {
                        activeSection.value = section;
                        return;
                    }
                }
                emit('close');
            } finally {
                closing.value = false;
            }
        }

        Vue.onMounted(() => {
            loadEmployees();
            loadTerminal();
            loadTills();
            loadSystemPrinters();
            loadNumbering();
            loadPaymentMethods();
        });

        return {
            PAY_METHODS, payMethods, payMethodsError, payMethodsSaving, loadPaymentMethods, togglePayMethod, savePaymentMethods,
            modalBox, modalStyle, startResize,
            brandBase: ((typeof window !== 'undefined' && window.TPV_BASE) || '') + '/assets/img/brand',
            labelTestStatus, printTestLabel,
            systemPrinters,
            employees, loading, error, success,
            activeSection,
            showCreateForm, createForm, createError, creating, handleCreate,
            editingId, editForm, editError, saving, startEdit, cancelEdit, handleSave,
            handleDeactivate, handleUnlock,
            terminal, terminalLoading, terminalSaving, terminalError, loadTerminal, saveShop, saveTill,
            tills, tillsError, confirmMoveId, newTill, creatingTill, loadTills, editTill, moveTillHere, createTill, tillSeen, printerTypeLabel,
            handleLogoUpload, handleLogoDrop, handleLogoPaste, logoError, logoDragOver, removeLogo,
            syncInterval, syncPushInterval, syncSaving, syncPushSaving, syncError,
            syncNowRunning, syncLastResult,
            saveSyncInterval, saveSyncPushInterval, syncNow,
            INPUT_STYLE, mail, mailSaving, mailError, mailTestTo, mailTestStatus, saveMail, sendMailTest, loadMail,
            verifactu, loadVerifactu, saveVerifactu, checkChain,
            numbering, loadNumbering, tillNumbering, startNumberingChange, cancelNumberingChange, saveNumberingChange,
            cashCloses, loadCashCloses, printClose, pdfClose, formatMoney, formatDateTime,
            provisioning, provisioningLoading, provisioningError, loadProvisioning, provisioningLabel,
            pairing, openPairing, runPairing,
            closing, closeSettings,
            emit,
        };
    },
    template: `
        <!-- Backdrop -->
        <div
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:100; display:flex; align-items:center; justify-content:center; padding:24px; box-sizing:border-box;">

            <div ref="modalBox" :style="modalStyle" style="position:relative; background:var(--surface); border:1px solid var(--border); border-radius:16px; width:100%; max-width:720px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;">
                <!-- Corner handles: drag to enlarge or shrink the window -->
                <div class="settings-resize settings-resize-nw" @pointerdown.prevent="startResize($event, -1, -1)" aria-hidden="true"></div>
                <div class="settings-resize settings-resize-ne" @pointerdown.prevent="startResize($event, 1, -1)" aria-hidden="true"></div>
                <div class="settings-resize settings-resize-sw" @pointerdown.prevent="startResize($event, -1, 1)" aria-hidden="true"></div>
                <div class="settings-resize settings-resize-se" @pointerdown.prevent="startResize($event, 1, 1)" aria-hidden="true"></div>
                <!-- Modal header -->
                <div style="padding:20px 24px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                    <div style="font-size:16px; font-weight:700; color:var(--text);">⚙ Configuración</div>
                    <!-- The only way to close Ajustes: it saves what was changed first. -->
                    <button @click="closeSettings" :disabled="closing" :title="closing ? 'Guardando…' : 'Cerrar (guarda los cambios)'" aria-label="Cerrar" style="background:transparent; border:none; color:var(--text3); font-size:20px; cursor:pointer; line-height:1; display:flex; align-items:center;"><icon name="close" :size="20"></icon></button>
                </div>

                <!-- Toast success / error -->
                <div v-if="success" style="margin:12px 24px 0; padding:10px 14px; background:var(--green-bg, rgba(0,200,100,0.12)); color:var(--green); border-radius:8px; font-size:13px;">{{ success }}</div>
                <div v-if="error" style="margin:12px 24px 0; padding:10px 14px; background:var(--red-bg); color:var(--red); border-radius:8px; font-size:13px;">{{ error }}</div>

                <!-- Body: sidebar + content -->
                <div style="flex:1; display:flex; overflow:hidden;">
                    <!-- Sidebar -->
                    <div style="width:180px; border-right:1px solid var(--border); display:flex; flex-direction:column; flex-shrink:0; overflow-y:auto; padding:12px 0;">
                        <button @click="activeSection = 'empleados'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'empleados' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : 'hover:bg-surface2'"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'empleados' ? 'var(--surface2)' : 'transparent'">
                            <icon name="user" :size="18"></icon> Empleados
                        </button>
                        <button @click="activeSection = 'tienda'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'tienda' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'tienda' ? 'var(--surface2)' : 'transparent'">
                            <icon name="print" :size="18"></icon> Tienda
                        </button>
                        <button @click="activeSection = 'cajas'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'cajas' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'cajas' ? 'var(--surface2)' : 'transparent'">
                            <icon name="cashier" :size="18"></icon> Cajas
                        </button>
                        <button @click="activeSection = 'sincronizacion'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'sincronizacion' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'sincronizacion' ? 'var(--surface2)' : 'transparent'">
                            <icon name="sync" :size="18"></icon> Sincronización
                        </button>
                        <button @click="activeSection = 'impresion'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'impresion' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'impresion' ? 'var(--surface2)' : 'transparent'">
                            <icon name="print" :size="18"></icon> Historial de impresión
                        </button>
                        <button @click="activeSection = 'email'; loadMail()"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'email' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'email' ? 'var(--surface2)' : 'transparent'">
                            <icon name="mail" :size="18"></icon> Email
                        </button>
                        <button @click="activeSection = 'verifactu'; loadVerifactu()"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'verifactu' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'verifactu' ? 'var(--surface2)' : 'transparent'">
                            <icon name="receipt" :size="18"></icon> VERI*FACTU
                        </button>
                        <button @click="activeSection = 'cierres'; loadCashCloses()"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'cierres' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'cierres' ? 'var(--surface2)' : 'transparent'">
                            <icon name="print" :size="18"></icon> Cierres de caja
                        </button>
                        <button @click="activeSection = 'acerca'; loadProvisioning()"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'acerca' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            @mouseover="$event.currentTarget.style.background='var(--surface2)'" @mouseout="$event.currentTarget.style.background=activeSection === 'acerca' ? 'var(--surface2)' : 'transparent'">
                            <icon name="note" :size="18"></icon> Acerca de
                        </button>
                    </div>

                    <!-- Content area -->
                    <div style="flex:1; overflow-y:auto; padding:20px 24px;">

                        <!-- ==================== EMPLEADOS ==================== -->
                        <div v-if="activeSection === 'empleados'">
                            <!-- Add employee button -->
                            <div style="display:flex; justify-content:flex-end; margin-bottom:14px;">
                                <button @click="showCreateForm = !showCreateForm"
                                    style="padding:8px 16px; border-radius:9px; border:1px solid var(--border); background:var(--surface2); color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px; font-weight:500;">
                                    {{ showCreateForm ? 'Cancelar' : '+ Nuevo empleado' }}
                                </button>
                            </div>

                            <!-- Create form -->
                            <div v-if="showCreateForm" style="background:var(--surface2); border:1px solid var(--border); border-radius:12px; padding:16px; margin-bottom:20px; display:flex; flex-direction:column; gap:12px;">
                                <div style="font-size:13px; font-weight:600; color:var(--text);">Nuevo empleado</div>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <input v-model="createForm.name" type="text" placeholder="Nombre de usuario" title="Nombre de usuario"
                                        style="flex:1; min-width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none;">
                                    <input v-model="createForm.full_name" type="text" maxlength="128" placeholder="Nombre y apellidos" title="Nombre y apellidos (sale en el ticket)"
                                        style="flex:1; min-width:180px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none;">
                                    <input v-model="createForm.code" type="text" placeholder="Código (opcional)"
                                        style="width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none;">
                                    <input v-model="createForm.pin" type="password" placeholder="PIN"
                                        style="width:130px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:'IBM Plex Mono', monospace; font-size:14px; outline:none;">
                                    <select v-model="createForm.role"
                                        style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer;">
                                        <option value="cashier">Cajero</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div v-if="createError" style="color:var(--red); font-size:12px;">{{ createError }}</div>
                                <button @click="handleCreate" :disabled="creating"
                                    style="align-self:flex-end; padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                    :style="creating ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                    {{ creating ? 'Guardando...' : 'Crear' }}
                                </button>
                            </div>

                            <!-- Employee list -->
                            <div v-if="loading" style="text-align:center; color:var(--text3); padding:20px; font-size:13px;">Cargando...</div>
                            <div v-else-if="employees.length === 0" style="text-align:center; color:var(--text3); padding:20px; font-size:13px;">No hay empleados</div>
                            <div v-else style="display:flex; flex-direction:column; gap:8px;">
                                <div v-for="emp in employees" :key="emp.id"
                                    style="background:var(--surface2); border:1px solid var(--border); border-radius:12px; padding:14px 16px;">

                                    <!-- View mode -->
                                    <div v-if="editingId !== emp.id" style="display:flex; align-items:center; gap:12px;">
                                        <div style="flex:1;">
                                            <div style="font-size:14px; font-weight:600; color:var(--text);">
                                                {{ emp.name }}
                                                <span v-if="emp.code" style="font-size:11px; color:var(--text3); font-weight:400; margin-left:6px;">#{{ emp.code }}</span>
                                            </div>
                                            <div v-if="emp.full_name" style="font-size:12px; color:var(--text2); margin-top:2px;">{{ emp.full_name }}</div>
                                            <div style="font-size:12px; color:var(--text3); margin-top:2px;">
                                                {{ emp.role === 'admin' ? 'Administrador' : 'Cajero' }}
                                                <span v-if="emp.locked" style="color:var(--red); margin-left:6px;">• bloqueado</span>
                                                <span v-else-if="!emp.active" style="color:var(--red); margin-left:6px;">• inactivo</span>
                                                <span v-else style="color:var(--green); margin-left:6px;">• activo</span>
                                            </div>
                                        </div>
                                        <button v-if="emp.locked" @click="handleUnlock(emp.id)"
                                            style="padding:6px 14px; border-radius:8px; border:1px solid var(--red); background:transparent; color:var(--red); cursor:pointer; font-family:inherit; font-size:12px; font-weight:500;">
                                            Desbloquear
                                        </button>
                                        <button @click="startEdit(emp)"
                                            style="padding:6px 14px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                                            Editar
                                        </button>
                                        <button v-if="emp.active && !emp.locked" @click="handleDeactivate(emp.id)"
                                            style="padding:6px 14px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--red); cursor:pointer; font-family:inherit; font-size:12px;">
                                            Desactivar
                                        </button>
                                    </div>

                                    <!-- Edit mode -->
                                    <div v-else style="display:flex; flex-direction:column; gap:10px;">
                                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                            <input v-model="editForm.name" type="text" placeholder="Nombre de usuario" title="Nombre de usuario"
                                                style="flex:1; min-width:140px; padding:7px 11px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; outline:none;">
                                            <input v-model="editForm.full_name" type="text" maxlength="128" placeholder="Nombre y apellidos" title="Nombre y apellidos (sale en el ticket)"
                                                style="flex:1; min-width:180px; padding:7px 11px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; outline:none;">
                                            <input v-model="editForm.code" type="text" placeholder="Código (opcional)"
                                                style="width:130px; padding:7px 11px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; outline:none;">
                                            <input v-model="editForm.pin" type="password" placeholder="Nuevo PIN (opcional)"
                                                style="width:160px; padding:7px 11px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:'IBM Plex Mono', monospace; font-size:13px; outline:none;">
                                            <select v-model="editForm.role"
                                                style="padding:7px 11px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; outline:none; cursor:pointer;">
                                                <option value="cashier">Cajero</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                            <label style="display:flex; align-items:center; gap:6px; color:var(--text2); font-size:13px; cursor:pointer;">
                                                <input type="checkbox" v-model="editForm.active"> Activo
                                            </label>
                                        </div>
                                        <div v-if="editError" style="color:var(--red); font-size:12px;">{{ editError }}</div>
                                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                                            <button @click="cancelEdit" style="padding:6px 14px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cancelar</button>
                                            <button @click="handleSave(emp.id)" :disabled="saving"
                                                style="padding:6px 14px; border-radius:8px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:12px; font-weight:600;"
                                                :style="saving ? 'opacity:0.6;' : ''">
                                                {{ saving ? 'Guardando...' : 'Guardar' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ==================== TIENDA ==================== -->
                        <div v-if="activeSection === 'tienda'">
                            <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">🏬 Datos de la tienda</div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:16px;">Son los mismos en todas las cajas: se guardan en todas a la vez y todas imprimen el mismo ticket.</div>
                            <div v-if="terminalLoading" style="color:var(--text3); font-size:13px;">Cargando...</div>
                            <div v-else-if="!terminal" style="color:var(--text3); font-size:13px;">Este equipo no tiene caja asignada.</div>
                            <div v-else style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Nombre de la tienda</label>
                                    <input v-model="terminal.store_name" type="text" maxlength="120" placeholder="Moda Centro"
                                        style="width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Nombre en grande arriba del ticket. Vacío: se usa la razón social.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Razón social</label>
                                    <input v-model="terminal.legal_name" type="text" maxlength="120" placeholder="Mi Tienda Moda SL"
                                        style="width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Nombre fiscal del titular. Se imprime en tickets y abonos si es distinto del nombre de la tienda.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">NIF</label>
                                    <input v-model="terminal.tax_id" type="text" maxlength="16" autocapitalize="characters" placeholder="B12345674"
                                        style="width:100%; max-width:200px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; text-transform:uppercase;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Obligatorio en la factura simplificada. Se comprueba la letra o cifra de control.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Número de cuenta (IBAN)</label>
                                    <input v-model="terminal.bank_account" type="text" maxlength="42" autocapitalize="characters" placeholder="ES91 2100 0418 4502 0005 1332"
                                        style="width:100%; max-width:340px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:'IBM Plex Mono'; font-size:14px; outline:none; box-sizing:border-box; text-transform:uppercase;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Se imprime en los tickets pagados por transferencia, con el número del ticket como concepto.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Dirección de la tienda</label>
                                    <textarea v-model="terminal.store_address" rows="2" placeholder="Calle Principal 123, 28001 Madrid"
                                        style="width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; resize:vertical;"></textarea>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Se imprime debajo del nombre de la tienda en el ticket.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Encabezado del ticket</label>
                                    <textarea v-model="terminal.ticket_header" rows="4" placeholder="www.tutienda.com&#10;Gracias por visitarnos"
                                        style="width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; resize:vertical;"></textarea>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Texto libre multi-línea que aparecerá en la cabecera del ticket.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Footer del ticket</label>
                                    <textarea v-model="terminal.ticket_footer" rows="4" placeholder="Cambios en 30 días con ticket&#10;Gracias por su compra"
                                        style="width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; resize:vertical;"></textarea>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Texto libre multi-línea al final del ticket.</div>
                                </div>

                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Logo del ticket</label>
                                    <div style="display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap;">
                                        <div style="flex:1; min-width:200px;">
                                            <div tabindex="0" @dragover.prevent="logoDragOver = true" @dragleave="logoDragOver = false" @drop="handleLogoDrop" @paste="handleLogoPaste"
                                                :style="{ borderColor: logoDragOver ? 'var(--primary)' : 'var(--border)' }"
                                                style="border:2px dashed var(--border); border-radius:10px; padding:14px; text-align:center; color:var(--text2); font-size:13px; outline:none;">
                                                <label style="display:inline-block; padding:6px 14px; border-radius:8px; background:var(--primary); color:#fff; cursor:pointer; font-size:13px; font-weight:600;">
                                                    Elegir imagen
                                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" @change="handleLogoUpload" style="display:none;">
                                                </label>
                                                <div style="margin-top:8px;">o arrastra la imagen aquí, o haz clic en este recuadro y pégala con Ctrl+V</div>
                                            </div>
                                            <div style="font-size:12px; color:var(--text3); margin-top:4px;">PNG, JPG, GIF o WebP, hasta 2 MB. Pulsa «Guardar» para conservarlo.</div>
                                            <div v-if="logoError" style="font-size:12px; color:var(--red); margin-top:4px;">{{ logoError }}</div>
                                            <button v-if="terminal.ticket_logo" @click="removeLogo"
                                                style="margin-top:8px; padding:4px 12px; border-radius:6px; border:1px solid var(--red); background:transparent; color:var(--red); cursor:pointer; font-family:inherit; font-size:12px;">
                                                Quitar logo
                                            </button>
                                        </div>
                                        <div v-if="terminal.ticket_logo" style="flex-shrink:0;">
                                            <img :src="terminal.ticket_logo" alt="Logo del ticket"
                                                style="max-width:120px; max-height:80px; object-fit:contain; border:1px solid var(--border); border-radius:8px; background:var(--surface2);">
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Código del ticket</label>
                                    <select v-model="terminal.ticket_code"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer; box-sizing:border-box;">
                                        <option value="barcode">Código de barras</option>
                                        <option value="qr">Código QR</option>
                                        <option value="both">Ambos</option>
                                    </select>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Lo que se imprime al pie del ticket para escanearlo en devoluciones. Los lectores láser normales solo leen el código de barras.</div>
                                </div>

                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Plazo de devolución (días)</label>
                                    <input v-model.number="terminal.refund_window_days" type="number" min="1" max="3650" placeholder="30"
                                        style="width:100%; max-width:200px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Pasado este plazo desde la venta no se admiten devoluciones</div>
                                </div>

                                <div>
                                    <button @click="saveShop" :disabled="terminalSaving"
                                        style="padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="terminalSaving ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                        {{ terminalSaving ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                                <div v-if="terminalError" style="color:var(--red); font-size:12px;">{{ terminalError }}</div>
                            </div>

                            <div v-if="payMethods" style="margin-top:22px; display:flex; flex-direction:column; gap:10px;">
                                <div style="font-size:14px; font-weight:600; color:var(--text);">Métodos de pago</div>
                                <div style="font-size:12px; color:var(--text3);">Los que se ofrecen al cobrar, en todas las cajas. El pago mixto necesita Efectivo y Tarjeta.</div>
                                <label v-for="m in PAY_METHODS" :key="m.id" style="display:flex; align-items:center; gap:10px; min-height:40px; font-size:14px; color:var(--text); cursor:pointer;">
                                    <input type="checkbox" :checked="payMethods.includes(m.id)" @change="togglePayMethod(m.id)" style="width:20px; height:20px;" />
                                    {{ m.label }}
                                </label>
                                <div>
                                    <button @click="savePaymentMethods" :disabled="payMethodsSaving"
                                        style="padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="payMethodsSaving ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                        {{ payMethodsSaving ? 'Guardando...' : 'Guardar métodos de pago' }}
                                    </button>
                                </div>
                                <div v-if="payMethodsError" style="color:var(--red); font-size:12px;">{{ payMethodsError }}</div>
                            </div>
                        </div>

                        <!-- ==================== CAJAS ==================== -->
                        <div v-if="activeSection === 'cajas'">
                            <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">🖥 Cajas</div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:12px;">Cada ordenador trabaja con una caja: su cajón, su impresora, su datáfono y su letra de ticket. Con «Agente de impresión» imprime el ordenador de la caja, con su programa de impresión. Con las demás impresoras imprime el servidor: una segunda caja necesita una impresora de red o compartida con el servidor.</div>
                            <div v-if="tillsError" style="color:var(--red); font-size:12px; margin-bottom:8px;">{{ tillsError }}</div>
                            <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px;">
                                <div v-for="till in tills" :key="till.id"
                                    style="border:1px solid var(--border); border-radius:10px; padding:10px 12px; display:flex; flex-direction:column; gap:6px;"
                                    :style="terminal && terminal.id === till.id ? 'border-color:var(--green-dim);' : ''">
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                                        <div style="min-width:0;">
                                            <div style="font-size:14px; font-weight:600; color:var(--text);">{{ till.name }} <span style="font-family:'IBM Plex Mono'; color:var(--text3);">({{ till.ticket_letter || '—' }})</span></div>
                                            <div style="font-size:12px; color:var(--text3);">
                                                Impresora: {{ printerTypeLabel(till.printer_type) }} · Datáfono: {{ till.dataphone_mode === 'independent' ? 'independiente' : 'integrado' }} ·
                                                {{ till.this_device ? 'Este equipo' : (till.device ? 'Otro equipo, visto ' + tillSeen(till) : 'Sin equipo') }}
                                            </div>
                                        </div>
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <button @click="editTill(till)"
                                                style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Editar</button>
                                            <button v-if="!till.this_device" @click="moveTillHere(till)"
                                                style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text); cursor:pointer; font-family:inherit; font-size:12px; font-weight:600;">Pasar la caja a este equipo</button>
                                        </div>
                                    </div>
                                    <div v-if="confirmMoveId === till.id" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; border-top:1px solid var(--border); padding-top:6px;">
                                        <div style="font-size:12px; color:var(--text2);">Está en otro equipo; aquel quedará sin caja.</div>
                                        <button @click="moveTillHere(till)"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:12px; font-weight:700;">Pasar la caja a este equipo</button>
                                        <button @click="confirmMoveId = null"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cancelar</button>
                                    </div>
                                </div>
                            </div>

                            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:20px;">
                                <input v-model="newTill.name" type="text" maxlength="64" placeholder="Nombre de la nueva caja"
                                    style="flex:1; min-width:160px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                <input v-model="newTill.ticket_letter" type="text" maxlength="1" autocapitalize="characters" placeholder="Letra"
                                    style="width:72px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; text-transform:uppercase;">
                                <button @click="createTill" :disabled="creatingTill"
                                    style="padding:8px 16px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;">Crear caja</button>
                            </div>

                            <div v-if="terminalLoading" style="color:var(--text3); font-size:13px;">Cargando...</div>
                            <div v-else-if="!terminal" style="color:var(--text3); font-size:13px;">Elige una caja para editarla.</div>
                            <div v-else style="display:flex; flex-direction:column; gap:16px; border-top:1px solid var(--border); padding-top:16px;">
                                <div style="font-size:13px; font-weight:600; color:var(--text);">Editar {{ terminal.name }}</div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Nombre de la caja</label>
                                    <input v-model="terminal.name" type="text" placeholder="Caja 1"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Letra de caja</label>
                                    <input v-model="terminal.ticket_letter" type="text" maxlength="1" autocapitalize="characters" placeholder="A"
                                        style="width:100%; max-width:200px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; text-transform:uppercase;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Primera letra del número de ticket (A26000123). Cada caja tiene la suya.</div>
                                </div>
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <div style="font-size:13px; color:var(--text);">Próximo número de ticket: <span style="font-family:'IBM Plex Mono'; font-weight:600;">{{ (tillNumbering(terminal.id) && tillNumbering(terminal.id).next_code) || '—' }}</span></div>
                                        <button v-if="tillNumbering(terminal.id) && tillNumbering(terminal.id).letter && numbering.editing !== 'ticket'" @click="startNumberingChange('ticket')"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cambiar</button>
                                    </div>
                                    <div v-if="numbering.editing === 'ticket'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:6px;">
                                        <input v-model="numbering.next" type="number" min="1" max="999999" placeholder="Próximo número" aria-label="Próximo número"
                                            style="width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        <input v-model="numbering.reason" type="text" maxlength="255" placeholder="Motivo" aria-label="Motivo"
                                            style="flex:1; min-width:180px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        <button @click="saveNumberingChange" :disabled="numbering.saving"
                                            style="min-height:36px; padding:4px 14px; border-radius:8px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:12px; font-weight:700;">{{ numbering.saving ? 'Guardando...' : 'Guardar' }}</button>
                                        <button @click="cancelNumberingChange"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cancelar</button>
                                    </div>
                                    <div v-if="numbering.editing === 'ticket' && numbering.error" style="color:var(--red); font-size:12px; margin-top:4px;">{{ numbering.error }}</div>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Solo puede avanzar. Úsalo tras restaurar o reinstalar para continuar la numeración donde se quedó; queda registrado.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Modo datáfono</label>
                                    <select v-model="terminal.dataphone_mode"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer; box-sizing:border-box;">
                                        <option value="integrated">Integrado</option>
                                        <option value="independent">Independiente</option>
                                    </select>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Independiente: el cobro se hace manualmente fuera del TPV</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Tipo de impresora</label>
                                    <select v-model="terminal.printer_type"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer; box-sizing:border-box;">
                                        <option value="none">Sin impresora</option>
                                        <option value="pdf">PDF</option>
                                        <option value="escpos">ESC/POS (red)</option>
                                        <option value="system">Impresora del sistema (USB)</option>
                                        <option value="agent">Agente de impresión</option>
                                    </select>
                                    <div v-if="terminal.printer_type === 'agent'" style="font-size:12px; color:var(--text3); margin-top:4px;">Imprime el ordenador de esta caja con el programa de impresión: tickets, cajón y etiquetas. Guarda la caja y conecta el agente.</div>
                                </div>
                                <div v-if="terminal.printer_type === 'system'">
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Impresora instalada en el servidor del TPV</label>
                                    <select v-model="terminal.printer_queue"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer; box-sizing:border-box;">
                                        <option value="" disabled>Elige una impresora</option>
                                        <option v-for="q in systemPrinters" :key="q" :value="q">{{ q }}</option>
                                    </select>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Imprime el ticket directamente (con corte y apertura del cajón). La imprime el servidor del TPV: tiene que estar instalada (USB o compartida) en ese ordenador.</div>
                                    <div v-if="systemPrinters.length === 0" style="font-size:12px; color:var(--red); margin-top:4px;">No se encuentran impresoras instaladas en el servidor del TPV.</div>
                                </div>
                                <template v-if="terminal.printer_type === 'escpos'">
                                    <div>
                                        <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Dirección IP</label>
                                    <input v-model="terminal.printer_ip" type="text" placeholder="192.168.1.100"
                                        style="width:100%; max-width:280px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Puerto</label>
                                    <input v-model="terminal.printer_port" type="number" placeholder="9100"
                                        style="width:100%; max-width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                </div>
                                </template>

                                <print-agent-panel v-if="terminal.printer_type === 'agent'" :key="'print-agent-' + terminal.id" :terminal-id="terminal.id" :terminal-name="terminal.name" />

                                <div>
                                    <label for="label-height-mm" style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Alto de etiqueta (mm)</label>
                                    <input id="label-height-mm" v-model.number="terminal.label_height_mm" type="number" min="25" max="60" placeholder="30"
                                        style="width:100%; max-width:140px; min-height:44px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Entre 25 y 60 mm. En la TM-T20 la etiqueta ocupa todo el ancho del papel.</div>
                                </div>
                                <label style="display:flex; align-items:center; gap:10px; min-height:44px; font-size:14px; color:var(--text); cursor:pointer;">
                                    <input type="checkbox" v-model="terminal.label_cut_each" style="width:20px; height:20px;">
                                    Cortar cada etiqueta
                                    <span style="font-size:12px; color:var(--text3);">(si no, salen en tiras de hasta 10 con un corte por tira)</span>
                                </label>
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <button type="button" class="tpv-btn" @click="printTestLabel" style="min-height:44px;">Imprimir etiqueta de prueba</button>
                                    <span v-if="labelTestStatus" style="font-size:13px; color:var(--text2);" role="status">{{ labelTestStatus }}</span>
                                </div>

                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Ancho del ticket (px)</label>
                                    <input v-model.number="terminal.ticket_width_px" type="number" min="100" max="600" placeholder="302 (80mm)"
                                        style="width:100%; max-width:200px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Deja vacío para usar 80mm por defecto</div>
                                </div>

                                <div v-if="'ticket_margin_left_mm' in terminal" style="display:flex; flex-direction:column; gap:12px;">
                                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                                        <div>
                                            <label for="ticket-margin-left" style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Margen izquierdo (mm)</label>
                                            <input id="ticket-margin-left" v-model="terminal.ticket_margin_left_mm" type="number" min="0" max="10" step="0.5" placeholder="2"
                                                style="width:100%; max-width:140px; min-height:44px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        </div>
                                        <div>
                                            <label for="ticket-margin-right" style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Margen derecho (mm)</label>
                                            <input id="ticket-margin-right" v-model="terminal.ticket_margin_right_mm" type="number" min="0" max="10" step="0.5" placeholder="2"
                                                style="width:100%; max-width:140px; min-height:44px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        </div>
                                        <div>
                                            <label for="ticket-logo-width" style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Tamaño del logo (% del ancho)</label>
                                            <input id="ticket-logo-width" v-model="terminal.ticket_logo_width_pct" type="number" min="20" max="100" step="5" placeholder="100"
                                                style="width:100%; max-width:140px; min-height:44px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        </div>
                                    </div>
                                    <div style="font-size:12px; color:var(--text3);">Vacío: 2 mm de margen y logo a todo el ancho.</div>
                                </div>

                                <div>
                                    <button @click="saveTill" :disabled="terminalSaving"
                                        style="padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="terminalSaving ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                        {{ terminalSaving ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                                <div v-if="terminalError" style="color:var(--red); font-size:12px;">{{ terminalError }}</div>
                            </div>

                            <div v-if="numbering.data" style="display:flex; flex-direction:column; gap:12px; border-top:1px solid var(--border); padding-top:16px; margin-top:16px;">
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <div style="font-size:13px; color:var(--text);">Abonos: próximo número <span style="font-family:'IBM Plex Mono'; font-weight:600;">{{ numbering.data.credit_notes.next_code }}</span></div>
                                        <button v-if="numbering.editing !== 'credit'" @click="startNumberingChange('credit')"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cambiar</button>
                                    </div>
                                    <div v-if="numbering.editing === 'credit'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:6px;">
                                        <input v-model="numbering.next" type="number" min="1" max="999999" placeholder="Próximo número" aria-label="Próximo número"
                                            style="width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        <input v-model="numbering.reason" type="text" maxlength="255" placeholder="Motivo" aria-label="Motivo"
                                            style="flex:1; min-width:180px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        <button @click="saveNumberingChange" :disabled="numbering.saving"
                                            style="min-height:36px; padding:4px 14px; border-radius:8px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:12px; font-weight:700;">{{ numbering.saving ? 'Guardando...' : 'Guardar' }}</button>
                                        <button @click="cancelNumberingChange"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cancelar</button>
                                    </div>
                                    <div v-if="numbering.editing === 'credit' && numbering.error" style="color:var(--red); font-size:12px; margin-top:4px;">{{ numbering.error }}</div>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Solo puede avanzar. Úsalo tras restaurar o reinstalar para continuar la numeración donde se quedó; queda registrado.</div>
                                </div>
                                <div v-if="numbering.data.invoices">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <div style="font-size:13px; color:var(--text);">Próxima factura <span style="font-family:'IBM Plex Mono'; font-weight:600;">{{ numbering.data.invoices.next_code }}</span></div>
                                        <button v-if="numbering.editing !== 'invoice'" @click="startNumberingChange('invoice')"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cambiar</button>
                                    </div>
                                    <div v-if="numbering.editing === 'invoice'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:6px;">
                                        <input v-model="numbering.next" type="number" min="1" max="999999" placeholder="Próximo número" aria-label="Próximo número de factura"
                                            style="width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        <input v-model="numbering.reason" type="text" maxlength="255" placeholder="Motivo" aria-label="Motivo"
                                            style="flex:1; min-width:180px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                        <button @click="saveNumberingChange" :disabled="numbering.saving"
                                            style="min-height:36px; padding:4px 14px; border-radius:8px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:12px; font-weight:700;">{{ numbering.saving ? 'Guardando...' : 'Guardar' }}</button>
                                        <button @click="cancelNumberingChange"
                                            style="min-height:36px; padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">Cancelar</button>
                                    </div>
                                    <div v-if="numbering.editing === 'invoice' && numbering.error" style="color:var(--red); font-size:12px; margin-top:4px;">{{ numbering.error }}</div>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Facturas completas de la tienda (serie F, una para todas las cajas). Solo puede avanzar y queda registrado.</div>
                                </div>
                                <div>
                                    <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">Cambios de numeración</div>
                                    <div v-if="!numbering.data.changes.length" style="font-size:12px; color:var(--text3);">Todavía no se ha cambiado la numeración.</div>
                                    <table v-else style="width:100%; border-collapse:collapse; font-size:12px;">
                                        <thead>
                                            <tr style="color:var(--text3); text-align:left; border-bottom:1px solid var(--border);">
                                                <th style="padding:6px; font-weight:600;">Fecha</th>
                                                <th style="padding:6px; font-weight:600;">Serie</th>
                                                <th style="padding:6px; font-weight:600;">De → a</th>
                                                <th style="padding:6px; font-weight:600;">Motivo</th>
                                                <th style="padding:6px; font-weight:600;">Usuario</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="change in numbering.data.changes" :key="change.id" style="border-bottom:1px solid var(--border); color:var(--text2);">
                                                <td style="padding:6px; white-space:nowrap;">{{ String(change.created_at).slice(0, 16) }}</td>
                                                <td style="padding:6px; font-family:'IBM Plex Mono';">{{ change.series }}</td>
                                                <td style="padding:6px; font-family:'IBM Plex Mono'; white-space:nowrap;">{{ change.previous_last }} → {{ change.new_last }}</td>
                                                <td style="padding:6px;">{{ change.reason }}</td>
                                                <td style="padding:6px;">{{ change.user_name || '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div v-else-if="numbering.error" style="color:var(--red); font-size:12px; margin-top:16px;">{{ numbering.error }}</div>
                        </div>

                        <!-- ==================== HISTORIAL DE IMPRESIÓN ==================== -->
                        <div v-if="activeSection === 'impresion'">
                            <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">Historial de impresión</div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:12px;">Trabajos del agente de impresión de los últimos 30 días: tickets, cajón y etiquetas.</div>
                            <print-history-panel :tills="tills" />
                        </div>

                        <!-- ==================== EMAIL ==================== -->
                        <div v-if="activeSection === 'verifactu'">
                            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">
                                <icon name="receipt" :size="16"></icon> VERI*FACTU
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:16px;">Cada ticket y cada devolución se guarda siempre en un registro encadenado con huella que no se puede modificar ni borrar. Al activar VERI*FACTU, los tickets imprimen además el «QR tributario» y la leyenda «VERI*FACTU». El envío de los registros a la AEAT aún no está disponible.</div>
                            <div v-if="verifactu.error" style="color:var(--red); font-size:13px; margin-bottom:12px;">{{ verifactu.error }}</div>
                            <label v-if="verifactu.loaded" style="display:flex; align-items:center; gap:10px; font-size:14px; color:var(--text); cursor:pointer; margin-bottom:20px;">
                                <input type="checkbox" :checked="verifactu.enabled" :disabled="verifactu.saving || !verifactu.available" @change="saveVerifactu($event.target.checked)" style="width:18px; height:18px;">
                                Activar VERI*FACTU (QR tributario en los tickets)
                            </label>
                            <div v-if="verifactu.loaded && !verifactu.available" style="font-size:12px; color:var(--text3); margin:-12px 0 20px;">{{ verifactu.unavailableReason }}</div>
                            <button @click="checkChain" :disabled="verifactu.checking"
                                style="min-height:40px; padding:0 16px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; color:var(--text); font-family:inherit; font-size:13px; cursor:pointer;">
                                {{ verifactu.checking ? 'Comprobando…' : 'Comprobar registros' }}
                            </button>
                            <div v-if="verifactu.chain" style="margin-top:12px; font-size:13px;">
                                <div v-if="verifactu.chain.ok" style="color:var(--green);">Cadena correcta: {{ verifactu.chain.records }} registros.</div>
                                <div v-else style="color:var(--red);">{{ verifactu.chain.reason }}<span v-if="verifactu.chain.broken_id"> (registro {{ verifactu.chain.broken_id }})</span></div>
                                <div style="color:var(--text3); margin-top:4px;">{{ verifactu.chain.protected ? 'La base de datos también impide modificar o borrar registros.' : 'El servidor no permite bloquear los registros en la base de datos; la aplicación no los modifica y esta comprobación detecta cualquier cambio.' }}</div>
                            </div>
                        </div>

                        <!-- ==================== CIERRES DE CAJA ==================== -->
                        <div v-if="activeSection === 'cierres'">
                            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">
                                <icon name="print" :size="16"></icon> Cierres de caja
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:16px;">Los últimos 50 cierres de la tienda. «Imprimir» saca el informe Z en la impresora de esta caja (o lo descarga en PDF si no tiene impresora); «PDF» lo descarga. El informe Z muestra los importes guardados al cerrar.</div>
                            <div v-if="cashCloses.error" style="color:var(--red); font-size:13px; margin-bottom:12px;">{{ cashCloses.error }}</div>
                            <div v-if="cashCloses.loading" style="font-size:13px; color:var(--text3);">Cargando…</div>
                            <div v-else-if="!cashCloses.list.length && !cashCloses.error" style="font-size:13px; color:var(--text3);">Todavía no hay cierres de caja.</div>
                            <table v-else-if="cashCloses.list.length" style="width:100%; border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr style="color:var(--text3); text-align:left; border-bottom:1px solid var(--border);">
                                        <th style="padding:8px 6px; font-weight:600;">Fecha de cierre</th>
                                        <th style="padding:8px 6px; font-weight:600;">Caja</th>
                                        <th style="padding:8px 6px; font-weight:600;">Cajero</th>
                                        <th style="padding:8px 6px; font-weight:600; text-align:right;">Contado</th>
                                        <th style="padding:8px 6px; font-weight:600; text-align:right;">Esperado</th>
                                        <th style="padding:8px 6px; font-weight:600; text-align:right;">Diferencia</th>
                                        <th style="padding:8px 6px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in cashCloses.list" :key="c.session_id" style="border-bottom:1px solid var(--border);">
                                        <td style="padding:8px 6px; font-family:'IBM Plex Mono';">{{ formatDateTime(c.closed_at) }}</td>
                                        <td style="padding:8px 6px;">{{ c.terminal_name }}</td>
                                        <td style="padding:8px 6px;">{{ c.cashier || '—' }}</td>
                                        <td style="padding:8px 6px; text-align:right; font-family:'IBM Plex Mono';">{{ formatMoney(c.counted_cash) }}</td>
                                        <td style="padding:8px 6px; text-align:right; font-family:'IBM Plex Mono';">{{ formatMoney(c.expected_cash) }}</td>
                                        <td style="padding:8px 6px; text-align:right; font-family:'IBM Plex Mono';"
                                            :style="{ color: Math.abs(Number(c.difference || 0)) < 0.005 ? 'var(--green)' : Number(c.difference) > 0 ? 'var(--amber)' : 'var(--red)' }">{{ formatMoney(c.difference, true) }}</td>
                                        <td style="padding:8px 6px; text-align:right; white-space:nowrap;">
                                            <button @click="printClose(c.session_id)" :disabled="cashCloses.printingId === c.session_id"
                                                style="min-height:36px; padding:0 12px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; color:var(--text); font-family:inherit; font-size:13px; cursor:pointer;">
                                                {{ cashCloses.printingId === c.session_id ? 'Imprimiendo…' : 'Imprimir' }}
                                            </button>
                                            <button @click="pdfClose(c.session_id)"
                                                style="min-height:36px; padding:0 12px; margin-left:6px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; color:var(--text); font-family:inherit; font-size:13px; cursor:pointer;">
                                                PDF
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="activeSection === 'email'">
                            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">
                                <icon name="mail" :size="16"></icon> Cuenta de correo de la tienda
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:16px;">Desde esta cuenta se envía el ticket en PDF a los clientes cuando la caja no tiene impresora. Usa los datos SMTP de tu correo (los muestra el panel del hosting en la configuración de la cuenta de correo).</div>
                            <div v-if="mailError" style="color:var(--red); font-size:13px; margin-bottom:12px;">{{ mailError }}</div>
                            <div style="display:flex; flex-direction:column; gap:14px;">
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <div style="flex:1; min-width:200px;">
                                        <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Servidor SMTP</label>
                                        <input v-model="mail.host" type="text" placeholder="smtp.tudominio.com" autocomplete="off" :style="INPUT_STYLE">
                                    </div>
                                    <div style="width:110px;">
                                        <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Puerto</label>
                                        <input v-model.number="mail.port" type="number" min="1" max="65535" :style="INPUT_STYLE">
                                    </div>
                                    <div style="width:150px;">
                                        <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Cifrado</label>
                                        <select v-model="mail.encryption" :style="INPUT_STYLE">
                                            <option value="tls">TLS (587)</option>
                                            <option value="ssl">SSL (465)</option>
                                            <option value="none">Ninguno (25)</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Usuario</label>
                                    <input v-model="mail.username" type="text" placeholder="tienda@tudominio.com" autocomplete="off" :style="INPUT_STYLE">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Contraseña <span v-if="mail.has_password" style="color:var(--green);">— guardada (déjala vacía para no cambiarla)</span></label>
                                    <input v-model="mail.password" type="password" autocomplete="new-password" :placeholder="mail.has_password ? '••••••••' : ''" :style="INPUT_STYLE">
                                </div>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <div style="flex:1; min-width:200px;">
                                        <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Email del remitente</label>
                                        <input v-model="mail.from_email" type="email" placeholder="tienda@tudominio.com" :style="INPUT_STYLE">
                                    </div>
                                    <div style="flex:1; min-width:200px;">
                                        <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Nombre del remitente</label>
                                        <input v-model="mail.from_name" type="text" maxlength="120" placeholder="Nombre de la tienda" :style="INPUT_STYLE">
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Avisar de copias de seguridad fallidas a:</label>
                                    <input v-model="mail.backup_alert_email" type="email" placeholder="tu@email.com (vacío = sin aviso)" :style="INPUT_STYLE">
                                    <div style="font-size:12px; color:var(--text3); margin-top:6px;">Si la copia diaria de la base de datos falla o hay que revisarla, se envía un aviso a este email desde la cuenta de la tienda.</div>
                                </div>
                                <div>
                                    <button @click="saveMail" :disabled="mailSaving"
                                        style="padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="mailSaving ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                        {{ mailSaving ? 'Guardando…' : 'Guardar' }}
                                    </button>
                                </div>
                                <div style="border-top:1px solid var(--border); padding-top:14px;">
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Enviar un email de prueba a</label>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                        <input v-model="mailTestTo" type="email" placeholder="tu@email.com" :style="INPUT_STYLE + ' max-width:300px;'">
                                        <button type="button" class="tpv-btn" @click="sendMailTest" :disabled="!mail.configured" style="min-height:40px;">Enviar prueba</button>
                                    </div>
                                    <div v-if="!mail.configured" style="font-size:12px; color:var(--text3); margin-top:6px;">Guarda la cuenta antes de probarla.</div>
                                    <div v-if="mailTestStatus" style="font-size:12px; color:var(--text2); margin-top:6px;">{{ mailTestStatus }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- ==================== SINCRONIZACIÓN ==================== -->
                        <div v-if="activeSection === 'sincronizacion'">
                            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px;">
                                <icon name="sync" :size="16"></icon> Sincronización de stock
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:20px;">Configura los intervalos de sincronización en ambas direcciones.</div>

                            <!-- PS → TPV -->
                            <div style="background:var(--surface2); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:12px;">
                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                    <span style="font-size:12px; font-weight:700; color:var(--text); text-transform:uppercase; letter-spacing:.04em;">PS → TPV</span>
                                    <span style="font-size:11px; color:var(--text3);">Web actualiza stock en el TPV</span>
                                </div>
                                <div style="font-size:12px; color:var(--text3); margin-bottom:12px;">El TPV descarga el stock de PrestaShop periódicamente.</div>
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <input v-model.number="syncInterval" type="number" min="0" max="3600"
                                        style="width:100px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; text-align:center;">
                                    <span style="font-size:13px; color:var(--text2);">segundos</span>
                                    <button @click="saveSyncInterval" :disabled="syncSaving || !terminal"
                                        style="padding:8px 18px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="(syncSaving || !terminal) ? 'opacity:0.5; cursor:not-allowed;' : ''">
                                        {{ syncSaving ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                                <div style="font-size:12px; color:var(--text3); margin-top:8px;">
                                    <span style="color:var(--green); font-weight:600;">0</span> = desactivado &nbsp;·&nbsp;
                                    <span style="color:var(--green); font-weight:600;">60</span> = cada minuto &nbsp;·&nbsp;
                                    <span style="color:var(--green); font-weight:600;">300</span> = cada 5 min
                                </div>
                            </div>

                            <!-- TPV → PS -->
                            <div style="background:var(--surface2); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:12px;">
                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                    <span style="font-size:12px; font-weight:700; color:var(--text); text-transform:uppercase; letter-spacing:.04em;">TPV → PS</span>
                                    <span style="font-size:11px; color:var(--text3);">Ventas del TPV actualizan la web</span>
                                </div>
                                <div style="font-size:12px; color:var(--text3); margin-bottom:12px;">Reintenta enviar stock pendiente a PrestaShop. Cada venta ya lo empuja en tiempo real — este intervalo es el reintento de fallos offline.</div>
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <input v-model.number="syncPushInterval" type="number" min="0" max="3600"
                                        style="width:100px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; text-align:center;">
                                    <span style="font-size:13px; color:var(--text2);">segundos</span>
                                    <button @click="saveSyncPushInterval" :disabled="syncPushSaving || !terminal"
                                        style="padding:8px 18px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="(syncPushSaving || !terminal) ? 'opacity:0.5; cursor:not-allowed;' : ''">
                                        {{ syncPushSaving ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                                <div style="font-size:12px; color:var(--text3); margin-top:8px;">
                                    <span style="color:var(--green); font-weight:600;">0</span> = desactivado &nbsp;·&nbsp;
                                    <span style="color:var(--green); font-weight:600;">120</span> = cada 2 min &nbsp;·&nbsp;
                                    <span style="color:var(--green); font-weight:600;">300</span> = cada 5 min
                                </div>
                            </div>

                            <!-- Manual sync now -->
                            <div style="background:var(--surface2); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:12px;">
                                <div style="font-size:12px; font-weight:700; color:var(--text); margin-bottom:8px; text-transform:uppercase; letter-spacing:.04em;">Sincronización manual</div>
                                <div style="font-size:12px; color:var(--text3); margin-bottom:12px;">Descarga catálogo completo, stock y precios desde PrestaShop ahora mismo.</div>
                                <button @click="syncNow" :disabled="syncNowRunning"
                                    style="padding:9px 20px; border-radius:9px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:8px;"
                                    :style="syncNowRunning ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                    <icon name="sync" :size="15"></icon>
                                    {{ syncNowRunning ? 'Sincronizando...' : 'Sincronizar ahora' }}
                                </button>
                                <div v-if="syncLastResult" style="margin-top:12px; font-size:12px; color:var(--green);">
                                    ✓ Completado —
                                    <span v-if="syncLastResult.products !== undefined">{{ syncLastResult.products }} productos, </span>
                                    <span v-if="syncLastResult.stock !== undefined">{{ syncLastResult.stock }} registros de stock</span>
                                    <span v-else>{{ JSON.stringify(syncLastResult) }}</span>
                                </div>
                            </div>

                            <div v-if="syncError" style="color:var(--red); font-size:12px; margin-top:4px;">{{ syncError }}</div>
                        </div>

                        <!-- ==================== ACERCA DE ==================== -->
                        <div v-if="activeSection === 'acerca'">
                            <div style="margin-bottom:8px; line-height:0;"><img class="brand-logo--dark" :src="brandBase + '/venpostpv-logo-dark.svg'" alt="VenPosTPV" style="height:40px; width:auto;"><img class="brand-logo--light" :src="brandBase + '/venpostpv-logo.svg'" alt="VenPosTPV" style="height:40px; width:auto;"></div>
                            <div style="font-size:13px; color:var(--text3); margin-bottom:20px;">Punto de venta web para tiendas con PrestaShop.</div>
                            <div style="background:var(--surface2); border-radius:12px; padding:16px; display:grid; grid-template-columns:auto 1fr; gap:10px 16px; font-size:13px; max-width:480px;">
                                <span style="color:var(--text3);">Creador</span>
                                <span style="color:var(--text); font-weight:600;">Jorge Alonso</span>
                                <span style="color:var(--text3);">GitHub</span>
                                <a href="https://github.com/jorgealonsodev" target="_blank" rel="noopener noreferrer" style="color:var(--green); font-weight:600; text-decoration:none;">github.com/jorgealonsodev</a>
                            </div>

                            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text); margin:24px 0 6px;">
                                <icon name="sync" :size="16"></icon> Estado de la instalación
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:12px; max-width:480px;">Pasos que la instalación y las actualizaciones hacen solos (base de datos, PrestaShop). Los pendientes se reintentan cada 15 minutos con la tarea programada de cada minuto; los bloqueados, no: revisa el motivo y ejecuta <code>tpv:provision</code>.</div>
                            <div v-if="provisioningLoading" style="font-size:12px; color:var(--text3);">Comprobando…</div>
                            <div v-else-if="provisioningError" style="font-size:12px; color:var(--red);">{{ provisioningError }}</div>
                            <div v-else-if="provisioning" style="background:var(--surface2); border-radius:12px; padding:4px 16px; max-width:480px;">
                                <div v-for="step in provisioning.steps" :key="step.id" style="padding:10px 0; border-bottom:1px solid var(--border); font-size:13px;">
                                    <div style="display:flex; justify-content:space-between; gap:12px;">
                                        <span style="color:var(--text);">{{ step.description }}</span>
                                        <span :style="step.status === 'done' ? 'color:var(--green); font-weight:600;' : 'color:var(--red); font-weight:600;'" style="flex-shrink:0;">{{ provisioningLabel(step) }}</span>
                                    </div>
                                    <div v-if="step.status !== 'done' && step.reason" style="margin-top:4px; font-size:12px; color:var(--text3); overflow-wrap:anywhere;">{{ step.reason }}</div>
                                </div>
                            </div>

                            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text); margin:24px 0 6px;">
                                <icon name="sync" :size="16"></icon> Tienda PrestaShop
                            </div>
                            <div style="font-size:12px; color:var(--text3); margin-bottom:12px; max-width:480px;">Si cambias de tienda o el TPV deja de conectar con ella: en PrestaShop, módulo TPV Connect, pulsa «Emparejar con un TPV» y escribe aquí el código. El TPV guarda las claves nuevas en <code>.env</code> y deja una copia del anterior.</div>
                            <div v-if="pairing.done" style="font-size:12px; color:var(--green); margin-bottom:10px; max-width:480px; overflow-wrap:anywhere;">{{ pairing.done }}</div>
                            <button v-if="!pairing.open" @click="openPairing()"
                                style="padding:8px 16px; border-radius:9px; border:1px solid var(--border); background:var(--surface2); color:var(--text); cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                                Volver a emparejar con la tienda
                            </button>
                            <div v-else style="background:var(--surface2); border-radius:12px; padding:14px 16px; max-width:480px; display:flex; flex-direction:column; gap:8px;">
                                <label style="font-size:12px; color:var(--text3);">Dirección de la tienda</label>
                                <input v-model="pairing.shopUrl" type="url" placeholder="https://tutienda.com" autocomplete="off" :style="INPUT_STYLE">
                                <label style="font-size:12px; color:var(--text3);">Código de emparejamiento</label>
                                <input v-model="pairing.code" inputmode="numeric" maxlength="7" placeholder="123456" autocomplete="one-time-code" :style="INPUT_STYLE" @keyup.enter="runPairing()">
                                <div v-if="pairing.error" style="font-size:12px; color:var(--red); overflow-wrap:anywhere;">{{ pairing.error }}</div>
                                <div style="display:flex; gap:8px; margin-top:4px;">
                                    <button @click="runPairing()" :disabled="pairing.running"
                                        style="padding:8px 16px; border-radius:9px; border:none; background:var(--green); color:#fff; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                                        {{ pairing.running ? 'Emparejando…' : 'Emparejar' }}
                                    </button>
                                    <button @click="pairing.open = false" :disabled="pairing.running"
                                        style="padding:8px 16px; border-radius:9px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">
                                        Cancelar
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    `
};

/**
 * New size of the centred Ajustes window when a corner moves (dx, dy) from where
 * the drag began; sx/sy are the corner's side (-1 left/top, 1 right/bottom). The
 * window grows on both sides, so it changes by twice the drag; never below a usable
 * size nor beyond the screen with a 16 px margin.
 */
SettingsModal.resizedSize = function (start, dx, dy, sx, sy, viewport) {
    const clamp = (value, min, max) => Math.round(Math.min(Math.max(value, min), Math.max(min, max)));
    return {
        w: clamp(start.w + 2 * dx * sx, 560, viewport.w - 32),
        h: clamp(start.h + 2 * dy * sy, 420, viewport.h - 32),
    };
};
