<?php
require_once __DIR__ . '/config.php';
require_auth();

$pdo = db();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shift_archives (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NULL,
        staff_name VARCHAR(255) NOT NULL DEFAULT \'\',
        shift_date DATE NOT NULL,
        closed_at DATETIME NOT NULL,
        snapshot_json LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shift_archives_date (shift_date),
        INDEX idx_shift_archives_staff (staff_id),
        CONSTRAINT fk_shift_archives_staff
            FOREIGN KEY (staff_id) REFERENCES users(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$m = method();
$sessionId = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = strtolower((string)($_SESSION['user_role'] ?? ''));

function shift_archive_decode_snapshot($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Fill waste/refund reason lists from live tables when older snapshots omit them.
 */
function shift_archive_enrich_reasons(PDO $pdo, array $archiveRow, array $snapshot): array
{
    $staffId = isset($archiveRow['staff_id']) && $archiveRow['staff_id'] !== null
        ? (int)$archiveRow['staff_id']
        : 0;
    $shiftDate = (string)($archiveRow['shift_date'] ?? '');
    if ($staffId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate)) {
        return $snapshot;
    }

    $startedMs = isset($snapshot['shift_started_ms']) ? (int)$snapshot['shift_started_ms'] : 0;
    $startedAt = $startedMs > 0 ? date('Y-m-d H:i:s', (int)floor($startedMs / 1000)) : null;

    $wasteList = $snapshot['waste_entries'] ?? null;
    if (!is_array($wasteList) || count($wasteList) === 0) {
        try {
            $sql = 'SELECT
                        w.id, w.quantity, w.reason, w.notes, w.estimated_value, w.logged_at,
                        COALESCE(i.item_name, m.name, "Unknown Item") AS item_name
                      FROM waste_log w
                      LEFT JOIN inventory_items i ON i.id = w.inventory_item_id
                      LEFT JOIN menu_items m ON m.id = w.menu_item_id
                     WHERE w.parent_waste_id IS NULL
                       AND w.staff_id = :sid
                       AND DATE(w.logged_at) = :d';
            $params = [':sid' => $staffId, ':d' => $shiftDate];
            if ($startedAt) {
                $sql .= ' AND w.logged_at >= :started';
                $params[':started'] = $startedAt;
            }
            $sql .= ' ORDER BY w.logged_at ASC, w.id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $wasteList = [];
            foreach ($stmt->fetchAll() ?: [] as $w) {
                $wasteList[] = [
                    'id' => (int)($w['id'] ?? 0),
                    'item_name' => (string)($w['item_name'] ?? 'Unknown Item'),
                    'quantity' => (float)($w['quantity'] ?? 0),
                    'reason' => (string)($w['reason'] ?? ''),
                    'notes' => $w['notes'] !== null ? (string)$w['notes'] : '',
                    'estimated_value' => (float)($w['estimated_value'] ?? 0),
                    'logged_at' => $w['logged_at'] ?? null,
                ];
            }
            $snapshot['waste_entries'] = $wasteList;
        } catch (Throwable $e) {
            // Best-effort enrichment.
        }
    }

    $refunds = $snapshot['refunds'] ?? null;
    $cashRefunds = is_array($snapshot['cash_refunds'] ?? null) ? $snapshot['cash_refunds'] : [];
    $hasRefundReasons = is_array($refunds) && count($refunds) > 0;
    if (!$hasRefundReasons && count($cashRefunds) > 0) {
        $mapped = [];
        foreach ($cashRefunds as $cr) {
            if (!is_array($cr)) {
                continue;
            }
            $reason = trim((string)($cr['reason'] ?? $cr['refund_reason'] ?? ''));
            if ($reason === '') {
                continue;
            }
            $mapped[] = [
                'order_id' => isset($cr['order_id']) ? (int)$cr['order_id'] : null,
                'order_number' => (string)($cr['order_number'] ?? ''),
                'amount' => (float)($cr['amount'] ?? 0),
                'reason' => $reason,
                'payment_method' => (string)($cr['payment_method'] ?? 'cash'),
                'created_at' => $cr['created_at'] ?? null,
            ];
        }
        if (count($mapped) > 0) {
            $snapshot['refunds'] = $mapped;
            $hasRefundReasons = true;
        }
    }

    if (!$hasRefundReasons) {
        try {
            // Ensure column exists (same helper as orders API when available).
            try {
                $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'refund_reason'");
                if ($col && !$col->fetch()) {
                    $pdo->exec(
                        'ALTER TABLE orders ADD COLUMN refund_reason VARCHAR(200) NULL DEFAULT NULL AFTER customer_name'
                    );
                }
            } catch (Throwable $e) {
                // Ignore schema probe failures.
            }

            $sql = 'SELECT id, order_number, total_amount, refund_reason, payment_method, created_at
                      FROM orders
                     WHERE status = \'voided\'
                       AND staff_id = :sid
                       AND DATE(created_at) = :d
                       AND refund_reason IS NOT NULL
                       AND TRIM(refund_reason) <> \'\'';
            $params = [':sid' => $staffId, ':d' => $shiftDate];
            if ($startedAt) {
                $sql .= ' AND created_at >= :started';
                $params[':started'] = $startedAt;
            }
            $sql .= ' ORDER BY created_at ASC, id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $mapped = [];
            foreach ($stmt->fetchAll() ?: [] as $o) {
                $mapped[] = [
                    'order_id' => (int)($o['id'] ?? 0),
                    'order_number' => (string)($o['order_number'] ?? ''),
                    'amount' => (float)($o['total_amount'] ?? 0),
                    'reason' => trim((string)($o['refund_reason'] ?? '')),
                    'payment_method' => (string)($o['payment_method'] ?? 'cash'),
                    'created_at' => $o['created_at'] ?? null,
                ];
            }
            $snapshot['refunds'] = $mapped;
        } catch (Throwable $e) {
            // Best-effort enrichment.
        }
    }

    return $snapshot;
}

function shift_archive_row(?array $row, bool $includeSnapshot = true, ?PDO $pdo = null): ?array
{
    if (!$row) {
        return null;
    }
    $out = [
        'id' => (int)($row['id'] ?? 0),
        'staff_id' => isset($row['staff_id']) && $row['staff_id'] !== null ? (int)$row['staff_id'] : null,
        'staff_name' => (string)($row['staff_name'] ?? ''),
        'shift_date' => (string)($row['shift_date'] ?? ''),
        'closed_at' => (string)($row['closed_at'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
    if ($includeSnapshot) {
        $snap = shift_archive_decode_snapshot($row['snapshot_json'] ?? null);
        if ($pdo instanceof PDO) {
            $snap = shift_archive_enrich_reasons($pdo, $row, $snap);
        }
        $out['snapshot'] = $snap;
    } else {
        $snap = shift_archive_decode_snapshot($row['snapshot_json'] ?? null);
        $summary = is_array($snap['summary'] ?? null) ? $snap['summary'] : [];
        $drawer = is_array($snap['drawer'] ?? null) ? $snap['drawer'] : [];
        $accuracy = is_array($snap['accuracy'] ?? null) ? $snap['accuracy'] : [];
        $out['preview'] = [
            'total_orders' => (int)($summary['total_orders'] ?? 0),
            'total_revenue' => (float)($summary['total_revenue'] ?? 0),
            'cash_revenue' => (float)($summary['cash_revenue'] ?? ($drawer['cash_payments'] ?? 0)),
            'gcash_revenue' => (float)($summary['gcash_revenue'] ?? ($drawer['gcash_payments'] ?? 0)),
            'expected_cash' => (float)($accuracy['expected_cash'] ?? ($drawer['expected_cash'] ?? 0)),
            'cash_declared' => (float)($accuracy['cash_declared'] ?? ($drawer['counted_cash'] ?? 0)),
            'difference' => (float)($accuracy['difference'] ?? 0),
            'status' => (string)($accuracy['status'] ?? ''),
        ];
    }
    return $out;
}

if ($m === 'GET') {
    // Keep archive in sync with closed-shift verifications (covers missed POS saves).
    try {
        $pdo->exec(
            "INSERT INTO shift_archives (staff_id, staff_name, shift_date, closed_at, snapshot_json)
             SELECT
                rv.staff_id,
                COALESCE(NULLIF(TRIM(u.full_name), ''), 'Staff'),
                rv.report_date,
                COALESCE(rv.verified_at, CONCAT(rv.report_date, ' 23:59:59')),
                JSON_OBJECT(
                    'source', 'revenue_verification_sync',
                    'notes', rv.notes,
                    'summary', JSON_OBJECT(
                        'total_orders', 0,
                        'total_revenue', rv.expected_revenue,
                        'cash_revenue', rv.expected_revenue,
                        'gcash_revenue', 0,
                        'discount_total', 0,
                        'waste_total', 0,
                        'coffee_sold_cups', 0
                    ),
                    'drawer', JSON_OBJECT(
                        'starting_money', 0,
                        'cash_payments', rv.expected_revenue,
                        'gcash_payments', 0,
                        'paid_in', 0,
                        'paid_out', 0,
                        'cash_refunds', 0,
                        'expected_cash', rv.expected_revenue,
                        'counted_cash', rv.cash_declared
                    ),
                    'accuracy', JSON_OBJECT(
                        'cash_declared', rv.cash_declared,
                        'expected_cash', rv.expected_revenue,
                        'difference', rv.difference,
                        'status', CASE
                            WHEN ABS(rv.difference) < 0.005 THEN 'accurate'
                            WHEN rv.difference > 0 THEN 'over'
                            ELSE 'short'
                        END
                    )
                )
             FROM revenue_verifications rv
             LEFT JOIN users u ON u.id = rv.staff_id
             WHERE NOT EXISTS (
                SELECT 1 FROM shift_archives sa
                 WHERE sa.staff_id <=> rv.staff_id
                   AND sa.shift_date = rv.report_date
                   AND DATE(sa.closed_at) = DATE(COALESCE(rv.verified_at, rv.report_date))
             )"
        );
    } catch (Throwable $e) {
        // Sync is best-effort; listing should still work.
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, staff_id, staff_name, shift_date, closed_at, snapshot_json, created_at
               FROM shift_archives
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            fail('Archive not found.', 404);
        }
        ok(['archive' => shift_archive_row($row, true, $pdo)]);
    }

    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }
    $staffId = (int)($_GET['staff_id'] ?? 0);
    $date = trim((string)($_GET['date'] ?? ''));

    $sql = 'SELECT id, staff_id, staff_name, shift_date, closed_at, snapshot_json, created_at
              FROM shift_archives
             WHERE 1=1';
    $params = [];
    if ($staffId > 0) {
        $sql .= ' AND staff_id = :sid';
        $params[':sid'] = $staffId;
    }
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $sql .= ' AND shift_date = :d';
        $params[':d'] = $date;
    }
    $sql .= ' ORDER BY closed_at DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $archives = [];
    foreach ($rows as $row) {
        $archives[] = shift_archive_row($row, false);
    }
    ok(['archives' => $archives]);
}

if ($m === 'POST') {
    $b = body();
    $staffId = (int)($b['staff_id'] ?? $sessionId);
    if ($sessionRole === 'staff') {
        $staffId = $sessionId;
    }
    if ($staffId <= 0) {
        $staffId = $sessionId ?: null;
    }

    $staffName = trim((string)($b['staff_name'] ?? ($_SESSION['user_name'] ?? 'Staff')));
    if ($staffName === '') {
        $staffName = 'Staff';
    }

    $shiftDate = trim((string)($b['shift_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate)) {
        $shiftDate = date('Y-m-d');
    }

    $closedAt = trim((string)($b['closed_at'] ?? ''));
    $closedTs = $closedAt !== '' ? strtotime($closedAt) : false;
    if ($closedTs === false) {
        $closedAt = date('Y-m-d H:i:s');
    } else {
        $closedAt = date('Y-m-d H:i:s', $closedTs);
    }

    $snapshot = $b;
    // Avoid nesting the whole request awkwardly; prefer explicit snapshot key.
    if (isset($b['snapshot']) && is_array($b['snapshot'])) {
        $snapshot = $b['snapshot'];
    } else {
        unset($snapshot['staff_id'], $snapshot['staff_name'], $snapshot['shift_date'], $snapshot['closed_at']);
    }

    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail('Could not encode shift archive snapshot.');
    }

    $ins = $pdo->prepare(
        'INSERT INTO shift_archives (staff_id, staff_name, shift_date, closed_at, snapshot_json)
         VALUES (:sid, :name, :d, :closed, :snap)'
    );
    $ins->execute([
        ':sid' => $staffId ?: null,
        ':name' => $staffName,
        ':d' => $shiftDate,
        ':closed' => $closedAt,
        ':snap' => $json,
    ]);

    $newId = (int)$pdo->lastInsertId();
    $fetch = $pdo->prepare(
        'SELECT id, staff_id, staff_name, shift_date, closed_at, snapshot_json, created_at
           FROM shift_archives
          WHERE id = :id
          LIMIT 1'
    );
    $fetch->execute([':id' => $newId]);
    ok([
        'message' => 'Shift archived.',
        'archive' => shift_archive_row($fetch->fetch() ?: null, true, $pdo),
    ], 201);
}

fail('Method not allowed.', 405);
