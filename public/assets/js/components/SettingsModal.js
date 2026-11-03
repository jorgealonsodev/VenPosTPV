/**
 * SettingsModal — Settings page with sidebar navigation (admin only).
 * Sections: Empleados, Terminal, Impresora, Sincronización.
 * Keeps all existing employee CRUD and terminal config APIs intact.
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
        const createForm = Vue.reactive({ name: '', code: '', pin: '', role: 'cashier' });
        const createError = Vue.ref('');
        const creating = Vue.ref(false);

        // Edit form
        const editingId = Vue.ref(null);
        const editForm = Vue.reactive({ name: '', code: '', pin: '', role: 'cashier', active: true });
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
            } catch (e) {
                terminalError.value = 'Error al cargar configuración del terminal';
            } finally {
                terminalLoading.value = false;
            }
        }

        async function saveTerminal() {
            if (!terminal.value) return;
            terminalSaving.value = true;
            terminalError.value = '';
            try {
                const payload = {
                    dataphone_mode: terminal.value.dataphone_mode,
                    printer_type: terminal.value.printer_type,
                    store_address: terminal.value.store_address,
                    ticket_header: terminal.value.ticket_header,
                    ticket_footer: terminal.value.ticket_footer,
                    ticket_logo: terminal.value.ticket_logo,
                    ticket_width_px: terminal.value.ticket_width_px,
                };
                if (terminal.value.ticket_code) payload.ticket_code = terminal.value.ticket_code;
                if (terminal.value.refund_window_days != null && terminal.value.refund_window_days !== '') {
                    payload.refund_window_days = Number(terminal.value.refund_window_days);
                }
                if (terminal.value.name != null) payload.name = terminal.value.name;
                if (terminal.value.printer_ip != null) payload.printer_ip = terminal.value.printer_ip;
                if (terminal.value.printer_port != null) payload.printer_port = terminal.value.printer_port;
                if (terminal.value.printer_queue != null) payload.printer_queue = terminal.value.printer_queue;

                await ApiClient.terminalUpdate(terminal.value.id, payload);
                success.value = 'Configuración guardada';
                setTimeout(() => { success.value = ''; }, 2000);
                // Refresh session values so PaymentModal picks them up immediately
                useSession.session.dataphoneMode = terminal.value.dataphone_mode;
                useSession.session.printerType = terminal.value.printer_type;
            } catch (e) {
                terminalError.value = e?.response?.data?.error || 'Error al guardar configuración';
            } finally {
                terminalSaving.value = false;
            }
        }

        function handleLogoUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = (e) => {
                terminal.value.ticket_logo = e.target.result;
            };
            reader.readAsDataURL(file);
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
                await ApiClient.terminalUpdate(terminal.value.id, { stock_sync_interval_seconds: seconds });
                useSession.session.stockSyncIntervalSeconds = seconds;
                useSession.startStockSyncTimer();
                success.value = 'Intervalo PS → TPV guardado';
                setTimeout(() => { success.value = ''; }, 2000);
            } catch (e) {
                syncError.value = e?.response?.data?.error || 'Error al guardar intervalo';
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
                await ApiClient.terminalUpdate(terminal.value.id, { stock_push_interval_seconds: seconds });
                useSession.session.stockPushIntervalSeconds = seconds;
                useSession.startStockPushTimer();
                success.value = 'Intervalo TPV → PS guardado';
                setTimeout(() => { success.value = ''; }, 2000);
            } catch (e) {
                syncError.value = e?.response?.data?.error || 'Error al guardar intervalo push';
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
                syncError.value = e?.response?.data?.error || 'Error al sincronizar';
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
                await ApiClient.post('/api/employees', body);
                createForm.name = '';
                createForm.code = '';
                createForm.pin = '';
                createForm.role = 'cashier';
                showCreateForm.value = false;
                success.value = 'Empleado creado';
                setTimeout(() => { success.value = ''; }, 2000);
                await loadEmployees();
            } catch (e) {
                createError.value = e?.response?.data?.error || 'Error al crear empleado';
            } finally {
                creating.value = false;
            }
        }

        function startEdit(emp) {
            editingId.value = emp.id;
            editForm.name = emp.name;
            editForm.code = emp.code || '';
            editForm.pin = '';
            editForm.role = emp.role;
            editForm.active = emp.active;
            editError.value = '';
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
                const body = { name: editForm.name, code: editForm.code, role: editForm.role, active: editForm.active };
                if (editForm.pin) body.pin = editForm.pin;
                await ApiClient.put('/api/employees/' + id, body);
                editingId.value = null;
                success.value = 'Empleado actualizado';
                setTimeout(() => { success.value = ''; }, 2000);
                await loadEmployees();
            } catch (e) {
                editError.value = e?.response?.data?.error || 'Error al guardar';
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

        Vue.onMounted(() => {
            loadEmployees();
            loadTerminal();
            loadSystemPrinters();
        });

        return {
            systemPrinters,
            employees, loading, error, success,
            activeSection,
            showCreateForm, createForm, createError, creating, handleCreate,
            editingId, editForm, editError, saving, startEdit, cancelEdit, handleSave,
            handleDeactivate, handleUnlock,
            terminal, terminalLoading, terminalSaving, terminalError, saveTerminal,
            handleLogoUpload, removeLogo,
            syncInterval, syncPushInterval, syncSaving, syncPushSaving, syncError,
            syncNowRunning, syncLastResult,
            saveSyncInterval, saveSyncPushInterval, syncNow,
            emit,
        };
    },
    template: `
        <!-- Backdrop -->
        <div @click.self="emit('close')"
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:100; display:flex; align-items:center; justify-content:center; padding:24px; box-sizing:border-box;">

            <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; width:100%; max-width:720px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;">
                <!-- Modal header -->
                <div style="padding:20px 24px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                    <div style="font-size:16px; font-weight:700; color:var(--text);">⚙ Configuración</div>
                    <button @click="emit('close')" style="background:transparent; border:none; color:var(--text3); font-size:20px; cursor:pointer; line-height:1; display:flex; align-items:center;"><icon name="close" :size="20"></icon></button>
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
                            onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=activeSection === 'empleados' ? 'var(--surface2)' : 'transparent'">
                            <icon name="user" :size="18"></icon> Empleados
                        </button>
                        <button @click="activeSection = 'terminal'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'terminal' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=activeSection === 'terminal' ? 'var(--surface2)' : 'transparent'">
                            <icon name="cashier" :size="18"></icon> Terminal
                        </button>
                        <button @click="activeSection = 'impresora'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'impresora' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=activeSection === 'impresora' ? 'var(--surface2)' : 'transparent'">
                            <icon name="print" :size="18"></icon> Impresora
                        </button>
                        <button @click="activeSection = 'sincronizacion'"
                            style="min-height:48px; padding:0 16px; background:transparent; border:none; border-left:3px solid transparent; color:var(--text2); font-family:inherit; font-size:14px; text-align:left; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background .15s;"
                            :style="activeSection === 'sincronizacion' ? 'background:var(--surface2); border-left-color:var(--green); color:var(--text); font-weight:600;' : ''"
                            onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=activeSection === 'sincronizacion' ? 'var(--surface2)' : 'transparent'">
                            <icon name="sync" :size="18"></icon> Sincronización
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
                                    <input v-model="createForm.name" type="text" placeholder="Nombre"
                                        style="flex:1; min-width:140px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none;">
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
                                            <input v-model="editForm.name" type="text" placeholder="Nombre"
                                                style="flex:1; min-width:140px; padding:7px 11px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:13px; outline:none;">
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

                        <!-- ==================== TERMINAL ==================== -->
                        <div v-if="activeSection === 'terminal'">
                            <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:16px;">🖥 Configuración del terminal</div>
                            <div v-if="terminalLoading" style="color:var(--text3); font-size:13px;">Cargando...</div>
                            <div v-else-if="!terminal" style="color:var(--text3); font-size:13px;">No hay terminal activo.</div>
                            <div v-else style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Nombre del terminal</label>
                                    <input v-model="terminal.name" type="text" placeholder="Terminal principal"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
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
                                    <button @click="saveTerminal" :disabled="terminalSaving"
                                        style="padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="terminalSaving ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                        {{ terminalSaving ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                                <div v-if="terminalError" style="color:var(--red); font-size:12px;">{{ terminalError }}</div>
                            </div>
                        </div>

                        <!-- ==================== IMPRESORA ==================== -->
                        <div v-if="activeSection === 'impresora'">
                            <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:16px;">🖨 Configuración de impresora</div>
                            <div v-if="terminalLoading" style="color:var(--text3); font-size:13px;">Cargando...</div>
                            <div v-else-if="!terminal" style="color:var(--text3); font-size:13px;">No hay terminal activo.</div>
                            <div v-else style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Tipo de impresora</label>
                                    <select v-model="terminal.printer_type"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer; box-sizing:border-box;">
                                        <option value="none">Sin impresora</option>
                                        <option value="pdf">PDF</option>
                                        <option value="escpos">ESC/POS (red)</option>
                                        <option value="system">Impresora del sistema (USB)</option>
                                    </select>
                                </div>
                                <div v-if="terminal.printer_type === 'system'">
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Impresora instalada en este equipo</label>
                                    <select v-model="terminal.printer_queue"
                                        style="width:100%; max-width:320px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; cursor:pointer; box-sizing:border-box;">
                                        <option value="" disabled>Elige una impresora</option>
                                        <option v-for="q in systemPrinters" :key="q" :value="q">{{ q }}</option>
                                    </select>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Imprime el ticket directamente (con corte y apertura del cajón). Solo funciona si el TPV corre en el mismo ordenador que la impresora.</div>
                                    <div v-if="systemPrinters.length === 0" style="font-size:12px; color:var(--red); margin-top:4px;">No se encuentran impresoras instaladas en este equipo.</div>
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

                                <!-- Ticket content fields -->
                                <div style="border-top:1px solid var(--border); padding-top:16px; margin-top:8px;">
                                    <div style="font-size:13px; font-weight:600; color:var(--text); margin-bottom:12px;">🧾 Contenido del ticket</div>
                                </div>

                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Logo del ticket</label>
                                    <div style="display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap;">
                                        <div style="flex:1; min-width:200px;">
                                            <input type="file" accept="image/*" @change="handleLogoUpload"
                                                style="font-size:13px; color:var(--text2); cursor:pointer;">
                                            <div style="font-size:12px; color:var(--text3); margin-top:4px;">PNG, JPG o SVG. Se almacena como imagen en base64.</div>
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
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Ancho del ticket (px)</label>
                                    <input v-model.number="terminal.ticket_width_px" type="number" min="100" max="600" placeholder="302 (80mm)"
                                        style="width:100%; max-width:200px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box;">
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Deja vacío para usar 80mm por defecto</div>
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
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Dirección de la tienda</label>
                                    <textarea v-model="terminal.store_address" rows="2" placeholder="Calle Principal 123, 28001 Madrid"
                                        style="width:100%; max-width:420px; padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:14px; outline:none; box-sizing:border-box; resize:vertical;"></textarea>
                                    <div style="font-size:12px; color:var(--text3); margin-top:4px;">Se imprime debajo del nombre de la tienda en el ticket.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; color:var(--text3); margin-bottom:6px;">Encabezado del ticket</label>
                                    <textarea v-model="terminal.ticket_header" rows="4" placeholder="NIF: B12345678&#10;www.tutienda.com&#10;Gracias por visitarnos"
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
                                    <button @click="saveTerminal" :disabled="terminalSaving"
                                        style="padding:8px 20px; border-radius:9px; border:none; background:var(--green); color:#000; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;"
                                        :style="terminalSaving ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                        {{ terminalSaving ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                                <div v-if="terminalError" style="color:var(--red); font-size:12px;">{{ terminalError }}</div>
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

                    </div>
                </div>
            </div>
        </div>
    `
};
