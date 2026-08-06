/* ─── Shared admin dashboard script ─────────────────────────────────────────
   Runs on ordering_dashboard.html (menu CRUD) and all other admin pages.
   Assumes pages are served through XAMPP (api/ endpoints via PHP).
─────────────────────────────────────────────────────────────────────────── */

// ─── Logout ──────────────────────────────────────────────────────────────────
(function () {
    var btn = document.getElementById('btn-logout');
    if (!btn) return;
    btn.addEventListener('click', function () {
        fetch('api/auth.php', { method: 'DELETE' })
            .finally(function () { window.location.href = 'admin.html'; });
    });
})();

function switchOrtusAccount(nextPage) {
    var next = nextPage || window.location.pathname.split('/').pop() || 'admin_dashboard.html';
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
    return fetch(url, opts).then(function (r) { return r.json(); });
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
    var categoryChipsRow = document.getElementById('menu-category-chips') || document.querySelector('.chips-row');
    var subcategoryChipsRow = document.getElementById('menu-subcategory-chips');
    var selectedCategoryChipKey = 'all';
    var selectedSubcategoryChipId = 'all';

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

    // ── Variants / size inputs (ordering_dashboard.html create modal) ──
    var variantsListEl = null;
    var variantTemplateEl = null;
    var variantAddBtn = null;

    function resetVariantRows() {
        if (!variantsListEl) return;
        variantsListEl.innerHTML = '';
        addVariantRow();
    }

    function addVariantRow(data) {
        if (!variantTemplateEl || !variantsListEl) return;
        var frag = variantTemplateEl.content.cloneNode(true);
        var row = frag.firstElementChild;
        if (!row) return;

        var sizeInp = row.querySelector('.variant-input--size');
        var labelInp = row.querySelector('.variant-input--label');
        var priceInp = row.querySelector('.variant-input--price');

        if (data) {
            if (sizeInp) sizeInp.value = data.size || '';
            if (labelInp) labelInp.value = data.label || '';
            if (priceInp) priceInp.value = data.price || '';
        }

        variantsListEl.appendChild(frag);
    }

    function initVariantInputs() {
        if (!createBackdrop) return;
        variantsListEl = createBackdrop.querySelector('#prod-variants-list');
        variantTemplateEl = createBackdrop.querySelector('#prod-variant-template');
        variantAddBtn = createBackdrop.querySelector('#prod-variant-add');
        if (!variantsListEl || !variantTemplateEl || !variantAddBtn) return;

        // Add new rows
        variantAddBtn.addEventListener('click', function () { addVariantRow(); });

        // Remove rows (event delegation)
        variantsListEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.variant-remove');
            if (!btn) return;
            var row = btn.closest('.variant-input-row');
            if (!row) return;
            row.remove();
        });

        // Start with one row
        resetVariantRows();
    }

    initVariantInputs();

    // ── Edit modal: removable ingredients + variants (same UX as create) ─────
    var editCustomizableTagsList = null;
    var editCustomizableIngredientsInput = null;
    var editVariantsListEl = null;
    var editVariantTemplateEl = null;
    var editVariantAddBtn = null;

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

    function resetEditVariantRows() {
        if (!editVariantsListEl) return;
        editVariantsListEl.innerHTML = '';
        addEditVariantRow();
    }

    function addEditVariantRow(data) {
        if (!editVariantTemplateEl || !editVariantsListEl) return;
        var frag = editVariantTemplateEl.content.cloneNode(true);
        var row = frag.firstElementChild;
        if (!row) return;

        var sizeInp = row.querySelector('.variant-input--size');
        var labelInp = row.querySelector('.variant-input--label');
        var priceInp = row.querySelector('.variant-input--price');

        if (data) {
            if (sizeInp) sizeInp.value = data.size || '';
            if (labelInp) labelInp.value = data.label || '';
            if (priceInp) priceInp.value = data.price || '';
        }

        editVariantsListEl.appendChild(frag);
    }

    function initEditVariantInputs() {
        if (!editBackdrop) return;
        editVariantsListEl = editBackdrop.querySelector('#edit-variants-list');
        editVariantTemplateEl = editBackdrop.querySelector('#edit-variant-template');
        editVariantAddBtn = editBackdrop.querySelector('#edit-variant-add');
        if (!editVariantsListEl || !editVariantTemplateEl || !editVariantAddBtn) return;

        editVariantAddBtn.addEventListener('click', function () { addEditVariantRow(); });

        editVariantsListEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.variant-remove');
            if (!btn) return;
            var row = btn.closest('.variant-input-row');
            if (!row) return;
            row.remove();
        });
    }

    initEditCustomizableIngredientsTags();
    initEditVariantInputs();

    // ── New category input (create modal) ───────────────────────────────────
    var newCategoryNameInput = null;
    var newCategoryAddBtn = null;

    if (createBackdrop) {
        newCategoryNameInput = createBackdrop.querySelector('#prod-new-category-name');
        newCategoryAddBtn = createBackdrop.querySelector('#prod-add-category-btn');
    }

    function createCategoryFromModal() {
        if (!newCategoryNameInput || !newCategoryAddBtn) return;
        var catName = newCategoryNameInput.value.trim();
        if (!catName) {
            alert('Category name is required.');
            return;
        }

        newCategoryAddBtn.disabled = true;
        var oldText = newCategoryAddBtn.textContent;
        newCategoryAddBtn.textContent = 'ADDING…';

        apiCall('POST', 'api/categories.php', { name: catName, is_active: 1 })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Failed to add category');
                newCategoryNameInput.value = '';
                loadItems();
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
        populateMainCategorySelect(prodMainCategorySelect, selectedId || null);
        populateMainCategorySelect(editMainCategorySelect, selectedId || null);
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
    var subcategoryParentSelect = createBackdrop ? createBackdrop.querySelector('#prod-subcategory-parent') : null;
    var prodSubcategorySelect = createBackdrop ? createBackdrop.querySelector('#prod-subcategory') : null;
    var prodCategorySelect = createBackdrop ? createBackdrop.querySelector('#prod-category') : null;
    var manageCategorySelect = createBackdrop ? createBackdrop.querySelector('#manage-category-select') : null;
    var manageSubcategorySelect = createBackdrop ? createBackdrop.querySelector('#manage-subcategory-select') : null;
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
            for (var i = 0; i < categories.length; i++) {
                if (categoryNameMergesIntoBeveragesAdmin(categories[i].name)) {
                    return parseInt(categories[i].id, 10);
                }
            }
        }
        return id;
    }

    function populateParentCategorySelect(sel) {
        if (!sel) return;
        sel.innerHTML = '<option value="">Select parent category</option>';
        (categories || []).forEach(function (c) {
            var o = document.createElement('option');
            o.value = String(c.id);
            o.textContent = c.name;
            sel.appendChild(o);
        });
    }

    function findCategoryById(id) {
        var want = parseInt(id, 10);
        if (!want) return null;
        for (var i = 0; i < categories.length; i++) {
            if (parseInt(categories[i].id, 10) === want) return categories[i];
        }
        return null;
    }

    function fillSubcategorySelectForCategory(sel, categoryId, selectedId) {
        if (!sel) return;
        sel.innerHTML = '<option value="">Select Subcategory</option>';
        if (!categoryId) return;
        (subcategories || []).forEach(function (s) {
            if (parseInt(s.category_id, 10) !== parseInt(categoryId, 10)) return;
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
        if (activeCatalogMode !== 'create') {
            if (manageCategorySelect && !manageCategorySelect.value && prodCategorySelect && prodCategorySelect.value) {
                manageCategorySelect.value = prodCategorySelect.value;
            }
            collapseCatalogPanels();
            syncManageSubcategoryOptions();
        }
    }

    function getSubcategoryParentCategoryId() {
        if (subcategoryParentSelect && subcategoryParentSelect.value) {
            return parseInt(subcategoryParentSelect.value, 10) || null;
        }
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
            alert('Select a parent category for the new subcategory.');
            return;
        }

        newSubcategoryAddBtn.disabled = true;
        var oldText = newSubcategoryAddBtn.textContent;
        newSubcategoryAddBtn.textContent = 'ADDING…';

        apiCall('POST', 'api/subcategories.php', { name: subName, category_id: parentId, is_active: 1 })
            .then(function (res) {
                if (!res.success) throw new Error(res.error || 'Failed to add subcategory');
                newSubcategoryNameInput.value = '';
                var newId = res.id || null;
                if (prodCategorySelect && String(prodCategorySelect.value) === '') {
                    prodCategorySelect.value = String(parentId);
                    syncBevSubVisibility();
                }
                return loadItems().then(function () {
                    syncProductSubcategoryOptions(newId);
                    if (newId && prodSubcategorySelect) {
                        prodSubcategorySelect.value = String(newId);
                    }
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
            var opening = newSubcategoryPanel.hidden;
            toggleCatalogPanel(newSubcategoryPanel, toggleNewSubcategoryBtn, opening);
            if (opening) {
                populateParentCategorySelect(subcategoryParentSelect);
                var currentCat = resolveSelectedCategoryId(prodCategorySelect);
                if (currentCat && subcategoryParentSelect) {
                    subcategoryParentSelect.value = String(currentCat);
                }
            }
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
            var currentCat = resolveSelectedCategoryId(prodCategorySelect);
            if (currentCat && subcategoryParentSelect) {
                subcategoryParentSelect.value = String(currentCat);
            }
        });
    }

    if (prodSubcategorySelect) {
        prodSubcategorySelect.addEventListener('change', function () {
            applyServeFlagsFromSubcategory('prod');
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
        if (!window.confirm('Delete category "' + cat.name + '"? Only allowed when it has no menu items.')) {
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
        if (!window.confirm('Delete subcategory "' + sub.name + '"? Only allowed when it is not used by any menu items.')) {
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
        });
    }
    if (editProdSubcategorySelect) {
        editProdSubcategorySelect.addEventListener('change', function () {
            applyServeFlagsFromSubcategory('edit');
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

        if (backdrop === createBackdrop && variantsListEl) {
            resetVariantRows();
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
        }
        if (backdrop === createBackdrop) {
            setCatalogMode('create');
            collapseCatalogPanels();
        }
        if (backdrop === createBackdrop && subcategoryParentSelect) {
            subcategoryParentSelect.value = '';
        }
        if (backdrop === createBackdrop && prodSubcategorySelect) {
            prodSubcategorySelect.value = '';
            syncProductSubcategoryOptions();
        }

        if (backdrop === createBackdrop) {
            setServeFlagsOnForm('prod', { hot: false, cold: false });
            var basePrice = document.getElementById('prod-base-price');
            if (basePrice) basePrice.value = '';
            syncBevSubVisibility();
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

    // ── Populate category <select> elements ──────────────────────────────────
    function fillCategorySelects(cats) {
        beverageMergedCategoryIds = [];
        (cats || []).forEach(function (c) {
            if (categoryNameMergesIntoBeveragesAdmin(c.name)) beverageMergedCategoryIds.push(c.id);
        });

        function populateCategorySelect(sel) {
            if (!sel) return;
            sel.innerHTML = '<option value="">Select Category</option>';
            var inserted = false;
            (cats || []).forEach(function (c) {
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
        }

        populateCategorySelect(document.getElementById('prod-category'));
        populateCategorySelect(document.getElementById('edit-prod-category'));
        populateCategorySelect(manageCategorySelect);
        populateParentCategorySelect(subcategoryParentSelect);
        syncProductSubcategoryOptions();
        syncEditSubcategoryOptions();
        syncManageSubcategoryOptions();
        syncBevSubVisibility();
    }

    ['prod-category', 'edit-prod-category'].forEach(function (id) {
        var s = document.getElementById(id);
        if (s) s.addEventListener('change', syncBevSubVisibility);
    });

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
                '<div><div class="prod-img-placeholder"></div></div>' +
                '<div class="name"><strong>' + escHtml(item.name) + '</strong>' +
                    '<small>' + escHtml(stripIngredientsFromDescription(item.description || '') || '--') + '</small></div>' +
                '<div><span class="cat">' + escHtml(displayCategoryLabelForTable(item.category_name)) + '</span></div>' +
                '<div class="price">₱' + parseFloat(item.price).toFixed(2) + '</div>' +
                '<div>' +
                    '<span class="pill ' + statusClass + '">' + statusLabel + '</span>' +
                    '<button class="status-toggle-btn ' + (item.is_available ? 'to-out' : 'to-ok') + '" data-toggle-availability="' + item.id + '" data-next-availability="' + (item.is_available ? '0' : '1') + '">' +
                        (item.is_available ? 'Mark Sold Out' : 'Mark Available') +
                    '</button>' +
                '</div>' +
                '<div class="acts">' +
                    '<button class="acts-btn" data-edit-id="' + item.id + '">Edit</button>' +
                    '<span class="acts-separator">|</span>' +
                    '<button class="acts-btn" data-recipe-id="' + item.id + '">Recipe</button>' +
                    '<span class="acts-separator">|</span>' +
                    '<button class="acts-btn" data-delete-id="' + item.id + '">Delete</button>' +
                '</div>';

            tableBody.appendChild(row);
        });

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

    function inferServeFlagsFromVariantRows(rowsContainer) {
        var hot = false;
        var cold = false;
        if (!rowsContainer) return { hot: hot, cold: cold };
        rowsContainer.querySelectorAll('.variant-input-row').forEach(function (row) {
            var size = (row.querySelector('.variant-input--size') && row.querySelector('.variant-input--size').value || '').trim().toLowerCase();
            var label = (row.querySelector('.variant-input--label') && row.querySelector('.variant-input--label').value || '').trim().toLowerCase();
            var combo = size + ' ' + label;
            if (/\b(hot|warm)\b/.test(combo)) hot = true;
            if (/\b(iced|cold|blended|frappe)\b/.test(combo)) cold = true;
        });
        return { hot: hot, cold: cold };
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
        var prodSel = document.getElementById('prod-category');
        var prodBasePriceWrap = document.getElementById('prod-base-price-wrap');
        var prodVariantsSection = document.getElementById('prod-variants-section');
        var editSel = document.getElementById('edit-prod-category');
        var editBasePriceWrap = document.getElementById('edit-base-price-wrap');
        var editVariantsSection = document.getElementById('edit-variants-section');
        if (prodSel) {
            var prodIsBev = isBevMergedOptionSelected(prodSel);
            if (prodBasePriceWrap) prodBasePriceWrap.hidden = prodIsBev;
            if (prodVariantsSection) prodVariantsSection.hidden = !prodIsBev;
        }
        if (editSel) {
            var editIsBev = isBevMergedOptionSelected(editSel);
            if (editBasePriceWrap) editBasePriceWrap.hidden = editIsBev;
            if (editVariantsSection) editVariantsSection.hidden = !editIsBev;
        }
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

    function getItemCategoryChipKey(item) {
        var catId = parseInt(item && item.category_id, 10);
        if (beverageMergedCategoryIds.indexOf(catId) !== -1) return 'bev';
        return 'cat-' + catId;
    }

    function getSubcategoriesForSelectedCategory() {
        if (selectedCategoryChipKey === 'all') return [];
        if (selectedCategoryChipKey === 'bev') {
            return subcategories.filter(function (s) {
                return beverageMergedCategoryIds.indexOf(parseInt(s.category_id, 10)) !== -1;
            });
        }
        var catId = parseInt(String(selectedCategoryChipKey).replace('cat-', ''), 10);
        if (!catId) return [];
        return subcategories.filter(function (s) { return parseInt(s.category_id, 10) === catId; });
    }

    function applyChipFilters() {
        var list = allItems.slice();
        if (selectedCategoryChipKey !== 'all') {
            list = list.filter(function (item) { return getItemCategoryChipKey(item) === selectedCategoryChipKey; });
        }
        if (selectedSubcategoryChipId !== 'all') {
            list = list.filter(function (item) {
                return String(item.subcategory_id || '') === String(selectedSubcategoryChipId);
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

    function renderCategoryChips() {
        if (!categoryChipsRow) return;
        var html = '<button class="chip' + (selectedCategoryChipKey === 'all' ? ' chip--active' : '') + '" data-category-key="all">All</button>';
        var insertedBeverage = false;
        (categories || []).forEach(function (cat) {
            var catId = parseInt(cat.id, 10);
            if (!catId) return;
            if (categoryNameMergesIntoBeveragesAdmin(cat.name)) {
                if (!insertedBeverage) {
                    html += '<button class="chip' + (selectedCategoryChipKey === 'bev' ? ' chip--active' : '') + '" data-category-key="bev">Drinks</button>';
                    insertedBeverage = true;
                }
                return;
            }
            var key = 'cat-' + catId;
            html += '<button class="chip' + (selectedCategoryChipKey === key ? ' chip--active' : '') + '" data-category-key="' + key + '">' + escHtml(cat.name || 'Category') + '</button>';
        });
        categoryChipsRow.innerHTML = html;
    }

    function renderFilterChips() {
        renderCategoryChips();
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

    function parseVariantsFromDescriptionForAdmin(description) {
        var descStr = String(description || '');
        var lines = descStr.split(/\r?\n/);
        var line = null;
        lines.forEach(function (ln) {
            if (line) return;
            if (/^\s*Variants\s*:/i.test(ln || '')) line = ln;
        });
        if (!line) return [];

        var payload = String(line).replace(/^\s*Variants\s*:/i, '').trim();
        if (!payload) return [];

        var parts = payload.split(';');
        var out = [];
        parts.forEach(function (seg) {
            var s = String(seg || '').trim();
            if (!s) return;

            var lastEq = s.lastIndexOf('=');
            if (lastEq === -1) return;

            var left = s.substring(0, lastEq).trim();
            var priceStr = s.substring(lastEq + 1).trim();

            var firstParen = left.indexOf('(');
            if (firstParen === -1) {
                out.push({ size: left, label: left, price: priceStr });
                return;
            }

            var size = left.substring(0, firstParen).trim();
            var label = left.substring(firstParen + 1).trim();
            if (label.endsWith(')')) label = label.substring(0, label.length - 1).trim();

            out.push({ size: size, label: label, price: priceStr });
        });
        return out;
    }

    // ── Load items from API ───────────────────────────────────────────────────
    function loadItems() {
        return apiCall('GET', 'api/menu.php').then(function (res) {
            if (!res.success) { console.error(res.error); return; }
            categories = res.categories || [];
            subcategories = res.subcategories || [];
            mainCategories = res.main_categories || [];
            allItems   = res.items;
            fillCategorySelects(categories);
            fillMainCategorySelects();
            renderFilterChips();

            // Update stats
            var totalEl = document.querySelector('.stat-card:first-child p');
            if (totalEl) totalEl.innerHTML = allItems.length + ' <span>items</span>';

            // Pull best-seller + alert count from dashboard aggregates.
            apiCall('GET', 'api/dashboard.php').then(function (dash) {
                if (!dash.success) return;
                var bestEl = document.querySelectorAll('.stat-card p')[1];
                var alertEl = document.querySelectorAll('.stat-card p')[2];
                if (bestEl) {
                    var best = (dash.top_items && dash.top_items[0] && dash.top_items[0].name) || 'N/A';
                    bestEl.textContent = best;
                }
                if (alertEl) {
                    alertEl.innerHTML = (dash.low_stock_count || 0) + ' <span class="warn">Unavailable</span>';
                }
            }).catch(function () {});
        }).catch(function (err) { console.error('Menu load failed:', err); });
    }

    // ── Create item ───────────────────────────────────────────────────────────
    var createPrimaryBtn = createBackdrop.querySelector('.product-btn--primary');
    var createGhostBtn   = createBackdrop.querySelector('.product-btn--ghost');

    if (createGhostBtn) createGhostBtn.addEventListener('click', function () { closeModal(createBackdrop); });

    if (createPrimaryBtn) {
        createPrimaryBtn.addEventListener('click', function () {
            var name     = document.getElementById('prod-name').value.trim();
            var catSel   = document.getElementById('prod-category');
            var catId    = parseInt(catSel && catSel.value, 10);
            var mainCatId = parseInt(prodMainCategorySelect && prodMainCategorySelect.value, 10);
            var desc     = document.getElementById('prod-description').value.trim();
            var basePriceInput = document.getElementById('prod-base-price');
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

            // Collect variants from the create modal (save into description).
            var variants = [];
            if (isBeverageCreate && variantsListEl) {
                variantsListEl.querySelectorAll('.variant-input-row').forEach(function (row) {
                    var size = (row.querySelector('.variant-input--size') && row.querySelector('.variant-input--size').value || '').trim();
                    var label = (row.querySelector('.variant-input--label') && row.querySelector('.variant-input--label').value || '').trim();
                    var priceRaw = (row.querySelector('.variant-input--price') && row.querySelector('.variant-input--price').value || '').trim();
                    if (!size && !label && !priceRaw) return;
                    variants.push({ size: size, label: label, price: priceRaw });
                });
            }

            if (isBeverageCreate && variants.length) {
                // Strip old variant line to avoid duplicates
                var lines2 = (desc || '').split(/\r?\n/);
                lines2 = lines2.filter(function (ln) {
                    return !/^Variants\s*:/i.test(String(ln || '').trim());
                });
                desc = lines2.join('\n').trim();

                var parts = variants.map(function (v) {
                    var s = v.size || '—';
                    var l = v.label || '';
                    var p = v.price !== '' ? Number(v.price).toFixed(2) : '0.00';
                    return s + ' (' + l + ') = ' + p;
                });
                desc = (desc ? (desc + '\n') : '') + 'Variants: ' + parts.join('; ');
            }

            var price = 0;
            if (isBeverageCreate && variants.length) {
                var pricedVariants = variants
                    .map(function (v) { return Number(v.price); })
                    .filter(function (n) { return Number.isFinite(n) && n > 0; });
                if (pricedVariants.length) {
                    price = Math.min.apply(null, pricedVariants);
                }
            } else if (!isBeverageCreate) {
                price = parseFloat(basePriceInput && basePriceInput.value || '0');
            }

            if (!name || !catId || !mainCatId) {
                alert('Name, main category, and category are required.');
                return;
            }

            if (!(price > 0)) {
                alert(isBeverageCreate ? 'Add at least one variant price greater than 0.' : 'Base price is required for non-beverage products.');
                return;
            }

            var subcategoryId = prodSubcategorySelect ? prodSubcategorySelect.value : '';
            if (isBeverageCreate && !subcategoryId) {
                alert('Select a subcategory (e.g. Coffee, Frappe, Hot, Cold).');
                return;
            }

            desc = stripPosBevSectionLine(desc);

            var serveHot = 0;
            var serveCold = 0;
            if (isBeverageCreate) {
                applyServeFlagsFromSubcategory('prod');
                var serveFlags = readServeFlagsFromForm('prod');
                if (!serveFlags.hot && !serveFlags.cold) {
                    serveFlags = inferServeFlagsFromVariantRows(variantsListEl);
                    setServeFlagsOnForm('prod', serveFlags);
                }
                if (!serveFlags.hot && !serveFlags.cold) {
                    alert('Pick Hot or Cold as the subcategory, or add a variant with temperature.');
                    return;
                }
                serveHot = serveFlags.hot ? 1 : 0;
                serveCold = serveFlags.cold ? 1 : 0;
            }

            createPrimaryBtn.disabled    = true;
            createPrimaryBtn.textContent = 'SAVING…';
            var createPayload = {
                name: name,
                category_id: catId,
                main_category_id: mainCatId,
                price: price,
                description: desc,
                is_available: 1,
            };
            if (subcategoryId) {
                createPayload.subcategory_id = parseInt(subcategoryId, 10);
            }
            if (isBeverageCreate) {
                createPayload.serve_hot = serveHot;
                createPayload.serve_cold = serveCold;
            }

            apiCall('POST', 'api/menu.php', createPayload).then(function (res) {
                if (res.success) {
                    closeModal(createBackdrop);
                    document.getElementById('prod-name').value        = '';
                    document.getElementById('prod-category').value    = '';
                    if (prodMainCategorySelect) prodMainCategorySelect.value = '';
                    document.getElementById('prod-description').value = '';
                    if (basePriceInput) basePriceInput.value = '';
                    setServeFlagsOnForm('prod', { hot: false, cold: false });
                    if (prodSubcategorySelect) prodSubcategorySelect.value = '';
                    if (newSubcategoryNameInput) newSubcategoryNameInput.value = '';
                    collapseCatalogPanels();
                    syncProductSubcategoryOptions();
                    syncBevSubVisibility();
                    if (variantsListEl) resetVariantRows();
                    if (customizableTagsList) {
                        customizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) { p.remove(); });
                    }
                    if (customizableIngredientsInput) customizableIngredientsInput.value = '';
                    loadItems();
                } else {
                    alert('Error: ' + res.error);
                }
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
        var editBasePriceInput = document.getElementById('edit-base-price');
        if (editBasePriceInput) editBasePriceInput.value = Number(item.price || 0).toFixed(2);
        var editCat = document.getElementById('edit-prod-category');
        setCategorySelectForBeverageItem(editCat, item.category_id);
        if (editMainCategorySelect) {
            editMainCategorySelect.value = item.main_category_id ? String(item.main_category_id) : '';
        }

        var rawDesc = item.description || '';
        document.getElementById('edit-prod-description').value = stripStructuredLinesForEditTextarea(rawDesc);

        if (editCustomizableTagsList) {
            editCustomizableTagsList.querySelectorAll('.tag-pill[data-ingredient]').forEach(function (p) { p.remove(); });
        }
        if (editCustomizableIngredientsInput) editCustomizableIngredientsInput.value = '';
        parseRemovableIngredientsFromDescription(rawDesc).forEach(function (ing) {
            addEditIngredientToken(ing);
        });

        if (editVariantsListEl) {
            editVariantsListEl.innerHTML = '';
            var parsedVariants = parseVariantsFromDescriptionForAdmin(rawDesc);
            if (parsedVariants.length) {
                parsedVariants.forEach(function (v) { addEditVariantRow(v); });
            } else {
                var base = parseFloat(item.price);
                if (Number.isFinite(base) && base > 0) {
                    addEditVariantRow({ size: '', label: '', price: String(base) });
                } else {
                    addEditVariantRow();
                }
            }
        }

        syncEditSubcategoryOptions(item.subcategory_id || null);
        if (item.subcategory_id && editProdSubcategorySelect) {
            editProdSubcategorySelect.value = String(item.subcategory_id);
        }
        if (isBevMergedOptionSelected(editCat)) {
            setServeFlagsOnForm('edit', {
                hot: !!item.serve_hot,
                cold: !!item.serve_cold
            });
            applyServeFlagsFromSubcategory('edit');
        } else {
            setServeFlagsOnForm('edit', { hot: false, cold: false });
        }
        syncBevSubVisibility();

        openModal(editBackdrop);
    }

    if (editBackdrop) {
        var editGhostBtn   = editBackdrop.querySelector('.product-btn--ghost');
        var editPrimaryBtn = editBackdrop.querySelector('.product-btn--primary');

        if (editGhostBtn)   editGhostBtn.addEventListener('click', function () { closeModal(editBackdrop); });

        if (editPrimaryBtn) {
            editPrimaryBtn.addEventListener('click', function () {
                if (!editingId) return;

                var editCatSel = document.getElementById('edit-prod-category');
                var descEdit = document.getElementById('edit-prod-description').value.trim();
                var resolvedBevCat = null;
                var editBasePriceInput = document.getElementById('edit-base-price');
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

                var variants = [];
                if (isBeverageEdit && editVariantsListEl) {
                    editVariantsListEl.querySelectorAll('.variant-input-row').forEach(function (row) {
                        var size = (row.querySelector('.variant-input--size') && row.querySelector('.variant-input--size').value || '').trim();
                        var label = (row.querySelector('.variant-input--label') && row.querySelector('.variant-input--label').value || '').trim();
                        var priceRaw = (row.querySelector('.variant-input--price') && row.querySelector('.variant-input--price').value || '').trim();
                        if (!size && !label && !priceRaw) return;
                        variants.push({ size: size, label: label, price: priceRaw });
                    });
                }

                var lines2 = (descEdit || '').split(/\r?\n/);
                lines2 = lines2.filter(function (ln) {
                    return !/^Variants\s*:/i.test(String(ln || '').trim());
                });
                descEdit = lines2.join('\n').trim();
                if (isBeverageEdit && variants.length) {
                    var parts = variants.map(function (v) {
                        var s = v.size || '—';
                        var l = v.label || '';
                        var p = v.price !== '' ? Number(v.price).toFixed(2) : '0.00';
                        return s + ' (' + l + ') = ' + p;
                    });
                    descEdit = (descEdit ? (descEdit + '\n') : '') + 'Variants: ' + parts.join('; ');
                }

                var price = 0;
                if (isBeverageEdit && variants.length) {
                    var pricedVariants = variants
                        .map(function (v) { return Number(v.price); })
                        .filter(function (n) { return Number.isFinite(n) && n > 0; });
                    if (pricedVariants.length) {
                        price = Math.min.apply(null, pricedVariants);
                    }
                } else if (!isBeverageEdit) {
                    price = parseFloat(editBasePriceInput && editBasePriceInput.value || '0');
                }

                if (!(price > 0)) {
                    alert(isBeverageEdit
                        ? 'Add at least one variant with a price greater than 0 (same as create product).'
                        : 'Base price is required for non-beverage products.');
                    return;
                }

                var serveHot = 0;
                var serveCold = 0;
                if (isBeverageEdit) {
                    applyServeFlagsFromSubcategory('edit');
                    var serveFlagsEdit = readServeFlagsFromForm('edit');
                    if (!serveFlagsEdit.hot && !serveFlagsEdit.cold) {
                        serveFlagsEdit = inferServeFlagsFromVariantRows(editVariantsListEl);
                        setServeFlagsOnForm('edit', serveFlagsEdit);
                    }
                    if (!serveFlagsEdit.hot && !serveFlagsEdit.cold) {
                        alert('Choose a Hot or Cold subcategory, or add a variant with temperature.');
                        return;
                    }
                    serveHot = serveFlagsEdit.hot ? 1 : 0;
                    serveCold = serveFlagsEdit.cold ? 1 : 0;
                }

                var editSubcategoryId = editProdSubcategorySelect ? editProdSubcategorySelect.value : '';
                if (isBeverageEdit && !editSubcategoryId) {
                    alert('Select a subcategory (e.g. Coffee, Frappe, Hot, Cold).');
                    return;
                }

                var editMainCatId = parseInt(editMainCategorySelect && editMainCategorySelect.value, 10);

                var payload = {
                    id:          editingId,
                    name:        document.getElementById('edit-prod-name').value.trim(),
                    category_id: resolvedBevCat || parseInt(editCatSel.value, 10),
                    main_category_id: editMainCatId,
                    price:       price,
                    description: descEdit,
                    subcategory_id: editSubcategoryId ? parseInt(editSubcategoryId, 10) : '',
                };
                if (isBeverageEdit) {
                    payload.serve_hot = serveHot;
                    payload.serve_cold = serveCold;
                }

                if (!payload.name || !payload.category_id || !editMainCatId || !(payload.price > 0)) {
                    alert('Name, main category, category, and a valid price are required.');
                    return;
                }

                editPrimaryBtn.disabled    = true;
                editPrimaryBtn.textContent = 'SAVING…';

                apiCall('PUT', 'api/menu.php', payload).then(function (res) {
                    if (res.success) {
                        closeModal(editBackdrop);
                        loadItems();
                    } else {
                        alert('Error: ' + res.error);
                    }
                }).finally(function () {
                    editPrimaryBtn.disabled    = false;
                    editPrimaryBtn.textContent = 'DONE';
                });
            });
        }
    }

    // ── Delete item ───────────────────────────────────────────────────────────
    function deleteItem(id) {
        var item = allItems.find(function (i) { return i.id === id; });
        if (!item) return;
        if (!confirm('Delete "' + item.name + '"? This cannot be undone.')) return;

        apiCall('DELETE', 'api/menu.php', { id: id }).then(function (res) {
            if (res.success) {
                loadItems();
            } else {
                alert('Error: ' + res.error);
            }
        });
    }

    // ── Category/subcategory filter chips ─────────────────────────────────────
    if (categoryChipsRow) {
        categoryChipsRow.addEventListener('click', function (e) {
            var chip = e.target.closest('.chip[data-category-key]');
            if (!chip) return;
            selectedCategoryChipKey = chip.getAttribute('data-category-key') || 'all';
            selectedSubcategoryChipId = 'all';
            renderFilterChips();
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

    // ── Initial load ──────────────────────────────────────────────────────────
    loadItems();
})();
