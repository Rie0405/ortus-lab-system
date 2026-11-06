<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gcash_receipt_helpers.php';

require_auth();

$pdo = db();
ensure_gcash_receipt_schema($pdo);

$m = method();

if ($m === 'GET') {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT id, order_number, customer_name, total_amount, gcash_ref, gcash_receipt_url,
                gcash_receipt_seen_at, created_at
         FROM orders
         WHERE order_source = \'kiosk\'
           AND payment_method = \'gcash\'
           AND gcash_receipt_url IS NOT NULL
           AND gcash_receipt_url != \'\'
           AND DATE(created_at) = :today
         ORDER BY created_at DESC'
    );
    $stmt->execute([':today' => $today]);
    $rows = $stmt->fetchAll() ?: [];
    $receipts = [];
    $unread = 0;
    foreach ($rows as $row) {
        $seen = !empty($row['gcash_receipt_seen_at']);
        if (!$seen) {
            $unread += 1;
        }
        $receipts[] = [
            'id' => (int) $row['id'],
            'order_number' => (string) $row['order_number'],
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'total_amount' => (float) $row['total_amount'],
            'gcash_ref' => (string) ($row['gcash_ref'] ?? ''),
            'gcash_receipt_url' => (string) $row['gcash_receipt_url'],
            'seen' => $seen,
            'seen_at' => $row['gcash_receipt_seen_at'],
            'created_at' => $row['created_at'],
        ];
    }
    ok([
        'receipts' => $receipts,
        'count' => count($receipts),
        'unread' => $unread,
        'today' => $today,
    ]);
}

if ($m === 'POST' || $m === 'PUT') {
    $b = body();
    $orderId = (int) ($b['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        fail('Order id is required.');
    }
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET gcash_receipt_seen_at = NOW()
         WHERE id = :id
           AND order_source = \'kiosk\'
           AND payment_method = \'gcash\'
           AND gcash_receipt_url IS NOT NULL
           AND gcash_receipt_url != \'\''
    );
    $stmt->execute([':id' => $orderId]);
    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare(
            'SELECT id, gcash_receipt_seen_at FROM orders
             WHERE id = :id AND order_source = \'kiosk\' AND payment_method = \'gcash\'
             LIMIT 1'
        );
        $check->execute([':id' => $orderId]);
        $row = $check->fetch();
        if (!$row) {
            fail('Receipt order not found.', 404);
        }
    }
    ok(['order_id' => $orderId, 'seen' => true]);
}

fail('Method not allowed.', 405);
