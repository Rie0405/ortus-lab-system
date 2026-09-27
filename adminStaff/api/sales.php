<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/cashflow_helpers.php';
require_auth(); // temporary: allow authenticated staff/admin

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$pdo = db();
ensure_order_discount_schema($pdo);
$today = date('Y-m-d');

function receipt_expense_between(PDO $pdo, string $fromDate, string $toDate, ?array $onlyDates = null): float {
    static $hasReceiptsTable = null;
    if ($hasReceiptsTable === null) {
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'Receipts'");
            $hasReceiptsTable = (bool)$chk->fetchColumn();
        } catch (Throwable $e) {
            $hasReceiptsTable = false;
        }
    }
    if (!$hasReceiptsTable) {
        return 0.0;
    }
    try {
        $dates = normalize_cashflow_dates($onlyDates);
        $params = [];
        $dateSql = cashflow_date_sql('`Date`', $dates, $fromDate, $toDate, $params);
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(TotalAmount), 0)
             FROM Receipts
             WHERE ' . $dateSql
        );
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

$limit  = min(max((int)($_GET['limit'] ?? 50), 1), 200);
$offset = max((int)($_GET['offset'] ?? 0), 0);

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$fromDate = date('Y-m-d', strtotime($from));
$toDate   = date('Y-m-d', strtotime($to));

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$selectedDates = [];
$datesRaw = trim((string)($_GET['dates'] ?? ''));
if ($datesRaw !== '') {
    foreach (explode(',', $datesRaw) as $part) {
        $part = trim($part);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $part)) {
            $selectedDates[$part] = true;
        }
    }
    $selectedDates = array_keys($selectedDates);
    sort($selectedDates);
    if ($selectedDates) {
        $fromDate = $selectedDates[0];
        $toDate = $selectedDates[count($selectedDates) - 1];
    }
}

$periodDays = max(1, count($selectedDates) ?: ((int)floor((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1));
$prevTo = date('Y-m-d', strtotime($fromDate . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($periodDays - 1) . ' day'));

$saleCond = sql_order_counts_as_sale();
$orderDateParams = [];
$orderDateSql = cashflow_date_sql('created_at', $selectedDates, $fromDate, $toDate, $orderDateParams);
$summaryStmt = $pdo->prepare(
    'SELECT COUNT(*) AS total_orders
     FROM orders
     WHERE ' . $orderDateSql . '
       AND ' . $saleCond
);
$summaryStmt->execute($orderDateParams);
$orderCountRow = $summaryStmt->fetch();
$cashflow = fetch_cashflow_summary($pdo, $fromDate, $toDate, $selectedDates ?: null);
$totalOrders = (int)($orderCountRow['total_orders'] ?? $cashflow['total_orders']);
$totalRevenue = (float)$cashflow['net_sales'];
$grossRevenue = (float)$cashflow['gross_revenue'];
$discountsTotal = (float)$cashflow['discounts_total'];
$refundsTotal = (float)$cashflow['refunds_total'];
$totalCogs = (float)$cashflow['total_cogs'];
$grossProfit = (float)$cashflow['gross_profit'];
$receiptExpenseTotal = receipt_expense_between($pdo, $fromDate, $toDate, $selectedDates ?: null);
$adjustedRevenue = $totalRevenue - $receiptExpenseTotal;
$totalSellingPrice = (float)($cashflow['total_selling_price'] ?? $grossRevenue);
$totalCostPrice = (float)($cashflow['total_cost_price'] ?? 0);
$netCashFlow = round((float)($cashflow['net_cash_flow'] ?? ($totalSellingPrice - $totalCostPrice)), 2);
$avgOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

$wasteParams = [];
$wasteDateSql = cashflow_date_sql('logged_at', $selectedDates, $fromDate, $toDate, $wasteParams);
$wasteStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(estimated_value), 0) AS waste_total
     FROM waste_log
     WHERE ' . $wasteDateSql
);
$wasteStmt->execute($wasteParams);
$wasteTotal = (float)$wasteStmt->fetchColumn();
$otherExpensesTotal = max(0, (float)($_GET['other_expenses_total'] ?? 0));
$netEstimate = $grossProfit - ($wasteTotal + $otherExpensesTotal + $receiptExpenseTotal);

$prevCashflow = fetch_cashflow_summary($pdo, $prevFrom, $prevTo);
$prevRevenue = (float)$prevCashflow['net_sales'];
$prevReceiptExpenseTotal = receipt_expense_between($pdo, $prevFrom, $prevTo);
$prevAdjustedRevenue = $prevRevenue - $prevReceiptExpenseTotal;

$pct = function (float $current, float $previous): ?float {
    if ($previous <= 0) return null;
    return round((($current - $previous) / $previous) * 100, 1);
};

$countParams = [];
$countDateSql = cashflow_date_sql('created_at', $selectedDates, $fromDate, $toDate, $countParams);
$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM orders
     WHERE ' . $countDateSql
);
$countStmt->execute($countParams);
$resultsTotal = (int)$countStmt->fetchColumn();

$listParams = [];
$listDateSql = cashflow_date_sql('o.created_at', $selectedDates, $fromDate, $toDate, $listParams);
$listStmt = $pdo->prepare(
    'SELECT
        o.id,
        o.order_number,
        o.created_at,
        o.gross_amount,
        o.discount_amount,
        o.discount_type,
        o.total_amount,
        o.payment_method,
        o.gcash_ref,
        o.status,
        COALESCE(u.full_name, "Unknown") AS staff_name,
        COALESCE(
            GROUP_CONCAT(CONCAT(oi.quantity, "x ", mi.name) ORDER BY oi.id SEPARATOR ", "),
            "No items"
        ) AS items_sold
     FROM orders o
     LEFT JOIN users u ON u.id = o.staff_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
     WHERE ' . $listDateSql . '
     GROUP BY o.id
     ORDER BY o.created_at DESC
     LIMIT :lim OFFSET :off'
);
foreach ($listParams as $key => $value) {
    $listStmt->bindValue($key, $value);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$orders = $listStmt->fetchAll();

foreach ($orders as &$order) {
    $order['id'] = (int)$order['id'];
    $order['gross_amount'] = (float)$order['gross_amount'];
    $order['discount_amount'] = (float)$order['discount_amount'];
    $order['total_amount'] = (float)$order['total_amount'];
}
unset($order);

ok([
    'from'           => $fromDate,
    'to'             => $toDate,
    'dates'          => $selectedDates,
    'today'          => $today,
    'summary'        => [
        'net_sales'         => $totalRevenue,
        'total_sales'       => $totalRevenue,
        'raw_total_revenue' => $totalRevenue,
        'adjusted_revenue'  => $adjustedRevenue,
        'total_revenue'     => $totalRevenue,
        'gross_revenue'     => $grossRevenue,
        'discounts_total'   => $discountsTotal,
        'refunds_total'     => $refundsTotal,
        'total_cogs'        => $totalCogs,
        'receipt_expense_total' => $receiptExpenseTotal,
        'other_expenses_total' => $otherExpensesTotal,
        'gross_profit'      => $grossProfit,
        'total_selling_price' => $totalSellingPrice,
        'total_cost_price'  => $totalCostPrice,
        'net_cash_flow'     => $netCashFlow,
        'total_orders'      => $totalOrders,
        'avg_order_value'   => $avgOrder,
        'net_profit_estimate' => $netEstimate,
        'waste_total'       => $wasteTotal,
    ],
    'pct'            => [
        'revenue'     => $pct($adjustedRevenue, $prevAdjustedRevenue),
        'orders'      => $pct((float)$totalOrders, (float)$prevCashflow['total_orders']),
        'avg_order'   => $pct($avgOrder, ((int)$prevCashflow['total_orders'] > 0 ? ($prevAdjustedRevenue / (int)$prevCashflow['total_orders']) : 0)),
        'net_profit'  => null,
    ],
    'orders'         => $orders,
    'results_total'  => $resultsTotal,
    'returned_count' => count($orders),
]);
