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
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_addon_name_station (main_category_id, name),
            KEY idx_addons_active (is_active, display_order),
            KEY idx_addons_main_category (main_category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function fetch_addons_rows(PDO $pdo, ?int $mainCategoryId = null, bool $activeOnly = true): array
{
    ensure_addons_schema($pdo);
    $sql = 'SELECT a.id, a.name, a.price, a.main_category_id, a.display_order, a.is_active,
                   mc.name AS station_name
              FROM addons a
              JOIN main_categories mc ON mc.id = a.main_category_id
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
        $row['display_order'] = (int)$row['display_order'];
        $row['is_active'] = !empty($row['is_active']) ? 1 : 0;
        $row['station'] = strtolower(trim((string)($row['station_name'] ?? ''))) === 'bar' ? 'bar' : 'kitchen';
    }
    unset($row);
    return $rows;
}
