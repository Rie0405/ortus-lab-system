<?php

/**
 * Human-readable order numbers: POS-MMDDYY-001 / KIO-MMDDYY-001
 * Sequence resets when a shift is finalized (same moment as kitchen tickets).
 */

function ensure_order_number_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_number_counters (
            source_key VARCHAR(10) NOT NULL PRIMARY KEY,
            next_value INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        "INSERT IGNORE INTO order_number_counters (source_key, next_value) VALUES
            ('pos', 1),
            ('kiosk', 1)"
    );
}

function order_number_source_key(string $orderSource): string
{
    $src = strtolower(trim($orderSource));
    return $src === 'kiosk' ? 'kiosk' : 'pos';
}

function order_number_prefix(string $orderSource): string
{
    return order_number_source_key($orderSource) === 'kiosk' ? 'KIO' : 'POS';
}

/**
 * Allocate next order number for the current shift.
 * Call only inside an open DB transaction (uses row lock).
 * Caller must run ensure_order_number_schema() BEFORE beginTransaction() —
 * DDL inside a txn causes MySQL implicit commit.
 */
function allocate_next_order_number(PDO $pdo, string $orderSource): string
{
    // Schema should already exist; keep as safety no-op when static $done is set.
    ensure_order_number_schema($pdo);

    $sourceKey = order_number_source_key($orderSource);
    $prefix = order_number_prefix($orderSource);
    $datePart = date('mdy');

    $stmt = $pdo->prepare(
        'SELECT next_value FROM order_number_counters WHERE source_key = :k FOR UPDATE'
    );
    $stmt->execute([':k' => $sourceKey]);
    $next = (int)$stmt->fetchColumn();
    if ($next < 1) {
        $next = 1;
    }

    $existsStmt = $pdo->prepare(
        'SELECT 1 FROM orders WHERE order_number = :num LIMIT 1'
    );

    // Skip collisions (e.g. second shift same calendar day after reset).
    $seq = $next;
    do {
        $orderNumber = $prefix . '-' . $datePart . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $existsStmt->execute([':num' => $orderNumber]);
        $taken = (bool)$existsStmt->fetchColumn();
        if (!$taken) {
            break;
        }
        $seq++;
        if ($seq > 9999) {
            // Extremely unlikely; fall back to unique suffix.
            $orderNumber = $prefix . '-' . $datePart . '-' . strtoupper(substr(uniqid(), -6));
            break;
        }
    } while (true);

    $upd = $pdo->prepare(
        'UPDATE order_number_counters SET next_value = :n WHERE source_key = :k'
    );
    $upd->execute([
        ':n' => $seq + 1,
        ':k' => $sourceKey,
    ]);

    return $orderNumber;
}

/** Reset POS + KIO sequences after shift finalize / SES confirm. */
function reset_order_number_counters(PDO $pdo): void
{
    ensure_order_number_schema($pdo);
    $pdo->exec(
        "INSERT INTO order_number_counters (source_key, next_value) VALUES
            ('pos', 1),
            ('kiosk', 1)
         ON DUPLICATE KEY UPDATE next_value = 1"
    );
}
