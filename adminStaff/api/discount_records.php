<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/cashflow_helpers.php';

require_auth();
if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$pdo = db();
ensure_order_discount_schema($pdo);

// Quick check: has this ID already been used on a confirmed discount today?
$checkId = trim((string)($_GET['check_id'] ?? ''));
if ($checkId !== '') {
    $excludeOrderId = isset($_GET['exclude_order_id']) ? (int)$_GET['exclude_order_id'] : null;
    $used = find_discount_id_used_today($pdo, $checkId, $excludeOrderId > 0 ? $excludeOrderId : null);
    ok([
        'check_id' => $checkId,
        'used_today' => $used !== null,
        'order_number' => $used['order_number'] ?? null,
        'order_id' => $used['order_id'] ?? null,
        'message' => $used
            ? ('This discount ID was already used today'
                . (!empty($used['order_number']) ? (' on order ' . $used['order_number']) : '')
                . '.')
            : 'This discount ID is available today.',
    ]);
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$fromDate = date('Y-m-d', strtotime($from));
$toDate = date('Y-m-d', strtotime($to));
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

$limit = min(max((int)($_GET['limit'] ?? 300), 1), 1000);
$records = fetch_confirmed_discount_records($pdo, $fromDate, $toDate, $selectedDates, $limit);

ok([
    'from' => $fromDate,
    'to' => $toDate,
    'dates' => $selectedDates,
    'records' => $records,
    'count' => count($records),
]);
