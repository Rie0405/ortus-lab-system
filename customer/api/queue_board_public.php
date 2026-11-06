<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

function is_pickup_order_type(string $orderType): bool
{
    $t = strtolower(trim($orderType));
    $t = str_replace(['-', '_'], ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t) ?: '';
    return $t === 'pickup' || $t === 'pick up';
}

$pdo = db();
$stmt = $pdo->query(
    "SELECT order_number, customer_name, order_type, status, kitchen_returned
       FROM orders
      WHERE order_source = 'kiosk'
        AND (
              (status = 'pending' AND COALESCE(kitchen_returned, 0) = 0)
           OR status = 'confirmed'
           OR (status = 'served' AND updated_at >= DATE_SUB(NOW(), INTERVAL 20 MINUTE))
            )
      ORDER BY created_at ASC, id ASC
      LIMIT 200"
);
$rows = $stmt ? $stmt->fetchAll() : [];

$pending = [];
$preparing = [];
$ready = [];

foreach ($rows as $row) {
    if (is_pickup_order_type((string)($row['order_type'] ?? ''))) {
        continue;
    }
    $number = trim((string)($row['order_number'] ?? ''));
    if ($number === '') {
        continue;
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $name = trim((string)($row['customer_name'] ?? ''));
    $ticket = [
        'order_number' => $number,
        'customer_name' => $name !== '' ? $name : 'Guest',
    ];
    if ($status === 'pending') {
        $pending[] = $ticket;
        continue;
    }
    if ($status === 'confirmed') {
        $preparing[] = $ticket;
        continue;
    }
    if ($status === 'served') {
        $ready[] = $ticket;
    }
}

ok([
    'pending' => $pending,
    'preparing' => $preparing,
    'ready' => $ready,
]);
