<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recipe_helpers.php';
require_once __DIR__ . '/menu_helpers.php';
require_auth();

function extract_waste_ingredient_unit_from_notes(?string $notes): string
{
    $text = (string)$notes;
    if ($text === '') {
        return '';
    }
    if (preg_match('/\bunit:([^\s|]+)/i', $text, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function format_waste_breakdown_label(string $name, float $quantity, string $unit = ''): string
{
    $qtyText = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    if ($qtyText === '') {
        $qtyText = '0';
    }
    $unit = trim($unit);
    if ($unit !== '' && $unit !== 'unit' && $unit !== 'units') {
        return $name . ' (' . $qtyText . ' ' . $unit . ')';
    }
    return $name . ' (' . $qtyText . 'x)';
}

$pdo = db();
ensure_recipe_schema_shared($pdo);
ensure_inventory_items_base_schema($pdo);

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

// Ensure waste_log has the fields used by this module.
$pdo->exec('CREATE TABLE IF NOT EXISTS waste_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT NULL,
    menu_item_id INT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    reason VARCHAR(50) NOT NULL,
    notes TEXT NULL,
    estimated_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    staff_id INT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS inventory_item_id INT NULL');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS menu_item_id INT NULL');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS quantity DECIMAL(10,2) NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS reason VARCHAR(50) NOT NULL DEFAULT "WASTED"');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS notes TEXT NULL');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS estimated_value DECIMAL(10,2) NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS staff_id INT NULL');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
$pdo->exec('ALTER TABLE waste_log ADD COLUMN IF NOT EXISTS parent_waste_id INT NULL');

$m = method();

if ($m === 'GET') {
    $type = strtolower(trim((string)($_GET['type'] ?? 'list')));
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));

    if ($type === 'daily_summary') {
        $sumStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(estimated_value), 0) AS total_estimated_value,
                COALESCE(SUM(quantity), 0) AS total_quantity,
                COUNT(*) AS total_logs
             FROM waste_log
             WHERE DATE(logged_at) = :d'
        );
        $sumStmt->execute([':d' => $date]);
        $summary = $sumStmt->fetch() ?: [];
        ok([
            'summary' => [
                'date' => $date,
                'total_estimated_value' => (float)($summary['total_estimated_value'] ?? 0),
                'total_quantity' => (float)($summary['total_quantity'] ?? 0),
                'total_logs' => (int)($summary['total_logs'] ?? 0),
            ]
        ]);
    }

    $limit = min(max((int)($_GET['limit'] ?? 20), 1), 100);
    $stmt = $pdo->prepare(
        'SELECT
            w.id, w.inventory_item_id, w.menu_item_id, w.parent_waste_id, w.quantity, w.reason, w.notes,
            w.estimated_value, w.logged_at, w.staff_id, u.full_name AS staff_name,
            COALESCE(i.item_name, m.name, "Unknown Item") AS item_name
         FROM waste_log w
         LEFT JOIN users u ON u.id = w.staff_id
         LEFT JOIN inventory_items i ON i.id = w.inventory_item_id
         LEFT JOIN menu_items m ON m.id = w.menu_item_id
         WHERE w.parent_waste_id IS NULL
         ORDER BY w.logged_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $parentIds = array_values(array_filter(array_map(function ($r) { return (int)($r['id'] ?? 0); }, $rows)));
    $childByParent = [];
    if (!empty($parentIds)) {
        $inList = implode(',', array_map('intval', $parentIds));
        $childStmt = $pdo->query(
            'SELECT
                w.id, w.parent_waste_id, w.inventory_item_id, w.menu_item_id, w.quantity, w.reason, w.notes,
                w.estimated_value, w.logged_at,
                COALESCE(i.item_name, m.name, "Unknown Item") AS item_name
             FROM waste_log w
             LEFT JOIN inventory_items i ON i.id = w.inventory_item_id
             LEFT JOIN menu_items m ON m.id = w.menu_item_id
             WHERE w.parent_waste_id IN (' . $inList . ')
             ORDER BY w.id ASC'
        );
        foreach ($childStmt->fetchAll() as $ch) {
            $pid = (int)$ch['parent_waste_id'];
            if (!isset($childByParent[$pid])) $childByParent[$pid] = [];
            $childByParent[$pid][] = [
                'id' => (int)($ch['id'] ?? 0),
                'parent_waste_id' => $pid,
                'inventory_item_id' => $ch['inventory_item_id'] !== null ? (int)$ch['inventory_item_id'] : null,
                'menu_item_id' => $ch['menu_item_id'] !== null ? (int)$ch['menu_item_id'] : null,
                'item_name' => (string)($ch['item_name'] ?? 'Ingredient'),
                'quantity' => (float)($ch['quantity'] ?? 0),
                'reason' => (string)($ch['reason'] ?? ''),
                'notes' => $ch['notes'] !== null ? (string)$ch['notes'] : null,
                'estimated_value' => (float)($ch['estimated_value'] ?? 0),
                'logged_at' => $ch['logged_at'] ?? null,
                'unit' => extract_waste_ingredient_unit_from_notes($ch['notes'] ?? null),
                'display_label' => format_waste_breakdown_label(
                    (string)($ch['item_name'] ?? 'Ingredient'),
                    (float)($ch['quantity'] ?? 0),
                    extract_waste_ingredient_unit_from_notes($ch['notes'] ?? null)
                ),
            ];
        }
    }
    foreach ($rows as &$r) {
        $rid = (int)($r['id'] ?? 0);
        $r['breakdown'] = $childByParent[$rid] ?? [];
    }
    unset($r);
    ok(['entries' => $rows]);
}

if ($m === 'POST') {
    $b = body();
    $itemName = trim($b['item_name'] ?? '');
    $menuItemId = (int)($b['menu_item_id'] ?? 0);
    $variantNotes = trim((string)($b['variant_notes'] ?? $b['variant_signature'] ?? ''));
    $qty = max(1, (int)($b['quantity'] ?? 1));
    $reason = trim($b['reason'] ?? 'wasted');
    $notes = trim($b['notes'] ?? '');
    $staffId = (int)($_SESSION['user_id'] ?? 0);

    $allowedReasons = ['wasted', 'rejected', 'unclaimed', 'spillage', 'expired', 'damaged', 'mistake'];
    if (!in_array(strtolower($reason), $allowedReasons, true)) {
        $reason = 'wasted';
    }
    if ($itemName === '' && $menuItemId <= 0) {
        fail('Item name or menu_item_id is required.');
    }

    $pdo->beginTransaction();
    try {
        $insWaste = $pdo->prepare(
            'INSERT INTO waste_log
                (inventory_item_id, menu_item_id, quantity, reason, notes, estimated_value, staff_id)
             VALUES
                (:inv_id, :menu_id, :qty, :reason, :notes, :val, :staff_id)'
        );
        $insBreakdown = $pdo->prepare(
            'INSERT INTO waste_log
                (inventory_item_id, menu_item_id, parent_waste_id, quantity, reason, notes, estimated_value, staff_id)
             VALUES
                (:inv_id, :menu_id, :parent_waste_id, :qty, :reason, :notes, :val, :staff_id)'
        );

        $menu = null;
        if ($menuItemId > 0) {
            $menuStmt = $pdo->prepare(
                'SELECT id, name, description
                 FROM menu_items
                 WHERE id = :id
                 LIMIT 1'
            );
            $menuStmt->execute([':id' => $menuItemId]);
            $menu = $menuStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($menu && $itemName === '') {
                $itemName = (string)$menu['name'];
            }
        }
        if (!$menu && $itemName !== '') {
            $menuStmt = $pdo->prepare(
                'SELECT id, name, description
                 FROM menu_items
                 WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
                 LIMIT 1'
            );
            $menuStmt->execute([':name' => $itemName]);
            $menu = $menuStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($menu) {
            $menuId = (int)$menu['id'];
            $menuDescription = (string)($menu['description'] ?? '');
            $variantContext = trim($variantNotes . ($variantNotes !== '' && $notes !== '' ? ' ' : '') . $notes);
            $recipeLines = fetch_recipe_lines_for_menu_waste($pdo, $menuId, $menuDescription, $variantContext);

            if (!empty($recipeLines)) {
                $breakdown = [];
                $totalEstimatedValue = 0.0;

                foreach ($recipeLines as $line) {
                    $unitCost = (float)($line['unit_cost'] ?? 0);
                    $recipeQty = (float)($line['quantity'] ?? 0);
                    $deductQty = round($recipeQty * $qty, 2);
                    if ($deductQty <= 0) {
                        continue;
                    }
                    $totalEstimatedValue += round($unitCost * $deductQty, 2);
                }

                $parentWasteNotes = trim($notes);
                if ($parentWasteNotes === '') {
                    $parentWasteNotes = 'Menu waste entry with recipe ingredient breakdown';
                }
                $insWaste->execute([
                    ':inv_id' => null,
                    ':menu_id' => $menuId,
                    ':qty' => $qty,
                    ':reason' => strtoupper($reason),
                    ':notes' => $parentWasteNotes,
                    ':val' => round($totalEstimatedValue, 2),
                    ':staff_id' => $staffId ?: null,
                ]);
                $parentWasteId = (int)$pdo->lastInsertId();

                foreach ($recipeLines as $line) {
                    $ingredientName = (string)($line['ingredient_name'] ?? '');
                    $unit = trim((string)($line['unit'] ?? 'unit'));
                    $unitCost = (float)($line['unit_cost'] ?? 0);
                    $recipeQty = (float)($line['quantity'] ?? 0);
                    $deductQty = round($recipeQty * $qty, 2);
                    if ($ingredientName === '' || $deductQty <= 0) {
                        continue;
                    }

                    $invId = resolve_inventory_item_id(
                        $pdo,
                        isset($line['inventory_item_id']) ? (int)$line['inventory_item_id'] : null,
                        $ingredientName
                    );
                    if ($invId) {
                        deduct_units_in_use_with_refill($pdo, $invId, $deductQty);
                    }

                    $estValue = round($unitCost * $deductQty, 2);
                    $lineNotes = 'Waste breakdown from recipe: ' . $menu['name'] . ' | unit:' . $unit;
                    if ($notes !== '') {
                        $lineNotes .= ' | ' . $notes;
                    }

                    $insBreakdown->execute([
                        ':inv_id' => $invId,
                        ':menu_id' => $menuId,
                        ':parent_waste_id' => $parentWasteId,
                        ':qty' => $deductQty,
                        ':reason' => strtoupper($reason),
                        ':notes' => $lineNotes,
                        ':val' => $estValue,
                        ':staff_id' => $staffId ?: null,
                    ]);

                    $breakdown[] = [
                        'inventory_item_id' => $invId,
                        'item_name' => $ingredientName,
                        'quantity' => $deductQty,
                        'unit' => $unit,
                        'unit_cost' => $unitCost,
                        'estimated_value' => $estValue,
                        'display_label' => format_waste_breakdown_label($ingredientName, $deductQty, $unit),
                    ];
                }

                $pdo->commit();
                ok([
                    'message' => 'Waste record added with recipe ingredient breakdown.',
                    'waste_id' => $parentWasteId,
                    'source_menu_item_id' => $menuId,
                    'source_menu_item_name' => $menu['name'],
                    'estimated_value' => round($totalEstimatedValue, 2),
                    'breakdown' => $breakdown,
                ], 201);
            }

            // Fallback: linked inventory items (legacy applicable-menu links).
            $linkStmt = $pdo->prepare(
                'SELECT DISTINCT i.id, i.item_name, i.unit_cost
                 FROM inventory_applicable_menu am
                 JOIN inventory_items i ON i.id = am.inventory_item_id
                 WHERE am.menu_item_id = :menu_id
                   AND i.is_active = 1'
            );
            $linkStmt->execute([':menu_id' => $menuId]);
            $linkedInventory = $linkStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($linkedInventory)) {
                $breakdown = [];
                $totalEstimatedValue = 0.0;
                $menuOrderEstValue = 0.0;
                foreach ($linkedInventory as $linked) {
                    $menuOrderEstValue += round(((float)$linked['unit_cost']) * $qty, 2);
                }
                $parentWasteNotes = trim($notes);
                if ($parentWasteNotes === '') {
                    $parentWasteNotes = 'Menu waste entry with ingredient breakdown';
                }
                $insWaste->execute([
                    ':inv_id' => null,
                    ':menu_id' => $menuId,
                    ':qty' => $qty,
                    ':reason' => strtoupper($reason),
                    ':notes' => $parentWasteNotes,
                    ':val' => round($menuOrderEstValue, 2),
                    ':staff_id' => $staffId ?: null,
                ]);
                $parentWasteId = (int)$pdo->lastInsertId();

                foreach ($linkedInventory as $linked) {
                    $invId = (int)$linked['id'];
                    $ingredientName = (string)$linked['item_name'];
                    $unitCost = (float)$linked['unit_cost'];
                    $estValue = round($unitCost * $qty, 2);
                    $totalEstimatedValue += $estValue;

                    deduct_units_in_use_with_refill($pdo, $invId, (float)$qty);

                    $lineNotes = 'Waste breakdown from menu item: ' . $menu['name'];
                    if ($notes !== '') {
                        $lineNotes .= ' | ' . $notes;
                    }

                    $insBreakdown->execute([
                        ':inv_id' => $invId,
                        ':menu_id' => $menuId,
                        ':parent_waste_id' => $parentWasteId,
                        ':qty' => $qty,
                        ':reason' => strtoupper($reason),
                        ':notes' => $lineNotes,
                        ':val' => $estValue,
                        ':staff_id' => $staffId ?: null,
                    ]);

                    $breakdown[] = [
                        'inventory_item_id' => $invId,
                        'item_name' => $ingredientName,
                        'quantity' => $qty,
                        'unit' => '',
                        'unit_cost' => $unitCost,
                        'estimated_value' => $estValue,
                        'display_label' => format_waste_breakdown_label($ingredientName, (float)$qty, ''),
                    ];
                }

                $pdo->commit();
                ok([
                    'message' => 'Waste record added with ingredient breakdown.',
                    'waste_id' => $parentWasteId,
                    'source_menu_item_id' => $menuId,
                    'source_menu_item_name' => $menu['name'],
                    'estimated_value' => round($totalEstimatedValue, 2),
                    'breakdown' => $breakdown,
                ], 201);
            }
        }

        // Fallback: normal single-inventory waste flow.
        if ($itemName === '') {
            fail('Item name is required.');
        }

        $invStmt = $pdo->prepare(
            'SELECT id, menu_item_id, stock_units, unit_cost
             FROM inventory_items
             WHERE is_active = 1 AND item_name = :name
             ORDER BY id DESC
             LIMIT 1'
        );
        $invStmt->execute([':name' => $itemName]);
        $inv = $invStmt->fetch();

        if (!$inv) {
            $create = $pdo->prepare(
                'INSERT INTO inventory_items
                    (menu_item_id, item_name, category_name, supplier, stock_units, reorder_level, unit_cost, is_active)
                 VALUES
                    (NULL, :name, "Uncategorized", "Unassigned", 0, 10, 0, 1)'
            );
            $create->execute([':name' => $itemName]);
            $invId = (int)$pdo->lastInsertId();
            $menuItemId = null;
            $unitCost = 0;
        } else {
            $invId = (int)$inv['id'];
            $menuItemId = $inv['menu_item_id'] !== null ? (int)$inv['menu_item_id'] : null;
            $unitCost = (float)$inv['unit_cost'];
            deduct_units_in_use_with_refill($pdo, $invId, (float)$qty);
        }

        $estValue = round($unitCost * $qty, 2);
        $insWaste->execute([
            ':inv_id' => $invId,
            ':menu_id' => $menuItemId,
            ':qty' => $qty,
            ':reason' => strtoupper($reason),
            ':notes' => $notes ?: null,
            ':val' => $estValue,
            ':staff_id' => $staffId ?: null,
        ]);

        $pdo->commit();
        ok(['message' => 'Waste record added.', 'estimated_value' => $estValue], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail('Failed to record waste: ' . $e->getMessage(), 500);
    }
}

fail('Method not allowed.', 405);
