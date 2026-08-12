<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/menu_helpers.php';
require_once __DIR__ . '/receipt_helpers.php';
require_once __DIR__ . '/recipe_helpers.php';
require_once __DIR__ . '/cashflow_helpers.php';
require_auth();   // admin or staff

$m = method();

function receipt_expense_for_day(PDO $pdo, string $date): float {
    static $hasReceiptsTable = null;
    if ($hasReceiptsTable === null) {
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'Receipts'");
            $hasReceiptsTable = (bool)$chk->fetchColumn();
        } catch (Throwable $e) {
            $hasReceiptsTable = false;
        }
    }
    if (!$hasReceiptsTable) {
        return 0.0;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(TotalAmount), 0)
             FROM Receipts
             WHERE `Date` = :d'
        );
        $stmt->execute([':d' => $date]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function ensure_inventory_schema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id INT NULL,
            item_name VARCHAR(140) NOT NULL,
            category_name VARCHAR(100) NOT NULL,
            supplier VARCHAR(140) NULL,
            stock_units INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 10,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inventory_menu_item (menu_item_id),
            CONSTRAINT fk_inventory_menu_item
                FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $checks = [
        ['units_in_use', 'ALTER TABLE inventory_items ADD COLUMN units_in_use INT NOT NULL DEFAULT 0 AFTER stock_units'],
        ['per_stock_amount', 'ALTER TABLE inventory_items ADD COLUMN per_stock_amount DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER units_in_use'],
        ['per_stock_unit', "ALTER TABLE inventory_items ADD COLUMN per_stock_unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER per_stock_amount"],
    ];
    foreach ($checks as $pair) {
        $chk = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE '" . $pair[0] . "'");
        if (!$chk || !$chk->fetch()) {
            try {
                $pdo->exec($pair[1]);
            } catch (Throwable $e) {
                // Ignore migration issues on environments with restricted ALTER privileges.
            }
        }
    }
}

/**
 * Ensure junction table exists (POST create order may run before any inventory GET).
 */
function ensure_inventory_applicable_menu_schema_orders(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_applicable_menu (
            inventory_item_id INT NOT NULL,
            menu_item_id INT NOT NULL,
            variant_signature VARCHAR(160) NOT NULL DEFAULT \'\',
            PRIMARY KEY (inventory_item_id, menu_item_id, variant_signature),
            INDEX idx_inventory_applicable_menu_item (menu_item_id),
            CONSTRAINT fk_inv_applicable_inv FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_inv_applicable_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * Migrate legacy 2-column PK → include variant_signature.
 * Duplicated here so POST orders does not require loading inventory.php (which runs GET handlers).
 */
function ensure_inventory_applicable_menu_variant_schema_orders(PDO $pdo): void {
    $chk = $pdo->query("SHOW COLUMNS FROM inventory_applicable_menu LIKE 'variant_signature'");
    if ($chk && $chk->fetch()) {
        return;
    }
    $pdo->exec(
        "ALTER TABLE inventory_applicable_menu
         ADD COLUMN variant_signature VARCHAR(160) NOT NULL DEFAULT '' AFTER menu_item_id"
    );
    try {
        $pdo->exec('ALTER TABLE inventory_applicable_menu DROP PRIMARY KEY');
    } catch (Throwable $e) {
        // ignore
    }
    $pdo->exec(
        'ALTER TABLE inventory_applicable_menu
         ADD PRIMARY KEY (inventory_item_id, menu_item_id, variant_signature)'
    );
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

function ensure_order_customer_name_schema(PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'customer_name'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE orders
         ADD COLUMN customer_name VARCHAR(160) NULL DEFAULT NULL AFTER order_type"
    );
}

function ensure_order_refund_reason_schema(PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'refund_reason'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE orders
         ADD COLUMN refund_reason VARCHAR(200) NULL DEFAULT NULL AFTER customer_name"
    );
}

// ─── GET  →  list orders (+ optional daily summary) ──────────────────────────
if ($m === 'GET') {
    ensure_order_source_schema(db());
    ensure_order_type_schema(db());
    ensure_order_customer_name_schema(db());
    ensure_order_refund_reason_schema(db());
    ensure_order_discount_schema(db());
    $type = $_GET['type'] ?? 'list';
    $date = $_GET['date'] ?? date('Y-m-d');

    if ($type === 'daily_summary') {
        // Aggregate revenue for orders already confirmed by staff (POS/Kiosk) or served.
        $stmt = db()->prepare(
            'SELECT
                COUNT(CASE WHEN (status IN ("confirmed","served","voided")) THEN 1 END) AS total_orders,
                COALESCE(SUM(CASE WHEN (status IN ("confirmed","served")) THEN total_amount END), 0) AS total_revenue,
                COALESCE(SUM(CASE WHEN (status IN ("confirmed","served")) THEN gross_amount END), 0) AS gross_revenue,
                COALESCE(SUM(CASE WHEN (status IN ("confirmed","served")) THEN discount_amount END), 0) AS discount_total,
                COALESCE(SUM(CASE WHEN (status IN ("confirmed","served")) AND payment_method="gcash" THEN total_amount END), 0) AS digital_revenue,
                COALESCE(SUM(CASE WHEN (status IN ("confirmed","served")) AND payment_method="cash"  THEN total_amount END), 0) AS cash_revenue,
                COUNT(CASE WHEN (status IN ("confirmed","served")) THEN 1 END) AS confirmed_count,
                COUNT(CASE WHEN status = "voided" THEN 1 END) AS voided_count
             FROM orders
            WHERE DATE(created_at) = :d'
        );
        $stmt->execute([':d' => $date]);
        $summary = $stmt->fetch();
        $receiptExpense = receipt_expense_for_day(db(), $date);
        $rawRevenue = (float)($summary['total_revenue'] ?? 0);
        $summary['receipt_expense_total'] = $receiptExpense;
        $summary['raw_total_revenue'] = $rawRevenue;
        $summary['total_revenue'] = $rawRevenue - $receiptExpense;

        // Also get hourly breakdown
        $hourly = db()->prepare(
            'SELECT HOUR(created_at) AS hour, COALESCE(SUM(total_amount),0) AS revenue
               FROM orders
              WHERE DATE(created_at) = :d
               AND status IN ("confirmed","served")
              GROUP BY HOUR(created_at)
              ORDER BY hour'
        );
        $hourly->execute([':d' => $date]);

        ok(['summary' => $summary, 'hourly' => $hourly->fetchAll()]);
    }

    // Default: paginated order list
    $status = $_GET['status'] ?? '';
    $source = strtolower(trim($_GET['source'] ?? ''));
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $sql    = 'SELECT o.id, o.order_number, o.order_source, o.status, o.payment_method, o.order_type,
                      o.staff_id,
                      o.customer_name, o.refund_reason,
                      o.discount_type, o.discount_customer_name, o.discount_id_number,
                      o.gross_amount, o.vat_exempt_amount, o.discount_amount, o.total_amount, o.created_at,
                      u.full_name AS staff_name
                 FROM orders o
            LEFT JOIN users u ON u.id = o.staff_id';
    $params = [];

    $where = [];
    if ($status) {
        $where[] = 'o.status = :st';
        $params[':st'] = $status;
    }
    $filterDate = trim($_GET['date'] ?? '');
    if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
        $where[] = 'DATE(o.created_at) = :fd';
        $params[':fd'] = $filterDate;
    }
    $filterStaffId = (int)($_GET['staff_id'] ?? 0);
    if ($filterStaffId > 0) {
        $where[] = 'o.staff_id = :fsid';
        $params[':fsid'] = $filterStaffId;
    }
    if (in_array($source, ['pos', 'kiosk'], true)) {
        $where[] = 'o.order_source = :src';
        $params[':src'] = $source;
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.created_at DESC LIMIT :lim OFFSET :off';

    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();

    // Attach items for each order
    if (!empty($orders)) {
        $ids = implode(',', array_column($orders, 'id'));
        $items = db()->query(
            "SELECT oi.order_id, oi.menu_item_id, mi.name, oi.quantity, oi.unit_price, oi.subtotal, oi.notes,
                    COALESCE(c.name, '') AS category_name
               FROM order_items oi
               JOIN menu_items mi ON mi.id = oi.menu_item_id
               LEFT JOIN categories c ON c.id = mi.category_id
              WHERE oi.order_id IN ($ids)"
        )->fetchAll();

        $itemMap = [];
        foreach ($items as $item) {
            $itemMap[$item['order_id']][] = $item;
        }
        foreach ($orders as &$order) {
            $order['staff_id'] = (int)($order['staff_id'] ?? 0);
            $order['gross_amount'] = (float)$order['gross_amount'];
            $order['vat_exempt_amount'] = (float)$order['vat_exempt_amount'];
            $order['discount_amount'] = (float)$order['discount_amount'];
            $order['total_amount'] = (float)$order['total_amount'];
            $order['items'] = $itemMap[$order['id']] ?? [];
        }
        unset($order);
    }

    ok(['orders' => $orders, 'count' => count($orders)]);
}

// ─── POST  →  create order ────────────────────────────────────────────────────
if ($m === 'POST') {
    ensure_order_source_schema(db());
    ensure_order_type_schema(db());
    ensure_order_customer_name_schema(db());
    ensure_order_refund_reason_schema(db());
    ensure_order_discount_schema(db());
    $b     = body();
    $items = $b['items'] ?? [];

    if (empty($items)) fail('Order must have at least one item.');

    $paymentMethod  = in_array($b['payment_method'] ?? '', ['cash','gcash']) ? $b['payment_method'] : 'cash';
    $amountReceived = isset($b['amount_received']) ? (float)$b['amount_received'] : null;
    $gcashRef       = trim($b['gcash_ref'] ?? '');
    $customerName   = trim((string)($b['customer_name'] ?? ''));
    if (strlen($customerName) > 160) {
        $customerName = substr($customerName, 0, 160);
    }
    $orderSource    = strtolower(trim($b['order_source'] ?? 'pos'));
    $discount       = normalize_order_discount_payload($b['discount'] ?? null);
    $sessionRole    = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
    $staffId        = (int)$_SESSION['user_id'];

    if ($sessionRole === 'admin') {
        $actingStaffId = (int)($b['acting_staff_id'] ?? 0);
        if (!$actingStaffId) {
            fail('Select a cashier before completing the sale.');
        }
        $actingStmt = db()->prepare(
            'SELECT id FROM users WHERE id = :id AND role = \'staff\' AND is_active = 1 LIMIT 1'
        );
        $actingStmt->execute([':id' => $actingStaffId]);
        if (!$actingStmt->fetch()) {
            fail('Invalid cashier selected.');
        }
        $staffId = $actingStaffId;
    }
    if (!in_array($orderSource, ['pos', 'kiosk'], true)) $orderSource = 'pos';

    $rawOrderType = strtolower(trim((string)($b['order_type'] ?? 'dine_in')));
    $orderType    = in_array($rawOrderType, ['dine_in', 'take_out'], true) ? $rawOrderType : 'dine_in';

    $pdo = db();
    // DDL must run outside beginTransaction — implicit commit would leave nothing to commit().
    ensure_inventory_schema($pdo);
    ensure_inventory_applicable_menu_schema_orders($pdo);
    ensure_inventory_applicable_menu_variant_schema_orders($pdo);
    ensure_order_inventory_deduction_schema($pdo);
    ensure_order_items_cost_schema($pdo);
    ensure_receipt_token_schema($pdo);

    $shortages = check_inventory_shortages_for_cart($pdo, $items);
    if ($shortages) {
        respond_inventory_shortage($shortages, false);
    }

    $receiptToken = generate_receipt_token();
    $pdo->beginTransaction();

    try {
        // Generate unique order number
        $orderNumber = 'GC-' . strtoupper(substr(uniqid(), -6));

        $grossAmount = 0;
        $itemRows = [];
        foreach ($items as $item) {
            $menuId  = (int)$item['menu_item_id'];
            $qty     = max(1, (int)$item['quantity']);
            $notes   = trim($item['notes'] ?? '');

            // Resolve price from menu + variant notes (matches POS cart pricing).
            $priceRow = $pdo->prepare('SELECT price, description FROM menu_items WHERE id = :id AND is_available = 1');
            $priceRow->execute([':id' => $menuId]);
            $row = $priceRow->fetch();
            if (!$row) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                fail("Menu item #$menuId not found or unavailable.");
            }
            $clientUnitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : null;
            $unitPrice = resolve_menu_item_unit_price($row, $notes, $clientUnitPrice);
            $subtotal  = $unitPrice * $qty;
            $grossAmount += $subtotal;
            $itemRows[] = [$menuId, $qty, $unitPrice, $subtotal, $notes];
        }

        validate_order_discount_payload($discount);
        $pricing = calculate_order_discount_breakdown($grossAmount, $discount);
        $total = (float)$pricing['total_amount'];

        $changeDue = ($paymentMethod === 'cash' && $amountReceived !== null)
            ? round($amountReceived - $total, 2)
            : null;

        $ins = $pdo->prepare(
            'INSERT INTO orders
                (order_number, receipt_token, order_source, staff_id, status, payment_method, order_type, customer_name, discount_type, discount_customer_name,
                 discount_id_number, gross_amount, vat_exempt_amount, discount_amount, discount_rate, total_amount, amount_received, change_due, gcash_ref)
             VALUES (:num, :rtok, :src, :sid, "confirmed", :pm, :otype, :cname, :dtype, :dname, :did, :gross, :vat_exempt, :discount_amount, :drate, :total, :recv, :change, :ref)'
        );
        $ins->execute([
            ':num'    => $orderNumber,
            ':rtok'   => $receiptToken,
            ':src'    => $orderSource,
            ':sid'    => $staffId,
            ':pm'     => $paymentMethod,
            ':otype'  => $orderType,
            ':cname'  => $customerName !== '' ? $customerName : null,
            ':dtype'  => $pricing['discount_type'],
            ':dname'  => in_array($discount['type'], ['senior', 'pwd'], true) ? $discount['customer_name'] : null,
            ':did'    => in_array($discount['type'], ['senior', 'pwd'], true) ? $discount['id_number'] : null,
            ':gross'  => $pricing['gross_amount'],
            ':vat_exempt' => $pricing['vat_exempt_amount'],
            ':discount_amount' => $pricing['discount_amount'],
            ':drate'  => $discount['type'] === 'custom' ? $discount['rate'] : null,
            ':total'  => $total,
            ':recv'   => $amountReceived,
            ':change' => $changeDue,
            ':ref'    => $gcashRef ?: null,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, subtotal, unit_cost, line_cost, notes)
             VALUES (:oid, :mid, :qty, :up, :sub, :unit_cost, :line_cost, :notes)'
        );
        foreach ($itemRows as [$mid, $qty, $up, $sub, $notes]) {
            $costSnapshot = snapshot_order_line_cost($pdo, (int)$mid, (int)$qty, $notes);
            $insItem->execute([
                ':oid'   => $orderId,
                ':mid'   => $mid,
                ':qty'   => $qty,
                ':up'    => $up,
                ':sub'   => $sub,
                ':unit_cost' => $costSnapshot['unit_cost'],
                ':line_cost' => $costSnapshot['line_cost'],
                ':notes' => $notes ?: null,
            ]);
        }

        // Deduct inventory immediately when the order is punched.
        apply_order_inventory_deduction($pdo, $orderId);

        $pdo->commit();
        publish_realtime_event('order_created', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'order_source' => $orderSource,
            'status' => 'confirmed',
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('Failed to create order: ' . $e->getMessage(), 500);
    }
}

// ─── PUT  →  update order status ─────────────────────────────────────────────
if ($m === 'PUT') {
    $b      = body();
    $id     = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';

    if (!$id) fail('Order ID required.');
    if (!in_array($status, ['confirmed','served','voided'])) fail('Invalid status.');

    $pdo = db();
    // Run schema guard outside explicit transaction. DDL (CREATE TABLE) can
    // implicitly commit in MySQL and break transaction state.
    ensure_inventory_schema($pdo);
    ensure_inventory_applicable_menu_schema_orders($pdo);
    ensure_inventory_applicable_menu_variant_schema_orders($pdo);
    ensure_order_refund_reason_schema($pdo);
    $pdo->beginTransaction();
    try {
        $prevStmt = $pdo->prepare('SELECT status, order_source FROM orders WHERE id = :id FOR UPDATE');
        $prevStmt->execute([':id' => $id]);
        $prev = $prevStmt->fetch();
        if (!$prev) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail('Order not found.', 404);
        }
        $prevStatus = $prev['status'];
        $orderSource = strtolower((string)($prev['order_source'] ?? ''));

        $refundReason = null;
        if ($status === 'voided') {
            $refundReason = trim((string)($b['refund_reason'] ?? ''));
            if ($refundReason === '') {
                if ($pdo->inTransaction()) $pdo->rollBack();
                fail('Refund reason is required.');
            }
            if (strlen($refundReason) > 200) {
                $refundReason = substr($refundReason, 0, 200);
            }
            $pdo->prepare('UPDATE orders SET status = :st, refund_reason = :reason WHERE id = :id')
                ->execute([':st' => $status, ':reason' => $refundReason, ':id' => $id]);
        } else {
            $pdo->prepare('UPDATE orders SET status = :st WHERE id = :id')
                ->execute([':st' => $status, ':id' => $id]);
        }

        $pdo->commit();
        publish_realtime_event('order_status_changed', [
            'order_id' => $id,
            'order_source' => $orderSource,
            'from_status' => $prevStatus,
            'status' => $status,
        ]);
        ok(['message' => 'Order status updated.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('Failed to update order status: ' . $e->getMessage(), 500);
    }
}

fail('Method not allowed.', 405);
