<?php

function ensure_order_discount_schema(PDO $pdo): void
{
    $columns = [
        'discount_type' => "ALTER TABLE orders ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'none' AFTER order_type",
        'discount_customer_name' => "ALTER TABLE orders ADD COLUMN discount_customer_name VARCHAR(160) NULL DEFAULT NULL AFTER discount_type",
        'discount_id_number' => "ALTER TABLE orders ADD COLUMN discount_id_number VARCHAR(80) NULL DEFAULT NULL AFTER discount_customer_name",
        'gross_amount' => "ALTER TABLE orders ADD COLUMN gross_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_id_number",
        'vat_exempt_amount' => "ALTER TABLE orders ADD COLUMN vat_exempt_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER gross_amount",
        'discount_amount' => "ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER vat_exempt_amount",
        'discount_rate' => "ALTER TABLE orders ADD COLUMN discount_rate DECIMAL(5,2) NULL DEFAULT NULL AFTER discount_amount",
    ];

    foreach ($columns as $column => $sql) {
        $quotedColumn = $pdo->quote($column);
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE {$quotedColumn}");
        if ($stmt && $stmt->fetch()) {
            continue;
        }
        $pdo->exec($sql);
    }
}

function normalize_order_discount_payload($raw): array
{
    $discount = is_array($raw) ? $raw : [];
    $type = strtolower(trim((string)($discount['type'] ?? 'none')));
    if (!in_array($type, ['none', 'senior', 'pwd', 'custom'], true)) {
        $type = 'none';
    }
    $rate = round(max(0, (float)($discount['rate'] ?? 0)), 2);
    if ($type === 'custom' && ($rate <= 0 || $rate > 100)) {
        $type = 'none';
        $rate = 0.0;
    }

    return [
        'type' => $type,
        'rate' => $rate,
        'customer_name' => trim((string)($discount['customer_name'] ?? '')),
        'id_number' => trim((string)($discount['id_number'] ?? '')),
    ];
}

function validate_order_discount_payload(array $discount): void
{
    $type = $discount['type'] ?? 'none';
    if ($type === 'none') {
        return;
    }

    if ($type === 'custom') {
        $rate = (float)($discount['rate'] ?? 0);
        if ($rate <= 0 || $rate > 100) {
            fail('Discount rate must be between 1 and 100.');
        }
        return;
    }

    if (($discount['customer_name'] ?? '') === '') {
        fail('Discount customer name is required.');
    }
    if (($discount['id_number'] ?? '') === '') {
        fail('Discount ID number is required.');
    }
}

function calculate_order_discount_breakdown(float $grossAmount, array $discount): array
{
    $grossAmount = round(max(0, $grossAmount), 2);
    $type = $discount['type'] ?? 'none';
    if ($type === 'none' || $grossAmount <= 0) {
        return [
            'discount_type' => 'none',
            'gross_amount' => $grossAmount,
            'vat_exempt_amount' => 0.0,
            'discount_amount' => 0.0,
            'total_amount' => $grossAmount,
        ];
    }

    if ($type === 'custom') {
        $rate = max(0, min(100, (float)($discount['rate'] ?? 0)));
        $discountAmount = round($grossAmount * ($rate / 100), 2);
        $netAmount = round($grossAmount - $discountAmount, 2);

        return [
            'discount_type' => 'custom',
            'gross_amount' => $grossAmount,
            'vat_exempt_amount' => 0.0,
            'discount_amount' => $discountAmount,
            'total_amount' => $netAmount,
        ];
    }

    $vatExemptBase = $grossAmount / 1.12;
    $vatExemptAmount = $grossAmount - $vatExemptBase;
    $seniorPwdDiscount = $vatExemptBase * 0.20;
    $netAmount = $vatExemptBase - $seniorPwdDiscount;

    return [
        'discount_type' => $type,
        'gross_amount' => round($grossAmount, 2),
        'vat_exempt_amount' => round($vatExemptAmount, 2),
        'discount_amount' => round($vatExemptAmount + $seniorPwdDiscount, 2),
        'total_amount' => round($netAmount, 2),
    ];
}
