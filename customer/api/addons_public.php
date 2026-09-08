<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';
require_once __DIR__ . '/../../adminStaff/api/addons_helpers.php';

$m = method();
if ($m !== 'GET') {
    fail('Method not allowed.', 405);
}

$station = strtolower(trim((string)($_GET['station'] ?? '')));
$mainCategoryId = isset($_GET['main_category_id']) ? (int)$_GET['main_category_id'] : null;

if ($station === 'bar' || $station === 'kitchen') {
    ensure_main_categories_schema(db());
    $stmt = db()->prepare(
        'SELECT id FROM main_categories
          WHERE LOWER(TRIM(name)) = :name AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':name' => $station]);
    $resolved = (int)$stmt->fetchColumn();
    if ($resolved > 0) {
        $mainCategoryId = $resolved;
    }
}

ok([
    'addons' => fetch_addons_rows(db(), $mainCategoryId, true),
]);
