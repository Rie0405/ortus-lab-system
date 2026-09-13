<?php
define('ORTUS_SKIP_JSON_HEADER', true);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/cashflow_helpers.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_auth();

$m = method();
if ($m !== 'GET' && $m !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$pdo = db();
ensure_order_discount_schema($pdo);

$payload = $m === 'POST' ? body() : [];
if (!is_array($payload)) {
    $payload = [];
}

$reportDate = trim((string)($payload['date'] ?? $_GET['date'] ?? date('Y-m-d')));
$reportDate = date('Y-m-d', strtotime($reportDate)) ?: date('Y-m-d');

$afterRaw = (int)($payload['after_ms'] ?? $_GET['after_ms'] ?? 0);
$afterSql = null;
if ($afterRaw > 0) {
    // Client sends epoch ms; accept seconds too.
    $afterSec = $afterRaw > 9999999999 ? (int)floor($afterRaw / 1000) : $afterRaw;
    $afterSql = date('Y-m-d H:i:s', $afterSec);
}

$drawer = is_array($payload['drawer'] ?? null) ? $payload['drawer'] : [];
$cashMgmt = is_array($payload['cash_mgmt'] ?? null) ? $payload['cash_mgmt'] : [];
$cashRefunds = is_array($payload['cash_refunds'] ?? null) ? $payload['cash_refunds'] : [];

function shift_money($v): float
{
    return round(max(0, (float)$v), 2);
}

function shift_style_header_row($sheet, string $range): void
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

function shift_style_money_columns($sheet, string $range): void
{
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
}

$saleCond = sql_order_counts_as_sale();
$saleCondO = sql_order_counts_as_sale('o');

$timeClause = 'DATE(created_at) = :d';
$timeParams = [':d' => $reportDate];
if ($afterSql !== null) {
    $timeClause .= ' AND created_at >= :after';
    $timeParams[':after'] = $afterSql;
}

$summaryStmt = $pdo->prepare(
    'SELECT
        COUNT(CASE WHEN ' . $saleCond . ' THEN 1 END) AS total_orders,
        COALESCE(SUM(CASE WHEN ' . $saleCond . ' THEN total_amount ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN ' . $saleCond . ' THEN gross_amount ELSE 0 END), 0) AS gross_revenue,
        COALESCE(SUM(CASE WHEN ' . $saleCond . ' THEN discount_amount ELSE 0 END), 0) AS discounts_total,
        COALESCE(SUM(CASE WHEN ' . $saleCond . ' AND payment_method = "cash" THEN total_amount ELSE 0 END), 0) AS cash_revenue,
        COALESCE(SUM(CASE WHEN ' . $saleCond . ' AND payment_method = "gcash" THEN total_amount ELSE 0 END), 0) AS gcash_revenue,
        COUNT(CASE WHEN status = "voided" THEN 1 END) AS voided_count,
        COALESCE(SUM(CASE WHEN status = "voided" THEN total_amount ELSE 0 END), 0) AS voided_total
     FROM orders
     WHERE ' . $timeClause
);
$summaryStmt->execute($timeParams);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOrders = (int)($summary['total_orders'] ?? 0);
$totalRevenue = (float)($summary['total_revenue'] ?? 0);
$grossRevenue = (float)($summary['gross_revenue'] ?? 0);
$discountsTotal = (float)($summary['discounts_total'] ?? 0);
$cashRevenue = (float)($summary['cash_revenue'] ?? 0);
$gcashRevenue = (float)($summary['gcash_revenue'] ?? 0);
$voidedCount = (int)($summary['voided_count'] ?? 0);
$voidedTotal = (float)($summary['voided_total'] ?? 0);

$wasteTimeClause = 'DATE(logged_at) = :d AND parent_waste_id IS NULL';
$wasteParams = [':d' => $reportDate];
if ($afterSql !== null) {
    $wasteTimeClause .= ' AND logged_at >= :after';
    $wasteParams[':after'] = $afterSql;
}

$wasteSumStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(estimated_value), 0)
     FROM waste_log
     WHERE ' . $wasteTimeClause
);
$wasteSumStmt->execute($wasteParams);
$wasteTotal = (float)$wasteSumStmt->fetchColumn();

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
        o.kitchen_returned,
        COALESCE(u.full_name, "Unknown") AS staff_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.staff_id
     WHERE DATE(o.created_at) = :d
       ' . ($afterSql !== null ? 'AND o.created_at >= :after' : '') . '
       AND (' . $saleCondO . ' OR o.status = "voided")
     ORDER BY o.created_at ASC'
);
$ordersParams = [':d' => $reportDate];
if ($afterSql !== null) {
    $ordersParams[':after'] = $afterSql;
}
$ordersStmt->execute($ordersParams);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

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
     WHERE DATE(o.created_at) = :d
       ' . ($afterSql !== null ? 'AND o.created_at >= :after' : '') . '
       AND ' . $saleCondO . '
     ORDER BY o.created_at ASC, oi.id ASC'
);
$itemsStmt->execute($ordersParams);
$lineItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$wasteStmt = $pdo->prepare(
    'SELECT
        w.id,
        w.quantity,
        w.reason,
        w.notes,
        w.estimated_value,
        w.logged_at,
        COALESCE(i.item_name, m.name, "Unknown Item") AS item_name,
        COALESCE(u.full_name, "—") AS staff_name
     FROM waste_log w
     LEFT JOIN inventory_items i ON i.id = w.inventory_item_id
     LEFT JOIN menu_items m ON m.id = w.menu_item_id
     LEFT JOIN users u ON u.id = w.staff_id
     WHERE ' . $wasteTimeClause . '
     ORDER BY w.logged_at ASC'
);
$wasteStmt->execute($wasteParams);
$wasteRows = $wasteStmt->fetchAll(PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Ortus Lab')
    ->setTitle('Shift End Summary')
    ->setSubject('Shift export')
    ->setDescription('Ortus Lab shift end export');

$summarySheet = $spreadsheet->getActiveSheet();
$summarySheet->setTitle('Shift Summary');

$summarySheet->setCellValue('A1', 'ORTUS LAB — SHIFT END SUMMARY');
$summarySheet->mergeCells('A1:B1');
$summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

$shiftStaff = trim((string)($payload['shift_staff'] ?? $_GET['shift_staff'] ?? ''));
if ($shiftStaff === '') {
    $shiftStaff = trim((string)($_SESSION['user_name'] ?? ''));
}
if ($shiftStaff === '') {
    $shiftStaff = 'Unknown Staff';
}

$summarySheet->setCellValue('A2', 'Current Shift Staff');
$summarySheet->setCellValue('B2', $shiftStaff);
$summarySheet->setCellValue('A3', 'Shift Date');
$summarySheet->setCellValue('B3', $reportDate);
$summarySheet->setCellValue('A4', 'Shift Window Start');
$summarySheet->setCellValue('B4', $afterSql ?: 'Start of day');
$summarySheet->setCellValue('A5', 'Generated');
$summarySheet->setCellValue('B5', date('Y-m-d H:i:s'));

$startingMoney = shift_money($drawer['starting_money'] ?? 0);
$paidIn = shift_money($drawer['paid_in'] ?? 0);
$paidOut = shift_money($drawer['paid_out'] ?? 0);
$cashRefundsTotal = shift_money($drawer['cash_refunds'] ?? 0);
$expectedCash = array_key_exists('expected_cash', $drawer)
    ? shift_money($drawer['expected_cash'])
    : round($startingMoney + $cashRevenue + $paidIn - $paidOut - $cashRefundsTotal, 2);
$countedCash = shift_money($drawer['counted_cash'] ?? 0);

$summaryRows = [
    ['Metric', 'Value'],
    ['Total Shift Orders', $totalOrders],
    ['Total Sales (PHP)', $totalRevenue],
    ['Gross Revenue (PHP)', $grossRevenue],
    ['Discounts (PHP)', $discountsTotal],
    ['Cash Payments (PHP)', $cashRevenue],
    ['GCash Payments (PHP)', $gcashRevenue],
    ['Total Payments (PHP)', $cashRevenue + $gcashRevenue],
    ['Voided / Refunded Count', $voidedCount],
    ['Voided / Refunded (PHP)', $voidedTotal],
    ['Waste Value (PHP)', $wasteTotal],
    ['Starting Money (PHP)', $startingMoney],
    ['Paid In (PHP)', $paidIn],
    ['Paid Out (PHP)', $paidOut],
    ['Cash Refunds (PHP)', $cashRefundsTotal],
    ['Expected Cash (PHP)', $expectedCash],
    ['Counted Cash (PHP)', $countedCash],
];

$startRow = 7;
foreach ($summaryRows as $i => $row) {
    $summarySheet->setCellValue('A' . ($startRow + $i), $row[0]);
    $summarySheet->setCellValue('B' . ($startRow + $i), $row[1]);
}
shift_style_header_row($summarySheet, 'A7:B7');
shift_style_money_columns($summarySheet, 'B9:B' . ($startRow + count($summaryRows) - 1));
$summarySheet->getColumnDimension('A')->setWidth(30);
$summarySheet->getColumnDimension('B')->setWidth(22);

$ordersSheet = $spreadsheet->createSheet();
$ordersSheet->setTitle('Orders');
$orderHeaders = [
    'Date', 'Time', 'Order #', 'Source', 'Order Type', 'Staff',
    'Customer', 'Gross (PHP)', 'Discount (PHP)', 'Total (PHP)',
    'Payment', 'Status', 'Discount Type',
];
$ordersSheet->fromArray($orderHeaders, null, 'A1');
shift_style_header_row($ordersSheet, 'A1:M1');
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
        strtoupper((string)($order['discount_type'] ?? '')),
    ], null, 'A' . $row);
    $row++;
}
if ($row > 2) {
    shift_style_money_columns($ordersSheet, 'H2:J' . ($row - 1));
    $ordersSheet->setAutoFilter('A1:M' . ($row - 1));
}
foreach (range('A', 'M') as $col) {
    $ordersSheet->getColumnDimension($col)->setAutoSize(true);
}

$itemsSheet = $spreadsheet->createSheet();
$itemsSheet->setTitle('Line Items');
$itemHeaders = [
    'Date', 'Time', 'Order #', 'Item', 'Category', 'Qty',
    'Unit Price (PHP)', 'Line Total (PHP)', 'Payment', 'Status', 'Notes',
];
$itemsSheet->fromArray($itemHeaders, null, 'A1');
shift_style_header_row($itemsSheet, 'A1:K1');
$row = 2;
foreach ($lineItems as $item) {
    $createdAt = strtotime((string)$item['created_at']);
    $itemsSheet->fromArray([
        date('Y-m-d', $createdAt),
        date('h:i A', $createdAt),
        $item['order_number'],
        $item['item_name'],
        $item['category_name'] ?: '—',
        (float)$item['quantity'],
        (float)$item['unit_price'],
        (float)$item['subtotal'],
        strtoupper((string)$item['payment_method']),
        strtoupper((string)$item['status']),
        $item['line_notes'] ?: '',
    ], null, 'A' . $row);
    $row++;
}
if ($row > 2) {
    shift_style_money_columns($itemsSheet, 'G2:H' . ($row - 1));
    $itemsSheet->setAutoFilter('A1:K' . ($row - 1));
}
foreach (range('A', 'K') as $col) {
    $itemsSheet->getColumnDimension($col)->setAutoSize(true);
}

$wasteSheet = $spreadsheet->createSheet();
$wasteSheet->setTitle('Waste');
$wasteHeaders = ['Date', 'Time', 'Item', 'Qty', 'Reason', 'Notes', 'Value (PHP)', 'Staff'];
$wasteSheet->fromArray($wasteHeaders, null, 'A1');
shift_style_header_row($wasteSheet, 'A1:H1');
$row = 2;
foreach ($wasteRows as $w) {
    $loggedAt = strtotime((string)$w['logged_at']);
    $wasteSheet->fromArray([
        date('Y-m-d', $loggedAt),
        date('h:i A', $loggedAt),
        $w['item_name'],
        (float)$w['quantity'],
        strtoupper((string)$w['reason']),
        $w['notes'] ?: '',
        (float)$w['estimated_value'],
        $w['staff_name'],
    ], null, 'A' . $row);
    $row++;
}
if ($row > 2) {
    shift_style_money_columns($wasteSheet, 'G2:G' . ($row - 1));
    $wasteSheet->setAutoFilter('A1:H' . ($row - 1));
}
foreach (range('A', 'H') as $col) {
    $wasteSheet->getColumnDimension($col)->setAutoSize(true);
}

$cashSheet = $spreadsheet->createSheet();
$cashSheet->setTitle('Cash Movements');
$cashHeaders = ['Type', 'Amount (PHP)', 'Reason', 'Time'];
$cashSheet->fromArray($cashHeaders, null, 'A1');
shift_style_header_row($cashSheet, 'A1:D1');
$row = 2;
foreach ($cashMgmt as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $type = strtolower((string)($entry['type'] ?? 'paid_in')) === 'paid_out' ? 'Paid Out' : 'Paid In';
    $created = (string)($entry['created_at'] ?? '');
    $cashSheet->fromArray([
        $type,
        shift_money($entry['amount'] ?? 0),
        (string)($entry['reason'] ?? ''),
        $created !== '' ? date('Y-m-d h:i A', strtotime($created)) : '—',
    ], null, 'A' . $row);
    $row++;
}
foreach ($cashRefunds as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $created = (string)($entry['created_at'] ?? '');
    $cashSheet->fromArray([
        'Cash Refund',
        shift_money($entry['amount'] ?? 0),
        (string)($entry['reason'] ?? ''),
        $created !== '' ? date('Y-m-d h:i A', strtotime($created)) : '—',
    ], null, 'A' . $row);
    $row++;
}
if ($row > 2) {
    shift_style_money_columns($cashSheet, 'B2:B' . ($row - 1));
    $cashSheet->setAutoFilter('A1:D' . ($row - 1));
}
foreach (range('A', 'D') as $col) {
    $cashSheet->getColumnDimension($col)->setAutoSize(true);
}

$spreadsheet->setActiveSheetIndex(0);

$filename = sprintf('Ortus_Shift_%s.xlsx', $reportDate);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
