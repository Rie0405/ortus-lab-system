<?php
require_once __DIR__ . '/config.php';
require_auth();

$pdo = db();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS staff_sales_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        report_date DATE NOT NULL,
        starting_money_json TEXT NULL,
        starting_money_locked TINYINT(1) NOT NULL DEFAULT 0,
        accuracy_json TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_sales_report (staff_id, report_date),
        CONSTRAINT fk_staff_sales_report_staff
            FOREIGN KEY (staff_id) REFERENCES users(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$m = method();
$session = [
    'id'   => (int)($_SESSION['user_id'] ?? 0),
    'role' => strtolower((string)($_SESSION['user_role'] ?? '')),
];

function decode_json_field($value): ?array {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : null;
}

function normalize_report_row(?array $row): ?array {
    if (!$row) {
        return null;
    }
    $starting = decode_json_field($row['starting_money_json'] ?? null);
    $accuracy = decode_json_field($row['accuracy_json'] ?? null);
    return [
        'staff_id' => (int)($row['staff_id'] ?? 0),
        'report_date' => (string)($row['report_date'] ?? ''),
        'starting_money' => $starting,
        'starting_money_locked' => (bool)($row['starting_money_locked'] ?? false),
        'accuracy' => $accuracy,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

if ($m === 'GET') {
    $staffId = (int)($_GET['staff_id'] ?? 0);
    $reportDate = trim($_GET['date'] ?? date('Y-m-d'));
    if (!$staffId) {
        fail('staff_id is required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        fail('Invalid report date.');
    }

    $stmt = $pdo->prepare(
        'SELECT staff_id, report_date, starting_money_json, starting_money_locked, accuracy_json, updated_at
           FROM staff_sales_reports
          WHERE staff_id = :sid AND report_date = :d
          LIMIT 1'
    );
    $stmt->execute([':sid' => $staffId, ':d' => $reportDate]);
    $row = $stmt->fetch();

    ok(['report' => normalize_report_row($row ?: null)]);
}

if ($m === 'POST' || $m === 'PUT') {
    $b = body();
    $staffId = (int)($b['staff_id'] ?? $session['id']);
    $reportDate = trim($b['report_date'] ?? date('Y-m-d'));

    if (!$staffId) {
        fail('staff_id is required.');
    }
    if ($session['role'] !== 'admin' && $staffId !== $session['id']) {
        fail('Forbidden', 403);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        fail('Invalid report date.');
    }

    $startingMoney = null;
    if (array_key_exists('starting_money', $b)) {
        $startingMoney = decode_json_field($b['starting_money']);
        if ($b['starting_money'] !== null && $b['starting_money'] !== '' && $startingMoney === null) {
            $startingMoney = ['raw' => (string)$b['starting_money']];
        }
    }

    $accuracy = null;
    if (array_key_exists('accuracy', $b)) {
        $accuracy = decode_json_field($b['accuracy']);
        if ($b['accuracy'] !== null && $b['accuracy'] !== '' && $accuracy === null) {
            $accuracy = ['raw' => (string)$b['accuracy']];
        }
    }

    $locked = isset($b['starting_money_locked']) ? (int)(bool)$b['starting_money_locked'] : null;

    $existing = $pdo->prepare(
        'SELECT starting_money_json, starting_money_locked, accuracy_json
           FROM staff_sales_reports
          WHERE staff_id = :sid AND report_date = :d
          LIMIT 1'
    );
    $existing->execute([':sid' => $staffId, ':d' => $reportDate]);
    $current = $existing->fetch() ?: [];

    $startingJson = $startingMoney !== null
        ? json_encode($startingMoney, JSON_UNESCAPED_UNICODE)
        : ($current['starting_money_json'] ?? null);
    $accuracyJson = $accuracy !== null
        ? json_encode($accuracy, JSON_UNESCAPED_UNICODE)
        : ($current['accuracy_json'] ?? null);
    $lockedValue = $locked !== null
        ? $locked
        : (int)($current['starting_money_locked'] ?? 0);

    $upsert = $pdo->prepare(
        'INSERT INTO staff_sales_reports
            (staff_id, report_date, starting_money_json, starting_money_locked, accuracy_json)
         VALUES
            (:sid, :d, :sm, :locked, :acc)
         ON DUPLICATE KEY UPDATE
            starting_money_json = VALUES(starting_money_json),
            starting_money_locked = VALUES(starting_money_locked),
            accuracy_json = VALUES(accuracy_json),
            updated_at = CURRENT_TIMESTAMP'
    );
    $upsert->execute([
        ':sid' => $staffId,
        ':d' => $reportDate,
        ':sm' => $startingJson,
        ':locked' => $lockedValue,
        ':acc' => $accuracyJson,
    ]);

    ok(['message' => 'Staff sales report saved.', 'staff_id' => $staffId, 'report_date' => $reportDate]);
}

fail('Method not allowed.', 405);
