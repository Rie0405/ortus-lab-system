<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';
require_auth(); // admin or staff

$pdo = db();
ensure_order_discount_schema($pdo);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS revenue_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL,
        expected_revenue DECIMAL(10,2) NOT NULL DEFAULT 0,
        cash_declared DECIMAL(10,2) NOT NULL DEFAULT 0,
        difference DECIMAL(10,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        staff_id INT NULL,
        verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_report_date_staff (report_date, staff_id),
        CONSTRAINT fk_revenue_staff
            FOREIGN KEY (staff_id) REFERENCES users(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$m = method();
$date = $_GET['date'] ?? date('Y-m-d');
$staffId = (int)($_SESSION['user_id'] ?? 0);

function variance_status_from_difference(float $difference): string
{
    if (abs($difference) < 0.005) {
        return 'accurate';
    }
    if ($difference > 0) {
        return 'over';
    }
    if ($difference < 0) {
        return 'short';
    }
    return 'accurate';
}

function get_cash_sales_total(PDO $pdo, string $date): float
{
    $sum = $pdo->prepare(
        'SELECT COALESCE(SUM(total_amount), 0) AS cash_sales
         FROM orders
         WHERE DATE(created_at) = :d
           AND payment_method = "cash"
           AND status IN ("confirmed","served")'
    );
    $sum->execute([':d' => $date]);
    return (float)$sum->fetchColumn();
}

function normalize_currency_amount(float $amount): float
{
    $rounded = round($amount, 2);
    return abs($rounded) < 0.005 ? 0.0 : $rounded;
}

if ($m === 'GET') {
    $openingFloat = (float)($_GET['opening_float'] ?? 0);
    $sum = $pdo->prepare(
        'SELECT
            COALESCE(SUM(total_amount), 0) AS expected_revenue,
            COALESCE(SUM(gross_amount), 0) AS gross_sales,
            COALESCE(SUM(discount_amount), 0) AS discount_total,
            COUNT(*) AS total_orders
         FROM orders
         WHERE DATE(created_at) = :d
           AND status IN ("confirmed","served")'
    );
    $sum->execute([':d' => $date]);
    $summary = $sum->fetch();
    $cashSales = get_cash_sales_total($pdo, $date);
    $expectedDrawer = normalize_currency_amount(max(0, $openingFloat) + $cashSales);

    $waste = $pdo->prepare(
        'SELECT COALESCE(SUM(estimated_value), 0) AS waste_total
         FROM waste_log
         WHERE DATE(logged_at) = :d'
    );
    $waste->execute([':d' => $date]);
    $wasteTotal = (float)$waste->fetchColumn();

    $ver = $pdo->prepare(
        'SELECT id, report_date, expected_revenue, cash_declared, difference, notes, staff_id, verified_at
         FROM revenue_verifications
         WHERE report_date = :d AND staff_id = :sid
         LIMIT 1'
    );
    $ver->execute([':d' => $date, ':sid' => $staffId]);
    $verification = $ver->fetch() ?: null;
    if ($verification) {
        $verification['expected_revenue'] = (float)$verification['expected_revenue'];
        $verification['cash_declared'] = (float)$verification['cash_declared'];
        $verification['difference'] = (float)$verification['difference'];
        $verification['status'] = variance_status_from_difference((float)$verification['difference']);
    }

    ok([
        'date' => $date,
        'summary' => [
            'expected_revenue' => (float)$summary['expected_revenue'],
            'cash_sales' => $cashSales,
            'opening_float' => round(max(0, $openingFloat), 2),
            'expected_cash_drawer' => $expectedDrawer,
            'gross_sales' => (float)$summary['gross_sales'],
            'discount_total' => (float)$summary['discount_total'],
            'total_orders' => (int)$summary['total_orders'],
            'waste_total' => $wasteTotal,
            'drawer_expected' => $expectedDrawer,
        ],
        'verification' => $verification,
    ]);
}

if ($m === 'POST') {
    $b = body();
    $reportDate = $b['report_date'] ?? date('Y-m-d');
    $cashDeclared = (float)($b['cash_declared'] ?? 0);
    $openingFloat = (float)($b['opening_float'] ?? 0);
    $notes = trim($b['notes'] ?? '');
    if ($cashDeclared < 0) fail('Cash declared cannot be negative.');
    if ($openingFloat < 0) fail('Opening float cannot be negative.');

    $cashSales = get_cash_sales_total($pdo, $reportDate);
    $expected = normalize_currency_amount($openingFloat + $cashSales);
    $diff = normalize_currency_amount($cashDeclared - $expected);
    $status = variance_status_from_difference($diff);

    $upsert = $pdo->prepare(
        'INSERT INTO revenue_verifications
            (report_date, expected_revenue, cash_declared, difference, notes, staff_id)
         VALUES
            (:d, :exp, :decl, :diff, :notes, :sid)
         ON DUPLICATE KEY UPDATE
            expected_revenue = VALUES(expected_revenue),
            cash_declared = VALUES(cash_declared),
            difference = VALUES(difference),
            notes = VALUES(notes),
            verified_at = CURRENT_TIMESTAMP'
    );
    $upsert->execute([
        ':d' => $reportDate,
        ':exp' => $expected,
        ':decl' => $cashDeclared,
        ':diff' => $diff,
        ':notes' => $notes ?: null,
        ':sid' => $staffId ?: null,
    ]);

    ok([
        'message' => 'Daily revenue verification saved.',
        'expected_cash_drawer' => $expected,
        'cash_sales' => $cashSales,
        'difference' => $diff,
        'status' => $status,
    ]);
}

fail('Method not allowed.', 405);
