/* ─── Shared admin dashboard script ─────────────────────────────────────────
   Runs on ordering_dashboard.html (menu CRUD) and all other admin pages.
   Assumes pages are served through XAMPP (api/ endpoints via PHP).
─────────────────────────────────────────────────────────────────────────── */

// ─── Logout ──────────────────────────────────────────────────────────────────
(function () {
    var btn = document.getElementById('btn-logout');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (typeof window.ortusClearAuthSession === 'function') {
            window.ortusClearAuthSession();
        }
        if (typeof window.ortusMarkInternalNav === 'function') {
            window.ortusMarkInternalNav();
        }
        fetch('api/auth.php', { method: 'DELETE' })
            .finally(function () { window.location.href = 'admin.html'; });
    });
})();

function switchOrtusAccount(nextPage) {
    var next = nextPage || window.location.pathname.split('/').pop() || 'admin_dashboard.html';
    if (typeof window.ortusClearAuthSession === 'function') {
        window.ortusClearAuthSession();
    }
    if (typeof window.ortusMarkInternalNav === 'function') {
        window.ortusMarkInternalNav();
    }
    fetch('api/auth.php', { method: 'DELETE' })
        .finally(function () {
            window.location.href = 'admin.html?next=' + encodeURIComponent(next);
        });
}

// ─── API helpers ─────────────────────────────────────────────────────────────
function apiCall(method, url, body) {
    var opts = {
        method:  method,
        headers: { 'Content-Type': 'application/json' },
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(function (r) {
        return r.text().then(function (text) {
            var data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (e) {
                var snippet = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 180);
                throw new Error(
                    snippet
                        ? ('Server error (not JSON): ' + snippet)
                        : ('Request failed (' + r.status + ')')
                );
            }
            if (!r.ok) {
                var msg = (data && data.error) ? data.error : ('Request failed (' + r.status + ')');
                throw new Error(msg);
            }
            return data || { success: false, error: 'Empty response' };
        });
    });
}

// ─── Menu Management (ordering_dashboard.html only) ──────────────────────────
(function () {
    var tableBody      = document.querySelector('.table-wrap');
    var createBackdrop = document.getElementById('create-product-modal');
    var editBackdrop   = document.getElementById('edit-product-modal');
    if (!tableBody || !createBackdrop) return;   // not on this page

    var categories  = [];
    var subcategories = [];
    var mainCategories = [];
    var allItems    = [];
    var editingId   = null;
    /** Category ids merged into the single "Beverages" option (same rules as staff POS). */
    var beverageMergedCategoryIds = [];
    var menuFilterBtn = document.getElementById('btn-menu-filter');
    var menuFilterSummary = document.getElementById('menu-filter-summary');
    var menuFilterBackdrop = document.getElementById('menu-filter-backdrop');
    var menuFilterCheckGrid = document.getElementById('menu-filter-check-grid');
    var menuFilterCloseBtn = document.getElementById('menu-filter-close');
    var menuFilterResetBtn = document.getElementById('menu-filter-reset');
    var menuFilterDoneBtn = document.getElementById('menu-filter-done');
    var subcategoryChipsRow = document.getElementById('menu-subcategory-chips');
    var menuSearchInput = document.getElementById('menu-search-input');
    /** category chip keys → enabled; empty object means “all” until categories load */
    var menuCategoryFilters = {};
    /** main category keys (main-1, …) → enabled */
    var menuMainCategoryFilters = {};
    var selectedSubcategoryChipId = 'all';
    var menuSearchQuery = '';
    var createImageUrl = '';
    var editImageUrl = '';
    /** True only when the admin uploaded a product-specific image (not inherited from category icon). */
    var createImageIsCustom = false;

    // ── Product visual upload (create + edit) ───────────────────────────────
    var PRODUCT_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    var PRODUCT_IMAGE_TYPES = {
        'image/jpeg': true,
        'image/jpg': true,
        'image/png': true,
        'image/webp': true
    };

    function resetDropzonePreview(prefix) {
        var dropzone = document.getElementById(prefix + '-visual-dropzone');
        var preview = document.getElementById(prefix + '-visual-preview');
        if (!dropzone || !preview) return;
        preview.onload = null;
        preview.onerror = null;
        preview.hidden = true;
        preview.removeAttribute('src');
        dropzone.classList.remove('has-preview');
        dropzone.classList.remove('is-dragover');
        dropzone.style.pointerEvents = '';
    }

    function setDropzonePreview(prefix, url) {
        var dropzone = document.getElementById(prefix + '-visual-dropzone');
        var preview = document.getElementById(prefix + '-visual-preview');
        if (!dropzone || !preview) return;
        if (url) {
            preview.hidden = false;
            preview.onerror = function () {
                resetDropzonePreview(prefix);
                setProductImageUrl(prefix, '');
            };
            preview.src = url;
            dropzone.classList.add('has-preview');
        } else {
            resetDropzonePreview(prefix);
        }
    }

    function deleteProductImageFile(url) {
        if (!url || /^https?:\/\//i.test(url)) return Promise.resolve();
        return fetch('api/menu_image_delete.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: url })
        }).then(function (r) { return r.json(); }).catch(function (err) {
            console.warn('Image delete failed:', err);
        });
    }

    function getProductImageUrl(prefix) {
        return prefix === 'prod' ? createImageUrl : editImageUrl;
    }

    function previewHasStoredImage(prefix) {
        var preview = document.getElementById(prefix + '-visual-preview');
        var src = preview && preview.getAttribute('src') ? String(preview.getAttribute('src')) : '';
        return src.indexOf('uploads/menu/') >= 0 || src.indexOf('blob:') === 0;
    }

    function setProductImageUrl(prefix, url) {
        if (prefix === 'prod') createImageUrl = url || '';
        if (prefix === 'edit') editImageUrl = url || '';
    }

    function clearProductVisual(prefix, deleteFile) {
        var preview = document.getElementById(prefix + '-visual-preview');
        var currentUrl = getProductImageUrl(prefix);
        var previewSrc = preview && preview.getAttribute('src') ? String(preview.getAttribute('src')) : '';
        if (!currentUrl && previewSrc.indexOf('uploads/menu/') >= 0) {
            currentUrl = previewSrc;
        }
        var wasCustom = prefix !== 'prod' || createImageIsCustom;
        setProductImageUrl(prefix, '');
        if (prefix === 'prod') createImageIsCustom = false;
        var fileInput = document.getElementById(prefix + '-visual-file');
        if (fileInput) fileInput.value = '';
        resetDropzonePreview(prefix);
        // Never delete a shared category icon when clearing an inherited preview.
        if (deleteFile && wasCustom && currentUrl && currentUrl.indexOf('blob:') !== 0) {
            deleteProductImageFile(currentUrl);
        }
        if (prefix === 'prod') {
            syncCreateProductVisualFromCategory();
        }
    }

    function resolveCategoryIconForCreate(categoryId) {
        var catId = parseInt(categoryId, 10) || 0;
        if (!catId) return '';
        var cat = findCategoryById(catId);
        if (cat && cat.icon_url) return String(cat.icon_url).trim();
        // Beverages umbrella: use any merged sibling that already has an icon.
        if (cat && categoryNameMergesIntoBeveragesAdmin(cat.name)) {
            var ids = getCategoryIdsForIconApply(catId);
            for (var i = 0; i < ids.length; i++) {
                var sibling = findCategoryById(ids[i]);
                if (sibling && sibling.icon_url) return String(sibling.icon_url).trim();
            }
        }
        return '';
    }

    function syncCreateProductVisualFromCategory() {
        if (createImageIsCustom) return;
        var catSel = document.getElementById('prod-category');
        var catId = catSel && catSel.value ? parseInt(catSel.value, 10) : 0;
        var iconUrl = catId ? resolveCategoryIconForCreate(catId) : '';
        setProductImageUrl('prod', iconUrl);
        setDropzonePreview('prod', iconUrl);
    }

    function uploadProductImage(file) {
        var fd = new FormData();
        fd.append('image', file);
        return fetch('api/menu_image_upload.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function (r) { return r.json(); });
    }

    function cropBeforeUpload(file, cropOpts) {
        if (!file) return Promise.reject(new Error('No file'));
        if (typeof window.OrtusImageCrop === 'undefined' || !window.OrtusImageCrop.cropFile) {
            return Promise.resolve(file);
        }
        return window.OrtusImageCrop.cropFile(file, cropOpts || {}).catch(function (err) {
            if (err && String(err.message || err) === 'cancelled') {
                return Promise.reject({ cancelled: true });
            }
            return Promise.reject(err);
        });
    }

    function handleProductImageFile(prefix, file) {
        if (!file) return;
        if (!PRODUCT_IMAGE_TYPES[file.type]) {
            alert('Only PNG, JPG, or WEBP images are allowed.');
            return;
        }
        if (file.size > PRODUCT_IMAGE_MAX_BYTES) {
            alert('Image must be PNG/JPG up to 5MB.');
            return;
        }

        var dropzone = document.getElementById(prefix + '-visual-dropzone');
        var titleEl = document.getElementById(prefix + '-visual-title');
        var prevTitle = titleEl ? titleEl.textContent : '';
        var previousUrl = getProductImageUrl(prefix);
        var previousWasCustom = prefix !== 'prod' || createImageIsCustom;
        var localUrl = '';

        cropBeforeUpload(file, {
            title: 'Adjust product image',
            aspect: (window.OrtusImageCrop && window.OrtusImageCrop.ASPECT_PRODUCT) || (231 / 172),
            shape: 'rect',
            outputType: 'image/jpeg',
            outputQuality: 0.92,
            maxOutputSize: 1400
        }).then(function (cropped) {
            localUrl = URL.createObjectURL(cropped);
            setDropzonePreview(prefix, localUrl);
        if (titleEl) titleEl.textContent = 'UPLOADING…';
        if (dropzone) dropzone.style.pointerEvents = 'none';
            return uploadProductImage(cropped).then(function (res) {
            if (res && res.success && res.url) {
                setProductImageUrl(prefix, res.url);
                    if (prefix === 'prod') createImageIsCustom = true;
                setDropzonePreview(prefix, res.url);
                    if (previousUrl && previousUrl !== res.url && previousWasCustom) {
                    deleteProductImageFile(previousUrl);
                }
            } else {
                alert('Upload failed: ' + ((res && res.error) || 'Unknown error'));
                setDropzonePreview(prefix, previousUrl || '');
            }
            });
        }).catch(function (err) {
            if (err && err.cancelled) return;
            console.error('Image upload failed:', err);
            alert((err && err.message) ? err.message : 'Image upload failed. Please try again.');
            setDropzonePreview(prefix, previousUrl || '');
        }).finally(function () {
            if (localUrl) URL.revokeObjectURL(localUrl);
            if (titleEl) titleEl.textContent = prevTitle || 'DRAG BLUEPRINT OR CLICK TO UPLOAD';
            if (dropzone) dropzone.style.pointerEvents = '';
        });
    }

    function wireProductVisualDropzone(prefix) {
        var dropzone = document.getElementById(prefix + '-visual-dropzone');
        var fileInput = document.getElementById(prefix + '-visual-file');
        var updateBtn = document.getElementById(prefix + '-visual-update');
        var deleteBtn = document.getElementById(prefix + '-visual-delete');
        if (!dropzone || !fileInput) return;

        function openPicker() { fileInput.click(); }

        dropzone.addEventListener('click', function (e) {
            if (e.target === fileInput) return;
            if (e.target.closest('.product-upload-action')) return;
            if (dropzone.classList.contains('has-preview')) return;
            openPicker();
        });
        dropzone.addEventListener('keydown', function (e) {
            if (dropzone.classList.contains('has-preview')) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openPicker();
            }
        });
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            handleProductImageFile(prefix, file);
            fileInput.value = '';
        });

        if (updateBtn) {
            updateBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPicker();
            });
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var hasImage = !!getProductImageUrl(prefix) ||
                    (dropzone.classList.contains('has-preview') && previewHasStoredImage(prefix));
                if (!hasImage) {
                    resetDropzonePreview(prefix);
                    return;
                }
                if (!confirm('Remove this product image?')) return;
                clearProductVisual(prefix, true);
            });
        }

        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            handleProductImageFile(prefix, file);
        });
    }

    wireProductVisualDropzone('prod');
    wireProductVisualDropzone('edit');

    // ── Category / subcategory icon uploads ─────────────────────────────────
    function setCatalogIconPreview(prefix, url) {
        var dropzone = document.getElementById(prefix + '-dropzone');
        var preview = document.getElementById(prefix + '-preview');
        if (!dropzone || !preview) return;
        if (url) {
            preview.hidden = false;
            preview.src = url;
            dropzone.classList.add('has-preview');
        } else {
            preview.hidden = true;
            preview.removeAttribute('src');
            dropzone.classList.remove('has-preview');
            dropzone.classList.remove('is-dragover');
        }
    }

    function setCatalogIconEnabled(prefix, enabled, hintText) {
        var dropzone = document.getElementById(prefix + '-dropzone');
        var hint = document.getElementById(prefix + '-hint');
        if (dropzone) {
            dropzone.classList.toggle('is-disabled', !enabled);
            dropzone.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            if (!enabled) dropzone.classList.remove('is-dragover');
        }
        if (hint) {
            if (enabled) {
                // Keep a short reminder for category icon apply-to-items behavior.
                if (prefix.indexOf('cat-icon') !== -1) {
                    hint.hidden = false;
                    hint.textContent = hintText || 'Uploading applies this picture to all products in this category.';
                } else {
                    hint.hidden = true;
                }
            } else {
                hint.hidden = false;
                hint.textContent = hintText || 'Select an option first';
            }
        }
    }

    function syncCatalogIconDropzone(prefix, kind) {
        var isCreate = prefix.indexOf('prod-') === 0;
        var catSel = document.getElementById(isCreate ? 'prod-category' : 'edit-prod-category');
        var subSel = document.getElementById(isCreate ? 'prod-subcategory' : 'edit-prod-subcategory');
        if (kind === 'cat') {
            var catId = catSel && catSel.value ? parseInt(catSel.value, 10) : 0;
            var cat = findCategoryById(catId);
            setCatalogIconEnabled(
                prefix,
                !!cat,
                cat
                    ? 'Uploading applies this picture to all products in this category.'
                    : 'Select a category first'
            );
            setCatalogIconPreview(prefix, cat && cat.icon_url ? cat.icon_url : '');
            return;
        }
        var subId = subSel && subSel.value ? parseInt(subSel.value, 10) : 0;
        var sub = findSubcategoryById(subId);
        setCatalogIconEnabled(prefix, !!sub, 'Select a subcategory first');
        setCatalogIconPreview(prefix, sub && sub.icon_url ? sub.icon_url : '');
    }

    function syncAllCatalogIconDropzones() {
        syncCatalogIconDropzone('prod-cat-icon', 'cat');
        syncCatalogIconDropzone('prod-sub-icon', 'sub');
        syncCatalogIconDropzone('edit-cat-icon', 'cat');
        syncCatalogIconDropzone('edit-sub-icon', 'sub');
    }

    function getCategoryIdsForIconApply(categoryId) {
        var catId = parseInt(categoryId, 10) || 0;
        if (!catId) return [];
        var cat = findCategoryById(catId);
        if (!cat) return [catId];
        // Beverages umbrella: apply to every merged drink category under the same station.
        if (categoryNameMergesIntoBeveragesAdmin(cat.name)) {
            var mainId = parseInt(cat.main_category_id, 10) || 0;
            var ids = (beverageMergedCategoryIds || []).filter(function (id) {
                var c = findCategoryById(id);
                return c && (parseInt(c.main_category_id, 10) || 0) === mainId;
            });
            return ids.length ? ids : [catId];
        }
        return [catId];
    }

    function getItemsForCategoryIconApply(categoryId) {
        var ids = getCategoryIdsForIconApply(categoryId);
        var idMap = {};
        ids.forEach(function (id) { idMap[id] = true; });
        return (allItems || []).filter(function (item) {
            return !!idMap[parseInt(item && item.category_id, 10) || 0];
        });
    }

    function itemHasUploadedPicture(item) {
        var url = item && item.image_url ? String(item.image_url).trim() : '';
        return !!url;
    }

    function confirmCategoryIconReplaceIfNeeded(categoryId) {
        var items = getItemsForCategoryIconApply(categoryId);
        var withPics = items.filter(itemHasUploadedPicture);
        if (!withPics.length) return true;
        var cat = findCategoryById(categoryId);
        var label = cat
            ? (categoryNameMergesIntoBeveragesAdmin(cat.name) ? 'Beverages' : (cat.name || 'this category'))
            : 'this category';
        var msg =
            withPics.length === 1
                ? '1 item under "' + label + '" already has a picture.\n\nUploading this category icon will replace that item\'s picture. Continue?'
                : withPics.length + ' items under "' + label + '" already have pictures.\n\nUploading this category icon will replace all of them. Continue?';
        return confirm(msg);
    }

    function applyCategoryIconToLocalItems(categoryId, imageUrl) {
        var ids = getCategoryIdsForIconApply(categoryId);
        var idMap = {};
        ids.forEach(function (id) { idMap[id] = true; });
        var nextUrl = imageUrl || '';
        (allItems || []).forEach(function (item) {
            if (!idMap[parseInt(item && item.category_id, 10) || 0]) return;
            item.image_url = nextUrl;
        });
        // Keep open edit modal product preview in sync when that item was affected.
        if (editingId) {
            var editing = (allItems || []).find(function (it) {
                return String(it.id) === String(editingId);
            });
            if (editing && idMap[parseInt(editing.category_id, 10) || 0]) {
                setProductImageUrl('edit', nextUrl);
                setDropzonePreview('edit', nextUrl);
            }
        }
        // New product form: inherit updated category icon unless a custom visual was uploaded.
        syncCreateProductVisualFromCategory();
        applyChipFilters();
    }

    function saveCatalogIconUrl(kind, id, url, options) {
        options = options || {};
        var endpoint = kind === 'cat' ? 'api/categories.php' : 'api/subcategories.php';
        var payload = { id: id, icon_url: url || '' };
        if (kind === 'cat' && options.applyToItems && url) {
            payload.apply_to_items = true;
            payload.category_ids = getCategoryIdsForIconApply(id);
        }
        return apiCall('PUT', endpoint, payload).then(function (res) {
            if (!res || !res.success) throw new Error((res && res.error) || 'Failed to save icon');
            var nextUrl = res.icon_url || null;
            if (kind === 'cat') {
                var cat = findCategoryById(id);
                if (cat) cat.icon_url = nextUrl;
                if (options.applyToItems && nextUrl) {
                    applyCategoryIconToLocalItems(id, nextUrl);
                }
            } else {
                var sub = findSubcategoryById(id);
                if (sub) sub.icon_url = nextUrl;
            }
            return nextUrl;
        });
    }

    function handleCatalogIconFile(prefix, kind, file) {
        if (!file) return;
        var isCreate = prefix.indexOf('prod-') === 0;
        var catSel = document.getElementById(isCreate ? 'prod-category' : 'edit-prod-category');
        var subSel = document.getElementById(isCreate ? 'prod-subcategory' : 'edit-prod-subcategory');
        var targetId = kind === 'cat'
            ? (catSel && catSel.value ? parseInt(catSel.value, 10) : 0)
            : (subSel && subSel.value ? parseInt(subSel.value, 10) : 0);
        if (!targetId) {
            alert(kind === 'cat' ? 'Select a category first.' : 'Select a subcategory first.');
            return;
        }
        if (!PRODUCT_IMAGE_TYPES[file.type]) {
            alert('Only PNG, JPG, or WEBP images are allowed.');
            return;
        }
        if (file.size > PRODUCT_IMAGE_MAX_BYTES) {
            alert('Image must be PNG/JPG up to 5MB.');
            return;
        }

        // Category icon replaces every product picture under that category (Beverages, Meals, etc.).
        if (kind === 'cat' && !confirmCategoryIconReplaceIfNeeded(targetId)) {
            return;
        }

        var entity = kind === 'cat' ? findCategoryById(targetId) : findSubcategoryById(targetId);
        var previousUrl = entity && entity.icon_url ? entity.icon_url : '';
        var dropzone = document.getElementById(prefix + '-dropzone');
        var titleEl = document.getElementById(prefix + '-title');
        var prevTitle = titleEl ? titleEl.textContent : '';
        var localUrl = '';
        var cropTitle = kind === 'cat' ? 'Adjust category icon' : 'Adjust subcategory icon';

        cropBeforeUpload(file, {
            title: cropTitle,
            aspect: 1,
            shape: 'circle',
            outputType: 'image/jpeg',
            outputQuality: 0.92,
            maxOutputSize: 800
        }).then(function (cropped) {
            localUrl = URL.createObjectURL(cropped);
            setCatalogIconPreview(prefix, localUrl);
            if (titleEl) titleEl.textContent = 'UPLOADING…';
            if (dropzone) dropzone.style.pointerEvents = 'none';
            return uploadProductImage(cropped).then(function (res) {
                if (!(res && res.success && res.url)) {
                    throw new Error((res && res.error) || 'Upload failed');
                }
                return saveCatalogIconUrl(kind, targetId, res.url, {
                    applyToItems: kind === 'cat'
                }).then(function (savedUrl) {
                    setCatalogIconPreview(prefix, savedUrl || '');
                    if (previousUrl && previousUrl !== savedUrl) {
                        deleteProductImageFile(previousUrl);
                    }
                });
            });
        }).catch(function (err) {
            if (err && err.cancelled) return;
            console.error('Catalog icon upload failed:', err);
            alert(err && err.message ? err.message : 'Icon upload failed. Please try again.');
            setCatalogIconPreview(prefix, previousUrl || '');
        }).finally(function () {
            if (localUrl) URL.revokeObjectURL(localUrl);
            if (titleEl) titleEl.textContent = prevTitle || 'UPLOAD ICON';
            if (dropzone) dropzone.style.pointerEvents = '';
        });
    }

    function wireCatalogIconDropzone(prefix, kind) {
        var dropzone = document.getElementById(prefix + '-dropzone');
        var fileInput = document.getElementById(prefix + '-file');
        var updateBtn = document.getElementById(prefix + '-update');
        var deleteBtn = document.getElementById(prefix + '-delete');
        if (!dropzone || !fileInput) return;

        function openPicker() {
            if (dropzone.classList.contains('is-disabled')) return;
            fileInput.click();
        }

        dropzone.addEventListener('click', function (e) {
            if (e.target === fileInput) return;
            if (e.target.closest('.product-upload-action')) return;
            if (dropzone.classList.contains('is-disabled')) return;
            if (dropzone.classList.contains('has-preview')) return;
            openPicker();
        });
        dropzone.addEventListener('keydown', function (e) {
            if (dropzone.classList.contains('is-disabled') || dropzone.classList.contains('has-preview')) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openPicker();
            }
        });
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            handleCatalogIconFile(prefix, kind, file);
            fileInput.value = '';
        });
        if (updateBtn) {
            updateBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPicker();
            });
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (dropzone.classList.contains('is-disabled')) return;
                var isCreate = prefix.indexOf('prod-') === 0;
                var catSel = document.getElementById(isCreate ? 'prod-category' : 'edit-prod-category');
                var subSel = document.getElementById(isCreate ? 'prod-subcategory' : 'edit-prod-subcategory');
                var targetId = kind === 'cat'
                    ? (catSel && catSel.value ? parseInt(catSel.value, 10) : 0)
                    : (subSel && subSel.value ? parseInt(subSel.value, 10) : 0);
                var entity = kind === 'cat' ? findCategoryById(targetId) : findSubcategoryById(targetId);
                var currentUrl = entity && entity.icon_url ? entity.icon_url : '';
                if (!currentUrl) {
                    setCatalogIconPreview(prefix, '');
                    return;
                }
                if (!confirm(kind === 'cat' ? 'Remove this category icon?' : 'Remove this subcategory icon?')) return;
                saveCatalogIconUrl(kind, targetId, '').then(function () {
                    setCatalogIconPreview(prefix, '');
                    deleteProductImageFile(currentUrl);
                }).catch(function (err) {
                    alert(err && err.message ? err.message : 'Could not remove icon.');
                });
            });
        }
        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (!dropzone.classList.contains('is-disabled')) dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            if (dropzone.classList.contains('is-disabled')) return;
            var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            handleCatalogIconFile(prefix, kind, file);
        });
    }

    wireCatalogIconDropzone('prod-cat-icon', 'cat');
    wireCatalogIconDropzone('prod-sub-icon', 'sub');
    wireCatalogIconDropzone('edit-cat-icon', 'cat');
    wireCatalogIconDropzone('edit-sub-icon', 'sub');

    // ── Customizable ingredients tags (ordering_dashboard.html only) ──
    var customizableTagsWrap = null;
    var customizableTagsList = null;
    var customizableIngredientsInput = null;

    function initCustomizableIngredientsTags() {
        if (!createBackdrop) return;
        customizableTagsWrap = createBackdrop.querySelector('#prod-customizable-tags');
        customizableTagsList = createBackdrop.querySelector('#prod-customizable-tags-list');
        customizableIngredientsInput = createBackdrop.querySelector('#prod-customizable-ingredients-input');
        if (!customizableTagsWrap || !customizableTagsList || !customizableIngredientsInput) return;

        function addIngredientToken(raw) {
            if (!raw) return;
            var parts = String(raw).split(',');
            parts.forEach(function (p) {
                var v = String(p || '').trim();
                if (!v) return;
                var pillExists = customizableTagsList.querySelector('.tag-pill[data-ingredient=\"' + v.replace(/\"/g, '') + '\"]');
                if (pillExists) return;

                var pill = document.createElement('div');
                pill.className = 'tag-pill';
                pill.setAttribute('data-ingredient', v);
                pill.title = 'Delete';

                var label = document.createElement('span');
                label.className = 'tag-pill__label';
                label.textContent = v;

                var remove = document.createElement('span');
                remove.className = 'tag-pill__remove';
                remove.textContent = 'Delete';
                remove.addEventListener('click', function (e) {
                    e.stopPropagation();
                    pill.remove();
                });

                pill.appendChild(label);
                pill.appendChild(remove);
                customizableTagsList.appendChild(pill);
            });
        }

        customizableIngredientsInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var raw = customizableIngredientsInput.value;
            customizableIngredientsInput.value = '';
            addIngredientToken(raw);
        });
    }

    initCustomizableIngredientsTags();

    // ── Main-category variants (Create product — beside station) ─────────────
    var createVariantsPanel = createBackdrop ? createBackdrop.querySelector('#prod-main-variants-panel') : null;
    var createEnableVariantsEl = createBackdrop ? createBackdrop.querySelector('#prod-enable-variants') : null;
    var createVariantsFieldsEl = createBackdrop ? createBackdrop.querySelector('#prod-variants-fields') : null;
    var createVariantsListEl = createBackdrop ? createBackdrop.querySelector('#prod-variants-list') : null;
    var createVariantTemplateEl = createBackdrop ? createBackdrop.querySelector('#prod-variant-template') : null;
    var createVariantAddBtn = createBackdrop ? createBackdrop.querySelector('#prod-variant-add') : null;
    var createVariantsNeedMainEl = createBackdrop ? createBackdrop.querySelector('#prod-variants-need-main') : null;
    var createVariantsDirty = false;

    function formatVariantDisplayName(data) {
        if (!data) return '';
        var size = String(data.size || data.name || '').trim();
        var label = String(data.label || '').trim();
        if (size && label && size !== label) return size + ' (' + label + ')';
        return size || label || '';
    }

    function selectedCreateMainCategoryId() {
        var sel = createBackdrop ? createBackdrop.querySelector('#prod-main-category') : null;
        return sel && sel.value ? sel.value : '';
    }

    function setCreateVariantsNeedMainWarning(show) {
        if (createVariantsNeedMainEl) createVariantsNeedMainEl.hidden = !show;
    }

    function resetCreateVariantRows() {
        if (!createVariantsListEl) return;
        createVariantsListEl.innerHTML = '';
    }

    function addCreateVariantRow(data) {
        if (!createVariantTemplateEl || !createVariantsListEl) return;
        var frag = createVariantTemplateEl.content.cloneNode(true);
        var row = frag.firstElementChild;
        if (!row) return;
        var sizeInp = row.querySelector('.variant-input--size');
        var priceInp = row.querySelector('.variant-input--price');
        var costInp = row.querySelector('.variant-input--cost');
        if (data) {
            if (sizeInp) sizeInp.value = formatVariantDisplayName(data) || String(data.name || data.size || '').trim();
            if (priceInp && data.price != null && data.price !== '') priceInp.value = data.price;
            if (costInp && data.cost != null && data.cost !== '') costInp.value = data.cost;
        }
        createVariantsListEl.appendChild(frag);
    }

    function collectCreateVariants() {
        var variants = [];
        if (!createVariantsListEl) return variants;
        createVariantsListEl.querySelectorAll('.variant-input-row').forEach(function (row) {
            var size = (row.querySelector('.variant-input--size') && row.querySelector('.variant-input--size').value || '').trim();
            var priceRaw = (row.querySelector('.variant-input--price') && row.querySelector('.variant-input--price').value || '').trim();
            var costRaw = (row.querySelector('.variant-input--cost') && row.querySelector('.variant-input--cost').value || '').trim();
            if (!size) return;
            var entry = { name: size, price: null, cost: null };
            if (priceRaw !== '') {
                var p = parseFloat(priceRaw);
                if (Number.isFinite(p) && p >= 0) entry.price = p;
            }
            if (costRaw !== '') {
                var c = parseFloat(costRaw);
                if (Number.isFinite(c) && c >= 0) entry.cost = c;
            }
            variants.push(entry);
        });
        return variants;
    }

    var DEFAULT_COLD_VARIANT_NAME = 'Iced (16oz)';
    var DEFAULT_HOT_VARIANT_NAME = 'Hot (12oz)';

    function ensureDefaultCreateVariantRows() {
        if (!createVariantsListEl) return;
        if (createVariantsListEl.querySelector('.variant-input-row')) return;
        addCreateVariantRow({ name: DEFAULT_COLD_VARIANT_NAME });
        addCreateVariantRow({ name: DEFAULT_HOT_VARIANT_NAME });
    }

    function syncCreateVariantsUI() {
        var enabled = !!(createEnableVariantsEl && createEnableVariantsEl.checked);
        var inCreate = typeof activeCatalogMode === 'undefined' || activeCatalogMode !== 'manage';
        if (createVariantsFieldsEl) createVariantsFieldsEl.hidden = !(inCreate && enabled);
        var costWrap = document.getElementById('prod-cost-price-wrap');
        var baseWrap = document.getElementById('prod-base-price-wrap');
        // Only swap prices when create mode is active; manage mode hides them via catalog role.
        if (inCreate) {
            if (costWrap) costWrap.hidden = enabled;
            if (baseWrap) baseWrap.hidden = enabled;
        }
        var costInp = document.getElementById('prod-cost-price');
        if (costInp) {
            if (enabled) costInp.removeAttribute('required');
            else costInp.setAttribute('required', 'required');
        }
        if (enabled) ensureDefaultCreateVariantRows();
        if (!enabled) setCreateVariantsNeedMainWarning(false);
    }

    function loadCreateVariantsForSelectedMain(opts) {
        opts = opts || {};
        if (!createVariantsPanel) return;
        // Don't overwrite in-progress edits when a background menu refresh finishes
        // (common on slow domain). Only force-reset when caller asks (modal open / main cat change).
        if (!opts.force && createVariantsDirty && createBackdrop && createBackdrop.classList.contains('is-visible')) {
            return;
        }
        createVariantsPanel.hidden = false;
        setCreateVariantsNeedMainWarning(false);
        var selectedId = selectedCreateMainCategoryId();
        if (!selectedId) {
            resetCreateVariantRows();
            if (createEnableVariantsEl) createEnableVariantsEl.checked = false;
            createVariantsDirty = false;
            syncCreateVariantsUI();
            return;
        }
        var cat = findMainCategoryById(selectedId);
        var enabled = !!(cat && Number(cat.variants_enabled) === 1);
        var variants = (cat && Array.isArray(cat.variants)) ? cat.variants : [];
        if (createEnableVariantsEl) createEnableVariantsEl.checked = enabled;
        resetCreateVariantRows();
        if (enabled) {
            if (variants.length) {
                // Station stores variant *names* only — prices/costs are per product.
                variants.forEach(function (v) {
                    addCreateVariantRow({
                        name: formatVariantDisplayName(v) || String(v.name || v.size || '').trim(),
                        price: null,
                        cost: null
                    });
                });
            } else {
                ensureDefaultCreateVariantRows();
            }
        }
        createVariantsDirty = false;
        syncCreateVariantsUI();
    }

    function persistCreateMainCategoryVariants(opts) {
        opts = opts || {};
        var quiet = !!opts.quiet;
        var selectedId = parseInt(selectedCreateMainCategoryId(), 10) || 0;
        if (!selectedId) {
            if (!quiet) {
                setCreateVariantsNeedMainWarning(true);
                alert('Select a main category first.');
            }
            return Promise.resolve(null);
        }
        var enabled = !!(createEnableVariantsEl && createEnableVariantsEl.checked);
        var variants = enabled ? collectCreateVariants() : [];
        if (enabled && !variants.length) {
            if (!quiet) alert('Add at least one variant, or turn off Enable variants.');
            return Promise.reject(new Error('variants_required'));
        }
        var cat = findMainCategoryById(selectedId);
        if (!cat) {
            if (!quiet) alert('Main category not found.');
            return Promise.reject(new Error('main_category_missing'));
        }
        // Persist structure (names) on the station — never shared selling/cost prices.
        // Per-product prices are stored on the menu item Variants: description line.
        var variantsForStation = variants.map(function (v) {
            return { name: String(v.name || '').trim(), price: null, cost: null };
        }).filter(function (v) { return !!v.name; });
        return apiCall('PUT', 'api/main_categories.php', {
            id: selectedId,
            name: cat.name,
            variants_enabled: enabled ? 1 : 0,
            variants: variantsForStation
        }).then(function (res) {
            if (!res.success) throw new Error(res.error || 'Failed to save variants');
            createVariantsDirty = false;
            return loadItems().then(function () {
                if (prodMainCategorySelect) prodMainCategorySelect.value = String(selectedId);
                loadCreateVariantsForSelectedMain({ force: true });
                return res;
            });
        });
    }

    function initCreateVariantInputs() {
        if (!createVariantsPanel) return;
        if (createEnableVariantsEl) {
            createEnableVariantsEl.addEventListener('change', function () {
                if (createEnableVariantsEl.checked && !selectedCreateMainCategoryId()) {
                    createEnableVariantsEl.checked = false;
                    setCreateVariantsNeedMainWarning(true);
                    syncCreateVariantsUI();
                    return;
                }
                setCreateVariantsNeedMainWarning(false);
                createVariantsDirty = true;
                syncCreateVariantsUI();
                if (!createEnableVariantsEl.checked) resetCreateVariantRows();
            });
        }
        if (createVariantAddBtn) {
            createVariantAddBtn.addEventListener('click', function () {
                createVariantsDirty = true;
                addCreateVariantRow();
            });
        }
        if (createVariantsListEl) {
            createVariantsListEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.variant-remove');
            if (!btn) return;
            var row = btn.closest('.variant-input-row');
                if (row) row.remove();
                createVariantsDirty = true;
                if (createEnableVariantsEl && createEnableVariantsEl.checked
                    && createVariantsListEl && !createVariantsListEl.querySelector('.variant-input-row')) {
                    ensureDefaultCreateVariantRows();
                }
            });
            createVariantsListEl.addEventListener('input', function () {
                createVariantsDirty = true;
            });
        }
        syncCreateVariantsUI();
    }

    initCreateVariantInputs();

    // ── Edit modal: per-product variants ──────────────────────────────────────
    var editVariantsFieldsEl = editBackdrop ? editBackdrop.querySelector('#edit-variants-fields') : null;
    var editVariantsListEl = editBackdrop ? editBackdrop.querySelector('#edit-variants-list') : null;
    var editVariantTemplateEl = editBackdrop ? editBackdrop.querySelector('#edit-variant-template') : null;
    var editVariantAddBtn = editBackdrop ? editBackdrop.querySelector('#edit-variant-add') : null;
    var editCostPriceWrap = document.getElementById('edit-cost-price-wrap');
    var editBasePriceWrap = document.getElementById('edit-base-price-wrap');

    function resetEditVariantRows() {
        if (editVariantsListEl) editVariantsListEl.innerHTML = '';
    }

    function addEditVariantRow(data) {
        if (!editVariantTemplateEl || !editVariantsListEl) return;
        var frag = editVariantTemplateEl.content.cloneNode(true);
        var row = frag.firstElementChild;
            if (!row) return;
        var sizeInp = row.querySelector('.variant-input--size');
        var priceInp = row.querySelector('.variant-input--price');
        var costInp = row.querySelector('.variant-input--cost');
        if (data) {
            if (sizeInp) sizeInp.value = formatVariantDisplayName(data) || String(data.name || data.size || '').trim();
            if (priceInp && data.price != null && data.price !== '') priceInp.value = data.price;
            if (costInp && data.cost != null && data.cost !== '') costInp.value = data.cost;
        }
        editVariantsListEl.appendChild(frag);
    }

    function collectEditVariants() {
        var variants = [];
        if (!editVariantsListEl) return variants;
        editVariantsListEl.querySelectorAll('.variant-input-row').forEach(function (row) {
            var size = (row.querySelector('.variant-input--size') && row.querySelector('.variant-input--size').value || '').trim();
            var priceRaw = (row.querySelector('.variant-input--price') && row.querySelector('.variant-input--price').value || '').trim();
            var costRaw = (row.querySelector('.variant-input--cost') && row.querySelector('.variant-input--cost').value || '').trim();
            if (!size) return;
            var entry = { name: size, price: null, cost: null };
            if (priceRaw !== '') {
                var p = parseFloat(priceRaw);
                if (Number.isFinite(p) && p >= 0) entry.price = p;
            }
            if (costRaw !== '') {
                var c = parseFloat(costRaw);
                if (Number.isFinite(c) && c >= 0) entry.cost = c;
            }
            variants.push(entry);
        });
        return variants;
    }

    function syncEditVariantsUI(show) {
        if (editVariantsFieldsEl) editVariantsFieldsEl.hidden = !show;
        if (editCostPriceWrap) editCostPriceWrap.hidden = !!show;
        if (editBasePriceWrap) editBasePriceWrap.hidden = !!show;
        var costInp = document.getElementById('edit-cost-price');
        if (costInp) {
            if (show) costInp.removeAttribute('required');
            else costInp.setAttribute('required', 'required');
        }
    }

    function loadEditVariantsForItem(item) {
        resetEditVariantRows();
        if (!item) {
            syncEditVariantsUI(false);
            return;
        }
        var mainMeta = findMainCategoryById(item.main_category_id);
        var stationOn = !!(mainMeta && Number(mainMeta.variants_enabled) === 1 && Array.isArray(mainMeta.variants) && mainMeta.variants.length);
        var itemVariants = Array.isArray(item.variants) ? item.variants.slice() : [];
        // Fallback: parse Variants: from description if API list empty/stale.
        if (!itemVariants.length && item.description) {
            var descLine = null;
            String(item.description).split(/\r?\n/).forEach(function (ln) {
                if (!descLine && /^\s*Variants\s*:/i.test(String(ln || '').trim())) descLine = ln;
            });
            if (descLine) {
                var payload = String(descLine).replace(/^\s*Variants\s*:/i, '').trim();
                payload.split(';').forEach(function (seg) {
                    var s = String(seg || '').trim();
                    if (!s) return;
                    var eq = s.lastIndexOf('=');
                    if (eq === -1) return;
                    var left = s.substring(0, eq).trim();
                    var right = s.substring(eq + 1).trim();
                    var costSplit = right.match(/^([\d.,]+)\s*(?:\/\s*cost\s*([\d.,]+))?$/i);
                    var price = costSplit ? parseFloat(String(costSplit[1]).replace(/,/g, '')) : parseFloat(String(right).replace(/,/g, ''));
                    var cost = costSplit && costSplit[2] != null
                        ? parseFloat(String(costSplit[2]).replace(/,/g, ''))
                        : null;
                    itemVariants.push({
                        name: left,
                        price: Number.isFinite(price) ? price : item.price,
                        cost: Number.isFinite(cost) ? cost : item.cost_price
                    });
                });
            }
        }
        var show = stationOn || itemVariants.length > 0;
        if (!show) {
            syncEditVariantsUI(false);
            return;
        }

        var rows = itemVariants.length
            ? itemVariants
            : (mainMeta.variants || []).map(function (v) {
                return {
                    name: formatVariantDisplayName(v) || String(v.name || v.size || '').trim(),
                    price: item.price,
                    cost: item.cost_price
                };
            });
        if (!rows.length) {
            rows = [
                { name: DEFAULT_COLD_VARIANT_NAME, price: item.price, cost: item.cost_price },
                { name: DEFAULT_HOT_VARIANT_NAME, price: item.price, cost: item.cost_price }
            ];
        }
        rows.forEach(function (v) {
            addEditVariantRow({
                name: formatVariantDisplayName(v) || String(v.name || v.size || '').trim(),
                price: v.price != null && v.price !== '' ? v.price : item.price,
                cost: v.cost != null && v.cost !== '' ? v.cost : item.cost_price
            });
        });
        syncEditVariantsUI(true);
    }

    function initEditVariantInputs() {
        if (!editVariantsFieldsEl) return;
        if (editVariantAddBtn) {
            editVariantAddBtn.addEventListener('click', function () {
                addEditVariantRow();
            });
        }
        if (editVariantsListEl) {
            editVariantsListEl.addEventListener('click', function (e) {
                var btn = e.target.closest('.variant-remove');
                if (!btn) return;
                var row = btn.closest('.variant-input-row');
                if (row) row.remove();
                if (editVariantsListEl && !editVariantsListEl.querySelector('.variant-input-row')) {
                    addEditVariantRow({ name: DEFAULT_COLD_VARIANT_NAME });
                    addEditVariantRow({ name: DEFAULT_HOT_VARIANT_NAME });
                }
            });
        }
    }

    initEditVariantInputs();

    // ── Edit modal: removable ingredients ────────────────────────────────────
    var editCustomizableTagsList = null;
    var editCustomizableIngredientsInput = null;

    function addEditIngredientToken(raw) {
        if (!raw || !editCustomizableTagsList) return;
        var parts = String(raw).split(',');
        parts.forEach(function (p) {
            var v = String(p || '').trim();
            if (!v) return;
            var pillExists = editCustomizableTagsList.querySelector('.tag-pill[data-ingredient="' + v.replace(/"/g, '') + '"]');
            if (pillExists) return;

            var pill = document.createElement('div');
            pill.className = 'tag-pill';
            pill.setAttribute('data-ingredient', v);
            pill.title = 'Delete';

            var label = document.createElement('span');
            label.className = 'tag-pill__label';
            label.textContent = v;

            var remove = document.createElement('span');
            remove.className = 'tag-pill__remove';
            remove.textContent = 'Delete';
            remove.addEventListener('click', function (e) {
                e.stopPropagation();
                pill.remove();
            });

            pill.appendChild(label);
            pill.appendChild(remove);
            editCustomizableTagsList.appendChild(pill);
        });
    }

    function initEditCustomizableIngredientsTags() {
        if (!editBackdrop) return;
        editCustomizableTagsList = editBackdrop.querySelector('#edit-customizable-tags-list');
        editCustomizableIngredientsInput = editBackdrop.querySelector('#edit-customizable-ingredients-input');
        if (!editCustomizableIngredientsInput) return;

        editCustomizableIngredientsInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var raw = editCustomizableIngredientsInput.value;
            editCustomizableIngredientsInput.value = '';
            addEditIngredientToken(raw);
        });
    }

    initEditCustomizableIngredientsTags();

    // ── New category input (create modal) ───────────────────────────────────
    var newCategoryNameInput = null;
    var newCategoryAddBtn = null;

    if (createBackdrop) {
        newCategoryNameInput = createBackdrop.querySelector('#prod-new-category-name');
        newCategoryAddBtn = createBackdrop.querySelector('#prod-add-category-btn');
    }

    function notifyCatalogAlreadyRegistered(res, label) {
        var msg = String((res && res.message) || '').toLowerCase();
        if (msg.indexOf('already exists') === -1 && msg.indexOf('already registered') === -1) {
            return false;
        }
        alert(label + ' already registered.');
        return true;
    }

    function catalogNamesMatch(a, b) {
        return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();
    }

    function findRegisteredCategoryByName(name) {
        var n = String(name || '').trim();
        if (!n) return null;
        for (var i = 0; i < (categories || []).length; i++) {
            if (catalogNamesMatch(categories[i].name, n)) return categories[i];
        }
        return null;
    }

    function findRegisteredSubcategoryByName(name, categoryId) {
        var n = String(name || '').trim();
        var cid = parseInt(categoryId, 10) || 0;
        if (!n || !cid) return null;
        for (var i = 0; i < (subcategories || []).length; i++) {
            var s = subcategories[i];
            if (parseInt(s.category_id, 10) === cid && catalogNamesMatch(s.name, n)) return s;
        }
        return null;
    }

    function createCategoryFromModal() {
        if (!newCategoryNameInput || !newCategoryAddBtn) return;
        var catName = newCategoryNameInput.value.trim();
        if (!catName) {
            alert('Category name is required.');
            return;
        }

        var keepMainCat = prodMainCategorySelect ? prodMainCategorySelect.value : '';
        var mainCatId = parseInt(keepMainCat, 10);
        if (!mainCatId) {
            alert('Select a main category first, then add a category.');
            return;
        }

        var existingCategory = findRegisteredCategoryByName(catName);
        if (existingCategory) {
            alert('Category already registered.');
            if (prodMainCategorySelect && existingCategory.main_category_id) {
                prodMainCategorySelect.value = String(existingCategory.main_category_id);
            }
            syncProductCategoryOptions(existingCategory.id);
            newCategoryNameInput.value = '';
            if (newCategoryPanel && toggleNewCategoryBtn) {
                toggleCatalogPanel(newCategoryPanel, toggleNewCategoryBtn, false);
            }
            return;
        }

        newCategoryAddBtn.disabled = true;
        var oldText = newCategoryAddBtn.textContent;
        newCategoryAddBtn.textContent = 'ADDING…';

        apiCall('POST', 'api/categories.php', { name: catName, is_active: 1, main_category_id: mainCatId })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Failed to add category');
                notifyCatalogAlreadyRegistered(res, 'Category');
                newCategoryNameInput.value = '';
                var newId = res.id || null;
                return loadItems().then(function () {
                    if (prodMainCategorySelect && keepMainCat) {
                        prodMainCategorySelect.value = keepMainCat;
                    }
                    syncProductCategoryOptions(newId || '');
                    if (newId && prodCategorySelect && prodCategorySelect.value !== String(newId)) {
                        for (var i = 0; i < (categories || []).length; i++) {
                            if (String(categories[i].name || '').toLowerCase() === catName.toLowerCase()
                                && parseInt(categories[i].main_category_id, 10) === mainCatId) {
                                syncProductCategoryOptions(categories[i].id);
                                break;
                            }
                        }
                    }
                    if (newCategoryPanel && toggleNewCategoryBtn) {
                        toggleCatalogPanel(newCategoryPanel, toggleNewCategoryBtn, false);
                    }
                });
            })
            .catch(function (err) { alert('Error: ' + err.message); })
            .finally(function () {
                newCategoryAddBtn.disabled = false;
                newCategoryAddBtn.textContent = oldText || 'Add';
            });
    }

    if (newCategoryAddBtn && newCategoryNameInput) {
        newCategoryAddBtn.addEventListener('click', function () { createCategoryFromModal(); });
        newCategoryNameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                createCategoryFromModal();
            }
        });
    }

    // ── New main category input (create modal) ──────────────────────────────
    var newMainCategoryNameInput = createBackdrop ? createBackdrop.querySelector('#prod-new-main-category-name') : null;
    var newMainCategoryAddBtn = createBackdrop ? createBackdrop.querySelector('#prod-add-main-category-btn') : null;
    var newMainCategoryPanel = createBackdrop ? createBackdrop.querySelector('#prod-new-main-category-panel') : null;
    var toggleNewMainCategoryBtn = createBackdrop ? createBackdrop.querySelector('#prod-toggle-new-main-category') : null;
    var prodMainCategorySelect = createBackdrop ? createBackdrop.querySelector('#prod-main-category') : null;
    var editMainCategorySelect = document.getElementById('edit-prod-main-category');

    function populateMainCategorySelect(sel, selectedId) {
        if (!sel) return;
        sel.innerHTML = '<option value="">Select main category</option>';
        (mainCategories || []).forEach(function (mc) {
            var o = document.createElement('option');
            o.value = String(mc.id);
            o.textContent = mc.name;
            if (selectedId && parseInt(selectedId, 10) === parseInt(mc.id, 10)) {
                o.selected = true;
            }
            sel.appendChild(o);
        });
    }

    function fillMainCategorySelects(selectedId) {
        var prodVal = selectedId
            || (prodMainCategorySelect && prodMainCategorySelect.value)
            || null;
        var editVal = selectedId
            || (editMainCategorySelect && editMainCategorySelect.value)
            || null;
        var manageSelected = manageMainCategorySelect && manageMainCategorySelect.value
            ? manageMainCategorySelect.value
            : null;
        populateMainCategorySelect(prodMainCategorySelect, prodVal);
        populateMainCategorySelect(editMainCategorySelect, editVal);
        populateMainCategorySelect(manageMainCategorySelect, selectedId || manageSelected);
    }

    function findMainCategoryById(id) {
        var want = parseInt(id, 10) || 0;
        if (!want) return null;
        for (var i = 0; i < (mainCategories || []).length; i++) {
            if (parseInt(mainCategories[i].id, 10) === want) return mainCategories[i];
        }
        return null;
    }

    function selectedManageMainCategory() {
        return manageMainCategorySelect && manageMainCategorySelect.value ? manageMainCategorySelect.value : '';
    }

    function promptRenameMainCategory() {
        var selectedId = selectedManageMainCategory();
        if (!selectedId) {
            alert('Select a main category first.');
            return;
        }
        var cat = findMainCategoryById(selectedId);
        if (!cat) {
            alert('Main category not found.');
            return;
        }
        var next = window.prompt('Rename main category:', cat.name || '');
        if (next === null) return;
        next = String(next).trim();
        if (!next) {
            alert('Name is required.');
            return;
        }
        apiCall('PUT', 'api/main_categories.php', { id: parseInt(cat.id, 10), name: next })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Rename failed');
                return loadItems();
            })
            .then(function () {
                if (manageMainCategorySelect) manageMainCategorySelect.value = String(selectedId);
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    }

    function promptDeleteMainCategory() {
        var selectedId = selectedManageMainCategory();
        if (!selectedId) {
            alert('Select a main category first.');
            return;
        }
        var cat = findMainCategoryById(selectedId);
        if (!cat) {
            alert('Main category not found.');
            return;
        }
        if (!window.confirm(
            'Warning: Deleting main category "' + cat.name + '" will also delete all categories, subcategories, and products under it. Continue?'
        )) {
            return;
        }
        apiCall('DELETE', 'api/main_categories.php', { id: parseInt(cat.id, 10) })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Delete failed');
                if (manageMainCategorySelect) manageMainCategorySelect.value = '';
                return loadItems();
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    }

    function createMainCategoryFromModal() {
        if (!newMainCategoryNameInput || !newMainCategoryAddBtn) return;
        var name = newMainCategoryNameInput.value.trim();
        if (!name) {
            alert('Main category name is required.');
            return;
        }

        newMainCategoryAddBtn.disabled = true;
        var oldText = newMainCategoryAddBtn.textContent;
        newMainCategoryAddBtn.textContent = 'ADDING…';

        apiCall('POST', 'api/main_categories.php', { name: name })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Failed to add main category');
                notifyCatalogAlreadyRegistered(res, 'Main category');
                newMainCategoryNameInput.value = '';
                toggleCatalogPanel(newMainCategoryPanel, toggleNewMainCategoryBtn, false);
                return loadItems();
            })
            .then(function () {
                if (prodMainCategorySelect && name) {
                    for (var i = 0; i < mainCategories.length; i++) {
                        if (String(mainCategories[i].name || '').toLowerCase() === name.toLowerCase()) {
                            prodMainCategorySelect.value = String(mainCategories[i].id);
                            break;
                        }
                    }
                }
                syncProductCategoryOptions('');
                loadCreateVariantsForSelectedMain({ force: true });
            })
            .catch(function (err) { alert('Error: ' + err.message); })
            .finally(function () {
                newMainCategoryAddBtn.disabled = false;
                newMainCategoryAddBtn.textContent = oldText || 'Add';
            });
    }

    if (newMainCategoryAddBtn && newMainCategoryNameInput) {
        newMainCategoryAddBtn.addEventListener('click', function () { createMainCategoryFromModal(); });
        newMainCategoryNameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                createMainCategoryFromModal();
            }
        });
    }
    if (toggleNewMainCategoryBtn) {
        toggleNewMainCategoryBtn.addEventListener('click', function () {
            toggleCatalogPanel(newMainCategoryPanel, toggleNewMainCategoryBtn);
        });
    }

    // ── New subcategory input (create modal) ─────────────────────────────────
    var newSubcategoryNameInput = createBackdrop ? createBackdrop.querySelector('#prod-new-subcategory-name') : null;
    var newSubcategoryAddBtn = createBackdrop ? createBackdrop.querySelector('#prod-add-subcategory-btn') : null;
    var newCategoryPanel = createBackdrop ? createBackdrop.querySelector('#prod-new-category-panel') : null;
    var newSubcategoryPanel = createBackdrop ? createBackdrop.querySelector('#prod-new-subcategory-panel') : null;
    var toggleNewCategoryBtn = createBackdrop ? createBackdrop.querySelector('#prod-toggle-new-category') : null;
    var toggleNewSubcategoryBtn = createBackdrop ? createBackdrop.querySelector('#prod-toggle-new-subcategory') : null;
    var catalogTabsWrap = createBackdrop ? createBackdrop.querySelector('#prod-catalog-tabs') : null;
    var catalogRoleCreateEls = createBackdrop ? createBackdrop.querySelectorAll('[data-catalog-role="create"]') : [];
    var catalogRoleManageEls = createBackdrop ? createBackdrop.querySelectorAll('[data-catalog-role="manage"]') : [];
    var activeCatalogMode = 'create';
    var prodSubcategorySelect = createBackdrop ? createBackdrop.querySelector('#prod-subcategory') : null;
    var prodCategorySelect = createBackdrop ? createBackdrop.querySelector('#prod-category') : null;
    var manageCategorySelect = createBackdrop ? createBackdrop.querySelector('#manage-category-select') : null;
    var manageMainCategorySelect = createBackdrop ? createBackdrop.querySelector('#manage-main-category-select') : null;
    var manageSubcategorySelect = createBackdrop ? createBackdrop.querySelector('#manage-subcategory-select') : null;
    var renameMainCategoryBtn = document.getElementById('prod-rename-main-category-btn');
    var deleteMainCategoryBtn = document.getElementById('prod-delete-main-category-btn');
    var renameCategoryBtn = document.getElementById('prod-rename-category-btn');
    var deleteCategoryBtn = document.getElementById('prod-delete-category-btn');
    var renameSubcategoryBtn = document.getElementById('prod-rename-subcategory-btn');
    var deleteSubcategoryBtn = document.getElementById('prod-delete-subcategory-btn');
    var editProdSubcategorySelect = document.getElementById('edit-prod-subcategory');

    function resolveSelectedCategoryId(sel) {
        if (!sel || !sel.value) return null;
        var id = parseInt(sel.value, 10);
        if (!id) return null;
        if (isBevMergedOptionSelected(sel)) {
            var mainId = mainCategoryIdForCategorySelect(sel);
            var pool = mainId ? categoriesForMain(mainId) : (categories || []);
            for (var i = 0; i < pool.length; i++) {
                if (categoryNameMergesIntoBeveragesAdmin(pool[i].name)) {
                    return parseInt(pool[i].id, 10);
                }
            }
        }
        return id;
    }

    function categoriesForMain(mainCatId) {
        var want = parseInt(mainCatId, 10);
        if (!want) return [];
        return (categories || []).filter(function (c) {
            return parseInt(c.main_category_id, 10) === want;
        });
    }

    function mainCategoryIdForCategorySelect(sel) {
        if (!sel) return '';
        if (sel === prodCategorySelect || sel.id === 'prod-category') {
            return prodMainCategorySelect && prodMainCategorySelect.value ? prodMainCategorySelect.value : '';
        }
        if (sel.id === 'edit-prod-category') {
            return editMainCategorySelect && editMainCategorySelect.value ? editMainCategorySelect.value : '';
        }
        if (sel === manageCategorySelect || sel.id === 'manage-category-select') {
            return manageMainCategorySelect && manageMainCategorySelect.value ? manageMainCategorySelect.value : '';
        }
        return '';
    }

    function findCategoryById(id) {
        var want = parseInt(id, 10);
        if (!want) return null;
        for (var i = 0; i < categories.length; i++) {
            if (parseInt(categories[i].id, 10) === want) return categories[i];
        }
        return null;
    }

    function isTemperatureSubcategoryName(name) {
        var n = String(name || '').trim().toLowerCase();
        return n === 'hot' || n === 'cold';
    }

    function fillSubcategorySelectForCategory(sel, categoryId, selectedId) {
        if (!sel) return;
        sel.innerHTML = '<option value="">Select Subcategory</option>';
        if (!categoryId) return;
        (subcategories || []).forEach(function (s) {
            if (parseInt(s.category_id, 10) !== parseInt(categoryId, 10)) return;
            if (isTemperatureSubcategoryName(s.name)) return;
            var o = document.createElement('option');
            o.value = String(s.id);
            o.textContent = s.name;
            if (selectedId && parseInt(selectedId, 10) === parseInt(s.id, 10)) {
                o.selected = true;
            }
            sel.appendChild(o);
        });
    }

    function findSubcategoryById(id) {
        var want = parseInt(id, 10);
        if (!want) return null;
        for (var i = 0; i < subcategories.length; i++) {
            if (parseInt(subcategories[i].id, 10) === want) return subcategories[i];
        }
        return null;
    }

    function applyServeFlagsFromSubcategory(prefix) {
        var subSel = prefix === 'prod' ? prodSubcategorySelect : editProdSubcategorySelect;
        if (!subSel || !subSel.value) return;
        var sub = findSubcategoryById(subSel.value);
        if (!sub) return;
        var n = String(sub.name || '').trim().toLowerCase();
        if (n === 'hot') setServeFlagsOnForm(prefix, { hot: true, cold: false });
        else if (n === 'cold') setServeFlagsOnForm(prefix, { hot: false, cold: true });
    }

    function syncProductSubcategoryOptions(selectedSubcategoryId) {
        var catId = resolveSelectedCategoryId(prodCategorySelect);
        fillSubcategorySelectForCategory(prodSubcategorySelect, catId, selectedSubcategoryId || null);
    }

    function syncManageSubcategoryOptions(selectedSubcategoryId) {
        var catId = resolveSelectedCategoryId(manageCategorySelect);
        fillSubcategorySelectForCategory(manageSubcategorySelect, catId, selectedSubcategoryId || null);
    }

    function syncEditSubcategoryOptions(selectedSubcategoryId) {
        var editCat = document.getElementById('edit-prod-category');
        var catId = resolveSelectedCategoryId(editCat);
        fillSubcategorySelectForCategory(editProdSubcategorySelect, catId, selectedSubcategoryId || null);
    }

    function toggleCatalogPanel(panel, toggleBtn, show) {
        if (!panel) return;
        var next = typeof show === 'boolean' ? show : panel.hidden;
        panel.hidden = !next;
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', next ? 'true' : 'false');
            var label = (toggleBtn.getAttribute('data-label') || toggleBtn.textContent || '').replace(/^[+−]\s*/, '');
            if (!toggleBtn.getAttribute('data-label')) {
                toggleBtn.setAttribute('data-label', label);
            }
            toggleBtn.textContent = (next ? '− ' : '+ ') + label;
        }
    }

    function collapseCatalogPanels() {
        toggleCatalogPanel(newCategoryPanel, toggleNewCategoryBtn, false);
        toggleCatalogPanel(newSubcategoryPanel, toggleNewSubcategoryBtn, false);
        toggleCatalogPanel(newMainCategoryPanel, toggleNewMainCategoryBtn, false);
    }

    function setCatalogMode(mode) {
        activeCatalogMode = mode === 'manage' ? 'manage' : 'create';
        if (createBackdrop) {
            createBackdrop.classList.toggle('catalog-mode-manage', activeCatalogMode === 'manage');
            createBackdrop.classList.toggle('catalog-mode-create', activeCatalogMode !== 'manage');
        }
        if (catalogTabsWrap) {
            catalogTabsWrap.querySelectorAll('.product-catalog-tab').forEach(function (btn) {
                var on = (btn.getAttribute('data-catalog-mode') || 'create') === activeCatalogMode;
                btn.classList.toggle('product-catalog-tab--active', on);
            });
        }
        Array.prototype.forEach.call(catalogRoleCreateEls || [], function (el) {
            el.hidden = activeCatalogMode !== 'create';
        });
        Array.prototype.forEach.call(catalogRoleManageEls || [], function (el) {
            el.hidden = activeCatalogMode !== 'manage';
        });
        if (typeof syncCreateVariantsUI === 'function') syncCreateVariantsUI();
        if (activeCatalogMode !== 'create') {
            if (manageCategorySelect && !manageCategorySelect.value && prodCategorySelect && prodCategorySelect.value) {
                manageCategorySelect.value = prodCategorySelect.value;
            }
            collapseCatalogPanels();
            syncManageSubcategoryOptions();
        }
    }

    function getSubcategoryParentCategoryId() {
        return resolveSelectedCategoryId(prodCategorySelect);
    }

    function createSubcategoryFromModal() {
        if (!newSubcategoryNameInput || !newSubcategoryAddBtn) return;
        var subName = newSubcategoryNameInput.value.trim();
        var parentId = getSubcategoryParentCategoryId();
        if (!subName) {
            alert('Subcategory name is required.');
            return;
        }
        if (!parentId) {
            alert('Select a category first, then add a subcategory.');
            return;
        }

        var existingSubcategory = findRegisteredSubcategoryByName(subName, parentId);
        if (existingSubcategory) {
            alert('Subcategory already registered.');
            newSubcategoryNameInput.value = '';
            syncProductSubcategoryOptions(existingSubcategory.id);
            if (prodSubcategorySelect) {
                prodSubcategorySelect.value = String(existingSubcategory.id);
            }
            return;
        }

        newSubcategoryAddBtn.disabled = true;
        var oldText = newSubcategoryAddBtn.textContent;
        newSubcategoryAddBtn.textContent = 'ADDING…';

        var keepMainCat = prodMainCategorySelect ? prodMainCategorySelect.value : '';
        var keepCategory = prodCategorySelect ? prodCategorySelect.value : '';
        var panelWasOpen = !!(newSubcategoryPanel && !newSubcategoryPanel.hidden);

        apiCall('POST', 'api/subcategories.php', { name: subName, category_id: parentId, is_active: 1 })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Failed to add subcategory');
                notifyCatalogAlreadyRegistered(res, 'Subcategory');
                newSubcategoryNameInput.value = '';
                var newId = res.id || null;
                return loadItems().then(function () {
                    if (prodMainCategorySelect && keepMainCat) {
                        prodMainCategorySelect.value = keepMainCat;
                    }
                    if (prodCategorySelect && keepCategory) {
                        prodCategorySelect.value = keepCategory;
                    }
                    syncProductSubcategoryOptions(newId);
                    if (newId && prodSubcategorySelect) {
                        prodSubcategorySelect.value = String(newId);
                    }
                    if (panelWasOpen) {
                        toggleCatalogPanel(newSubcategoryPanel, toggleNewSubcategoryBtn, true);
                    }
                    syncBevSubVisibility();
                    syncAllCatalogIconDropzones();
                });
            })
            .catch(function (err) { alert('Error: ' + err.message); })
            .finally(function () {
                newSubcategoryAddBtn.disabled = false;
                newSubcategoryAddBtn.textContent = oldText || 'Add';
            });
    }

    if (newSubcategoryAddBtn && newSubcategoryNameInput) {
        newSubcategoryAddBtn.addEventListener('click', function () { createSubcategoryFromModal(); });
        newSubcategoryNameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                createSubcategoryFromModal();
            }
        });
    }

    if (toggleNewCategoryBtn && newCategoryPanel) {
        toggleNewCategoryBtn.addEventListener('click', function () {
            toggleCatalogPanel(newCategoryPanel, toggleNewCategoryBtn, newCategoryPanel.hidden);
        });
    }

    if (toggleNewSubcategoryBtn && newSubcategoryPanel) {
        toggleNewSubcategoryBtn.addEventListener('click', function () {
            toggleCatalogPanel(newSubcategoryPanel, toggleNewSubcategoryBtn, newSubcategoryPanel.hidden);
        });
    }

    if (catalogTabsWrap) {
        catalogTabsWrap.addEventListener('click', function (e) {
            var tab = e.target.closest('.product-catalog-tab');
            if (!tab) return;
            setCatalogMode(tab.getAttribute('data-catalog-mode') || 'create');
        });
    }
    setCatalogMode('create');

    if (prodCategorySelect) {
        prodCategorySelect.addEventListener('change', function () {
            syncProductSubcategoryOptions();
            syncAllCatalogIconDropzones();
        });
    }

    if (prodSubcategorySelect) {
        prodSubcategorySelect.addEventListener('change', function () {
            applyServeFlagsFromSubcategory('prod');
            syncAllCatalogIconDropzones();
        });
    }
    if (manageCategorySelect) {
        manageCategorySelect.addEventListener('change', function () {
            syncManageSubcategoryOptions();
        });
    }

    function selectedManageCategory() {
        return manageCategorySelect && manageCategorySelect.value ? manageCategorySelect.value : '';
    }

    function selectedManageSubcategory() {
        return manageSubcategorySelect && manageSubcategorySelect.value ? manageSubcategorySelect.value : '';
    }

    function promptRenameCategory() {
        var selectedId = selectedManageCategory();
        if (!selectedId) {
            alert('Select a category first.');
            return;
        }
        var cat = findCategoryById(selectedId);
        if (!cat) {
            alert('Category not found.');
            return;
        }
        var next = window.prompt('Rename category:', cat.name || '');
        if (next === null) return;
        next = String(next).trim();
        if (!next) {
            alert('Name is required.');
            return;
        }
        apiCall('PUT', 'api/categories.php', { id: parseInt(cat.id, 10), name: next })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Rename failed');
                return loadItems();
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    }

    function promptDeleteCategory() {
        var selectedId = selectedManageCategory();
        if (!selectedId) {
            alert('Select a category first.');
            return;
        }
        var cat = findCategoryById(selectedId);
        if (!cat) {
            alert('Category not found.');
            return;
        }
        if (!window.confirm(
            'Warning: Deleting category "' + cat.name + '" will also delete all subcategories and products under it. Continue?'
        )) {
            return;
        }
        apiCall('DELETE', 'api/categories.php', { id: parseInt(cat.id, 10) })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Delete failed');
                if (manageCategorySelect) manageCategorySelect.value = '';
                syncProductSubcategoryOptions();
                syncManageSubcategoryOptions();
                return loadItems();
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    }

    function promptRenameSubcategory() {
        var selectedId = selectedManageSubcategory();
        if (!selectedId) {
            alert('Select a subcategory first.');
            return;
        }
        var sub = findSubcategoryById(selectedId);
        if (!sub) {
            alert('Subcategory not found.');
            return;
        }
        var next = window.prompt('Rename subcategory:', sub.name || '');
        if (next === null) return;
        next = String(next).trim();
        if (!next) {
            alert('Name is required.');
            return;
        }
        apiCall('PUT', 'api/subcategories.php', { id: parseInt(sub.id, 10), name: next })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Rename failed');
                return loadItems();
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    }

    function promptDeleteSubcategory() {
        var selectedId = selectedManageSubcategory();
        if (!selectedId) {
            alert('Select a subcategory first.');
            return;
        }
        var sub = findSubcategoryById(selectedId);
        if (!sub) {
            alert('Subcategory not found.');
            return;
        }
        if (!window.confirm(
            'Warning: Deleting subcategory "' + sub.name + '" will also delete all products under it. Continue?'
        )) {
            return;
        }
        apiCall('DELETE', 'api/subcategories.php', { id: parseInt(sub.id, 10) })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Delete failed');
                if (manageSubcategorySelect) manageSubcategorySelect.value = '';
                return loadItems();
            })
            .catch(function (err) { alert('Error: ' + err.message); });
    }

    if (renameMainCategoryBtn) {
        renameMainCategoryBtn.addEventListener('click', function () { promptRenameMainCategory(); });
    }
    if (deleteMainCategoryBtn) {
        deleteMainCategoryBtn.addEventListener('click', function () { promptDeleteMainCategory(); });
    }
    if (renameCategoryBtn) {
        renameCategoryBtn.addEventListener('click', function () { promptRenameCategory(); });
    }
    if (deleteCategoryBtn) {
        deleteCategoryBtn.addEventListener('click', function () { promptDeleteCategory(); });
    }
    if (renameSubcategoryBtn) {
        renameSubcategoryBtn.addEventListener('click', function () { promptRenameSubcategory(); });
    }
    if (deleteSubcategoryBtn) {
        deleteSubcategoryBtn.addEventListener('click', function () { promptDeleteSubcategory(); });
    }

    var editCategorySelect = document.getElementById('edit-prod-category');
    if (editCategorySelect) {
        editCategorySelect.addEventListener('change', function () {
            syncEditSubcategoryOptions();
            syncBevSubVisibility();
            syncAllCatalogIconDropzones();
        });
    }
    if (editProdSubcategorySelect) {
        editProdSubcategorySelect.addEventListener('change', function () {
            applyServeFlagsFromSubcategory('edit');
            syncAllCatalogIconDropzones();
        });
    }

    // ── Modal helpers ────────────────────────────────────────────────────────
    function openModal(backdrop) {
        backdrop.classList.add('is-visible');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        // Clear tokens when opening the create modal.
        if (backdrop === createBackdrop && customizableTagsList && customizableIngredientsInput) {
            customizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) { p.remove(); });
            customizableIngredientsInput.value = '';
        }

        if (backdrop === createBackdrop && newCategoryNameInput) {
            newCategoryNameInput.value = '';
        }

        if (backdrop === createBackdrop && newSubcategoryNameInput) {
            newSubcategoryNameInput.value = '';
        }
        if (backdrop === createBackdrop && newMainCategoryNameInput) {
            newMainCategoryNameInput.value = '';
        }
        if (backdrop === createBackdrop && prodMainCategorySelect) {
            prodMainCategorySelect.value = '';
            syncProductCategoryOptions('');
            createVariantsDirty = false;
            loadCreateVariantsForSelectedMain({ force: true });
        }
        if (backdrop === createBackdrop) {
            setCatalogMode('create');
            collapseCatalogPanels();
        }
        if (backdrop === createBackdrop && prodSubcategorySelect) {
            prodSubcategorySelect.value = '';
            syncProductSubcategoryOptions();
        }

        if (backdrop === createBackdrop) {
            setServeFlagsOnForm('prod', { hot: false, cold: true });
            var costPrice = document.getElementById('prod-cost-price');
            if (costPrice) costPrice.value = '';
            var basePrice = document.getElementById('prod-base-price');
            if (basePrice) basePrice.value = '';
            syncBevSubVisibility();
            clearProductVisual('prod');
            createImageIsCustom = false;
            syncAllCatalogIconDropzones();
        }
    }
    function closeModal(backdrop) {
        backdrop.classList.remove('is-visible');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    [createBackdrop, editBackdrop].forEach(function (bd) {
        if (!bd) return;
        bd.querySelector('.product-modal__close')
          .addEventListener('click', function () { closeModal(bd); });
        bd.addEventListener('click', function (e) {
            if (e.target === bd) closeModal(bd);
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeModal(createBackdrop);
            if (editBackdrop) closeModal(editBackdrop);
        }
    });

    // ── Populate category <select> elements (scoped to selected main category) ─
    function populateCategorySelect(sel, mainCatId, keepId) {
            if (!sel) return;
            sel.innerHTML = '<option value="">Select Category</option>';
        if (!mainCatId) return;

        var pool = categoriesForMain(mainCatId);
            var inserted = false;
        pool.forEach(function (c) {
                if (categoryNameMergesIntoBeveragesAdmin(c.name)) {
                    if (!inserted) {
                        var opt = document.createElement('option');
                        opt.value = String(c.id);
                        opt.textContent = 'Beverages';
                        opt.setAttribute('data-bev-merged', '1');
                        sel.appendChild(opt);
                        inserted = true;
                    }
                } else {
                    var o = document.createElement('option');
                    o.value = String(c.id);
                    o.textContent = c.name;
                    sel.appendChild(o);
                }
            });

        if (keepId) {
            setCategorySelectForBeverageItem(sel, parseInt(keepId, 10));
        }
    }

    function syncProductCategoryOptions(selectedCategoryId, selectedSubcategoryId) {
        var mainId = prodMainCategorySelect ? prodMainCategorySelect.value : '';
        var keep = selectedCategoryId != null && selectedCategoryId !== ''
            ? selectedCategoryId
            : (prodCategorySelect ? prodCategorySelect.value : '');
        populateCategorySelect(prodCategorySelect, mainId, keep);
        var keepSub = selectedSubcategoryId != null
            ? selectedSubcategoryId
            : (prodSubcategorySelect ? prodSubcategorySelect.value : null);
        syncProductSubcategoryOptions(keepSub);
        syncBevSubVisibility();
        syncAllCatalogIconDropzones();
        syncCreateProductVisualFromCategory();
    }

    function syncEditCategoryOptions(selectedCategoryId, selectedSubcategoryId) {
        var editCat = document.getElementById('edit-prod-category');
        var mainId = editMainCategorySelect ? editMainCategorySelect.value : '';
        var keep = selectedCategoryId != null && selectedCategoryId !== ''
            ? selectedCategoryId
            : (editCat ? editCat.value : '');
        populateCategorySelect(editCat, mainId, keep);
        var keepSub = selectedSubcategoryId != null
            ? selectedSubcategoryId
            : (editProdSubcategorySelect ? editProdSubcategorySelect.value : null);
        syncEditSubcategoryOptions(keepSub);
        syncBevSubVisibility();
        syncAllCatalogIconDropzones();
    }

    function syncManageCategoryOptions(selectedCategoryId, selectedSubcategoryId) {
        var mainId = manageMainCategorySelect ? manageMainCategorySelect.value : '';
        var keep = selectedCategoryId != null && selectedCategoryId !== ''
            ? selectedCategoryId
            : (manageCategorySelect ? manageCategorySelect.value : '');
        populateCategorySelect(manageCategorySelect, mainId, keep);
        var keepSub = selectedSubcategoryId != null
            ? selectedSubcategoryId
            : (manageSubcategorySelect ? manageSubcategorySelect.value : null);
        syncManageSubcategoryOptions(keepSub);
    }

    function fillCategorySelects(cats) {
        beverageMergedCategoryIds = [];
        (cats || []).forEach(function (c) {
            if (categoryNameMergesIntoBeveragesAdmin(c.name)) beverageMergedCategoryIds.push(c.id);
        });

        var prodCatVal = prodCategorySelect ? prodCategorySelect.value : '';
        var editCatEl = document.getElementById('edit-prod-category');
        var editCatVal = editCatEl ? editCatEl.value : '';
        var manageCatVal = manageCategorySelect ? manageCategorySelect.value : '';
        var prodSubVal = prodSubcategorySelect ? prodSubcategorySelect.value : '';
        var editSubVal = editProdSubcategorySelect ? editProdSubcategorySelect.value : '';
        var manageSubVal = manageSubcategorySelect ? manageSubcategorySelect.value : '';

        syncProductCategoryOptions(prodCatVal, prodSubVal);
        syncEditCategoryOptions(editCatVal, editSubVal);
        syncManageCategoryOptions(manageCatVal, manageSubVal);
    }

    ['prod-category', 'edit-prod-category'].forEach(function (id) {
        var s = document.getElementById(id);
        if (s) {
            s.addEventListener('change', function () {
                syncBevSubVisibility();
                syncAllCatalogIconDropzones();
                if (id === 'prod-category') syncCreateProductVisualFromCategory();
            });
        }
    });
    if (prodMainCategorySelect) {
        prodMainCategorySelect.addEventListener('change', function () {
            syncProductCategoryOptions('');
            createVariantsDirty = false;
            loadCreateVariantsForSelectedMain({ force: true });
        });
    }
    if (editMainCategorySelect) {
        editMainCategorySelect.addEventListener('change', function () {
            syncEditCategoryOptions('');
            if (editingId) {
                var cur = allItems.find(function (i) { return i.id === editingId; });
                if (cur) {
                    loadEditVariantsForItem(Object.assign({}, cur, {
                        main_category_id: parseInt(editMainCategorySelect.value, 10) || 0
                    }));
                }
            }
        });
    }
    if (manageMainCategorySelect) {
        manageMainCategorySelect.addEventListener('change', function () {
            syncManageCategoryOptions('');
        });
    }
    ['prod-subcategory', 'edit-prod-subcategory'].forEach(function (id) {
        var s = document.getElementById(id);
        if (s) {
            s.addEventListener('change', function () {
                syncAllCatalogIconDropzones();
            });
        }
    });

    function menuItemThumbHtml(item) {
        var url = item && item.image_url ? String(item.image_url).trim() : '';
        if (!url) {
            return '<div class="prod-img prod-img-placeholder" aria-hidden="true"></div>';
        }
        return '<img class="prod-img" src="' + escHtml(url) + '" alt="' + escHtml(item.name || '') + '" loading="lazy">';
    }

    function bindMenuThumbFallbacks(root) {
        if (!root) return;
        root.querySelectorAll('img.prod-img').forEach(function (img) {
            img.addEventListener('error', function () {
                var ph = document.createElement('div');
                ph.className = 'prod-img prod-img-placeholder';
                ph.setAttribute('aria-hidden', 'true');
                if (img.parentNode) img.parentNode.replaceChild(ph, img);
            });
        });
    }

    // ── Render table rows from items array ───────────────────────────────────
    function renderTable(items) {
        // Keep the header row, replace only data rows
        var existing = tableBody.querySelectorAll('.table-row');
        existing.forEach(function (r) { r.remove(); });

        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'table-row';
            empty.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#64748b;padding:24px">No menu items found.</div>';
            tableBody.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'table-row';
            row.dataset.id = item.id;

            var statusClass = item.is_available ? 'ok' : 'out';
            var statusLabel = item.is_available ? 'AVAILABLE' : 'SOLD OUT';

            row.innerHTML =
                '<div class="menu-img-cell">' + menuItemThumbHtml(item) + '</div>' +
                '<div class="name"><strong>' + escHtml(item.name) + '</strong>' +
                    '<small>' + escHtml(stripIngredientsFromDescription(item.description || '') || '--') + '</small></div>' +
                '<div class="menu-cat-cell"><span class="cat">' + escHtml(displayCategoryLabelForTable(item.category_name)) + '</span></div>' +
                '<div class="price price--cost">₱' + parseFloat(item.cost_price || 0).toFixed(2) + '</div>' +
                '<div class="price">₱' + parseFloat(item.price || 0).toFixed(2) + '</div>' +
                '<div class="status-cell">' +
                    '<span class="pill ' + statusClass + '">' + statusLabel + '</span>' +
                    '<button class="status-toggle-btn ' + (item.is_available ? 'to-out' : 'to-ok') + '" data-toggle-availability="' + item.id + '" data-next-availability="' + (item.is_available ? '0' : '1') + '">' +
                        (item.is_available ? 'Mark Sold Out' : 'Mark Available') +
                    '</button>' +
                '</div>' +
                '<div class="acts actions-cell">' +
                    '<button class="acts-btn" data-edit-id="' + item.id + '">Edit</button>' +
                    '<span class="acts-separator">|</span>' +
                    '<button class="acts-btn" data-recipe-id="' + item.id + '">Recipe</button>' +
                    '<span class="acts-separator">|</span>' +
                    '<button class="acts-btn" data-delete-id="' + item.id + '">Delete</button>' +
                '</div>';

            tableBody.appendChild(row);
        });

        bindMenuThumbFallbacks(tableBody);

        // Re-bind edit / delete handlers
        tableBody.querySelectorAll('.acts button[data-edit-id]').forEach(function (btn) {
            btn.addEventListener('click', function () { openEditModal(parseInt(btn.getAttribute('data-edit-id'), 10)); });
        });
        tableBody.querySelectorAll('.acts button[data-recipe-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-recipe-id') || '';
                if (!id) return;
                window.location.href = 'recipe_builder.html?menu_id=' + encodeURIComponent(id);
            });
        });
        tableBody.querySelectorAll('.acts button[data-delete-id]').forEach(function (btn) {
            btn.addEventListener('click', function () { deleteItem(parseInt(btn.getAttribute('data-delete-id'), 10)); });
        });
        tableBody.querySelectorAll('button[data-toggle-availability]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-toggle-availability'), 10);
                var next = btn.getAttribute('data-next-availability') === '1' ? 1 : 0;
                if (!id) return;
                btn.disabled = true;
                apiCall('PUT', 'api/menu.php', { id: id, is_available: next })
                    .then(function (res) {
                        if (!res.success) throw new Error(res.error || 'Failed to update item status.');
                        loadItems();
                    })
                    .catch(function (err) {
                        btn.disabled = false;
                        alert('Error: ' + err.message);
                    });
            });
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function stripPosBevSectionLine(description) {
        var lines = String(description || '').split(/\r?\n/);
        var filtered = lines.filter(function (ln) {
            return !/^__pos_bev_section__:/i.test(String(ln || '').trim());
        });
        return filtered.join('\n').trim();
    }

    function parsePosBevSectionKey(description) {
        var lines = String(description || '').split(/\r?\n/);
        var valid = { coffee: 1, nonCoffee: 1, frappeCoffee: 1, frappeNonCoffee: 1, refreshers: 1 };
        for (var i = 0; i < lines.length; i++) {
            var m = String(lines[i] || '').trim().match(/^__pos_bev_section__:(.+)$/i);
            if (m) {
                var k = String(m[1] || '').trim();
                if (valid[k]) return k;
            }
        }
        return '';
    }

    function withPosBevSectionLine(description, key) {
        var d = stripPosBevSectionLine(description);
        return (d ? d + '\n' : '') + '__pos_bev_section__:' + key;
    }

    function readServeFlagsFromForm(prefix) {
        var hotEl = document.getElementById(prefix + '-serve-hot');
        var coldEl = document.getElementById(prefix + '-serve-cold');
        return {
            hot: !!(hotEl && hotEl.checked),
            cold: !!(coldEl && coldEl.checked)
        };
    }

    function setServeFlagsOnForm(prefix, flags) {
        var hotEl = document.getElementById(prefix + '-serve-hot');
        var coldEl = document.getElementById(prefix + '-serve-cold');
        if (hotEl) hotEl.checked = !!(flags && flags.hot);
        if (coldEl) coldEl.checked = !!(flags && flags.cold);
    }

    function categoryNameMergesIntoBeveragesAdmin(name) {
        var n = (name || '').trim().toLowerCase();
        if (!n) return false;
        if (n === 'beverages') return true;
        if (n === 'drinks') return true;
        if (/\brefresher/.test(n)) return true;
        if (n.indexOf('frappe') !== -1) return true;
        if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee') return true;
        if (n === 'coffee') return true;
        return false;
    }

    function isAddonsCategoryName(name) {
        var n = String(name || '').trim().toLowerCase();
        return n === 'add-ons' || n === 'addons' || n === 'add ons';
    }

    function isAddonCardItem(item) {
        if (!item) return false;
        if (item.is_addon_card) return true;
        return isAddonsCategoryName(item.category_name);
    }

    /** Label for the virtual per-station Add-ons filter (from catalog / items — not a station name). */
    function getAddonsFilterLabel() {
        var i;
        for (i = 0; i < (allItems || []).length; i++) {
            if (isAddonCardItem(allItems[i]) && allItems[i].category_name) {
                return String(allItems[i].category_name);
            }
        }
        for (i = 0; i < (categories || []).length; i++) {
            if (isAddonsCategoryName(categories[i].name)) {
                return String(categories[i].name || '').trim() || 'Add-ons';
            }
        }
        return 'Add-ons';
    }

    function stationHasAddonCards(mainId) {
        var want = parseInt(mainId, 10) || 0;
        return (allItems || []).some(function (item) {
            if (!isAddonCardItem(item)) return false;
            var mid = parseInt(item.main_category_id, 10) || 0;
            if (!mid) {
                var cat = findCategoryById(item.category_id);
                mid = cat ? (parseInt(cat.main_category_id, 10) || 0) : 0;
            }
            return mid === want;
        });
    }

    /** Table + UI: show merged beverage umbrella as "Beverages" (matches create/edit selects and POS). */
    function displayCategoryLabelForTable(categoryName) {
        if (categoryNameMergesIntoBeveragesAdmin(categoryName)) return 'Beverages';
        return String(categoryName || '').trim() || '—';
    }

    function isBevMergedOptionSelected(sel) {
        if (!sel) return false;
        var opt = sel.options[sel.selectedIndex];
        return !!(opt && opt.getAttribute('data-bev-merged') === '1');
    }

    function syncBevSubVisibility() {
        var prodBasePriceWrap = document.getElementById('prod-base-price-wrap');
        var editBasePriceWrap = document.getElementById('edit-base-price-wrap');
        var variantsOn = !!(createEnableVariantsEl && createEnableVariantsEl.checked);
        // Selling price visible unless create-form variants replace it.
        if (prodBasePriceWrap) prodBasePriceWrap.hidden = variantsOn;
        if (editBasePriceWrap) editBasePriceWrap.hidden = false;
        var prodCostWrap = document.getElementById('prod-cost-price-wrap');
        if (prodCostWrap) prodCostWrap.hidden = variantsOn;
    }

    function resolveBevSubToCategoryId(key, cats) {
        function findId(pred) {
            for (var i = 0; i < cats.length; i++) {
                var n = String(cats[i].name || '').trim().toLowerCase();
                if (pred(n)) return cats[i].id;
            }
            return null;
        }
        if (key === 'coffee') {
            return findId(function (n) { return n === 'coffee'; })
                || findId(function (n) { return n.indexOf('coffee') !== -1 && !/\bnon[\s-]*coffee\b/.test(n); });
        }
        if (key === 'nonCoffee') {
            return findId(function (n) { return /\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee'; });
        }
        if (key === 'frappeCoffee') {
            return findId(function (n) {
                return n.indexOf('frappe') !== -1 && !/\bnon[\s-]*coffee\b/.test(n);
            }) || findId(function (n) { return n.indexOf('frappe') !== -1; });
        }
        if (key === 'frappeNonCoffee') {
            return findId(function (n) {
                return n.indexOf('frappe') !== -1 && /\bnon[\s-]*coffee\b/.test(n);
            }) || findId(function (n) { return n.indexOf('frappe') !== -1; });
        }
        if (key === 'refreshers') {
            return findId(function (n) { return /\brefresher/.test(n); });
        }
        return null;
    }

    function inferBevKeyFromCategoryName(name) {
        var n = String(name || '').toLowerCase();
        if (n === 'beverages') return '';
        if (/\brefresher/.test(n)) return 'refreshers';
        if (n.indexOf('frappe') !== -1) {
            if (/\bnon[\s-]*coffee\b/.test(n)) return 'frappeNonCoffee';
            return 'frappeCoffee';
        }
        if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee') return 'nonCoffee';
        if (n.indexOf('coffee') !== -1) return 'coffee';
        return 'coffee';
    }

    function setCategorySelectForBeverageItem(sel, categoryId) {
        if (!sel) return false;
        var mergedOpt = sel.querySelector('option[data-bev-merged="1"]');
        if (mergedOpt && beverageMergedCategoryIds.indexOf(categoryId) !== -1) {
            mergedOpt.selected = true;
            return true;
        }
        sel.value = String(categoryId);
        return false;
    }

    function normCatName(n) {
        return String(n || '').trim().toLowerCase();
    }

    function getItemMainCategoryKey(item) {
        var mid = parseInt(item && item.main_category_id, 10);
        if (!mid) {
            var cat = findCategoryById(item && item.category_id);
            mid = cat ? parseInt(cat.main_category_id, 10) : 0;
        }
        return mid ? ('main-' + mid) : '';
    }

    function getItemCategoryChipKey(item) {
        // Addon cards share one global catalog category but belong to a station via
        // menu_items.main_category_id — use a per-station filter key so they appear
        // under the correct station in Menu Management filters.
        if (isAddonCardItem(item)) {
            var addonMainKey = getItemMainCategoryKey(item);
            var addonMainId = addonMainKey ? String(addonMainKey).replace('main-', '') : '0';
            return 'addon-main-' + (addonMainId || '0');
        }
        var catId = parseInt(item && item.category_id, 10);
        if (beverageMergedCategoryIds.indexOf(catId) !== -1) {
            var mainKey = getItemMainCategoryKey(item);
            var mainId = mainKey ? String(mainKey).replace('main-', '') : '';
            return mainId ? ('bev-main-' + mainId) : 'bev';
        }
        return 'cat-' + catId;
    }

    function getMenuFilterGroups() {
        var groups = [];
        var claimedCatIds = {};
        var addonsLabel = getAddonsFilterLabel();

        function buildCategoryOpts(pool, mainId) {
            var catsOpts = [];
            var insertedBev = false;
            (pool || []).forEach(function (cat) {
                var catId = parseInt(cat.id, 10);
                if (!catId) return;
                claimedCatIds[catId] = true;
                // Global Add-ons category is station-scoped via items; skip the lone
                // catalog row so it does not land under the wrong station / Other.
                if (isAddonsCategoryName(cat.name)) return;
                if (categoryNameMergesIntoBeveragesAdmin(cat.name)) {
                    if (!insertedBev) {
                        catsOpts.push({
                            key: mainId ? ('bev-main-' + mainId) : 'bev',
                            label: 'Beverages'
                        });
                        insertedBev = true;
                    }
                    return;
                }
                catsOpts.push({ key: 'cat-' + catId, label: cat.name || 'Category' });
            });
            if (stationHasAddonCards(mainId)) {
                catsOpts.push({
                    key: 'addon-main-' + (mainId || 0),
                    label: addonsLabel
                });
            }
            return catsOpts;
        }

        (mainCategories || []).forEach(function (mc) {
            var mainId = parseInt(mc.id, 10);
            if (!mainId) return;
            groups.push({
                mainKey: 'main-' + mainId,
                mainId: mainId,
                label: mc.name || 'Station',
                categories: buildCategoryOpts(categoriesForMain(mainId), mainId)
            });
        });

        var orphans = (categories || []).filter(function (cat) {
            var catId = parseInt(cat.id, 10);
            if (!catId || claimedCatIds[catId]) return false;
            // Claimed as skipped so it is not re-listed under Other.
            if (isAddonsCategoryName(cat.name)) {
                claimedCatIds[catId] = true;
                return false;
            }
            return true;
        });
        // Empty orphan category pool still gets a virtual Add-ons checkbox when
        // stationHasAddonCards(0) is true (handled inside buildCategoryOpts).
        var orphanCats = buildCategoryOpts(orphans, 0);
        if (orphanCats.length) {
            groups.push({
                mainKey: 'main-0',
                mainId: 0,
                label: 'Other',
                categories: orphanCats
            });
        }
        return groups;
    }

    function getMenuCategoryFilterOptions() {
        var opts = [];
        getMenuFilterGroups().forEach(function (group) {
            (group.categories || []).forEach(function (opt) { opts.push(opt); });
        });
        return opts;
    }

    function getEnabledCategoryKeys() {
        return Object.keys(menuCategoryFilters).filter(function (key) {
            if (!menuCategoryFilters[key]) return false;
            // Category counts only when its main station is enabled (if known).
            var groups = getMenuFilterGroups();
            for (var i = 0; i < groups.length; i++) {
                var group = groups[i];
                var belongs = (group.categories || []).some(function (opt) { return opt.key === key; });
                if (!belongs) continue;
                if (menuMainCategoryFilters[group.mainKey] === false) return false;
                return true;
            }
            return true;
        });
    }

    function getEnabledMainKeys() {
        return Object.keys(menuMainCategoryFilters).filter(function (key) {
            return !!menuMainCategoryFilters[key];
        });
    }

    function ensureMenuCategoryFilterState() {
        var groups = getMenuFilterGroups();
        var nextMain = {};
        var hadMain = Object.keys(menuMainCategoryFilters).length > 0;
        groups.forEach(function (group) {
            if (hadMain && Object.prototype.hasOwnProperty.call(menuMainCategoryFilters, group.mainKey)) {
                nextMain[group.mainKey] = !!menuMainCategoryFilters[group.mainKey];
            } else {
                nextMain[group.mainKey] = true;
            }
        });
        menuMainCategoryFilters = nextMain;

        var opts = getMenuCategoryFilterOptions();
        var next = {};
        var hadAny = Object.keys(menuCategoryFilters).length > 0;
        opts.forEach(function (opt) {
            if (hadAny && Object.prototype.hasOwnProperty.call(menuCategoryFilters, opt.key)) {
                next[opt.key] = !!menuCategoryFilters[opt.key];
            } else {
                next[opt.key] = true;
            }
        });
        menuCategoryFilters = next;
        return groups;
    }

    function getSubcategoriesForCategoryKey(selectedKey) {
        var key = String(selectedKey || '');
        if (key.indexOf('addon-main-') === 0) return [];
        if (key === 'bev' || key.indexOf('bev-main-') === 0) {
            var mainId = key.indexOf('bev-main-') === 0
                ? parseInt(key.replace('bev-main-', ''), 10)
                : 0;
            return subcategories.filter(function (s) {
                if (isTemperatureSubcategoryName(s.name)) return false;
                var catId = parseInt(s.category_id, 10);
                if (beverageMergedCategoryIds.indexOf(catId) === -1) return false;
                if (!mainId) return true;
                var cat = findCategoryById(catId);
                return cat && parseInt(cat.main_category_id, 10) === mainId;
            });
        }
        var catId = parseInt(String(selectedKey).replace('cat-', ''), 10);
        if (!catId) return [];
        return subcategories.filter(function (s) {
            if (isTemperatureSubcategoryName(s.name)) return false;
            return parseInt(s.category_id, 10) === catId;
        });
    }

    function getSubcategoriesForSelectedCategory() {
        var enabled = getEnabledCategoryKeys();
        // Subcategory chips when 1–2 categories are selected; hide if more than 2.
        if (enabled.length < 1 || enabled.length > 2) return [];
        var seen = {};
        var combined = [];
        enabled.forEach(function (key) {
            getSubcategoriesForCategoryKey(key).forEach(function (sub) {
                var sid = String(sub.id);
                if (seen[sid]) return;
                seen[sid] = true;
                combined.push(sub);
            });
        });
        return combined;
    }

    function applyChipFilters() {
        var list = allItems.slice();
        var groups = getMenuFilterGroups();
        var enabledMains = getEnabledMainKeys();
        var allMainKeys = Object.keys(menuMainCategoryFilters);
        if (allMainKeys.length && !enabledMains.length) {
            list = [];
        } else if (enabledMains.length && enabledMains.length < allMainKeys.length) {
            var mainMap = {};
            enabledMains.forEach(function (key) { mainMap[key] = true; });
            list = list.filter(function (item) {
                var mk = getItemMainCategoryKey(item);
                // Items without a main still show if any main is on; otherwise require match.
                if (!mk) return true;
                return !!mainMap[mk];
            });
        }

        var enabled = getEnabledCategoryKeys();
        var allKeys = getMenuCategoryFilterOptions().map(function (o) { return o.key; });
        if (!enabled.length) {
            list = [];
        } else if (enabled.length < allKeys.length) {
            var enabledMap = {};
            enabled.forEach(function (key) { enabledMap[key] = true; });
            list = list.filter(function (item) {
                return !!enabledMap[getItemCategoryChipKey(item)];
            });
        }
        if (selectedSubcategoryChipId !== 'all') {
            list = list.filter(function (item) {
                return String(item.subcategory_id || '') === String(selectedSubcategoryChipId);
            });
        }
        var query = String(menuSearchQuery || '').trim().toLowerCase();
        if (query) {
            list = list.filter(function (item) {
                var name = String(item && item.name || '').toLowerCase();
                var category = String(item && item.category_name || '').toLowerCase();
                var subcategory = String(item && item.subcategory_name || '').toLowerCase();
                return name.indexOf(query) !== -1 || category.indexOf(query) !== -1 || subcategory.indexOf(query) !== -1;
            });
        }
        renderTable(list);
    }

    function renderSubcategoryChips() {
        if (!subcategoryChipsRow) return;
        var subs = getSubcategoriesForSelectedCategory();
        if (!subs.length) {
            subcategoryChipsRow.hidden = true;
            subcategoryChipsRow.innerHTML = '';
            selectedSubcategoryChipId = 'all';
            return;
        }
        subcategoryChipsRow.hidden = false;
        var html = '<button class="chip' + (selectedSubcategoryChipId === 'all' ? ' chip--active' : '') + '" data-subcategory-id="all">All subcategories</button>';
        subs.sort(function (a, b) {
            return (parseInt(a.display_order, 10) || 0) - (parseInt(b.display_order, 10) || 0);
        }).forEach(function (sub) {
            var sid = String(sub.id);
            html += '<button class="chip' + (selectedSubcategoryChipId === sid ? ' chip--active' : '') + '" data-subcategory-id="' + sid + '">' + escHtml(sub.name || 'Subcategory') + '</button>';
        });
        subcategoryChipsRow.innerHTML = html;
    }

    function updateMenuFilterSummary() {
        if (!menuFilterSummary) return;
        var groups = getMenuFilterGroups();
        var opts = getMenuCategoryFilterOptions();
        var enabledMains = getEnabledMainKeys();
        var enabled = getEnabledCategoryKeys();
        var totalMains = Object.keys(menuMainCategoryFilters).length;
        if (!groups.length && !opts.length) {
            menuFilterSummary.textContent = 'All Items';
            return;
        }
        var allMainsOn = totalMains > 0 && enabledMains.length === totalMains;
        var allCatsOn = opts.length > 0 && enabled.length === opts.length;
        if ((allMainsOn || !totalMains) && (allCatsOn || !opts.length)) {
            menuFilterSummary.textContent = 'All Items';
                return;
            }
        if (!enabledMains.length || !enabled.length) {
            menuFilterSummary.textContent = 'None selected';
            return;
        }
        if (!allMainsOn && enabledMains.length <= 2) {
            var mainLabels = enabledMains.map(function (key) {
                var g = groups.find(function (x) { return x.mainKey === key; });
                return g ? g.label : '';
            }).filter(Boolean);
            if (mainLabels.length && allCatsOn) {
                menuFilterSummary.textContent = mainLabels.join(' + ');
                return;
            }
            if (mainLabels.length === 1 && enabled.length === 1) {
                var onlyCat = opts.find(function (o) { return o.key === enabled[0]; });
                menuFilterSummary.textContent = mainLabels[0] + (onlyCat ? (': ' + onlyCat.label) : '');
                return;
            }
        }
        if (enabled.length === 1) {
            var only = opts.find(function (o) { return o.key === enabled[0]; });
            menuFilterSummary.textContent = only ? only.label : '1 category';
            return;
        }
        menuFilterSummary.textContent = enabled.length + ' categories';
    }

    function syncMenuFilterPanelHeights() {
        if (!menuFilterCheckGrid) return;
        menuFilterCheckGrid.querySelectorAll('.menu-filter-group').forEach(function (group) {
            var panel = group.querySelector('.menu-filter-group__panel');
            if (!panel) return;
            if (group.classList.contains('is-collapsed')) {
                panel.style.maxHeight = '0px';
                return;
            }
            var inner = panel.querySelector('.menu-filter-group__panel-inner') || panel;
            panel.style.maxHeight = Math.max(inner.scrollHeight + 8, 48) + 'px';
        });
    }

    function renderMenuFilterCheckboxes() {
        if (!menuFilterCheckGrid) return;
        var groups = ensureMenuCategoryFilterState();
        if (!groups.length) {
            menuFilterCheckGrid.innerHTML = '<p class="menu-filter-empty">No categories yet.</p>';
            updateMenuFilterSummary();
            return;
        }
        var html = '';
        groups.forEach(function (group) {
            var mainOn = menuMainCategoryFilters[group.mainKey] !== false;
            html +=
                '<div class="menu-filter-group' + (mainOn ? '' : ' is-collapsed') + '" data-main-group="' + escHtml(group.mainKey) + '">' +
                    '<label class="menu-filter-check menu-filter-check--main">' +
                        '<input type="checkbox" data-main-key="' + escHtml(group.mainKey) + '"' + (mainOn ? ' checked' : '') + '>' +
                        '<span class="menu-filter-check__box" aria-hidden="true"></span>' +
                        '<span class="menu-filter-check__text">' + escHtml(group.label) + '</span>' +
                    '</label>';
            html += '<div class="menu-filter-group__panel" aria-hidden="' + (mainOn ? 'false' : 'true') + '"><div class="menu-filter-group__panel-inner">';
            if ((group.categories || []).length) {
                html += '<div class="menu-filter-group__cats">';
                group.categories.forEach(function (opt) {
                    var checked = menuCategoryFilters[opt.key] !== false;
                    html +=
                        '<label class="menu-filter-check" title="' + escHtml(opt.label) + '">' +
                            '<input type="checkbox" data-category-key="' + escHtml(opt.key) + '"' + (checked ? ' checked' : '') + '>' +
                            '<span class="menu-filter-check__box" aria-hidden="true"></span>' +
                            '<span class="menu-filter-check__text">' + escHtml(opt.label) + '</span>' +
                        '</label>';
                });
                html += '</div>';
            } else {
                html += '<p class="menu-filter-group__empty">No categories under this station.</p>';
            }
            html += '</div></div></div>';
        });
        menuFilterCheckGrid.innerHTML = html;
        updateMenuFilterSummary();
        requestAnimationFrame(syncMenuFilterPanelHeights);
    }

    function syncMenuFilterStateFromCheckboxes() {
        if (!menuFilterCheckGrid) return;
        menuFilterCheckGrid.querySelectorAll('input[data-main-key]').forEach(function (input) {
            var key = input.getAttribute('data-main-key');
            if (!key) return;
            menuMainCategoryFilters[key] = !!input.checked;
            var group = input.closest('.menu-filter-group');
            if (group) {
                group.classList.toggle('is-collapsed', !input.checked);
                var panel = group.querySelector('.menu-filter-group__panel');
                if (panel) panel.setAttribute('aria-hidden', input.checked ? 'false' : 'true');
            }
        });
        menuFilterCheckGrid.querySelectorAll('input[data-category-key]').forEach(function (input) {
            var key = input.getAttribute('data-category-key');
            if (!key) return;
            menuCategoryFilters[key] = !!input.checked;
        });
        syncMenuFilterPanelHeights();
    }

    function resetMenuFilters() {
        Object.keys(menuMainCategoryFilters).forEach(function (key) {
            menuMainCategoryFilters[key] = true;
        });
        Object.keys(menuCategoryFilters).forEach(function (key) {
            menuCategoryFilters[key] = true;
        });
        selectedSubcategoryChipId = 'all';
        renderMenuFilterCheckboxes();
        renderSubcategoryChips();
        applyChipFilters();
    }

    function openMenuFilterPopover() {
        if (!menuFilterBackdrop) return;
        renderMenuFilterCheckboxes();
        menuFilterBackdrop.classList.add('is-visible');
        menuFilterBackdrop.setAttribute('aria-hidden', 'false');
        if (menuFilterBtn) menuFilterBtn.setAttribute('aria-expanded', 'true');
    }

    function closeMenuFilterPopover() {
        if (!menuFilterBackdrop) return;
        menuFilterBackdrop.classList.remove('is-visible');
        menuFilterBackdrop.setAttribute('aria-hidden', 'true');
        if (menuFilterBtn) menuFilterBtn.setAttribute('aria-expanded', 'false');
    }

    function renderFilterChips() {
        renderMenuFilterCheckboxes();
        renderSubcategoryChips();
        applyChipFilters();
    }

    function stripIngredientsFromDescription(description) {
        var lines = String(description || '').split(/\r?\n/);
        var filtered = lines.filter(function (ln) {
            var t = String(ln || '').trim();
            if (/^__pos_bev_section__:/i.test(t)) return false;
            return !/(Customizable|Removable)\s+ingredients\s*:/i.test(t);
        });
        return filtered.join('\n').trim();
    }

    /** Free-text description only (edit modal textarea — same idea as create). */
    function stripStructuredLinesForEditTextarea(description) {
        var lines = String(description || '').split(/\r?\n/);
        var filtered = lines.filter(function (ln) {
            var t = String(ln || '').trim();
            if (/^__pos_bev_section__:/i.test(t)) return false;
            if (/(Customizable|Removable)\s+ingredients\s*:/i.test(t)) return false;
            if (/^Variants\s*:/i.test(t)) return false;
            return true;
        });
        return filtered.join('\n').trim();
    }

    function parseRemovableIngredientsFromDescription(description) {
        var lines = String(description || '').split(/\r?\n/);
        var out = [];
        lines.forEach(function (ln) {
            var m = String(ln || '').trim().match(/^(?:Removable|Customizable)\s+ingredients\s*:\s*(.+)$/i);
            if (m) {
                m[1].split(',').forEach(function (p) {
                    var v = String(p || '').trim();
                    if (v) out.push(v);
                });
            }
        });
        return out;
    }

    // ── Load items from API ───────────────────────────────────────────────────
    function loadItems() {
        return apiCall('GET', 'api/menu.php').then(function (res) {
            if (!res.success) { console.error(res.error); return; }
            categories = res.categories || [];
            subcategories = (res.subcategories || []).filter(function (s) {
                var n = String(s && s.name || '').trim().toLowerCase();
                return n !== 'hot' && n !== 'cold';
            });
            mainCategories = res.main_categories || [];
            allItems   = res.items;
            fillCategorySelects(categories);
            fillMainCategorySelects();
            // Do not reset create-modal variant UI here — slow domain menu reloads were
            // wiping the Enable variants checkbox while the user was editing.
            renderFilterChips();

            // Update stats
            var totalEl = document.querySelector('.stat-card:first-child p');
            if (totalEl) totalEl.innerHTML = allItems.length + ' <span>items</span>';

            // Best seller + unavailable products — same source as dashboard Most Selling chart.
            apiCall('GET', 'api/dashboard.php').then(function (dash) {
                if (!dash.success) return;
                var bestNameEl = document.querySelector('#stat-best-seller .stat-best-seller__name');
                var bestCatEl = document.querySelector('#stat-best-seller .stat-best-seller__cat');
                var unavailableEl = document.querySelector('#stat-unavailable p');
                var best = dash.best_seller || (dash.top_items && dash.top_items[0]) || null;
                if (bestNameEl) {
                    bestNameEl.textContent = (best && best.name) ? best.name : 'N/A';
                }
                if (bestCatEl) {
                    var catLabel = best && best.category
                        ? displayCategoryLabelForTable(best.category)
                        : '';
                    bestCatEl.textContent = catLabel || '';
                    bestCatEl.hidden = !catLabel;
                }
                if (unavailableEl) {
                    var unavailableCount = dash.unavailable_products_count != null
                        ? dash.unavailable_products_count
                        : (dash.low_stock_count || 0);
                    unavailableEl.innerHTML = unavailableCount + ' <span class="warn">items</span>';
                }
            }).catch(function () {});
        }).catch(function (err) {
            console.error('Menu load failed:', err);
            allItems = [];
            categories = [];
            subcategories = [];
            mainCategories = [];
            var totalEl = document.querySelector('.stat-card:first-child p');
            if (totalEl) totalEl.innerHTML = '0 <span>items</span>';
            renderFilterChips();
        });
    }

    // ── Create item ───────────────────────────────────────────────────────────
    function resolveItemStationLabel(item) {
        var mid = parseInt(item && item.main_category_id, 10) || 0;
        if (!mid) {
            var cat = findCategoryById(item && item.category_id);
            mid = cat ? parseInt(cat.main_category_id, 10) || 0 : 0;
        }
        var main = findMainCategoryById(mid);
        if (main && main.name) return String(main.name);
        if (item && item.main_category_name) return String(item.main_category_name);
        return 'Unknown station';
    }

    function formatDuplicateProductLocation(item) {
        var station = resolveItemStationLabel(item);
        var category = displayCategoryLabelForTable(item && item.category_name);
        var sub = String((item && item.subcategory_name) || '').trim();
        var parts = ['Station: ' + station, 'Category: ' + category];
        if (sub) parts.push('Subcategory: ' + sub);
        return '• ' + parts.join(' · ');
    }

    function findDuplicateMenuItemsByName(name, excludeId) {
        var want = String(name || '').trim().toLowerCase();
        if (!want) return [];
        var skip = parseInt(excludeId, 10) || 0;
        return (allItems || []).filter(function (item) {
            if (!item) return false;
            if (skip && parseInt(item.id, 10) === skip) return false;
            return String(item.name || '').trim().toLowerCase() === want;
        });
    }

    function confirmDuplicateProductNameSave(name, excludeId) {
        var dupes = findDuplicateMenuItemsByName(name, excludeId);
        if (!dupes.length) return true;
        var lines = dupes.slice(0, 8).map(formatDuplicateProductLocation);
        if (dupes.length > 8) {
            lines.push('• …and ' + (dupes.length - 8) + ' more');
        }
        var msg =
            'A product named "' + name + '" already exists:\n\n' +
            lines.join('\n') +
            '\n\nSave another product with the same name anyway?';
        return window.confirm(msg);
    }

    function assertCostNotAboveSelling(costPrice, sellingPrice, costInput) {
        var cost = Number(costPrice);
        var sell = Number(sellingPrice);
        if (!Number.isFinite(cost) || !Number.isFinite(sell)) return true;
        if (cost <= sell) return true;
        alert(
            'Cost price (₱' + cost.toFixed(2) + ') cannot be higher than selling price (₱' + sell.toFixed(2) + ').\n\n' +
            'Please lower the cost or raise the selling price.'
        );
        if (costInput) costInput.focus();
        return false;
    }

    var createPrimaryBtn = createBackdrop.querySelector('.product-modal__footer .product-btn--primary');
    var createGhostBtn   = createBackdrop.querySelector('.product-modal__footer .product-btn--ghost');

    if (createGhostBtn) createGhostBtn.addEventListener('click', function () { closeModal(createBackdrop); });

    if (createPrimaryBtn) {
        createPrimaryBtn.addEventListener('click', function () {
            var name     = document.getElementById('prod-name').value.trim();
            var catSel   = document.getElementById('prod-category');
            var catId    = parseInt(catSel && catSel.value, 10);
            var mainCatId = parseInt(prodMainCategorySelect && prodMainCategorySelect.value, 10);
            var desc     = document.getElementById('prod-description').value.trim();
            var basePriceInput = document.getElementById('prod-base-price');
            var costPriceInput = document.getElementById('prod-cost-price');
            var isBeverageCreate = isBevMergedOptionSelected(catSel);
            var customizableIngredients = [];
            if (customizableTagsList) {
                customizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) {
                    var v = p.getAttribute('data-ingredient');
                    if (v) customizableIngredients.push(v);
                });
            }
            // Also include any current (not yet tokenized) input text.
            if (customizableIngredientsInput && customizableIngredientsInput.value.trim()) {
                var extraParts = customizableIngredientsInput.value.trim().split(',');
                extraParts.forEach(function (p) {
                    var v = String(p || '').trim();
                    if (v && customizableIngredients.indexOf(v) === -1) customizableIngredients.push(v);
                });
            }

            // Persist customizable-ingredients via the existing `description` column.
            if (customizableIngredients.length) {
                // Remove any previous line to avoid duplicates when the admin saves multiple times.
                var lines = (desc || '').split(/\r?\n/);
                lines = lines.filter(function (ln) {
                    return !/(Customizable|Removable)\s+ingredients\s*:/i.test(ln);
                });
                desc = lines.join('\n').trim();

                desc = (desc ? (desc + '\n') : '') + 'Removable ingredients: ' + customizableIngredients.join(', ');
            }

            var price = parseFloat(basePriceInput && basePriceInput.value || '0');
            var formVariantsEnabled = !!(createEnableVariantsEl && createEnableVariantsEl.checked);
            var formVariants = formVariantsEnabled ? collectCreateVariants() : [];

            if (!name || !catId || !mainCatId) {
                alert('Name, main category, and category are required.');
                return;
            }

            if (formVariantsEnabled) {
                if (!formVariants.length) {
                    alert('Add at least one variant, or turn off Enable variants.');
                    return;
                }
                var derivedPrice = null;
                var derivedCost = null;
                formVariants.forEach(function (v) {
                    if (v.price != null && Number.isFinite(v.price) && v.price > 0) {
                        if (derivedPrice == null || v.price < derivedPrice) derivedPrice = v.price;
                    }
                    if (v.cost != null && Number.isFinite(v.cost) && v.cost >= 0) {
                        if (derivedCost == null || v.cost < derivedCost) derivedCost = v.cost;
                    }
                });
                if (!(derivedPrice > 0)) {
                    alert('Add a price on every variant (lowest becomes the product selling price).');
                    return;
                }
                if (derivedCost == null) {
                    alert('Add a cost on every variant (lowest becomes the product cost price).');
                    return;
                }
                price = derivedPrice;
                if (basePriceInput) basePriceInput.value = String(derivedPrice);
                if (costPriceInput) costPriceInput.value = String(derivedCost);
                if (!assertCostNotAboveSelling(derivedCost, derivedPrice, null)) {
                    return;
                }
            } else {
                if (!(price > 0)) {
                    alert('Selling price is required.');
                    if (basePriceInput) basePriceInput.focus();
                    return;
                }

                var costPriceRaw = costPriceInput ? String(costPriceInput.value || '').trim() : '';
                if (!costPriceRaw) {
                    alert('Cost price is required.');
                    if (costPriceInput) costPriceInput.focus();
                return;
                }
                var costPriceCheck = parseFloat(costPriceRaw);
                if (!Number.isFinite(costPriceCheck) || costPriceCheck < 0) {
                    alert('Cost price must be 0 or greater.');
                    if (costPriceInput) costPriceInput.focus();
                    return;
                }
                if (!assertCostNotAboveSelling(costPriceCheck, price, costPriceInput)) {
                    return;
                }
            }

            var costPriceRaw2 = costPriceInput ? String(costPriceInput.value || '').trim() : '';
            var costPrice = parseFloat(costPriceRaw2);
            if (!Number.isFinite(costPrice) || costPrice < 0) {
                alert('Cost price must be 0 or greater.');
                return;
            }

            var subcategoryId = prodSubcategorySelect ? prodSubcategorySelect.value : '';
            if (isBeverageCreate && !subcategoryId) {
                alert('Select a subcategory (e.g. Coffee, Frappe, Refreshers).');
                return;
            }

            if (!confirmDuplicateProductNameSave(name)) {
                return;
            }

            desc = stripPosBevSectionLine(desc);
            // Clear any old Variants line, then store this product's prices on the item.
            desc = String(desc || '').split(/\r?\n/).filter(function (ln) {
                return !/^Variants\s*:/i.test(String(ln || '').trim());
            }).join('\n').trim();
            if (formVariantsEnabled && formVariants.length) {
                var missingPrice = formVariants.some(function (v) {
                    return !(v.price != null && Number.isFinite(v.price) && v.price > 0);
                });
                var missingCost = formVariants.some(function (v) {
                    return !(v.cost != null && Number.isFinite(v.cost) && v.cost >= 0);
                });
                if (missingPrice) {
                    alert('Enter a selling price for every variant.');
                    return;
                }
                if (missingCost) {
                    alert('Enter a cost for every variant.');
                    return;
                }
                var variantLine = 'Variants: ' + formVariants.map(function (v) {
                    var seg = String(v.name).trim() + ' = ' + Number(v.price).toFixed(2);
                    seg += ' / cost ' + Number(v.cost).toFixed(2);
                    return seg;
                }).join('; ');
                desc = desc ? (desc + '\n' + variantLine) : variantLine;
            }

            // Infer Hot/Cold from create-form variants (or saved main-category config).
            var serveHot = 0;
            var serveCold = 0;
            var variantsForFlags = formVariants.length
                ? formVariants
                : ((findMainCategoryById(mainCatId) || {}).variants || []);
            variantsForFlags.forEach(function (v) {
                var n = String(v && (v.name || v.size) || '').toLowerCase();
                if (/\b(hot|warm)\b/.test(n)) serveHot = 1;
                if (/\b(iced|cold|blended|frappe)\b/.test(n)) serveCold = 1;
            });
            setServeFlagsOnForm('prod', { hot: !!serveHot, cold: !!serveCold });

            if (formVariantsEnabled && !formVariants.length) {
                alert('Add at least one variant, or turn off Enable variants.');
                    return;
            }

            createPrimaryBtn.disabled    = true;
            createPrimaryBtn.textContent = 'SAVING…';
            var createPayload = {
                name: name,
                category_id: catId,
                main_category_id: mainCatId,
                price: price,
                cost_price: costPrice,
                description: desc,
                is_available: 1,
                image_url: createImageUrl || resolveCategoryIconForCreate(catId) || '',
                serve_hot: serveHot,
                serve_cold: serveCold,
            };
            if (subcategoryId) {
                createPayload.subcategory_id = parseInt(subcategoryId, 10);
            }

            var beforeCreate = formVariantsEnabled
                ? persistCreateMainCategoryVariants({ quiet: true })
                : Promise.resolve(null);

            beforeCreate
                .catch(function (err) {
                    if (err && (err.message === 'variants_required' || err.message === 'main_category_missing')) {
                        throw err;
                    }
                    console.warn('Variant save warning:', err);
                    return null;
                })
                .then(function () {
                    return apiCall('POST', 'api/menu.php', createPayload);
                })
                .then(function (res) {
                if (res.success) {
                    closeModal(createBackdrop);
                    document.getElementById('prod-name').value        = '';
                    document.getElementById('prod-category').value    = '';
                    if (prodMainCategorySelect) prodMainCategorySelect.value = '';
                    document.getElementById('prod-description').value = '';
                    if (costPriceInput) costPriceInput.value = '';
                    if (basePriceInput) basePriceInput.value = '';
                    setServeFlagsOnForm('prod', { hot: false, cold: false });
                    if (prodSubcategorySelect) prodSubcategorySelect.value = '';
                    if (newSubcategoryNameInput) newSubcategoryNameInput.value = '';
                    collapseCatalogPanels();
                    syncProductSubcategoryOptions();
                    syncBevSubVisibility();
                    if (customizableTagsList) {
                        customizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) { p.remove(); });
                    }
                    if (customizableIngredientsInput) customizableIngredientsInput.value = '';
                    createImageIsCustom = false;
                    clearProductVisual('prod');
                    createVariantsDirty = false;
                    loadCreateVariantsForSelectedMain({ force: true });
                    loadItems();
                } else {
                    alert('Error: ' + res.error);
                }
            }).catch(function (err) {
                if (err && (err.message === 'variants_required' || err.message === 'main_category_missing')) return;
                alert('Error: ' + (err && err.message ? err.message : err));
            }).finally(function () {
                createPrimaryBtn.disabled    = false;
                createPrimaryBtn.textContent = 'Save product';
            });
        });
    }

    // Open create modal button
    var createBtn = document.querySelector('.heading-row .cta-btn');
    if (createBtn) {
        createBtn.addEventListener('click', function (e) {
            e.preventDefault();
            openModal(createBackdrop);
        });
    }

    // ── Edit item ─────────────────────────────────────────────────────────────
    function openEditModal(id) {
        var item = allItems.find(function (i) { return i.id === id; });
        if (!item || !editBackdrop) return;
        editingId = id;

        document.getElementById('edit-prod-name').value = item.name;
        var editCostPriceInput = document.getElementById('edit-cost-price');
        if (editCostPriceInput) editCostPriceInput.value = Number(item.cost_price || 0).toFixed(2);
        var editBasePriceInput = document.getElementById('edit-base-price');
        if (editBasePriceInput) editBasePriceInput.value = Number(item.price || 0).toFixed(2);
        if (editMainCategorySelect) {
            editMainCategorySelect.value = item.main_category_id ? String(item.main_category_id) : '';
        }
        syncEditCategoryOptions(item.category_id || '', item.subcategory_id || null);
        var editCat = document.getElementById('edit-prod-category');

        var rawDesc = item.description || '';
        document.getElementById('edit-prod-description').value = stripStructuredLinesForEditTextarea(rawDesc);

        if (editCustomizableTagsList) {
            editCustomizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) { p.remove(); });
        }
        if (editCustomizableIngredientsInput) editCustomizableIngredientsInput.value = '';
        parseRemovableIngredientsFromDescription(rawDesc).forEach(function (ing) {
            addEditIngredientToken(ing);
        });

        if (item.subcategory_id && editProdSubcategorySelect) {
            editProdSubcategorySelect.value = String(item.subcategory_id);
        }
            setServeFlagsOnForm('edit', {
                hot: !!item.serve_hot,
                cold: !!item.serve_cold
            });
        if (isBevMergedOptionSelected(editCat)) {
            applyServeFlagsFromSubcategory('edit');
        }
        syncBevSubVisibility();

        editImageUrl = (item.image_url && String(item.image_url).trim()) || '';
        setDropzonePreview('edit', editImageUrl);
        syncAllCatalogIconDropzones();
        loadEditVariantsForItem(item);

        openModal(editBackdrop);
    }

    if (editBackdrop) {
        var editGhostBtn   = editBackdrop.querySelector('.product-modal__footer .product-btn--ghost');
        var editPrimaryBtn = editBackdrop.querySelector('.product-modal__footer .product-btn--primary');

        if (editGhostBtn)   editGhostBtn.addEventListener('click', function () { closeModal(editBackdrop); });

        if (editPrimaryBtn) {
            editPrimaryBtn.addEventListener('click', function () {
                if (!editingId) return;

                var editCatSel = document.getElementById('edit-prod-category');
                var descEdit = document.getElementById('edit-prod-description').value.trim();
                var resolvedBevCat = null;
                var editBasePriceInput = document.getElementById('edit-base-price');
                var editCostPriceInput = document.getElementById('edit-cost-price');
                var isBeverageEdit = isBevMergedOptionSelected(editCatSel);

                descEdit = stripPosBevSectionLine(descEdit);
                if (isBeverageEdit) {
                    resolvedBevCat = resolveSelectedCategoryId(editCatSel);
                }

                var customizableIngredients = [];
                if (editCustomizableTagsList) {
                    editCustomizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) {
                        var v = p.getAttribute('data-ingredient');
                        if (v) customizableIngredients.push(v);
                    });
                }
                if (editCustomizableIngredientsInput && editCustomizableIngredientsInput.value.trim()) {
                    editCustomizableIngredientsInput.value.trim().split(',').forEach(function (p) {
                        var v = String(p || '').trim();
                        if (v && customizableIngredients.indexOf(v) === -1) customizableIngredients.push(v);
                    });
                }

                var linesIng = (descEdit || '').split(/\r?\n/);
                linesIng = linesIng.filter(function (ln) {
                    return !/(Customizable|Removable)\s+ingredients\s*:/i.test(ln);
                });
                descEdit = linesIng.join('\n').trim();
                if (customizableIngredients.length) {
                    descEdit = (descEdit ? (descEdit + '\n') : '') + 'Removable ingredients: ' + customizableIngredients.join(', ');
                }

                var lines2 = (descEdit || '').split(/\r?\n/);
                lines2 = lines2.filter(function (ln) {
                    return !/^Variants\s*:/i.test(String(ln || '').trim());
                });
                descEdit = lines2.join('\n').trim();

                var editVariantsOn = !!(editVariantsFieldsEl && !editVariantsFieldsEl.hidden);
                var formEditVariants = editVariantsOn ? collectEditVariants() : [];
                var price = parseFloat(editBasePriceInput && editBasePriceInput.value || '0');
                var costPrice = NaN;

                if (editVariantsOn) {
                    if (!formEditVariants.length) {
                        alert('Add at least one variant, or clear variants in Manage.');
                        return;
                    }
                    var missingPrice = formEditVariants.some(function (v) {
                        return !(v.price != null && Number.isFinite(v.price) && v.price > 0);
                    });
                    var missingCost = formEditVariants.some(function (v) {
                        return !(v.cost != null && Number.isFinite(v.cost) && v.cost >= 0);
                    });
                    if (missingPrice) {
                        alert('Enter a selling price for every variant.');
                        return;
                    }
                    if (missingCost) {
                        alert('Enter a cost for every variant.');
                        return;
                    }
                    var derivedPrice = null;
                    var derivedCost = null;
                    formEditVariants.forEach(function (v) {
                        if (derivedPrice == null || v.price < derivedPrice) derivedPrice = v.price;
                        if (derivedCost == null || v.cost < derivedCost) derivedCost = v.cost;
                    });
                    price = derivedPrice;
                    costPrice = derivedCost;
                    if (editBasePriceInput) editBasePriceInput.value = String(derivedPrice);
                    if (editCostPriceInput) editCostPriceInput.value = String(derivedCost);
                    if (!assertCostNotAboveSelling(derivedCost, derivedPrice, null)) {
                        return;
                    }
                    var variantLine = 'Variants: ' + formEditVariants.map(function (v) {
                        return String(v.name).trim() + ' = ' + Number(v.price).toFixed(2)
                            + ' / cost ' + Number(v.cost).toFixed(2);
                    }).join('; ');
                    descEdit = descEdit ? (descEdit + '\n' + variantLine) : variantLine;
                } else {
                if (!(price > 0)) {
                        alert('Selling price is required.');
                        if (editBasePriceInput) editBasePriceInput.focus();
                    return;
                }

                    var costPriceRaw = editCostPriceInput ? String(editCostPriceInput.value || '').trim() : '';
                    if (!costPriceRaw) {
                        alert('Cost price is required.');
                        if (editCostPriceInput) editCostPriceInput.focus();
                        return;
                    }
                    costPrice = parseFloat(costPriceRaw);
                    if (!Number.isFinite(costPrice) || costPrice < 0) {
                        alert('Cost price must be 0 or greater.');
                        if (editCostPriceInput) editCostPriceInput.focus();
                        return;
                    }
                    if (!assertCostNotAboveSelling(costPrice, price, editCostPriceInput)) {
                        return;
                    }
                }

                var editMainCatId = parseInt(editMainCategorySelect && editMainCategorySelect.value, 10);
                var serveHot = 0;
                var serveCold = 0;
                var editMainMeta = findMainCategoryById(editMainCatId);
                var variantsForFlags = formEditVariants.length
                    ? formEditVariants
                    : ((editMainMeta && editMainMeta.variants) || []);
                variantsForFlags.forEach(function (v) {
                    var n = String(v && (v.name || v.size) || '').toLowerCase();
                    if (/\b(hot|warm)\b/.test(n)) serveHot = 1;
                    if (/\b(iced|cold|blended|frappe)\b/.test(n)) serveCold = 1;
                });
                setServeFlagsOnForm('edit', { hot: !!serveHot, cold: !!serveCold });

                var editSubcategoryId = editProdSubcategorySelect ? editProdSubcategorySelect.value : '';
                if (isBeverageEdit && !editSubcategoryId) {
                    alert('Select a subcategory (e.g. Coffee, Frappe, Refreshers).');
                    return;
                }

                var payload = {
                    id:          editingId,
                    name:        document.getElementById('edit-prod-name').value.trim(),
                    category_id: resolvedBevCat || parseInt(editCatSel.value, 10),
                    main_category_id: editMainCatId,
                    price:       price,
                    cost_price:  costPrice,
                    description: descEdit,
                    subcategory_id: editSubcategoryId ? parseInt(editSubcategoryId, 10) : '',
                    image_url:   editImageUrl || '',
                    serve_hot: serveHot,
                    serve_cold: serveCold,
                };

                if (!payload.name || !payload.category_id || !editMainCatId || !(payload.price > 0)) {
                    alert('Name, main category, category, and a valid price are required.');
                    return;
                }

                editPrimaryBtn.disabled    = true;
                editPrimaryBtn.textContent = 'SAVING…';

                function syncStationVariantNamesFromEdit() {
                    if (!editVariantsOn || !formEditVariants.length || !editMainCatId) {
                        return Promise.resolve(null);
                    }
                    var cat = findMainCategoryById(editMainCatId);
                    if (!cat) return Promise.resolve(null);
                    var nameMap = {};
                    (Array.isArray(cat.variants) ? cat.variants : []).forEach(function (v) {
                        var n = String(v && (v.name || v.size) || '').trim();
                        if (n) nameMap[n.toLowerCase()] = n;
                    });
                    formEditVariants.forEach(function (v) {
                        var n = String(v.name || '').trim();
                        if (n) nameMap[n.toLowerCase()] = n;
                    });
                    var variantsForStation = Object.keys(nameMap).map(function (k) {
                        return { name: nameMap[k], price: null, cost: null };
                    });
                    if (!variantsForStation.length) return Promise.resolve(null);
                    return apiCall('PUT', 'api/main_categories.php', {
                        id: editMainCatId,
                        name: cat.name,
                        variants_enabled: 1,
                        variants: variantsForStation
                    }).catch(function (err) {
                        console.warn('Station variant name sync failed:', err);
                        return null;
                    });
                }

                apiCall('PUT', 'api/menu.php', payload)
                    .then(function (res) {
                        if (!res.success) {
                        alert('Error: ' + res.error);
                            return null;
                        }
                        return syncStationVariantNamesFromEdit().then(function () {
                            return res;
                        });
                    })
                    .then(function (res) {
                        if (!res) return;
                        closeModal(editBackdrop);
                        loadItems();
                    })
                    .finally(function () {
                    editPrimaryBtn.disabled    = false;
                        editPrimaryBtn.textContent = 'Done';
                });
            });
        }
    }

    // ── Delete item ───────────────────────────────────────────────────────────
    function deleteItem(id) {
        var item = allItems.find(function (i) { return i.id === id; });
        if (!item) return;
        var label = item.is_addon_card
            ? ('addon "' + item.name + '" (also removes it from Register Addon)')
            : ('"' + item.name + '"');
        if (!confirm('Delete ' + label + '? This cannot be undone.')) return;

        apiCall('DELETE', 'api/menu.php', { id: id }).then(function (res) {
            if (res.success) {
                loadItems();
            } else {
                alert('Error: ' + (res.error || 'Failed to delete item.'));
            }
        }).catch(function (err) {
            alert('Error: ' + ((err && err.message) || 'Failed to delete item.'));
        });
    }

    // ── Category filter popover + subcategory chips ───────────────────────────
    if (menuFilterBtn) {
        menuFilterBtn.addEventListener('click', openMenuFilterPopover);
    }
    if (menuFilterCloseBtn) {
        menuFilterCloseBtn.addEventListener('click', closeMenuFilterPopover);
    }
    if (menuFilterDoneBtn) {
        menuFilterDoneBtn.addEventListener('click', closeMenuFilterPopover);
    }
    if (menuFilterResetBtn) {
        menuFilterResetBtn.addEventListener('click', resetMenuFilters);
    }
    if (menuFilterBackdrop) {
        menuFilterBackdrop.addEventListener('click', function (e) {
            if (e.target === menuFilterBackdrop) closeMenuFilterPopover();
        });
    }
    if (menuFilterCheckGrid) {
        menuFilterCheckGrid.addEventListener('change', function (e) {
            var mainInput = e.target && e.target.closest ? e.target.closest('input[data-main-key]') : null;
            var catInput = e.target && e.target.closest ? e.target.closest('input[data-category-key]') : null;
            if (!mainInput && !catInput) return;
            syncMenuFilterStateFromCheckboxes();
            selectedSubcategoryChipId = 'all';
            updateMenuFilterSummary();
            renderSubcategoryChips();
            applyChipFilters();
        });
    }
    if (subcategoryChipsRow) {
        subcategoryChipsRow.addEventListener('click', function (e) {
            var chip = e.target.closest('.chip[data-subcategory-id]');
            if (!chip) return;
            selectedSubcategoryChipId = chip.getAttribute('data-subcategory-id') || 'all';
            renderSubcategoryChips();
            applyChipFilters();
        });
    }
    if (menuSearchInput) {
        menuSearchInput.addEventListener('input', function () {
            menuSearchQuery = String(menuSearchInput.value || '').trim();
            applyChipFilters();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menuFilterBackdrop && menuFilterBackdrop.classList.contains('is-visible')) {
            closeMenuFilterPopover();
        }
    });

    // ── Initial load ──────────────────────────────────────────────────────────
    loadItems();
})();

// ─── System activity notification feed (admin header, near logout) ───────────
(function () {
    var PAGE_SIZE = 15;
    var actions = document.querySelector('.top-actions');
    if (!actions) return;

    // Shared stylesheet (idempotent).
    if (!document.getElementById('activity-feed-css')) {
        var css = document.createElement('link');
        css.id = 'activity-feed-css';
        css.rel = 'stylesheet';
        css.href = 'activity_feed.css?v=20261007-responsive';
        document.head.appendChild(css);
    }

    var logoutBtn = document.getElementById('btn-logout');
    var wrap = document.createElement('div');
    wrap.className = 'activity-bell-wrap';
    wrap.innerHTML =
        '<button type="button" class="activity-bell-btn" id="btn-activity-feed" title="System updates" aria-label="System updates" aria-expanded="false" aria-haspopup="true">' +
            '<img src="icons_admin/bell_icon.svg" alt="">' +
            '<span class="activity-bell-badge is-hidden" id="activity-feed-badge">0</span>' +
        '</button>' +
        '<div class="activity-feed-panel" id="activity-feed-panel" role="dialog" aria-label="System activity" hidden>' +
            '<div class="activity-feed-panel__head">' +
                '<h3>System Updates</h3>' +
                '<button type="button" class="activity-feed-panel__close" id="activity-feed-close" aria-label="Close">×</button>' +
            '</div>' +
            '<div class="activity-feed-panel__list" id="activity-feed-list">' +
                '<p class="activity-feed-empty">Loading…</p>' +
            '</div>' +
            '<div class="activity-feed-panel__foot">' +
                '<button type="button" class="activity-feed-more is-hidden" id="activity-feed-more">See more</button>' +
            '</div>' +
        '</div>';

    // Prefer replacing a leftover static bell, else insert before logout.
    var existingBell = actions.querySelector('button[title="Notifications"], button[aria-label="Notifications"]');
    if (existingBell && existingBell !== logoutBtn) {
        existingBell.replaceWith(wrap);
    } else if (logoutBtn) {
        actions.insertBefore(wrap, logoutBtn);
    } else {
        actions.appendChild(wrap);
    }

    var bellBtn = document.getElementById('btn-activity-feed');
    var badgeEl = document.getElementById('activity-feed-badge');
    var panelEl = document.getElementById('activity-feed-panel');
    var listEl = document.getElementById('activity-feed-list');
    var moreBtn = document.getElementById('activity-feed-more');
    var closeBtn = document.getElementById('activity-feed-close');

    var entries = [];
    var hasMore = false;
    var loading = false;
    var maxSeenInPanel = 0;
    var unreadCount = 0;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatWhen(iso) {
        if (!iso) return '';
        var d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(iso);
        return d.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function setBadge(count) {
        unreadCount = Math.max(0, parseInt(count, 10) || 0);
        if (!badgeEl) return;
        if (unreadCount <= 0) {
            badgeEl.textContent = '0';
            badgeEl.classList.add('is-hidden');
            return;
        }
        badgeEl.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        badgeEl.classList.remove('is-hidden');
    }

    function renderList() {
        if (!listEl) return;
        if (!entries.length) {
            listEl.innerHTML = '<p class="activity-feed-empty">No system updates yet.</p>';
        } else {
            listEl.innerHTML = entries.map(function (row) {
                var unreadClass = row.is_unread ? ' is-unread' : '';
                return (
                    '<div class="activity-feed-row' + unreadClass + '" data-id="' + esc(row.id) + '">' +
                        '<span class="activity-feed-row__text">' + esc(row.display || '') + '</span>' +
                        '<span class="activity-feed-row__meta">' + esc(formatWhen(row.created_at)) + '</span>' +
                    '</div>'
                );
            }).join('');
        }
        if (moreBtn) {
            moreBtn.classList.toggle('is-hidden', !hasMore);
            moreBtn.disabled = false;
            moreBtn.textContent = 'See more';
        }
    }

    function fetchPage(beforeId) {
        if (loading) return Promise.resolve();
        loading = true;
        if (moreBtn && beforeId) {
            moreBtn.disabled = true;
            moreBtn.textContent = 'Loading…';
        }
        var url = 'api/activity_log.php?limit=' + PAGE_SIZE;
        if (beforeId) url += '&before_id=' + encodeURIComponent(beforeId);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) {
                    throw new Error((d && d.error) || 'Failed to load activity');
                }
                var page = Array.isArray(d.entries) ? d.entries : [];
                if (!beforeId) {
                    entries = page;
                } else {
                    entries = entries.concat(page);
                }
                hasMore = !!d.has_more;
                setBadge(d.unread_count);
                page.forEach(function (row) {
                    maxSeenInPanel = Math.max(maxSeenInPanel, parseInt(row.id, 10) || 0);
                });
                renderList();
            })
            .catch(function () {
                if (!beforeId && listEl) {
                    listEl.innerHTML = '<p class="activity-feed-empty">Could not load updates.</p>';
                }
                if (moreBtn) {
                    moreBtn.disabled = false;
                    moreBtn.textContent = 'See more';
                }
            })
            .finally(function () {
                loading = false;
            });
    }

    function refreshBadgeOnly() {
        fetch('api/activity_log.php?limit=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) setBadge(d.unread_count);
            })
            .catch(function () { /* ignore */ });
    }

    function isOpen() {
        return !!(panelEl && panelEl.classList.contains('is-open'));
    }

    function markReadAndClear() {
        var seenId = maxSeenInPanel;
        entries.forEach(function (row) { row.is_unread = false; });
        renderList();
        setBadge(0);
        return fetch('api/activity_log.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_read', last_seen_id: seenId || 0 })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) setBadge(d.unread_count);
            })
            .catch(function () { /* ignore */ });
    }

    function closePanel() {
        if (!isOpen()) return;
        if (panelEl) {
            panelEl.classList.remove('is-open');
            panelEl.setAttribute('hidden', 'hidden');
        }
        if (bellBtn) {
            bellBtn.classList.remove('is-open');
            bellBtn.setAttribute('aria-expanded', 'false');
        }
        markReadAndClear();
    }

    function openPanel() {
        if (!panelEl || !bellBtn) return;
        panelEl.classList.add('is-open');
        panelEl.removeAttribute('hidden');
        bellBtn.classList.add('is-open');
        bellBtn.setAttribute('aria-expanded', 'true');
        maxSeenInPanel = 0;
        fetchPage(0);
    }

    function togglePanel() {
        if (isOpen()) closePanel();
        else openPanel();
    }

    if (bellBtn) bellBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        togglePanel();
    });
    if (closeBtn) closeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        closePanel();
    });
    if (moreBtn) moreBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!entries.length) return;
        var last = entries[entries.length - 1];
        fetchPage(last && last.id ? last.id : 0);
    });

    document.addEventListener('click', function (e) {
        if (!isOpen()) return;
        if (wrap.contains(e.target)) return;
        closePanel();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) closePanel();
    });

    // Initial badge + light polling while the page is open.
    refreshBadgeOnly();
    window.setInterval(function () {
        if (!isOpen()) refreshBadgeOnly();
    }, 45000);
})();
