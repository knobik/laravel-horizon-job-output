/**
 * Shared groundwork for the scripts this package inlines into Horizon's layout.
 *
 * They are concatenated into one inline module, so this runs first and hangs the
 * pieces both of them need off a single object. Without it each script carries
 * its own copy of the URL handling, which is exactly the kind of thing that
 * drifts apart the first time Horizon changes how it computes its base path.
 */
window.HorizonJobOutputSupport = (function () {
    const settings = window.HorizonJobOutput || {};

    function basePath() {
        return (window.Horizon && window.Horizon.basePath) || '';
    }

    /**
     * The current path relative to the dashboard root. Horizon uses history-mode
     * routing, so every screen is a real path.
     */
    function dashboardPath() {
        const base = basePath();
        let path = window.location.pathname;

        if (base && path.indexOf(base) === 0) {
            path = path.slice(base.length);
        }

        return path.replace(/\/$/, '') || '/';
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getJson(path) {
        return fetch(basePath() + path, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .catch(() => null);
    }

    /**
     * Send a JSON body to the dashboard.
     *
     * Horizon runs its routes through the `web` middleware group, so a POST
     * without a CSRF token is rejected outright. Horizon's own layout carries
     * the token in a meta tag for the same reason, and this reads that one
     * rather than adding a second.
     *
     * Unlike getJson this reports how it went. A caller acting on the response
     * has to tell "the server said no" apart from "the request never landed",
     * which a bare null cannot express.
     */
    function postJson(path, body) {
        const token = document.querySelector('meta[name="csrf-token"]');

        return fetch(basePath() + path, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        })
            .then((response) =>
                response
                    .json()
                    .catch(() => null)
                    .then((data) => ({ ok: response.ok, status: response.status, data }))
            )
            .catch(() => ({ ok: false, status: 0, data: null }));
    }

    /**
     * Mount points are created by Vue when it renders the layout template, so on
     * a cold load they may not exist yet.
     */
    function whenElementExists(id, callback, attempts) {
        if (document.getElementById(id)) {
            callback();
            return;
        }

        if ((attempts || 0) > 50) {
            return;
        }

        setTimeout(() => whenElementExists(id, callback, (attempts || 0) + 1), 100);
    }

    // vue-router pushes state rather than reloading, so navigation is observed by
    // wrapping the history methods it calls and re-announcing them as an event.
    // Done here, once, rather than as a side effect of whichever feature script
    // happened to load first.
    ['pushState', 'replaceState'].forEach((method) => {
        const original = history[method];

        history[method] = function () {
            const result = original.apply(this, arguments);
            window.dispatchEvent(new Event('hjo:navigated'));
            return result;
        };
    });

    /**
     * Call `sync` whenever the dashboard navigates, and once on load.
     */
    function onNavigation(sync) {
        window.addEventListener('hjo:navigated', sync);
        window.addEventListener('popstate', sync);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', sync);
        } else {
            sync();
        }
    }

    return {
        settings,
        pollInterval: settings.pollInterval || 2000,
        basePath,
        dashboardPath,
        escapeHtml,
        getJson,
        postJson,
        whenElementExists,
        onNavigation,
    };
})();
