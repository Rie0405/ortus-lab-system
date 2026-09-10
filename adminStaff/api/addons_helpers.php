<?php
require_once __DIR__ . '/menu_helpers.php';

function ensure_addons_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensure_main_categories_schema($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            main_category_id INT NOT NULL,
            inventory_item_id INT NULL DEFAULT NULL,
            inventory_qty DECIMAL(12,2) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_addon_name_station (main_category_id, name),
            KEY idx_addons_active (is_active, display_order),
            KEY idx_addons_main_category (main_category_id),
            KEY idx_addons_inventory_item (inventory_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $cols = [
        'inventory_item_id' => 'ALTER TABLE addons ADD COLUMN inventory_item_id INT NULL DEFAULT NULL AFTER main_category_id',
        'inventory_qty' => 'ALTER TABLE addons ADD COLUMN inventory_qty DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER inventory_item_id',
        'menu_item_id' => 'ALTER TABLE addons ADD COLUMN menu_item_id INT NULL DEFAULT NULL AFTER inventory_qty',
    ];
    foreach ($cols as $column => $sql) {
        $chk = $pdo->query('SHOW COLUMNS FROM addons LIKE ' . $pdo->quote($column));
        if ($chk && $chk->fetch()) {
            continue;
        }
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Ignore migration issues on restricted environments.
        }
    }

    try {
        $idx = $pdo->query("SHOW INDEX FROM addons WHERE Key_name = 'idx_addons_menu_item'");
        if ($idx && !$idx->fetch()) {
            $pdo->exec('ALTER TABLE addons ADD KEY idx_addons_menu_item (menu_item_id)');
        }
    } catch (Throwable $e) {
        // Ignore.
    }
}

/**
 * Dedicated sellable category for standalone addon cards on POS / kiosk.
 */
function ensure_addons_menu_category(PDO $pdo): int
{
    ensure_catalog_icon_columns($pdo);
    $stmt = $pdo->query(
        "SELECT id FROM categories
          WHERE is_active = 1 AND LOWER(TRIM(name)) IN ('add-ons', 'addons', 'add ons')
          ORDER BY id ASC
          LIMIT 1"
    );
    $id = (int)($stmt ? $stmt->fetchColumn() : 0);
    if ($id > 0) {
        return $id;
    }

    $nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM categories')->fetchColumn();
    $ins = $pdo->prepare(
        'INSERT INTO categories (name, display_order, is_active) VALUES (:name, :ord, 1)'
    );
    $ins->execute([':name' => 'Add-ons', ':ord' => max(1, $nextOrder)]);
    return (int)$pdo->lastInsertId();
}

/**
 * Keep a menu_items row in sync so the addon can be sold as its own card.
 */
function sync_addon_menu_item(PDO $pdo, int $addonId): ?int
{
    ensure_addons_schema($pdo);
    if ($addonId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, price, main_category_id, menu_item_id, is_active
           FROM addons
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $addonId]);
    $addon = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$addon) {
        return null;
    }

    $categoryId = ensure_addons_menu_category($pdo);
    $mainCategoryId = resolve_main_category_id($pdo, (int)($addon['main_category_id'] ?? 0));
    $name = trim((string)($addon['name'] ?? ''));
    $price = round(max(0, (float)($addon['price'] ?? 0)), 2);
    $available = !empty($addon['is_active']) ? 1 : 0;
    $description = 'Standalone add-on';
    $menuItemId = !empty($addon['menu_item_id']) ? (int)$addon['menu_item_id'] : 0;

    if ($menuItemId > 0) {
        $exists = $pdo->prepare('SELECT id FROM menu_items WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $menuItemId]);
        if (!$exists->fetch()) {
            $menuItemId = 0;
        }
    }

    if ($menuItemId > 0) {
        $upd = $pdo->prepare(
            'UPDATE menu_items
                SET category_id = :cid,
                    main_category_id = :mcid,
                    subcategory_id = NULL,
                    name = :name,
                    description = :desc,
                    price = :price,
                    is_available = :avail,
                    serve_hot = 0,
                    serve_cold = 0
              WHERE id = :id'
        );
        $upd->execute([
            ':cid' => $categoryId,
            ':mcid' => $mainCategoryId,
            ':name' => $name,
            ':desc' => $description,
            ':price' => $price,
            ':avail' => $available,
            ':id' => $menuItemId,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO menu_items
                (category_id, main_category_id, subcategory_id, name, description, price, image_url, is_available, serve_hot, serve_cold)
             VALUES
                (:cid, :mcid, NULL, :name, :desc, :price, NULL, :avail, 0, 0)'
        );
        $ins->execute([
            ':cid' => $categoryId,
            ':mcid' => $mainCategoryId,
            ':name' => $name,
            ':desc' => $description,
            ':price' => $price,
            ':avail' => $available,
        ]);
        $menuItemId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE addons SET menu_item_id = :mid WHERE id = :id')
            ->execute([':mid' => $menuItemId, ':id' => $addonId]);
    }

    return $menuItemId > 0 ? $menuItemId : null;
}

function hide_addon_menu_item(PDO $pdo, int $addonId): void
{
    ensure_addons_schema($pdo);
    $stmt = $pdo->prepare('SELECT menu_item_id FROM addons WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $addonId]);
    $menuItemId = (int)$stmt->fetchColumn();
    if ($menuItemId <= 0) {
        return;
    }
    $pdo->prepare('UPDATE menu_items SET is_available = 0 WHERE id = :id')
        ->execute([':id' => $menuItemId]);
}

/**
 * Backfill / refresh sellable addon cards (once per request lifecycle).
 */
function ensure_addon_menu_items_synced(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensure_addons_schema($pdo);
    $ids = $pdo->query('SELECT id FROM addons WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids ?: [] as $id) {
        sync_addon_menu_item($pdo, (int)$id);
    }
}

/**
 * Mark menu rows that are standalone addon cards.
 *
 * @param list<array<string,mixed>> $items
 */
function annotate_menu_items_addon_flags(PDO $pdo, array &$items): void
{
    ensure_addons_schema($pdo);
    $linked = [];
    try {
        $stmt = $pdo->query(
            'SELECT menu_item_id FROM addons
              WHERE is_active = 1 AND menu_item_id IS NOT NULL AND menu_item_id > 0'
        );
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $mid) {
            $linked[(int)$mid] = true;
        }
    } catch (Throwable $e) {
        $linked = [];
    }

    foreach ($items as &$item) {
        $cat = strtolower(trim((string)($item['category_name'] ?? '')));
        $isCat = ($cat === 'add-ons' || $cat === 'addons' || $cat === 'add ons');
        $item['is_addon_card'] = !empty($linked[(int)($item['id'] ?? 0)]) || $isCat;
    }
    unset($item);
}

function normalize_addon_inventory_qty($qty): float
{
    $n = (float)$qty;
    if (!is_finite($n) || $n <= 0) {
        return 1.0;
    }
    return round($n, 2);
}

function fetch_addons_rows(PDO $pdo, ?int $mainCategoryId = null, bool $activeOnly = true): array
{
    ensure_addons_schema($pdo);
    $sql = 'SELECT a.id, a.name, a.price, a.main_category_id, a.inventory_item_id, a.inventory_qty,
                   a.menu_item_id, a.display_order, a.is_active,
                   mc.name AS station_name,
                   ii.item_name AS inventory_item_name
              FROM addons a
              JOIN main_categories mc ON mc.id = a.main_category_id
              LEFT JOIN inventory_items ii ON ii.id = a.inventory_item_id AND ii.is_active = 1
             WHERE 1=1';
    $params = [];
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1 AND mc.is_active = 1';
    }
    if ($mainCategoryId !== null && $mainCategoryId > 0) {
        $sql .= ' AND a.main_category_id = :mcid';
        $params[':mcid'] = $mainCategoryId;
    }
    $sql .= ' ORDER BY mc.display_order, a.display_order, a.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['price'] = round((float)$row['price'], 2);
        $row['main_category_id'] = (int)$row['main_category_id'];
        $row['inventory_item_id'] = !empty($row['inventory_item_id']) ? (int)$row['inventory_item_id'] : null;
        $row['inventory_qty'] = normalize_addon_inventory_qty($row['inventory_qty'] ?? 1);
        $row['inventory_item_name'] = $row['inventory_item_id']
            ? trim((string)($row['inventory_item_name'] ?? ''))
            : '';
        $row['menu_item_id'] = !empty($row['menu_item_id']) ? (int)$row['menu_item_id'] : null;
        $row['display_order'] = (int)$row['display_order'];
        $row['is_active'] = !empty($row['is_active']) ? 1 : 0;
        $row['station'] = strtolower(trim((string)($row['station_name'] ?? ''))) === 'bar' ? 'bar' : 'kitchen';
    }
    unset($row);
    return $rows;
}

/**
 * Parse addon display names from order_items.notes "Add-ons: ..." segment.
 *
 * @return list<string>
 */
function parse_addon_names_from_order_notes(string $notes): array
{
    if ($notes === '' || !preg_match('/Add-ons\s*:\s*(.+?)(?:\s*\||$)/is', $notes, $m)) {
        return [];
    }
    $chunk = trim((string)$m[1]);
    if ($chunk === '') {
        return [];
    }
    $parts = preg_split('/\s*,\s*/', $chunk) ?: [];
    $names = [];
    foreach ($parts as $part) {
        $name = trim((string)$part);
        // Strip price suffix like "(P20.00)" or "(20.00)".
        $name = preg_replace('/\s*\((?:P|PHP)?\s*[\d.,]+\)\s*$/iu', '', $name) ?? $name;
        $name = trim($name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}
