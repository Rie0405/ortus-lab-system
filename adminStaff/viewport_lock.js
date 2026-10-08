/* Lock screen height once on load so the virtual keyboard does not
   recalculate layout height while typing. No business logic. */
(function () {
    function lockVh() {
        var vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', vh + 'px');
    }

    if (document.readyState === 'complete') {
        lockVh();
    } else {
        window.addEventListener('load', lockVh);
    }
})();
