/**
 * Theme toggle — standalone utility for pages that don't load useSession.js.
 * Reads/writes theme preference from localStorage and applies it to <html>.
 */
(function () {
    var THEME_KEY = 'venpostpv_session';
    var html = document.documentElement;

    function getTheme() {
        var raw = localStorage.getItem(THEME_KEY);
        if (raw) {
            try {
                var data = JSON.parse(raw);
                if (data.theme) return data.theme;
            } catch (e) { /* ignore */ }
        }
        return 'dark';
    }

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
    }

    function toggleTheme() {
        var current = getTheme();
        var next = current === 'dark' ? 'light' : 'dark';
        // Update full session key if it exists, otherwise just set theme
        var raw = localStorage.getItem(THEME_KEY);
        if (raw) {
            try {
                var data = JSON.parse(raw);
                data.theme = next;
                localStorage.setItem(THEME_KEY, JSON.stringify(data));
            } catch (e) {
                localStorage.setItem(THEME_KEY, JSON.stringify({ theme: next }));
            }
        } else {
            localStorage.setItem(THEME_KEY, JSON.stringify({ theme: next }));
        }
        applyTheme(next);
        // Notify Vue components if useSession is loaded
        if (window.useSession && window.useSession.toggleTheme) {
            // useSession manages its own state; only call if session.theme differs
            if (window.useSession.session && window.useSession.session.theme !== next) {
                window.useSession.toggleTheme();
            }
        }
        // Dispatch custom event for any listener (e.g. Vue components)
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: next } }));
    }

    // Apply on load
    applyTheme(getTheme());

    // Expose globally for inline onclick or Vue components
    window.ThemeToggle = { getTheme: getTheme, toggle: toggleTheme };
})();
