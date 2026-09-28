/* ─── Browser-session auth gate ─────────────────────────────────────────────
   Keep login across refresh / Ctrl+F5 / in-app navigation.
   Force re-login only when the browser/tab session is truly new (no tab mark
   after the previous browser session ended).
─────────────────────────────────────────────────────────────────────────── */
(function (w) {
    var SS_KEY = 'ortus_auth_tab';
    var HB_KEY = 'ortus_auth_hb';
    var HB_MAX_MS = 120000;
    var LOGIN = 'admin.html';

    function pageName() {
        return (w.location.pathname.split('/').pop() || '').split('?')[0];
    }

    function isLoginPage() {
        return pageName().toLowerCase() === 'admin.html';
    }

    function markLive() {
        try {
            sessionStorage.setItem(SS_KEY, '1');
            localStorage.setItem(HB_KEY, String(Date.now()));
        } catch (e) { /* ignore */ }
    }

    function clearLive() {
        try {
            sessionStorage.removeItem(SS_KEY);
            localStorage.removeItem(HB_KEY);
        } catch (e) { /* ignore */ }
    }

    function hasTabMark() {
        try {
            return sessionStorage.getItem(SS_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function heartbeatFresh() {
        try {
            var t = parseInt(localStorage.getItem(HB_KEY) || '0', 10);
            return t > 0 && (Date.now() - t) < HB_MAX_MS;
        } catch (e) {
            return false;
        }
    }

    function navType() {
        try {
            var entry = performance.getEntriesByType('navigation')[0];
            return entry && entry.type ? String(entry.type) : '';
        } catch (e) {
            return '';
        }
    }

    function isReloadNavigation() {
        var type = navType();
        if (type === 'reload') return true;
        // Legacy fallback
        try {
            if (w.performance && w.performance.navigation
                && w.performance.navigation.type === 1) {
                return true;
            }
        } catch (e) { /* ignore */ }
        return false;
    }

    function goLogin() {
        var next = pageName();
        if (!next || next.toLowerCase() === 'admin.html') {
            w.location.replace(LOGIN);
            return;
        }
        w.location.replace(LOGIN + '?next=' + encodeURIComponent(next));
    }

    function serverLogout() {
        return fetch('api/auth.php', {
            method: 'DELETE',
            credentials: 'same-origin',
            keepalive: true
        }).catch(function () { /* ignore */ });
    }

    w.ortusBeginAuthSession = function () {
        markLive();
    };

    w.ortusClearAuthSession = function () {
        clearLive();
    };

    w.ortusMarkInternalNav = function () {
        w.__ortusInternalNav = true;
    };

    // Same-origin link clicks = stay logged in across admin/staff pages
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        if (a.target && a.target !== '_self' && a.target !== '') return;
        try {
            var url = new URL(href, w.location.href);
            if (url.origin === w.location.origin) w.__ortusInternalNav = true;
        } catch (err) { /* ignore */ }
    }, true);

    // Mark reload shortcuts so unload handlers do not treat F5 as a close
    document.addEventListener('keydown', function (e) {
        var key = String(e.key || '').toLowerCase();
        if (key === 'f5' || ((e.ctrlKey || e.metaKey) && key === 'r')) {
            w.__ortusIsReload = true;
            w.__ortusInternalNav = true;
        }
    }, true);

    // Programmatic navigations used around the app
    var _assign = w.location.assign.bind(w.location);
    var _replace = w.location.replace.bind(w.location);
    w.location.assign = function (url) {
        w.__ortusInternalNav = true;
        return _assign(url);
    };
    w.location.replace = function (url) {
        w.__ortusInternalNav = true;
        return _replace(url);
    };
    try {
        var hrefDesc = Object.getOwnPropertyDescriptor(w.Location.prototype, 'href');
        if (hrefDesc && hrefDesc.set && hrefDesc.get) {
            Object.defineProperty(w.Location.prototype, 'href', {
                configurable: true,
                enumerable: hrefDesc.enumerable,
                get: function () {
                    return hrefDesc.get.call(this);
                },
                set: function (url) {
                    w.__ortusInternalNav = true;
                    hrefDesc.set.call(this, url);
                }
            });
        }
    } catch (err) { /* ignore */ }

    if (isLoginPage()) {
        return;
    }

    function startHeartbeat() {
        if (w.__ortusAuthHb) return;
        markLive();
        w.__ortusAuthHb = setInterval(markLive, 5000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') markLive();
        });
        // IMPORTANT: refresh / Ctrl+F5 / toolbar reload also fire pagehide.
        // Never destroy the PHP session here — only the next cold open should.
        w.addEventListener('pagehide', function (e) {
            if (w.__ortusInternalNav || w.__ortusIsReload) return;
            if (e && e.persisted) return;
            // Drop heartbeat only. sessionStorage stays for refresh in this tab;
            // a real browser close clears sessionStorage, so the next open logs out.
            try { localStorage.removeItem(HB_KEY); } catch (err) { /* ignore */ }
        });
    }

    // Stay logged in on reload, same-tab mark, or another live tab's heartbeat.
    if (isReloadNavigation() || hasTabMark() || heartbeatFresh()) {
        startHeartbeat();
        fetch('api/auth.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success || !d.user) {
                    clearLive();
                    goLogin();
                }
            })
            .catch(function () {
                clearLive();
                goLogin();
            });
        return;
    }

    // Fresh browser/tab session with leftover PHP cookie → force login
    clearLive();
    serverLogout().finally(function () {
        goLogin();
    });
})(window);
