<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';
require_once __DIR__ . '/../../adminStaff/api/discounts.php';
require_once __DIR__ . '/../../adminStaff/api/receipt_helpers.php';
require_once __DIR__ . '/../../adminStaff/api/recipe_helpers.php';
require_once __DIR__ . '/../../adminStaff/api/cashflow_helpers.php';
require_once __DIR__ . '/../../adminStaff/api/menu_helpers.php';

$m = method();
if ($m !== 'POST') fail('Method not allowed.', 405);

function is_pickup_order_type(string $orderType): bool {
    $t = strtolower(trim($orderType));
    return $t === 'pickup' || $t === 'pick up';
}

function ensure_order_source_schema(PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'order_source'");
    $stmt->execute();
    $exists = $stmt->fetch();
    if ($exists) return;

    $pdo->exec(
        "ALTER TABLE orders
         ADD COLUMN order_source VARCHAR(20) NOT NULL DEFAULT 'pos' AFTER order_number"
    );
}

function ensure_order_type_schema(PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'order_type'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE orders
         ADD COLUMN order_type VARCHAR(60) NULL DEFAULT NULL AFTER payment_method"
    );
}

$b = body();
$items = $b['items'] ?? [];
if (empty($items) || !is_array($items)) fail('Order must have at least one item.');

$serviceType = strtolower(trim($b['service_type'] ?? ''));
$orderType = trim($b['order_type'] ?? '');
$paymentMethod = strtolower(trim($b['payment_method'] ?? 'cash'));
$gcashRef = trim($b['gcash_ref'] ?? '');
$pickupLaterTime = trim((string)($b['pickup_later_time'] ?? ''));
$customerName = trim((string)($b['customer_name'] ?? ''));

if (!in_array($serviceType, ['remote', 'onsite'], true)) fail('Invalid service_type.');
if (!$orderType) fail('Order type is required.');

if ($serviceType === 'remote') {
    if ($paymentMethod !== 'gcash') fail('Remote orders require GCash payment.');
} else {
    if (!in_array($paymentMethod, ['cash', 'gcash'], true)) fail('Invalid payment method.');
}
if ($paymentMethod === 'gcash' && $gcashRef === '') {
    fail('GCash reference number is required.');
}

$pdo = db();
ensure_order_source_schema($pdo);
ensure_order_type_schema($pdo);
ensure_order_discount_schema($pdo);
ensure_order_inventory_deduction_schema($pdo);
ensure_order_items_cost_schema($pdo);
ensure_receipt_token_schema($pdo);

// Kiosk: hard-block when stock is short (no override).
$checkItems = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $checkItems[] = [
        'menu_item_id' => (int)($item['menu_item_id'] ?? 0),
        'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        'notes' => '', // built later; stock check uses menu+qty primarily via recipe
    ];
}
$shortages = check_inventory_shortages_for_cart($pdo, $checkItems);
if ($shortages) {
    respond_inventory_shortage($shortages, false);
}

$receiptToken = generate_receipt_token();
$pdo->beginTransaction();
try {
    $orderNumber = 'CU-' . strtoupper(substr(uniqid(), -6));
    $discount = normalize_order_discount_payload($b['discount'] ?? null);
    $discountRequested = !empty($b['discount_requested']);
    $discountRequestType = strtolower(trim((string)($b['discount_request_type'] ?? '')));
    if (!in_array($discountRequestType, ['senior', 'pwd'], true)) {
        $discountRequestType = '';
    }
    if ($discountRequested && $discountRequestType === '') {
        $discountRequestType = 'senior';
    }
    // Customer PWD/SC request is applied immediately (no staff confirm step).
    if ($discountRequested && in_array($discountRequestType, ['senior', 'pwd'], true) && ($discount['type'] ?? 'none') === 'none') {
        $discount = [
            'type' => $discountRequestType,
            'rate' => 0.0,
            'customer_name' => $customerName !== '' ? $customerName : 'Customer',
            'id_number' => 'KIOSK-REQUEST',
        ];
    }

    $grossAmount = 0.0;
    $itemRows = [];
    foreach ($items as $item) {
        $menuId = (int)($item['menu_item_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $removed = $item['removed'] ?? [];
        $addons = $item['addons'] ?? [];
        $temperature = $item['temperature'] ?? null;
        if (!$menuId) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail('Invalid menu_item_id.');
        }

        $priceRow = $pdo->prepare(
            'SELECT id, name, price, description, is_available
               FROM menu_items
              WHERE id = :id AND is_available = 1
              LIMIT 1'
        );
        $priceRow->execute([':id' => $menuId]);
        $row = $priceRow->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail("Menu item #$menuId not found or unavailable.");
        }

        $notesParts = [];
        $notesParts[] = 'Service: ' . $serviceType;
        $notesParts[] = 'Order type: ' . $orderType;
        if ($pickupLaterTime !== '') {
            $notesParts[] = 'Pickup time: ' . mb_substr($pickupLaterTime, 0, 80);
        }
        if (is_array($removed) && !empty($removed)) {
            $clean = array_values(array_filter(array_map(function ($x) {
                return trim((string)$x);
            }, $removed)));
            if (!empty($clean)) $notesParts[] = 'Removed: ' . implode(', ', $clean);
        }
        if (is_array($temperature)) {
            $tm = trim((string)($temperature['main'] ?? ''));
            if ($tm !== '') {
                $dt = trim((string)($temperature['detail'] ?? ''));
                if ($dt !== '') {
                    $notesParts[] = 'Temperature: ' . $tm . ' (' . $dt . ')';
                } else {
                    $notesParts[] = 'Temperature: ' . $tm;
                }
            }
        }
        if (is_array($addons) && !empty($addons)) {
            $addonLines = [];
            foreach ($addons as $x) {
                if (is_array($x)) {
                    $n = trim((string)($x['name'] ?? ''));
                    if ($n === '') {
                        continue;
                    }
                    $p = isset($x['price']) ? (float)$x['price'] : 0.0;
                    if ($p > 0) {
                        $addonLines[] = $n . ' (P' . number_format($p, 2, '.', '') . ')';
                    } else {
                        $addonLines[] = $n;
                    }
                } else {
                    $t = trim((string)$x);
                    if ($t !== '') {
                        $addonLines[] = $t;
                    }
                }
            }
            if (!empty($addonLines)) {
                $notesParts[] = 'Add-ons: ' . implode(', ', $addonLines);
            }
        }
        $itemNote = trim((string)($item['note'] ?? ''));
        if ($itemNote !== '') {
            $notesParts[] = 'Note: ' . mb_substr($itemNote, 0, 160);
        }
        $notes = implode(' | ', $notesParts);

        $clientUnitPrice = array_key_exists('unit_price', $item) ? (float)$item['unit_price'] : null;
        $unitPrice = resolve_menu_item_unit_price($row, $notes, $clientUnitPrice);
        $subtotal = $unitPrice * $qty;
        $grossAmount += $subtotal;

        $itemRows[] = [$menuId, $qty, $unitPrice, $subtotal, $notes];
    }

    validate_order_discount_payload($discount);
    $pricing = calculate_order_discount_breakdown($grossAmount, $discount);
    $total = (float)$pricing['total_amount'];
    $discountAlreadyApplied = ($pricing['discount_type'] ?? 'none') !== 'none';

    $ins = $pdo->prepare(
        'INSERT INTO orders
            (order_number, receipt_token, order_source, staff_id, status, payment_method, order_type, customer_name, discount_type, discount_customer_name,
             discount_id_number, gross_amount, vat_exempt_amount, discount_amount, total_amount, amount_received, change_due, gcash_ref,
             discount_requested, discount_request_type)
         VALUES (:num, :rtok, :src, NULL, "pending", :pm, :otype, :cname, :dtype, :dname, :did, :gross, :vat_exempt, :discount_amount, :total, NULL, NULL, :ref,
             :dreq, :dreqtype)'
    );
    $ins->execute([
        ':num' => $orderNumber,
        ':rtok' => $receiptToken,
        ':src' => 'kiosk',
        ':pm' => $paymentMethod,
        ':otype' => substr($orderType, 0, 60),
        ':cname' => $customerName !== '' ? substr($customerName, 0, 100) : null,
        ':dtype' => $pricing['discount_type'],
        ':dname' => $discount['type'] === 'none' ? null : $discount['customer_name'],
        ':did' => $discount['type'] === 'none' ? null : $discount['id_number'],
        ':gross' => $pricing['gross_amount'],
        ':vat_exempt' => $pricing['vat_exempt_amount'],
        ':discount_amount' => $pricing['discount_amount'],
        ':total' => $total,
        ':ref' => ($gcashRef !== '' ? $gcashRef : null),
        // Already applied at create — no staff confirmation queue.
        ':dreq' => 0,
        ':dreqtype' => ($discountRequested && $discountAlreadyApplied) ? $discountRequestType : null,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, subtotal, unit_cost, line_cost, notes)
         VALUES (:oid, :mid, :qty, :up, :sub, :unit_cost, :line_cost, :notes)'
    );
    foreach ($itemRows as [$mid, $qty, $up, $sub, $notes]) {
        $costSnapshot = snapshot_order_line_cost($pdo, (int)$mid, (int)$qty, $notes);
        $insItem->execute([
            ':oid' => $orderId,
            ':mid' => $mid,
            ':qty' => $qty,
            ':up' => $up,
            ':sub' => $sub,
            ':unit_cost' => $costSnapshot['unit_cost'],
            ':line_cost' => $costSnapshot['line_cost'],
            ':notes' => $notes,
        ]);
    }

    apply_order_inventory_deduction($pdo, $orderId);

    $pdo->commit();
    publish_realtime_event('order_created', [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'order_source' => 'kiosk',
        'status' => 'pending',
    ]);
    ok([
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'receipt_token' => $receiptToken,
        'gross_amount' => $pricing['gross_amount'],
        'vat_exempt_amount' => $pricing['vat_exempt_amount'],
        'discount_amount' => $pricing['discount_amount'],
        'total' => $total,
    ], 201);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Failed to create order: ' . $e->getMessage(), 500);
}

