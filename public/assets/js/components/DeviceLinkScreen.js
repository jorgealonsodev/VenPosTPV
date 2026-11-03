/**
 * DeviceLinkScreen — "Este equipo no tiene caja asignada" (S10).
 *
 * An administrator links this computer to an existing till or creates one. A till
 * already on another computer is only taken after confirming "Pasar la caja a este
 * equipo", which unlinks the other computer. A cashier sees the message and a
 * button to log in as administrator.
 */
const DeviceLinkScreen = {
    setup() {
        const { session, rememberDeviceToken, logout } = useSession;
        const tills = Vue.ref([]);
        const loading = Vue.ref(false);
        const error = Vue.ref('');
        const busy = Vue.ref(false);
        const confirmingId = Vue.ref(null);
        const newTill = Vue.reactive({ name: '', ticket_letter: '' });

        const isAdmin = Vue.computed(() => session.role === 'admin');

        // ApiClient rejects with "HTTP <status>: <body>"; the body carries code/error.
        function parseError(err) {
            const match = /^HTTP (\d+): ([\s\S]*)$/.exec((err && err.message) || '');
            if (!match) return { status: 0, code: null, error: 'No se pudo conectar con el servidor' };
            let body = {};
            try { body = JSON.parse(match[2]); } catch (e) { body = {}; }
            return { status: Number(match[1]), code: body.code || null, error: body.error || 'Error ' + match[1] };
        }

        async function load() {
            if (!isAdmin.value) return;
            loading.value = true;
            error.value = '';
            try {
                const resp = await ApiClient.get('/api/device');
                tills.value = (resp && resp.data && resp.data.tills) || [];
            } catch (e) {
                error.value = parseError(e).error;
            } finally {
                loading.value = false;
            }
        }

        function reload() {
            if (window.location && typeof window.location.reload === 'function') window.location.reload();
        }

        async function link(till, move) {
            busy.value = true;
            error.value = '';
            try {
                const resp = await ApiClient.post('/api/device/link', { terminal_id: till.id, move: move === true });
                rememberDeviceToken(resp && resp.data && resp.data.token);
                confirmingId.value = null;
                reload();
                return true;
            } catch (e) {
                const parsed = parseError(e);
                if (parsed.code === 'till_has_device') {
                    confirmingId.value = till.id;
                } else {
                    error.value = parsed.error;
                }
                return false;
            } finally {
                busy.value = false;
            }
        }

        function choose(till) {
            // A till on another computer needs the explicit move.
            if (till.device && !till.this_device) {
                confirmingId.value = till.id;
                return Promise.resolve(false);
            }
            return link(till, false);
        }

        function confirmMove(till) {
            return link(till, true);
        }

        async function createAndLink() {
            error.value = '';
            const name = String(newTill.name || '').trim();
            const letter = String(newTill.ticket_letter || '').trim().toUpperCase();
            if (!name) { error.value = 'Indica el nombre de la caja'; return false; }
            if (!/^[A-Z]$/.test(letter)) { error.value = 'La letra de caja debe ser una letra de la A a la Z'; return false; }
            busy.value = true;
            let created;
            try {
                created = await ApiClient.post('/api/terminal', { name, ticket_letter: letter });
            } catch (e) {
                error.value = parseError(e).error;
                busy.value = false;
                return false;
            }
            busy.value = false;
            return link({ id: created.data.id }, false);
        }

        async function loginAsAdmin() {
            try { await ApiClient.authLogout(); } catch (e) { /* offline: still go to the login */ }
            window.location.href = (window.TPV_BASE || '') + '/app/login?admin=1';
        }

        function lastSeen(till) {
            const seen = till.device && (till.device.last_seen_at || till.device.linked_at);
            return seen ? String(seen).slice(0, 16) : '';
        }

        Vue.onMounted(load);

        return {
            session, isAdmin, tills, loading, error, busy, confirmingId, newTill,
            load, choose, confirmMove, createAndLink, loginAsAdmin, logout, lastSeen,
        };
    },
    template: `
        <div style="width:100%; height:100%; background:var(--bg); display:flex; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:36px; width:100%; max-width:520px; max-height:100%; overflow-y:auto; display:flex; flex-direction:column; gap:20px; box-sizing:border-box;">
                <div>
                    <div style="font-size:11px; color:var(--text3); letter-spacing:0.12em; text-transform:uppercase; margin-bottom:6px;">Caja</div>
                    <div style="font-size:22px; font-weight:600; color:var(--text);">Este equipo no tiene caja asignada</div>
                    <div style="font-size:13px; color:var(--text2); margin-top:8px;">Cada ordenador trabaja con su propia caja: su cajón, su impresora y su letra de ticket. Un administrador la asigna una sola vez.</div>
                </div>

                <div v-if="error" style="padding:10px 14px; background:var(--red-bg); color:var(--red); border-radius:8px; font-size:13px;">{{ error }}</div>

                <template v-if="!isAdmin">
                    <button @click="loginAsAdmin"
                        style="min-height:48px; padding:12px; border-radius:12px; border:none; cursor:pointer; background:var(--green); color:#000; font-family:inherit; font-size:15px; font-weight:700;">
                        Entrar como administrador
                    </button>
                </template>

                <template v-else>
                    <div v-if="loading" style="color:var(--text3); font-size:13px;">Cargando cajas...</div>
                    <div v-else style="display:flex; flex-direction:column; gap:10px;">
                        <div v-if="tills.length === 0" style="color:var(--text3); font-size:13px;">Todavía no hay cajas. Crea la primera.</div>
                        <div v-for="till in tills" :key="till.id"
                            style="border:1px solid var(--border); border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:8px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                                <div style="min-width:0;">
                                    <div style="font-size:15px; font-weight:600; color:var(--text);">{{ till.name }} <span style="font-family:'IBM Plex Mono'; color:var(--text3);">({{ till.ticket_letter || '—' }})</span></div>
                                    <div style="font-size:12px; color:var(--text3);">{{ till.device ? 'En otro equipo · visto ' + lastSeen(till) : 'Sin equipo' }}</div>
                                </div>
                                <button v-if="confirmingId !== till.id" @click="choose(till)" :disabled="busy"
                                    style="flex-shrink:0; min-height:44px; padding:8px 14px; border-radius:9px; border:1px solid var(--border); background:transparent; color:var(--text); cursor:pointer; font-family:inherit; font-size:13px; font-weight:600;">
                                    {{ till.device ? 'Pasar la caja a este equipo' : 'Usar esta caja' }}
                                </button>
                            </div>
                            <div v-if="confirmingId === till.id" style="display:flex; flex-direction:column; gap:8px; padding-top:4px; border-top:1px solid var(--border);">
                                <div style="font-size:13px; color:var(--text2);">Esta caja está en otro equipo. Si la pasas a este, aquel mostrará «Este equipo no tiene caja asignada».</div>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button @click="confirmMove(till)" :disabled="busy"
                                        style="min-height:44px; padding:8px 14px; border-radius:9px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:13px; font-weight:700;">
                                        Pasar la caja a este equipo
                                    </button>
                                    <button @click="confirmingId = null" :disabled="busy"
                                        style="min-height:44px; padding:8px 14px; border-radius:9px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:13px;">
                                        Cancelar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="border-top:1px solid var(--border); padding-top:16px; display:flex; flex-direction:column; gap:10px;">
                        <div style="font-size:13px; font-weight:600; color:var(--text);">Crear una caja nueva para este equipo</div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <input v-model="newTill.name" type="text" maxlength="64" placeholder="Nombre (p. ej. Caja 2)"
                                style="flex:1; min-width:160px; padding:10px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface2); color:var(--text); font-family:inherit; font-size:14px; outline:none;">
                            <input v-model="newTill.ticket_letter" type="text" maxlength="1" autocapitalize="characters" placeholder="Letra"
                                style="width:80px; padding:10px 12px; border-radius:8px; border:1px solid var(--border); background:var(--surface2); color:var(--text); font-family:'IBM Plex Mono'; font-size:14px; outline:none; text-transform:uppercase;">
                        </div>
                        <button @click="createAndLink" :disabled="busy"
                            style="min-height:44px; padding:10px; border-radius:10px; border:none; background:var(--green); color:#000; cursor:pointer; font-family:inherit; font-size:14px; font-weight:700;">
                            Crear caja y usarla en este equipo
                        </button>
                    </div>
                </template>

                <button @click="logout"
                    style="align-self:center; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text3); cursor:pointer; font-family:inherit; font-size:12px;">
                    Salir
                </button>
            </div>
        </div>
    `
};
