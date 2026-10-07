<?php

require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/recipe_helpers.php';

function ensure_order_items_cost_schema(PDO $pdo): void
{
    $columns = [
        'unit_cost' => 'ALTER TABLE order_items ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal',
        'line_cost' => 'ALTER TABLE order_items ADD COLUMN line_cost DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_cost',
    ];

    foreach ($columns as $column => $sql) {
        $quotedColumn = $pdo->quote($column);
        $stmt = $pdo->query("SHOW COLUMNS FROM order_items LIKE {$quotedColumn}");
        if ($stmt && $stmt->fetch()) {
            continue;
        }
        $pdo->exec($sql);
    }
}

/**
 * Orders that still count as sales for reporting.
 * Kitchen-returned pending orders stay as sales until refunded/voided.
 */
function sql_order_counts_as_sale(string $alias = ''): string
{
    $p = $alias === '' ? '' : (rtrim($alias, '.') . '.');
    return '(' . $p . 'status IN ("confirmed","served")'
        . ' OR (' . $p . 'status = "pending" AND COALESCE(' . $p . 'kitchen_returned, 0) = 1))';
}

/**
 * Cashflow summary for a date range.
 * COGS comes from recipe unit_cost snapshots on order_items (saved at checkout).
 * Optional $onlyDates limits the summary to specific YYYY-MM-DD values inside the range.
 */
function fetch_cashflow_summary(PDO $pdo, string $fromDate, string $toDate, ?array $onlyDates = null): array
{
    ensure_order_discount_schema($pdo);
    ensure_order_items_cost_schema($pdo);
    ensure_recipe_schema_shared($pdo);

    $dates = normalize_cashflow_dates($onlyDates);
    $saleCond = sql_order_counts_as_sale();
    $params = [];
    $dateSql = cashflow_date_sql('created_at', $dates, $fromDate, $toDate, $params);

    $salesStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN ' . $saleCond . ' THEN total_amount ELSE 0 END), 0) AS collected_sales,
            COALESCE(SUM(CASE WHEN ' . $saleCond . ' THEN discount_amount ELSE 0 END), 0) AS discounts_total,
            COALESCE(SUM(CASE WHEN status = "voided" THEN total_amount ELSE 0 END), 0) AS refunds_total,
            COUNT(CASE WHEN ' . $saleCond . ' THEN 1 END) AS total_orders,
            COALESCE(SUM(CASE WHEN ' . $saleCond . ' THEN gross_amount ELSE 0 END), 0) AS gross_revenue
         FROM orders
         WHERE ' . $dateSql
    );
    $salesStmt->execute($params);
    $sales = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $cogsSaleCond = sql_order_counts_as_sale('o');
    $cogsParams = [];
    $cogsDateSql = cashflow_date_sql('o.created_at', $dates, $fromDate, $toDate, $cogsParams);
    $cogsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(
            CASE
                WHEN oi.line_cost > 0 THEN oi.line_cost
                ELSE oi.quantity * oi.unit_cost
            END
         ), 0) AS total_cogs
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE ' . $cogsDateSql . '
           AND ' . $cogsSaleCond
    );
    $cogsStmt->execute($cogsParams);
    $totalCogs = (float)$cogsStmt->fetchColumn();

    $collectedSales = round((float)($sales['collected_sales'] ?? 0), 2);
    $discountsTotal = round((float)($sales['discounts_total'] ?? 0), 2);
    $refundsTotal = round((float)($sales['refunds_total'] ?? 0), 2);
    $grossRevenue = round((float)($sales['gross_revenue'] ?? 0), 2);
    // Net Sales = Gross Sales − Discounts − Refunds (matches dashboard KPI copy).
    $netSales = round($grossRevenue - $discountsTotal - $refundsTotal, 2);
    $totalOrders = (int)($sales['total_orders'] ?? 0);
    $totalCogs = round($totalCogs, 2);
    $grossProfit = round($netSales - $totalCogs, 2);
    $avgOrder = $totalOrders > 0 ? round($netSales / $totalOrders, 2) : 0.0;

    $priceTotals = fetch_menu_price_totals($pdo, $fromDate, $toDate, $dates);
    $totalSellingPrice = (float)$priceTotals['total_selling_price'];
    $totalCostPrice = (float)$priceTotals['total_cost_price'];
    $netCashFlow = round($totalSellingPrice - $totalCostPrice, 2);

    return [
        'net_sales' => $netSales,
        'collected_sales' => $collectedSales,
        'discounts_total' => $discountsTotal,
        'refunds_total' => $refundsTotal,
        'total_cogs' => $totalCogs,
        'gross_profit' => $grossProfit,
        'gross_revenue' => $grossRevenue,
        'total_orders' => $totalOrders,
        'avg_order_value' => $avgOrder,
        'total_selling_price' => $totalSellingPrice,
        'total_cost_price' => $totalCostPrice,
        'net_cash_flow' => $netCashFlow,
        // Backward-compatible aliases used by older dashboard JS.
        'total_revenue' => $netSales,
        'total_sales' => $netSales,
        'raw_total_revenue' => $netSales,
        'adjusted_revenue' => $netSales,
    ];
}

/**
 * @param list<string>|null $onlyDates
 * @return list<string>
 */
function normalize_cashflow_dates(?array $onlyDates): array
{
    if (!$onlyDates) {
        return [];
    }
    $out = [];
    foreach ($onlyDates as $d) {
        $d = trim((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $out[$d] = true;
        }
    }
    $keys = array_keys($out);
    sort($keys);
    return $keys;
}

/**
 * Build a DATE(...) filter using either an explicit date list or a from/to range.
 *
 * @param list<string> $dates
 * @param array<string,string> $params
 */
function cashflow_date_sql(string $columnExpr, array $dates, string $fromDate, string $toDate, array &$params): string
{
    $params = [];
    if ($dates) {
        $placeholders = [];
        foreach ($dates as $i => $d) {
            $key = ':cd' . $i;
            $placeholders[] = $key;
            $params[$key] = $d;
        }
        return 'DATE(' . $columnExpr . ') IN (' . implode(',', $placeholders) . ')';
    }
    $params[':from'] = $fromDate;
    $params[':to'] = $toDate;
    return 'DATE(' . $columnExpr . ') BETWEEN :from AND :to';
}

/**
 * Weekly net cash flow basis: sum(selling unit price × qty) − sum(menu cost_price × qty).
 *
 * @param list<string>|null $onlyDates
 */
function fetch_menu_price_totals(PDO $pdo, string $fromDate, string $toDate, ?array $onlyDates = null): array
{
    if (function_exists('ensure_menu_cost_price_schema')) {
        ensure_menu_cost_price_schema($pdo);
    } else {
        try {
            $hasCost = (bool)$pdo->query("SHOW COLUMNS FROM menu_items LIKE 'cost_price'")->fetch();
            if (!$hasCost) {
                $pdo->exec(
                    'ALTER TABLE menu_items
                     ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER price'
                );
            }
        } catch (Throwable $e) {
            // Non-fatal if schema race.
        }
    }

    $dates = normalize_cashflow_dates($onlyDates);
    $saleCond = sql_order_counts_as_sale('o');
    $params = [];
    $dateSql = cashflow_date_sql('o.created_at', $dates, $fromDate, $toDate, $params);
    $stmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS total_selling_price,
            COALESCE(SUM(COALESCE(m.cost_price, 0) * oi.quantity), 0) AS total_cost_price
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         LEFT JOIN menu_items m ON m.id = oi.menu_item_id
         WHERE ' . $dateSql . '
           AND ' . $saleCond
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_selling_price' => round((float)($row['total_selling_price'] ?? 0), 2),
        'total_cost_price' => round((float)($row['total_cost_price'] ?? 0), 2),
    ];
}
