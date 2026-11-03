/**
 * CashOpenScreen — initial balance with quick presets.
 */
const CashOpenScreen = {
    props: ['cashierName'],
    emits: ['open'],
    setup(props, { emit }) {
        const { session, logout } = useSession;
        const amount = Vue.ref('');
        const quick = [0, 50, 100, 150, 200, 300];
        const showSettings = Vue.ref(false);

        // Sync theme changes from standalone ThemeToggle utility
        window.addEventListener('theme-changed', (e) => {
            session.theme = e.detail.theme;
        });

        function handleThemeToggle() {
            if (window.ThemeToggle) {
                window.ThemeToggle.toggle();
            }
        }

        function setAmount(v) {
            amount.value = String(v);
        }

        function confirm() {
            const val = parseFloat(String(amount.value).replace(',', '.')) || 0;
            emit('open', val);
        }

        function handleLogout() {
            logout();
        }

        const drawerStatus = Vue.ref('');

        async function openDrawer() {
            drawerStatus.value = 'Enviando...';
            try {
                await ApiClient.openDrawer();
                drawerStatus.value = '✓ Cajón abierto';
                setTimeout(() => { drawerStatus.value = ''; }, 2500);
            } catch (e) {
                drawerStatus.value = '✗ Error al abrir';
                setTimeout(() => { drawerStatus.value = ''; }, 2500);
            }
        }

        return { session, amount, quick, setAmount, confirm, showSettings, handleLogout, handleThemeToggle, openDrawer, drawerStatus };
    },
    template: `
        <div style="width:100%; height:100%; background:var(--bg); display:flex; align-items:center; justify-content:center;">
            <!-- Top-right action bar -->
            <div style="position:absolute; top:16px; right:16px; display:flex; align-items:center; gap:8px;">
                <button @click="handleThemeToggle" title="Cambiar tema"
                    style="background:transparent; border:1px solid var(--border); border-radius:8px; padding:4px 8px; color:var(--text3); font-size:11px; cursor:pointer; font-family:inherit; display:flex; align-items:center; gap:4px;">
                    <icon :name="session.theme === 'dark' ? 'sun' : 'moon'" :size="13" />
                </button>
                <button v-if="session.role === 'admin'" @click="showSettings = true"
                    title="Ajustes: empleados, terminal, impresora y datos del ticket"
                    style="min-height:44px; display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; font-family:inherit; font-size:12px;">
                    <span aria-hidden="true" style="font-size:15px; line-height:1;">⚙</span> Ajustes
                </button>
                <button @click="handleLogout" title="Cambiar usuario / Cerrar sesión"
                    style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text3); cursor:pointer; font-family:inherit; font-size:12px;">
                    <icon name="logout" :size="13" /> Salir
                </button>
            </div>
            <!-- Settings Modal -->
            <settings-modal v-if="showSettings" @close="showSettings = false" />
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:48px; width:420px; display:flex; flex-direction:column; gap:24px;">
                <div>
                    <div style="font-size:11px; color:var(--text3); letter-spacing:0.12em; text-transform:uppercase; margin-bottom:6px;">Apertura de caja</div>
                    <div style="font-size:22px; font-weight:600;">Buenos días, {{ (cashierName || 'Cajero').split(' ')[0] }}</div>
                </div>
                <div style="font-size:13px; color:var(--text2);">Indica el saldo inicial de la caja antes de comenzar la jornada.</div>

                <div>
                    <div style="font-size:11px; color:var(--text3); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.1em;">Saldo inicial (€)</div>
                    <div style="position:relative;">
                        <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text2); font-family:'IBM Plex Mono'; font-size:18px; pointer-events:none;">€</span>
                        <input type="text" inputmode="decimal" v-model="amount" placeholder="0,00"
                            style="width:100%; background:var(--surface2); border:1px solid var(--border2); border-radius:10px; padding:14px 16px 14px 40px; color:var(--text); font-family:'IBM Plex Mono'; font-size:22px; font-weight:500; outline:none;" />
                    </div>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    <button v-for="v in quick" :key="v" @click="setAmount(v)"
                        style="flex:0 0 auto; padding:7px 14px; border-radius:8px; border:1px solid; cursor:pointer; font-family:'IBM Plex Mono'; font-size:13px; transition:all .1s;"
                        :style="{ background: Number(amount) === v ? 'var(--green-bg)' : 'transparent', color: Number(amount) === v ? 'var(--green)' : 'var(--text2)', borderColor: Number(amount) === v ? 'var(--green-dim)' : 'var(--border)' }">
                        {{ v === 0 ? 'Sin fondos' : '€' + v }}
                    </button>
                </div>

                <button @click="confirm"
                    style="padding:16px; border-radius:12px; border:none; cursor:pointer; background:var(--green); color:#000; font-family:inherit; font-size:16px; font-weight:700; letter-spacing:0.02em;">
                    Abrir caja →
                </button>

                <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
                    <button @click="openDrawer"
                        style="width:100%; padding:12px; border-radius:12px; border:1px solid var(--border); cursor:pointer; background:transparent; color:var(--text2); font-family:inherit; font-size:14px; font-weight:500; transition:all .15s;"
                        onmouseover="this.style.borderColor='var(--text3)'; this.style.color='var(--text)'"
                        onmouseout="this.style.borderColor='var(--border)'; this.style.color='var(--text2)'">
                        🗂 Abrir cajón
                    </button>
                    <div v-if="drawerStatus" style="font-size:12px; color:var(--text3);">{{ drawerStatus }}</div>
                </div>
            </div>
        </div>
    `
};
