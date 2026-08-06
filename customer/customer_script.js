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
const cartItems = [];
let ingredientModalResolver = null;
let ingredientModalCurrentItemName = '';

let menuData = { categories: [], items: [] };
let fastMovingItemIds = [];
let selectedMenuGroup = null; // 'beverages' | 'meals'
/** Beverage circle filter: matches data-bev-category on #bev-circles (aligned with staff POS beverage sections). */
let selectedBevCategory = 'coffee';
/** Hot / cold subcategory filter for drinks. */
let selectedDrinkTemp = 'all';
let selectedMealCategory = 'all';
const PICKUP_START_HOUR = 20; // 8:00 PM
const PICKUP_END_HOUR = 24; // 12:00 MN
const VAT_RATE = 0.12;
const SENIOR_PWD_RATE = 0.20;
let latestServerHour24 = null;
let orderDiscount = { type: 'none', customer_name: '', id_number: '' };

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
    return [
        { size: 'Hot', label: '', price: p },
        { size: 'Iced', label: '', price: p }
    ];
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

function getAddonOptionsForItem(itemName, categoryName) {
    var name = String(itemName || '').toLowerCase();
    var cat = String(categoryName || '').toLowerCase();
    if (name.indexOf('fries') !== -1) {
        return [
            { name: 'Extra dip', price: 15 },
            { name: 'Extra cheese', price: 25 },
            { name: 'Bacon bits', price: 30 },
            { name: 'Jalapeños', price: 15 },
            { name: 'Ranch drizzle', price: 15 }
        ];
    }
    if (name.indexOf('nacho') !== -1) {
        return [
            { name: 'Guacamole', price: 35 },
            { name: 'Sour cream', price: 20 },
            { name: 'Extra cheese', price: 30 },
            { name: 'Jalapeños', price: 15 },
            { name: 'Salsa', price: 15 }
        ];
    }
    if (name.indexOf('pizza') !== -1) {
        return [
            { name: 'Extra toppings', price: 45 },
            { name: 'Stuffed crust', price: 50 },
            { name: 'Dipping sauce', price: 20 },
            { name: 'Garlic butter', price: 20 },
            { name: 'Chili flakes', price: 10 }
        ];
    }
    if (name.indexOf('pasta') !== -1) {
        return [
            { name: 'Extra cheese', price: 25 },
            { name: 'Extra sauce', price: 20 },
            { name: 'Garlic bread', price: 35 },
            { name: 'Chili flakes', price: 10 },
            { name: 'Grilled chicken', price: 45 }
        ];
    }
    if (name.indexOf('chicken') !== -1) {
        return [
            { name: 'Extra rice', price: 25 },
            { name: 'Extra gravy', price: 15 },
            { name: 'Coleslaw', price: 25 },
            { name: 'Spicy sauce', price: 15 },
            { name: 'Cheese sauce', price: 20 }
        ];
    }
    if (!isBeverageCategoryName(cat)) {
        return [];
    }
    return [
        { name: 'Espresso Shot', price: 50, badge: '×2' },
        { name: 'Oat Milk', price: 40 },
        { name: 'Breve', price: 30 },
        { name: 'Whipped Cream', price: 20 },
        { name: 'Sauce', price: 20 },
        { name: 'Syrup', price: 20 }
    ];
}

/**
 * @param {object} menuItem — API row (id, name, price, description, category_name, …)
 * @param {{ addons?: object[], temperature?: object|null }} initial — existing selections when editing
 * @param {{ temperatureFallback?: boolean }} openOpts — Hot/Iced when no Variants: line (beverages only)
 */
function openIngredientModal(menuItem, initial, openOpts) {
    var modal = document.getElementById('ingredient-modal');
    var checklist = document.getElementById('ingredient-checklist');
    var title = document.getElementById('ingredient-modal-title');
    var sub = document.getElementById('ingredient-modal-sub');
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
        var tempPills = variants
            .map(function (v) {
                var isActive = kioskVariantMatchesTemperature(v, initialTemp);
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
        sub.textContent = variants.length
            ? 'Choose a temperature, then optional add-ons. Tap an add-on again to remove it.'
            : 'Tap add-ons to include them. Tap again to remove. Extra charges may apply at pickup.';
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

function getItemKey(name, addons, removed, temperature) {
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
    return name + '|t:' + tk + '|a:' + sortedA.join(',') + '|r:' + sortedR.join(',');
}

function addItemToCart(item) {
    var ad = normalizeAddonsList(item.addons);
    var rm = item.removed || [];
    var temp = item.temperature || null;
    var key = getItemKey(item.name, ad, rm, temp);
    var existing = cartItems.find(function (cartItem) {
        return (
            getItemKey(cartItem.name, cartItem.addons || [], cartItem.removed || [], cartItem.temperature || null) ===
            key
        );
    });

    if (existing) {
        existing.qty += 1;
        return;
    }

    var unit = computeCartLineUnit(item.menu_item_id, temp, ad);

    cartItems.push({
        menu_item_id: item.menu_item_id || null,
        name: item.name,
        price: unit,
        qty: 1,
        addons: ad.map(function (a) {
            return { name: a.name, price: a.price };
        }),
        temperature: temp,
        removed: (item.removed || []).slice()
    });
}

function updateOrderTypeLabels() {
    menuPages.forEach(function (page) {
        var target = page.querySelector('.order-type-value');
        if (target) target.textContent = selectedOrderType;
    });
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
    var subtotal = cartItems.reduce(function (sum, item) {
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

function renderSidebarForPage(page) {
    var itemsWrap = page.querySelector('.sidebar-items');
    var badge = page.querySelector('.sidebar-badge');
    var totalEl = page.querySelector('.total-amt-final');
    var discountTypeEl = page.querySelector('.discount-type-select');
    var discountFields = page.querySelector('.discount-fields');
    var discountNameEl = page.querySelector('.discount-name-input');
    var discountIdEl = page.querySelector('.discount-id-input');

    if (!itemsWrap || !badge || !totalEl) return;

    if (cartItems.length === 0) {
        itemsWrap.innerHTML = '<div class="cart-item"><span class="cart-mods">No items yet. Add from menu cards.</span></div>';
    } else {
        itemsWrap.innerHTML = cartItems
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
                var modBits = [];
                if (tempPart) modBits.push(tempPart);
                if (addonPart) modBits.push(addonPart);
                if (removedPart) modBits.push(removedPart);
                var modsText = modBits.length ? modBits.join(' · ') : 'No customizations';
                return (
                    '<div class="cart-item">' +
                    '  <div class="cart-item-row">' +
                    '    <span class="cart-item-name">' + item.name + '</span>' +
                    '    <span class="cart-item-price">' + formatCurrency(item.price * item.qty) + '</span>' +
                    '  </div>' +
                    '  <div class="cart-item-row">' +
                    '    <button class="cart-mods cart-mods-btn" data-action="edit-mods" data-index="' + index + '">' + modsText + '</button>' +
                    '    <div class="qty-ctrl">' +
                    '      <button class="qty-btn" data-action="dec" data-index="' + index + '">−</button>' +
                    '      <span class="qty-val">' + item.qty + '</span>' +
                    '      <button class="qty-btn" data-action="inc" data-index="' + index + '">+</button>' +
                    '    </div>' +
                    '  </div>' +
                    '</div>'
                );
            })
            .join('');
    }

    var totalQty = cartItems.reduce(function (sum, item) {
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
    applyPaymentRules();
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
        n.indexOf('tea') !== -1
    );
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

function getCustomerBevCircleKey(item) {
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

function getItemsForGroup(group) {
    return (menuData.items || []).filter(function (it) {
        var isBev = isBeverageCategoryName(it.category_name || '');
        return group === 'beverages' ? isBev : !isBev;
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
        gridEl.innerHTML = '<div class="prod-card"><div class="prod-card-body"><p class="prod-card-desc">No items available in this category.</p></div></div>';
        return;
    }

    gridEl.innerHTML = items.map(function (it) {
        var kioskMenuPlaceholderImg = '../adminStaff/icons_admin/static_img.jpg';
        var isAvailable = it.is_available !== false;
        var imgSrc =
            it.image_url && String(it.image_url).trim() ? String(it.image_url).trim() : kioskMenuPlaceholderImg;
        var imgHtml =
            '<img class="prod-card-img-el" src="' +
            escapeHtml(imgSrc) +
            '" alt="' +
            escapeHtml(it.name) +
            '">';
        var isBestSeller = isFastMovingMenuItem(it.id);
        var bestSellerBadge = isBestSeller
            ? '<span class="prod-card-bestseller-badge">BEST SELLER</span>'
            : '';
        return (
            '<div class="prod-card ' + (isAvailable ? '' : 'prod-card--soldout') + (isBestSeller ? ' prod-card--bestseller' : '') + '" data-menu-id="' + it.id + '">' +
            '  <div class="prod-card-img-wrap">' + bestSellerBadge + imgHtml + '</div>' +
            '  <div class="prod-card-body">' +
            '    <div class="prod-card-name-row">' +
            '      <span class="prod-card-name">' + escapeHtml(it.name) + '</span>' +
            '      <span class="prod-card-price">' + formatPeso(it.price) + '</span>' +
            '    </div>' +
            (isAvailable ? '' : '    <span class="prod-card-soldout-badge">SOLD OUT</span>') +
            '    <p class="prod-card-desc">' + escapeHtml(stripPosBevSectionForDisplay(it.description) || '—') + '</p>' +
            '  </div>' +
            '  <button class="prod-add-btn" data-add-menu-id="' + it.id + '" ' + (isAvailable ? '' : 'disabled') + '>' + (isAvailable ? 'ADD ORDER' : 'SOLD OUT') + '</button>' +
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
            var quickVariants = getKioskTemperatureVariants(it, !!opts.temperatureFallback);
            var quickAddons = getAddonOptionsForItem(it.name, it.category_name || '');
            if (!quickVariants.length && !quickAddons.length) {
                addItemToCart({
                    menu_item_id: it.id,
                    name: it.name,
                    price: it.price,
                    addons: [],
                    temperature: null,
                    removed: []
                });
                renderAllSidebars();
                return;
            }
            openIngredientModal(
                it,
                { addons: [], temperature: null },
                { temperatureFallback: !!opts.temperatureFallback }
            ).then(function (sel) {
                if (sel === null) return;
                addItemToCart({
                    menu_item_id: it.id,
                    name: it.name,
                    price: it.price,
                    addons: sel.addons,
                    temperature: sel.temperature,
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
        return getCustomerBevCircleKey(it) === selectedBevCategory;
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
    selectedBevCategory = cat || 'coffee';
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
    if (!wrap) return;

    wrap.querySelectorAll('[data-bev-category]').forEach(function (el) {
        function activate() {
            var cat = el.getAttribute('data-bev-category');
            if (!cat) return;
            setSelectedBevCategory(cat);
        }
        el.addEventListener('click', function (e) {
            e.preventDefault();
            activate();
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });
}

function normalizeMealCategoryName(name) {
    return String(name || '').trim().toLowerCase();
}

function inferMealCategoryKey(item) {
    var c = normalizeMealCategoryName(item && item.category_name);
    if (!c) return 'other';
    if (c.indexOf('snack') !== -1 || c.indexOf('starter') !== -1 || c.indexOf('nacho') !== -1) return 'snacks';
    if (c.indexOf('pastr') !== -1 || c.indexOf('toast') !== -1 || c.indexOf('dessert') !== -1) return 'pastries';
    if (c.indexOf('wing') !== -1 || c.indexOf('chicken') !== -1) return 'wings';
    if (c.indexOf('pasta') !== -1) return 'pasta';
    if (c === 'meals' || c === 'meal' || c.indexOf('rice') !== -1) return 'meal';
    return 'other';
}

function mealCategoryKeyFromCircleToken(token) {
    var t = String(token || '').trim().toLowerCase();
    if (t === 'snacks' || t === 'pastries' || t === 'meal' || t === 'wings' || t === 'pasta') return t;
    if (t === 'add more') return '';
    return '';
}

function renderMealProductGrid() {
    var grid = document.getElementById('meal-prod-grid');
    if (!grid) return;
    var input = screenMeals.querySelector('.menu-search-input');
    var q = input ? String(input.value || '').toLowerCase().trim() : '';
    var base = getItemsForGroup('meals');
    var byCat = selectedMealCategory === 'all'
        ? base
        : base.filter(function (it) { return inferMealCategoryKey(it) === selectedMealCategory; });
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
            var key = mealCategoryKeyFromCircleToken(el.getAttribute('data-meal'));
            el.classList.toggle('menu-product--selected', key === selectedMealCategory);
        });
    }
    renderMealProductGrid();
}

function wireMealCategoryCircles() {
    var wrap = document.getElementById('meal-circles');
    if (!wrap) return;
    wrap.querySelectorAll('[data-meal]').forEach(function (el) {
        function activate() {
            var key = mealCategoryKeyFromCircleToken(el.getAttribute('data-meal'));
            if (!key) return; // Keep the blank placeholder circle non-interactive.
            setSelectedMealCategory(key);
        }
        el.addEventListener('click', function (e) {
            e.preventDefault();
            activate();
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });
}

function showMenuForGroup(group) {
    selectedMenuGroup = group;
    var isBeverage = group === 'beverages';
    var page = isBeverage ? screenBeverages : screenMeals;
    var items = getItemsForGroup(group);

    var heading = page.querySelector('.menu-grid-heading');
    var sub = page.querySelector('.menu-grid-sub');
    if (heading) heading.textContent = isBeverage ? 'Beverages' : 'Snacks and Meals';
    if (sub) sub.textContent = 'Select items then proceed to checkout';

    var grid = isBeverage ? document.getElementById('prod-grid') : document.getElementById('meal-prod-grid');
    if (grid) grid.classList.add('prod-grid--visible');
    if (isBeverage) {
        var bevCircles = document.getElementById('bev-circles');
        if (bevCircles) bevCircles.classList.remove('menu-choice-hidden');
        selectedDrinkTemp = 'all';
        setSelectedBevCategory('coffee');
    } else {
        setSelectedMealCategory('snacks');
    }

    document.querySelectorAll('.menu-group-btn').forEach(function (btn) {
        var g = btn.getAttribute('data-menu-group');
        btn.classList.toggle('menu-group-btn--active', g === group);
    });

    showScreen(page);
    renderAllSidebars();
}

function openMenuSelection() {
    selectedMenuGroup = null;
    document.querySelectorAll('.menu-group-btn').forEach(function (btn) {
        btn.classList.remove('menu-group-btn--active');
    });
    var bevCircles = document.getElementById('bev-circles');
    if (bevCircles) bevCircles.classList.add('menu-choice-hidden');
    var bevGrid = document.getElementById('prod-grid');
    if (bevGrid) bevGrid.classList.remove('prod-grid--visible');
    showScreen(screenBeverages);
    renderAllSidebars();
}

function wireMenuGroupButtons() {
    document.querySelectorAll('.menu-group-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var group = btn.getAttribute('data-menu-group');
            if (group === 'beverages' || group === 'meals') {
                showMenuForGroup(group);
            }
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
                showMenuForGroup('beverages');
            } else {
                showMenuForGroup('meals');
            }
        });
    });
}

function loadMenuData() {
    return fetch('api/menu_public.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) throw new Error((res && res.error) || 'Failed to load menu');
            menuData.categories = res.categories || [];
            menuData.items = res.items || [];
            applyFastMovingIds(res.fast_moving_item_ids || []);
            wireSearch();
        })
        .catch(function () {
            // Keep UI usable even if API is down
            menuData = { categories: [], items: [] };
            fastMovingItemIds = [];
        });
}

// Screen 1 → Menu flow (Pick Up)
document.getElementById('btn-remote-order').addEventListener('click', function (e) {
    e.preventDefault();
    selectedServiceType = 'onsite';
    selectedOrderType = 'Pick Up';
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

    var itemsWrap = page.querySelector('.sidebar-items');
    if (!itemsWrap) return;

    itemsWrap.addEventListener('click', function (e) {
        var target = e.target.closest('button[data-action]');
        if (!target) return;

        var index = parseInt(target.getAttribute('data-index'), 10);
        if (isNaN(index) || !cartItems[index]) return;

        var action = target.getAttribute('data-action');
        if (action === 'inc') {
            cartItems[index].qty += 1;
        } else if (action === 'dec') {
            cartItems[index].qty -= 1;
            if (cartItems[index].qty <= 0) {
                cartItems.splice(index, 1);
            }
        } else if (action === 'edit-mods') {
            var row = cartItems[index];
            var mi =
                getMenuItemById(row.menu_item_id) ||
                ({ name: row.name, price: row.price, description: '', category_name: '' });
            var tempFb = categoryNameMergesIntoCustomerBeverages(mi.category_name || '');
            openIngredientModal(
                mi,
                { addons: row.addons || [], temperature: row.temperature || null },
                { temperatureFallback: tempFb }
            ).then(function (sel) {
                if (sel === null) return;
                cartItems[index].addons = sel.addons;
                cartItems[index].temperature = sel.temperature;
                cartItems[index].price = computeCartLineUnit(row.menu_item_id, sel.temperature, sel.addons);
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
var gcashNumberDisplay = document.getElementById('gcash-number-display');
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

function renderCheckoutSummary(targetEl, paymentLabel) {
    if (!targetEl) return;
    if (!cartItems.length) {
        targetEl.innerHTML = '<div class="cash-order-empty">No items in this order.</div>';
        return;
    }
    var itemsHtml = cartItems
        .map(function (item) {
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
        })
        .join('');

    var totals = getTotals();
    var discount = getOrderDiscountPayload();
    var discountMetaHtml = discount.type === 'none'
        ? ''
        : '<div class="cash-modal-meta-row">' +
            '<span class="cash-modal-meta-label">Discount</span>' +
            '<span class="cash-modal-meta-value">' + escapeHtml(getDiscountLabel(discount.type) + ' - ' + discount.customer_name + ' (' + discount.id_number + ')') + '</span>' +
          '</div>';

    targetEl.innerHTML =
        '<div class="cash-modal-meta-row">' +
            '<span class="cash-modal-meta-label">Payment Method</span>' +
            '<span class="cash-modal-meta-value">' + escapeHtml(paymentLabel || 'CASH') + '</span>' +
        '</div>' +
        discountMetaHtml +
        '<div class="cash-modal-order-list">' + itemsHtml + '</div>' +
        '<div class="cash-modal-meta-row">' +
            '<span class="cash-modal-meta-label">Gross</span>' +
            '<span class="cash-modal-meta-value">' + formatCurrency(totals.gross) + '</span>' +
        '</div>' +
        (totals.discountType === 'none' ? '' : (
            '<div class="cash-modal-meta-row">' +
                '<span class="cash-modal-meta-label">VAT Exempt</span>' +
                '<span class="cash-modal-meta-value">' + formatCurrency(totals.vatExempt) + '</span>' +
            '</div>' +
            '<div class="cash-modal-meta-row">' +
                '<span class="cash-modal-meta-label">Senior/PWD Discount</span>' +
                '<span class="cash-modal-meta-value">' + formatCurrency(totals.discountAmount) + '</span>' +
            '</div>'
        )) +
        '<div class="cash-modal-meta-row">' +
            '<span class="cash-modal-meta-label">Total</span>' +
            '<span class="cash-modal-meta-value">' + formatCurrency(totals.total) + '</span>' +
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
    gcashCheckoutModal.classList.add('cash-modal-overlay--open');
    gcashCheckoutModal.setAttribute('aria-hidden', 'false');
    if (pickupLaterTimeInput) pickupLaterTimeInput.value = '';
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

        if (cartItems.length === 0) {
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
            openGcashModal();
        }
    });
});

function submitPublicOrder(paymentMethod, gcashRef) {
    var pickupLaterTime = pickupLaterTimeInput ? String(pickupLaterTimeInput.value || '').trim() : '';
    var discount = getOrderDiscountPayload();
    if (discount.type !== 'none' && (!discount.customer_name || !discount.id_number)) {
        return Promise.reject(new Error('Please complete the Senior/PWD customer name and ID number.'));
    }
    var payload = {
        service_type: selectedServiceType || 'onsite',
        order_type: selectedOrderType || 'Not selected',
        payment_method: paymentMethod,
        gcash_ref: gcashRef || '',
        discount: discount,
        items: cartItems.map(function (ci) {
            var t = ci.temperature || null;
            return {
                menu_item_id: ci.menu_item_id,
                quantity: ci.qty,
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
                        : null
            };
        })
    };
    if (pickupLaterTime) payload.pickup_later_time = pickupLaterTime;
    // Guard: menu_item_id is required to be DB-backed
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
                var lines = ['Insufficient inventory for this order:'];
                (res.shortages || []).forEach(function (s) {
                    lines.push('- ' + (s.item_name || 'Item') + ': need ' + s.required + ', only ' + s.available + ' available');
                });
                throw new Error(lines.join('\n'));
            }
            throw new Error((res && res.error) || 'Order failed');
        }
        return res;
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
        submitPublicOrder('cash', '').then(function (res) {
            closeCashModal();
            cartItems.splice(0, cartItems.length);
            orderDiscount = { type: 'none', customer_name: '', id_number: '' };
            renderAllSidebars();
            openOrderReceiptModal(res);
        }).catch(function (err) {
            window.alert(err && err.message ? err.message : 'Failed to submit order.');
        });
    });
}
if (cashModalCancelBtn) {
    cashModalCancelBtn.addEventListener('click', closeCashModal);
}
if (gcashModalConfirmBtn) {
    gcashModalConfirmBtn.addEventListener('click', function () {
        var ref = gcashRefInput ? String(gcashRefInput.value || '').trim() : '';
        if (selectedServiceType === 'remote' && !ref) {
            window.alert('Please enter your GCash reference number.');
            if (gcashRefInput) gcashRefInput.focus();
            return;
        }
        submitPublicOrder('gcash', ref).then(function (res) {
            closeGcashModal();
            cartItems.splice(0, cartItems.length);
            orderDiscount = { type: 'none', customer_name: '', id_number: '' };
            renderAllSidebars();
            openOrderReceiptModal(res);
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

        closeIngredientModal({ addons: selected, temperature: temperature });
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
