<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/staff_shifts_helpers.php';

require_auth();

$pdo = db();
ensure_staff_shifts_schema($pdo);
$m = method();

if ($m === 'GET') {
    $staffId = (int)($_GET['staff_id'] ?? 0);
    if (!$staffId) {
        fail('staff_id is required.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, staff_id, shift_date, logged_in_at, logged_out_at
           FROM staff_shifts
          WHERE staff_id = :sid
          ORDER BY shift_date DESC, logged_in_at DESC
          LIMIT 120'
    );
    $stmt->execute([':sid' => $staffId]);
    $shifts = [];
    foreach ($stmt->fetchAll() as $row) {
        $shifts[] = [
            'id' => (int)$row['id'],
            'staff_id' => (int)$row['staff_id'],
            'shift_date' => (string)$row['shift_date'],
            'logged_in_at' => (string)$row['logged_in_at'],
            'logged_out_at' => $row['logged_out_at'] ? (string)$row['logged_out_at'] : null,
        ];
    }

    ok(['shifts' => $shifts]);
}

fail('Method not allowed.', 405);
