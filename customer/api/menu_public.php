<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';
require_once __DIR__ . '/../../adminStaff/api/menu_helpers.php';

ensure_menu_serve_schema(db());
ensure_subcategories_schema(db());

$m = method();
if ($m !== 'GET') fail('Method not allowed.', 405);

$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

$cats = db()->query(
    'SELECT id, name, display_order
       FROM categories
      WHERE is_active = 1
      ORDER BY display_order'
)->fetchAll();

$sql = 'SELECT m.id, m.category_id, m.subcategory_id, sc.name AS subcategory_name,
               c.name AS category_name,
               m.name, m.description, m.price, m.image_url, m.is_available,
               m.serve_hot, m.serve_cold
          FROM menu_items m
          JOIN categories c ON c.id = m.category_id
          LEFT JOIN subcategories sc ON sc.id = m.subcategory_id
         WHERE c.is_active = 1';
$params = [];
if ($categoryId) {
    $sql .= ' AND m.category_id = :cid';
    $params[':cid'] = $categoryId;
}
$sql .= ' ORDER BY c.display_order, m.name';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

foreach ($items as &$item) {
    cast_menu_item_row($item);
}
unset($item);

$fastMoving = fetch_fast_moving_items(db(), 100, 5);

ok([
    'categories' => $cats,
    'items' => $items,
    'fast_moving' => $fastMoving,
    'fast_moving_item_ids' => array_map(static function ($row) {
        return (int)$row['menu_item_id'];
    }, $fastMoving),
]);

