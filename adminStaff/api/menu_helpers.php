<?php

function infer_serve_flags_from_description(?string $desc): array
{
    $hot = false;
    $cold = false;
    $text = strtolower((string)$desc);

    if (preg_match('/variants:\s*([^\n]+)/i', $text, $m)) {
        $seg = $m[1];
        if (preg_match('/\b(hot|warm)\b/', $seg)) {
            $hot = true;
        }
        if (preg_match('/\b(iced|cold|blended|frappe)\b/', $seg)) {
            $cold = true;
        }
    }

    if (preg_match('/__pos_bev_section__:\s*(\w+)/i', $text, $m)) {
        $sec = strtolower($m[1]);
        if (strpos($sec, 'frappe') !== false) {
            $cold = true;
        }
        if ($sec === 'refreshers' || $sec === 'juice') {
            $cold = true;
        }
    }

    return ['hot' => $hot, 'cold' => $cold];
}

function ensure_menu_serve_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $hasHot = (bool)$pdo->query("SHOW COLUMNS FROM menu_items LIKE 'serve_hot'")->fetch();
    if (!$hasHot) {
        $pdo->exec(
            'ALTER TABLE menu_items
                ADD COLUMN serve_hot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_available,
                ADD COLUMN serve_cold TINYINT(1) NOT NULL DEFAULT 0 AFTER serve_hot'
        );
    }

    ensure_menu_cost_price_schema($pdo);

    $rows = $pdo->query(
        "SELECT m.id, m.description, m.serve_hot, m.serve_cold
           FROM menu_items m
           JOIN categories c ON c.id = m.category_id
          WHERE LOWER(c.name) = 'drinks'
            AND m.serve_hot = 0
            AND m.serve_cold = 0"
    )->fetchAll();

    if (!$rows) {
        return;
    }

    $upd = $pdo->prepare('UPDATE menu_items SET serve_hot = :hot, serve_cold = :cold WHERE id = :id');
    foreach ($rows as $row) {
        $flags = infer_serve_flags_from_description($row['description'] ?? '');
        if (!$flags['hot'] && !$flags['cold']) {
            $flags = ['hot' => true, 'cold' => true];
        }
        $upd->execute([
            ':hot' => $flags['hot'] ? 1 : 0,
            ':cold' => $flags['cold'] ? 1 : 0,
            ':id' => (int)$row['id'],
        ]);
    }
}

function ensure_menu_cost_price_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $hasCost = (bool)$pdo->query("SHOW COLUMNS FROM menu_items LIKE 'cost_price'")->fetch();
    if (!$hasCost) {
        $pdo->exec(
            'ALTER TABLE menu_items
                ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER price'
        );
    }
}

function ensure_subcategories_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subcategories (
            id INT(11) NOT NULL AUTO_INCREMENT,
            category_id INT(11) NOT NULL,
            name VARCHAR(100) NOT NULL,
            display_order INT(11) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_subcategory_category_name (category_id, name),
            KEY category_id (category_id),
            CONSTRAINT subcategories_ibfk_1 FOREIGN KEY (category_id) REFERENCES categories (id) ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM menu_items LIKE 'subcategory_id'")->fetch();
    if (!$hasCol) {
        $pdo->exec(
            'ALTER TABLE menu_items
                ADD COLUMN subcategory_id INT(11) NULL DEFAULT NULL AFTER category_id,
                ADD KEY subcategory_id (subcategory_id),
                ADD CONSTRAINT menu_items_ibfk_subcategory
                    FOREIGN KEY (subcategory_id) REFERENCES subcategories (id)
                    ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    $drinksId = $pdo->query("SELECT id FROM categories WHERE LOWER(name) = 'drinks' LIMIT 1")->fetchColumn();
    if ($drinksId) {
        // Hot/Cold are temperatures (serve_hot / serve_cold), not food-group subcategories.
        $drinkSubs = [
            ['Coffee', 1],
            ['Non-Coffee', 2],
            ['Frappe', 3],
            ['Refreshers', 4],
        ];
        $seed = $pdo->prepare(
            'INSERT IGNORE INTO subcategories (category_id, name, display_order, is_active)
             VALUES (:cid, :name, :ord, 1)'
        );
        foreach ($drinkSubs as $row) {
            $seed->execute([':cid' => (int)$drinksId, ':name' => $row[0], ':ord' => $row[1]]);
        }

        migrate_menu_bev_sections_to_subcategories($pdo, (int)$drinksId);
    }

    // Soft-remove legacy Hot/Cold subcategory rows (temperatures, not categories).
    try {
        $pdo->exec(
            "UPDATE subcategories
                SET is_active = 0
              WHERE is_active = 1
                AND LOWER(TRIM(name)) IN ('hot', 'cold')"
        );
    } catch (Throwable $e) {
        // Ignore on restricted environments.
    }

    ensure_catalog_icon_columns($pdo);
}

function ensure_catalog_icon_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $catHas = (bool)$pdo->query("SHOW COLUMNS FROM categories LIKE 'icon_url'")->fetch();
    if (!$catHas) {
        try {
            $pdo->exec('ALTER TABLE categories ADD COLUMN icon_url VARCHAR(500) NULL DEFAULT NULL AFTER name');
        } catch (Throwable $e) {
            // Ignore migration issues on restricted environments.
        }
    }

    $subHas = (bool)$pdo->query("SHOW COLUMNS FROM subcategories LIKE 'icon_url'")->fetch();
    if (!$subHas) {
        try {
            $pdo->exec('ALTER TABLE subcategories ADD COLUMN icon_url VARCHAR(500) NULL DEFAULT NULL AFTER name');
        } catch (Throwable $e) {
            // Ignore migration issues on restricted environments.
        }
    }
}

function bev_section_key_to_subcategory_name(string $key): ?string
{
    $k = strtolower(trim($key));
    return match ($k) {
        'coffee' => 'Coffee',
        'noncoffee' => 'Non-Coffee',
        'frappecoffee', 'frappenoncoffee' => 'Frappe',
        'refreshers', 'juice' => 'Refreshers',
        default => null,
    };
}

function migrate_menu_bev_sections_to_subcategories(PDO $pdo, int $drinksCategoryId): void
{
    $subByName = [];
    $stmt = $pdo->prepare(
        'SELECT id, name FROM subcategories WHERE category_id = :cid AND is_active = 1'
    );
    $stmt->execute([':cid' => $drinksCategoryId]);
    foreach ($stmt->fetchAll() as $row) {
        $subByName[strtolower((string)$row['name'])] = (int)$row['id'];
    }

    $items = $pdo->query(
        "SELECT m.id, m.description, m.subcategory_id
           FROM menu_items m
           JOIN categories c ON c.id = m.category_id
          WHERE LOWER(c.name) = 'drinks'
            AND (m.subcategory_id IS NULL OR m.subcategory_id = 0)"
    )->fetchAll();

    $upd = $pdo->prepare('UPDATE menu_items SET subcategory_id = :sid WHERE id = :id');
    foreach ($items as $item) {
        if (!empty($item['subcategory_id'])) {
            continue;
        }
        $desc = (string)($item['description'] ?? '');
        if (!preg_match('/__pos_bev_section__:\s*(\w+)/i', $desc, $m)) {
            continue;
        }
        $subName = bev_section_key_to_subcategory_name(trim($m[1]));
        if (!$subName) {
            continue;
        }
        $sid = $subByName[strtolower($subName)] ?? null;
        if (!$sid) {
            continue;
        }
        $upd->execute([':sid' => $sid, ':id' => (int)$item['id']]);
    }
}

function fetch_active_subcategories(PDO $pdo): array
{
    ensure_catalog_icon_columns($pdo);
    $rows = $pdo->query(
        'SELECT s.id, s.category_id, c.name AS category_name, s.name, s.icon_url, s.display_order
           FROM subcategories s
           JOIN categories c ON c.id = s.category_id
          WHERE s.is_active = 1 AND c.is_active = 1
          ORDER BY c.display_order, s.display_order, s.name'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['category_id'] = (int)$row['category_id'];
        $row['display_order'] = (int)$row['display_order'];
        $row['icon_url'] = isset($row['icon_url']) && $row['icon_url'] !== null && $row['icon_url'] !== ''
            ? (string)$row['icon_url']
            : null;
    }
    unset($row);
    return $rows;
}

function ensure_main_categories_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS main_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_main_category_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Optional variants config per main category (station).
    try {
        $hasVe = (bool)$pdo->query("SHOW COLUMNS FROM main_categories LIKE 'variants_enabled'")->fetch();
        if (!$hasVe) {
            $pdo->exec(
                'ALTER TABLE main_categories
                    ADD COLUMN variants_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
            );
        }
    } catch (Throwable $e) {
        // Hostinger may block ALTER — run SQL manually (see ops notes).
    }
    try {
        $hasVj = (bool)$pdo->query("SHOW COLUMNS FROM main_categories LIKE 'variants_json'")->fetch();
        if (!$hasVj) {
            $pdo->exec(
                'ALTER TABLE main_categories
                    ADD COLUMN variants_json TEXT NULL AFTER variants_enabled'
            );
        }
    } catch (Throwable $e) {
        // Hostinger may block ALTER — run SQL manually (see ops notes).
    }

    $pdo->exec(
        "INSERT IGNORE INTO main_categories (name, display_order, is_active) VALUES
            ('Bar', 1, 1),
            ('Kitchen', 2, 1)"
    );

    try {
        $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM menu_items LIKE 'main_category_id'")->fetch();
    } catch (Throwable $e) {
        // menu_items may be unavailable on partial schemas; main_categories alone is enough for receipts.
        return;
    }
    if (!$hasCol) {
        try {
            $pdo->exec(
                'ALTER TABLE menu_items
                    ADD COLUMN main_category_id INT NULL DEFAULT NULL AFTER category_id,
                    ADD KEY idx_menu_main_category (main_category_id)'
            );
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $barId = (int)$pdo->query("SELECT id FROM main_categories WHERE LOWER(name) = 'bar' LIMIT 1")->fetchColumn();
    $kitchenId = (int)$pdo->query("SELECT id FROM main_categories WHERE LOWER(name) = 'kitchen' LIMIT 1")->fetchColumn();
    if ($barId <= 0 || $kitchenId <= 0) {
        return;
    }

    try {
        $rows = $pdo->query(
            'SELECT m.id, c.name AS category_name
               FROM menu_items m
               JOIN categories c ON c.id = m.category_id
              WHERE m.main_category_id IS NULL OR m.main_category_id = 0'
        )->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    if ($rows) {
        $upd = $pdo->prepare('UPDATE menu_items SET main_category_id = :mcid WHERE id = :id');
        foreach ($rows as $row) {
            $cat = strtolower(trim((string)($row['category_name'] ?? '')));
            $isBar = ($cat === 'drinks' || $cat === 'beverages' || $cat === 'coffee'
                || strpos($cat, 'drink') !== false || strpos($cat, 'coffee') !== false
                || strpos($cat, 'frappe') !== false || strpos($cat, 'refresher') !== false);
            $upd->execute([
                ':mcid' => $isBar ? $barId : $kitchenId,
                ':id' => (int)$row['id'],
            ]);
        }
    }

    // Tie categories to the station (main category) they were created under.
    $hasCatMc = (bool)$pdo->query("SHOW COLUMNS FROM categories LIKE 'main_category_id'")->fetch();
    if (!$hasCatMc) {
        try {
            $pdo->exec(
                'ALTER TABLE categories
                    ADD COLUMN main_category_id INT NULL DEFAULT NULL AFTER id,
                    ADD KEY idx_categories_main_category (main_category_id)'
            );
        } catch (Throwable $e) {
            // Ignore migration issues on restricted environments.
        }
    }

    $orphanCats = $pdo->query(
        'SELECT id, name FROM categories
          WHERE main_category_id IS NULL OR main_category_id = 0'
    )->fetchAll();
    if ($orphanCats) {
        $voteStmt = $pdo->prepare(
            'SELECT main_category_id, COUNT(*) AS cnt
               FROM menu_items
              WHERE category_id = :id
                AND main_category_id IS NOT NULL
                AND main_category_id > 0
              GROUP BY main_category_id
              ORDER BY cnt DESC
              LIMIT 1'
        );
        $updCat = $pdo->prepare('UPDATE categories SET main_category_id = :mcid WHERE id = :id');
        foreach ($orphanCats as $catRow) {
            $catId = (int)$catRow['id'];
            $voteStmt->execute([':id' => $catId]);
            $mcid = (int)$voteStmt->fetchColumn();
            if ($mcid <= 0) {
                $cat = strtolower(trim((string)($catRow['name'] ?? '')));
                $isBar = ($cat === 'drinks' || $cat === 'beverages' || $cat === 'coffee'
                    || strpos($cat, 'drink') !== false || strpos($cat, 'coffee') !== false
                    || strpos($cat, 'frappe') !== false || strpos($cat, 'refresher') !== false);
                $mcid = $isBar ? $barId : $kitchenId;
            }
            $updCat->execute([':mcid' => $mcid, ':id' => $catId]);
        }
    }
}

function assert_category_belongs_to_main(PDO $pdo, int $categoryId, int $mainCategoryId): void
{
    ensure_main_categories_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, main_category_id FROM categories WHERE id = :id AND is_active = 1'
    );
    $stmt->execute([':id' => $categoryId]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('Category not found.');
    }
    $catMain = isset($row['main_category_id']) ? (int)$row['main_category_id'] : 0;
    if ($catMain <= 0 || $catMain !== $mainCategoryId) {
        fail('Category does not belong to the selected main category.');
    }
}

function normalize_main_category_variants($raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? $row['size'] ?? ''));
        if ($name === '') {
            continue;
        }
        $price = null;
        if (isset($row['price']) && $row['price'] !== '' && $row['price'] !== null) {
            $price = (float)$row['price'];
            if (!is_finite($price) || $price < 0) {
                $price = null;
            }
        }
        $cost = null;
        $costRaw = $row['cost'] ?? $row['cost_price'] ?? null;
        if ($costRaw !== null && $costRaw !== '') {
            $cost = (float)$costRaw;
            if (!is_finite($cost) || $cost < 0) {
                $cost = null;
            }
        }
        $out[] = [
            'name' => $name,
            'price' => $price,
            'cost' => $cost,
        ];
    }
    return $out;
}

function format_variants_description_line(array $variants, ?float $fallbackPrice = null): string
{
    $parts = [];
    foreach ($variants as $v) {
        $name = trim((string)($v['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $price = $v['price'];
        if ($price === null || $price === '') {
            $price = $fallbackPrice;
        }
        $priceNum = is_numeric($price) ? (float)$price : 0.0;
        $seg = $name . ' = ' . number_format($priceNum, 2, '.', '');
        $cost = $v['cost'] ?? null;
        if ($cost !== null && $cost !== '' && is_numeric($cost)) {
            $seg .= ' / cost ' . number_format((float)$cost, 2, '.', '');
        }
        $parts[] = $seg;
    }
    return implode('; ', $parts);
}

function fetch_active_main_categories(PDO $pdo): array
{
    ensure_main_categories_schema($pdo);
    $rows = $pdo->query(
        'SELECT id, name, display_order, variants_enabled, variants_json
           FROM main_categories
          WHERE is_active = 1
          ORDER BY display_order, name'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['display_order'] = (int)($row['display_order'] ?? 0);
        $row['variants_enabled'] = !empty($row['variants_enabled']) ? 1 : 0;
        $row['variants'] = $row['variants_enabled']
            ? normalize_main_category_variants($row['variants_json'] ?? '[]')
            : [];
        unset($row['variants_json']);
    }
    unset($row);
    return $rows;
}

/**
 * Display name for a parsed Variants: segment (size + optional label).
 */
function variant_display_name_from_parsed(array $v): string
{
    $size = trim((string)($v['size'] ?? ''));
    $label = trim((string)($v['label'] ?? ''));
    if ($size !== '' && $label !== '' && strcasecmp($size, $label) !== 0) {
        return $size . ' (' . $label . ')';
    }
    return $size !== '' ? $size : $label;
}

/**
 * Apply main-category variant *names* onto a menu item for POS/kiosk clients.
 *
 * Selling/cost prices come from the product (per-item Variants: line and/or
 * menu_items.price / cost_price) — never from shared main_categories.variants_json
 * prices, which would stamp one product's prices onto every item in the station.
 */
function apply_main_category_variants_to_menu_item(array &$item, array $mainCatsById): void
{
    $mcid = (int)($item['main_category_id'] ?? 0);
    $mc = $mcid > 0 ? ($mainCatsById[$mcid] ?? null) : null;
    $enabled = $mc && !empty($mc['variants_enabled']) && !empty($mc['variants']);

    $perItem = parse_variants_from_description($item['description'] ?? '');

    $desc = (string)($item['description'] ?? '');
    $lines = preg_split("/\r\n|\n|\r/", $desc) ?: [];
    $lines = array_values(array_filter($lines, static function ($ln) {
        return !preg_match('/^\s*Variants\s*:/i', (string)$ln);
    }));
    $descClean = trim(implode("\n", $lines));

    $itemPrice = isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : null;
    $itemCost = isset($item['cost_price']) && is_numeric($item['cost_price'])
        ? (float)$item['cost_price']
        : null;

    // Prefer per-product Variants: line (edit/create modal). Station JSON is only a
    // fallback template — otherwise adding Hot in Update gets wiped on next GET
    // when main_categories.variants_json still only lists Iced.
    $merged = [];
    if ($perItem) {
        foreach ($perItem as $pv) {
            $name = variant_display_name_from_parsed($pv);
            if ($name === '') {
                continue;
            }
            $merged[] = [
                'name' => $name,
                'price' => isset($pv['price']) && is_numeric($pv['price'])
                    ? (float)$pv['price']
                    : $itemPrice,
                'cost' => array_key_exists('cost', $pv) && $pv['cost'] !== null && is_numeric($pv['cost'])
                    ? (float)$pv['cost']
                    : $itemCost,
            ];
        }
    } elseif ($enabled) {
        foreach ($mc['variants'] as $mv) {
            $name = trim((string)($mv['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $merged[] = [
                'name' => $name,
                'price' => $itemPrice,
                'cost' => $itemCost,
            ];
        }
    }

    $item['variants_enabled'] = $merged ? 1 : 0;
    $item['variants'] = $merged;

    if ($merged) {
        $line = 'Variants: ' . format_variants_description_line($merged, $itemPrice);
        $item['description'] = $descClean !== '' ? ($descClean . "\n" . $line) : $line;
    } else {
        $item['description'] = $descClean !== '' ? $descClean : null;
    }
}

function resolve_main_category_id(PDO $pdo, int $mainCategoryId): int
{
    ensure_main_categories_schema($pdo);
    $stmt = $pdo->prepare('SELECT id FROM main_categories WHERE id = :id AND is_active = 1');
    $stmt->execute([':id' => $mainCategoryId]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        fail('Main category not found.');
    }
    return $id;
}

/**
 * Fully remove a menu item and detach dependent rows.
 * Clears order_items first so FK constraints don't leave orphan catalog rows.
 */
function purge_menu_item(PDO $pdo, int $menuItemId): bool
{
    $menuItemId = (int)$menuItemId;
    if ($menuItemId <= 0) {
        return false;
    }

    try {
        $pdo->prepare(
            'UPDATE addons
                SET is_active = 0, menu_item_id = NULL
              WHERE menu_item_id = :mid'
        )->execute([':mid' => $menuItemId]);
    } catch (Throwable $e) {
        // Addons table may be missing.
    }

    try {
        $recipeIdsStmt = $pdo->prepare('SELECT id FROM recipes WHERE menu_item_id = :mid');
        $recipeIdsStmt->execute([':mid' => $menuItemId]);
        foreach ($recipeIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $recipeId) {
            $rid = (int)$recipeId;
            if ($rid <= 0) {
                continue;
            }
            try {
                $pdo->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = :rid')
                    ->execute([':rid' => $rid]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        $pdo->prepare('DELETE FROM recipes WHERE menu_item_id = :mid')
            ->execute([':mid' => $menuItemId]);
    } catch (Throwable $e) {
        // Recipes may be missing.
    }

    try {
        // Soft-delete finished-goods inventory that was tied to this menu SKU.
        // Nulling menu_item_id alone would make orphans show up as "registered" inventory.
        $pdo->prepare(
            'UPDATE inventory_items
                SET is_active = 0, menu_item_id = NULL
              WHERE menu_item_id = :mid'
        )->execute([':mid' => $menuItemId]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare('UPDATE inventory_items SET menu_item_id = NULL WHERE menu_item_id = :mid')
                ->execute([':mid' => $menuItemId]);
        } catch (Throwable $e2) {
            // ignore
        }
    }

    try {
        $pdo->prepare('UPDATE order_items SET menu_item_id = NULL WHERE menu_item_id = :mid')
            ->execute([':mid' => $menuItemId]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare('DELETE FROM order_items WHERE menu_item_id = :mid')
                ->execute([':mid' => $menuItemId]);
        } catch (Throwable $e2) {
            // ignore
        }
    }

    try {
        $pdo->prepare('DELETE FROM order_station_tickets WHERE menu_item_id = :mid')
            ->execute([':mid' => $menuItemId]);
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->prepare('DELETE FROM menu_items WHERE id = :mid')->execute([':mid' => $menuItemId]);
        return true;
    } catch (Throwable $e) {
        try {
            // Last resort: hide from every catalog query (active category join).
            $pdo->prepare(
                'UPDATE menu_items
                    SET is_available = 0, main_category_id = NULL, subcategory_id = NULL
                  WHERE id = :mid'
            )->execute([':mid' => $menuItemId]);
        } catch (Throwable $e2) {
            // ignore
        }
        return false;
    }
}

/**
 * Collect unique menu item ids for cascade deletes.
 *
 * @param list<int> $ids
 * @return list<int>
 */
function unique_positive_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $n = (int)$id;
        if ($n > 0) {
            $out[$n] = true;
        }
    }
    return array_map('intval', array_keys($out));
}

function main_category_name_is_valid(PDO $pdo, string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM main_categories
          WHERE LOWER(TRIM(name)) = LOWER(:name) AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    return (bool)$stmt->fetchColumn();
}

function cast_menu_item_row(array &$item): void
{
    $item['id'] = (int)$item['id'];
    $item['category_id'] = (int)$item['category_id'];
    $item['subcategory_id'] = isset($item['subcategory_id']) && $item['subcategory_id'] !== null
        ? (int)$item['subcategory_id']
        : null;
    if (array_key_exists('subcategory_name', $item)) {
        $item['subcategory_name'] = $item['subcategory_name'] !== null
            ? (string)$item['subcategory_name']
            : null;
    }
    $item['price'] = (float)$item['price'];
    if (array_key_exists('cost_price', $item)) {
        $item['cost_price'] = (float)$item['cost_price'];
    }
    $item['is_available'] = (bool)$item['is_available'];
    $item['serve_hot'] = (bool)($item['serve_hot'] ?? false);
    $item['serve_cold'] = (bool)($item['serve_cold'] ?? false);
    $item['main_category_id'] = isset($item['main_category_id']) && $item['main_category_id'] !== null
        ? (int)$item['main_category_id']
        : null;
    if (array_key_exists('main_category_name', $item)) {
        $item['main_category_name'] = $item['main_category_name'] !== null
            ? (string)$item['main_category_name']
            : null;
    }
    if (array_key_exists('is_addon_card', $item)) {
        $item['is_addon_card'] = !empty($item['is_addon_card']);
    }
}

/**
 * Parse "Variants:" line from menu item description.
 * Format: Variants: Hot (8oz) = 100.00; Iced (16oz) = 110.00 / cost 45.00
 *
 * @return array<int, array{size:string,label:string,price:float,cost?:float}>
 */
function parse_variants_from_description(?string $description): array
{
    $text = (string)$description;
    if ($text === '') {
        return [];
    }

    $line = null;
    foreach (preg_split('/\r\n|\r|\n/', $text) as $ln) {
        if (preg_match('/^\s*Variants\s*:/i', (string)$ln)) {
            $line = $ln;
            break;
        }
    }
    if ($line === null) {
        return [];
    }

    $payload = trim((string)preg_replace('/^\s*Variants\s*:/i', '', $line));
    if ($payload === '') {
        return [];
    }

    $out = [];
    foreach (explode(';', $payload) as $seg) {
        $s = trim((string)$seg);
        if ($s === '') {
            continue;
        }

        $lastEq = strrpos($s, '=');
        if ($lastEq === false) {
            continue;
        }

        $left = trim(substr($s, 0, $lastEq));
        $right = trim(substr($s, $lastEq + 1));
        $cost = null;
        if (preg_match('/^([\d.,]+)\s*(?:\/\s*cost\s*([\d.,]+))?$/i', $right, $rm)) {
            $price = (float)str_replace(',', '', $rm[1]);
            if (isset($rm[2]) && $rm[2] !== '') {
                $cost = round((float)str_replace(',', '', $rm[2]), 2);
            }
        } else {
            $price = (float)str_replace(',', '', $right);
        }
        if ($price < 0) {
            continue;
        }

        $firstParen = strpos($left, '(');
        if ($firstParen === false) {
            $row = ['size' => $left, 'label' => $left, 'price' => round($price, 2)];
            if ($cost !== null) {
                $row['cost'] = $cost;
            }
            $out[] = $row;
            continue;
        }

        $size = trim(substr($left, 0, $firstParen));
        $label = trim(substr($left, $firstParen + 1));
        if (str_ends_with($label, ')')) {
            $label = trim(substr($label, 0, -1));
        }

        $row = ['size' => $size, 'label' => $label, 'price' => round($price, 2)];
        if ($cost !== null) {
            $row['cost'] = $cost;
        }
        $out[] = $row;
    }

    return $out;
}

function resolve_variant_price_from_notes(float $basePrice, ?string $description, ?string $notes): float
{
    $variants = parse_variants_from_description($description);
    if (!$variants) {
        return round(max(0, $basePrice), 2);
    }

    $notesText = strtolower(trim((string)$notes));
    if ($notesText === '') {
        return round(max(0, $basePrice), 2);
    }

    foreach ($variants as $variant) {
        $size = strtolower(trim((string)($variant['size'] ?? '')));
        $label = strtolower(trim((string)($variant['label'] ?? '')));
        $needle = trim($size . ' ' . $label);
        if ($needle !== '' && strpos($notesText, $needle) !== false) {
            return (float)$variant['price'];
        }
        if ($size !== '' && strpos($notesText, $size) !== false) {
            if ($label === '' || strpos($notesText, $label) !== false) {
                return (float)$variant['price'];
            }
        }
    }

    return round(max(0, $basePrice), 2);
}

function variant_signature_from_parts(string $size, string $label): string
{
    $size = trim($size);
    $label = trim($label);
    if ($size !== '' && $label !== '' && strcasecmp($size, $label) !== 0) {
        return $size . ' (' . $label . ')';
    }
    return $size !== '' ? $size : $label;
}

function resolve_variant_signature_from_notes(?string $description, ?string $notes): string
{
    $variants = parse_variants_from_description($description);
    if (!$variants) {
        return '';
    }

    $notesText = strtolower(trim((string)$notes));
    if ($notesText === '') {
        return variant_signature_from_parts(
            (string)($variants[0]['size'] ?? ''),
            (string)($variants[0]['label'] ?? '')
        );
    }

    foreach ($variants as $variant) {
        $sig = variant_signature_from_parts(
            (string)($variant['size'] ?? ''),
            (string)($variant['label'] ?? '')
        );
        if ($sig !== '' && stripos((string)$notes, $sig) !== false) {
            return $sig;
        }

        $size = strtolower(trim((string)($variant['size'] ?? '')));
        $label = strtolower(trim((string)($variant['label'] ?? '')));
        $needle = trim($size . ' ' . $label);
        if ($needle !== '' && strpos($notesText, $needle) !== false) {
            return $sig;
        }
        if ($size !== '' && strpos($notesText, $size) !== false) {
            if ($label === '' || strpos($notesText, $label) !== false) {
                return $sig;
            }
        }
    }

    return '';
}

function resolve_menu_item_unit_price(array $menuRow, ?string $notes, ?float $clientUnitPrice = null): float
{
    $basePrice = round(max(0, (float)($menuRow['price'] ?? 0)), 2);
    $description = (string)($menuRow['description'] ?? '');
    $resolved = resolve_variant_price_from_notes($basePrice, $description, $notes);

    $allowed = [$basePrice];
    foreach (parse_variants_from_description($description) as $variant) {
        $allowed[] = (float)$variant['price'];
    }
    $maxAllowed = max($allowed);

    if ($clientUnitPrice !== null) {
        $clientUnitPrice = round(max(0, $clientUnitPrice), 2);
        // Variant selection + beverage add-ons can raise the unit price above the variant alone.
        if ($clientUnitPrice + 0.009 >= $resolved && $clientUnitPrice <= $maxAllowed + 150.01) {
            return $clientUnitPrice;
        }
    }

    return $resolved;
}

/**
 * Fast-moving menu items from recent processed orders (confirmed + served).
 * Same basis as admin dashboard "Top selling" (last N orders).
 *
 * @return list<array{menu_item_id:int,name:string,category:string,qty_sold:int,revenue:float,rank:int}>
 */
function fetch_fast_moving_items(PDO $pdo, int $orderLimit = 100, int $itemLimit = 5): array
{
    $orderLimit = max(1, min(500, $orderLimit));
    $itemLimit = max(1, min(20, $itemLimit));

    $processedIds = $pdo->query(
        'SELECT id FROM orders WHERE status IN ("confirmed","served") ORDER BY created_at DESC LIMIT ' . (int)$orderLimit
    )->fetchAll(PDO::FETCH_COLUMN);

    $processedIds = array_values(array_filter(array_map('intval', $processedIds ?: [])));
    if (!$processedIds) {
        return [];
    }

    $inList = implode(',', $processedIds);
    $stmt = $pdo->query(
        "SELECT mi.id AS menu_item_id,
                mi.name,
                COALESCE(MAX(c.name), 'Uncategorized') AS category,
                SUM(oi.quantity) AS qty_sold,
                SUM(oi.subtotal) AS revenue
           FROM order_items oi
           JOIN menu_items mi ON mi.id = oi.menu_item_id
           LEFT JOIN categories c ON c.id = mi.category_id
           JOIN orders o ON o.id = oi.order_id
          WHERE o.id IN ($inList)
          GROUP BY mi.id, mi.name
          ORDER BY qty_sold DESC
          LIMIT " . (int)$itemLimit
    );
    $rows = $stmt->fetchAll();
    $out = [];
    $rank = 1;
    foreach ($rows as $row) {
        $out[] = [
            'menu_item_id' => (int)$row['menu_item_id'],
            'name' => (string)$row['name'],
            'category' => (string)$row['category'],
            'qty_sold' => (int)$row['qty_sold'],
            'revenue' => (float)$row['revenue'],
            'rank' => $rank++,
        ];
    }
    return $out;
}

function fetch_fast_moving_item_ids(PDO $pdo, int $orderLimit = 100, int $itemLimit = 5): array
{
    $ids = [];
    foreach (fetch_fast_moving_items($pdo, $orderLimit, $itemLimit) as $row) {
        $ids[] = (int)$row['menu_item_id'];
    }
    return $ids;
}

/** Overall #1 seller — same window as the dashboard Most Selling chart. */
function fetch_best_seller(PDO $pdo, int $orderLimit = 100): ?array
{
    $items = fetch_fast_moving_items($pdo, $orderLimit, 1);
    return $items[0] ?? null;
}
