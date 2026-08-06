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
            if (pendingStaffRedirect) {
                window.location.href = loginNext || pendingStaffRedirect;
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
                var redirect = data.redirect || '';
                var role     = data.user && data.user.role ? String(data.user.role).toLowerCase() : '';
                var isStaff  = role === 'staff' || redirect.indexOf('staff_dashboard') !== -1;

                if (isStaff) {
                    pendingStaffRedirect = loginNext || redirect || 'staff_dashboard.html';
                    showStartingMoneyOverlay();
                    btn.disabled    = false;
                    btn.textContent = 'LOGIN';
                    return;
                }

                window.location.href = loginNext || redirect;
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
