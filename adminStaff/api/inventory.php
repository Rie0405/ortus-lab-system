<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';
require_once __DIR__ . '/recipe_helpers.php';
require_auth();

function ensure_inventory_schema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id INT NULL,
            item_name VARCHAR(140) NOT NULL,
            category_name VARCHAR(100) NOT NULL,
            supplier VARCHAR(140) NULL,
            stock_units INT NOT NULL DEFAULT 0,
            average_daily_usage DECIMAL(12,2) NOT NULL DEFAULT 0,
            lead_time_days INT NOT NULL DEFAULT 7,
            safety_stock DECIMAL(12,2) NOT NULL DEFAULT 10,
            reorder_point_ready TINYINT(1) NOT NULL DEFAULT 0,
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

    $chkInUse = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'units_in_use'");
    if (!$chkInUse || !$chkInUse->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN units_in_use INT NOT NULL DEFAULT 0 AFTER stock_units');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkPerStock = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'per_stock_amount'");
    if (!$chkPerStock || !$chkPerStock->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN per_stock_amount DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER units_in_use');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkPerStockUnit = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'per_stock_unit'");
    if (!$chkPerStockUnit || !$chkPerStockUnit->fetch()) {
        try {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN per_stock_unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER per_stock_amount");
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkOrdersPerBox = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'orders_per_box'");
    if (!$chkOrdersPerBox || !$chkOrdersPerBox->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN orders_per_box INT NOT NULL DEFAULT 0 AFTER per_stock_unit');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkOpenItems = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'open_items_count'");
    if (!$chkOpenItems || !$chkOpenItems->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN open_items_count INT NOT NULL DEFAULT 0 AFTER orders_per_box');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkStockType = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'stock_type'");
    if (!$chkStockType || !$chkStockType->fetch()) {
        try {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN stock_type VARCHAR(20) NOT NULL DEFAULT 'consumable' AFTER open_items_count");
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkAvgUsage = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'average_daily_usage'");
    if (!$chkAvgUsage || !$chkAvgUsage->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN average_daily_usage DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock_units');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkLeadTime = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'lead_time_days'");
    if (!$chkLeadTime || !$chkLeadTime->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN lead_time_days INT NOT NULL DEFAULT 7 AFTER average_daily_usage');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkSafetyStock = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'safety_stock'");
    if (!$chkSafetyStock || !$chkSafetyStock->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN safety_stock DECIMAL(12,2) NOT NULL DEFAULT 10 AFTER lead_time_days');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkReorderReady = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'reorder_point_ready'");
    if (!$chkReorderReady || !$chkReorderReady->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN reorder_point_ready TINYINT(1) NOT NULL DEFAULT 0 AFTER safety_stock');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkEntryMode = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'entry_mode'");
    if (!$chkEntryMode || !$chkEntryMode->fetch()) {
        try {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN entry_mode VARCHAR(20) NOT NULL DEFAULT 'automatic' AFTER stock_type");
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkStockStatus = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'stock_status'");
    if (!$chkStockStatus || !$chkStockStatus->fetch()) {
        try {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN stock_status VARCHAR(20) NOT NULL DEFAULT 'good' AFTER entry_mode");
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkNotes = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'notes'");
    if (!$chkNotes || !$chkNotes->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN notes TEXT NULL AFTER stock_status');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
}

function ensure_inventory_settings_schema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_settings (
            id INT PRIMARY KEY,
            low_stock_threshold INT NOT NULL DEFAULT 10,
            low_stock_fraction_den INT NOT NULL DEFAULT 2,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM inventory_settings WHERE id = 1')->fetchColumn();
    if ($exists === 0) {
        $pdo->exec('INSERT INTO inventory_settings (id, low_stock_threshold, low_stock_fraction_den) VALUES (1, 10, 2)');
    }
    $chk = $pdo->query("SHOW COLUMNS FROM inventory_settings LIKE 'low_stock_fraction_den'");
    if (!$chk || !$chk->fetch()) {
        $pdo->exec('ALTER TABLE inventory_settings ADD COLUMN low_stock_fraction_den INT NOT NULL DEFAULT 2 AFTER low_stock_threshold');
    }
}

function get_low_stock_fraction_den(PDO $pdo): int {
    ensure_inventory_settings_schema($pdo);
    $val = $pdo->query('SELECT low_stock_fraction_den FROM inventory_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    $den = (int)$val;
    return in_array($den, [2, 3, 4, 5], true) ? $den : 2;
}

function set_low_stock_fraction_den(PDO $pdo, int $fractionDen): void {
    ensure_inventory_settings_schema($pdo);
    if (!in_array($fractionDen, [2, 3, 4, 5], true)) {
        fail('Invalid low stock fraction.');
    }
    $stmt = $pdo->prepare('UPDATE inventory_settings SET low_stock_fraction_den = :den WHERE id = 1');
    $stmt->execute([':den' => $fractionDen]);
}

function ensure_inventory_applicable_menu_schema(PDO $pdo): void {
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

/** Migrate legacy (inventory_item_id, menu_item_id) PK → include variant_signature. */
function ensure_inventory_applicable_menu_variant_schema(PDO $pdo): void {
    ensure_inventory_applicable_menu_schema($pdo);
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
        // ignore if already migrated or engine quirk
    }
    $pdo->exec(
        'ALTER TABLE inventory_applicable_menu
         ADD PRIMARY KEY (inventory_item_id, menu_item_id, variant_signature)'
    );
}

/**
 * @param mixed $links list of menu_item_id ints, or list of [ 'menu_item_id' => int, 'variant_signature' => string ]
 */
function sync_applicable_menu_links(PDO $pdo, int $inventoryId, $links): void {
    $pdo->prepare('DELETE FROM inventory_applicable_menu WHERE inventory_item_id = :iid')
        ->execute([':iid' => $inventoryId]);
    if (!is_array($links) || $links === []) {
        return;
    }
    $exists = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id LIMIT 1');
    $ins = $pdo->prepare(
        'INSERT INTO inventory_applicable_menu (inventory_item_id, menu_item_id, variant_signature)
         VALUES (:iid, :mid, :vs)'
    );
    foreach ($links as $raw) {
        $mid = 0;
        $vs = '';
        if (is_array($raw)) {
            $mid = (int)($raw['menu_item_id'] ?? 0);
            $vs = substr(trim((string)($raw['variant_signature'] ?? '')), 0, 160);
        } else {
            $mid = (int)$raw;
        }
        if ($mid <= 0) {
            continue;
        }
        $exists->execute([':id' => $mid]);
        if (!$exists->fetchColumn()) {
            continue;
        }
        try {
            $ins->execute([':iid' => $inventoryId, ':mid' => $mid, ':vs' => $vs]);
        } catch (PDOException $e) {
            // ignore duplicate key
        }
    }
}

/**
 * @param mixed $ids list of menu_item ids (ints) — all use empty variant_signature
 */
function sync_applicable_menu_items(PDO $pdo, int $inventoryId, $ids): void {
    if (!is_array($ids) || $ids === []) {
        sync_applicable_menu_links($pdo, $inventoryId, []);
        return;
    }
    $links = [];
    foreach ($ids as $raw) {
        $mid = (int)$raw;
        if ($mid > 0) {
            $links[] = ['menu_item_id' => $mid, 'variant_signature' => ''];
        }
    }
    sync_applicable_menu_links($pdo, $inventoryId, $links);
}

function extract_removable_ingredients(string $description): array {
    $description = (string)$description;
    $found = [];

    $lines = preg_split('/\r?\n/', $description);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === '') continue;

            if (preg_match('/^(Customizable|Removable)\s+ingredients\s*:\s*(.+)$/i', $trimmed, $m)) {
                $value = $m[2] ?? '';
                $parts = preg_split('/,/', $value);
                if (!is_array($parts)) continue;
                foreach ($parts as $p) {
                    $v = trim((string)$p);
                    if ($v === '') continue;
                    $found[] = $v;
                }
            }
        }
    }

    // Fallback: try to find the ingredient line anywhere in the description.
    if (empty($found)) {
        if (preg_match('/(Customizable|Removable)\s+ingredients\s*:\s*(.+)$/ims', $description, $m)) {
            $value = $m[2] ?? '';
            $parts = preg_split('/,/', $value);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $v = trim((string)$p);
                    if ($v === '') continue;
                    $found[] = $v;
                }
            }
        }
    }

    $found = array_values(array_unique($found));
    return array_values(array_filter($found, function ($x) { return trim((string)$x) !== ''; }));
}

$pdo = db();
ensure_inventory_schema($pdo);
ensure_inventory_items_base_schema($pdo);
ensure_inventory_applicable_menu_schema($pdo);
ensure_inventory_applicable_menu_variant_schema($pdo);
ensure_inventory_settings_schema($pdo);
$method = method();

if ($method === 'POST') {
    $b = body();
    $name = trim($b['item_name'] ?? '');
    $category = trim($b['category_name'] ?? '');
    $supplier = trim($b['supplier'] ?? '');
    $stockUnits = (int)($b['stock_units'] ?? 0);
    $averageDailyUsage = max(0, (float)($b['average_daily_usage'] ?? 0));
    $leadTimeDays = max(0, (int)($b['lead_time_days'] ?? 7));
    $safetyStock = max(0, (float)($b['safety_stock'] ?? ($b['reorder_level'] ?? 10)));
    $reorderPointReady = isset($b['reorder_point_ready']) ? (((int)$b['reorder_point_ready'] === 1) ? 1 : 0) : 0;
    $reorderLevel = max(0, (int)($b['reorder_level'] ?? 10));
    $unitCost = (float)($b['unit_cost'] ?? 0);
    $menuItemId = isset($b['menu_item_id']) && $b['menu_item_id'] !== '' ? (int)$b['menu_item_id'] : null;
    $stockType = normalize_inventory_stock_type($b['stock_type'] ?? 'consumable');

    if ($name === '') fail('Item name is required.');
    if ($category === '') fail('Category is required.');
    if ($stockUnits < 0) fail('Stock units cannot be negative.');

    $stmt = $pdo->prepare(
        'INSERT INTO inventory_items
            (menu_item_id, item_name, category_name, supplier, stock_units, average_daily_usage, lead_time_days, safety_stock, reorder_point_ready, reorder_level, unit_cost, stock_type, is_active)
         VALUES
            (:menu_item_id, :item_name, :category_name, :supplier, :stock_units, :average_daily_usage, :lead_time_days, :safety_stock, :reorder_point_ready, :reorder_level, :unit_cost, :stock_type, 1)'
    );
    $stmt->execute([
        ':menu_item_id' => $menuItemId,
        ':item_name' => $name,
        ':category_name' => $category,
        ':supplier' => $supplier ?: null,
        ':stock_units' => $stockUnits,
        ':average_daily_usage' => $averageDailyUsage,
        ':lead_time_days' => $leadTimeDays,
        ':safety_stock' => $safetyStock,
        ':reorder_point_ready' => $reorderPointReady,
        ':reorder_level' => $reorderLevel,
        ':unit_cost' => $unitCost,
        ':stock_type' => $stockType,
    ]);

    $newId = (int)$pdo->lastInsertId();
    if (array_key_exists('applicable_menu_links', $b) && is_array($b['applicable_menu_links'])) {
        sync_applicable_menu_links($pdo, $newId, $b['applicable_menu_links']);
    } elseif (array_key_exists('applicable_menu_ids', $b) && is_array($b['applicable_menu_ids'])) {
        sync_applicable_menu_items($pdo, $newId, $b['applicable_menu_ids']);
    }

    ok(['id' => $newId, 'message' => 'Inventory item created.'], 201);
}

if ($method === 'PUT') {
    $b = body();
    $action = strtolower(trim((string)($b['action'] ?? '')));
    if ($action === 'restock_all') {
        $targetStock = isset($b['target_stock']) ? (int)$b['target_stock'] : 60;
        if ($targetStock < 0) fail('Target stock must be zero or greater.');

        $stmt = $pdo->prepare(
            'UPDATE inventory_items
             SET stock_units = :target
             WHERE is_active = 1
               AND menu_item_id IS NULL'
        );
        $stmt->execute([':target' => $targetStock]);
        ensure_open_box_when_stock_available($pdo);

        ok([
            'message' => 'All inventory items restocked to normal level.',
            'target_stock' => $targetStock,
            'updated_rows' => $stmt->rowCount(),
        ]);
    }

    if ($action === 'set_low_stock_fraction') {
        $fractionDen = (int)($b['low_stock_fraction_den'] ?? 2);
        set_low_stock_fraction_den($pdo, $fractionDen);
        ok([
            'message' => 'Low stock alert level updated.',
            'low_stock_fraction_den' => $fractionDen,
        ]);
    }

    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) fail('Inventory item ID is required.');

    $fields = [];
    $params = [':id' => $id];

    if (array_key_exists('menu_item_id', $b)) {
        $fields[] = 'menu_item_id = :menu_item_id';
        $params[':menu_item_id'] = ($b['menu_item_id'] === '' || $b['menu_item_id'] === null) ? null : (int)$b['menu_item_id'];
    }
    if (array_key_exists('item_name', $b)) {
        $fields[] = 'item_name = :item_name';
        $params[':item_name'] = trim((string)$b['item_name']);
    }
    if (array_key_exists('category_name', $b)) {
        $fields[] = 'category_name = :category_name';
        $params[':category_name'] = trim((string)$b['category_name']);
    }
    if (array_key_exists('supplier', $b)) {
        $fields[] = 'supplier = :supplier';
        $supplier = trim((string)$b['supplier']);
        $params[':supplier'] = $supplier !== '' ? $supplier : null;
    }
    if (array_key_exists('stock_type', $b)) {
        $fields[] = 'stock_type = :stock_type';
        $params[':stock_type'] = normalize_inventory_stock_type($b['stock_type']);
    }
    if (array_key_exists('entry_mode', $b)) {
        $fields[] = 'entry_mode = :entry_mode';
        $params[':entry_mode'] = normalize_inventory_entry_mode($b['entry_mode']);
    }
    if (array_key_exists('stock_status', $b)) {
        $fields[] = 'stock_status = :stock_status';
        $params[':stock_status'] = normalize_inventory_stock_status($b['stock_status']);
    }
    if (array_key_exists('notes', $b)) {
        $fields[] = 'notes = :notes';
        $normalizedNotes = normalize_inventory_notes($b['notes']);
        $params[':notes'] = $normalizedNotes !== '' ? $normalizedNotes : null;
    }
    if (array_key_exists('stock_units', $b)) {
        $fields[] = 'stock_units = :stock_units';
        $params[':stock_units'] = max(0, (int)$b['stock_units']);
    }
    if (array_key_exists('units_in_use', $b)) {
        $fields[] = 'units_in_use = :units_in_use';
        $params[':units_in_use'] = max(0, (float)$b['units_in_use']);
    }
    if (array_key_exists('open_items_count', $b)) {
        $fields[] = 'open_items_count = :open_items_count';
        $params[':open_items_count'] = max(0, min(inventory_max_open_items(), (int)$b['open_items_count']));
    }

    $countFieldsTouched = array_key_exists('stock_units', $b)
        || array_key_exists('units_in_use', $b)
        || array_key_exists('open_items_count', $b);

    if ($countFieldsTouched) {
        $curStmt = $pdo->prepare(
            'SELECT stock_units, units_in_use, open_items_count, orders_per_box, per_stock_amount, stock_type, category_name
             FROM inventory_items
             WHERE id = :id
             LIMIT 1'
        );
        $curStmt->execute([':id' => $id]);
        $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            fail('Inventory item not found.', 404);
        }

        $mergedRow = array_merge($cur, $b);
        $usesBatch = inventory_uses_batch_logic($mergedRow);
        $stockType = normalize_inventory_stock_type(
            array_key_exists('stock_type', $b) ? $b['stock_type'] : ($cur['stock_type'] ?? 'consumable')
        );
        $capacity = $usesBatch
            ? 0
            : configured_item_capacity(array_merge($cur, $b));
        $targetOpen = array_key_exists('open_items_count', $b)
            ? (int)$b['open_items_count']
            : open_items_count_for_row($cur);
        $targetSealed = array_key_exists('stock_units', $b)
            ? (int)$b['stock_units']
            : (int)$cur['stock_units'];
        $targetOrders = array_key_exists('units_in_use', $b)
            ? (float)$b['units_in_use']
            : (float)$cur['units_in_use'];

        if ($usesBatch) {
            $batchSize = batch_size_for_inventory_row($mergedRow);
            $targetSealed = max(0, $targetSealed);
            $targetOrders = max(0, $targetOrders);
            $targetOrders = min($targetOrders, (float)$batchSize);
            $reconciled = [
                'stock_units' => $targetSealed,
                'units_in_use' => round($targetOrders, 2),
                'open_items_count' => $targetOrders > 0.0001 ? 1 : 0,
            ];
        } else {
            try {
                $reconciled = reconcile_inventory_counts(
                    $targetSealed,
                    $targetOrders,
                    open_items_count_for_row($cur),
                    $targetOpen,
                    array_key_exists('units_in_use', $b) ? $targetOrders : null,
                    max(0, $capacity)
                );
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
        }

        $fields = array_values(array_filter($fields, function ($field) {
            return !preg_match('/^(stock_units|units_in_use|open_items_count)\s*=/', $field);
        }));
        $params = array_filter(
            $params,
            function ($key) {
                return !in_array($key, [':stock_units', ':units_in_use', ':open_items_count'], true);
            },
            ARRAY_FILTER_USE_KEY
        );

        $fields[] = 'stock_units = :stock_units';
        $fields[] = 'units_in_use = :units_in_use';
        $fields[] = 'open_items_count = :open_items_count';
        $params[':stock_units'] = $reconciled['stock_units'];
        $params[':units_in_use'] = $reconciled['units_in_use'];
        $params[':open_items_count'] = $reconciled['open_items_count'];
    }

    if (array_key_exists('per_stock_amount', $b)) {
        $perStockTypeStmt = $pdo->prepare(
            'SELECT stock_type, category_name
             FROM inventory_items
             WHERE id = :id
             LIMIT 1'
        );
        $perStockTypeStmt->execute([':id' => $id]);
        $perStockTypeRow = $perStockTypeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$perStockTypeRow) {
            fail('Inventory item not found.', 404);
        }

        $perStockMerged = array_merge($perStockTypeRow, $b);
        $perStockUsesBatch = inventory_uses_batch_logic($perStockMerged);
        $fields[] = 'per_stock_amount = :per_stock_amount';
        $params[':per_stock_amount'] = max(0.01, (float)$b['per_stock_amount']);
        if ($perStockUsesBatch && !array_key_exists('units_in_use', $b)) {
            $perStockCurStmt = $pdo->prepare(
                'SELECT stock_units, units_in_use
                 FROM inventory_items
                 WHERE id = :id
                 LIMIT 1'
            );
            $perStockCurStmt->execute([':id' => $id]);
            $perStockCur = $perStockCurStmt->fetch(PDO::FETCH_ASSOC);
            if (!$perStockCur) {
                fail('Inventory item not found.', 404);
            }

            $currentOverallStock = max(0, (int)($perStockCur['stock_units'] ?? 0));
            $currentBatchRemaining = max(0, (float)($perStockCur['units_in_use'] ?? 0));
            $newBatchSize = max(1, (float)$b['per_stock_amount']);

            if ($currentOverallStock <= 0 && $currentBatchRemaining <= 0.0001) {
                $fields[] = 'units_in_use = 0';
                $fields[] = 'open_items_count = 0';
            } elseif ($currentBatchRemaining <= 0.0001) {
                $opened = open_initial_batch_from_stock($perStockCur, (int)$newBatchSize);
                $fields[] = 'stock_units = :per_stock_stock_units';
                $fields[] = 'units_in_use = :per_stock_units_in_use';
                $fields[] = 'open_items_count = :per_stock_open_items_count';
                $params[':per_stock_stock_units'] = $opened['stock_units'];
                $params[':per_stock_units_in_use'] = $opened['units_in_use'];
                $params[':per_stock_open_items_count'] = $opened['open_items_count'];
            } else {
                $fields[] = 'units_in_use = LEAST(units_in_use, :per_stock_amount_limit)';
                $fields[] = 'open_items_count = 1';
                $params[':per_stock_amount_limit'] = $newBatchSize;
            }
        }
    }
    if (array_key_exists('per_stock_unit', $b)) {
        $fields[] = 'per_stock_unit = :per_stock_unit';
        $unit = strtolower(trim((string)$b['per_stock_unit']));
        $params[':per_stock_unit'] = ($unit !== '') ? substr($unit, 0, 20) : 'pcs';
    }
    if (array_key_exists('orders_per_box', $b)) {
        $capCurStmt = $pdo->prepare(
            'SELECT stock_units, units_in_use, open_items_count, orders_per_box, category_name, stock_type
             FROM inventory_items
             WHERE id = :id
             LIMIT 1'
        );
        $capCurStmt->execute([':id' => $id]);
        $capCur = $capCurStmt->fetch(PDO::FETCH_ASSOC);
        if (!$capCur) {
            fail('Inventory item not found.', 404);
        }

        $oldCap = (int)($capCur['orders_per_box'] ?? 0);
        $newCapacity = max(0, (int)$b['orders_per_box']);
        $ordersLeft = (float)($capCur['units_in_use'] ?? 0);
        $openCount = open_items_count_for_row($capCur);
        $usesBatch = inventory_uses_batch_logic($capCur);

        $fields[] = 'orders_per_box = :orders_per_box';
        $params[':orders_per_box'] = $newCapacity;

        if ($newCapacity > 0 && !array_key_exists('units_in_use', $b)) {
            if ($usesBatch && is_kitchen_inventory_category($capCur['category_name'] ?? '')) {
                $firstTimeCapacity = ($oldCap <= 0);
                if ($firstTimeCapacity || $ordersLeft <= 0.0001) {
                    $opened = open_initial_batch_from_stock(
                        array_merge($capCur, ['orders_per_box' => $newCapacity]),
                        $newCapacity
                    );
                    $fields[] = 'stock_units = :cap_stock_units';
                    $fields[] = 'units_in_use = :cap_units_in_use';
                    $fields[] = 'open_items_count = :cap_open_items_count';
                    $params[':cap_stock_units'] = $opened['stock_units'];
                    $params[':cap_units_in_use'] = $opened['units_in_use'];
                    $params[':cap_open_items_count'] = $opened['open_items_count'];
                } else {
                    $fields[] = 'units_in_use = LEAST(units_in_use, :cap_units_in_use)';
                    $params[':cap_units_in_use'] = $newCapacity;
                    if ($openCount <= 0) {
                        $fields[] = 'open_items_count = 1';
                    }
                }
            } elseif ($openCount > 0 || $ordersLeft > 0) {
                $wasFullAtOldCap = $oldCap > 0 && abs($ordersLeft - (float)$oldCap) < 0.01;
                $legacyFallbackOpen = ($oldCap <= 1 && $ordersLeft > 0 && $ordersLeft <= 1.01 && $newCapacity > 1);
                $firstTimeCapacity = ($oldCap <= 0);

                if ($firstTimeCapacity || $wasFullAtOldCap || $legacyFallbackOpen) {
                    // Newly configured / was "full" at wrong capacity (often fallback of 1) → fill to capacity.
                    $fields[] = 'units_in_use = :cap_units_in_use';
                    $params[':cap_units_in_use'] = (float)$newCapacity;
                    if ($openCount <= 0) {
                        $fields[] = 'open_items_count = 1';
                    }
                } else {
                    $fields[] = 'units_in_use = LEAST(units_in_use, :cap_units_in_use)';
                    $params[':cap_units_in_use'] = $newCapacity;
                }
            }
        }
    }
    if (array_key_exists('reorder_level', $b)) {
        $fields[] = 'reorder_level = :reorder_level';
        $params[':reorder_level'] = max(0, (int)$b['reorder_level']);
    }
    if (array_key_exists('average_daily_usage', $b)) {
        $fields[] = 'average_daily_usage = :average_daily_usage';
        $params[':average_daily_usage'] = max(0, (float)$b['average_daily_usage']);
    }
    if (array_key_exists('lead_time_days', $b)) {
        $fields[] = 'lead_time_days = :lead_time_days';
        $params[':lead_time_days'] = max(0, (int)$b['lead_time_days']);
    }
    if (array_key_exists('safety_stock', $b)) {
        $fields[] = 'safety_stock = :safety_stock';
        $params[':safety_stock'] = max(0, (float)$b['safety_stock']);
    }
    if (array_key_exists('reorder_point_ready', $b)) {
        $fields[] = 'reorder_point_ready = :reorder_point_ready';
        $params[':reorder_point_ready'] = ((int)$b['reorder_point_ready'] === 1) ? 1 : 0;
    }
    if (array_key_exists('unit_cost', $b)) {
        $fields[] = 'unit_cost = :unit_cost';
        $params[':unit_cost'] = max(0, (float)$b['unit_cost']);
    }

    $hadApplicable = array_key_exists('applicable_menu_ids', $b) || array_key_exists('applicable_menu_links', $b);
    if (array_key_exists('applicable_menu_links', $b) && is_array($b['applicable_menu_links'])) {
        sync_applicable_menu_links($pdo, $id, $b['applicable_menu_links']);
    } elseif (array_key_exists('applicable_menu_ids', $b)) {
        sync_applicable_menu_items($pdo, $id, $b['applicable_menu_ids']);
    }

    if (empty($fields) && !$hadApplicable) {
        fail('No fields to update.');
    }

    if (!empty($fields)) {
        $sql = 'UPDATE inventory_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $openStmt = $pdo->prepare(
            'SELECT open_items_count, units_in_use, category_name, stock_type, orders_per_box, per_stock_amount
             FROM inventory_items
             WHERE id = :id
             LIMIT 1'
        );
        $openStmt->execute([':id' => $id]);
        $after = $openStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (open_items_count_for_row($after) <= 0) {
            if (inventory_uses_batch_logic($after)) {
                ensure_batch_open_when_stock_available($pdo, $id);
            } else {
                ensure_open_box_when_stock_available($pdo, $id);
            }
        }
    }
    ok(['message' => 'Inventory item updated.']);
}

if ($method === 'DELETE') {
    $b = body();
    $id = (int)($b['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) fail('Inventory item ID is required.');
    $stmt = $pdo->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = :id');
    $stmt->execute([':id' => $id]);
    ok(['message' => 'Inventory item archived.']);
}

if ($method !== 'GET') {
    fail('Method not allowed.', 405);
}

ensure_open_box_when_stock_available($pdo);
ensure_batch_open_when_stock_available($pdo);

$stmt = $pdo->query(
    'SELECT
        i.id,
        i.menu_item_id,
        i.item_name,
        i.category_name,
        i.supplier,
        i.stock_units,
        i.average_daily_usage,
        i.lead_time_days,
        i.safety_stock,
        i.reorder_point_ready,
        i.units_in_use,
        i.per_stock_amount,
        i.per_stock_unit,
        i.orders_per_box,
        i.open_items_count,
        i.stock_type,
        i.entry_mode,
        i.stock_status,
        i.notes,
        i.reorder_level,
        i.unit_cost,
        i.is_active,
        i.created_at,
        i.updated_at,
        COALESCE(SUM(CASE
            WHEN o.status IN ("confirmed","served")
             AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            THEN oi.quantity ELSE 0 END), 0) AS sold_7d,
        COALESCE(SUM(CASE
            WHEN o.status IN ("confirmed","served")
             AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            THEN oi.quantity ELSE 0 END), 0) AS sold_30d
     FROM inventory_items i
     LEFT JOIN order_items oi ON oi.menu_item_id = i.menu_item_id
     LEFT JOIN orders o ON o.id = oi.order_id
     WHERE i.is_active = 1
       AND i.menu_item_id IS NULL
     GROUP BY i.id
     ORDER BY i.category_name, i.item_name'
);
$rows = $stmt->fetchAll();

$linksByInv = [];
$linkStmt = $pdo->query(
    'SELECT inventory_item_id, menu_item_id, variant_signature FROM inventory_applicable_menu'
);
foreach ($linkStmt->fetchAll() as $lr) {
    $iid = (int)$lr['inventory_item_id'];
    if (!isset($linksByInv[$iid])) {
        $linksByInv[$iid] = [];
    }
    $linksByInv[$iid][] = [
        'menu_item_id'       => (int)$lr['menu_item_id'],
        'variant_signature'  => (string)($lr['variant_signature'] ?? ''),
    ];
}

// Compute applicable menu items from each menu item's removable ingredients.
// This keeps the inventory checklist in sync even if the admin never manually selected items.
$invIdsByName = [];
foreach ($rows as $row) {
    $name = strtolower(trim((string)($row['item_name'] ?? '')));
    if ($name === '') continue;
    $invIdsByName[$name][] = (int)$row['id'];
}

$computedLinksByInv = [];
$menuRows = $pdo->query('SELECT id, description FROM menu_items')->fetchAll();
foreach ($menuRows as $mr) {
    $menuId = (int)$mr['id'];
    $ingredients = extract_removable_ingredients((string)($mr['description'] ?? ''));
    foreach ($ingredients as $ing) {
        $key = strtolower(trim((string)$ing));
        if ($key === '') continue;
        if (!isset($invIdsByName[$key])) continue;
        foreach ($invIdsByName[$key] as $invId) {
            if (!isset($computedLinksByInv[$invId])) $computedLinksByInv[$invId] = [];
            $computedLinksByInv[$invId][] = $menuId;
        }
    }
}
foreach ($computedLinksByInv as $iid => $arr) {
    $computedLinksByInv[$iid] = array_values(array_unique(array_map('intval', $arr)));
}

$lowStockFractionDen = get_low_stock_fraction_den($pdo);

$items = [];
foreach ($rows as $row) {
    $stockUnits = (int)$row['stock_units'];
    $ordersLeft = (float)($row['units_in_use'] ?? 0);
    $ordersPerBoxRaw = (int)($row['orders_per_box'] ?? 0);
    $openItemsCount = open_items_count_for_row($row);
    $averageDailyUsage = (float)($row['average_daily_usage'] ?? 0);
    $leadTimeDays = (int)($row['lead_time_days'] ?? 7);
    $safetyStock = (float)($row['safety_stock'] ?? ($row['reorder_level'] ?? 0));
    $reorderPointReady = ((int)($row['reorder_point_ready'] ?? 0) === 1) ? 1 : 0;
    $totalAvailableOrders = total_available_orders_for_inventory_row($row);
    $maxCapacity = max_capacity_for_inventory_row($row);
    $stockRatio = inventory_stock_ratio($row);
    $status = 'in_stock';
    if ($totalAvailableOrders <= 0) {
        $status = 'out_of_stock';
    } elseif (is_low_stock_for_inventory_row($row, $lowStockFractionDen)) {
        $status = 'low_stock';
    }

    $computedIds = $computedLinksByInv[(int)$row['id']] ?? [];
    $computedLinks = [];
    foreach ($computedIds as $mid) {
        $computedLinks[] = ['menu_item_id' => (int)$mid, 'variant_signature' => ''];
    }
    $saved = $linksByInv[(int)$row['id']] ?? [];
    $finalLinks = !empty($computedLinks) ? $computedLinks : $saved;
    $finalMenuIds = [];
    foreach ($finalLinks as $fl) {
        $finalMenuIds[] = (int)($fl['menu_item_id'] ?? 0);
    }
    $finalMenuIds = array_values(array_unique(array_filter($finalMenuIds)));

    $items[] = [
        'id'            => (int)$row['id'],
        'menu_item_id'  => $row['menu_item_id'] !== null ? (int)$row['menu_item_id'] : null,
        'name'          => $row['item_name'],
        'category_name' => $row['category_name'],
        'supplier'      => $row['supplier'] ?: 'Unassigned',
        'unit_cost'     => (float)$row['unit_cost'],
        'stock_units'   => $stockUnits,
        'units_in_use'  => $ordersLeft,
        'orders_left'   => $ordersLeft,
        'orders_per_box' => $ordersPerBoxRaw,
        'item_capacity_configured' => $ordersPerBoxRaw > 0 ? 1 : 0,
        'open_items_count' => $openItemsCount,
        'stock_type' => normalize_inventory_stock_type($row['stock_type'] ?? 'consumable'),
        'entry_mode' => normalize_inventory_entry_mode($row['entry_mode'] ?? 'automatic'),
        'stock_status' => normalize_inventory_stock_status($row['stock_status'] ?? 'good'),
        'notes' => (string)($row['notes'] ?? ''),
        'average_daily_usage' => $averageDailyUsage,
        'lead_time_days' => $leadTimeDays,
        'safety_stock' => $safetyStock,
        'reorder_point_ready' => $reorderPointReady,
        'per_stock_amount' => (float)($row['per_stock_amount'] ?? 1),
        'per_stock_unit' => (string)($row['per_stock_unit'] ?? 'pcs'),
        'reorder_level' => $lowStockFractionDen,
        'total_available' => $totalAvailableOrders,
        'max_capacity' => $maxCapacity,
        'stock_ratio' => round($stockRatio, 4),
        'is_active'     => (bool)$row['is_active'],
        'sold_7d'       => (int)$row['sold_7d'],
        'sold_30d'      => (int)$row['sold_30d'],
        'status'        => $status,
        'created_at'    => $row['created_at'],
        'updated_at'    => $row['updated_at'],
        'applicable_menu_ids'   => $finalMenuIds,
        'applicable_menu_links' => $finalLinks,
    ];
}

$lowAlerts = array_values(array_filter($items, function ($item) {
    return $item['status'] !== 'in_stock';
}));
usort($lowAlerts, function ($a, $b) {
    $totalA = total_available_orders_for_inventory_row($a);
    $totalB = total_available_orders_for_inventory_row($b);
    return $totalA <=> $totalB;
});

$mainCategories = fetch_active_main_categories($pdo);
$categoryNames = array_map(static function ($row) {
    return (string)$row['name'];
}, $mainCategories);

ok([
    'items'         => $items,
    'categories'    => $categoryNames,
    'main_categories' => $mainCategories,
    'low_alerts'    => $lowAlerts,
    'low_stock_fraction_den' => $lowStockFractionDen,
    'total_items'   => count($items),
    'low_stock_cnt' => count(array_filter($items, function ($i) { return $i['status'] === 'low_stock'; })),
    'out_stock_cnt' => count(array_filter($items, function ($i) { return $i['status'] === 'out_of_stock'; })),
]);
