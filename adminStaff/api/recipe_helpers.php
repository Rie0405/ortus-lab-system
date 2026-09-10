<?php

require_once __DIR__ . '/menu_helpers.php';

function ensure_recipe_schema_shared(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id INT NOT NULL,
            variant_signature VARCHAR(160) NOT NULL DEFAULT "",
            recipe_type ENUM("made_to_order","batch") NOT NULL DEFAULT "made_to_order",
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross_profit DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_recipes_menu_variant (menu_item_id, variant_signature),
            CONSTRAINT fk_recipes_menu_item
                FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $chkVariant = $pdo->query("SHOW COLUMNS FROM recipes LIKE 'variant_signature'");
    if (!$chkVariant || !$chkVariant->fetch()) {
        try {
            $pdo->exec(
                'ALTER TABLE recipes
                    ADD COLUMN variant_signature VARCHAR(160) NOT NULL DEFAULT "" AFTER menu_item_id'
            );
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    try {
        $pdo->exec('ALTER TABLE recipes DROP INDEX uq_recipes_menu_item');
    } catch (Throwable $e) {
        // Index may not exist on newer schemas.
    }
    try {
        $idxChk = $pdo->query("SHOW INDEX FROM recipes WHERE Key_name = 'uq_recipes_menu_variant'");
        if (!$idxChk || !$idxChk->fetch()) {
            $pdo->exec('ALTER TABLE recipes ADD UNIQUE KEY uq_recipes_menu_variant (menu_item_id, variant_signature)');
        }
    } catch (Throwable $e) {
        // Ignore migration issues on environments with restricted ALTER privileges.
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recipe_ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            inventory_item_id INT NULL,
            ingredient_name VARCHAR(140) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit VARCHAR(20) NOT NULL DEFAULT "unit",
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_recipe_ingredients_recipe
                FOREIGN KEY (recipe_id) REFERENCES recipes(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_recipe_ingredients_inventory
                FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensure_inventory_units_in_use_decimal(PDO $pdo): void {
    $chk = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'units_in_use'");
    $col = $chk ? $chk->fetch(PDO::FETCH_ASSOC) : false;
    if (!$col) {
        return;
    }
    $type = strtolower((string)($col['Type'] ?? ''));
    if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE inventory_items MODIFY COLUMN units_in_use DECIMAL(12,2) NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // Ignore migration issues on environments with restricted ALTER privileges.
    }
}

function ensure_inventory_items_base_schema(PDO $pdo): void {
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
            INDEX idx_inventory_menu_item (menu_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $checks = [
        ['units_in_use', 'ALTER TABLE inventory_items ADD COLUMN units_in_use DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock_units'],
        ['per_stock_amount', 'ALTER TABLE inventory_items ADD COLUMN per_stock_amount DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER units_in_use'],
        ['per_stock_unit', "ALTER TABLE inventory_items ADD COLUMN per_stock_unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER per_stock_amount"],
        ['orders_per_box', 'ALTER TABLE inventory_items ADD COLUMN orders_per_box INT NOT NULL DEFAULT 0 AFTER per_stock_unit'],
        ['open_items_count', 'ALTER TABLE inventory_items ADD COLUMN open_items_count INT NOT NULL DEFAULT 0 AFTER orders_per_box'],
        ['stock_type', "ALTER TABLE inventory_items ADD COLUMN stock_type VARCHAR(20) NOT NULL DEFAULT 'consumable' AFTER open_items_count"],
        ['entry_mode', "ALTER TABLE inventory_items ADD COLUMN entry_mode VARCHAR(20) NOT NULL DEFAULT 'automatic' AFTER stock_type"],
        ['stock_status', "ALTER TABLE inventory_items ADD COLUMN stock_status VARCHAR(20) NOT NULL DEFAULT 'good' AFTER entry_mode"],
        ['notes', 'ALTER TABLE inventory_items ADD COLUMN notes TEXT NULL AFTER stock_status'],
        ['category_type', "ALTER TABLE inventory_items ADD COLUMN category_type VARCHAR(40) NOT NULL DEFAULT 'main' AFTER category_name"],
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

    ensure_inventory_units_in_use_decimal($pdo);

    try {
        $pdo->exec(
            'UPDATE inventory_items
             SET open_items_count = 1,
                 stock_units = GREATEST(0, stock_units - 1)
             WHERE open_items_count = 0
               AND units_in_use > 0
               AND stock_units > 0
               AND is_active = 1
               AND COALESCE(stock_type, \'consumable\') = \'consumable\''
        );
    } catch (Throwable $e) {
        // Best-effort backfill for legacy rows.
    }

    try {
        $pdo->exec(
            "UPDATE inventory_items
             SET stock_type = 'non_consumable'
             WHERE is_active = 1
               AND menu_item_id IS NULL
               AND (stock_type IS NULL OR stock_type = '' OR stock_type = 'consumable')
               AND (
                    LOWER(item_name) LIKE '%cup%'
                    OR LOWER(item_name) LIKE '%lid%'
                    OR LOWER(item_name) LIKE '%straw%'
                    OR LOWER(item_name) LIKE '%napkin%'
               )"
        );
    } catch (Throwable $e) {
        // Best-effort: cups/packaging → non-consumable.
    }
}

function normalize_inventory_stock_type($type): string
{
    $t = strtolower(trim((string)$type));
    if ($t === 'non_consumable' || $t === 'non-consumable' || $t === 'nonconsumable') {
        return 'non_consumable';
    }
    return 'consumable';
}

function normalize_inventory_entry_mode($mode): string
{
    $m = strtolower(trim((string)$mode));
    return $m === 'manual' ? 'manual' : 'automatic';
}

function normalize_inventory_stock_status($status): string
{
    $s = strtolower(trim((string)$status));
    return in_array($s, ['good', 'low', 'critical'], true) ? $s : 'good';
}

function inventory_is_manual_entry(array $row): bool
{
    return normalize_inventory_entry_mode($row['entry_mode'] ?? 'automatic') === 'manual';
}

/**
 * Dashboard/alert status.
 * Manual items use the staff checklist (good/low/critical), not sealed quantity.
 *
 * @return 'in_stock'|'low_stock'|'out_of_stock'
 */
function inventory_computed_status_for_row(array $row, int $alertPercent): string
{
    if (inventory_is_manual_entry($row)) {
        $manual = normalize_inventory_stock_status($row['stock_status'] ?? 'good');
        if ($manual === 'critical') {
            return 'out_of_stock';
        }
        if ($manual === 'low') {
            return 'low_stock';
        }
        return 'in_stock';
    }

    $total = total_available_orders_for_inventory_row($row);
    if ($total <= 0) {
        return 'out_of_stock';
    }
    if (is_low_stock_for_inventory_row($row, $alertPercent)) {
        return 'low_stock';
    }
    return 'in_stock';
}

function normalize_inventory_notes($notes): string
{
    $value = trim((string)$notes);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 500);
    }
    return substr($value, 0, 500);
}

function ensure_inventory_category_types_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_category_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL,
            name VARCHAR(80) NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inv_cat_type_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $seeds = [
        ['main', 'Main', 1],
        ['ice', 'Ice', 2],
        ['sauce', 'Sauce', 3],
        ['chocolate', 'Chocolate', 4],
        ['syrup', 'Syrup', 5],
        ['powder', 'Powder', 6],
        ['sinkers', 'Sinkers', 7],
        ['toppings', 'Toppings', 8],
        ['packaging', 'Packaging', 9],
        ['cashier_area', 'Cashier Area', 10],
    ];
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO inventory_category_types (slug, name, display_order, is_active)
         VALUES (:slug, :name, :ord, 1)'
    );
    foreach ($seeds as $row) {
        $ins->execute([
            ':slug' => $row[0],
            ':name' => $row[1],
            ':ord' => $row[2],
        ]);
    }
}

function slugify_inventory_category_type(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[\s-]+/', '_', $slug) ?? '';
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '') {
        return '';
    }
    return substr($slug, 0, 40);
}

function fetch_active_inventory_category_types(PDO $pdo): array
{
    ensure_inventory_category_types_schema($pdo);
    $rows = $pdo->query(
        'SELECT id, slug, name, display_order
           FROM inventory_category_types
          WHERE is_active = 1
          ORDER BY display_order, name'
    )->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'value' => (string)$row['slug'],
            'slug' => (string)$row['slug'],
            'label' => (string)$row['name'],
            'name' => (string)$row['name'],
            'display_order' => (int)$row['display_order'],
        ];
    }
    return $out;
}

function inventory_category_types(): array
{
    try {
        $rows = fetch_active_inventory_category_types(db());
        $slugs = array_values(array_filter(array_map(static function ($row) {
            return (string)($row['slug'] ?? '');
        }, $rows)));
        if ($slugs) {
            return $slugs;
        }
    } catch (Throwable $e) {
        // Fall through to defaults if DB is unavailable during bootstrap.
    }
    return ['main', 'ice', 'sauce', 'chocolate', 'syrup', 'powder', 'sinkers', 'toppings', 'packaging', 'cashier_area'];
}

function normalize_inventory_category_type($type): string
{
    $t = strtolower(trim((string)$type));
    $t = str_replace(['-', ' '], '_', $t);
    $t = preg_replace('/[^a-z0-9_]/', '', $t) ?? '';
    $t = trim($t, '_');
    if ($t === '') {
        return 'main';
    }
    $allowed = inventory_category_types();
    if (in_array($t, $allowed, true)) {
        return $t;
    }
    // Keep unknown legacy slugs readable instead of forcing everything to main.
    if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $t)) {
        return $t;
    }
    return 'main';
}

function is_non_consumable_inventory_row(array $row): bool
{
    return normalize_inventory_stock_type($row['stock_type'] ?? 'consumable') === 'non_consumable';
}

function is_kitchen_inventory_category($category): bool
{
    return strtolower(trim((string)$category)) === 'kitchen';
}

/** Kitchen items and explicit non-consumables share batch/reserve stock logic. */
function inventory_uses_batch_logic(array $row): bool
{
    if (is_non_consumable_inventory_row($row)) {
        return true;
    }

    return is_kitchen_inventory_category($row['category_name'] ?? '');
}

function batch_size_for_inventory_row(array $row): int
{
    if (is_kitchen_inventory_category($row['category_name'] ?? '')) {
        $ordersPerBox = (int)($row['orders_per_box'] ?? 0);
        if ($ordersPerBox > 0) {
            return $ordersPerBox;
        }
    }

    return max(1, (int)round((float)($row['per_stock_amount'] ?? 1)));
}

/**
 * Pull the first active batch from sealed reserve into units_in_use.
 */
function open_initial_batch_from_stock(array $row, int $batchSize): array
{
    $stockUnits = max(0, (int)($row['stock_units'] ?? 0));
    $batchRemaining = max(0, (int)round((float)($row['units_in_use'] ?? 0)));
    $batchSize = max(1, $batchSize);

    if ($batchRemaining > 0) {
        return [
            'stock_units' => $stockUnits,
            'units_in_use' => min($batchRemaining, $batchSize),
            'open_items_count' => 1,
        ];
    }

    if ($stockUnits <= 0) {
        return [
            'stock_units' => 0,
            'units_in_use' => 0,
            'open_items_count' => 0,
        ];
    }

    $opened = min($batchSize, $stockUnits);

    return [
        'stock_units' => $stockUnits - $opened,
        'units_in_use' => $opened,
        'open_items_count' => 1,
    ];
}

/**
 * Auto-open one batch for Kitchen / non-consumable rows when reserve exists.
 */
function ensure_batch_open_when_stock_available(PDO $pdo, ?int $invId = null): void
{
    $sql = 'SELECT id, stock_units, units_in_use, open_items_count, orders_per_box, per_stock_amount, stock_type, category_name
            FROM inventory_items
            WHERE is_active = 1
              AND menu_item_id IS NULL
              AND stock_units > 0
              AND COALESCE(units_in_use, 0) <= 0
              AND COALESCE(open_items_count, 0) = 0';
    if ($invId !== null && $invId > 0) {
        $sql .= ' AND id = :id';
    }

    $stmt = $pdo->prepare($sql);
    $params = [];
    if ($invId !== null && $invId > 0) {
        $params[':id'] = $invId;
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE inventory_items
         SET stock_units = :stock_units,
             units_in_use = :units_in_use,
             open_items_count = :open_items_count
         WHERE id = :id'
    );

    foreach ($rows as $row) {
        if (!inventory_uses_batch_logic($row)) {
            continue;
        }
        if (is_kitchen_inventory_category($row['category_name'] ?? '')) {
            if ((int)($row['orders_per_box'] ?? 0) <= 0) {
                continue;
            }
        } elseif (!is_non_consumable_inventory_row($row)) {
            continue;
        }

        $batchSize = batch_size_for_inventory_row($row);
        if ($batchSize <= 0) {
            continue;
        }

        $opened = open_initial_batch_from_stock($row, $batchSize);
        if ($opened['units_in_use'] <= 0) {
            continue;
        }

        $updateStmt->execute([
            ':stock_units' => $opened['stock_units'],
            ':units_in_use' => $opened['units_in_use'],
            ':open_items_count' => $opened['open_items_count'],
            ':id' => (int)$row['id'],
        ]);
    }
}

/**
 * Apply sealed stock + optional batch opens from a receipt line (non-consumable / Kitchen).
 */
function apply_batch_receipt_stock_counts(
    int $incomingSealed,
    int $receiptOpenBatches,
    int $existingSealed,
    float $existingBatchRemaining,
    int $batchSize
): array {
    $stockUnits = $existingSealed + max(0, $incomingSealed);
    $batchRemaining = max(0, (int)round($existingBatchRemaining));
    $batchSize = max(1, $batchSize);
    $toOpen = max(0, $receiptOpenBatches);

    while ($toOpen > 0 && $stockUnits > 0) {
        if ($batchRemaining > 0) {
            break;
        }

        $opened = min($batchSize, $stockUnits);
        $stockUnits -= $opened;
        $batchRemaining = $opened;
        $toOpen--;
    }

    return [
        'stock_units' => max(0, $stockUnits),
        'units_in_use' => max(0, $batchRemaining),
        'open_items_count' => $batchRemaining > 0 ? 1 : 0,
    ];
}

function inventory_max_open_items(): int
{
    // No practical maximum — open items are only limited by available sealed stock.
    return PHP_INT_MAX;
}

function open_items_count_for_row(array $row): int
{
    $count = (int)($row['open_items_count'] ?? 0);
    if ($count > 0) {
        return max(0, min(inventory_max_open_items(), $count));
    }

    $ordersLeft = (float)($row['units_in_use'] ?? 0);
    if ($ordersLeft > 0.0001) {
        return 1;
    }

    return 0;
}

/**
 * Keep sealed stock (stock_units) separate from open items (open_items_count).
 * Opening an item always pulls from sealed stock first.
 */
function reconcile_inventory_counts(
    int $sealedStock,
    float $ordersLeft,
    int $openCount,
    int $requestedOpen,
    ?float $requestedOrdersLeft,
    int $capacity
): array {
    $maxOpen = inventory_max_open_items();
    $sealedStock = max(0, $sealedStock);
    $openCount = max(0, min($maxOpen, $openCount));
    $requestedOpen = max(0, min($maxOpen, $requestedOpen));
    $ordersLeft = max(0, $ordersLeft);

    if ($requestedOpen > $openCount) {
        $need = $requestedOpen - $openCount;
        if ($sealedStock < $need) {
            throw new InvalidArgumentException(
                'Not enough sealed stock to open ' . $need . ' item(s). Only ' . $sealedStock . ' sealed left.'
            );
        }
        $sealedStock -= $need;
        $openCount = $requestedOpen;
    } elseif ($requestedOpen < $openCount) {
        $sealedStock += ($openCount - $requestedOpen);
        $openCount = $requestedOpen;
    } else {
        $openCount = $requestedOpen;
    }

    if ($requestedOrdersLeft !== null) {
        $ordersLeft = max(0, $requestedOrdersLeft);
    }

    if ($openCount <= 0) {
        $ordersLeft = 0;
    } elseif ($ordersLeft <= 0 && $capacity > 0) {
        $ordersLeft = (float)$capacity;
    }

    if ($capacity > 0 && $openCount > 0) {
        $ordersLeft = min($ordersLeft, (float)$capacity);
    }

    return [
        'stock_units' => max(0, $sealedStock),
        'units_in_use' => round(max(0, $ordersLeft), 2),
        'open_items_count' => max(0, min($maxOpen, $openCount)),
    ];
}

/**
 * Run all DDL needed for inventory deduction BEFORE beginTransaction().
 * MySQL implicitly commits on CREATE/ALTER, which breaks an open transaction.
 */
function ensure_order_inventory_deduction_schema(PDO $pdo): void {
    ensure_inventory_items_base_schema($pdo);
    ensure_recipe_schema_shared($pdo);
    ensure_order_inventory_deducted_schema($pdo);
    // Addon inventory links are deducted from order notes — migrate schema outside txn.
    if (!function_exists('ensure_addons_schema')) {
        require_once __DIR__ . '/addons_helpers.php';
    }
    ensure_addons_schema($pdo);
}

function ensure_order_inventory_deducted_schema(PDO $pdo): void {
    $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'inventory_deducted'");
    if ($chk && $chk->fetch()) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE orders ADD COLUMN inventory_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    } catch (Throwable $e) {
        // Ignore migration issues on environments with restricted ALTER privileges.
    }
}

function resolve_inventory_item_id(PDO $pdo, ?int $inventoryItemId, string $ingredientName): ?int {
    if ($inventoryItemId && $inventoryItemId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM inventory_items
             WHERE id = :id
               AND is_active = 1
               AND menu_item_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([':id' => $inventoryItemId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    $name = trim($ingredientName);
    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM inventory_items
         WHERE is_active = 1
           AND menu_item_id IS NULL
           AND LOWER(TRIM(item_name)) = LOWER(TRIM(:name))
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function orders_per_box_for_inventory_row(array $row): int
{
    $ordersPerBox = (int)($row['orders_per_box'] ?? 0);
    if ($ordersPerBox > 0) {
        return $ordersPerBox;
    }

    return max(1, (int)round((float)($row['per_stock_amount'] ?? 1)));
}

/**
 * Item Capacity as configured by staff. 0 = not set yet (do not invent a fallback of 1).
 */
function configured_item_capacity(array $row): int
{
    return max(0, (int)($row['orders_per_box'] ?? 0));
}

/**
 * When sealed stock exists but no box is open yet, auto-open one box for use.
 * Requires item capacity (orders_per_box) to be configured.
 */
function ensure_open_box_when_stock_available(PDO $pdo, ?int $invId = null): void
{
    $sql = 'SELECT id, stock_units, units_in_use, open_items_count, orders_per_box, stock_type, category_name
            FROM inventory_items
            WHERE is_active = 1
              AND menu_item_id IS NULL
              AND stock_units > 0
              AND COALESCE(open_items_count, 0) = 0
              AND units_in_use <= 0
              AND orders_per_box > 0
              AND COALESCE(stock_type, \'consumable\') = \'consumable\'
              AND LOWER(TRIM(COALESCE(category_name, \'\'))) <> \'kitchen\'';
    if ($invId !== null && $invId > 0) {
        $sql .= ' AND id = :id';
    }

    $stmt = $pdo->prepare($sql);
    $params = [];
    if ($invId !== null && $invId > 0) {
        $params[':id'] = $invId;
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE inventory_items
         SET stock_units = :stock_units,
             units_in_use = :units_in_use,
             open_items_count = 1
         WHERE id = :id'
    );

    foreach ($rows as $row) {
        $stockUnits = (int)$row['stock_units'];
        $capacity = (int)$row['orders_per_box'];
        if ($stockUnits <= 0 || $capacity <= 0) {
            continue;
        }
        $updateStmt->execute([
            ':stock_units' => $stockUnits - 1,
            ':units_in_use' => (float)$capacity,
            ':id' => (int)$row['id'],
        ]);
    }
}

/**
 * Total order slots still available (open box remainder + sealed boxes × capacity).
 */
function total_available_orders_for_inventory_row(array $row): float
{
    $stockUnits = max(0, (int)($row['stock_units'] ?? 0));
    $ordersLeft = max(0, (float)($row['units_in_use'] ?? 0));
    $ordersPerBox = (int)($row['orders_per_box'] ?? 0);
    $openCount = open_items_count_for_row($row);

    // Batch logic (non-consumable / Kitchen): reserve plus active batch remaining.
    if (inventory_uses_batch_logic($row)) {
        return (float)($stockUnits + max(0, $ordersLeft));
    }

    if ($ordersPerBox <= 0) {
        return (float)($stockUnits + max(0, $ordersLeft));
    }

    $queuedOrders = max(0, $openCount - 1) * $ordersPerBox;

    return $ordersLeft + $queuedOrders + ($stockUnits * $ordersPerBox);
}

/**
 * Full capacity for the item's current stock structure (all sealed + open units at max fill).
 */
function max_capacity_for_inventory_row(array $row): float
{
    $stockUnits = max(0, (int)($row['stock_units'] ?? 0));
    $ordersLeft = max(0, (float)($row['units_in_use'] ?? 0));
    $openCount = open_items_count_for_row($row);

    if (inventory_uses_batch_logic($row)) {
        $batchSize = max(1, batch_size_for_inventory_row($row));
        if ($ordersLeft > 0 || $openCount > 0) {
            return (float)($stockUnits + $batchSize);
        }
        return (float)max(1, $stockUnits);
    }

    $ordersPerBox = (int)($row['orders_per_box'] ?? 0);

    if ($ordersPerBox <= 0) {
        if ($ordersLeft > 0 || $openCount > 0) {
            return (float)max(1, $stockUnits + max($ordersLeft, 1));
        }
        return (float)max(1, $stockUnits);
    }

    return (float)(($stockUnits + $openCount) * $ordersPerBox);
}

function inventory_stock_ratio(array $row): float
{
    $max = max_capacity_for_inventory_row($row);
    if ($max <= 0) {
        return 0.0;
    }
    return total_available_orders_for_inventory_row($row) / $max;
}

function is_low_stock_for_inventory_row(array $row, int $alertPercent): bool
{
    $alertPercent = max(1, min(100, $alertPercent));
    $total = total_available_orders_for_inventory_row($row);
    if ($total <= 0) {
        return false;
    }
    $max = max_capacity_for_inventory_row($row);
    if ($max <= 0) {
        return false;
    }
    return ($total / $max) <= ($alertPercent / 100.0);
}

/**
 * Deduct inventory for a sale.
 * - Consumable: FIFO against orders left on open items (item capacity).
 * - Non-consumable (cups, lids, etc.): deduct physical pieces from open items, then sealed.
 */
function deduct_units_in_use_with_refill(PDO $pdo, int $invId, float $amount): void {
    if ($amount <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT stock_units, units_in_use, open_items_count, orders_per_box, per_stock_amount, stock_type, category_name, entry_mode
         FROM inventory_items
         WHERE id = :id
           AND menu_item_id IS NULL
           AND is_active = 1
         FOR UPDATE'
    );
    $stmt->execute([':id' => $invId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    // Manual checklist items are not qty-tracked — staff marks good/low/critical instead.
    if (inventory_is_manual_entry($row)) {
        return;
    }

    $stockUnits = (int)$row['stock_units'];
    $ordersLeft = (float)$row['units_in_use'];
    $openCount = open_items_count_for_row($row);

    if (inventory_uses_batch_logic($row)) {
        $need = (int)max(0, (int)round($amount));
        if ($need <= 0) {
            return;
        }

        $batchSize = batch_size_for_inventory_row($row);
        $batchRemaining = max(0, (int)round($ordersLeft));

        while ($need > 0 && ($batchRemaining > 0 || $stockUnits > 0)) {
            if ($batchRemaining <= 0) {
                if ($stockUnits <= 0) {
                    break;
                }
                $openedBatch = min($batchSize, $stockUnits);
                $stockUnits -= $openedBatch;
                $batchRemaining = $openedBatch;
            }

            $take = min($need, $batchRemaining);
            if ($take <= 0) {
                break;
            }

            $batchRemaining -= $take;
            $need -= $take;
        }

        $openCount = $batchRemaining > 0 ? 1 : 0;

        $upd = $pdo->prepare(
            'UPDATE inventory_items
             SET stock_units = :stock_units,
                 units_in_use = :units_in_use,
                 open_items_count = :open_items_count
             WHERE id = :id'
        );
        $upd->execute([
            ':stock_units' => max(0, $stockUnits),
            ':units_in_use' => max(0, $batchRemaining),
            ':open_items_count' => max(0, $openCount),
            ':id' => $invId,
        ]);
        return;
    }

    $ordersPerBox = orders_per_box_for_inventory_row($row);
    $remaining = round($amount, 2);
    $maxOpen = inventory_max_open_items();
    $guard = 0;

    while ($remaining > 0.0001 && $guard < 500) {
        $guard++;

        if ($ordersLeft <= 0.0001) {
            if ($openCount > 1) {
                $openCount -= 1;
                $ordersLeft = (float)$ordersPerBox;
                continue;
            }
            if ($stockUnits > 0) {
                $stockUnits -= 1;
                $openCount = max(1, $openCount);
                $ordersLeft = (float)$ordersPerBox;
                continue;
            }
            break;
        }

        if ($ordersLeft >= $remaining) {
            $ordersLeft -= $remaining;
            $remaining = 0;
            break;
        }

        $remaining -= $ordersLeft;
        $ordersLeft = 0;
    }

    if ($ordersLeft <= 0.0001) {
        if ($openCount > 1) {
            // Move to the next already-open item.
            $openCount -= 1;
            $ordersLeft = (float)$ordersPerBox;
        } else {
            // Exhausted the last open item exactly at the boundary.
            // Pre-open 1 sealed item (if available) so the UI never stays at "0 out of 0"
            // while stock_units still exists.
            if ($stockUnits > 0 && $ordersPerBox > 0) {
                $stockUnits -= 1;
                $openCount = 1;
                $ordersLeft = (float)$ordersPerBox;
            } else {
                $openCount = 0;
            }
        }
    }

    $openCount = max(0, min($maxOpen, $openCount));
    if ($openCount <= 0) {
        $ordersLeft = 0;
    }

    $upd = $pdo->prepare(
        'UPDATE inventory_items
         SET stock_units = :stock_units,
             units_in_use = :units_in_use,
             open_items_count = :open_items_count
         WHERE id = :id'
    );
    $upd->execute([
        ':stock_units' => max(0, $stockUnits),
        ':units_in_use' => round(max(0, $ordersLeft), 2),
        ':open_items_count' => $openCount,
        ':id' => $invId,
    ]);
}

function normalize_notes_for_inventory_match(string $notes): string {
    $s = strtolower(trim($notes));
    $s = str_replace(['(', ')'], '', $s);
    $s = preg_replace('/(\d+(?:\.\d+)?)\s*(oz|ml)\b/iu', '$1$2', $s);
    $s = preg_replace('/[·|]+/u', ' ', $s);
    $s = preg_replace('/\s*,\s*/u', ' ', $s);
    $s = preg_replace('/\bp\d+(?:\.\d+)?\b/u', '', $s);

    return trim(preg_replace('/\s+/u', ' ', $s));
}

function variant_signature_matches_notes(string $notes, string $variantSignature): bool {
    $vs = trim($variantSignature);
    if ($vs === '') {
        return false;
    }
    if (stripos($notes, $vs) !== false) {
        return true;
    }

    return strpos(normalize_notes_for_inventory_match($notes), normalize_notes_for_inventory_match($vs)) !== false;
}

/**
 * Resolve the saved recipe row for an order line (variant-aware).
 *
 * @return array{id:int,unit_cost:float}|null
 */
function lookup_recipe_for_order_line(PDO $pdo, int $menuId, ?string $notes): ?array
{
    if ($menuId <= 0) {
        return null;
    }

    $menuDescStmt = $pdo->prepare('SELECT description FROM menu_items WHERE id = :id LIMIT 1');
    $menuDescStmt->execute([':id' => $menuId]);
    $menuRow = $menuDescStmt->fetch(PDO::FETCH_ASSOC);
    $description = (string)($menuRow['description'] ?? '');
    $variantSignature = resolve_variant_signature_from_notes($description, (string)($notes ?? ''));

    $recipeStmt = $pdo->prepare(
        'SELECT id, unit_cost
         FROM recipes
         WHERE menu_item_id = :menu_id
           AND variant_signature = :variant_signature
         LIMIT 1'
    );
    $recipeStmt->execute([
        ':menu_id' => $menuId,
        ':variant_signature' => $variantSignature,
    ]);
    $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipe && $variantSignature !== '') {
        $recipeStmt->execute([
            ':menu_id' => $menuId,
            ':variant_signature' => '',
        ]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$recipe) {
        return null;
    }

    return [
        'id' => (int)$recipe['id'],
        'unit_cost' => round(max(0, (float)($recipe['unit_cost'] ?? 0)), 2),
    ];
}

function resolve_recipe_unit_cost_for_order_line(PDO $pdo, int $menuId, ?string $notes): float
{
    $recipe = lookup_recipe_for_order_line($pdo, $menuId, $notes);
    return $recipe ? $recipe['unit_cost'] : 0.0;
}

function snapshot_order_line_cost(PDO $pdo, int $menuId, int $qty, ?string $notes): array
{
    $quantity = max(1, $qty);
    $unitCost = resolve_recipe_unit_cost_for_order_line($pdo, $menuId, $notes);

    return [
        'unit_cost' => $unitCost,
        'line_cost' => round($unitCost * $quantity, 2),
    ];
}

/**
 * @return int[] menu_item_ids that were handled via recipe deduction
 */
function deduct_recipe_ingredients_for_order(PDO $pdo, int $orderId): array {
    $rows = $pdo->prepare(
        'SELECT oi.menu_item_id, oi.quantity, oi.notes
         FROM order_items oi
         WHERE oi.order_id = :oid'
    );
    $rows->execute([':oid' => $orderId]);

    $menuDescStmt = $pdo->prepare('SELECT description FROM menu_items WHERE id = :id LIMIT 1');
    $recipeStmt = $pdo->prepare(
        'SELECT id
         FROM recipes
         WHERE menu_item_id = :menu_id
           AND variant_signature = :variant_signature
         LIMIT 1'
    );
    $recipeFallbackStmt = $pdo->prepare(
        'SELECT id
         FROM recipes
         WHERE menu_item_id = :menu_id
           AND variant_signature = ""
         LIMIT 1'
    );
    $ingredientStmt = $pdo->prepare(
        'SELECT inventory_item_id, ingredient_name, quantity
         FROM recipe_ingredients
         WHERE recipe_id = :recipe_id
         ORDER BY sort_order ASC, id ASC'
    );

    $handledMenuIds = [];

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $menuId = (int)($row['menu_item_id'] ?? 0);
        if ($menuId <= 0) {
            continue;
        }
        $orderQty = max(1, (int)($row['quantity'] ?? 1));
        $notes = (string)($row['notes'] ?? '');

        $menuDescStmt->execute([':id' => $menuId]);
        $menuRow = $menuDescStmt->fetch(PDO::FETCH_ASSOC);
        $description = (string)($menuRow['description'] ?? '');
        $variantSignature = resolve_variant_signature_from_notes($description, $notes);

        $recipeStmt->execute([
            ':menu_id' => $menuId,
            ':variant_signature' => $variantSignature,
        ]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$recipe && $variantSignature !== '') {
            $recipeFallbackStmt->execute([':menu_id' => $menuId]);
            $recipe = $recipeFallbackStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$recipe) {
            continue;
        }

        $ingredientStmt->execute([':recipe_id' => (int)$recipe['id']]);
        $ingredients = $ingredientStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$ingredients) {
            continue;
        }

        $handledMenuIds[] = $menuId;

        foreach ($ingredients as $ing) {
            $invId = resolve_inventory_item_id(
                $pdo,
                isset($ing['inventory_item_id']) ? (int)$ing['inventory_item_id'] : null,
                (string)($ing['ingredient_name'] ?? '')
            );
            if (!$invId) {
                continue;
            }

            // Recipe line qty × order line qty (e.g. 1 cup per drink × 20 drinks = 20).
            $recipeQty = max(0, (float)($ing['quantity'] ?? 1));
            $deductQty = (float)$orderQty * $recipeQty;
            if ($deductQty <= 0) {
                continue;
            }

            deduct_units_in_use_with_refill($pdo, $invId, $deductQty);
        }
    }

    return array_values(array_unique($handledMenuIds));
}

/**
 * Recipe ingredient lines for waste logging (variant-aware).
 *
 * @return list<array{inventory_item_id:?int,ingredient_name:string,quantity:float,unit:string,unit_cost:float}>
 */
function fetch_recipe_lines_for_menu_waste(PDO $pdo, int $menuId, ?string $description, ?string $notes): array
{
    $variantSignature = resolve_variant_signature_from_notes($description, $notes);

    $recipeStmt = $pdo->prepare(
        'SELECT id
           FROM recipes
          WHERE menu_item_id = :menu_id
            AND variant_signature = :variant_signature
          LIMIT 1'
    );
    $recipeStmt->execute([
        ':menu_id' => $menuId,
        ':variant_signature' => $variantSignature,
    ]);
    $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipe && $variantSignature !== '') {
        $recipeStmt->execute([
            ':menu_id' => $menuId,
            ':variant_signature' => '',
        ]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$recipe) {
        return [];
    }

    $ingredientStmt = $pdo->prepare(
        'SELECT inventory_item_id, ingredient_name, quantity, unit, unit_cost
           FROM recipe_ingredients
          WHERE recipe_id = :recipe_id
          ORDER BY sort_order ASC, id ASC'
    );
    $ingredientStmt->execute([':recipe_id' => (int)$recipe['id']]);
    $rows = $ingredientStmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'inventory_item_id' => $row['inventory_item_id'] !== null ? (int)$row['inventory_item_id'] : null,
            'ingredient_name' => (string)$row['ingredient_name'],
            'quantity' => (float)$row['quantity'],
            'unit' => (string)($row['unit'] ?? 'unit'),
            'unit_cost' => (float)($row['unit_cost'] ?? 0),
        ];
    }
    return $out;
}

function deduct_linked_materials_for_order(PDO $pdo, int $orderId, array $skipMenuIds = []): void {
    $skipMenuIds = array_values(array_unique(array_map('intval', $skipMenuIds)));

    $rows = $pdo->prepare(
        'SELECT oi.menu_item_id, oi.quantity, oi.notes
         FROM order_items oi
         WHERE oi.order_id = :oid'
    );
    $rows->execute([':oid' => $orderId]);

    $linkStmt = $pdo->prepare(
        'SELECT inv.id AS inv_id, am.variant_signature
         FROM inventory_applicable_menu am
         INNER JOIN inventory_items inv
            ON inv.id = am.inventory_item_id
           AND inv.is_active = 1
           AND inv.menu_item_id IS NULL
         WHERE am.menu_item_id = :mid'
    );

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mid = (int)($r['menu_item_id'] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        if ($skipMenuIds && in_array($mid, $skipMenuIds, true)) {
            continue;
        }
        $qty = max(1, (int)($r['quantity'] ?? 1));
        $notes = (string)($r['notes'] ?? '');

        $linkStmt->execute([':mid' => $mid]);
        $candidates = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$candidates) {
            continue;
        }

        $specificIds = [];
        $genericIds = [];
        foreach ($candidates as $c) {
            $invId = (int)($c['inv_id'] ?? 0);
            if ($invId <= 0) {
                continue;
            }
            $vs = trim((string)($c['variant_signature'] ?? ''));
            if ($vs === '') {
                $genericIds[] = $invId;
                continue;
            }
            if (variant_signature_matches_notes($notes, $vs)) {
                $specificIds[] = $invId;
            }
        }

        $toDeduct = !empty($specificIds)
            ? array_values(array_unique($specificIds))
            : array_values(array_unique($genericIds));

        foreach ($toDeduct as $invId) {
            deduct_units_in_use_with_refill($pdo, $invId, (float)$qty);
        }
    }
}

function deduct_menu_sku_stock_for_order(PDO $pdo, int $orderId): void {
    $missingStmt = $pdo->prepare(
        'INSERT INTO inventory_items (menu_item_id, item_name, category_name, supplier, stock_units, reorder_level, unit_cost, is_active)
         SELECT m.id, m.name, c.name, "Unassigned", 0, 10, m.price, 1
         FROM order_items oi
         JOIN menu_items m ON m.id = oi.menu_item_id
         JOIN categories c ON c.id = m.category_id
         LEFT JOIN inventory_items i ON i.menu_item_id = m.id AND i.is_active = 1
         WHERE oi.order_id = :oid
           AND i.id IS NULL
         GROUP BY m.id'
    );
    $missingStmt->execute([':oid' => $orderId]);

    $deductStmt = $pdo->prepare(
        'UPDATE inventory_items i
         JOIN (
            SELECT menu_item_id, SUM(quantity) AS qty
            FROM order_items
            WHERE order_id = :oid
            GROUP BY menu_item_id
         ) x ON x.menu_item_id = i.menu_item_id
         SET i.stock_units = GREATEST(0, i.stock_units - x.qty)
         WHERE i.is_active = 1'
    );
    $deductStmt->execute([':oid' => $orderId]);
}

/**
 * How much of an inventory item is available to sell against.
 */
function inventory_sellable_units_for_row(array $row): float
{
    if (inventory_is_manual_entry($row)) {
        $manual = normalize_inventory_stock_status($row['stock_status'] ?? 'good');
        // critical = treat as empty; good/low = sellable (qty not tracked).
        return $manual === 'critical' ? 0.0 : 999999.0;
    }

    if (inventory_uses_batch_logic($row)) {
        return (float)(max(0, (int)($row['stock_units'] ?? 0)) + max(0, (float)($row['units_in_use'] ?? 0)));
    }

    return total_available_orders_for_inventory_row($row);
}

/**
 * Aggregate inventory needed for a cart (mirrors deduction: recipes first, then linked materials).
 *
 * @param list<array{menu_item_id:int|string,quantity?:int|string,notes?:string}> $items
 * @return array<int, array{inventory_item_id:int,item_name:string,required:float}>
 */
function collect_inventory_requirements_for_cart(PDO $pdo, array $items): array
{
    ensure_recipe_schema_shared($pdo);
    ensure_inventory_items_base_schema($pdo);
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_applicable_menu (
                inventory_item_id INT NOT NULL,
                menu_item_id INT NOT NULL,
                variant_signature VARCHAR(160) NOT NULL DEFAULT \'\',
                PRIMARY KEY (inventory_item_id, menu_item_id, variant_signature),
                INDEX idx_inventory_applicable_menu_item (menu_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        // Table may already exist with FKs from inventory schema.
    }

    $needed = []; // inv_id => ['inventory_item_id'=>, 'item_name'=>, 'required'=>]

    $addNeed = function (int $invId, float $qty, string $fallbackName) use ($pdo, &$needed): void {
        if ($invId <= 0 || $qty <= 0) {
            return;
        }
        if (!isset($needed[$invId])) {
            $nameStmt = $pdo->prepare(
                'SELECT item_name FROM inventory_items WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $nameStmt->execute([':id' => $invId]);
            $nameRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
            $needed[$invId] = [
                'inventory_item_id' => $invId,
                'item_name' => (string)($nameRow['item_name'] ?? $fallbackName),
                'required' => 0.0,
            ];
        }
        $needed[$invId]['required'] += $qty;
    };

    $menuDescStmt = $pdo->prepare('SELECT description FROM menu_items WHERE id = :id LIMIT 1');
    $recipeStmt = $pdo->prepare(
        'SELECT id FROM recipes
         WHERE menu_item_id = :menu_id AND variant_signature = :variant_signature
         LIMIT 1'
    );
    $recipeFallbackStmt = $pdo->prepare(
        'SELECT id FROM recipes
         WHERE menu_item_id = :menu_id AND variant_signature = ""
         LIMIT 1'
    );
    $ingredientStmt = $pdo->prepare(
        'SELECT inventory_item_id, ingredient_name, quantity
         FROM recipe_ingredients
         WHERE recipe_id = :recipe_id
         ORDER BY sort_order ASC, id ASC'
    );
    $linkStmt = $pdo->prepare(
        'SELECT inv.id AS inv_id, inv.item_name, am.variant_signature
         FROM inventory_applicable_menu am
         INNER JOIN inventory_items inv
            ON inv.id = am.inventory_item_id
           AND inv.is_active = 1
           AND inv.menu_item_id IS NULL
         WHERE am.menu_item_id = :mid'
    );

    $handledMenuIds = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $menuId = (int)($item['menu_item_id'] ?? 0);
        if ($menuId <= 0) {
            continue;
        }
        $orderQty = max(1, (int)($item['quantity'] ?? 1));
        $notes = (string)($item['notes'] ?? '');

        $menuDescStmt->execute([':id' => $menuId]);
        $menuRow = $menuDescStmt->fetch(PDO::FETCH_ASSOC);
        $description = (string)($menuRow['description'] ?? '');
        $variantSignature = resolve_variant_signature_from_notes($description, $notes);

        $recipeStmt->execute([
            ':menu_id' => $menuId,
            ':variant_signature' => $variantSignature,
        ]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$recipe && $variantSignature !== '') {
            $recipeFallbackStmt->execute([':menu_id' => $menuId]);
            $recipe = $recipeFallbackStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($recipe) {
            $ingredientStmt->execute([':recipe_id' => (int)$recipe['id']]);
            $ingredients = $ingredientStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($ingredients) {
                $handledMenuIds[$menuId] = true;
                foreach ($ingredients as $ing) {
                    $invId = resolve_inventory_item_id(
                        $pdo,
                        isset($ing['inventory_item_id']) ? (int)$ing['inventory_item_id'] : null,
                        (string)($ing['ingredient_name'] ?? '')
                    );
                    if (!$invId) {
                        continue;
                    }
                    $recipeQty = max(0, (float)($ing['quantity'] ?? 1));
                    $addNeed($invId, $orderQty * $recipeQty, (string)($ing['ingredient_name'] ?? 'Item'));
                }
            }
        }
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $menuId = (int)($item['menu_item_id'] ?? 0);
        if ($menuId <= 0 || isset($handledMenuIds[$menuId])) {
            continue;
        }
        $orderQty = max(1, (int)($item['quantity'] ?? 1));
        $notes = (string)($item['notes'] ?? '');

        $linkStmt->execute([':mid' => $menuId]);
        $candidates = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$candidates) {
            continue;
        }

        $specific = [];
        $generic = [];
        foreach ($candidates as $c) {
            $invId = (int)($c['inv_id'] ?? 0);
            if ($invId <= 0) {
                continue;
            }
            $vs = trim((string)($c['variant_signature'] ?? ''));
            if ($vs === '') {
                $generic[$invId] = (string)($c['item_name'] ?? 'Item');
                continue;
            }
            if (variant_signature_matches_notes($notes, $vs)) {
                $specific[$invId] = (string)($c['item_name'] ?? 'Item');
            }
        }

        $toUse = !empty($specific) ? $specific : $generic;
        foreach ($toUse as $invId => $name) {
            $addNeed((int)$invId, (float)$orderQty, $name);
        }
    }

    return $needed;
}

/**
 * @param list<array{menu_item_id:int|string,quantity?:int|string,notes?:string}> $items
 * @return list<array{inventory_item_id:int,item_name:string,required:float,available:float,shortage:float,category_name:string,per_stock_unit:string}>
 */
function check_inventory_shortages_for_cart(PDO $pdo, array $items): array
{
    $needed = collect_inventory_requirements_for_cart($pdo, $items);
    if (!$needed) {
        return [];
    }

    $shortages = [];
    $stockStmt = $pdo->prepare(
        'SELECT id, item_name, category_name, per_stock_unit, stock_units, units_in_use, open_items_count, orders_per_box, stock_type, entry_mode, stock_status
         FROM inventory_items
         WHERE id = :id
           AND is_active = 1
           AND menu_item_id IS NULL
         LIMIT 1'
    );

    foreach ($needed as $invId => $req) {
        $stockStmt->execute([':id' => $invId]);
        $row = $stockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $shortages[] = [
                'inventory_item_id' => (int)$invId,
                'item_name' => (string)$req['item_name'],
                'required' => round((float)$req['required'], 2),
                'available' => 0.0,
                'shortage' => round((float)$req['required'], 2),
                'category_name' => 'Bar',
                'per_stock_unit' => 'pcs',
            ];
            continue;
        }

        $available = inventory_sellable_units_for_row($row);
        $required = (float)$req['required'];
        if ($available + 0.0001 >= $required) {
            continue;
        }

        $shortages[] = [
            'inventory_item_id' => (int)$invId,
            'item_name' => (string)($row['item_name'] ?? $req['item_name']),
            'required' => round($required, 2),
            'available' => round(max(0, $available), 2),
            'shortage' => round(max(0, $required - $available), 2),
            'category_name' => (string)($row['category_name'] ?? 'Bar'),
            'per_stock_unit' => (string)($row['per_stock_unit'] ?? 'pcs'),
        ];
    }

    return $shortages;
}

function respond_inventory_shortage(array $shortages, bool $canOverride = true): void
{
    $lines = [];
    foreach ($shortages as $s) {
        $lines[] = sprintf(
            '%s: need %s, only %s available (short %s)',
            (string)($s['item_name'] ?? 'Item'),
            rtrim(rtrim(number_format((float)($s['required'] ?? 0), 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format((float)($s['available'] ?? 0), 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format((float)($s['shortage'] ?? 0), 2, '.', ''), '0'), '.')
        );
    }
    $message = 'Insufficient inventory for this order.'
        . ($lines ? "\n" . implode("\n", $lines) : '');

    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => 'inventory_shortage',
        'can_override' => $canOverride,
        'shortages' => array_values($shortages),
    ]);
    exit;
}

/**
 * Add enough stock to cover shortage amounts for specific inventory items only.
 *
 * @param list<array{inventory_item_id?:int,shortage?:float,item_name?:string}> $shortages
 * @return list<array{inventory_item_id:int,item_name:string,shortage:float}>
 */
function compute_restock_counts_for_shortage(array $row, float $shortage): array
{
    $stockUnits = max(0, (int)($row['stock_units'] ?? 0));
    $ordersLeft = max(0, (float)($row['units_in_use'] ?? 0));
    $openCount = open_items_count_for_row($row);

    if ($openCount > 0 || $ordersLeft > 0) {
        return [
            'stock_units' => $stockUnits,
            'units_in_use' => $ordersLeft + $shortage,
            'open_items_count' => max(1, $openCount),
        ];
    }

    if (inventory_uses_batch_logic($row)) {
        $capacity = max(1, batch_size_for_inventory_row($row));
        $sealedToAdd = max(1, (int)ceil($shortage / $capacity));

        return [
            'stock_units' => $stockUnits + max(0, $sealedToAdd - 1),
            'units_in_use' => min($shortage, (float)$capacity),
            'open_items_count' => 1,
        ];
    }

    $ordersPerBox = max(0, (int)($row['orders_per_box'] ?? 0));
    if ($ordersPerBox > 0) {
        $sealedToAdd = max(1, (int)ceil($shortage / $ordersPerBox));

        return [
            'stock_units' => $stockUnits + $sealedToAdd,
            'units_in_use' => min($shortage, (float)$ordersPerBox),
            'open_items_count' => 1,
        ];
    }

    return [
        'stock_units' => $stockUnits + max(1, (int)ceil($shortage)),
        'units_in_use' => $ordersLeft,
        'open_items_count' => $openCount,
    ];
}

function restock_inventory_shortages(PDO $pdo, array $shortages): array
{
    if (!$shortages) {
        return [];
    }

    $selectStmt = $pdo->prepare(
        'SELECT id, item_name, stock_units, units_in_use, open_items_count, orders_per_box, per_stock_amount, stock_type, category_name
         FROM inventory_items
         WHERE id = :id
           AND is_active = 1
           AND menu_item_id IS NULL
         LIMIT 1'
    );
    $updateStmt = $pdo->prepare(
        'UPDATE inventory_items
         SET stock_units = :stock_units,
             units_in_use = :units_in_use,
             open_items_count = :open_items_count
         WHERE id = :id'
    );

    $restocked = [];

    foreach ($shortages as $s) {
        if (!is_array($s)) {
            continue;
        }
        $invId = (int)($s['inventory_item_id'] ?? 0);
        $shortage = max(0, (float)($s['shortage'] ?? 0));
        if ($invId <= 0 || $shortage <= 0) {
            continue;
        }

        $selectStmt->execute([':id' => $invId]);
        $row = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $counts = compute_restock_counts_for_shortage($row, $shortage);
        $updateStmt->execute([
            ':stock_units' => $counts['stock_units'],
            ':units_in_use' => $counts['units_in_use'],
            ':open_items_count' => $counts['open_items_count'],
            ':id' => $invId,
        ]);

        $restocked[] = [
            'inventory_item_id' => $invId,
            'item_name' => (string)($row['item_name'] ?? $s['item_name'] ?? 'Item'),
            'shortage' => round($shortage, 2),
        ];
    }

    return $restocked;
}

/**
 * Deduct inventory as soon as the order is punched/placed.
 * Skips if this order was already processed.
 */
function apply_order_inventory_deduction(PDO $pdo, int $orderId): void {
    $flagStmt = $pdo->prepare('SELECT inventory_deducted FROM orders WHERE id = :id FOR UPDATE');
    $flagStmt->execute([':id' => $orderId]);
    $flagRow = $flagStmt->fetch(PDO::FETCH_ASSOC);
    if (!$flagRow || (int)($flagRow['inventory_deducted'] ?? 0) === 1) {
        return;
    }

    $recipeMenuIds = deduct_recipe_ingredients_for_order($pdo, $orderId);
    deduct_linked_materials_for_order($pdo, $orderId, $recipeMenuIds);
    deduct_menu_sku_stock_for_order($pdo, $orderId);
    deduct_addon_inventory_for_order($pdo, $orderId);

    $pdo->prepare('UPDATE orders SET inventory_deducted = 1 WHERE id = :id')
        ->execute([':id' => $orderId]);
}

/**
 * Deduct inventory linked to sold add-ons (parsed from order_items.notes).
 */
function deduct_addon_inventory_for_order(PDO $pdo, int $orderId): void
{
    // Do NOT run ensure_addons_schema() here — DDL inside an open order transaction
    // causes MySQL to implicitly commit and then commit() fails with
    // "There is no active transaction". Schema is ensured before beginTransaction().
    if (!function_exists('parse_addon_names_from_order_notes')) {
        require_once __DIR__ . '/addons_helpers.php';
    }

    $rows = $pdo->prepare(
        'SELECT quantity, notes
           FROM order_items
          WHERE order_id = :oid'
    );
    $rows->execute([':oid' => $orderId]);
    $lineRows = $rows->fetchAll(PDO::FETCH_ASSOC);
    if (!$lineRows) {
        return;
    }

    $addonMapStmt = $pdo->query(
        'SELECT id, name, inventory_item_id, inventory_qty
           FROM addons
          WHERE is_active = 1
            AND inventory_item_id IS NOT NULL
            AND inventory_item_id > 0'
    );
    $addonByName = [];
    foreach ($addonMapStmt->fetchAll(PDO::FETCH_ASSOC) as $addon) {
        $key = strtolower(trim((string)($addon['name'] ?? '')));
        if ($key === '') {
            continue;
        }
        $addonByName[$key] = [
            'inventory_item_id' => (int)$addon['inventory_item_id'],
            'inventory_qty' => normalize_addon_inventory_qty($addon['inventory_qty'] ?? 1),
        ];
    }
    if (!$addonByName) {
        return;
    }

    foreach ($lineRows as $line) {
        $orderQty = max(1, (int)($line['quantity'] ?? 1));
        $names = parse_addon_names_from_order_notes((string)($line['notes'] ?? ''));
        if (!$names) {
            continue;
        }
        foreach ($names as $name) {
            $key = strtolower(trim($name));
            if ($key === '' || !isset($addonByName[$key])) {
                continue;
            }
            $invId = (int)$addonByName[$key]['inventory_item_id'];
            $perAddonQty = (float)$addonByName[$key]['inventory_qty'];
            $deductQty = $orderQty * $perAddonQty;
            if ($invId <= 0 || $deductQty <= 0) {
                continue;
            }
            deduct_units_in_use_with_refill($pdo, $invId, $deductQty);
        }
    }
}

/**
 * Aggregate recipe ingredient usage for one order into $usageByInvId.
 *
 * @param array<int, array<string, mixed>> $usageByInvId
 */
function aggregate_recipe_usage_for_order(PDO $pdo, int $orderId, array &$usageByInvId): void
{
    ensure_recipe_schema_shared($pdo);

    $rows = $pdo->prepare(
        'SELECT oi.menu_item_id, oi.quantity, oi.notes
         FROM order_items oi
         WHERE oi.order_id = :oid'
    );
    $rows->execute([':oid' => $orderId]);

    $menuDescStmt = $pdo->prepare('SELECT description FROM menu_items WHERE id = :id LIMIT 1');
    $recipeStmt = $pdo->prepare(
        'SELECT id
         FROM recipes
         WHERE menu_item_id = :menu_id
           AND variant_signature = :variant_signature
         LIMIT 1'
    );
    $recipeFallbackStmt = $pdo->prepare(
        'SELECT id
         FROM recipes
         WHERE menu_item_id = :menu_id
           AND variant_signature = ""
         LIMIT 1'
    );
    $ingredientStmt = $pdo->prepare(
        'SELECT inventory_item_id, ingredient_name, quantity
         FROM recipe_ingredients
         WHERE recipe_id = :recipe_id
         ORDER BY sort_order ASC, id ASC'
    );

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $menuId = (int)($row['menu_item_id'] ?? 0);
        if ($menuId <= 0) {
            continue;
        }
        $orderQty = max(1, (int)($row['quantity'] ?? 1));
        $notes = (string)($row['notes'] ?? '');

        $menuDescStmt->execute([':id' => $menuId]);
        $menuRow = $menuDescStmt->fetch(PDO::FETCH_ASSOC);
        $description = (string)($menuRow['description'] ?? '');
        $variantSignature = resolve_variant_signature_from_notes($description, $notes);

        $recipeStmt->execute([
            ':menu_id' => $menuId,
            ':variant_signature' => $variantSignature,
        ]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$recipe && $variantSignature !== '') {
            $recipeFallbackStmt->execute([':menu_id' => $menuId]);
            $recipe = $recipeFallbackStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$recipe) {
            continue;
        }

        $ingredientStmt->execute([':recipe_id' => (int)$recipe['id']]);
        $ingredients = $ingredientStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$ingredients) {
            continue;
        }

        foreach ($ingredients as $ing) {
            $invId = resolve_inventory_item_id(
                $pdo,
                isset($ing['inventory_item_id']) ? (int)$ing['inventory_item_id'] : null,
                (string)($ing['ingredient_name'] ?? '')
            );
            if (!$invId) {
                continue;
            }

            $recipeQty = max(0, (float)($ing['quantity'] ?? 1));
            $deductQty = (float)$orderQty * $recipeQty;
            if ($deductQty <= 0) {
                continue;
            }

            if (!isset($usageByInvId[$invId])) {
                $usageByInvId[$invId] = [
                    'qty_used' => 0.0,
                    'qty_received' => 0.0,
                    'qty_wasted' => 0.0,
                ];
            }
            $usageByInvId[$invId]['qty_used'] += $deductQty;
        }
    }
}

/**
 * @return list<array{
 *   id:int,
 *   name:string,
 *   category_name:string,
 *   category_type:string,
 *   category_type_label:string,
 *   qty_used:float,
 *   qty_received:float,
 *   qty_wasted:float,
 *   qty_movement:float
 * }>
 */
function fetch_inventory_movement_for_date(PDO $pdo, string $date): array
{
    ensure_inventory_items_base_schema($pdo);
    ensure_recipe_schema_shared($pdo);

    $date = trim($date);
    if ($date === '') {
        $date = date('Y-m-d');
    }

    $catalog = [];
    $catalogStmt = $pdo->query(
        'SELECT id, item_name, category_name, category_type, stock_type
         FROM inventory_items
         WHERE is_active = 1'
    );
    foreach ($catalogStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $catalog[$id] = [
            'id' => $id,
            'name' => (string)$row['item_name'],
            'category_name' => (string)$row['category_name'],
            'category_type' => normalize_inventory_category_type($row['category_type'] ?? 'main'),
            'stock_type' => normalize_inventory_stock_type($row['stock_type'] ?? 'consumable'),
            'qty_used' => 0.0,
            'qty_received' => 0.0,
            'qty_wasted' => 0.0,
        ];
    }

    $usageScratch = [];
    $orderStmt = $pdo->prepare(
        'SELECT id
         FROM orders
         WHERE status IN ("confirmed","served")
           AND DATE(created_at) = :d'
    );
    $orderStmt->execute([':d' => $date]);
    foreach ($orderStmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        aggregate_recipe_usage_for_order($pdo, (int)$orderId, $usageScratch);
    }
    foreach ($usageScratch as $invId => $totals) {
        if (!isset($catalog[$invId])) {
            continue;
        }
        $catalog[$invId]['qty_used'] += (float)($totals['qty_used'] ?? 0);
    }

    try {
        $receiptStmt = $pdo->prepare(
            'SELECT rl.ItemName, rl.LineType, SUM(rl.Quantity) AS qty_in
             FROM ReceiptLines rl
             INNER JOIN Receipts r ON r.ReceiptID = rl.ReceiptID
             WHERE DATE(r.`Date`) = :d
             GROUP BY rl.ItemName, rl.LineType'
        );
        $receiptStmt->execute([':d' => $date]);
        $findInvStmt = $pdo->prepare(
            'SELECT id
             FROM inventory_items
             WHERE is_active = 1
               AND LOWER(TRIM(item_name)) = LOWER(TRIM(:item_name))
               AND category_name = :category_name
             LIMIT 1'
        );
        foreach ($receiptStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findInvStmt->execute([
                ':item_name' => (string)$row['ItemName'],
                ':category_name' => (string)$row['LineType'],
            ]);
            $invId = (int)$findInvStmt->fetchColumn();
            if ($invId <= 0 || !isset($catalog[$invId])) {
                continue;
            }
            $catalog[$invId]['qty_received'] += (float)($row['qty_in'] ?? 0);
        }
    } catch (Throwable $e) {
        // Receipt tables may not exist on older installs.
    }

    try {
        $wasteStmt = $pdo->prepare(
            'SELECT inventory_item_id, SUM(quantity) AS qty_wasted
             FROM waste_log
             WHERE inventory_item_id IS NOT NULL
               AND DATE(logged_at) = :d
             GROUP BY inventory_item_id'
        );
        $wasteStmt->execute([':d' => $date]);
        foreach ($wasteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invId = (int)($row['inventory_item_id'] ?? 0);
            if ($invId <= 0 || !isset($catalog[$invId])) {
                continue;
            }
            $catalog[$invId]['qty_wasted'] += (float)($row['qty_wasted'] ?? 0);
        }
    } catch (Throwable $e) {
        // waste_log may not exist on older installs.
    }

    $out = [];
    foreach ($catalog as $item) {
        $qtyUsed = round((float)$item['qty_used'], 2);
        $qtyReceived = round((float)$item['qty_received'], 2);
        $qtyWasted = round((float)$item['qty_wasted'], 2);
        $qtyMovement = $qtyUsed + $qtyReceived + $qtyWasted;
        if ($qtyMovement <= 0) {
            continue;
        }
        $type = (string)$item['category_type'];
        $out[] = [
            'id' => (int)$item['id'],
            'name' => (string)$item['name'],
            'category_name' => (string)$item['category_name'],
            'category_type' => $type,
            'category_type_label' => ucwords(str_replace('_', ' ', $type)),
            'stock_type' => (string)$item['stock_type'],
            'qty_used' => $qtyUsed,
            'qty_received' => $qtyReceived,
            'qty_wasted' => $qtyWasted,
            'qty_movement' => $qtyMovement,
        ];
    }

    usort($out, static function ($a, $b) {
        $cmp = ($b['qty_movement'] <=> $a['qty_movement']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });

    return $out;
}
