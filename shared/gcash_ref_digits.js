/**
 * Digits-only binding for GCash reference number inputs.
 * Targets: .js-gcash-ref-digits, [data-gcash-ref-digits], #gcash-ref-input, #gcash-ref-number
 */
(function (global) {
    'use strict';

    function sanitizeGcashRefDigits(value) {
        return String(value == null ? '' : value).replace(/\D+/g, '');
    }

    function bindGcashRefInput(el) {
        if (!el || el.nodeName !== 'INPUT' || el.__gcashRefDigitsBound) return;
        el.__gcashRefDigitsBound = true;
        el.setAttribute('inputmode', 'numeric');
        el.setAttribute('pattern', '[0-9]*');
        el.setAttribute('autocomplete', 'off');

        function applyDigits() {
            var next = sanitizeGcashRefDigits(el.value);
            if (el.value !== next) {
                var start = el.selectionStart;
                var end = el.selectionEnd;
                var removedBefore = String(el.value.slice(0, start == null ? 0 : start)).replace(/\D+/g, '').length;
                el.value = next;
                if (typeof el.setSelectionRange === 'function' && el === document.activeElement) {
                    try {
                        el.setSelectionRange(removedBefore, removedBefore);
                    } catch (e) {}
                }
            }
        }

        el.addEventListener('beforeinput', function (e) {
            if (!e || e.isComposing) return;
            if (e.inputType && e.inputType.indexOf('insert') === 0 && e.data && /\D/.test(e.data)) {
                e.preventDefault();
            }
        });
        el.addEventListener('input', applyDigits);
        el.addEventListener('paste', function () {
            setTimeout(applyDigits, 0);
        });
        el.addEventListener('drop', function () {
            setTimeout(applyDigits, 0);
        });
        applyDigits();
    }

    function bindGcashRefInputs(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var nodes = scope.querySelectorAll(
            'input.js-gcash-ref-digits, input[data-gcash-ref-digits], #gcash-ref-input, #gcash-ref-number'
        );
        Array.prototype.forEach.call(nodes, bindGcashRefInput);
    }

    global.sanitizeGcashRefDigits = sanitizeGcashRefDigits;
    global.bindGcashRefInputs = bindGcashRefInputs;
    global.bindGcashRefInput = bindGcashRefInput;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindGcashRefInputs(document);
        });
    } else {
        bindGcashRefInputs(document);
    }
})(typeof window !== 'undefined' ? window : this);
