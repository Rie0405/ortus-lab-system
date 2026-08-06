<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';
require_once __DIR__ . '/../../adminStaff/api/receipt_helpers.php';

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '' || strlen($token) > 64 || !preg_match('/^[a-f0-9]+$/i', $token)) {
    fail('Invalid receipt link.', 400);
}

$pdo = db();
ensure_receipt_token_schema($pdo);

$stmt = $pdo->prepare(
    'SELECT o.id, o.order_number, o.order_source, o.status, o.payment_method, o.order_type,
            o.discount_type, o.discount_customer_name, o.discount_id_number,
            o.gross_amount, o.vat_exempt_amount, o.discount_amount, o.total_amount,
            o.amount_received, o.change_due, o.created_at
       FROM orders o
      WHERE o.receipt_token = :tok
      LIMIT 1'
);
$stmt->execute([':tok' => $token]);
$order = $stmt->fetch();

if (!$order) {
    fail('Receipt not found.', 404);
}

if (in_array((string)($order['status'] ?? ''), ['voided'], true)) {
    fail('This receipt is no longer available.', 410);
}

$itemsStmt = $pdo->prepare(
    'SELECT mi.name, oi.quantity, oi.unit_price, oi.subtotal, oi.notes,
            COALESCE(c.name, "") AS category_name
       FROM order_items oi
       JOIN menu_items mi ON mi.id = oi.menu_item_id
       LEFT JOIN categories c ON c.id = mi.category_id
      WHERE oi.order_id = :oid
      ORDER BY oi.id ASC'
);
$itemsStmt->execute([':oid' => (int)$order['id']]);
$items = $itemsStmt->fetchAll();

foreach ($items as &$item) {
    $item['quantity'] = (int)$item['quantity'];
    $item['unit_price'] = (float)$item['unit_price'];
    $item['subtotal'] = (float)$item['subtotal'];
}
unset($item);

ok([
    'receipt' => [
        'order_number' => (string)$order['order_number'],
        'order_source' => (string)$order['order_source'],
        'status' => (string)$order['status'],
        'payment_method' => (string)$order['payment_method'],
        'order_type' => (string)($order['order_type'] ?? ''),
        'discount_type' => (string)($order['discount_type'] ?? 'none'),
        'discount_customer_name' => (string)($order['discount_customer_name'] ?? ''),
        'discount_id_number' => (string)($order['discount_id_number'] ?? ''),
        'gross_amount' => (float)$order['gross_amount'],
        'vat_exempt_amount' => (float)$order['vat_exempt_amount'],
        'discount_amount' => (float)$order['discount_amount'],
        'total_amount' => (float)$order['total_amount'],
        'amount_received' => $order['amount_received'] !== null ? (float)$order['amount_received'] : null,
        'change_due' => $order['change_due'] !== null ? (float)$order['change_due'] : null,
        'created_at' => (string)$order['created_at'],
        'items' => $items,
    ],
]);
