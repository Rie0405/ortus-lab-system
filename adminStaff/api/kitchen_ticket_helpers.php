<?php

/**
 * Station display ticket numbers (Order #1, #2, …) for Bar and Kitchen.
 * Separate counters; both reset each calendar day and on shift finalize.
 * Separate from orders.id / order_number (POS-/KIO-).
 */

function ensure_kitchen_ticket_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Migrate legacy single-row counter (id=1) → station_key counters if needed.
    $legacy = false;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM kitchen_ticket_counters');
        $colNames = [];
        if ($cols) {
            while ($c = $cols->fetch(PDO::FETCH_ASSOC)) {
                $colNames[] = strtolower((string)($c['Field'] ?? ''));
            }
        }
        if ($colNames && in_array('id', $colNames, true) && !in_array('station_key', $colNames, true)) {
            $legacy = true;
            $pdo->exec('RENAME TABLE kitchen_ticket_counters TO kitchen_ticket_counters_legacy');
        }
    } catch (Throwable $e) {
        // Table may not exist yet.
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS kitchen_ticket_counters (
            station_key VARCHAR(16) NOT NULL PRIMARY KEY,
            next_value INT NOT NULL DEFAULT 1,
            counter_date DATE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $today = date('Y-m-d');
    $pdo->exec(
        "INSERT IGNORE INTO kitchen_ticket_counters (station_key, next_value, counter_date) VALUES
            ('bar', 1, " . $pdo->quote($today) . "),
            ('kitchen', 1, " . $pdo->quote($today) . ")"
    );

    if ($legacy) {
        try {
            // Do not carry over the shared sequence — start both stations at 1 for today.
            $pdo->exec('DROP TABLE IF EXISTS kitchen_ticket_counters_legacy');
        } catch (Throwable $e) {
            // Ignore.
        }
    }

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

    $chkBar = $pdo->query("SHOW COLUMNS FROM orders LIKE 'bar_ticket_number'");
    if ($chkBar && !$chkBar->fetch()) {
        try {
            $pdo->exec(
                'ALTER TABLE orders
                 ADD COLUMN bar_ticket_number INT NULL DEFAULT NULL AFTER kitchen_ticket_number'
            );
        } catch (Throwable $e) {
            // Ignore.
        }
    }
}

/**
 * Match staff_dashboard station rules: beverages → bar, everything else → kitchen.
 */
function station_for_category_name(string $name): string
{
    $c = strtolower(trim($name));
    if ($c === '') {
        return 'kitchen';
    }
    if ($c === 'beverages' || $c === 'drinks' || $c === 'coffee') {
        return 'bar';
    }
    if (preg_match('/\bnon[\s-]*coffee\b/', $c) || $c === 'noncoffee' || $c === 'non coffee') {
        return 'bar';
    }
    if (strpos($c, 'frappe') !== false) {
        return 'bar';
    }
    if (preg_match('/\brefresher/', $c)) {
        return 'bar';
    }
    return 'kitchen';
}

/**
 * Which stations (bar / kitchen) are present for the given menu item IDs.
 *
 * @param int[] $menuItemIds
 * @return array{bar:bool,kitchen:bool}
 */
function stations_for_menu_item_ids(PDO $pdo, array $menuItemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $menuItemIds))));
    $out = ['bar' => false, 'kitchen' => false];
    if (!$ids) {
        // Fallback: treat as kitchen so the order still gets a display number.
        $out['kitchen'] = true;
        return $out;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(c.name, '') AS category_name
           FROM menu_items mi
           LEFT JOIN categories c ON c.id = mi.category_id
          WHERE mi.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        $out['kitchen'] = true;
        return $out;
    }

    foreach ($rows as $row) {
        $station = station_for_category_name((string)($row['category_name'] ?? ''));
        $out[$station] = true;
    }
    return $out;
}

/**
 * Allocate the next ticket for a station (bar|kitchen).
 * Auto-resets to 1 when the calendar day changes.
 * Call only inside an open DB transaction (uses row lock).
 */
function allocate_next_station_ticket(PDO $pdo, string $station): int
{
    ensure_kitchen_ticket_schema($pdo);

    $station = strtolower(trim($station)) === 'bar' ? 'bar' : 'kitchen';
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT next_value, counter_date FROM kitchen_ticket_counters WHERE station_key = :k FOR UPDATE'
    );
    $stmt->execute([':k' => $station]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO kitchen_ticket_counters (station_key, next_value, counter_date)
             VALUES (:k, 2, :d)'
        );
        $ins->execute([':k' => $station, ':d' => $today]);
        return 1;
    }

    $counterDate = (string)($row['counter_date'] ?? '');
    if ($counterDate !== $today) {
        $next = 1;
    } else {
        $next = (int)($row['next_value'] ?? 0);
        if ($next < 1) {
            $next = 1;
        }
    }

    $upd = $pdo->prepare(
        'UPDATE kitchen_ticket_counters
            SET next_value = :n, counter_date = :d
          WHERE station_key = :k'
    );
    $upd->execute([
        ':n' => $next + 1,
        ':d' => $today,
        ':k' => $station,
    ]);

    return $next;
}

/** @deprecated Use allocate_next_station_ticket($pdo, 'kitchen') */
function allocate_next_kitchen_ticket(PDO $pdo): int
{
    return allocate_next_station_ticket($pdo, 'kitchen');
}

/**
 * Assign station ticket(s) for an order based on its menu items.
 *
 * @param int[] $menuItemIds
 * @return array{bar:?int,kitchen:?int}
 */
function assign_station_tickets_to_order(PDO $pdo, int $orderId, array $menuItemIds = []): array
{
    $result = ['bar' => null, 'kitchen' => null];
    if ($orderId <= 0) {
        return $result;
    }

    ensure_kitchen_ticket_schema($pdo);

    // If menu IDs not passed, resolve from order_items (after insert).
    if (!$menuItemIds) {
        $stmt = $pdo->prepare('SELECT menu_item_id FROM order_items WHERE order_id = :oid');
        $stmt->execute([':oid' => $orderId]);
        $menuItemIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $stations = stations_for_menu_item_ids($pdo, $menuItemIds);

    if (!empty($stations['bar'])) {
        $result['bar'] = allocate_next_station_ticket($pdo, 'bar');
    }
    if (!empty($stations['kitchen'])) {
        $result['kitchen'] = allocate_next_station_ticket($pdo, 'kitchen');
    }

    // Always give at least one ticket so UI never falls back to raw DB id mid-shift.
    if ($result['bar'] === null && $result['kitchen'] === null) {
        $result['kitchen'] = allocate_next_station_ticket($pdo, 'kitchen');
    }

    $pdo->prepare(
        'UPDATE orders
            SET kitchen_ticket_number = :kt,
                bar_ticket_number = :bt
          WHERE id = :id'
    )->execute([
        ':kt' => $result['kitchen'],
        ':bt' => $result['bar'],
        ':id' => $orderId,
    ]);

    return $result;
}

/**
 * @deprecated Prefer assign_station_tickets_to_order with menu IDs.
 * Kept for older call sites — assigns kitchen ticket only.
 */
function assign_kitchen_ticket_to_order(PDO $pdo, int $orderId): int
{
    $tickets = assign_station_tickets_to_order($pdo, $orderId, []);
    if ($tickets['kitchen'] !== null) {
        return (int)$tickets['kitchen'];
    }
    if ($tickets['bar'] !== null) {
        return (int)$tickets['bar'];
    }
    return 0;
}

/** Reset both station sequences to 1 (shift finalize / SES confirm). */
function reset_kitchen_ticket_counter(PDO $pdo): void
{
    ensure_kitchen_ticket_schema($pdo);
    $today = date('Y-m-d');
    $pdo->exec(
        "INSERT INTO kitchen_ticket_counters (station_key, next_value, counter_date) VALUES
            ('bar', 1, " . $pdo->quote($today) . "),
            ('kitchen', 1, " . $pdo->quote($today) . ")
         ON DUPLICATE KEY UPDATE next_value = 1, counter_date = VALUES(counter_date)"
    );
}
