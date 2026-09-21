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

    $reqCols = [
        'discount_requested' => "ALTER TABLE orders ADD COLUMN discount_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_rate",
        'discount_request_type' => "ALTER TABLE orders ADD COLUMN discount_request_type VARCHAR(20) NULL DEFAULT NULL AFTER discount_requested",
    ];
    foreach ($reqCols as $column => $sql) {
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

/**
 * Normalize optional per-line discounts from POS (multiple cards on one order sheet).
 * Each non-empty discount ID may appear only once.
 *
 * @return list<array{type:string,rate:float,customer_name:string,id_number:string,unit_price:float}>
 */
function normalize_order_discount_lines($rawDiscount): array
{
    if (!is_array($rawDiscount) || empty($rawDiscount['lines']) || !is_array($rawDiscount['lines'])) {
        return [];
    }

    $lines = [];
    $seenIds = [];
    foreach ($rawDiscount['lines'] as $row) {
        if (!is_array($row)) continue;
        $entry = normalize_order_discount_payload($row);
        if (($entry['type'] ?? 'none') === 'none') continue;
        $unit = round(max(0, (float)($row['unit_price'] ?? 0)), 2);
        if ($unit <= 0) continue;
        validate_order_discount_payload($entry);

        $idKey = strtoupper(preg_replace('/\s+/', '', (string)($entry['id_number'] ?? '')));
        if ($idKey !== '') {
            if (isset($seenIds[$idKey])) {
                fail('The same discount ID cannot be used twice on one order.');
            }
            $seenIds[$idKey] = true;
        }

        $lines[] = [
            'type' => $entry['type'],
            'rate' => (float)$entry['rate'],
            'customer_name' => $entry['customer_name'],
            'id_number' => $entry['id_number'],
            'unit_price' => $unit,
        ];
    }

    return $lines;
}

/**
 * Sum plain % discounts across multiple selected unit prices (1 serving each).
 */
function calculate_order_discount_lines_breakdown(float $grossAmount, array $lines): array
{
    $grossAmount = round(max(0, $grossAmount), 2);
    if ($grossAmount <= 0 || !$lines) {
        return [
            'discount_type' => 'none',
            'gross_amount' => $grossAmount,
            'vat_exempt_amount' => 0.0,
            'discount_amount' => 0.0,
            'total_amount' => $grossAmount,
            'discountable_amount' => 0.0,
            'discount_rate' => null,
            'discount_customer_name' => null,
            'discount_id_number' => null,
        ];
    }

    $discountAmount = 0.0;
    $eligible = 0.0;
    $types = [];
    $names = [];
    $ids = [];
    $rates = [];

    foreach ($lines as $line) {
        $unit = round(max(0, (float)($line['unit_price'] ?? 0)), 2);
        if ($unit <= 0) continue;
        $type = $line['type'] ?? 'none';
        if ($type === 'custom') {
            $rate = max(0, min(100, (float)($line['rate'] ?? 0)));
            $discountAmount += round($unit * ($rate / 100), 2);
            $rates[] = $rate;
        } else {
            $discountAmount += round($unit * 0.20, 2);
            $rates[] = 20.0;
        }
        $eligible += $unit;
        $types[$type] = true;
        if (!empty($line['customer_name'])) $names[] = (string)$line['customer_name'];
        if (!empty($line['id_number'])) $ids[] = (string)$line['id_number'];
    }

    $discountAmount = round(min($grossAmount, $discountAmount), 2);
    $eligible = round(min($grossAmount, $eligible), 2);
    $typeKeys = array_keys($types);
    $summaryType = count($typeKeys) === 1 ? $typeKeys[0] : (count($typeKeys) > 1 ? 'custom' : 'none');
    $summaryRate = null;
    if ($summaryType === 'custom') {
        $summaryRate = count(array_unique($rates)) === 1 ? (float)$rates[0] : null;
    }

    $nameJoined = $names ? substr(implode(' | ', array_unique($names)), 0, 160) : null;
    $idJoined = $ids ? substr(implode(' | ', array_unique($ids)), 0, 80) : null;

    return [
        'discount_type' => $summaryType,
        'gross_amount' => $grossAmount,
        'vat_exempt_amount' => 0.0,
        'discount_amount' => $discountAmount,
        'total_amount' => round(max(0, $grossAmount - $discountAmount), 2),
        'discountable_amount' => $eligible,
        'discount_rate' => $summaryRate,
        'discount_customer_name' => $nameJoined,
        'discount_id_number' => $idJoined,
    ];
}

function calculate_order_discount_breakdown(float $grossAmount, array $discount, ?float $discountableAmount = null): array
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
            'discountable_amount' => 0.0,
        ];
    }

    // Only the highest-priced single serving is discountable; other items stay full price.
    if ($discountableAmount === null) {
        $eligible = $grossAmount;
    } else {
        $eligible = round(max(0, min($grossAmount, $discountableAmount)), 2);
    }
    $remainder = round(max(0, $grossAmount - $eligible), 2);

    if ($eligible <= 0) {
        return [
            'discount_type' => 'none',
            'gross_amount' => $grossAmount,
            'vat_exempt_amount' => 0.0,
            'discount_amount' => 0.0,
            'total_amount' => $grossAmount,
            'discountable_amount' => 0.0,
        ];
    }

    if ($type === 'custom') {
        $rate = max(0, min(100, (float)($discount['rate'] ?? 0)));
        $discountAmount = round($eligible * ($rate / 100), 2);
        $netEligible = round($eligible - $discountAmount, 2);

        return [
            'discount_type' => 'custom',
            'gross_amount' => $grossAmount,
            'vat_exempt_amount' => 0.0,
            'discount_amount' => $discountAmount,
            'total_amount' => round($remainder + $netEligible, 2),
            'discountable_amount' => $eligible,
        ];
    }

    // Senior/PWD: plain 20% off eligible amount (no VAT strip).
    $discountAmount = round($eligible * 0.20, 2);
    $netEligible = round($eligible - $discountAmount, 2);

    return [
        'discount_type' => $type,
        'gross_amount' => $grossAmount,
        'vat_exempt_amount' => 0.0,
        'discount_amount' => $discountAmount,
        'total_amount' => round($remainder + $netEligible, 2),
        'discountable_amount' => $eligible,
    ];
}

/**
 * Highest unit price among order lines (one serving of the most expensive item).
 *
 * @param list<array{0:int,1:int,2:float,3:float,4?:?string}|array{unit_price?:float|int|string}> $itemRows
 */
function max_discountable_unit_price_from_rows(array $itemRows): float
{
    $max = 0.0;
    foreach ($itemRows as $row) {
        if (is_array($row) && array_key_exists(2, $row)) {
            $up = (float)$row[2];
        } else {
            $up = (float)($row['unit_price'] ?? 0);
        }
        if ($up > $max) {
            $max = $up;
        }
    }
    return round($max, 2);
}
