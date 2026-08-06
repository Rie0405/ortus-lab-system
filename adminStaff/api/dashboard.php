<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/cashflow_helpers.php';
// Admin dashboard UI is also opened by staff in some setups; payload is operational stats only
// (same class of data as orders.php / waste.php, which already allow staff).
require_auth();

function receipt_expense_between(PDO $pdo, string $fromDate, string $toDate): float {
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
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(TotalAmount), 0)
             FROM Receipts
             WHERE `Date` BETWEEN :from AND :to'
        );
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$monthStart = date('Y-m-01');
$pdo       = db();
ensure_order_discount_schema($pdo);

// ─── Today totals ─────────────────────────────────────────────────────────────
$todayStmt = $pdo->prepare(
    'SELECT
        COUNT(*)                                                         AS total_orders,
        COALESCE(SUM(total_amount), 0)                                   AS total_revenue,
        COALESCE(SUM(gross_amount), 0)                                   AS gross_revenue,
        COALESCE(SUM(discount_amount), 0)                                AS discounts_total,
        COALESCE(SUM(CASE WHEN payment_method = "gcash" THEN total_amount END), 0) AS digital_revenue,
        COALESCE(SUM(CASE WHEN payment_method = "cash"  THEN total_amount END), 0) AS cash_revenue,
        COUNT(CASE WHEN status IN ("confirmed","served") THEN 1 END)     AS confirmed_count,
        COUNT(CASE WHEN status = "voided"  THEN 1 END)                   AS voided_count
       FROM orders
      WHERE DATE(created_at) = :d'
);
$todayStmt->execute([':d' => $today]);
$todayData = $todayStmt->fetch();
$todayRawRevenue = (float)($todayData['total_revenue'] ?? 0);
$todayReceiptExpense = receipt_expense_between($pdo, $today, $today);
$todayData['raw_total_revenue'] = $todayRawRevenue;
$todayData['receipt_expense_total'] = $todayReceiptExpense;
$todayData['total_revenue'] = $todayRawRevenue - $todayReceiptExpense;

// ─── Yesterday totals (for % change) ─────────────────────────────────────────
$yestStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) AS total_revenue,
            COALESCE(SUM(discount_amount), 0) AS discounts_total,
            COUNT(*) AS total_orders
       FROM orders
      WHERE DATE(created_at) = :d
        AND status IN ("confirmed","served")'
);
$yestStmt->execute([':d' => $yesterday]);
$yestData = $yestStmt->fetch();
$yestRawRevenue = (float)($yestData['total_revenue'] ?? 0);
$yestReceiptExpense = receipt_expense_between($pdo, $yesterday, $yesterday);
$yestData['raw_total_revenue'] = $yestRawRevenue;
$yestData['receipt_expense_total'] = $yestReceiptExpense;
$yestData['total_revenue'] = $yestRawRevenue - $yestReceiptExpense;

// ─── Waste total today ────────────────────────────────────────────────────────
$wasteStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(estimated_value), 0) AS waste_total,
            COUNT(*) AS waste_count
       FROM waste_log
      WHERE DATE(logged_at) = :d'
);
$wasteStmt->execute([':d' => $today]);
$wasteData = $wasteStmt->fetch();

// ─── Recent confirmed orders (last 10) ───────────────────────────────────────
$recentStmt = $pdo->prepare(
    'SELECT o.order_number, o.total_amount, o.gross_amount, o.discount_amount, o.discount_type, o.payment_method, o.status,
            o.created_at, u.full_name AS staff_name
       FROM orders o
  LEFT JOIN users u ON u.id = o.staff_id
      WHERE o.status IN ("confirmed","served")
      ORDER BY o.created_at DESC
      LIMIT 10'
);
$recentStmt->execute();
$recentOrders = $recentStmt->fetchAll();

// Stock movement should align with current sales flow: processed = confirmed + served.
$processedIds = $pdo->query(
    'SELECT id FROM orders WHERE status IN ("confirmed","served") ORDER BY created_at DESC LIMIT 100'
)->fetchAll(PDO::FETCH_COLUMN);
$processedIds = array_values(array_filter(array_map('intval', $processedIds ?: [])));

$topItems   = [];
$catRevenue = [];
if ($processedIds) {
    $inList = implode(',', $processedIds);
    $topStmt = $pdo->query(
        "SELECT mi.name,
                COALESCE(MAX(c.name), 'Uncategorized') AS category,
                SUM(oi.quantity) AS qty_sold,
                SUM(oi.subtotal) AS revenue
           FROM order_items oi
           JOIN menu_items mi ON mi.id = oi.menu_item_id
           LEFT JOIN categories c ON c.id = mi.category_id
           JOIN orders o ON o.id = oi.order_id
          WHERE o.id IN ($inList)
          GROUP BY mi.id, mi.name
          ORDER BY qty_sold DESC"
    );
    $topItems = $topStmt->fetchAll();

    $catRevStmt = $pdo->query(
        "SELECT c.name AS category, COALESCE(SUM(oi.subtotal), 0) AS revenue
           FROM order_items oi
           JOIN menu_items mi ON mi.id = oi.menu_item_id
           JOIN categories c ON c.id = mi.category_id
           JOIN orders o ON o.id = oi.order_id
          WHERE o.id IN ($inList)
          GROUP BY c.id
          ORDER BY revenue DESC"
    );
    $catRevenue = $catRevStmt->fetchAll();
}

// Today's sold menu items only (stock movement view).
$topItemsTodayStmt = $pdo->prepare(
    'SELECT mi.name,
            COALESCE(MAX(c.name), \'Uncategorized\') AS category,
            SUM(oi.quantity) AS qty_sold,
            SUM(oi.subtotal) AS revenue
       FROM order_items oi
       JOIN menu_items mi ON mi.id = oi.menu_item_id
       LEFT JOIN categories c ON c.id = mi.category_id
       JOIN orders o ON o.id = oi.order_id
      WHERE o.status IN ("confirmed","served")
        AND DATE(o.created_at) = :d
      GROUP BY mi.id, mi.name
     HAVING qty_sold > 0
      ORDER BY qty_sold DESC'
);
$topItemsTodayStmt->execute([':d' => $today]);
$topItemsToday = $topItemsTodayStmt->fetchAll();

// ─── Monthly revenue (month to date) ─────────────────────────────────────────
$monthStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) AS monthly_revenue,
            COALESCE(SUM(discount_amount), 0) AS monthly_discounts
       FROM orders
      WHERE DATE(created_at) BETWEEN :from AND :to
        AND status IN ("confirmed","served")'
);
$monthStmt->execute([':from' => $monthStart, ':to' => $today]);
$monthData = $monthStmt->fetch();
$monthRawRevenue = (float)($monthData['monthly_revenue'] ?? 0);
$monthReceiptExpense = receipt_expense_between($pdo, $monthStart, $today);
$monthRevenue = $monthRawRevenue - $monthReceiptExpense;
$monthDiscounts = (float)($monthData['monthly_discounts'] ?? 0);
$monthCashflow = fetch_cashflow_summary($pdo, $monthStart, $today);
$todayCashflow = fetch_cashflow_summary($pdo, $today, $today);

// ─── Inventory low-stock proxy count (unavailable menu items) ───────────────
$lowStockStmt = $pdo->query(
    'SELECT COUNT(*) FROM menu_items WHERE is_available = 0'
);
$lowStockCount = (int)$lowStockStmt->fetchColumn();

// Slow movers: fewest units on served orders in the last 30 days.
$slowStmt = $pdo->query(
    'SELECT
        mi.name,
        COALESCE(MAX(c.name), "Uncategorized") AS category,
        COALESCE(SUM(CASE
            WHEN o.status = "served"
             AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            THEN oi.quantity ELSE 0 END), 0) AS qty_sold_30d
     FROM menu_items mi
     LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
     LEFT JOIN orders o ON o.id = oi.order_id
     LEFT JOIN categories c ON c.id = mi.category_id
     GROUP BY mi.id, mi.name
     ORDER BY qty_sold_30d ASC, mi.name ASC
     LIMIT 5'
);
$slowItems = $slowStmt->fetchAll();

$inventoryCategories = $pdo->query(
    'SELECT name FROM categories WHERE is_active = 1 ORDER BY display_order, name'
)->fetchAll(PDO::FETCH_COLUMN);

// ─── % Change helpers ─────────────────────────────────────────────────────────
function pctChange($today, $yesterday): ?float {
    if (!$yesterday) return null;
    return round((($today - $yesterday) / $yesterday) * 100, 1);
}

$payload = [
    'today'           => $todayData,
    'yesterday'       => $yestData,
    'waste'           => $wasteData,
    'recent_orders'   => $recentOrders,
    'cat_revenue'     => $catRevenue,
    'top_items'       => $topItems,
    'top_items_today' => $topItemsToday,
    'slow_items'      => $slowItems,
    'inventory_categories' => $inventoryCategories,
    'monthly_revenue' => $monthRevenue,
    'monthly_raw_revenue' => $monthRawRevenue,
    'monthly_receipt_expense' => $monthReceiptExpense,
    'monthly_discounts' => $monthDiscounts,
    'cashflow_today' => $todayCashflow,
    'cashflow_month' => $monthCashflow,
    'low_stock_count' => $lowStockCount,
    'pct_revenue'     => pctChange((float)$todayData['total_revenue'], (float)$yestData['total_revenue']),
    'pct_orders'      => pctChange((float)$todayData['total_orders'],  (float)$yestData['total_orders']),
    'date'            => $today,
];

$debugDashboard = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
if ($debugDashboard) {
    $dbg = [
        'php_today'                 => $today,
        'processed_ids_in_top_window'  => count($processedIds),
        'top_items_rows'            => count($topItems),
        'slow_items_rows'           => count($slowItems),
    ];
    try {
        $dbg['orders_status_served_total']     = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE status = "served"')->fetchColumn();
        $dbg['orders_status_confirmed_total']  = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE status = "confirmed"')->fetchColumn();
        $dbg['orders_by_status'] = $pdo->query('SELECT status, COUNT(*) AS c FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $dbg['status_count_error'] = $e->getMessage();
    }
    if ($processedIds) {
        $inDbg = implode(',', $processedIds);
        $dbg['order_lines_in_processed_window'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM order_items WHERE order_id IN ($inDbg)"
        )->fetchColumn();
        $dbg['first_processed_order_ids'] = array_slice($processedIds, 0, 8);
    } else {
        $dbg['order_lines_in_processed_window'] = 0;
        $dbg['first_processed_order_ids'] = [];
    }
    $payload['debug'] = $dbg;
}

ok($payload);
