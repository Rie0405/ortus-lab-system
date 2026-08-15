<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recipe_helpers.php';
require_once __DIR__ . '/forecast_helpers.php';

require_auth();

$method = method();

if ($method === 'POST') {
    $data = body();
    $forecast = compute_item_forecast([
        'item_id' => $data['item_id'] ?? null,
        'item_name' => $data['item_name'] ?? ($data['name'] ?? null),
        'qty_sold_window' => $data['qty_sold_window'] ?? ($data['quantity_sold'] ?? 0),
        'ma_days' => $data['ma_days'] ?? 2,
        'lead_time_days' => $data['lead_time_days'] ?? 2,
        'current_stock' => $data['current_stock'] ?? 0,
        'max_capacity' => array_key_exists('max_capacity', $data) ? $data['max_capacity'] : null,
        'orders_per_stock_item' => $data['orders_per_stock_item'] ?? ($data['orders_per_box'] ?? null),
        'sealed_stock_units' => $data['sealed_stock_units'] ?? ($data['stock_units'] ?? null),
        'orders_left_open' => $data['orders_left_open'] ?? 0,
        'sealed_max_units' => $data['sealed_max_units'] ?? null,
        'stock_in_order_slots' => !empty($data['stock_in_order_slots']),
        'alert_percent' => $data['alert_percent'] ?? 50,
        'last_supply_date' => $data['last_supply_date'] ?? null,
        'as_of_date' => $data['as_of_date'] ?? null,
    ]);
    ok([
        'mode' => 'sandbox',
        'forecast' => $forecast,
    ]);
}

if ($method !== 'GET') {
    fail('Method not allowed.', 405);
}

$maDays = forecast_clamp_days($_GET['ma_days'] ?? 2, 2);
$leadTimeDays = forecast_clamp_days($_GET['lead_time_days'] ?? 2, 2);
$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;
if ($itemId !== null && $itemId <= 0) {
    $itemId = null;
}
$asOf = isset($_GET['as_of_date']) ? trim((string)$_GET['as_of_date']) : null;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : null;

$pdo = db();
$payload = build_live_inventory_forecasts($pdo, $maDays, $leadTimeDays, $itemId, $asOf);

if ($limit !== null) {
    $payload['items'] = array_slice($payload['items'], 0, $limit);
    $payload['top_usage'] = array_slice($payload['top_usage'], 0, min(5, $limit));
    $payload['priority_restock'] = array_slice($payload['priority_restock'], 0, min(5, $limit));
}

ok([
    'mode' => 'live',
    'params' => $payload['params'],
    'alert_percent' => $payload['alert_percent'],
    'summary' => $payload['summary'],
    'items' => $payload['items'],
    'top_usage' => $payload['top_usage'],
    'priority_restock' => $payload['priority_restock'],
]);
