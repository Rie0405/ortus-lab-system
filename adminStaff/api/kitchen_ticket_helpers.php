<?php

/**
 * Kitchen display ticket numbers (Order #1, #2, …) that reset each shift finalize.
 * Separate from orders.id / order_number (GC-/CU-).
 */

function ensure_kitchen_ticket_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS kitchen_ticket_counters (
            id TINYINT NOT NULL PRIMARY KEY,
            next_value INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'INSERT IGNORE INTO kitchen_ticket_counters (id, next_value) VALUES (1, 1)'
    );

    $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'kitchen_ticket_number'");
    if ($chk && !$chk->fetch()) {
        try {
            $pdo->exec(
                'ALTER TABLE orders
                 ADD COLUMN kitchen_ticket_number INT NULL DEFAULT NULL AFTER order_number'
            );
        } catch (Throwable $e) {
            // Ignore migration issues on restricted environments.
        }
    }
}

/**
 * Allocate the next kitchen ticket for the current shift.
 * Call only inside an open DB transaction (uses row lock).
 */
function allocate_next_kitchen_ticket(PDO $pdo): int
{
    ensure_kitchen_ticket_schema($pdo);

    $stmt = $pdo->query('SELECT next_value FROM kitchen_ticket_counters WHERE id = 1 FOR UPDATE');
    $next = (int)($stmt ? $stmt->fetchColumn() : 0);
    if ($next < 1) {
        $next = 1;
    }

    $upd = $pdo->prepare('UPDATE kitchen_ticket_counters SET next_value = :n WHERE id = 1');
    $upd->execute([':n' => $next + 1]);

    return $next;
}

function assign_kitchen_ticket_to_order(PDO $pdo, int $orderId): int
{
    if ($orderId <= 0) {
        return 0;
    }
    $ticket = allocate_next_kitchen_ticket($pdo);
    $pdo->prepare(
        'UPDATE orders SET kitchen_ticket_number = :t WHERE id = :id'
    )->execute([
        ':t' => $ticket,
        ':id' => $orderId,
    ]);
    return $ticket;
}

/** Reset sequence to 1 after shift finalize / SES confirm. */
function reset_kitchen_ticket_counter(PDO $pdo): void
{
    ensure_kitchen_ticket_schema($pdo);
    $pdo->exec(
        'INSERT INTO kitchen_ticket_counters (id, next_value) VALUES (1, 1)
         ON DUPLICATE KEY UPDATE next_value = 1'
    );
}
