/**
 * LoginScreen — Two-tab login: Admin (username+password) and Employee (name/code search → PIN numpad).
 * Employee flow:
 *   Step 1: Enter name or code → lookup via /api/auth/employee-lookup
 *   Step 2: PIN keypad → submit login
 * On 3 failed PINs: account locked → show message (the login answers 403 {locked:true}).
 * Too many attempts (429: from this computer, or 10 wrong admin passwords from anywhere): ask to wait.
 * On success: redirect to /app/pos.
 */
const TOO_MANY_ATTEMPTS = 'Demasiados intentos. Espera un poco y vuelve a probar.';

const LoginScreen = {
    setup() {
        // ?admin=1: sent here from "Este equipo no tiene caja asignada" to log in as administrator.
        const wantsAdmin = typeof URLSearchParams === 'function' && window.location
            && new URLSearchParams(window.location.search || '').get('admin') === '1';
        const tab = Vue.ref(wantsAdmin ? 'admin' : 'employee'); // 'admin' | 'employee'
        const theme = Vue.ref('dark');

        function toggleTheme() {
            if (window.ThemeToggle) {
                window.ThemeToggle.toggle();
                theme.value = window.ThemeToggle.getTheme();
            }
        }

        // Initialize theme from utility
        if (window.ThemeToggle) {
            theme.value = window.ThemeToggle.getTheme();
        }

        // Listen for theme changes from other sources (e.g. useSession)
        window.addEventListener('theme-changed', function (e) {
            theme.value = e.detail.theme;
        });

        // --- Admin tab ---
        const adminUser = Vue.ref('');
        const adminPass = Vue.ref('');
        const adminError = Vue.ref('');
        const adminLoading = Vue.ref(false);

        async function handleAdminLogin() {
            adminError.value = '';
            if (!adminUser.value || !adminPass.value) {
                adminError.value = 'Usuario y contraseña son obligatorios';
                return;
            }
            adminLoading.value = true;
            try {
                await ApiClient.post('/api/auth/login', {
                    type: 'admin',
                    username: adminUser.value,
                    password: adminPass.value,
                });
                window.location.href = (window.TPV_BASE || '') + '/app/pos';
            } catch (err) {
                adminError.value = err?.status === 429 ? TOO_MANY_ATTEMPTS : 'Credenciales incorrectas';
            } finally {
                adminLoading.value = false;
            }
        }

        // --- Employee tab ---
        // Step 1: name/code search
        const empQuery = Vue.ref('');
        const empSearchError = Vue.ref('');
        const empSearchLoading = Vue.ref(false);

        // Step 2: PIN entry
        const selectedEmployee = Vue.ref(null); // { id, name }
        const pin = Vue.ref('');
        const pinShake = Vue.ref(false);
        const pinError = Vue.ref('');
        const pinLocked = Vue.ref(false);
        const pinLoading = Vue.ref(false);

        async function handleEmployeeLookup() {
            const q = empQuery.value.trim();
            if (q === '') {
                empSearchError.value = 'Introduce tu nombre o código';
                return;
            }
            empSearchError.value = '';
            empSearchLoading.value = true;
            try {
                const resp = await ApiClient.post('/api/auth/employee-lookup', { query: q });
                // { id, name } only: a locked account is reported after the PIN (403 locked).
                selectedEmployee.value = resp.data;
                pin.value = '';
                pinError.value = '';
                pinLocked.value = false;
            } catch (err) {
                empSearchError.value = err?.status === 429 ? TOO_MANY_ATTEMPTS : 'Empleado no encontrado';
            } finally {
                empSearchLoading.value = false;
            }
        }

        function handleKey(k) {
            if (pinLocked.value) return;
            if (pin.value.length >= 12) return;
            pin.value += k;
        }

        function handleDel() {
            if (pinLocked.value) return;
            pin.value = pin.value.slice(0, -1);
            pinError.value = '';
        }

        function goBack() {
            selectedEmployee.value = null;
            pin.value = '';
            pinError.value = '';
            pinLocked.value = false;
            empQuery.value = '';
            empSearchError.value = '';
        }

        async function handlePinConfirm() {
            if (!selectedEmployee.value || pinLocked.value) return;
            if (pin.value.length < 8) {
                pinShake.value = true;
                setTimeout(() => { pinShake.value = false; }, 500);
                return;
            }
            pinError.value = '';
            pinLoading.value = true;
            try {
                await ApiClient.post('/api/auth/login', {
                    type: 'employee',
                    user_id: selectedEmployee.value.id,
                    pin: pin.value,
                });
                window.location.href = (window.TPV_BASE || '') + '/app/pos';
            } catch (err) {
                const body = err?.body || {};
                if (body.locked) {
                    pinLocked.value = true;
                    pinError.value = 'Cuenta bloqueada. Contacta al administrador para desbloquearla.';
                } else if (err?.status === 429) {
                    pinError.value = TOO_MANY_ATTEMPTS;
                    pinShake.value = true;
                } else {
                    pinError.value = 'PIN incorrecto';
                    pinShake.value = true;
                }
                pin.value = '';
                setTimeout(() => { pinShake.value = false; }, 500);
            } finally {
                pinLoading.value = false;
            }
        }

        Vue.watch(pin, (val) => {
            if (val.length >= 8 && selectedEmployee.value && !pinLoading.value && !pinLocked.value) {
                setTimeout(() => handlePinConfirm(), 200);
            }
        });

        return {
            brandBase: (window.TPV_BASE || '') + '/assets/img/brand',
            tab, theme, toggleTheme,
            adminUser, adminPass, adminError, adminLoading, handleAdminLogin,
            empQuery, empSearchError, empSearchLoading, handleEmployeeLookup,
            selectedEmployee, pin, pinShake, pinError, pinLocked, pinLoading,
            handleKey, handleDel, handlePinConfirm, goBack,
        };
    },
    template: `
        <div style="width:100vw; height:100vh; background:var(--bg); display:flex; flex-direction:column; align-items:center; justify-content:center; gap:28px; padding:24px; box-sizing:border-box;">
            <!-- Theme toggle -->
            <button @click="toggleTheme"
                style="position:fixed; top:16px; right:16px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:8px 12px; color:var(--text3); cursor:pointer; display:flex; align-items:center; gap:6px; font-family:inherit; font-size:12px; z-index:100; transition:all .15s;"
                @mouseover="$event.currentTarget.style.borderColor='var(--border2)'" @mouseout="$event.currentTarget.style.borderColor='var(--border)'">
                <svg v-if="theme === 'dark'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>

            <!-- Header -->
            <div style="text-align:center;">
                <div style="font-size:12px; letter-spacing:0.2em; color:var(--text3); text-transform:uppercase; margin-bottom:6px;">Terminal Punto de Venta</div>
                <h1 style="margin:0; line-height:0;"><img class="brand-logo--dark" :src="brandBase + '/venpostpv-logo-dark.svg'" alt="VenPosTPV" style="height:48px; width:auto;"><img class="brand-logo--light" :src="brandBase + '/venpostpv-logo.svg'" alt="VenPosTPV" style="height:48px; width:auto;"></h1>
            </div>

            <!-- Tabs -->
            <div style="display:flex; gap:4px; background:var(--surface); border-radius:12px; padding:4px; border:1px solid var(--border);">
                <button @click="tab='employee'"
                    style="padding:8px 22px; border-radius:9px; border:none; cursor:pointer; font-family:inherit; font-size:13px; font-weight:500; transition:all .15s;"
                    :style="tab==='employee' ? 'background:var(--green); color:#000;' : 'background:transparent; color:var(--text2);'">
                    Empleado
                </button>
                <button @click="tab='admin'"
                    style="padding:8px 22px; border-radius:9px; border:none; cursor:pointer; font-family:inherit; font-size:13px; font-weight:500; transition:all .15s;"
                    :style="tab==='admin' ? 'background:var(--green); color:#000;' : 'background:transparent; color:var(--text2);'">
                    Administrador
                </button>
            </div>

            <!-- Admin tab -->
            <div v-if="tab==='admin'" style="display:flex; flex-direction:column; gap:14px; width:100%; max-width:320px;">
                <div>
                    <label style="font-size:12px; color:var(--text3); margin-bottom:4px; display:block;">Usuario</label>
                    <input v-model="adminUser" type="text" placeholder="admin"
                        @keyup.enter="handleAdminLogin"
                        style="width:100%; box-sizing:border-box; padding:10px 14px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:15px; outline:none;">
                </div>
                <div>
                    <label style="font-size:12px; color:var(--text3); margin-bottom:4px; display:block;">Contraseña</label>
                    <input v-model="adminPass" type="password" placeholder="••••••"
                        @keyup.enter="handleAdminLogin"
                        style="width:100%; box-sizing:border-box; padding:10px 14px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:15px; outline:none;">
                </div>
                <div v-if="adminError" style="color:var(--red); font-size:13px; text-align:center;">{{ adminError }}</div>
                <button @click="handleAdminLogin" :disabled="adminLoading"
                    style="padding:12px; border-radius:10px; border:none; cursor:pointer; font-family:inherit; font-size:15px; font-weight:600; background:var(--green); color:#000; transition:opacity .15s;"
                    :style="adminLoading ? 'opacity:0.6; cursor:not-allowed;' : ''">
                    {{ adminLoading ? 'Accediendo...' : 'Entrar' }}
                </button>
            </div>

            <!-- Employee tab -->
            <div v-if="tab==='employee'" style="display:flex; flex-direction:column; align-items:center; gap:20px; width:100%; max-width:420px;">

                <!-- Step 1: Name / code search -->
                <div v-if="!selectedEmployee" style="width:100%; display:flex; flex-direction:column; gap:12px;">
                    <div>
                        <label style="font-size:12px; color:var(--text3); margin-bottom:6px; display:block;">Nombre o código de empleado</label>
                        <div style="display:flex; gap:8px;">
                            <input v-model="empQuery" type="text" placeholder="Ej: Marina García o EMP001"
                                @keyup.enter="handleEmployeeLookup"
                                style="flex:1; padding:10px 14px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:15px; outline:none;">
                            <button @click="handleEmployeeLookup" :disabled="empSearchLoading"
                                style="padding:10px 18px; border-radius:10px; border:none; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600; background:var(--green); color:#000; transition:opacity .15s; white-space:nowrap;"
                                :style="empSearchLoading ? 'opacity:0.6; cursor:not-allowed;' : ''">
                                {{ empSearchLoading ? '...' : 'Buscar' }}
                            </button>
                        </div>
                    </div>
                    <div v-if="empSearchError" style="color:var(--red); font-size:13px; text-align:center;">{{ empSearchError }}</div>
                </div>

                <!-- Step 2: PIN entry -->
                <div v-if="selectedEmployee" style="display:flex; flex-direction:column; align-items:center; gap:16px; width:100%;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:15px; font-weight:600; color:var(--text);">{{ selectedEmployee.name }}</span>
                        <button @click="goBack"
                            style="font-size:12px; color:var(--text3); background:transparent; border:none; cursor:pointer; padding:2px 6px;">✕ cambiar</button>
                    </div>

                    <!-- Locked state -->
                    <div v-if="pinLocked"
                        style="padding:14px 18px; background:rgba(255,60,60,0.12); border:1px solid var(--red); border-radius:12px; color:var(--red); font-size:13px; text-align:center; max-width:320px;">
                        Cuenta bloqueada. Contacta al administrador para desbloquearla.
                    </div>

                    <!-- PIN dots (only show when not locked) -->
                    <template v-if="!pinLocked">
                        <div :class="pinShake ? 'animate-shake' : ''" style="display:flex; gap:12px;">
                            <div v-for="i in 8" :key="i"
                                style="width:14px; height:14px; border-radius:50%; border:2px solid; transition:background .1s;"
                                :style="{ background: i <= pin.length ? 'var(--green)' : 'var(--surface3)', borderColor: i <= pin.length ? 'var(--green)' : 'var(--border2)' }"></div>
                        </div>

                        <div v-if="pinError" style="color:var(--red); font-size:13px;">{{ pinError }}</div>

                        <!-- Numpad -->
                        <div style="display:grid; grid-template-columns:repeat(3, 72px); gap:10px;">
                            <template v-for="k in [1,2,3,4,5,6,7,8,9]" :key="k">
                                <button @click="handleKey(String(k))"
                                    style="height:72px; border-radius:14px; border:none; cursor:pointer; font-family:'IBM Plex Mono', monospace; font-size:22px; font-weight:500; background:var(--surface2); color:var(--text); transition:all .08s;"
                                    @mouseover="$event.currentTarget.style.background='var(--surface3)'" @mouseout="$event.currentTarget.style.background='var(--surface2)'">{{ k }}</button>
                            </template>
                            <div></div>
                            <button @click="handleKey('0')"
                                style="height:72px; border-radius:14px; border:none; cursor:pointer; font-family:'IBM Plex Mono', monospace; font-size:22px; font-weight:500; background:var(--surface2); color:var(--text); transition:all .08s;"
                                @mouseover="$event.currentTarget.style.background='var(--surface3)'" @mouseout="$event.currentTarget.style.background='var(--surface2)'">0</button>
                            <button @click="handleDel"
                                style="height:72px; border-radius:14px; border:none; cursor:pointer; font-family:'IBM Plex Mono', monospace; font-size:22px; font-weight:500; background:var(--surface2); color:var(--text2); transition:all .08s;"
                                @mouseover="$event.currentTarget.style.background='var(--surface3)'" @mouseout="$event.currentTarget.style.background='var(--surface2)'">⌫</button>
                        </div>
                        <div style="color:var(--text3); font-size:12px;">Introduce tu PIN</div>
                    </template>
                </div>
            </div>
        </div>
    `
};
