/* Ortus shared image crop / pan editor (before upload) */
(function (global) {
    'use strict';

    var overlay = null;
    var els = {};
    var state = null;

    function ensureDom() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'img-crop-overlay';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="img-crop-modal">' +
                '<div class="img-crop-modal__head">' +
                    '<div>' +
                        '<h3 class="img-crop-modal__title" id="img-crop-title">Adjust image</h3>' +
                        '<p class="img-crop-modal__hint">Drag to move · use zoom to crop. Confirm to upload.</p>' +
                    '</div>' +
                    '<button type="button" class="img-crop-modal__close" id="img-crop-close" aria-label="Cancel">×</button>' +
                '</div>' +
                '<div class="img-crop-modal__stage">' +
                    '<div class="img-crop-viewport" id="img-crop-viewport">' +
                        '<img id="img-crop-img" alt="">' +
                    '</div>' +
                '</div>' +
                '<div class="img-crop-modal__controls">' +
                    '<div class="img-crop-zoom-row">' +
                        '<span>Zoom</span>' +
                        '<input type="range" id="img-crop-zoom" min="100" max="300" value="100" step="1">' +
                    '</div>' +
                '</div>' +
                '<div class="img-crop-modal__actions">' +
                    '<button type="button" class="img-crop-btn img-crop-btn--ghost" id="img-crop-cancel">Cancel</button>' +
                    '<button type="button" class="img-crop-btn img-crop-btn--primary" id="img-crop-confirm">Crop &amp; Use</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        els = {
            title: overlay.querySelector('#img-crop-title'),
            viewport: overlay.querySelector('#img-crop-viewport'),
            img: overlay.querySelector('#img-crop-img'),
            zoom: overlay.querySelector('#img-crop-zoom'),
            close: overlay.querySelector('#img-crop-close'),
            cancel: overlay.querySelector('#img-crop-cancel'),
            confirm: overlay.querySelector('#img-crop-confirm')
        };

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) cancel();
        });
        els.close.addEventListener('click', cancel);
        els.cancel.addEventListener('click', cancel);
        els.confirm.addEventListener('click', confirm);
        els.zoom.addEventListener('input', function () {
            if (!state) return;
            var pct = parseInt(els.zoom.value, 10) || 100;
            setScale(state.minScale * (pct / 100));
        });

        els.viewport.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerUp);
        document.addEventListener('keydown', function (e) {
            if (!state || overlay.hidden) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                cancel();
            }
        });
    }

    function onPointerDown(e) {
        if (!state || e.button != null && e.button !== 0) return;
        e.preventDefault();
        state.dragging = true;
        state.dragX = e.clientX;
        state.dragY = e.clientY;
        state.originX = state.x;
        state.originY = state.y;
        els.viewport.classList.add('is-dragging');
        try { els.viewport.setPointerCapture(e.pointerId); } catch (err) {}
    }

    function onPointerMove(e) {
        if (!state || !state.dragging) return;
        e.preventDefault();
        state.x = state.originX + (e.clientX - state.dragX);
        state.y = state.originY + (e.clientY - state.dragY);
        clampAndRender();
    }

    function onPointerUp() {
        if (!state || !state.dragging) return;
        state.dragging = false;
        els.viewport.classList.remove('is-dragging');
    }

    function setScale(nextScale) {
        if (!state) return;
        var vw = state.vw;
        var vh = state.vh;
        var prev = state.scale;
        var cx = (vw / 2 - state.x) / prev;
        var cy = (vh / 2 - state.y) / prev;
        state.scale = Math.max(state.minScale, Math.min(state.minScale * 3, nextScale));
        state.x = vw / 2 - cx * state.scale;
        state.y = vh / 2 - cy * state.scale;
        els.zoom.value = String(Math.round((state.scale / state.minScale) * 100));
        clampAndRender();
    }

    function clampAndRender() {
        if (!state) return;
        var dispW = state.nw * state.scale;
        var dispH = state.nh * state.scale;
        var minX = state.vw - dispW;
        var minY = state.vh - dispH;
        if (dispW <= state.vw) state.x = (state.vw - dispW) / 2;
        else state.x = Math.min(0, Math.max(minX, state.x));
        if (dispH <= state.vh) state.y = (state.vh - dispH) / 2;
        else state.y = Math.min(0, Math.max(minY, state.y));
        els.img.style.transform = 'translate(' + state.x + 'px,' + state.y + 'px) scale(' + state.scale + ')';
    }

    function cleanupObjectUrl() {
        if (state && state.objectUrl) {
            try { URL.revokeObjectURL(state.objectUrl); } catch (e) {}
            state.objectUrl = null;
        }
    }

    function closeUi() {
        overlay.hidden = true;
        els.img.removeAttribute('src');
        els.viewport.classList.remove('is-dragging', 'img-crop-viewport--circle');
        els.confirm.disabled = false;
        cleanupObjectUrl();
        state = null;
    }

    function cancel() {
        if (!state) {
            if (overlay) overlay.hidden = true;
            return;
        }
        var reject = state.reject;
        closeUi();
        if (reject) reject(new Error('cancelled'));
    }

    function confirm() {
        if (!state || state.busy) return;
        state.busy = true;
        els.confirm.disabled = true;

        var opts = state.options;
        var maxEdge = opts.maxOutputSize || 1200;
        var sx = -state.x / state.scale;
        var sy = -state.y / state.scale;
        var sw = state.vw / state.scale;
        var sh = state.vh / state.scale;

        // Clamp crop rect to image bounds
        sx = Math.max(0, Math.min(state.nw - 1, sx));
        sy = Math.max(0, Math.min(state.nh - 1, sy));
        sw = Math.max(1, Math.min(state.nw - sx, sw));
        sh = Math.max(1, Math.min(state.nh - sy, sh));

        var outW = Math.round(sw);
        var outH = Math.round(sh);
        if (outW > maxEdge || outH > maxEdge) {
            var r = Math.min(maxEdge / outW, maxEdge / outH);
            outW = Math.max(1, Math.round(outW * r));
            outH = Math.max(1, Math.round(outH * r));
        }

        var canvas = document.createElement('canvas');
        canvas.width = outW;
        canvas.height = outH;
        var ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(els.img, sx, sy, sw, sh, 0, 0, outW, outH);

        var mime = opts.outputType || 'image/jpeg';
        var quality = opts.outputQuality != null ? opts.outputQuality : 0.92;
        var baseName = (opts.fileName || 'image').replace(/\.[^.]+$/, '');
        var ext = mime === 'image/png' ? 'png' : (mime === 'image/webp' ? 'webp' : 'jpg');

        canvas.toBlob(function (blob) {
            if (!blob) {
                state.busy = false;
                els.confirm.disabled = false;
                alert('Could not crop image. Try another file.');
                return;
            }
            var file = new File([blob], baseName + '-crop.' + ext, { type: mime, lastModified: Date.now() });
            var resolve = state.resolve;
            closeUi();
            if (resolve) resolve(file);
        }, mime, quality);
    }

    function open(options) {
        ensureDom();
        options = options || {};
        var file = options.file;
        if (!file) return Promise.reject(new Error('No file'));

        return new Promise(function (resolve, reject) {
            cleanupObjectUrl();
            var objectUrl = URL.createObjectURL(file);
            var aspect = options.aspect > 0 ? options.aspect : 1;
            var shape = options.shape === 'circle' ? 'circle' : 'rect';
            var maxW = Math.min(360, window.innerWidth - 80);
            var vw = maxW;
            var vh = Math.round(vw / aspect);
            if (vh > Math.min(420, window.innerHeight * 0.5)) {
                vh = Math.min(420, Math.round(window.innerHeight * 0.5));
                vw = Math.round(vh * aspect);
            }

            els.title.textContent = options.title || 'Adjust image';
            els.viewport.style.width = vw + 'px';
            els.viewport.style.height = vh + 'px';
            els.viewport.classList.toggle('img-crop-viewport--circle', shape === 'circle');
            els.zoom.value = '100';
            els.confirm.disabled = false;
            els.img.style.width = 'auto';
            els.img.style.height = 'auto';
            els.img.style.transform = 'none';

            state = {
                options: {
                    aspect: aspect,
                    shape: shape,
                    title: options.title,
                    outputType: options.outputType || (file.type === 'image/png' ? 'image/png' : 'image/jpeg'),
                    outputQuality: options.outputQuality != null ? options.outputQuality : 0.92,
                    maxOutputSize: options.maxOutputSize || 1200,
                    fileName: file.name || 'image'
                },
                objectUrl: objectUrl,
                resolve: resolve,
                reject: reject,
                vw: vw,
                vh: vh,
                nw: 0,
                nh: 0,
                scale: 1,
                minScale: 1,
                x: 0,
                y: 0,
                dragging: false,
                busy: false
            };

            els.img.onload = function () {
                state.nw = els.img.naturalWidth || 1;
                state.nh = els.img.naturalHeight || 1;
                state.minScale = Math.max(state.vw / state.nw, state.vh / state.nh);
                state.scale = state.minScale;
                state.x = (state.vw - state.nw * state.scale) / 2;
                state.y = (state.vh - state.nh * state.scale) / 2;
                els.img.style.width = state.nw + 'px';
                els.img.style.height = state.nh + 'px';
                clampAndRender();
                overlay.hidden = false;
            };
            els.img.onerror = function () {
                cleanupObjectUrl();
                state = null;
                reject(new Error('Could not load image'));
            };
            els.img.src = objectUrl;
        });
    }

    /**
     * Crop a selected File before upload.
     * Returns Promise<File>. Rejects with message "cancelled" if user cancels.
     */
    function cropFile(file, options) {
        options = options || {};
        options.file = file;
        return open(options);
    }

    global.OrtusImageCrop = {
        open: open,
        cropFile: cropFile,
        ASPECT_PRODUCT: 231 / 172,
        ASPECT_SQUARE: 1
    };
})(window);
