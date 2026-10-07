/* ─── Admin login — calls PHP API ─────────────────────────────────────────── */
(function () {
    var form   = document.getElementById('admin-login-form');
    var errBox = document.getElementById('login-error');

    var overlay      = document.getElementById('starting-money-overlay');
    var moneyInput   = document.getElementById('login-starting-money');
    var moneySaveBtn = document.getElementById('starting-money-save-btn');
    var moneyError   = document.getElementById('starting-money-error');

    var pendingStaffRedirect = '';
    var loginNext = '';

    function getLoginNext() {
        try {
            var params = new URLSearchParams(window.location.search);
            var next = (params.get('next') || '').trim();
            if (!next || next.indexOf('://') !== -1 || next.charAt(0) === '/') return '';
            return next;
        } catch (e) {
            return '';
        }
    }

    /** Allow ?next= only when it matches the signed-in role. */
    function sanitizeNextForRole(next, role) {
        var path = String(next || '').trim();
        if (!path || path.indexOf('://') !== -1 || path.charAt(0) === '/') return '';
        var lower = path.toLowerCase();
        var isStaffPage = lower.indexOf('staff_dashboard') !== -1;
        if (role === 'admin') {
            // Admin must never be sent to POS via leftover ?next= from staff logout/switch.
            return isStaffPage ? '' : path;
        }
        if (role === 'staff') {
            // Staff stays on POS; ignore admin dashboard deep-links.
            if (isStaffPage) return path;
            if (
                lower.indexOf('admin_dashboard') !== -1 ||
                lower.indexOf('ordering_dashboard') !== -1 ||
                lower.indexOf('inventory_dashboard') !== -1 ||
                lower.indexOf('sales_history') !== -1 ||
                lower.indexOf('staff_admin') !== -1 ||
                lower.indexOf('recipe') !== -1
            ) {
                return '';
            }
            return path;
        }
        return '';
    }

    loginNext = getLoginNext();

    function showError(msg) {
        if (errBox) {
            errBox.textContent = msg;
            errBox.hidden = false;
        } else {
            alert(msg);
        }
    }

    function clearError() {
        if (errBox) { errBox.textContent = ''; errBox.hidden = true; }
    }

    function toMoney(val) {
        var n = parseFloat(val);
        return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
    }

    function getStartingMoneyStorageKey() {
        return 'staff_sales_report_starting_money_' + new Date().toISOString().slice(0, 10);
    }

    function getStartingMoneyLockedKey() {
        return 'staff_sales_report_starting_money_locked_' + new Date().toISOString().slice(0, 10);
    }

    /** Once set for today (any staff on this device), skip the popup until shift reset/next day. */
    function hasStartingMoneyForToday() {
        if (localStorage.getItem(getStartingMoneyLockedKey()) === '1') {
            return true;
        }
        try {
            var raw = localStorage.getItem(getStartingMoneyStorageKey());
            if (!raw) return false;
            var parsed = JSON.parse(raw);
            var total = parseFloat(
                parsed && (parsed.total != null ? parsed.total : parsed.base)
            );
            return Number.isFinite(total) && total > 0;
        } catch (e) {
            return false;
        }
    }

    function getInventoryCheckDoneKey() {
        return 'staff_inventory_check_done_' + new Date().toISOString().slice(0, 10);
    }

    function hasInventoryCheckDoneForToday() {
        try {
            return localStorage.getItem(getInventoryCheckDoneKey()) === '1';
        } catch (e) {
            return false;
        }
    }

    function markPendingInventoryCheck() {
        if (hasInventoryCheckDoneForToday()) return;
        try {
            sessionStorage.setItem('ortus_pending_inventory_check', '1');
        } catch (e) {}
    }

    function continueStaffRedirect() {
        window.location.href = loginNext || pendingStaffRedirect || 'staff_dashboard.html';
    }

    function showMoneyError(msg) {
        if (!moneyError) {
            alert(msg);
            return;
        }
        moneyError.textContent = msg;
        moneyError.hidden = false;
    }

    function clearMoneyError() {
        if (moneyError) {
            moneyError.textContent = '';
            moneyError.hidden = true;
        }
    }

    function showStartingMoneyOverlay() {
        if (!overlay) return;
        overlay.classList.remove('is-hidden');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('starting-money-overlay-open');
        if (moneyInput) {
            moneyInput.value = '';
            moneyInput.focus();
        }
    }

    function hideStartingMoneyOverlay() {
        if (!overlay) return;
        overlay.classList.add('is-hidden');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('starting-money-overlay-open');
    }

    function saveStartingMoney() {
        clearMoneyError();
        var amount = toMoney(moneyInput && moneyInput.value);
        if (!amount) {
            showMoneyError('Enter a starting money amount greater than zero.');
            if (moneyInput) moneyInput.focus();
            return false;
        }

        localStorage.setItem(
            getStartingMoneyStorageKey(),
            JSON.stringify({ base: amount, additional_inputs: [], total: amount })
        );
        localStorage.setItem(getStartingMoneyLockedKey(), '1');
        return true;
    }

    if (moneySaveBtn) {
        moneySaveBtn.addEventListener('click', function () {
            if (!saveStartingMoney()) return;
            hideStartingMoneyOverlay();
            markPendingInventoryCheck();
            if (pendingStaffRedirect) {
                continueStaffRedirect();
            }
        });
    }

    if (moneyInput) {
        moneyInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (moneySaveBtn) moneySaveBtn.click();
            }
        });
    }

    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();

        var username = (document.getElementById('admin-username').value || '').trim();
        var password = document.getElementById('admin-password').value || '';
        var btn      = form.querySelector('.login-btn');

        if (!username || !password) {
            showError('Please enter both username/email and password.');
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'VERIFYING…';

        fetch('api/auth.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ username: username, password: password }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                if (typeof window.ortusBeginAuthSession === 'function') {
                    window.ortusBeginAuthSession();
                }
                var role = data.user && data.user.role ? String(data.user.role).toLowerCase() : '';
                var redirect = data.redirect || (role === 'admin' ? 'admin_dashboard.html' : 'staff_dashboard.html');
                var safeNext = sanitizeNextForRole(loginNext, role);

                // Role decides destination — never treat admin as staff because of ?next=.
                if (role === 'staff') {
                    pendingStaffRedirect = safeNext || redirect || 'staff_dashboard.html';
                    // Shared float for the day — only prompt on the first staff login / shift open.
                    if (hasStartingMoneyForToday()) {
                        // Still show inventory checking on POS if not completed yet today.
                        markPendingInventoryCheck();
                        continueStaffRedirect();
                        return;
                    }
                    showStartingMoneyOverlay();
                    btn.disabled    = false;
                    btn.textContent = 'LOGIN';
                    return;
                }

                window.location.href = safeNext || redirect || 'admin_dashboard.html';
            } else {
                showError(data.error || 'Login failed.');
                btn.disabled    = false;
                btn.textContent = 'LOGIN';
            }
        })
        .catch(function () {
            showError('Cannot reach server. Make sure XAMPP is running.');
            btn.disabled    = false;
            btn.textContent = 'LOGIN';
        });
    });
})();
