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

/** Normalize discount ID for comparison (case/space insensitive). */
function discount_id_key(string $id): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($id)) ?? '');
}

/**
 * Extract usable PWD/Senior ID keys from a discount payload (single or lines).
 *
 * @return list<string> normalized keys
 */
function collect_discount_id_keys_from_payload(array $discount, array $discountLines = []): array
{
    $keys = [];
    if ($discountLines) {
        foreach ($discountLines as $line) {
            $type = strtolower(trim((string)($line['type'] ?? 'none')));
            if ($type !== 'pwd' && $type !== 'senior') {
                continue;
            }
            $key = discount_id_key((string)($line['id_number'] ?? ''));
            if ($key !== '' && !is_placeholder_discount_id($key)) {
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }

    $type = strtolower(trim((string)($discount['type'] ?? 'none')));
    if ($type === 'pwd' || $type === 'senior') {
        $key = discount_id_key((string)($discount['id_number'] ?? ''));
        if ($key !== '' && !is_placeholder_discount_id($key)) {
            $keys[$key] = true;
        }
    }
    return array_keys($keys);
}

/**
 * Find if a discount ID was already used on a confirmed order today.
 * @return array{order_id:int,order_number:string}|null
 */
function find_discount_id_used_today(PDO $pdo, string $idKey, ?int $excludeOrderId = null): ?array
{
    $idKey = discount_id_key($idKey);
    if ($idKey === '' || is_placeholder_discount_id($idKey)) {
        return null;
    }

    ensure_order_discount_schema($pdo);
    if (!function_exists('sql_order_counts_as_sale')) {
        require_once __DIR__ . '/cashflow_helpers.php';
    }

    $saleCond = sql_order_counts_as_sale('o');
    $sql = 'SELECT o.id, o.order_number, o.discount_id_number
            FROM orders o
            WHERE DATE(o.created_at) = CURDATE()
              AND ' . $saleCond . '
              AND o.discount_type IN (\'pwd\', \'senior\')
              AND COALESCE(o.discount_amount, 0) > 0
              AND COALESCE(o.discount_requested, 0) = 0
              AND o.discount_id_number IS NOT NULL
              AND TRIM(o.discount_id_number) <> \'\'';
    $params = [];
    if ($excludeOrderId !== null && $excludeOrderId > 0) {
        $sql .= ' AND o.id <> :exclude_id';
        $params[':exclude_id'] = $excludeOrderId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $parts = preg_split('/\s*\|\s*/', (string)($row['discount_id_number'] ?? '')) ?: [];
        foreach ($parts as $part) {
            if (discount_id_key($part) === $idKey) {
                return [
                    'order_id' => (int)$row['id'],
                    'order_number' => (string)$row['order_number'],
                ];
            }
        }
    }
    return null;
}

/**
 * Fail if any ID was already used today (one-time use per calendar day).
 *
 * @param list<string> $idNumbers
 */
function assert_discount_ids_unused_today(PDO $pdo, array $idNumbers, ?int $excludeOrderId = null): void
{
    $seen = [];
    foreach ($idNumbers as $raw) {
        $key = discount_id_key((string)$raw);
        if ($key === '' || is_placeholder_discount_id($key)) {
            continue;
        }
        if (isset($seen[$key])) {
            fail('This discount ID can only be used once per day.');
        }
        $seen[$key] = true;
        $used = find_discount_id_used_today($pdo, $key, $excludeOrderId);
        if ($used) {
            $orderNo = trim((string)($used['order_number'] ?? ''));
            if ($orderNo !== '') {
                fail('This discount ID was already used today on order ' . $orderNo . '.');
            }
            fail('This discount ID was already used today.');
        }
    }
}

/** Placeholder IDs used before staff confirms a real PWD/SC ID. */
function is_placeholder_discount_id(string $id): bool
{
    $key = strtoupper(trim($id));
    if ($key === '') {
        return true;
    }
    $blocked = [
        'KIOSK-REQUEST',
        'KIOSK_REQUEST',
        'PENDING',
        'REQUEST',
        'N/A',
        'NA',
        'NONE',
        'TBD',
    ];
    return in_array($key, $blocked, true);
}

/**
 * Confirmed PWD/Senior discount credentials (real ID after staff apply).
 *
 * @param list<string> $selectedDates
 * @return list<array<string,mixed>>
 */
function fetch_confirmed_discount_records(
    PDO $pdo,
    string $fromDate,
    string $toDate,
    array $selectedDates = [],
    int $limit = 500
): array {
    ensure_order_discount_schema($pdo);
    $limit = max(1, min(1000, $limit));

    if (!function_exists('cashflow_date_sql') || !function_exists('sql_order_counts_as_sale')) {
        require_once __DIR__ . '/cashflow_helpers.php';
    }

    $params = [];
    $dateSql = cashflow_date_sql('o.created_at', $selectedDates, $fromDate, $toDate, $params);
    $saleCond = sql_order_counts_as_sale('o');

    $stmt = $pdo->prepare(
        'SELECT
            o.id,
            o.order_number,
            o.created_at,
            o.discount_type,
            o.discount_customer_name,
            o.discount_id_number,
            o.gross_amount,
            o.discount_amount,
            o.total_amount,
            o.discount_rate,
            o.status,
            COALESCE(u.full_name, "Unknown") AS staff_name
         FROM orders o
         LEFT JOIN users u ON u.id = o.staff_id
         WHERE ' . $dateSql . '
           AND ' . $saleCond . '
           AND o.discount_type IN (\'pwd\', \'senior\')
           AND COALESCE(o.discount_amount, 0) > 0
           AND COALESCE(o.discount_requested, 0) = 0
           AND o.discount_id_number IS NOT NULL
           AND TRIM(o.discount_id_number) <> \'\'
           AND UPPER(TRIM(o.discount_id_number)) NOT IN (
                \'KIOSK-REQUEST\', \'KIOSK_REQUEST\', \'PENDING\', \'REQUEST\', \'N/A\', \'NA\', \'NONE\', \'TBD\'
           )
         ORDER BY o.created_at DESC
         LIMIT ' . (int)$limit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $records = [];
    foreach ($rows as $row) {
        $type = strtolower(trim((string)($row['discount_type'] ?? '')));
        $names = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*\|\s*/', (string)($row['discount_customer_name'] ?? ''))
        )));
        $ids = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*\|\s*/', (string)($row['discount_id_number'] ?? ''))
        )));
        if (!$ids) {
            continue;
        }

        $gross = (float)($row['gross_amount'] ?? 0);
        $discountAmt = (float)($row['discount_amount'] ?? 0);
        $net = (float)($row['total_amount'] ?? max(0, $gross - $discountAmt));
        $count = max(count($ids), count($names) ?: 1);

        for ($i = 0; $i < $count; $i++) {
            $idNo = $ids[$i] ?? ($ids[0] ?? '');
            if (is_placeholder_discount_id($idNo)) {
                continue;
            }
            $name = $names[$i] ?? ($names[0] ?? '');
            if ($name === '') {
                continue;
            }
            $records[] = [
                'order_id' => (int)$row['id'],
                'order_number' => (string)$row['order_number'],
                'created_at' => (string)$row['created_at'],
                'discount_type' => $type,
                'discount_label' => $type === 'pwd' ? 'PWD' : 'Senior Citizen',
                'customer_name' => $name,
                'id_number' => $idNo,
                'gross_amount' => $gross,
                'discount_amount' => $discountAmt,
                'net_amount' => $net,
                'discount_rate' => $row['discount_rate'] !== null ? (float)$row['discount_rate'] : 20.0,
                'staff_name' => (string)$row['staff_name'],
                'status' => (string)$row['status'],
            ];
        }
    }

    return $records;
}
