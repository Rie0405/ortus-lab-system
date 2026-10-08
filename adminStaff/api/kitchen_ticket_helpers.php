<?php

/**
 * Per-station display ticket numbers (Order #1, #2, …) keyed by main category.
 * Resets each calendar day and on shift finalize.
 * Separate from orders.id / order_number (POS-/KIO-).
 */

function ensure_kitchen_ticket_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Migrate legacy single-row counter (id=1) if present.
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM kitchen_ticket_counters');
        $colNames = [];
        if ($cols) {
            while ($c = $cols->fetch(PDO::FETCH_ASSOC)) {
                $colNames[] = strtolower((string)($c['Field'] ?? ''));
            }
        }
        if ($colNames && in_array('id', $colNames, true) && !in_array('station_key', $colNames, true)) {
            $pdo->exec('DROP TABLE IF EXISTS kitchen_ticket_counters_legacy');
            $pdo->exec('RENAME TABLE kitchen_ticket_counters TO kitchen_ticket_counters_legacy');
            $pdo->exec('DROP TABLE IF EXISTS kitchen_ticket_counters_legacy');
        }
    } catch (Throwable $e) {
        // Table may not exist yet.
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS kitchen_ticket_counters (
            station_key VARCHAR(32) NOT NULL PRIMARY KEY,
            next_value INT NOT NULL DEFAULT 1,
            counter_date DATE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_station_tickets (
            order_id INT NOT NULL,
            station_key VARCHAR(32) NOT NULL,
            main_category_id INT NULL DEFAULT NULL,
            ticket_number INT NOT NULL,
            PRIMARY KEY (order_id, station_key),
            KEY idx_ost_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'kitchen_ticket_number'");
    if ($chk && !$chk->fetch()) {
        try {
            $pdo->exec(
                'ALTER TABLE orders
                 ADD COLUMN kitchen_ticket_number INT NULL DEFAULT NULL AFTER order_number'
            );
        } catch (Throwable $e) {
            // Ignore.
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

    // Seed legacy bar/kitchen keys so old DBs keep working until first dynamic assign.
    $today = date('Y-m-d');
    $pdo->exec(
        "INSERT IGNORE INTO kitchen_ticket_counters (station_key, next_value, counter_date) VALUES
            ('bar', 1, " . $pdo->quote($today) . "),
            ('kitchen', 1, " . $pdo->quote($today) . "),
            ('mc_bar', 1, " . $pdo->quote($today) . "),
            ('mc_kitchen', 1, " . $pdo->quote($today) . ")"
    );
}

function station_key_for_main_category_id(int $mainCategoryId): string
{
    return 'mc_' . max(0, $mainCategoryId);
}

/**
 * Resolve stations present on an order from menu item main categories.
 *
 * @param int[] $menuItemIds
 * @return array<int, array{id:int,key:string,name:string}>
 */
function stations_for_menu_item_ids(PDO $pdo, array $menuItemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $menuItemIds))));
    if (!$ids) {
        return [];
    }

    if (function_exists('ensure_main_categories_schema')) {
        ensure_main_categories_schema($pdo);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT
                COALESCE(NULLIF(mi.main_category_id, 0), NULLIF(c.main_category_id, 0), 0) AS main_category_id,
                COALESCE(mc.name, mc_cat.name, '') AS main_category_name,
                COALESCE(c.name, '') AS category_name
           FROM menu_items mi
           LEFT JOIN categories c ON c.id = mi.category_id
           LEFT JOIN main_categories mc ON mc.id = mi.main_category_id AND mc.is_active = 1
           LEFT JOIN main_categories mc_cat ON mc_cat.id = c.main_category_id AND mc_cat.is_active = 1
          WHERE mi.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stations = [];
    foreach ($rows as $row) {
        // Route strictly by registered main_category_id (item → subcategory → main).
        // No keyword / name heuristics — those collapse distinct categories onto one station.
        $mcid = (int)($row['main_category_id'] ?? 0);
        if ($mcid <= 0) {
            continue;
        }
        $mcName = trim((string)($row['main_category_name'] ?? ''));
        if ($mcName === '') {
            try {
                $nameStmt = $pdo->prepare(
                    'SELECT name FROM main_categories WHERE id = :id LIMIT 1'
                );
                $nameStmt->execute([':id' => $mcid]);
                $mcName = trim((string)($nameStmt->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $mcName = '';
            }
        }
        $key = station_key_for_main_category_id($mcid);
        $stations[$key] = [
            'id' => $mcid,
            'key' => $key,
            'name' => $mcName !== '' ? $mcName : ('Station ' . $mcid),
        ];
    }

    return array_values($stations);
}

/**
 * Allocate next ticket for a station_key. Auto-resets when the calendar day changes.
 * Call inside an open DB transaction.
 */
function allocate_next_station_ticket(PDO $pdo, string $stationKey): int
{
    ensure_kitchen_ticket_schema($pdo);

    $stationKey = trim($stationKey);
    if ($stationKey === '') {
        $stationKey = 'kitchen';
    }
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT next_value, counter_date FROM kitchen_ticket_counters WHERE station_key = :k FOR UPDATE'
    );
    $stmt->execute([':k' => $stationKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO kitchen_ticket_counters (station_key, next_value, counter_date)
             VALUES (:k, 2, :d)'
        );
        $ins->execute([':k' => $stationKey, ':d' => $today]);
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
        ':k' => $stationKey,
    ]);

    return $next;
}

/** @deprecated */
function allocate_next_kitchen_ticket(PDO $pdo): int
{
    return allocate_next_station_ticket($pdo, 'kitchen');
}

/**
 * Assign station ticket(s) for an order based on menu item main categories.
 *
 * @param int[] $menuItemIds
 * @return array{tickets: array<string,int>, by_main_id: array<int,int>, bar:?int, kitchen:?int}
 */
function assign_station_tickets_to_order(PDO $pdo, int $orderId, array $menuItemIds = []): array
{
    $result = [
        'tickets' => [],
        'by_main_id' => [],
        'bar' => null,
        'kitchen' => null,
    ];
    if ($orderId <= 0) {
        return $result;
    }

    ensure_kitchen_ticket_schema($pdo);

    if (!$menuItemIds) {
        $stmt = $pdo->prepare('SELECT menu_item_id FROM order_items WHERE order_id = :oid');
        $stmt->execute([':oid' => $orderId]);
        $menuItemIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $stations = stations_for_menu_item_ids($pdo, $menuItemIds);

    // Drop stale station tickets when cart changes (e.g. returned order edit adds/removes stations).
    $keepKeys = [];
    foreach ($stations as $st) {
        $keepKeys[] = (string)$st['key'];
    }
    try {
        if ($keepKeys) {
            $placeholders = implode(',', array_fill(0, count($keepKeys), '?'));
            $del = $pdo->prepare(
                "DELETE FROM order_station_tickets
                  WHERE order_id = ?
                    AND station_key NOT IN ($placeholders)"
            );
            $del->execute(array_merge([$orderId], $keepKeys));
        } else {
            // No resolvable main categories — clear tickets rather than inventing a station.
            $pdo->prepare('DELETE FROM order_station_tickets WHERE order_id = :oid')
                ->execute([':oid' => $orderId]);
        }
    } catch (Throwable $e) {
        // Non-fatal if table missing during bootstrap.
    }

    if (!$stations) {
        $pdo->prepare(
            'UPDATE orders SET kitchen_ticket_number = NULL, bar_ticket_number = NULL WHERE id = :id'
        )->execute([':id' => $orderId]);
        return $result;
    }

    $barTicket = null;
    $kitchenTicket = null;

    $insOst = $pdo->prepare(
        'INSERT INTO order_station_tickets (order_id, station_key, main_category_id, ticket_number)
         VALUES (:oid, :sk, :mcid, :tn)
         ON DUPLICATE KEY UPDATE ticket_number = VALUES(ticket_number), main_category_id = VALUES(main_category_id)'
    );

    foreach ($stations as $st) {
        $key = (string)$st['key'];
        $mcid = (int)$st['id'];
        $nameLower = strtolower(trim((string)$st['name']));
        $ticket = allocate_next_station_ticket($pdo, $key);
        $result['tickets'][$key] = $ticket;
        $result['by_main_id'][$mcid] = $ticket;
        $insOst->execute([
            ':oid' => $orderId,
            ':sk' => $key,
            ':mcid' => $mcid,
            ':tn' => $ticket,
        ]);

        if ($nameLower === 'bar') {
            $barTicket = $ticket;
        }
        if ($nameLower === 'kitchen') {
            $kitchenTicket = $ticket;
        }
    }

    // Legacy columns: prefer named Bar/Kitchen; else first ticket for kitchen col.
    if ($barTicket === null && $kitchenTicket === null) {
        $first = reset($result['tickets']);
        $kitchenTicket = $first !== false ? (int)$first : null;
    }

    $result['bar'] = $barTicket;
    $result['kitchen'] = $kitchenTicket;

    $pdo->prepare(
        'UPDATE orders
            SET kitchen_ticket_number = :kt,
                bar_ticket_number = :bt
          WHERE id = :id'
    )->execute([
        ':kt' => $kitchenTicket,
        ':bt' => $barTicket,
        ':id' => $orderId,
    ]);

    return $result;
}

/** @deprecated Prefer assign_station_tickets_to_order */
function assign_kitchen_ticket_to_order(PDO $pdo, int $orderId): int
{
    $tickets = assign_station_tickets_to_order($pdo, $orderId, []);
    if ($tickets['kitchen'] !== null) {
        return (int)$tickets['kitchen'];
    }
    if ($tickets['bar'] !== null) {
        return (int)$tickets['bar'];
    }
    $vals = array_values($tickets['tickets']);
    return isset($vals[0]) ? (int)$vals[0] : 0;
}

/**
 * @param int[] $orderIds
 * @return array<int, array<string,int>> order_id => [station_key => ticket]
 */
function fetch_order_station_tickets_map(PDO $pdo, array $orderIds): array
{
    ensure_kitchen_ticket_schema($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT order_id, station_key, main_category_id, ticket_number
           FROM order_station_tickets
          WHERE order_id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$row['order_id'];
        if (!isset($map[$oid])) {
            $map[$oid] = [];
        }
        $map[$oid][(string)$row['station_key']] = (int)$row['ticket_number'];
        $mcid = (int)($row['main_category_id'] ?? 0);
        if ($mcid > 0) {
            $map[$oid]['id:' . $mcid] = (int)$row['ticket_number'];
        }
    }
    return $map;
}

/**
 * Main category IDs involved in an order (for station tab notify dots).
 *
 * @return list<int>
 */
function order_main_category_ids(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0) {
        return [];
    }
    ensure_kitchen_ticket_schema($pdo);
    $ids = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT main_category_id
               FROM order_station_tickets
              WHERE order_id = :oid
                AND main_category_id IS NOT NULL
                AND main_category_id > 0'
        );
        $stmt->execute([':oid' => $orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
    } catch (Throwable $e) {
        // Fall through to menu items lookup.
    }
    if ($ids) {
        return array_map('intval', array_keys($ids));
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT COALESCE(NULLIF(mi.main_category_id, 0), NULLIF(c.main_category_id, 0), 0) AS main_category_id
               FROM order_items oi
               INNER JOIN menu_items mi ON mi.id = oi.menu_item_id
               LEFT JOIN categories c ON c.id = mi.category_id
              WHERE oi.order_id = :oid'
        );
        $stmt->execute([':oid' => $orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return array_map('intval', array_keys($ids));
}

/** Reset all station sequences to 1 (shift finalize / SES confirm). */
function reset_kitchen_ticket_counter(PDO $pdo): void
{
    ensure_kitchen_ticket_schema($pdo);
    $today = date('Y-m-d');
    $pdo->exec(
        'UPDATE kitchen_ticket_counters
            SET next_value = 1, counter_date = ' . $pdo->quote($today)
    );
}
