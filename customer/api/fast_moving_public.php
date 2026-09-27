<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';
require_once __DIR__ . '/../../adminStaff/api/menu_helpers.php';

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$orderLimit = isset($_GET['order_limit']) ? (int)$_GET['order_limit'] : 100;
$itemLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

$items = fetch_fast_moving_items(db(), $orderLimit, $itemLimit);
$bestSeller = fetch_best_seller(db(), $orderLimit);

ok([
    'items' => $items,
    'menu_item_ids' => array_map(static function ($row) {
        return (int)$row['menu_item_id'];
    }, $items),
    'best_seller' => $bestSeller,
    'best_seller_menu_item_id' => $bestSeller ? (int)$bestSeller['menu_item_id'] : 0,
    'order_limit' => max(1, min(500, $orderLimit)),
    'item_limit' => max(1, min(20, $itemLimit)),
]);
