const screenService = document.getElementById('screen-service');
const screenRemote = document.getElementById('screen-remote');
const screenOnsite = document.getElementById('screen-onsite');
const screenCategory = document.getElementById('screen-category');
const screenBeverages = document.getElementById('screen-beverages');
const screenMeals = document.getElementById('screen-meals');

const menuPages = [screenBeverages, screenMeals];

let previousScreen = null;
let selectedServiceType = '';
let selectedOrderType = 'Not selected';
const cartByFulfillment = {
    dine_in: [],
    take_out: []
};
let activeFulfillmentBucket = 'dine_in';
let ingredientModalResolver = null;
let ingredientModalCurrentItemName = '';

let menuData = { categories: [], items: [], subcategories: [] };
let fastMovingItemIds = [];
/** Top tab key: 'beverages' | 'cat-{id}' (same category chips as POS). */
let selectedMenuGroup = null;
/** Category ids merged into the Beverages tab (POS-aligned). */
let beverageCategoryIds = [];
var BEVERAGES_MERGED_KEY = 'beverages';
/** Side circle key: 'sub-{id}' | '_other'. */
let selectedBevCategory = '';
/** Hot / cold subcategory filter for drinks. */
let selectedDrinkTemp = 'all';
let selectedMealCategory = '';
/** When set, meals screen shows only this category_id (POS-style tab). */
let selectedKioskCategoryId = null;
const PICKUP_START_HOUR = 20; // 8:00 PM
const PICKUP_END_HOUR = 24; // 12:00 MN
const VAT_RATE = 0.12;
const SENIOR_PWD_RATE = 0.20;
let latestServerHour24 = null;
let orderDiscount = { type: 'none', customer_name: '', id_number: '' };
/** Customer-flagged PWD/SC request for staff verification (not applied until staff enters ID). */
let discountRequest = { active: false, type: 'senior' };
let orderCustomerName = '';

function isPickupOrderSelected() {
    var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    return orderTypeNorm === 'pickup' || orderTypeNorm === 'pick_up';
}

function isDineTakeOrderSelected() {
    var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    return orderTypeNorm === 'dine_in' || orderTypeNorm === 'dinein' || orderTypeNorm === 'take_out' || orderTypeNorm === 'takeout';
}

function isDineInOrderSelected() {
    var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    return orderTypeNorm === 'dine_in' || orderTypeNorm === 'dinein';
}

function getFulfillmentCart(bucket) {
    return cartByFulfillment[bucket === 'take_out' ? 'take_out' : 'dine_in'];
}

function getActiveFulfillmentCart() {
    if (!isDineTakeOrderSelected()) return cartByFulfillment.dine_in;
    return getFulfillmentCart(activeFulfillmentBucket);
}

function getAllCartItems() {
    return cartByFulfillment.dine_in.concat(cartByFulfillment.take_out);
}

function clearAllCarts() {
    cartByFulfillment.dine_in.length = 0;
    cartByFulfillment.take_out.length = 0;
}

function setActiveFulfillmentFromOrderType() {
    if (!isDineTakeOrderSelected()) {
        activeFulfillmentBucket = 'dine_in';
        return;
    }
    activeFulfillmentBucket = isDineInOrderSelected() ? 'dine_in' : 'take_out';
}

function syncOrderTypeToggleButtons() {
    var show = isDineTakeOrderSelected();
    var isTakeOutActive = activeFulfillmentBucket === 'take_out';
    var question = isTakeOutActive ? 'Would you like to dine in?' : 'Would you like to take out?';
    var showDineLabel = show && cartByFulfillment.dine_in.length > 0 && cartByFulfillment.take_out.length > 0;
    document.querySelectorAll('.order-type-toggle-section').forEach(function (section) {
        section.hidden = !show;
        var label = section.querySelector('.order-type-target-label');
        if (label) {
            label.hidden = !isTakeOutActive;
            label.textContent = 'Orders to take out:';
        }
        var takeWrap = section.querySelector('.sidebar-items--take-out');
        if (takeWrap) {
            takeWrap.hidden = !isTakeOutActive && cartByFulfillment.take_out.length === 0;
        }
        var takeBox = section.querySelector('.sidebar-cart-box--take-out');
        if (takeBox) {
            takeBox.hidden = !isTakeOutActive && cartByFulfillment.take_out.length === 0;
        }
    });
    document.querySelectorAll('.order-type-toggle-btn').forEach(function (btn) {
        btn.textContent = question;
        btn.setAttribute('aria-label', isTakeOutActive ? 'Switch to dine in' : 'Switch to take out');
    });
    document.querySelectorAll('.sidebar-cart-group__label--dine-in').forEach(function (label) {
        label.hidden = !showDineLabel;
    });
    syncFulfillmentCartBoxHighlights();
}

function syncFulfillmentCartBoxHighlights() {
    var show = isDineTakeOrderSelected();
    var active = activeFulfillmentBucket === 'take_out' ? 'take_out' : 'dine_in';
    document.querySelectorAll('[data-fulfillment-box]').forEach(function (box) {
        var key = box.getAttribute('data-fulfillment-box');
        var isActive = show && key === active;
        box.classList.toggle('sidebar-cart-box--active', isActive);
        box.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function setActiveFulfillmentBucket(bucket) {
    if (!isDineTakeOrderSelected()) return;
    var next = bucket === 'take_out' ? 'take_out' : 'dine_in';
    if (activeFulfillmentBucket === next) {
        syncFulfillmentCartBoxHighlights();
        return;
    }
    activeFulfillmentBucket = next;
    syncOrderTypeToggleButtons();
    renderAllSidebars();
}

function toggleOrderTypeDineTake() {
    if (!isDineTakeOrderSelected()) return;
    setActiveFulfillmentBucket(activeFulfillmentBucket === 'dine_in' ? 'take_out' : 'dine_in');
}

function getSidebarOrderName() {
    var fromInput = '';
    document.querySelectorAll('.order-name-input').forEach(function (input) {
        if (!fromInput) fromInput = String(input.value || '').trim();
    });
    return fromInput || String(orderCustomerName || '').trim();
}

function syncOrderNameSection() {
    var show = isPickupOrderSelected();
    document.querySelectorAll('.order-name-section').forEach(function (section) {
        section.hidden = !show;
    });
    if (show) {
        document.querySelectorAll('.order-name-input').forEach(function (input) {
            if (input.value !== orderCustomerName) input.value = orderCustomerName;
        });
    }
}

function getPickupTestHourOverride() {
    try {
        var qp = new URLSearchParams(window.location.search || '');
        var raw = qp.get('pickup_test_hour');
        if (raw == null || raw === '') return null;
        var n = parseInt(raw, 10);
        if (!Number.isFinite(n) || n < 0 || n > 23) return null;
        return n;
    } catch (e) {
        return null;
    }
}

function isPickupAvailableNow(now) {
    return true; // Temporarily disabled per request.
}

function refreshPickupServerTime() {
    return fetch('api/time_public.php?_=' + Date.now(), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) return;
            var h = parseInt(res.server_hour_24, 10);
            if (Number.isFinite(h) && h >= 0 && h <= 23) {
                latestServerHour24 = h;
            }
        })
        .catch(function () {
            // Keep local-device fallback time.
        })
        .finally(function () {
            syncPickupAvailabilityUI();
        });
}

function syncPickupAvailabilityUI() {
    var pickupCard = document.getElementById('btn-remote-order');
    if (!pickupCard) return;
    pickupCard.classList.remove('card--time-locked');
    pickupCard.setAttribute('aria-disabled', 'false');
}

function showScreen(show) {
    [screenService, screenRemote, screenOnsite, screenCategory, screenBeverages, screenMeals].forEach(function (s) {
        s.classList.add('page--hidden');
    });
    show.classList.remove('page--hidden');
}

function formatCurrency(value) {
    return formatPeso(value);
}

function formatPeso(value) {
    return '₱' + (value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function normalizeDiscountType(value) {
    var type = String(value || '').trim().toLowerCase();
    return type === 'senior' || type === 'pwd' ? type : 'none';
}

function getDiscountLabel(type) {
    if (type === 'senior') return 'Senior Citizen';
    if (type === 'pwd') return 'PWD';
    return 'None';
}

function getOrderDiscountPayload() {
    return {
        type: normalizeDiscountType(orderDiscount.type),
        customer_name: String(orderDiscount.customer_name || '').trim(),
        id_number: String(orderDiscount.id_number || '').trim()
    };
}

function calculateDiscountTotals(grossAmount, discountType) {
    var gross = Math.round((Number(grossAmount) || 0) * 100) / 100;
    var type = normalizeDiscountType(discountType);
    if (type === 'none' || gross <= 0) {
        return { gross: gross, vatExempt: 0, discountAmount: 0, total: gross, type: 'none' };
    }

    var vatExemptBase = gross / (1 + VAT_RATE);
    var vatExempt = gross - vatExemptBase;
    var seniorPwdDiscount = vatExemptBase * SENIOR_PWD_RATE;
    var total = vatExemptBase - seniorPwdDiscount;

    return {
        gross: Math.round(gross * 100) / 100,
        vatExempt: Math.round(vatExempt * 100) / 100,
        discountAmount: Math.round((vatExempt + seniorPwdDiscount) * 100) / 100,
        total: Math.round(total * 100) / 100,
        type: type
    };
}

function escapeHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Same display style as staff POS add-on pills (P prefix). */
function formatAddonPPrice(value) {
    var n = Number(value) || 0;
    return 'P' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function normalizeAddonEntry(x) {
    if (x && typeof x === 'object' && x.name) {
        var name = String(x.name).trim();
        if (!name) return null;
        var price = Math.round((Number(x.price) || 0) * 100) / 100;
        return { name: name, price: price };
    }
    if (typeof x === 'string') {
        var t = x.trim();
        return t ? { name: t, price: 0 } : null;
    }
    return null;
}

function normalizeAddonsList(arr) {
    return (arr || []).map(normalizeAddonEntry).filter(Boolean);
}

/** Same parsing rules as staff POS (`staff_dashboard.html` parseVariantsFromDescription). */
function parseMoneyAttr(val) {
    var n = parseFloat(String(val == null ? '' : val).replace(/,/g, ''));
    return isNaN(n) ? 0 : n;
}

function parseVariantsFromDescription(description) {
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

function categoryNameMergesIntoCustomerBeverages(name) {
    var n = (name || '').trim().toLowerCase();
    if (!n) return false;
    if (n === 'drinks') return true;
    if (/\brefresher/.test(n)) return true;
    if (n.indexOf('frappe') !== -1) return true;
    if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee') return true;
    if (n === 'coffee' || n === 'beverages') return true;
    return false;
}

function getKioskTemperatureVariants(menuItem, temperatureFallback) {
    var v = parseVariantsFromDescription(menuItem.description || '');
    if (v.length) return v;
    if (!temperatureFallback) return [];
    var p = menuItem.price != null ? String(menuItem.price) : '0';
    var out = [];
    var serveHot = menuItem.serve_hot == null ? true : !!Number(menuItem.serve_hot);
    var serveCold = menuItem.serve_cold == null ? true : !!Number(menuItem.serve_cold);
    if (serveHot) out.push({ size: 'Hot', label: '', price: p });
    if (serveCold) out.push({ size: 'Iced', label: '', price: p });
    return out;
}

function getMenuItemById(id) {
    return (menuData.items || []).find(function (x) {
        return x.id === id;
    });
}

function computeCartLineUnit(menuItemId, temperature, addons) {
    var mi = getMenuItemById(menuItemId);
    var base = mi ? Number(mi.price) || 0 : 0;
    var unit = base;
    if (temperature && Number(temperature.variantPrice) > 0) {
        unit = Number(temperature.variantPrice);
    }
    var addSum = normalizeAddonsList(addons).reduce(function (s, a) {
        return s + (Number(a.price) || 0);
    }, 0);
    return Math.round((unit + addSum) * 100) / 100;
}

function describeTemperatureForCart(t) {
    if (!t || !t.main) return '';
    var d = String(t.detail || '').trim();
    return d ? t.main + ' (' + d + ')' : t.main;
}

function kioskVariantMatchesTemperature(v, t) {
    if (!t || !t.main) return false;
    var vm = String(v.size || '').trim().toLowerCase();
    var tm = String(t.main || '').trim().toLowerCase();
    if (vm !== tm) return false;
    var vl = String(v.label || '').trim().toLowerCase();
    var td = String(t.detail || '').trim().toLowerCase();
    if (!td && !vl) return true;
    return vl === td;
}

var catalogAddons = [];

function loadCatalogAddons() {
    return fetch('api/addons_public.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            catalogAddons = res && res.success && Array.isArray(res.addons) ? res.addons : [];
        })
        .catch(function () {
            catalogAddons = [];
        });
}

function getAddonOptionsForItem(itemName, categoryName) {
    var cat = String(categoryName || '').toLowerCase();
    var station = isBeverageCategoryName(cat) ? 'bar' : 'kitchen';
    return catalogAddons
        .filter(function (a) {
            return String(a.station || '').toLowerCase() === station;
        })
        .map(function (a) {
            return {
                name: String(a.name || '').trim(),
                price: Number(a.price) || 0
            };
        })
        .filter(function (a) {
            return !!a.name;
        });
}

/**
 * @param {object} menuItem — API row (id, name, price, description, category_name, …)
 * @param {{ addons?: object[], temperature?: object|null, note?: string }} initial — existing selections when editing
 * @param {{ temperatureFallback?: boolean }} openOpts — Hot/Iced when no Variants: line (beverages only)
 */
function openIngredientModal(menuItem, initial, openOpts) {
    var modal = document.getElementById('ingredient-modal');
    var checklist = document.getElementById('ingredient-checklist');
    var title = document.getElementById('ingredient-modal-title');
    var sub = document.getElementById('ingredient-modal-sub');
    var noteInput = document.getElementById('ingredient-modal-note-input');
    if (!modal || !checklist || !title) return Promise.resolve(null);

    initial = initial || {};
    openOpts = openOpts || {};

    var itemName = menuItem.name || 'Item';
    ingredientModalCurrentItemName = itemName;
    title.textContent = 'Customize — ' + itemName;

    var variants = getKioskTemperatureVariants(menuItem, !!openOpts.temperatureFallback);
    var initialTemp = initial.temperature || null;
    var selectedSet = new Set(
        (initial.addons || []).map(function (x) {
            if (x && typeof x === 'object' && x.name) return String(x.name).toLowerCase();
            return String(x || '').toLowerCase();
        })
    );

    var tempSectionHtml = '';
    if (variants.length) {
        var autoSelectOnlyTemp = variants.length === 1;
        var tempPills = variants
            .map(function (v) {
                var isActive = autoSelectOnlyTemp || kioskVariantMatchesTemperature(v, initialTemp);
                var main = String(v.size || v.label || 'Option').trim();
                var oz = String(v.label || '').trim();
                var showOz = oz && oz.toLowerCase() !== main.toLowerCase();
                var subLine = [];
                if (showOz) subLine.push(oz);
                subLine.push(formatAddonPPrice(parseMoneyAttr(v.price)));
                var vp = parseMoneyAttr(v.price);
                return (
                    '<button type="button" class="kiosk-addon-pill kiosk-temp-pill' +
                    (isActive ? ' kiosk-addon-pill--active' : '') +
                    '" aria-pressed="' +
                    (isActive ? 'true' : 'false') +
                    '" data-kiosk-temp="1"' +
                    ' data-temp-main="' +
                    encodeURIComponent(main) +
                    '" data-temp-detail="' +
                    encodeURIComponent(showOz ? oz : '') +
                    '" data-temp-variant-price="' +
                    String(vp) +
                    '">' +
                    '<span class="kiosk-addon-pill__main">' +
                    escapeHtml(main) +
                    '</span>' +
                    '<span class="kiosk-addon-pill__sub">' +
                    escapeHtml(subLine.join(' · ')) +
                    '</span>' +
                    '</button>'
                );
            })
            .join('');
        tempSectionHtml =
            '<div class="kiosk-temp-section">' +
            '<p class="ingredient-modal-section-label">Temperature</p>' +
            '<div class="kiosk-temp-row">' +
            tempPills +
            '</div></div>';
    }

    if (sub) {
        if (!variants.length) {
            sub.textContent = 'Tap add-ons to include them. Tap again to remove. Extra charges may apply at pickup.';
        } else if (variants.length === 1) {
            sub.textContent = 'Temperature is set. Add optional add-ons, or continue.';
        } else {
            sub.textContent = 'Choose a temperature, then optional add-ons. Tap an add-on again to remove it.';
        }
    }

    var options = getAddonOptionsForItem(itemName, menuItem.category_name || '');
    var pillsHtml = options
        .map(function (opt) {
            var isActive = selectedSet.has(String(opt.name).toLowerCase());
            var badgeHtml = opt.badge
                ? '<span class="kiosk-addon-pill__badge" aria-hidden="true">' + escapeHtml(opt.badge) + '</span>'
                : '';
            return (
                '<button type="button" class="kiosk-addon-pill' +
                (isActive ? ' kiosk-addon-pill--active' : '') +
                '" aria-pressed="' +
                (isActive ? 'true' : 'false') +
                '" data-kiosk-addon-name="' +
                encodeURIComponent(opt.name) +
                '" data-kiosk-addon-price="' +
                String(Number(opt.price) || 0) +
                '">' +
                '<span class="kiosk-addon-pill__main">' +
                escapeHtml(opt.name) +
                '</span>' +
                badgeHtml +
                '<span class="kiosk-addon-pill__sub">' +
                formatAddonPPrice(opt.price) +
                '</span>' +
                '</button>'
            );
        })
        .join('');

    checklist.className = 'ingredient-checklist ingredient-checklist--addons';
    checklist.innerHTML =
        tempSectionHtml +
        '<p class="ingredient-modal-section-label">Add-ons</p>' +
        '<div class="kiosk-addon-grid">' +
        pillsHtml +
        '</div>';

    // If only one temperature exists, force it selected in the DOM (covers cache/edge cases).
    if (variants.length === 1) {
        var onlyTemp = checklist.querySelector('.kiosk-temp-pill');
        if (onlyTemp) {
            checklist.querySelectorAll('.kiosk-temp-pill').forEach(function (x) {
                x.classList.remove('kiosk-addon-pill--active');
                x.setAttribute('aria-pressed', 'false');
            });
            onlyTemp.classList.add('kiosk-addon-pill--active');
            onlyTemp.setAttribute('aria-pressed', 'true');
        }
    }

    if (noteInput) {
        noteInput.value = String(initial.note || '').trim();
    }

    modal.setAttribute('data-kiosk-require-temp', variants.length ? '1' : '0');

    checklist.onclick = function (e) {
        var tbtn = e.target.closest('.kiosk-temp-pill');
        if (tbtn && checklist.contains(tbtn)) {
            e.preventDefault();
            var row = tbtn.closest('.kiosk-temp-row');
            if (row) {
                row.querySelectorAll('.kiosk-temp-pill').forEach(function (x) {
                    x.classList.remove('kiosk-addon-pill--active');
                    x.setAttribute('aria-pressed', 'false');
                });
            }
            tbtn.classList.add('kiosk-addon-pill--active');
            tbtn.setAttribute('aria-pressed', 'true');
            return;
        }
        var btn = e.target.closest('.kiosk-addon-pill');
        if (!btn || !checklist.contains(btn) || btn.classList.contains('kiosk-temp-pill')) return;
        e.preventDefault();
        btn.classList.toggle('kiosk-addon-pill--active');
        btn.setAttribute('aria-pressed', btn.classList.contains('kiosk-addon-pill--active') ? 'true' : 'false');
    };

    modal.classList.add('ingredient-modal-overlay--open');
    modal.setAttribute('aria-hidden', 'false');

    return new Promise(function (resolve) {
        ingredientModalResolver = resolve;
    });
}

function closeIngredientModal(value) {
    var modal = document.getElementById('ingredient-modal');
    if (!modal) return;
    modal.classList.remove('ingredient-modal-overlay--open');
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('data-kiosk-require-temp');
    if (ingredientModalResolver) {
        ingredientModalResolver(value);
        ingredientModalResolver = null;
    }
}

function getItemKey(name, addons, removed, temperature, note) {
    var tk = '';
    if (temperature && temperature.main) {
        tk =
            String(temperature.main).toLowerCase() +
            ':' +
            String(temperature.detail || '').toLowerCase() +
            ':' +
            String(Number(temperature.variantPrice) || 0);
    }
    var sortedA = normalizeAddonsList(addons)
        .map(function (a) {
            return a.name.toLowerCase() + ':' + String(a.price);
        })
        .sort();
    var sortedR = (removed || [])
        .slice()
        .map(function (x) {
            return String(x || '').trim().toLowerCase();
        })
        .filter(Boolean)
        .sort();
    var nk = String(note || '').trim().toLowerCase();
    return name + '|t:' + tk + '|a:' + sortedA.join(',') + '|r:' + sortedR.join(',') + '|n:' + nk;
}

function addItemToCart(item) {
    var ad = normalizeAddonsList(item.addons);
    var rm = item.removed || [];
    var temp = item.temperature || null;
    var note = String(item.note || '').trim();
    var key = getItemKey(item.name, ad, rm, temp, note);
    var targetCart = getActiveFulfillmentCart();
    var existing = targetCart.find(function (cartItem) {
        return (
            getItemKey(
                cartItem.name,
                cartItem.addons || [],
                cartItem.removed || [],
                cartItem.temperature || null,
                cartItem.note || ''
            ) === key
        );
    });

    if (existing) {
        existing.qty += 1;
        return;
    }

    var unit = computeCartLineUnit(item.menu_item_id, temp, ad);

    targetCart.push({
        menu_item_id: item.menu_item_id || null,
        name: item.name,
        price: unit,
        qty: 1,
        addons: ad.map(function (a) {
            return { name: a.name, price: a.price };
        }),
        temperature: temp,
        note: note,
        removed: (item.removed || []).slice()
    });
}

function updateOrderTypeLabels() {
    menuPages.forEach(function (page) {
        var target = page.querySelector('.order-type-value');
        if (target) target.textContent = selectedOrderType;
    });
    syncOrderTypeToggleButtons();
}

function applyPaymentRules() {
    menuPages.forEach(function (page) {
        var paymentSection = page.querySelector('.payment-method-section');
        var cashBtn = Array.prototype.slice.call(page.querySelectorAll('.payment-btn')).find(function (b) {
            return b.textContent.trim().toUpperCase() === 'CASH';
        });
        var gcashBtn = Array.prototype.slice.call(page.querySelectorAll('.payment-btn')).find(function (b) {
            return b.textContent.trim().toUpperCase() === 'GCASH';
        });
        if (!cashBtn || !gcashBtn) return;

        var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
        var isPickupOrder = orderTypeNorm === 'pickup' || orderTypeNorm === 'pick_up';
        if (selectedServiceType === 'remote' || isPickupOrder) {
            if (paymentSection) paymentSection.style.display = 'none';
            cashBtn.setAttribute('disabled', 'disabled');
            cashBtn.classList.add('is-disabled');
            gcashBtn.removeAttribute('disabled');
            gcashBtn.classList.remove('is-disabled');
            cashBtn.classList.remove('payment-btn--active');
            gcashBtn.classList.add('payment-btn--active');
        } else {
            if (paymentSection) paymentSection.style.display = '';
            cashBtn.removeAttribute('disabled');
            cashBtn.classList.remove('is-disabled');
            gcashBtn.removeAttribute('disabled');
            gcashBtn.classList.remove('is-disabled');
            if (!page.querySelector('.payment-btn.payment-btn--active')) {
                cashBtn.classList.add('payment-btn--active');
            }
        }
    });
}

function getTotals() {
    var subtotal = getAllCartItems().reduce(function (sum, item) {
        return sum + item.price * item.qty;
    }, 0);
    var discount = getOrderDiscountPayload();
    var pricing = calculateDiscountTotals(subtotal, discount.type);
    return {
        subtotal: pricing.gross,
        gross: pricing.gross,
        vatExempt: pricing.vatExempt,
        discountAmount: pricing.discountAmount,
        total: pricing.total,
        discountType: pricing.type
    };
}

function renderCartItemsHtml(items, bucket) {
    if (!items.length) {
        return '<div class="cart-item cart-item--empty"><span class="cart-empty-text">No items yet.</span></div>';
    }
    return items
        .map(function (item, index) {
            var tempPart = describeTemperatureForCart(item.temperature || null);
            var alist = normalizeAddonsList(item.addons);
            var addonPart = alist.length
                ? 'Add-ons: ' +
                  alist
                      .map(function (a) {
                          return a.name + (a.price > 0 ? ' ' + formatAddonPPrice(a.price) : '');
                      })
                      .join(', ')
                : '';
            var removedPart = item.removed && item.removed.length ? 'Removed: ' + item.removed.join(', ') : '';
            var notePart = item.note ? 'Note: ' + item.note : '';
            var modBits = [];
            if (tempPart) modBits.push(tempPart);
            if (addonPart) modBits.push(addonPart);
            if (removedPart) modBits.push(removedPart);
            if (notePart) modBits.push(notePart);
            var modsText = modBits.length ? modBits.join(' · ') : 'No customizations';
            var badgeHtml = shouldShowFulfillmentSplit()
                ? '<span class="cart-fulfillment-badge cart-fulfillment-badge--' + (bucket === 'take_out' ? 'takeout' : 'dinein') + '">' +
                  (bucket === 'take_out' ? 'Take out' : 'Dine in') +
                  '</span>'
                : '';
            return (
                '<div class="cart-item">' +
                '  <div class="cart-item-row">' +
                '    <span class="cart-item-name-wrap">' + badgeHtml + '<span class="cart-item-name">' + item.name + '</span></span>' +
                '    <span class="cart-item-price">' + formatCurrency(item.price * item.qty) + '</span>' +
                '  </div>' +
                '  <div class="cart-item-row">' +
                '    <button class="cart-mods cart-mods-btn" data-action="edit-mods" data-bucket="' + bucket + '" data-index="' + index + '">' + modsText + '</button>' +
                '    <div class="qty-ctrl">' +
                '      <button type="button" class="qty-btn qty-btn--minus" data-action="dec" data-bucket="' + bucket + '" data-index="' + index + '">−</button>' +
                '      <span class="qty-val">' + item.qty + '</span>' +
                '      <button type="button" class="qty-btn qty-btn--plus" data-action="inc" data-bucket="' + bucket + '" data-index="' + index + '">+</button>' +
                '    </div>' +
                '  </div>' +
                '</div>'
            );
        })
        .join('');
}

function renderSidebarForPage(page) {
    var dineWrap = page.querySelector('.sidebar-items--dine-in');
    var takeWrap = page.querySelector('.sidebar-items--take-out');
    var badge = page.querySelector('.sidebar-badge');
    var totalEl = page.querySelector('.total-amt-final');
    var discountTypeEl = page.querySelector('.discount-type-select');
    var discountFields = page.querySelector('.discount-fields');
    var discountNameEl = page.querySelector('.discount-name-input');
    var discountIdEl = page.querySelector('.discount-id-input');
    var dineLabel = page.querySelector('.sidebar-cart-group__label--dine-in');

    if (!dineWrap || !badge || !totalEl) return;

    var showSplit = isDineTakeOrderSelected();
    var isTakeOutActive = activeFulfillmentBucket === 'take_out';
    var showDineLabel = showSplit && cartByFulfillment.dine_in.length > 0 && cartByFulfillment.take_out.length > 0;

    if (dineLabel) dineLabel.hidden = !showDineLabel;

    if (!cartByFulfillment.dine_in.length) {
        dineWrap.innerHTML = !getAllCartItems().length
            ? '<div class="cart-item cart-item--empty"><span class="cart-empty-text">No items yet. Add from menu cards.</span></div>'
            : '';
    } else {
        dineWrap.innerHTML = renderCartItemsHtml(cartByFulfillment.dine_in, 'dine_in');
    }

    if (takeWrap) {
        if (cartByFulfillment.take_out.length) {
            takeWrap.innerHTML = renderCartItemsHtml(cartByFulfillment.take_out, 'take_out');
        } else if (isTakeOutActive) {
            takeWrap.innerHTML = '<div class="cart-item cart-item--empty"><span class="cart-empty-text">No take out items yet.</span></div>';
        } else {
            takeWrap.innerHTML = '';
        }
        takeWrap.hidden = !isTakeOutActive && cartByFulfillment.take_out.length === 0;
    }

    var takeBox = page.querySelector('.sidebar-cart-box--take-out');
    if (takeBox) {
        takeBox.hidden = !isTakeOutActive && cartByFulfillment.take_out.length === 0;
    }
    syncFulfillmentCartBoxHighlights();

    var totalQty = getAllCartItems().reduce(function (sum, item) {
        return sum + item.qty;
    }, 0);
    badge.textContent = totalQty + ' ITEMS';

    var totals = getTotals();
    totalEl.textContent = formatCurrency(totals.total);
    if (discountTypeEl) discountTypeEl.value = normalizeDiscountType(orderDiscount.type);
    if (discountFields) discountFields.hidden = normalizeDiscountType(orderDiscount.type) === 'none';
    if (discountNameEl) discountNameEl.value = orderDiscount.customer_name || '';
    if (discountIdEl) discountIdEl.value = orderDiscount.id_number || '';
}

function renderAllSidebars() {
    menuPages.forEach(renderSidebarForPage);
    updateOrderTypeLabels();
    syncOrderTypeToggleButtons();
    applyPaymentRules();
    syncOrderNameSection();
}

function parseCardItem(card) {
    var nameEl = card.querySelector('.prod-card-name');
    var priceEl = card.querySelector('.prod-card-price');
    var name = nameEl ? nameEl.textContent.trim() : 'Menu Item';
    var rawPrice = priceEl ? priceEl.textContent : '0';
    var parsedPrice = parseFloat(rawPrice.replace(/[^0-9.]/g, ''));
    var price = isNaN(parsedPrice) ? 0 : parsedPrice;
    var menuIdAttr = card.getAttribute('data-menu-id');
    var menuId = menuIdAttr ? parseInt(menuIdAttr, 10) : null;
    return { menu_item_id: isNaN(menuId) ? null : menuId, name: name, price: price };
}

function isBeverageCategoryName(name) {
    var n = String(name || '')
        .trim()
        .toLowerCase();
    if (!n) return false;
    if (n === 'drinks') return true;
    if (/\brefresher/.test(n)) return true;
    if (n.indexOf('frappe') !== -1) return true;
    if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee') return true;
    if (n === 'coffee') return true;
    if (/\bjuice\b/.test(n)) return true;
    return (
        n.indexOf('beverage') !== -1 ||
        n.indexOf('drink') !== -1 ||
        n.indexOf('coffee') !== -1 ||
        n.indexOf('tea') !== -1 ||
        n.indexOf('latte') !== -1 ||
        n.indexOf('smoothie') !== -1 ||
        n.indexOf('milktea') !== -1 ||
        n.indexOf('milk tea') !== -1
    );
}

function isBeverageItem(item) {
    var mc = String(item && item.main_category_name || '').trim().toLowerCase();
    if (mc === 'bar') return true;
    if (mc === 'kitchen') return false;
    return isBeverageCategoryName(item && item.category_name);
}

/** Same beverage bucketing as adminStaff/staff_dashboard.html (POS), then mapped to customer circles. */
function parsePosBevSectionKeyFromDesc(description) {
    var valid = { coffee: 1, nonCoffee: 1, frappeCoffee: 1, frappeNonCoffee: 1, refreshers: 1, juice: 1 };
    var lines = String(description || '').split(/\r?\n/);
    for (var i = 0; i < lines.length; i++) {
        var m = String(lines[i] || '').trim().match(/^__pos_bev_section__:(.+)$/i);
        if (m) {
            var k = String(m[1] || '').trim();
            if (valid[k]) return k;
        }
    }
    return '';
}

function stripPosBevSectionForDisplay(description) {
    var lines = String(description || '').split(/\r?\n/);
    return lines
        .filter(function (ln) {
            return !/^__pos_bev_section__:/i.test(String(ln || '').trim());
        })
        .join('\n')
        .trim();
}

function subcategoryNameToBevSectionKey(name) {
    var n = String(name || '').trim().toLowerCase();
    if (!n) return '';
    if (n === 'coffee') return 'coffee';
    if (n === 'non-coffee' || n === 'non coffee') return 'nonCoffee';
    if (n === 'frappe') return 'frappeCoffee';
    if (n === 'refreshers' || n === 'juice') return 'refreshers';
    return '';
}

function getBeverageSectionKey(item) {
    var cat = String(item.category_name || '').toLowerCase().replace(/\s+/g, ' ').trim();
    var nm = String(item.name || '').toLowerCase().replace(/\s+/g, ' ').trim();
    var both = cat + ' ' + nm;
    var subName = String(item.subcategory_name || '').trim().toLowerCase();

    function hasNonCoffeeSignal(s) {
        return (
            /\bnon[\s-]*coffee\b/.test(s) ||
            s.indexOf('noncoffee') !== -1 ||
            s.indexOf('non-coffee') !== -1 ||
            s.indexOf('non coffee') !== -1
        );
    }

    if (subName === 'frappe') {
        if (/\b(coffee|mocha|java|caramel|espresso|latte)\b/.test(nm)) return 'frappeCoffee';
        return 'frappeNonCoffee';
    }

    var fromSub = subcategoryNameToBevSectionKey(item.subcategory_name || '');
    if (fromSub) return fromSub;

    var meta = parsePosBevSectionKeyFromDesc(item.description || '');
    if (meta === 'juice') return 'refreshers';
    if (meta) return meta;

    if (/\brefresher/.test(both)) return 'refreshers';
    if (/\bjuice\b/.test(cat)) return 'refreshers';

    var frappeHit = cat.indexOf('frappe') !== -1 || nm.indexOf('frappe') !== -1;
    if (frappeHit) {
        if (hasNonCoffeeSignal(both)) return 'frappeNonCoffee';
        return 'frappeCoffee';
    }

    if (hasNonCoffeeSignal(cat) || cat === 'noncoffee' || cat === 'non coffee') return 'nonCoffee';

    if (cat.indexOf('coffee') !== -1 && !hasNonCoffeeSignal(cat)) return 'coffee';

    var genericDrink = cat === 'drinks';
    if (genericDrink) {
        if (/\b(coffee|latte|espresso|americano|cappuccino|mocha|macchiato|flat white)\b/.test(nm)) return 'coffee';
        if (/\b(juice|soda|lemonade|smoothie|shake)\b/.test(nm) && !/\b(coffee|latte|espresso)\b/.test(nm)) return 'nonCoffee';
    }

    if (/\b(coffee|latte|espresso|americano|cappuccino|mocha|macchiato)\b/.test(nm)) return 'coffee';

    return 'other';
}

function mapCategoryNameToBevPreset(name) {
    var n = String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
    if (!n) return '';
    if (n.indexOf('frappe') !== -1) return 'frappe';
    if (/\bjuice\b/.test(n) || /\brefresher/.test(n)) return 'juice';
    if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee') return 'nonCoffee';
    if (n === 'coffee' || (n.indexOf('coffee') !== -1 && n.indexOf('non') === -1)) return 'coffee';
    return '';
}

function mapCategoryNameToMealPreset(name) {
    var c = normalizeMealCategoryName(name);
    if (!c) return '';
    if (c.indexOf('snack') !== -1 || c.indexOf('starter') !== -1 || c.indexOf('nacho') !== -1) return 'snacks';
    if (c.indexOf('pastr') !== -1 || c.indexOf('toast') !== -1 || c.indexOf('dessert') !== -1) return 'pastries';
    if (c.indexOf('wing') !== -1 || c.indexOf('chicken') !== -1) return 'wings';
    if (c.indexOf('pasta') !== -1) return 'pasta';
    if (c === 'meals' || c === 'meal' || c.indexOf('rice') !== -1) return 'meal';
    return '';
}

function dynamicCategoryCircleKey(categoryId) {
    var id = parseInt(categoryId, 10);
    return id > 0 ? 'cat-' + id : '';
}

function getCustomerBevCircleKey(item) {
    var presetFromCat = mapCategoryNameToBevPreset(item && item.category_name);
    if (!presetFromCat) {
        var dyn = dynamicCategoryCircleKey(item && item.category_id);
        if (dyn) return dyn;
    }

    var staffKey = getBeverageSectionKey(item);
    var nm = String(item.name || '').toLowerCase();

    if (staffKey === 'frappeCoffee' || staffKey === 'frappeNonCoffee') return 'frappe';
    if (staffKey === 'refreshers') return 'juice';
    if (
        /\b(juice|lemonade|soda|smoothie|shake|refresher)\b/.test(nm) &&
        !/\b(coffee|latte|espresso)\b/.test(nm)
    ) {
        return 'juice';
    }
    if (staffKey === 'coffee') return 'coffee';
    if (staffKey === 'nonCoffee') return 'nonCoffee';
    if (staffKey === 'other') {
        if (/\b(juice|lemonade|soda|smoothie|shake)\b/.test(nm)) return 'juice';
        return 'nonCoffee';
    }
    return 'nonCoffee';
}

/** Same merge rule as adminStaff/staff_dashboard.html POS category chips. */
function categoryNameMergesIntoBeverages(name) {
    var n = String(name || '').trim().toLowerCase();
    if (!n) return false;
    if (n === 'drinks') return true;
    if (/\brefresher/.test(n)) return true;
    if (n.indexOf('frappe') !== -1) return true;
    if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee' || n === 'non coffee') return true;
    if (n === 'coffee') return true;
    return false;
}

function isAddonCategoryName(name) {
    var n = String(name || '').trim().toLowerCase();
    return n === 'add-ons' || n === 'addons' || n === 'add ons';
}

function isAddonCardItem(it) {
    if (!it) return false;
    if (it.is_addon_card) return true;
    return isAddonCategoryName(it.category_name);
}

function getKioskTabCategories() {
    beverageCategoryIds = [];
    var cats = menuData.categories || [];
    var tabs = [];
    var insertedBev = false;
    cats.forEach(function (c) {
        var id = parseInt(c.id, 10);
        if (categoryNameMergesIntoBeverages(c.name)) {
            if (id > 0) beverageCategoryIds.push(id);
            if (!insertedBev) {
                tabs.push({ key: BEVERAGES_MERGED_KEY, id: null, name: 'Beverages' });
                insertedBev = true;
            }
            return;
        }
        if (!(id > 0)) return;
        tabs.push({
            key: dynamicCategoryCircleKey(id),
            id: id,
            name: c.name || ('Category ' + id)
        });
    });
    if (!insertedBev && (menuData.items || []).some(isBeverageItem)) {
        tabs.unshift({ key: BEVERAGES_MERGED_KEY, id: null, name: 'Beverages' });
    }
    return tabs;
}

function getKioskTabIcon(tab) {
    if (!tab) return '';
    if (tab.key === BEVERAGES_MERGED_KEY) return 'icons_customer/icon_startup/beverage.svg';
    var n = String(tab.name || '').toLowerCase();
    if (isAddonCategoryName(n)) return '';
    if (/\bmeal|\bsnack|\bstarter|\bnacho/.test(n)) return 'icons_customer/icon_startup/meals.svg';
    if (/\bpastr|\bdessert|\bbakery/.test(n)) return 'icons_customer/icon_startup/meals.svg';
    return '';
}

function buildMenuGroupTabHtml(tab, activeKey) {
    var active = tab.key === activeKey ? ' menu-group-btn--active' : '';
    var addonTab = isAddonCategoryName(tab && tab.name) ? ' menu-group-btn--addons' : '';
    var icon = getKioskTabIcon(tab);
    var iconHtml = icon
        ? '<span class="menu-group-btn__icon-wrap"><img src="' + escapeHtmlAttr(icon) + '" alt="" class="menu-group-btn__icon"></span>'
        : '<span class="menu-group-btn__icon-wrap menu-group-btn__icon-wrap--empty" aria-hidden="true"></span>';
    return (
        '<button type="button" class="menu-group-btn' + active + addonTab + '" data-menu-group="' + escapeHtmlAttr(tab.key) + '">' +
            iconHtml +
            '<span class="menu-group-btn__label">' + escapeHtmlText(tab.name) + '</span>' +
        '</button>'
    );
}

function rebuildMenuGroupTabs(activeKey) {
    var tabs = getKioskTabCategories();
    var key = activeKey || (tabs[0] && tabs[0].key) || BEVERAGES_MERGED_KEY;
    var html = tabs.map(function (tab) {
        return buildMenuGroupTabHtml(tab, key);
    }).join('');
    document.querySelectorAll('.menu-group-switch').forEach(function (wrap) {
        wrap.innerHTML = html || (
            '<button type="button" class="menu-group-btn menu-group-btn--active" data-menu-group="beverages">' +
                '<span class="menu-group-btn__icon-wrap"><img src="icons_customer/icon_startup/beverage.svg" alt="" class="menu-group-btn__icon"></span>' +
                '<span class="menu-group-btn__label">Beverages</span>' +
            '</button>'
        );
    });
    return tabs;
}

function firstFoodTabKey() {
    var tabs = getKioskTabCategories();
    for (var i = 0; i < tabs.length; i++) {
        if (tabs[i].key !== BEVERAGES_MERGED_KEY) return tabs[i].key;
    }
    return null;
}

function parseKioskCategoryIdFromTabKey(key) {
    var m = String(key || '').match(/^cat-(\d+)$/i);
    return m ? parseInt(m[1], 10) : null;
}

function getItemsForGroup(group) {
    if (group === 'beverages') {
        if (beverageCategoryIds.length) {
            return (menuData.items || []).filter(function (it) {
                return beverageCategoryIds.indexOf(parseInt(it.category_id, 10)) !== -1;
            });
        }
        return (menuData.items || []).filter(isBeverageItem);
    }
    if (selectedKioskCategoryId) {
        return (menuData.items || []).filter(function (it) {
            return parseInt(it.category_id, 10) === selectedKioskCategoryId;
        });
    }
    return (menuData.items || []).filter(function (it) {
        return !isBeverageItem(it);
    });
}

function applyFastMovingIds(ids) {
    fastMovingItemIds = (ids || []).map(function (id) { return parseInt(id, 10) || 0; }).filter(Boolean);
}

function isFastMovingMenuItem(menuItemId) {
    return fastMovingItemIds.indexOf(parseInt(menuItemId, 10)) !== -1;
}

function refreshFastMovingBadges() {
    return fetch('api/fast_moving_public.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) return;
            applyFastMovingIds(res.menu_item_ids || []);
            renderCurrentMenuView();
        })
        .catch(function () {});
}

function renderCurrentMenuView() {
    if (screenBeverages && !screenBeverages.classList.contains('page--hidden')) {
        renderBeverageProductGrid();
        return;
    }
    if (screenMeals && !screenMeals.classList.contains('page--hidden')) {
        renderMealProductGrid();
    }
}

function renderMenuItemsInto(gridEl, items) {
    if (!gridEl) return;
    if (!items || !items.length) {
        gridEl.innerHTML = '<p class="prod-grid-empty">No items available in this category.</p>';
        return;
    }

    gridEl.innerHTML = items.map(function (it) {
        var kioskMenuPlaceholderImg = '../adminStaff/icons_admin/static_img.jpg';
        var isAvailable = it.is_available !== false;
        var rawImg = it.image_url && String(it.image_url).trim() ? String(it.image_url).trim() : '';
        var imgSrc = kioskMenuPlaceholderImg;
        if (rawImg) {
            if (/^https?:\/\//i.test(rawImg) || rawImg.indexOf('../') === 0 || rawImg.indexOf('/') === 0) {
                imgSrc = rawImg;
            } else if (rawImg.indexOf('uploads/') === 0 || rawImg.indexOf('icons_admin/') === 0) {
                imgSrc = '../adminStaff/' + rawImg;
            } else {
                imgSrc = rawImg;
            }
        }
        var imgHtml =
            '<img class="prod-card-img-el" src="' +
            escapeHtml(imgSrc) +
            '" alt="' +
            escapeHtml(it.name) +
            '">';
        var isBestSeller = isFastMovingMenuItem(it.id);
        var isAddonCard = isAddonCardItem(it);
        var bestSellerBadge = isBestSeller
            ? '<span class="prod-card-bestseller-badge">BEST SELLER</span>'
            : '';
        var addonBadge = isAddonCard
            ? '<span class="prod-card-addon-badge">ADD-ON</span>'
            : '';
        var cardMods =
            (isAvailable ? '' : ' prod-card--soldout') +
            (isBestSeller ? ' prod-card--bestseller' : '') +
            (isAddonCard ? ' prod-card--addon' : '');
        return (
            '<div class="prod-card' + cardMods + '" data-menu-id="' + it.id + '">' +
            '  <div class="prod-card-img-wrap">' + addonBadge + bestSellerBadge + imgHtml + '</div>' +
            '  <div class="prod-card-body">' +
            '    <div class="prod-card-name-row">' +
            '      <span class="prod-card-name">' + escapeHtml(it.name) + '</span>' +
            '      <span class="prod-card-price">' + formatPeso(it.price) + '</span>' +
            '    </div>' +
            (isAvailable ? '' : '    <span class="prod-card-soldout-badge">SOLD OUT</span>') +
            '    <p class="prod-card-desc">' + escapeHtml(isAddonCard ? 'Add on its own' : (stripPosBevSectionForDisplay(it.description) || '—')) + '</p>' +
            '  </div>' +
            '  <button class="prod-add-btn' + (isAddonCard ? ' prod-add-btn--addon' : '') + '" data-add-menu-id="' + it.id + '" ' + (isAvailable ? '' : 'disabled') + '>' + (isAvailable ? (isAddonCard ? 'ADD ADD-ON' : 'ADD ORDER') : 'SOLD OUT') + '</button>' +
            '</div>'
        );
    }).join('');
}

function attachAddHandlers(scopeEl, opts) {
    if (!scopeEl) return;
    opts = opts || {};
    scopeEl.querySelectorAll('button[data-add-menu-id]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (btn.disabled) return;
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt(btn.getAttribute('data-add-menu-id'), 10);
            var it = (menuData.items || []).find(function (x) { return x.id === id; });
            if (!it) return;
            if (isAddonCardItem(it)) {
                addItemToCart({
                    menu_item_id: it.id,
                    name: it.name,
                    price: it.price,
                    // price:0 so cart unit stays menu price; notes still trigger inventory deduct
                    addons: [{ name: it.name, price: 0 }],
                    temperature: null,
                    note: '',
                    removed: []
                });
                renderAllSidebars();
                return;
            }
            var quickVariants = getKioskTemperatureVariants(it, !!opts.temperatureFallback);
            var quickAddons = getAddonOptionsForItem(it.name, it.category_name || '');
            if (!quickVariants.length && !quickAddons.length) {
                addItemToCart({
                    menu_item_id: it.id,
                    name: it.name,
                    price: it.price,
                    addons: [],
                    temperature: null,
                    note: '',
                    removed: []
                });
                renderAllSidebars();
                return;
            }
            openIngredientModal(
                it,
                { addons: [], temperature: null, note: '' },
                { temperatureFallback: !!opts.temperatureFallback }
            ).then(function (sel) {
                if (sel === null) return;
                addItemToCart({
                    menu_item_id: it.id,
                    name: it.name,
                    price: it.price,
                    addons: sel.addons,
                    temperature: sel.temperature,
                    note: sel.note || '',
                    removed: []
                });
                renderAllSidebars();
            });
        });
    });
}

function getBeverageSearchQuery() {
    var input = screenBeverages.querySelector('.menu-search-input');
    return input ? String(input.value || '').toLowerCase().trim() : '';
}

function itemMatchesDrinkTempFilter(item) {
    if (selectedDrinkTemp === 'hot') return !!item.serve_hot;
    if (selectedDrinkTemp === 'cold') return !!item.serve_cold;
    return true;
}

function updateBevTempFiltersUI() {
    var wrap = document.getElementById('bev-temp-filters');
    if (!wrap) return;
    var show = selectedMenuGroup === 'beverages' && wrap.closest('#screen-beverages');
    wrap.hidden = !show;
    wrap.querySelectorAll('[data-drink-temp]').forEach(function (chip) {
        var key = chip.getAttribute('data-drink-temp') || 'all';
        chip.classList.toggle('bev-temp-chip--active', key === selectedDrinkTemp);
    });
}

function renderBeverageProductGrid() {
    var grid = document.getElementById('prod-grid');
    if (!grid) return;
    var q = getBeverageSearchQuery();
    var base = getItemsForGroup('beverages').filter(itemMatchesDrinkTempFilter);
    var byCat = base.filter(function (it) {
        return itemMatchesSideSubKey(it, selectedBevCategory);
    });
    var filtered = !q
        ? byCat
        : byCat.filter(function (it) {
              return (
                  String(it.name || '').toLowerCase().indexOf(q) !== -1 ||
                  String(it.description || '').toLowerCase().indexOf(q) !== -1
              );
          });
    renderMenuItemsInto(grid, filtered);
    attachAddHandlers(grid, { temperatureFallback: true });
}

function setSelectedBevCategory(cat) {
    selectedBevCategory = cat || 'all';
    var wrap = document.getElementById('bev-circles');
    if (wrap) {
        wrap.querySelectorAll('.menu-product').forEach(function (p) {
            var isSel = p.getAttribute('data-bev-category') === selectedBevCategory;
            p.classList.toggle('menu-product--selected', isSel);
        });
    }
    updateBevTempFiltersUI();
    renderBeverageProductGrid();
}

function setSelectedDrinkTemp(temp) {
    selectedDrinkTemp = temp || 'all';
    updateBevTempFiltersUI();
    renderBeverageProductGrid();
}

function wireBevTempFilters() {
    var wrap = document.getElementById('bev-temp-filters');
    if (!wrap) return;
    wrap.querySelectorAll('[data-drink-temp]').forEach(function (chip) {
        chip.addEventListener('click', function (e) {
            e.preventDefault();
            setSelectedDrinkTemp(chip.getAttribute('data-drink-temp') || 'all');
        });
    });
}

function wireBevCategoryCircles() {
    var wrap = document.getElementById('bev-circles');
    if (!wrap || wrap.dataset.wired === '1') return;
    wrap.dataset.wired = '1';

    wrap.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-bev-category]') : null;
        if (!el || !wrap.contains(el)) return;
        e.preventDefault();
        var cat = el.getAttribute('data-bev-category');
        if (cat) setSelectedBevCategory(cat);
    });
    wrap.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var el = e.target && e.target.closest ? e.target.closest('[data-bev-category]') : null;
        if (!el || !wrap.contains(el)) return;
        e.preventDefault();
        var cat = el.getAttribute('data-bev-category');
        if (cat) setSelectedBevCategory(cat);
    });
}

function normalizeMealCategoryName(name) {
    return String(name || '').trim().toLowerCase();
}

function inferMealCategoryKey(item) {
    var preset = mapCategoryNameToMealPreset(item && item.category_name);
    if (preset) return preset;
    var dyn = dynamicCategoryCircleKey(item && item.category_id);
    if (dyn) return dyn;
    return 'other';
}

function mealCategoryKeyFromCircleToken(token) {
    var t = String(token || '').trim();
    if (/^cat-\d+$/i.test(t)) return t.toLowerCase();
    var low = t.toLowerCase();
    if (low === 'snacks' || low === 'pastries' || low === 'meal' || low === 'wings' || low === 'pasta') return low;
    return '';
}

function escapeHtmlAttr(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function escapeHtmlText(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function categoryBelongsToGroup(cat, group) {
    var id = parseInt(cat && cat.id, 10);
    var items = (menuData.items || []).filter(function (it) {
        return parseInt(it.category_id, 10) === id;
    });
    if (items.length) {
        var bevCount = 0;
        for (var i = 0; i < items.length; i++) {
            if (isBeverageItem(items[i])) bevCount++;
        }
        if (group === 'beverages') return bevCount > 0;
        return bevCount < items.length || bevCount === 0;
    }
    var isBev = isBeverageCategoryName(cat && cat.name);
    return group === 'beverages' ? isBev : !isBev;
}

function getCategoriesForGroup(group) {
    if (group === 'beverages') {
        if (!beverageCategoryIds.length) getKioskTabCategories();
        return (menuData.categories || []).filter(function (cat) {
            var id = parseInt(cat && cat.id, 10);
            return beverageCategoryIds.indexOf(id) !== -1 || categoryNameMergesIntoBeverages(cat && cat.name);
        });
    }
    return (menuData.categories || []).filter(function (cat) {
        return categoryBelongsToGroup(cat, group);
    });
}

function subcategoryCircleKey(subId) {
    var id = parseInt(subId, 10);
    return id > 0 ? 'sub-' + id : '';
}

function parseSubcategoryIdFromCircleKey(key) {
    var m = String(key || '').match(/^sub-(\d+)$/i);
    return m ? parseInt(m[1], 10) : null;
}

function getDbSubcategoriesForCategoryIds(categoryIds) {
    var idSet = {};
    (categoryIds || []).forEach(function (id) {
        var n = parseInt(id, 10);
        if (n > 0) idSet[n] = true;
    });
    return (menuData.subcategories || []).filter(function (s) {
        var name = String(s && s.name || '').trim().toLowerCase();
        if (name === 'hot' || name === 'cold') return false;
        return idSet[parseInt(s.category_id, 10)];
    }).sort(function (a, b) {
        var ao = parseInt(a.display_order, 10) || 0;
        var bo = parseInt(b.display_order, 10) || 0;
        if (ao !== bo) return ao - bo;
        return String(a.name || '').localeCompare(String(b.name || ''));
    });
}

function getSideCircleIconForSubName(name) {
    var n = String(name || '').trim().toLowerCase();
    if (!n) return { icon: '', iconClass: '' };
    if (n.indexOf('frappe') !== -1) {
        return { icon: 'icons_customer/icon_customer_meals/bev3.svg', iconClass: 'menu-product-icon--bev3' };
    }
    if (/\bjuice\b/.test(n) || /\brefresher/.test(n)) {
        return { icon: 'icons_customer/icon_customer_meals/bev4.svg', iconClass: 'menu-product-icon--bev4' };
    }
    if (/\bnon[\s-]*coffee\b/.test(n) || n === 'noncoffee') {
        return { icon: 'icons_customer/icon_customer_meals/bev2.svg', iconClass: 'menu-product-icon--bev2' };
    }
    if (n === 'coffee' || (n.indexOf('coffee') !== -1 && n.indexOf('non') === -1)) {
        return { icon: 'icons_customer/icon_customer_meals/bev1.svg', iconClass: 'menu-product-icon--bev1' };
    }
    if (n.indexOf('snack') !== -1 || n.indexOf('starter') !== -1 || n.indexOf('nacho') !== -1) {
        return { icon: 'icons_customer/icon_customer_meals/nacho.svg', iconClass: 'menu-product-icon--meal-nacho' };
    }
    if (n.indexOf('pastr') !== -1 || n.indexOf('toast') !== -1 || n.indexOf('dessert') !== -1) {
        return { icon: 'icons_customer/icon_customer_meals/pastries.svg', iconClass: 'menu-product-icon--meal-pastries' };
    }
    if (n.indexOf('wing') !== -1 || n.indexOf('chicken') !== -1) {
        return { icon: 'icons_customer/icon_customer_meals/chicken.svg', iconClass: 'menu-product-icon--meal-chicken' };
    }
    if (n.indexOf('pasta') !== -1) {
        return { icon: 'icons_customer/icon_customer_meals/pasta.svg', iconClass: 'menu-product-icon--meal-pasta' };
    }
    if (n === 'meals' || n === 'meal' || n.indexOf('rice') !== -1) {
        return { icon: 'icons_customer/icon_customer_meals/rice_meal.svg', iconClass: 'menu-product-icon--meal-rice' };
    }
    return { icon: '', iconClass: '' };
}

function itemMatchesSideSubKey(item, sideKey) {
    var key = String(sideKey || '');
    if (!key || key === 'all') return true;
    if (key === '_other') return !(parseInt(item && item.subcategory_id, 10) > 0);
    var sid = parseSubcategoryIdFromCircleKey(key);
    if (sid) return parseInt(item && item.subcategory_id, 10) === sid;
    return true;
}

function buildSideSubSections(items, categoryIds) {
    var subs = getDbSubcategoriesForCategoryIds(categoryIds);
    var buckets = {};
    var sections = [];
    subs.forEach(function (sub) {
        var key = subcategoryCircleKey(sub.id);
        if (!key) return;
        buckets[key] = [];
        sections.push({
            key: key,
            label: sub.name || 'Subcategory',
            subId: parseInt(sub.id, 10)
        });
    });
    buckets._other = [];
    (items || []).forEach(function (item) {
        var sid = parseInt(item && item.subcategory_id, 10);
        var key = sid > 0 ? subcategoryCircleKey(sid) : '';
        if (key && buckets[key]) buckets[key].push(item);
        else buckets._other.push(item);
    });
    sections = sections.filter(function (sec) {
        return (buckets[sec.key] || []).length > 0;
    });
    if (buckets._other.length && sections.length) {
        sections.push({ key: '_other', label: 'Other', subId: null });
    }
    return { buckets: buckets, sections: sections };
}

function buildBevCircleHtml(key, label, icon, iconClass, selected) {
    var sel = selected ? ' menu-product--selected' : '';
    var iconHtml = icon
        ? '<img class="menu-product-icon ' + escapeHtmlAttr(iconClass || '') + '" src="' + escapeHtmlAttr(icon) + '" alt="">'
        : '';
    return (
        '<div class="menu-product' + sel + '" data-bev-category="' + escapeHtmlAttr(key) + '" role="button" tabindex="0">' +
            '<div class="menu-product-circle' + (icon ? '' : ' menu-product-circle--empty') + '">' + iconHtml + '</div>' +
            '<span class="menu-product-name">' + escapeHtmlText(label) + '</span>' +
        '</div>'
    );
}

function buildMealCircleHtml(key, label, icon, iconClass, selected) {
    var sel = selected ? ' menu-product--selected' : '';
    var iconHtml = icon
        ? '<img class="menu-product-icon ' + escapeHtmlAttr(iconClass || '') + '" src="' + escapeHtmlAttr(icon) + '" alt="' + escapeHtmlAttr(label) + '">'
        : '';
    return (
        '<div class="menu-product' + sel + '" data-meal="' + escapeHtmlAttr(key) + '" role="button" tabindex="0">' +
            '<div class="menu-product-circle menu-product-circle--meal' + (icon ? '' : ' menu-product-circle--empty') + '">' + iconHtml + '</div>' +
            '<span class="menu-product-circle-label">' + escapeHtmlText(label) + '</span>' +
        '</div>'
    );
}

function setSideCirclesVisible(isBeverage, visible) {
    var bevWrap = document.getElementById('bev-circles');
    var mealWrap = document.getElementById('meal-circles');
    var bevLayout = screenBeverages ? screenBeverages.querySelector('.meals-layout') : null;
    var mealLayout = screenMeals ? screenMeals.querySelector('.meals-layout') : null;
    if (isBeverage) {
        if (bevWrap) {
            bevWrap.hidden = !visible;
            if (visible) bevWrap.classList.remove('menu-choice-hidden');
            else bevWrap.classList.add('menu-choice-hidden');
        }
        if (bevLayout) bevLayout.classList.toggle('meals-layout--category-tab', !visible);
    } else {
        if (mealWrap) mealWrap.hidden = !visible;
        if (mealLayout) mealLayout.classList.toggle('meals-layout--category-tab', !visible);
    }
}

function rebuildBevCategoryCircles(selectedKey) {
    var wrap = document.getElementById('bev-circles');
    if (!wrap) return '';
    if (!beverageCategoryIds.length) getKioskTabCategories();
    var items = getItemsForGroup('beverages');
    var built = buildSideSubSections(items, beverageCategoryIds);
    var html = '';
    var i;
    for (i = 0; i < built.sections.length; i++) {
        var sec = built.sections[i];
        var ic = getSideCircleIconForSubName(sec.label);
        html += buildBevCircleHtml(sec.key, sec.label, ic.icon, ic.iconClass, selectedKey === sec.key);
    }
    wrap.innerHTML = html;
    setSideCirclesVisible(true, built.sections.length > 0);
    return built.sections.length ? (selectedKey && built.buckets[selectedKey] && built.buckets[selectedKey].length ? selectedKey : built.sections[0].key) : 'all';
}

function rebuildMealCategoryCircles(selectedKey) {
    var wrap = document.getElementById('meal-circles');
    if (!wrap) return '';
    var catIds = selectedKioskCategoryId ? [selectedKioskCategoryId] : [];
    var items = getItemsForGroup('meals');
    var built = buildSideSubSections(items, catIds);
    var html = '';
    var i;
    for (i = 0; i < built.sections.length; i++) {
        var sec = built.sections[i];
        var ic = getSideCircleIconForSubName(sec.label);
        html += buildMealCircleHtml(sec.key, sec.label, ic.icon, ic.iconClass, selectedKey === sec.key);
    }
    wrap.innerHTML = html;
    setSideCirclesVisible(false, built.sections.length > 0);
    return built.sections.length ? (selectedKey && built.buckets[selectedKey] && built.buckets[selectedKey].length ? selectedKey : built.sections[0].key) : 'all';
}

function renderMealProductGrid() {
    var grid = document.getElementById('meal-prod-grid');
    if (!grid) return;
    var input = screenMeals.querySelector('.menu-search-input');
    var q = input ? String(input.value || '').toLowerCase().trim() : '';
    var base = getItemsForGroup('meals');
    var byCat = base.filter(function (it) {
        return itemMatchesSideSubKey(it, selectedMealCategory);
    });
    var filtered = !q
        ? byCat
        : byCat.filter(function (it) {
              return (
                  String(it.name || '').toLowerCase().indexOf(q) !== -1 ||
                  String(it.description || '').toLowerCase().indexOf(q) !== -1
              );
          });
    renderMenuItemsInto(grid, filtered);
    attachAddHandlers(grid);
}

function setSelectedMealCategory(cat) {
    selectedMealCategory = cat || 'all';
    var wrap = document.getElementById('meal-circles');
    if (wrap) {
        wrap.querySelectorAll('[data-meal]').forEach(function (el) {
            var key = el.getAttribute('data-meal') || '';
            el.classList.toggle('menu-product--selected', key === selectedMealCategory);
        });
    }
    renderMealProductGrid();
}

function wireMealCategoryCircles() {
    var wrap = document.getElementById('meal-circles');
    if (!wrap || wrap.dataset.wired === '1') return;
    wrap.dataset.wired = '1';

    wrap.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-meal]') : null;
        if (!el || !wrap.contains(el)) return;
        e.preventDefault();
        var key = el.getAttribute('data-meal');
        if (key) setSelectedMealCategory(key);
    });
    wrap.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var el = e.target && e.target.closest ? e.target.closest('[data-meal]') : null;
        if (!el || !wrap.contains(el)) return;
        e.preventDefault();
        var key = el.getAttribute('data-meal');
        if (key) setSelectedMealCategory(key);
    });
}

function showMenuForGroup(group) {
    var tabs = rebuildMenuGroupTabs(group);
    var tabKey = group;
    if (tabKey === 'meals') {
        tabKey = firstFoodTabKey() || BEVERAGES_MERGED_KEY;
    }
    if (!tabKey) tabKey = BEVERAGES_MERGED_KEY;

    var known = tabs.some(function (t) { return t.key === tabKey; });
    if (!known && tabKey !== BEVERAGES_MERGED_KEY) {
        tabKey = (tabs[0] && tabs[0].key) || BEVERAGES_MERGED_KEY;
        rebuildMenuGroupTabs(tabKey);
    }

    selectedMenuGroup = tabKey;
    var isBeverage = tabKey === BEVERAGES_MERGED_KEY;
    var catId = isBeverage ? null : parseKioskCategoryIdFromTabKey(tabKey);
    selectedKioskCategoryId = catId;
    var page = isBeverage ? screenBeverages : screenMeals;

    var heading = page.querySelector('.menu-grid-heading');
    var sub = page.querySelector('.menu-grid-sub');
    var activeTab = tabs.filter(function (t) { return t.key === tabKey; })[0];
    if (heading) heading.textContent = activeTab ? activeTab.name : (isBeverage ? 'Beverages' : 'Menu');
    if (sub) sub.textContent = 'Select items then proceed to checkout';

    var grid = isBeverage ? document.getElementById('prod-grid') : document.getElementById('meal-prod-grid');
    if (grid) grid.classList.add('prod-grid--visible');
    if (isBeverage) {
        selectedDrinkTemp = 'all';
        var bevKey = rebuildBevCategoryCircles(selectedBevCategory);
        setSelectedBevCategory(bevKey);
    } else {
        var mealKey = rebuildMealCategoryCircles(selectedMealCategory);
        setSelectedMealCategory(mealKey);
    }

    document.querySelectorAll('.menu-group-btn').forEach(function (btn) {
        var g = btn.getAttribute('data-menu-group');
        btn.classList.toggle('menu-group-btn--active', g === tabKey);
    });

    showScreen(page);
    renderAllSidebars();
}

function openMenuSelection() {
    // Land on Beverages with products visible (not a blank menu shell).
    showMenuForGroup(BEVERAGES_MERGED_KEY);
}

function wireMenuGroupButtons() {
    document.querySelectorAll('.menu-group-switch').forEach(function (wrap) {
        if (wrap.dataset.wired === '1') return;
        wrap.dataset.wired = '1';
        wrap.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-menu-group]') : null;
            if (!btn || !wrap.contains(btn)) return;
            e.preventDefault();
            var group = btn.getAttribute('data-menu-group');
            if (group) showMenuForGroup(group);
        });
    });
}

function wireSearch() {
    [screenBeverages, screenMeals].forEach(function (page) {
        var input = page.querySelector('.menu-search-input');
        if (!input) return;
        input.addEventListener('input', function () {
            if (!selectedMenuGroup) return;
            var isBeverage = page === screenBeverages;
            if (isBeverage) {
                renderBeverageProductGrid();
                return;
            }
            renderMealProductGrid();
        });
    });
}

function wireFixedCategoryButtons() {
    // Uses the existing 2 cards in customer.html (Meals vs Beverages)
    document.querySelectorAll('#screen-category .ro-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var title = card.querySelector('.ro-card-title');
            var text = title ? title.textContent.trim().toLowerCase() : '';
            if (text.indexOf('beverage') !== -1) {
                showMenuForGroup(BEVERAGES_MERGED_KEY);
            } else {
                showMenuForGroup(firstFoodTabKey() || 'meals');
            }
        });
    });
}

function loadMenuData() {
    return Promise.all([
        fetch('api/menu_public.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); }),
        loadCatalogAddons()
    ])
        .then(function (results) {
            var res = results[0];
            if (!res || !res.success) throw new Error((res && res.error) || 'Failed to load menu');
            menuData.categories = res.categories || [];
            menuData.subcategories = res.subcategories || [];
            menuData.items = res.items || [];
            applyFastMovingIds(res.fast_moving_item_ids || []);
            rebuildMenuGroupTabs(selectedMenuGroup || BEVERAGES_MERGED_KEY);
            wireSearch();
            if (selectedMenuGroup) {
                showMenuForGroup(selectedMenuGroup);
            } else if (screenBeverages && !screenBeverages.classList.contains('page--hidden')) {
                showMenuForGroup(BEVERAGES_MERGED_KEY);
            }
        })
        .catch(function () {
            menuData = { categories: [], items: [], subcategories: [] };
            beverageCategoryIds = [];
            fastMovingItemIds = [];
            if (selectedMenuGroup) {
                showMenuForGroup(selectedMenuGroup);
            }
        });
}

// Screen 1 → Menu flow (Pick Up)
document.getElementById('btn-remote-order').addEventListener('click', function (e) {
    e.preventDefault();
    selectedServiceType = 'onsite';
    selectedOrderType = 'Pick Up';
    setActiveFulfillmentFromOrderType();
    updateOrderTypeLabels();
    previousScreen = screenService;
    openMenuSelection();
});

// Screen 1 → Screen 3 (Onsite Order)
document.getElementById('btn-onsite-order').addEventListener('click', function (e) {
    e.preventDefault();
    selectedServiceType = 'onsite';
    showScreen(screenOnsite);
});

// Screen 2 Back → Screen 1
document.getElementById('btn-remote-back').addEventListener('click', function () {
    showScreen(screenService);
});

// Screen 3 Back → Screen 1
document.getElementById('btn-onsite-back').addEventListener('click', function () {
    showScreen(screenService);
});

// Screen 2 & 3: clicking any card → Menu
document.querySelectorAll('#screen-remote .ro-card, #screen-onsite .ro-card').forEach(function (card) {
    card.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        previousScreen = card.closest('.page');

        var typeTitle = card.querySelector('.ro-card-title');
        if (typeTitle) {
            selectedOrderType = typeTitle.textContent.trim();
            setActiveFulfillmentFromOrderType();
            updateOrderTypeLabels();
        }

        openMenuSelection();
    });
});

// Screen 4 Back → whichever screen 2/3 we came from
var categoryBackBtn = document.getElementById('btn-category-back');
if (categoryBackBtn) {
    categoryBackBtn.addEventListener('click', function () {
        showScreen(previousScreen || screenService);
    });
}

// Menu screens Back → previous selection screen
document.getElementById('btn-beverages-back').addEventListener('click', function () {
    showScreen(previousScreen || screenService);
});
document.getElementById('btn-meals-back').addEventListener('click', function () {
    showScreen(previousScreen || screenService);
});

// Screen 4 has fixed 2 options (Meals vs Beverages).

// Payment method toggle scoped to each order sidebar
document.querySelectorAll('.payment-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (btn.hasAttribute('disabled')) return;
        var group = btn.closest('.payment-btns');
        if (!group) return;
        group.querySelectorAll('.payment-btn').forEach(function (b) {
            b.classList.remove('payment-btn--active');
        });
        btn.classList.add('payment-btn--active');
    });
});

document.querySelectorAll('.order-type-toggle-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        toggleOrderTypeDineTake();
    });
});

document.querySelectorAll('[data-fulfillment-box]').forEach(function (box) {
    box.setAttribute('role', 'button');
    box.setAttribute('tabindex', '0');
    box.addEventListener('click', function (e) {
        if (e.target.closest('button, a, input, select, textarea')) return;
        var key = box.getAttribute('data-fulfillment-box');
        if (key === 'dine_in' || key === 'take_out') {
            setActiveFulfillmentBucket(key);
        }
    });
    box.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        var key = box.getAttribute('data-fulfillment-box');
        if (key === 'dine_in' || key === 'take_out') {
            setActiveFulfillmentBucket(key);
        }
    });
});

// Menu items are rendered dynamically; add handlers are attached after render.

// Cart interactions (qty + customization edit)
menuPages.forEach(function (page) {
    var discountTypeSelect = page.querySelector('.discount-type-select');
    var discountNameInput = page.querySelector('.discount-name-input');
    var discountIdInput = page.querySelector('.discount-id-input');
    if (discountTypeSelect) {
        discountTypeSelect.addEventListener('change', function () {
            orderDiscount.type = normalizeDiscountType(discountTypeSelect.value);
            if (orderDiscount.type === 'none') {
                orderDiscount.customer_name = '';
                orderDiscount.id_number = '';
            }
            renderAllSidebars();
        });
    }
    if (discountNameInput) {
        discountNameInput.addEventListener('input', function () {
            orderDiscount.customer_name = discountNameInput.value;
        });
    }
    if (discountIdInput) {
        discountIdInput.addEventListener('input', function () {
            orderDiscount.id_number = discountIdInput.value;
        });
    }

    var sidebarInner = page.querySelector('.sidebar-inner');
    if (!sidebarInner) return;

    sidebarInner.addEventListener('click', function (e) {
        var target = e.target.closest('button[data-action]');
        if (!target) return;

        var bucket = target.getAttribute('data-bucket') || 'dine_in';
        var index = parseInt(target.getAttribute('data-index'), 10);
        var cart = getFulfillmentCart(bucket);
        if (isNaN(index) || !cart[index]) return;

        var action = target.getAttribute('data-action');
        if (action === 'inc') {
            cart[index].qty += 1;
        } else if (action === 'dec') {
            cart[index].qty -= 1;
            if (cart[index].qty <= 0) {
                cart.splice(index, 1);
            }
        } else if (action === 'edit-mods') {
            var row = cart[index];
            var mi =
                getMenuItemById(row.menu_item_id) ||
                ({ name: row.name, price: row.price, description: '', category_name: '' });
            var tempFb = categoryNameMergesIntoCustomerBeverages(mi.category_name || '');
            openIngredientModal(
                mi,
                { addons: row.addons || [], temperature: row.temperature || null, note: row.note || '' },
                { temperatureFallback: tempFb }
            ).then(function (sel) {
                if (sel === null) return;
                cart[index].addons = sel.addons;
                cart[index].temperature = sel.temperature;
                cart[index].note = sel.note || '';
                cart[index].price = computeCartLineUnit(row.menu_item_id, sel.temperature, sel.addons);
                renderAllSidebars();
            });
            return;
        }

        renderAllSidebars();
    });
});

// Checkout modals (CASH / GCASH)
var cashCheckoutModal = document.getElementById('cash-checkout-modal');
var cashModalConfirmBtn = document.getElementById('cash-modal-confirm');
var cashModalCancelBtn = document.getElementById('cash-modal-cancel');
var cashModalSummary = document.getElementById('cash-modal-summary');
var gcashCheckoutModal = document.getElementById('gcash-checkout-modal');
var gcashModalConfirmBtn = document.getElementById('gcash-modal-confirm');
var gcashModalCancelBtn = document.getElementById('gcash-modal-cancel');
var gcashModalSummary = document.getElementById('gcash-modal-summary');
var gcashRefInput = document.getElementById('gcash-ref-input');
var pickupLaterTimeInput = document.getElementById('pickup-later-time-input');
var pickupOnlyModalMessages = document.querySelectorAll('.cash-modal-message--pickup-only');
var pickupAlarmPickerEl = document.getElementById('pickup-alarm-picker');
var pickupAlarmState = { hour: 6, minute: 30, ampm: 'PM', wheelsBuilt: false, snapTimer: null };

function padPickupMinute(n) {
    return n < 10 ? '0' + n : String(n);
}

function formatPickupAlarmTime(hour, minute, ampm) {
    return hour + ':' + padPickupMinute(minute) + ' ' + ampm;
}

function getDefaultPickupAlarmTime() {
    var now = new Date();
    now.setMinutes(now.getMinutes() + 20);
    var h24 = now.getHours();
    var minute = now.getMinutes();
    var ampm = h24 >= 12 ? 'PM' : 'AM';
    var hour = h24 % 12;
    if (hour === 0) hour = 12;
    return { hour: hour, minute: minute, ampm: ampm };
}

function syncPickupAlarmHiddenInput() {
    if (!pickupLaterTimeInput) return;
    pickupLaterTimeInput.value = formatPickupAlarmTime(
        pickupAlarmState.hour,
        pickupAlarmState.minute,
        pickupAlarmState.ampm
    );
}

function buildPickupAlarmWheel(wheelEl, type) {
    if (!wheelEl) return;
    var options = [];
    var h;
    var mi;
    if (type === 'hour') {
        for (h = 1; h <= 12; h++) {
            options.push({ value: String(h), label: String(h) });
        }
    } else if (type === 'minute') {
        for (mi = 0; mi < 60; mi++) {
            options.push({ value: String(mi), label: padPickupMinute(mi) });
        }
    } else {
        options = [{ value: 'AM', label: 'AM' }, { value: 'PM', label: 'PM' }];
    }
    var html = '<div class="pickup-alarm-picker__pad" aria-hidden="true"></div>';
    options.forEach(function (opt) {
        html +=
            '<button type="button" class="pickup-alarm-picker__option" data-value="' +
            opt.value +
            '">' +
            opt.label +
            '</button>';
    });
    html += '<div class="pickup-alarm-picker__pad" aria-hidden="true"></div>';
    wheelEl.innerHTML = html;
}

function updatePickupAlarmActiveStates() {
    if (!pickupAlarmPickerEl) return;
    pickupAlarmPickerEl.querySelectorAll('.pickup-alarm-picker__wheel').forEach(function (wheel) {
        var type = wheel.getAttribute('data-wheel');
        var activeVal =
            type === 'hour'
                ? String(pickupAlarmState.hour)
                : type === 'minute'
                  ? String(pickupAlarmState.minute)
                  : pickupAlarmState.ampm;
        wheel.querySelectorAll('.pickup-alarm-picker__option').forEach(function (btn) {
            var isActive = btn.getAttribute('data-value') === activeVal;
            btn.classList.toggle('is-active', isActive);
            if (isActive) btn.setAttribute('aria-selected', 'true');
            else btn.removeAttribute('aria-selected');
        });
    });
}

function scrollPickupAlarmWheelToValue(wheelEl, value, behavior) {
    if (!wheelEl) return;
    var btn = wheelEl.querySelector('.pickup-alarm-picker__option[data-value="' + value + '"]');
    if (!btn) return;
    btn.scrollIntoView({ block: 'center', behavior: behavior || 'auto' });
}

function setPickupAlarmSelection(hour, minute, ampm, behavior) {
    pickupAlarmState.hour = hour;
    pickupAlarmState.minute = minute;
    pickupAlarmState.ampm = ampm;
    syncPickupAlarmHiddenInput();
    updatePickupAlarmActiveStates();
    if (!pickupAlarmPickerEl) return;
    scrollPickupAlarmWheelToValue(
        pickupAlarmPickerEl.querySelector('.pickup-alarm-picker__wheel[data-wheel="hour"]'),
        String(hour),
        behavior
    );
    scrollPickupAlarmWheelToValue(
        pickupAlarmPickerEl.querySelector('.pickup-alarm-picker__wheel[data-wheel="minute"]'),
        String(minute),
        behavior
    );
    scrollPickupAlarmWheelToValue(
        pickupAlarmPickerEl.querySelector('.pickup-alarm-picker__wheel[data-wheel="ampm"]'),
        ampm,
        behavior
    );
}

function snapPickupAlarmWheel(wheelEl) {
    if (!wheelEl) return;
    var type = wheelEl.getAttribute('data-wheel');
    var centerY = wheelEl.getBoundingClientRect().top + wheelEl.clientHeight / 2;
    var bestBtn = null;
    var bestDist = Infinity;
    wheelEl.querySelectorAll('.pickup-alarm-picker__option').forEach(function (btn) {
        var rect = btn.getBoundingClientRect();
        var mid = rect.top + rect.height / 2;
        var dist = Math.abs(mid - centerY);
        if (dist < bestDist) {
            bestDist = dist;
            bestBtn = btn;
        }
    });
    if (!bestBtn) return;
    bestBtn.scrollIntoView({ block: 'center', behavior: 'smooth' });
    var raw = bestBtn.getAttribute('data-value') || '';
    if (type === 'hour') pickupAlarmState.hour = parseInt(raw, 10) || 12;
    else if (type === 'minute') pickupAlarmState.minute = parseInt(raw, 10) || 0;
    else pickupAlarmState.ampm = raw === 'AM' ? 'AM' : 'PM';
    syncPickupAlarmHiddenInput();
    updatePickupAlarmActiveStates();
}

function initPickupAlarmPicker() {
    if (!pickupAlarmPickerEl || pickupAlarmState.wheelsBuilt) return;
    ['hour', 'minute', 'ampm'].forEach(function (type) {
        buildPickupAlarmWheel(
            pickupAlarmPickerEl.querySelector('.pickup-alarm-picker__wheel[data-wheel="' + type + '"]'),
            type
        );
    });
    pickupAlarmPickerEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.pickup-alarm-picker__option');
        if (!btn || !pickupAlarmPickerEl.contains(btn)) return;
        var wheel = btn.closest('.pickup-alarm-picker__wheel');
        if (!wheel) return;
        btn.scrollIntoView({ block: 'center', behavior: 'smooth' });
        setTimeout(function () {
            snapPickupAlarmWheel(wheel);
        }, 180);
    });
    pickupAlarmPickerEl.querySelectorAll('.pickup-alarm-picker__wheel').forEach(function (wheel) {
        wheel.addEventListener('scroll', function () {
            if (pickupAlarmState.snapTimer) clearTimeout(pickupAlarmState.snapTimer);
            pickupAlarmState.snapTimer = setTimeout(function () {
                snapPickupAlarmWheel(wheel);
            }, 120);
        });
    });
    pickupAlarmState.wheelsBuilt = true;
}

function resetPickupAlarmPicker() {
    initPickupAlarmPicker();
    var defaults = getDefaultPickupAlarmTime();
    setPickupAlarmSelection(defaults.hour, defaults.minute, defaults.ampm, 'auto');
}
var gcashNumberDisplay = document.getElementById('gcash-number-display');
var gcashNameDisplay = document.getElementById('gcash-name-display');
var gcashQrImg = document.getElementById('gcash-qr-img');
var gcashCopyNumberBtn = document.getElementById('gcash-copy-number-btn');
var ingredientModal = document.getElementById('ingredient-modal');
var ingredientModalCancelBtn = document.getElementById('ingredient-modal-cancel');
var ingredientModalConfirmBtn = document.getElementById('ingredient-modal-confirm');

var gcashKioskConfigLoaded = false;

var orderReceiptModal = document.getElementById('order-receipt-modal');
var orderReceiptNumber = document.getElementById('order-receipt-number');
var orderReceiptQrCanvas = document.getElementById('order-receipt-qr');
var orderReceiptViewBtn = document.getElementById('order-receipt-view-btn');
var orderReceiptDoneBtn = document.getElementById('order-receipt-done');
var lastReceiptUrl = '';

function buildReceiptUrl(token) {
    var dir = window.location.href.replace(/[^/]+$/, '');
    return dir + 'receipt.html?t=' + encodeURIComponent(token);
}

function showPaymentSuccessToast(onDone) {
    var toast = document.getElementById('payment-success-toast');
    var bar = document.getElementById('payment-success-toast-bar');
    var DURATION_MS = 1500;
    if (!toast) {
        if (typeof onDone === 'function') onDone();
        return;
    }
    if (showPaymentSuccessToast._timer) {
        clearTimeout(showPaymentSuccessToast._timer);
        showPaymentSuccessToast._timer = null;
    }
    toast.hidden = false;
    toast.setAttribute('aria-hidden', 'false');
    toast.classList.add('is-visible');
    if (bar) {
        bar.style.transition = 'none';
        bar.style.width = '100%';
        void bar.offsetWidth;
        bar.style.transition = 'width ' + DURATION_MS + 'ms linear';
        bar.style.width = '0%';
    }
    showPaymentSuccessToast._timer = setTimeout(function () {
        showPaymentSuccessToast._timer = null;
        toast.classList.remove('is-visible');
        toast.setAttribute('aria-hidden', 'true');
        toast.hidden = true;
        if (bar) {
            bar.style.transition = 'none';
            bar.style.width = '100%';
        }
        if (typeof onDone === 'function') onDone();
    }, DURATION_MS);
}

function openOrderReceiptModal(res) {
    var token = res && res.receipt_token;
    if (!token) {
        window.alert(
            'Order sent! Order #: ' + (res && res.order_number) + '\nStatus: Pending staff confirmation.'
        );
        return;
    }
    lastReceiptUrl = buildReceiptUrl(token);
    if (orderReceiptNumber) {
        orderReceiptNumber.textContent = 'Order #' + (res.order_number || '');
    }
    if (orderReceiptQrCanvas && typeof QRCode !== 'undefined') {
        QRCode.toCanvas(
            orderReceiptQrCanvas,
            lastReceiptUrl,
            { width: 200, margin: 1, color: { dark: '#120e0a', light: '#ffffff' } },
            function (err) {
                if (err) console.warn('Receipt QR render failed', err);
            }
        );
    }
    if (orderReceiptModal) {
        orderReceiptModal.classList.add('cash-modal-overlay--open');
        orderReceiptModal.setAttribute('aria-hidden', 'false');
    }
}

function closeOrderReceiptModal() {
    if (orderReceiptModal) {
        orderReceiptModal.classList.remove('cash-modal-overlay--open');
        orderReceiptModal.setAttribute('aria-hidden', 'true');
    }
    lastReceiptUrl = '';
}

function applyGcashKioskConfig(cfg) {
    if (!cfg) return;
    if (gcashNumberDisplay) gcashNumberDisplay.textContent = String(cfg.gcash_number || '09XXXXXXXXXX');
    if (gcashNameDisplay) {
        var gcashName = String(cfg.gcash_name || '').trim();
        gcashNameDisplay.textContent = gcashName || '***** ***';
    }
    if (!gcashQrImg) return;
    var dataUrl = cfg.qr_data_url;
    if (dataUrl) {
        gcashQrImg.src = dataUrl;
        gcashQrImg.style.display = 'block';
    } else {
        gcashQrImg.src = '';
        gcashQrImg.style.display = 'none';
    }
}

function loadGcashKioskConfig(force) {
    if (gcashKioskConfigLoaded && !force) return Promise.resolve();
    gcashKioskConfigLoaded = true;
    return fetch('api/gcash_public.php', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.success) applyGcashKioskConfig(d);
        })
        .catch(function () {
            // Keep existing fallback values if network/config fails.
        });
}

function buildCheckoutItemRows(items) {
    return (items || []).map(function (item) {
        var qty = parseInt(item.qty, 10) || 1;
        var lineTotal = (Number(item.price) || 0) * qty;
        return (
            '<div class="cash-order-row">' +
            '<span class="cash-order-name">' +
            escapeHtml(qty + 'x ' + (item.name || 'Item')) +
            '</span>' +
            '<span class="cash-order-amt">' +
            formatCurrency(lineTotal) +
            '</span>' +
            '</div>'
        );
    }).join('');
}

function shouldShowFulfillmentSplit() {
    return isDineTakeOrderSelected() && cartByFulfillment.take_out.length > 0;
}

function buildCheckoutGroupedItemsHtml() {
    if (!shouldShowFulfillmentSplit()) {
        return buildCheckoutItemRows(getAllCartItems());
    }
    var html = '';
    if (cartByFulfillment.dine_in.length) {
        html +=
            '<div class="cash-order-group">' +
            '<p class="cash-order-group__label">Orders to dine in:</p>' +
            '<div class="cash-order-group__items">' + buildCheckoutItemRows(cartByFulfillment.dine_in) + '</div>' +
            '</div>';
    }
    if (cartByFulfillment.take_out.length) {
        html +=
            '<div class="cash-order-group cash-order-group--takeout">' +
            '<p class="cash-order-group__label cash-order-group__label--takeout">Orders to take out:</p>' +
            '<div class="cash-order-group__items">' + buildCheckoutItemRows(cartByFulfillment.take_out) + '</div>' +
            '</div>';
    }
    return html;
}

function renderCheckoutSummary(targetEl, paymentLabel) {
    if (!targetEl) return;
    if (!getAllCartItems().length) {
        targetEl.innerHTML = '<div class="cash-modal-summary-card"><div class="cash-order-empty">No items in this order.</div></div>';
        return;
    }
    var itemsHtml = buildCheckoutGroupedItemsHtml();

    var totals = getTotals();
    var discount = getOrderDiscountPayload();
    var discountMetaHtml = discount.type === 'none'
        ? ''
        : '<div class="cash-modal-summary-row">' +
            '<span class="cash-modal-meta-label">Discount</span>' +
            '<span class="cash-modal-meta-value">' + escapeHtml(getDiscountLabel(discount.type) + ' - ' + discount.customer_name + ' (' + discount.id_number + ')') + '</span>' +
          '</div>';

    var discountTotalsHtml = totals.discountType === 'none' ? '' : (
        '<div class="cash-modal-summary-row">' +
            '<span class="cash-modal-meta-label">VAT Exempt</span>' +
            '<span class="cash-modal-meta-value">' + formatCurrency(totals.vatExempt) + '</span>' +
        '</div>' +
        '<div class="cash-modal-summary-row">' +
            '<span class="cash-modal-meta-label">Senior/PWD Discount</span>' +
            '<span class="cash-modal-meta-value">' + formatCurrency(totals.discountAmount) + '</span>' +
        '</div>'
    );

    targetEl.innerHTML =
        '<div class="cash-modal-summary-card">' +
            '<div class="cash-modal-summary-row cash-modal-summary-row--meta">' +
                '<span class="cash-modal-meta-label">Payment method</span>' +
                '<span class="cash-modal-meta-value cash-modal-meta-value--payment">' + escapeHtml(paymentLabel || 'CASH') + '</span>' +
            '</div>' +
            discountMetaHtml +
            '<div class="cash-modal-order-list">' + itemsHtml + '</div>' +
            discountTotalsHtml +
            '<div class="cash-modal-summary-row cash-modal-summary-row--total">' +
                '<span class="cash-modal-meta-label">Total</span>' +
                '<span class="cash-modal-meta-value cash-modal-meta-value--total">' + formatCurrency(totals.total) + '</span>' +
            '</div>' +
        '</div>';
}

function openCashModal() {
    if (!cashCheckoutModal) return;
    var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    var isPickupOrder = orderTypeNorm === 'pickup' || orderTypeNorm === 'pick_up';
    Array.prototype.forEach.call(pickupOnlyModalMessages, function (el) {
        el.style.display = isPickupOrder ? '' : 'none';
    });
    renderCheckoutSummary(cashModalSummary, 'CASH');
    syncDiscountRequestButtons();
    var cashNameInput = document.getElementById('cash-order-name-input');
    if (cashNameInput) cashNameInput.value = '';
    cashCheckoutModal.classList.add('cash-modal-overlay--open');
    cashCheckoutModal.setAttribute('aria-hidden', 'false');
}

function closeCashModal() {
    if (!cashCheckoutModal) return;
    cashCheckoutModal.classList.remove('cash-modal-overlay--open');
    cashCheckoutModal.setAttribute('aria-hidden', 'true');
}

function openGcashModal() {
    if (!gcashCheckoutModal) return;
    loadGcashKioskConfig(true);
    var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    var isPickupOrder = orderTypeNorm === 'pickup' || orderTypeNorm === 'pick_up';
    Array.prototype.forEach.call(pickupOnlyModalMessages, function (el) {
        el.style.display = isPickupOrder ? '' : 'none';
    });
    renderCheckoutSummary(gcashModalSummary, 'GCASH');
    syncDiscountRequestButtons();
    gcashCheckoutModal.classList.add('cash-modal-overlay--open');
    gcashCheckoutModal.setAttribute('aria-hidden', 'false');
    if (isPickupOrder) resetPickupAlarmPicker();
    else if (pickupLaterTimeInput) pickupLaterTimeInput.value = '';
    var gcashNameInput = document.getElementById('gcash-order-name-input');
    var sidebarName = getSidebarOrderName();
    if (gcashNameInput) gcashNameInput.value = sidebarName;
    if (gcashRefInput) {
        gcashRefInput.value = '';
        setTimeout(function () { gcashRefInput.focus(); }, 50);
    }
}

function closeGcashModal() {
    if (!gcashCheckoutModal) return;
    gcashCheckoutModal.classList.remove('cash-modal-overlay--open');
    gcashCheckoutModal.setAttribute('aria-hidden', 'true');
}

function syncDiscountRequestButtons() {
    var label = discountRequest.active
        ? ('Requested: ' + (discountRequest.type === 'pwd' ? 'PWD' : 'Senior'))
        : 'Request PWD/SC Discount';
    [document.getElementById('cash-request-pwd-sc-btn'), document.getElementById('gcash-request-pwd-sc-btn')].forEach(function (btn) {
        if (!btn) return;
        btn.textContent = label;
        btn.classList.toggle('is-active', !!discountRequest.active);
    });
    renderDiscountRequestPreviews();
}

function renderDiscountRequestPreviews() {
    var cashPreview = document.getElementById('cash-discount-preview');
    var gcashPreview = document.getElementById('gcash-discount-preview');
    var html = buildDiscountRequestPreviewHtml();
    [cashPreview, gcashPreview].forEach(function (el) {
        if (!el) return;
        if (!html) {
            el.hidden = true;
            el.innerHTML = '';
            return;
        }
        el.hidden = false;
        el.innerHTML = html;
    });
}

function buildDiscountRequestPreviewHtml() {
    if (!discountRequest.active || !getAllCartItems().length) return '';
    var type = discountRequest.type === 'pwd' ? 'pwd' : 'senior';
    var typeLabel = type === 'pwd' ? 'PWD' : 'Senior Citizen';
    var lines = getAllCartItems().map(function (item) {
        var qty = Math.max(1, parseInt(item.qty, 10) || 1);
        var gross = Math.round(((Number(item.price) || 0) * qty) * 100) / 100;
        var priced = calculateDiscountTotals(gross, type);
        return {
            name: qty + 'x ' + (item.name || 'Item'),
            gross: gross,
            discounted: priced.total
        };
    });
    var discountedTotal = Math.round(lines.reduce(function (sum, line) {
        return sum + line.discounted;
    }, 0) * 100) / 100;

    var rowsHtml = lines.map(function (line) {
        return (
            '<div class="cash-modal-discount-preview__row">' +
                '<span class="cash-modal-discount-preview__name">' + escapeHtml(line.name) + '</span>' +
                '<span class="cash-modal-discount-preview__prices">' +
                    '<span class="cash-modal-discount-preview__was">' + formatPeso(line.gross) + '</span>' +
                    '<span class="cash-modal-discount-preview__now">' + formatPeso(line.discounted) + '</span>' +
                '</span>' +
            '</div>'
        );
    }).join('');

    return (
        '<p class="cash-modal-discount-preview__title">' + escapeHtml(typeLabel) + ' discount preview</p>' +
        rowsHtml +
        '<div class="cash-modal-discount-preview__total">' +
            '<span class="cash-modal-discount-preview__total-label">Discounted total</span>' +
            '<strong class="cash-modal-discount-preview__total-amt">' + formatPeso(discountedTotal) + '</strong>' +
        '</div>'
    );
}

var pwdScRequestModal = document.getElementById('pwd-sc-request-modal');
var pwdScRequestType = document.getElementById('pwd-sc-request-type');
var pwdScRequestError = document.getElementById('pwd-sc-request-error');
var pwdScRequestCancel = document.getElementById('pwd-sc-request-cancel');
var pwdScRequestConfirm = document.getElementById('pwd-sc-request-confirm');

function openPwdScRequestModal() {
    if (!pwdScRequestModal) return;
    if (pwdScRequestType) pwdScRequestType.value = discountRequest.type === 'pwd' ? 'pwd' : 'senior';
    if (pwdScRequestError) {
        pwdScRequestError.hidden = true;
        pwdScRequestError.textContent = '';
    }
    pwdScRequestModal.classList.add('cash-modal-overlay--open');
    pwdScRequestModal.setAttribute('aria-hidden', 'false');
}

function closePwdScRequestModal() {
    if (!pwdScRequestModal) return;
    pwdScRequestModal.classList.remove('cash-modal-overlay--open');
    pwdScRequestModal.setAttribute('aria-hidden', 'true');
}

function copyToClipboard(text) {
    var t = String(text || '').trim();
    if (!t) return Promise.resolve(false);

    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(t).then(function () { return true; }).catch(function () { return false; });
    }

    return new Promise(function (resolve) {
        try {
            var ta = document.createElement('textarea');
            ta.value = t;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            ta.style.left = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            resolve(!!ok);
        } catch (e) {
            resolve(false);
        }
    });
}

document.querySelectorAll('.checkout-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        var page = btn.closest('.page');
        if (!page) return;

        if (!getAllCartItems().length) {
            e.preventDefault();
            window.alert('Please add at least one item before checkout.');
            return;
        }

        var activePaymentBtn = page.querySelector('.payment-btn.payment-btn--active');
        if (!activePaymentBtn) return;

        var paymentText = activePaymentBtn.textContent.trim().toUpperCase();
        e.preventDefault();
        if (paymentText === 'CASH') {
            var orderTypeNorm = String(selectedOrderType || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
            var isPickupOrder = orderTypeNorm === 'pickup' || orderTypeNorm === 'pick_up';
            if (selectedServiceType === 'remote' || isPickupOrder) {
                window.alert('Pick up orders require GCash payment.');
                return;
            }
            openCashModal();
        } else if (paymentText === 'GCASH') {
            if (isPickupOrderSelected() && !getSidebarOrderName()) {
                window.alert('Please enter a customer name for the order.');
                var nameInput = page.querySelector('.order-name-input');
                if (nameInput) nameInput.focus();
                return;
            }
            openGcashModal();
        }
    });
});

function mapCartItemsForSubmit(items) {
    return items.map(function (ci) {
        var t = ci.temperature || null;
        return {
            menu_item_id: ci.menu_item_id,
            quantity: ci.qty,
            unit_price: Number(ci.price) || 0,
            removed: ci.removed || [],
            addons: normalizeAddonsList(ci.addons).map(function (a) {
                return { name: a.name, price: a.price };
            }),
            temperature:
                t && t.main
                    ? {
                          main: t.main,
                          detail: t.detail || '',
                          variantPrice: Number(t.variantPrice) || 0
                      }
                    : null,
            note: String(ci.note || '').trim()
        };
    });
}

function submitOnePublicOrder(paymentMethod, gcashRef, orderTypeLabel, items, applyDiscount) {
    var pickupLaterTime = pickupLaterTimeInput ? String(pickupLaterTimeInput.value || '').trim() : '';
    var cashNameInput = document.getElementById('cash-order-name-input');
    var gcashNameInput = document.getElementById('gcash-order-name-input');
    var orderName = String(
        (paymentMethod === 'gcash'
            ? (gcashNameInput && gcashNameInput.value)
            : (cashNameInput && cashNameInput.value)) || getSidebarOrderName() || ''
    ).trim();
    var discount = applyDiscount ? getOrderDiscountPayload() : { type: 'none', customer_name: '', id_number: '' };
    if (applyDiscount && discount.type !== 'none' && (!discount.customer_name || !discount.id_number)) {
        return Promise.reject(new Error('Please complete the Senior/PWD customer name and ID number.'));
    }
    if (paymentMethod === 'gcash' && !String(gcashRef || '').trim()) {
        return Promise.reject(new Error('Please enter your GCash reference number.'));
    }
    var payload = {
        service_type: selectedServiceType || 'onsite',
        order_type: orderTypeLabel || selectedOrderType || 'Not selected',
        payment_method: paymentMethod,
        gcash_ref: gcashRef || '',
        discount: discount,
        discount_requested: applyDiscount && !!(discountRequest && discountRequest.active),
        discount_request_type: (applyDiscount && discountRequest && discountRequest.active) ? discountRequest.type : '',
        items: mapCartItemsForSubmit(items)
    };
    if (pickupLaterTime) payload.pickup_later_time = pickupLaterTime;
    if (orderName) payload.customer_name = orderName;
    var hasMissingIds = payload.items.some(function (it) { return !it.menu_item_id; });
    if (hasMissingIds) {
        return Promise.reject(new Error('Some items are missing menu IDs. Please reload the menu.'));
    }

    return fetch('api/order_public.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || !res.success) {
            if (res && res.code === 'inventory_shortage') {
                var lines = ['This order cannot be completed because some items are out of stock or missing:'];
                (res.shortages || []).forEach(function (s) {
                    lines.push('- ' + (s.item_name || 'Item') + ': need ' + s.required + ', only ' + s.available + ' available');
                });
                lines.push('Please choose different items or try again later.');
                throw new Error(lines.join('\n'));
            }
            throw new Error((res && res.error) || 'Order failed');
        }
        return res;
    });
}

function submitPublicOrder(paymentMethod, gcashRef) {
    var jobs = [];
    if (!isDineTakeOrderSelected()) {
        if (cartByFulfillment.dine_in.length) {
            jobs.push({ type: selectedOrderType || 'Not selected', items: cartByFulfillment.dine_in.slice() });
        }
    } else {
        if (cartByFulfillment.dine_in.length) {
            jobs.push({ type: 'Dine in', items: cartByFulfillment.dine_in.slice() });
        }
        if (cartByFulfillment.take_out.length) {
            jobs.push({ type: 'Take out', items: cartByFulfillment.take_out.slice() });
        }
    }
    if (!jobs.length) {
        return Promise.reject(new Error('Please add at least one item before checkout.'));
    }

    var lastRes = null;
    return jobs.reduce(function (chain, job, idx) {
        return chain.then(function () {
            return submitOnePublicOrder(paymentMethod, gcashRef, job.type, job.items, idx === 0);
        }).then(function (res) {
            lastRes = res;
        });
    }, Promise.resolve()).then(function () {
        return lastRes;
    });
}

if (orderReceiptViewBtn) {
    orderReceiptViewBtn.addEventListener('click', function () {
        if (lastReceiptUrl) window.open(lastReceiptUrl, '_blank', 'noopener');
    });
}
if (orderReceiptDoneBtn) {
    orderReceiptDoneBtn.addEventListener('click', closeOrderReceiptModal);
}
if (orderReceiptModal) {
    orderReceiptModal.addEventListener('click', function (e) {
        if (e.target === orderReceiptModal) closeOrderReceiptModal();
    });
}

if (cashModalConfirmBtn) {
    cashModalConfirmBtn.addEventListener('click', function () {
        var cashNameInput = document.getElementById('cash-order-name-input');
        var orderName = cashNameInput ? String(cashNameInput.value || '').trim() : '';
        if (!orderName) {
            window.alert('Please enter a customer name for the order.');
            if (cashNameInput) cashNameInput.focus();
            return;
        }
        submitPublicOrder('cash', '').then(function (res) {
            closeCashModal();
            clearAllCarts();
            orderDiscount = { type: 'none', customer_name: '', id_number: '' };
            discountRequest = { active: false, type: 'senior' };
            orderCustomerName = '';
            syncDiscountRequestButtons();
            renderAllSidebars();
            showPaymentSuccessToast(function () {
                openOrderReceiptModal(res);
            });
        }).catch(function (err) {
            window.alert(err && err.message ? err.message : 'Failed to submit order.');
        });
    });
}
if (cashModalCancelBtn) {
    cashModalCancelBtn.addEventListener('click', closeCashModal);
}

['cash-request-pwd-sc-btn', 'gcash-request-pwd-sc-btn'].forEach(function (id) {
    var btn = document.getElementById(id);
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (discountRequest.active) {
            discountRequest = { active: false, type: 'senior' };
            syncDiscountRequestButtons();
            return;
        }
        openPwdScRequestModal();
    });
});
if (pwdScRequestCancel) pwdScRequestCancel.addEventListener('click', closePwdScRequestModal);
if (pwdScRequestConfirm) {
    pwdScRequestConfirm.addEventListener('click', function () {
        var type = pwdScRequestType ? String(pwdScRequestType.value || '').toLowerCase() : 'senior';
        if (type !== 'pwd' && type !== 'senior') {
            if (pwdScRequestError) {
                pwdScRequestError.hidden = false;
                pwdScRequestError.textContent = 'Please select Senior Citizen or PWD.';
            }
            return;
        }
        discountRequest = { active: true, type: type };
        syncDiscountRequestButtons();
        closePwdScRequestModal();
    });
}
if (pwdScRequestModal) {
    pwdScRequestModal.addEventListener('click', function (e) {
        if (e.target === pwdScRequestModal) closePwdScRequestModal();
    });
}

if (gcashModalConfirmBtn) {
    gcashModalConfirmBtn.addEventListener('click', function () {
        var gcashNameInput = document.getElementById('gcash-order-name-input');
        var orderName = gcashNameInput ? String(gcashNameInput.value || '').trim() : '';
        if (!orderName) {
            window.alert('Please enter a customer name for the order.');
            if (gcashNameInput) gcashNameInput.focus();
            return;
        }
        var ref = gcashRefInput ? String(gcashRefInput.value || '').trim() : '';
        if (!ref) {
            window.alert('Please enter your GCash reference number.');
            if (gcashRefInput) gcashRefInput.focus();
            return;
        }
        submitPublicOrder('gcash', ref).then(function (res) {
            closeGcashModal();
            clearAllCarts();
            orderDiscount = { type: 'none', customer_name: '', id_number: '' };
            discountRequest = { active: false, type: 'senior' };
            orderCustomerName = '';
            syncDiscountRequestButtons();
            renderAllSidebars();
            showPaymentSuccessToast(function () {
                openOrderReceiptModal(res);
            });
        }).catch(function (err) {
            window.alert(err && err.message ? err.message : 'Failed to submit order.');
        });
    });
}
if (gcashModalCancelBtn) {
    gcashModalCancelBtn.addEventListener('click', closeGcashModal);
}

if (gcashCopyNumberBtn) {
    gcashCopyNumberBtn.addEventListener('click', function () {
        var num = gcashNumberDisplay ? String(gcashNumberDisplay.textContent || '').trim() : '';
        copyToClipboard(num).then(function (ok) {
            gcashCopyNumberBtn.textContent = ok ? 'COPIED' : 'COPY';
            setTimeout(function () {
                if (gcashCopyNumberBtn) gcashCopyNumberBtn.textContent = 'COPY';
            }, 1000);
        });
    });
}

if (cashCheckoutModal) {
    cashCheckoutModal.addEventListener('click', function (e) {
        if (e.target === cashCheckoutModal) closeCashModal();
    });
}
if (gcashCheckoutModal) {
    gcashCheckoutModal.addEventListener('click', function (e) {
        if (e.target === gcashCheckoutModal) closeGcashModal();
    });
}

if (ingredientModalCancelBtn) {
    ingredientModalCancelBtn.addEventListener('click', function () {
        closeIngredientModal(null);
    });
}

if (ingredientModalConfirmBtn) {
    ingredientModalConfirmBtn.addEventListener('click', function () {
        var checklist = document.getElementById('ingredient-checklist');
        if (!checklist) return closeIngredientModal([]);

        var modalEl = document.getElementById('ingredient-modal');
        var needTemp = modalEl && modalEl.getAttribute('data-kiosk-require-temp') === '1';
        var tempPill = checklist.querySelector('.kiosk-temp-row .kiosk-addon-pill--active');
        var temperature = null;
        if (tempPill) {
            var rawM = tempPill.getAttribute('data-temp-main');
            var rawD = tempPill.getAttribute('data-temp-detail');
            var main = '';
            var detail = '';
            try {
                main = rawM ? decodeURIComponent(rawM) : '';
            } catch (e) {
                main = rawM || '';
            }
            try {
                detail = rawD ? decodeURIComponent(rawD) : '';
            } catch (e) {
                detail = rawD || '';
            }
            temperature = {
                main: main,
                detail: detail,
                variantPrice: parseFloat(tempPill.getAttribute('data-temp-variant-price') || '0') || 0
            };
        } else if (needTemp) {
            window.alert('Please select a temperature.');
            return;
        }

        var selected = Array.prototype.slice
            .call(checklist.querySelectorAll('.kiosk-addon-grid .kiosk-addon-pill--active'))
            .map(function (btn) {
                var raw = btn.getAttribute('data-kiosk-addon-name');
                var name = '';
                try {
                    name = raw ? decodeURIComponent(raw) : '';
                } catch (e) {
                    name = raw || '';
                }
                var price = parseFloat(btn.getAttribute('data-kiosk-addon-price') || '0') || 0;
                return name ? { name: name, price: price } : null;
            })
            .filter(Boolean);

        var noteEl = document.getElementById('ingredient-modal-note-input');
        var note = noteEl ? String(noteEl.value || '').trim() : '';
        closeIngredientModal({ addons: selected, temperature: temperature, note: note });
    });
}

if (ingredientModal) {
    ingredientModal.addEventListener('click', function (e) {
        if (e.target === ingredientModal) {
            closeIngredientModal(null);
        }
    });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeCashModal();
        closeGcashModal();
        closeIngredientModal(null);
    }
});

document.addEventListener('input', function (e) {
    if (!e.target || !e.target.classList || !e.target.classList.contains('order-name-input')) return;
    orderCustomerName = String(e.target.value || '');
    document.querySelectorAll('.order-name-input').forEach(function (input) {
        if (input !== e.target) input.value = orderCustomerName;
    });
});

renderAllSidebars();
wireFixedCategoryButtons();
wireMenuGroupButtons();
wireBevCategoryCircles();
wireBevTempFilters();
wireMealCategoryCircles();

var kioskRealtimeSource = null;
var kioskRealtimeRetryTimer = null;
var kioskRealtimeReloadTimer = null;

function scheduleKioskRealtimeRefresh() {
    if (kioskRealtimeReloadTimer) return;
    kioskRealtimeReloadTimer = setTimeout(function () {
        kioskRealtimeReloadTimer = null;
        loadMenuData();
    }, 450);
}

function connectKioskRealtimeStream() {
    if (typeof window.EventSource === 'undefined') return;
    if (kioskRealtimeRetryTimer) {
        clearTimeout(kioskRealtimeRetryTimer);
        kioskRealtimeRetryTimer = null;
    }
    if (kioskRealtimeSource) {
        try { kioskRealtimeSource.close(); } catch (e) {}
        kioskRealtimeSource = null;
    }
    kioskRealtimeSource = new EventSource('../adminStaff/api/order_events_sse.php');
    function onRealtimeMessage(e) {
        if (!e || !e.data) return;
        try {
            var evt = JSON.parse(e.data);
            var payload = (evt && evt.payload) || {};
            var source = String(payload.order_source || '').toLowerCase();
            if (source === 'kiosk' || !source) {
                scheduleKioskRealtimeRefresh();
            }
        } catch (err) {}
    }
    kioskRealtimeSource.onmessage = onRealtimeMessage;
    kioskRealtimeSource.addEventListener('order_update', onRealtimeMessage);
    kioskRealtimeSource.onerror = function () {
        if (kioskRealtimeSource) {
            try { kioskRealtimeSource.close(); } catch (e) {}
            kioskRealtimeSource = null;
        }
        if (!kioskRealtimeRetryTimer) {
            kioskRealtimeRetryTimer = setTimeout(function () {
                kioskRealtimeRetryTimer = null;
                connectKioskRealtimeStream();
            }, 3000);
        }
    };
}

window.addEventListener('beforeunload', function () {
    if (kioskRealtimeRetryTimer) {
        clearTimeout(kioskRealtimeRetryTimer);
        kioskRealtimeRetryTimer = null;
    }
    if (kioskRealtimeReloadTimer) {
        clearTimeout(kioskRealtimeReloadTimer);
        kioskRealtimeReloadTimer = null;
    }
    if (kioskRealtimeSource) {
        try { kioskRealtimeSource.close(); } catch (e) {}
        kioskRealtimeSource = null;
    }
});

connectKioskRealtimeStream();
refreshPickupServerTime();
setInterval(refreshPickupServerTime, 60000);
loadMenuData();
setInterval(refreshFastMovingBadges, 90000);
