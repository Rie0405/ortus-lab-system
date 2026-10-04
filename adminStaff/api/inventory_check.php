<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recipe_helpers.php';

$user = require_auth();
$pdo = db();

function ensure_inventory_check_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_check_selection (
            inventory_item_id INT NOT NULL PRIMARY KEY,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_inv_check_item
                FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function fetch_inventory_check_catalog(PDO $pdo): array
{
    ensure_inventory_check_schema($pdo);
    ensure_inventory_items_base_schema($pdo);

    $selectedStmt = $pdo->query(
        'SELECT inventory_item_id
           FROM inventory_check_selection
          ORDER BY sort_order ASC, inventory_item_id ASC'
    );
    $selectedIds = array_map('intval', $selectedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $selectedSet = array_fill_keys($selectedIds, true);

    $rows = $pdo->query(
        'SELECT id, item_name, category_name, stock_units, units_in_use, open_items_count,
                orders_per_box, per_stock_amount, per_stock_unit, stock_type, entry_mode, stock_status
           FROM inventory_items
          WHERE is_active = 1
            AND menu_item_id IS NULL
          ORDER BY category_name ASC, item_name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $alertPercent = function_exists('get_low_stock_fraction_den')
        ? (int)get_low_stock_fraction_den($pdo)
        : 50;

    // get_low_stock_fraction_den lives in inventory.php — fall back if not loaded.
    if (!function_exists('get_low_stock_fraction_den')) {
        try {
            $val = $pdo->query('SELECT low_stock_fraction_den FROM inventory_settings WHERE id = 1 LIMIT 1')->fetchColumn();
            $percent = (int)$val;
            $allowed = [75, 50, 25];
            $alertPercent = in_array($percent, $allowed, true) ? $percent : 50;
        } catch (Throwable $e) {
            $alertPercent = 50;
        }
    }

    $items = [];
    $checkItems = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $status = inventory_computed_status_for_row($row, $alertPercent);
        $payload = [
            'id' => $id,
            'name' => (string)$row['item_name'],
            'category_name' => (string)($row['category_name'] ?? ''),
            'stock_units' => (int)($row['stock_units'] ?? 0),
            'units_in_use' => (float)($row['units_in_use'] ?? 0),
            'open_items_count' => open_items_count_for_row($row),
            'per_stock_unit' => (string)($row['per_stock_unit'] ?? 'pcs'),
            'status' => $status,
            'selected' => isset($selectedSet[$id]),
        ];
        $items[] = $payload;
        if (isset($selectedSet[$id])) {
            $checkItems[] = $payload;
        }
    }

    // Keep check_items in saved sort order.
    if ($selectedIds) {
        $byId = [];
        foreach ($checkItems as $ci) {
            $byId[(int)$ci['id']] = $ci;
        }
        $ordered = [];
        foreach ($selectedIds as $sid) {
            if (isset($byId[$sid])) {
                $ordered[] = $byId[$sid];
            }
        }
        $checkItems = $ordered;
    }

    return [
        'selected_ids' => $selectedIds,
        'items' => $items,
        'check_items' => $checkItems,
    ];
}

ensure_inventory_check_schema($pdo);
$m = method();

if ($m === 'GET') {
    ok(fetch_inventory_check_catalog($pdo));
}

if ($m === 'PUT' || $m === 'POST') {
    // Only admin configures which items appear in the staff shift-open popup.
    require_auth('admin');
    $b = body();
    $idsRaw = $b['inventory_item_ids'] ?? $b['selected_ids'] ?? [];
    if (!is_array($idsRaw)) {
        fail('inventory_item_ids must be an array.');
    }

    $ids = [];
    foreach ($idsRaw as $raw) {
        $id = (int)$raw;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $validStmt = $pdo->prepare(
            "SELECT id FROM inventory_items
              WHERE is_active = 1
                AND menu_item_id IS NULL
                AND id IN ($placeholders)"
        );
        $validStmt->execute($ids);
        $validIds = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        // Preserve request order among valid ids.
        $validSet = array_fill_keys($validIds, true);
        $ids = array_values(array_filter($ids, static function ($id) use ($validSet) {
            return isset($validSet[$id]);
        }));
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM inventory_check_selection');
        if ($ids) {
            $ins = $pdo->prepare(
                'INSERT INTO inventory_check_selection (inventory_item_id, sort_order)
                 VALUES (:id, :ord)'
            );
            foreach ($ids as $i => $id) {
                $ins->execute([':id' => $id, ':ord' => $i]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail('Failed to save inventory check selection: ' . $e->getMessage(), 500);
    }

    $catalog = fetch_inventory_check_catalog($pdo);
    ok(array_merge(['message' => 'Inventory check selection saved.'], $catalog));
}

fail('Method not allowed.', 405);
