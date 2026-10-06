/* Enhance all password inputs with show/hide eye toggle */
(function () {
    var ICON_CLOSED = 'icons_admin/eye-closed-svgrepo-com.svg';
    var ICON_OPEN = 'icons_admin/eye-svgrepo-com.svg';

    function enhance(input) {
        if (!input || input.dataset.pwToggle === '1') return;
        input.dataset.pwToggle = '1';

        var wrap = input.closest('.pw-toggle-wrap');
        if (!wrap) {
            var existing = input.closest('.input-wrap');
            if (existing) {
                wrap = existing;
                wrap.classList.add('pw-toggle-wrap');
            } else {
                wrap = document.createElement('div');
                wrap.className = 'pw-toggle-wrap';
                input.parentNode.insertBefore(wrap, input);
                wrap.appendChild(input);
            }
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle-btn';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.tabIndex = 0;

        var img = document.createElement('img');
        img.src = ICON_CLOSED;
        img.alt = '';
        img.width = 18;
        img.height = 18;
        btn.appendChild(img);
        wrap.appendChild(btn);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            img.src = showing ? ICON_CLOSED : ICON_OPEN;
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
        });
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('input[type="password"]').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.initPasswordToggles = init;
})();
