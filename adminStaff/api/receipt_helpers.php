<?php

function ensure_receipt_token_schema(PDO $pdo): void
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'receipt_token'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(
        'ALTER TABLE orders
         ADD COLUMN receipt_token VARCHAR(64) NULL DEFAULT NULL AFTER order_number,
         ADD UNIQUE INDEX idx_orders_receipt_token (receipt_token)'
    );
}

function generate_receipt_token(): string
{
    return bin2hex(random_bytes(16));
}

function assign_receipt_token(PDO $pdo, int $orderId, ?string $token = null): string
{
    ensure_receipt_token_schema($pdo);
    $token = $token ?: generate_receipt_token();
    $pdo->prepare('UPDATE orders SET receipt_token = :tok WHERE id = :id')
        ->execute([':tok' => $token, ':id' => $orderId]);
    return $token;
}
