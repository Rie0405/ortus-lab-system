<?php

/**
 * Shared inventory forecast math (moving-average demand + lead-time ROP).
 */

function forecast_clamp_days($value, int $default = 2): int
{
    $n = (int)$value;
    if ($n < 1) {
        return $default;
    }
    return min(30, $n);
}

/**
 * @param array{
 *   qty_sold_window?:float|int,
 *   ma_days?:int,
 *   lead_time_days?:int,
 *   current_stock?:float|int,
 *   max_capacity?:float|int|null,
 *   alert_percent?:int,
 *   last_supply_date?:string|null,
 *   as_of_date?:string|null,
 *   item_id?:int|null,
 *   item_name?:string|null,
 *   orders_per_stock_item?:int|float|null,
 *   orders_per_box?:int|float|null,
 *   sealed_stock_units?:float|int|null,
 *   stock_units?:float|int|null,
 *   orders_left_open?:float|int|null,
 *   sealed_max_units?:float|int|null,
 *   stock_in_order_slots?:bool
 * } $input
 * @return array<string,mixed>
 */
function compute_item_forecast(array $input): array
{
    $maDays = forecast_clamp_days($input['ma_days'] ?? 2, 2);
    $leadTimeDays = forecast_clamp_days($input['lead_time_days'] ?? 2, 2);
    $qtySoldWindow = max(0.0, (float)($input['qty_sold_window'] ?? 0));

    $ordersPerStock = $input['orders_per_stock_item'] ?? ($input['orders_per_box'] ?? null);
    $ordersPerStock = ($ordersPerStock === null || $ordersPerStock === '')
        ? 0
        : max(0, (int)$ordersPerStock);

    $sealedUnitsRaw = $input['sealed_stock_units'] ?? ($input['stock_units'] ?? null);
    $hasSealedUnits = !($sealedUnitsRaw === null || $sealedUnitsRaw === '');
    $sealedUnits = $hasSealedUnits ? max(0.0, (float)$sealedUnitsRaw) : null;

    $ordersLeftOpen = max(0.0, (float)($input['orders_left_open'] ?? 0));
    $stockAlreadyOrderSlots = !empty($input['stock_in_order_slots']);

    // Prefer inventory-style conversion: order slots = open remainder + sealed × orders/stock item.
    $currentStock = (float)($input['current_stock'] ?? 0);
    if (!$stockAlreadyOrderSlots && $ordersPerStock > 0 && $hasSealedUnits) {
        $currentStock = $ordersLeftOpen + ($sealedUnits * $ordersPerStock);
    }

    $maxCapacityRaw = $input['max_capacity'] ?? null;
    $maxCapacity = ($maxCapacityRaw === null || $maxCapacityRaw === '')
        ? null
        : max(0.0, (float)$maxCapacityRaw);

    $sealedMaxRaw = $input['sealed_max_units'] ?? null;
    if ($ordersPerStock > 0 && !($sealedMaxRaw === null || $sealedMaxRaw === '')) {
        $maxCapacity = max(0.0, (float)$sealedMaxRaw) * $ordersPerStock;
    } elseif ($ordersPerStock > 0 && $hasSealedUnits && ($maxCapacity === null || $maxCapacity <= 0)) {
        // Fallback capacity from current sealed structure (same idea as inventory max capacity).
        $openCount = $ordersLeftOpen > 0 ? 1 : 0;
        $maxCapacity = ($sealedUnits + $openCount) * $ordersPerStock;
    }

    $alertPercent = (int)($input['alert_percent'] ?? 50);
    if (!in_array($alertPercent, [75, 50, 25], true)) {
        $alertPercent = 50;
    }
    $asOf = trim((string)($input['as_of_date'] ?? ''));
    if ($asOf === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
        $asOf = date('Y-m-d');
    }
    $lastSupply = trim((string)($input['last_supply_date'] ?? ''));
    if ($lastSupply !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastSupply)) {
        $lastSupply = '';
    }

    $avgDailyUsage = $maDays > 0 ? ($qtySoldWindow / $maDays) : 0.0;
    $hasCapacity = $maxCapacity !== null && $maxCapacity > 0;
    $alertThreshold = $hasCapacity ? ($maxCapacity * ($alertPercent / 100.0)) : null;

    $reorderPoint = $avgDailyUsage * $leadTimeDays;
    $daysRemaining = null;
    if ($avgDailyUsage > 0) {
        $daysRemaining = $currentStock / $avgDailyUsage;
    }

    $suggestedRestock = max(0.0, $reorderPoint - max(0.0, $currentStock));
    if ($hasCapacity) {
        $roomToFill = max(0.0, $maxCapacity - max(0.0, $currentStock));
        // Prefer at least covering ROP; never suggest more than remaining capacity.
        $suggestedRestock = min(max($suggestedRestock, 0.0), $roomToFill);
        // If already above ROP but below alert threshold, suggest topping up to alert line as a soft floor.
        if ($suggestedRestock <= 0 && $alertThreshold !== null && $currentStock < $alertThreshold) {
            $suggestedRestock = min(max(0.0, $alertThreshold - $currentStock), $roomToFill);
        }
    }

    $suggestedRestockSealed = null;
    if ($ordersPerStock > 0) {
        $suggestedRestockSealed = (int)ceil($suggestedRestock / $ordersPerStock);
    }

    $urgency = 'OK';
    $why = 'Stock covers demand beyond lead time at current moving-average usage.';

    if ($currentStock <= 0) {
        $urgency = 'OUT';
        $why = 'This item looks empty — restock it now.';
    } elseif ($avgDailyUsage <= 0) {
        $urgency = 'NO_SIGNAL';
        $why = 'No recent sales/usage in the last ' . $maDays
            . ' day(s), so we cannot estimate how long the stock will last yet.';
    } elseif ($daysRemaining !== null && $daysRemaining <= $leadTimeDays) {
        $urgency = 'CRITICAL';
        $why = 'Stock may only last about ' . round($daysRemaining, 2)
            . ' day(s), which is shorter than the ' . $leadTimeDays . '-day supplier wait.';
    } elseif ($alertThreshold !== null && $currentStock <= $alertThreshold) {
        $urgency = 'LOW';
        $why = 'Stock is at or below the ' . $alertPercent
            . '% low-stock line (' . round($alertThreshold, 2) . ').';
    } else {
        $why = 'Stock looks fine for now based on recent usage and lead time.';
    }

    $restockByDate = null;
    if ($daysRemaining !== null && $avgDailyUsage > 0) {
        $bufferDays = $daysRemaining - $leadTimeDays;
        if ($bufferDays <= 0) {
            $restockByDate = $asOf;
        } else {
            $restockByDate = date('Y-m-d', strtotime($asOf . ' +' . (int)floor($bufferDays) . ' days'));
        }
    }

    return [
        'item_id' => isset($input['item_id']) ? (int)$input['item_id'] : null,
        'item_name' => isset($input['item_name']) ? (string)$input['item_name'] : null,
        'ma_days' => $maDays,
        'lead_time_days' => $leadTimeDays,
        'qty_sold_window' => round($qtySoldWindow, 4),
        'avg_daily_usage' => round($avgDailyUsage, 4),
        'current_stock' => round($currentStock, 4),
        'max_capacity' => $hasCapacity ? round($maxCapacity, 4) : null,
        'orders_per_stock_item' => $ordersPerStock > 0 ? $ordersPerStock : null,
        'sealed_stock_units' => $hasSealedUnits ? round((float)$sealedUnits, 4) : null,
        'orders_left_open' => $ordersLeftOpen > 0 ? round($ordersLeftOpen, 4) : 0.0,
        'alert_percent' => $alertPercent,
        'alert_threshold' => $alertThreshold !== null ? round($alertThreshold, 4) : null,
        'reorder_point' => round($reorderPoint, 4),
        'days_remaining' => $daysRemaining !== null ? round($daysRemaining, 4) : null,
        'suggested_restock' => round($suggestedRestock, 4),
        'suggested_restock_sealed' => $suggestedRestockSealed,
        'urgency' => $urgency,
        'restock_by_date' => $restockByDate,
        'last_supply_date' => $lastSupply !== '' ? $lastSupply : null,
        'as_of_date' => $asOf,
        'why' => $why,
        'unit_basis' => $ordersPerStock > 0 ? 'order_slots' : 'stock_units',
    ];
}

/**
 * Resolve low-stock alert percent from inventory_settings (75/50/25).
 */
function forecast_get_alert_percent(PDO $pdo): int
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_settings (
                id INT PRIMARY KEY,
                low_stock_threshold INT NOT NULL DEFAULT 10,
                low_stock_fraction_den INT NOT NULL DEFAULT 2
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $val = $pdo->query('SELECT low_stock_fraction_den FROM inventory_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        $percent = (int)$val;
        if (in_array($percent, [75, 50, 25], true)) {
            return $percent;
        }
        $legacyMap = [2 => 50, 3 => 30, 4 => 25, 5 => 10, 30 => 25, 20 => 25, 10 => 25];
        return $legacyMap[$percent] ?? 50;
    } catch (Throwable $e) {
        return 50;
    }
}

/**
 * Aggregate recipe-based usage per inventory item over the last $maDays calendar days
 * ending on $asOfDate (inclusive).
 *
 * @return array<int,float> inventory_item_id => qty_used
 */
function forecast_usage_by_inventory_id(PDO $pdo, int $maDays, string $asOfDate): array
{
    ensure_recipe_schema_shared($pdo);
    $maDays = forecast_clamp_days($maDays, 2);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
        $asOfDate = date('Y-m-d');
    }
    $fromDate = date('Y-m-d', strtotime($asOfDate . ' -' . ($maDays - 1) . ' days'));

    $orderStmt = $pdo->prepare(
        'SELECT id
         FROM orders
         WHERE status IN ("confirmed","served")
           AND DATE(created_at) BETWEEN :from_d AND :to_d'
    );
    $orderStmt->execute([
        ':from_d' => $fromDate,
        ':to_d' => $asOfDate,
    ]);

    $usageByInvId = [];
    foreach ($orderStmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        aggregate_recipe_usage_for_order($pdo, (int)$orderId, $usageByInvId);
    }

    $out = [];
    foreach ($usageByInvId as $invId => $totals) {
        $out[(int)$invId] = (float)($totals['qty_used'] ?? 0);
    }
    return $out;
}

/**
 * Latest supply date per inventory item (matched by item name + category / line type).
 *
 * @return array<int,string> inventory_item_id => Y-m-d
 */
function forecast_last_supply_dates(PDO $pdo, array $items): array
{
    if ($items === []) {
        return [];
    }

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'Receipts'")->fetchColumn();
        $existsLines = $pdo->query("SHOW TABLES LIKE 'ReceiptLines'")->fetchColumn();
        if (!$exists || !$existsLines) {
            return [];
        }
    } catch (Throwable $e) {
        return [];
    }

    $byExact = [];
    $idsByName = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = strtolower(trim((string)($item['item_name'] ?? $item['name'] ?? '')));
        $cat = strtolower(trim((string)($item['category_name'] ?? '')));
        if ($name === '') {
            continue;
        }
        $byExact[$name . "\0" . $cat] = $id;
        if (!isset($idsByName[$name])) {
            $idsByName[$name] = [];
        }
        $idsByName[$name][] = $id;
    }
    if ($byExact === []) {
        return [];
    }

    $sql = 'SELECT rl.ItemName, rl.LineType,
                   MAX(COALESCE(r.ExpectedReceiveDate, r.OrderedDate, r.`Date`)) AS last_supply
            FROM ReceiptLines rl
            INNER JOIN Receipts r ON r.ReceiptID = rl.ReceiptID
            GROUP BY rl.ItemName, rl.LineType';
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $name = strtolower(trim((string)($row['ItemName'] ?? '')));
        $cat = strtolower(trim((string)($row['LineType'] ?? '')));
        $date = trim((string)($row['last_supply'] ?? ''));
        if ($name === '' || $date === '') {
            continue;
        }
        $date = substr($date, 0, 10);
        $exactKey = $name . "\0" . $cat;
        if (isset($byExact[$exactKey])) {
            $invId = $byExact[$exactKey];
            if (!isset($out[$invId]) || $date > $out[$invId]) {
                $out[$invId] = $date;
            }
            continue;
        }
        $nameIds = $idsByName[$name] ?? [];
        if (count($nameIds) === 1) {
            $invId = $nameIds[0];
            if (!isset($out[$invId]) || $date > $out[$invId]) {
                $out[$invId] = $date;
            }
        }
    }
    return $out;
}

/**
 * Build live forecast rows for active inventory SKUs (menu_item_id IS NULL).
 *
 * @return array{params:array,alert_percent:int,items:list<array>,summary:array}
 */
function build_live_inventory_forecasts(
    PDO $pdo,
    int $maDays,
    int $leadTimeDays,
    ?int $itemId = null,
    ?string $asOfDate = null
): array {
    ensure_inventory_items_base_schema($pdo);
    ensure_recipe_schema_shared($pdo);

    $maDays = forecast_clamp_days($maDays, 2);
    $leadTimeDays = forecast_clamp_days($leadTimeDays, 2);
    $asOf = trim((string)$asOfDate);
    if ($asOf === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
        $asOf = date('Y-m-d');
    }

    $alertPercent = forecast_get_alert_percent($pdo);
    $usageById = forecast_usage_by_inventory_id($pdo, $maDays, $asOf);

    $sql = 'SELECT *
            FROM inventory_items
            WHERE is_active = 1
              AND menu_item_id IS NULL';
    $params = [];
    if ($itemId !== null && $itemId > 0) {
        $sql .= ' AND id = :id';
        $params[':id'] = $itemId;
    }
    $sql .= ' ORDER BY item_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lastSupply = forecast_last_supply_dates($pdo, $rows);
    $items = [];
    $critical = 0;
    $low = 0;
    $out = 0;
    $ok = 0;
    $noSignal = 0;
    $daysSamples = [];

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $currentStock = total_available_orders_for_inventory_row($row);
        $maxCapacity = max_capacity_for_inventory_row($row);
        $ordersPerStock = max(0, (int)($row['orders_per_box'] ?? 0));
        $forecast = compute_item_forecast([
            'item_id' => $id,
            'item_name' => (string)$row['item_name'],
            'qty_sold_window' => $usageById[$id] ?? 0,
            'ma_days' => $maDays,
            'lead_time_days' => $leadTimeDays,
            'current_stock' => $currentStock,
            'max_capacity' => $maxCapacity,
            'orders_per_stock_item' => $ordersPerStock > 0 ? $ordersPerStock : null,
            'sealed_stock_units' => (float)($row['stock_units'] ?? 0),
            'orders_left_open' => (float)($row['units_in_use'] ?? 0),
            'stock_in_order_slots' => true,
            'alert_percent' => $alertPercent,
            'last_supply_date' => $lastSupply[$id] ?? null,
            'as_of_date' => $asOf,
        ]);
        if ($ordersPerStock > 0) {
            $forecast['orders_per_stock_item'] = $ordersPerStock;
            $forecast['sealed_stock_units'] = round((float)($row['stock_units'] ?? 0), 4);
            $forecast['suggested_restock_sealed'] = (int)ceil(((float)$forecast['suggested_restock']) / $ordersPerStock);
            $forecast['unit_basis'] = 'order_slots';
        }
        $forecast['entry_mode'] = normalize_inventory_entry_mode($row['entry_mode'] ?? 'automatic');
        $forecast['stock_status'] = normalize_inventory_stock_status($row['stock_status'] ?? 'good');

        // Manual checklist items: staff Good/Low/Critical overrides qty-based OUT/urgency.
        if (inventory_is_manual_entry($row)) {
            $manual = $forecast['stock_status'];
            if ($manual === 'good') {
                $forecast['urgency'] = 'OK';
                $forecast['why'] = 'Marked Good on the manual checklist — treating this as in stock.';
                $forecast['suggested_restock'] = 0.0;
                $forecast['suggested_restock_sealed'] = 0;
            } elseif ($manual === 'low') {
                $forecast['urgency'] = 'LOW';
                $forecast['why'] = 'Marked Low on the manual checklist — plan a restock soon.';
            } else {
                $forecast['urgency'] = 'CRITICAL';
                $forecast['why'] = 'Marked Critical on the manual checklist — restock this now.';
            }
        }

        $forecast['category_name'] = (string)($row['category_name'] ?? '');
        $forecast['supplier'] = (string)($row['supplier'] ?? '');
        $items[] = $forecast;

        switch ($forecast['urgency']) {
            case 'CRITICAL':
                $critical++;
                break;
            case 'LOW':
                $low++;
                break;
            case 'OUT':
                $out++;
                break;
            case 'NO_SIGNAL':
                $noSignal++;
                break;
            default:
                $ok++;
                break;
        }
        if ($forecast['days_remaining'] !== null) {
            $daysSamples[] = (float)$forecast['days_remaining'];
        }
    }

    sort($daysSamples);
    $medianDays = null;
    $n = count($daysSamples);
    if ($n > 0) {
        $mid = intdiv($n, 2);
        $medianDays = ($n % 2 === 1)
            ? $daysSamples[$mid]
            : (($daysSamples[$mid - 1] + $daysSamples[$mid]) / 2);
        $medianDays = round($medianDays, 4);
    }

    // Highest ADU for sales suggestion; lowest days / worst urgency for inventory.
    $byAdu = $items;
    usort($byAdu, static function ($a, $b) {
        return ($b['avg_daily_usage'] <=> $a['avg_daily_usage']);
    });
    $byRisk = $items;
    usort($byRisk, static function ($a, $b) {
        $rank = ['OUT' => 0, 'CRITICAL' => 1, 'LOW' => 2, 'NO_SIGNAL' => 3, 'OK' => 4];
        $ra = $rank[$a['urgency']] ?? 9;
        $rb = $rank[$b['urgency']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $da = $a['days_remaining'];
        $db = $b['days_remaining'];
        if ($da === null && $db === null) {
            return 0;
        }
        if ($da === null) {
            return 1;
        }
        if ($db === null) {
            return -1;
        }
        return $da <=> $db;
    });

    return [
        'params' => [
            'ma_days' => $maDays,
            'lead_time_days' => $leadTimeDays,
            'as_of_date' => $asOf,
        ],
        'alert_percent' => $alertPercent,
        'items' => $items,
        'top_usage' => array_slice($byAdu, 0, 5),
        'priority_restock' => array_slice($byRisk, 0, 5),
        'summary' => [
            'item_count' => count($items),
            'critical_count' => $critical,
            'low_count' => $low,
            'out_count' => $out,
            'ok_count' => $ok,
            'no_signal_count' => $noSignal,
            'median_days_remaining' => $medianDays,
        ],
    ];
}
