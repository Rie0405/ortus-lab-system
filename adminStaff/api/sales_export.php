<?php
define('ORTUS_SKIP_JSON_HEADER', true);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_auth();

if (method() !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$pdo = db();
ensure_order_discount_schema($pdo);

function sales_receipt_expense_between(PDO $pdo, string $fromDate, string $toDate): float
{
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

function sales_parse_date_range(): array
{
    // Default: current week (Monday → today), not month start / all-time.
    $today = new DateTimeImmutable('today');
    $daysFromMonday = ((int)$today->format('N')) - 1; // N: 1=Mon ... 7=Sun
    $weekStart = $today->modify('-' . $daysFromMonday . ' days');

    $from = $_GET['from'] ?? $weekStart->format('Y-m-d');
    $to = $_GET['to'] ?? $today->format('Y-m-d');
    $fromDate = date('Y-m-d', strtotime((string)$from));
    $toDate = date('Y-m-d', strtotime((string)$to));

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $singleDate = trim((string)($_GET['date'] ?? ''));
    if ($singleDate !== '' && $singleDate !== 'all') {
        $only = date('Y-m-d', strtotime($singleDate));
        if ($only) {
            $fromDate = $only;
            $toDate = $only;
        }
    }

    return [$fromDate, $toDate];
}

function sales_style_header_row($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F48C25'],
        ],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']],
        ],
    ]);
}

function sales_style_money_columns($sheet, string $range): void
{
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
}

[$fromDate, $toDate] = sales_parse_date_range();

$summaryStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total_amount), 0) AS total_revenue,
        COALESCE(SUM(gross_amount), 0) AS gross_revenue,
        COALESCE(SUM(discount_amount), 0) AS discounts_total
     FROM orders
     WHERE DATE(created_at) BETWEEN :from AND :to
       AND status IN ("confirmed","served")'
);
$summaryStmt->execute([':from' => $fromDate, ':to' => $toDate]);
$summary = $summaryStmt->fetch() ?: [];

$totalOrders = (int)($summary['total_orders'] ?? 0);
$totalRevenue = (float)($summary['total_revenue'] ?? 0);
$grossRevenue = (float)($summary['gross_revenue'] ?? 0);
$discountsTotal = (float)($summary['discounts_total'] ?? 0);
$receiptExpenseTotal = sales_receipt_expense_between($pdo, $fromDate, $toDate);

$wasteStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(estimated_value), 0)
     FROM waste_log
     WHERE DATE(logged_at) BETWEEN :from AND :to'
);
$wasteStmt->execute([':from' => $fromDate, ':to' => $toDate]);
$wasteTotal = (float)$wasteStmt->fetchColumn();

$cashStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0)
     FROM orders
     WHERE DATE(created_at) BETWEEN :from AND :to
       AND status IN ("confirmed","served")
       AND payment_method = "cash"'
);
$cashStmt->execute([':from' => $fromDate, ':to' => $toDate]);
$cashRevenue = (float)$cashStmt->fetchColumn();

$gcashStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0)
     FROM orders
     WHERE DATE(created_at) BETWEEN :from AND :to
       AND status IN ("confirmed","served")
       AND payment_method = "gcash"'
);
$gcashStmt->execute([':from' => $fromDate, ':to' => $toDate]);
$gcashRevenue = (float)$gcashStmt->fetchColumn();

$avgOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;
$netEstimate = $totalRevenue - ($receiptExpenseTotal + $wasteTotal);

$ordersStmt = $pdo->prepare(
    'SELECT
        o.id,
        o.order_number,
        o.created_at,
        o.order_source,
        o.order_type,
        o.gross_amount,
        o.discount_amount,
        o.discount_type,
        o.total_amount,
        o.payment_method,
        o.status,
        o.customer_name,
        COALESCE(u.full_name, "Unknown") AS staff_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.staff_id
     WHERE DATE(o.created_at) BETWEEN :from AND :to
       AND o.status IN ("confirmed","served")
     ORDER BY o.created_at ASC'
);
$ordersStmt->execute([':from' => $fromDate, ':to' => $toDate]);
$orders = $ordersStmt->fetchAll();

$itemsStmt = $pdo->prepare(
    'SELECT
        o.order_number,
        o.created_at,
        o.payment_method,
        o.status,
        mi.name AS item_name,
        c.name AS category_name,
        oi.quantity,
        oi.unit_price,
        oi.subtotal,
        oi.notes AS line_notes
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN menu_items mi ON mi.id = oi.menu_item_id
     LEFT JOIN categories c ON c.id = mi.category_id
     WHERE DATE(o.created_at) BETWEEN :from AND :to
       AND o.status IN ("confirmed","served")
     ORDER BY o.created_at ASC, oi.id ASC'
);
$itemsStmt->execute([':from' => $fromDate, ':to' => $toDate]);
$lineItems = $itemsStmt->fetchAll();

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Ortus Lab')
    ->setTitle('Sales Report')
    ->setSubject('Sales export')
    ->setDescription('Ortus Lab sales report export');

// ── Summary sheet ─────────────────────────────────────────────────────────────
$summarySheet = $spreadsheet->getActiveSheet();
$summarySheet->setTitle('Summary');

$summarySheet->setCellValue('A1', 'ORTUS LAB — SALES REPORT');
$summarySheet->mergeCells('A1:D1');
$summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

$summarySheet->setCellValue('A2', 'Period');
$summarySheet->setCellValue('B2', $fromDate . ' to ' . $toDate);
$summarySheet->setCellValue('A3', 'Generated');
$summarySheet->setCellValue('B3', date('Y-m-d H:i:s'));

$summaryRows = [
    ['Metric', 'Value'],
    ['Total Orders', $totalOrders],
    ['Total Sales (PHP)', $totalRevenue],
    ['Gross Revenue (PHP)', $grossRevenue],
    ['Discounts (PHP)', $discountsTotal],
    ['Cash Sales (PHP)', $cashRevenue],
    ['GCash Sales (PHP)', $gcashRevenue],
    ['Receipt Expenses (PHP)', $receiptExpenseTotal],
    ['Waste Value (PHP)', $wasteTotal],
    ['Average Order Value (PHP)', $avgOrder],
    ['Net Estimate (PHP)', $netEstimate],
];

$startRow = 5;
foreach ($summaryRows as $i => $row) {
    $summarySheet->setCellValue('A' . ($startRow + $i), $row[0]);
    $summarySheet->setCellValue('B' . ($startRow + $i), $row[1]);
}

sales_style_header_row($summarySheet, 'A5:B5');
sales_style_money_columns($summarySheet, 'B7:B' . ($startRow + count($summaryRows) - 1));
$summarySheet->getColumnDimension('A')->setWidth(28);
$summarySheet->getColumnDimension('B')->setWidth(22);

// ── Orders sheet ──────────────────────────────────────────────────────────────
$ordersSheet = $spreadsheet->createSheet();
$ordersSheet->setTitle('Orders');

$orderHeaders = [
    'Date', 'Time', 'Order #', 'Source', 'Order Type', 'Staff',
    'Customer', 'Gross (PHP)', 'Discount (PHP)', 'Total (PHP)',
    'Payment', 'Status', 'Discount Type',
];
$ordersSheet->fromArray($orderHeaders, null, 'A1');
sales_style_header_row($ordersSheet, 'A1:M1');

$row = 2;
foreach ($orders as $order) {
    $createdAt = strtotime((string)$order['created_at']);
    $ordersSheet->fromArray([
        date('Y-m-d', $createdAt),
        date('h:i A', $createdAt),
        $order['order_number'],
        strtoupper((string)$order['order_source']),
        $order['order_type'] ?: '—',
        $order['staff_name'],
        $order['customer_name'] ?: '—',
        (float)$order['gross_amount'],
        (float)$order['discount_amount'],
        (float)$order['total_amount'],
        strtoupper((string)$order['payment_method']),
        strtoupper((string)$order['status']),
        strtoupper((string)$order['discount_type']),
    ], null, 'A' . $row);
    $row++;
}

if ($row > 2) {
    sales_style_money_columns($ordersSheet, 'H2:J' . ($row - 1));
    $ordersSheet->setAutoFilter('A1:M' . ($row - 1));
}

foreach (range('A', 'M') as $col) {
    $ordersSheet->getColumnDimension($col)->setAutoSize(true);
}

// ── Line items sheet ──────────────────────────────────────────────────────────
$itemsSheet = $spreadsheet->createSheet();
$itemsSheet->setTitle('Line Items');

$itemHeaders = [
    'Date', 'Time', 'Order #', 'Item', 'Category', 'Qty',
    'Unit Price (PHP)', 'Line Total (PHP)', 'Payment', 'Status', 'Notes',
];
$itemsSheet->fromArray($itemHeaders, null, 'A1');
sales_style_header_row($itemsSheet, 'A1:K1');

$row = 2;
foreach ($lineItems as $item) {
    $createdAt = strtotime((string)$item['created_at']);
    $itemsSheet->fromArray([
        date('Y-m-d', $createdAt),
        date('h:i A', $createdAt),
        $item['order_number'],
        $item['item_name'],
        $item['category_name'] ?: '—',
        (int)$item['quantity'],
        (float)$item['unit_price'],
        (float)$item['subtotal'],
        strtoupper((string)$item['payment_method']),
        strtoupper((string)$item['status']),
        $item['line_notes'] ?: '',
    ], null, 'A' . $row);
    $row++;
}

if ($row > 2) {
    sales_style_money_columns($itemsSheet, 'G2:H' . ($row - 1));
    $itemsSheet->setAutoFilter('A1:K' . ($row - 1));
}

foreach (range('A', 'K') as $col) {
    $itemsSheet->getColumnDimension($col)->setAutoSize(true);
}

$spreadsheet->setActiveSheetIndex(0);

$filename = sprintf(
    'Ortus_Sales_%s_to_%s.xlsx',
    $fromDate,
    $toDate
);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$target = (defined('ORTUS_EXPORT_TARGET') && ORTUS_EXPORT_TARGET !== '')
    ? (string)ORTUS_EXPORT_TARGET
    : 'php://output';

if ($target === 'php://output') {
    $writer->save('php://output');
    exit;
}

$writer->save($target);
exit;
