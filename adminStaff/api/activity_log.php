<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity_log_helpers.php';

$user = require_auth();
$pdo = db();
ensure_activity_log_schema($pdo);
$m = method();

if ($m === 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
    $beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
    $feed = fetch_activity_feed($pdo, (int)$user['id'], $limit, $beforeId);
    ok($feed);
}

if ($m === 'POST' || $m === 'PUT') {
    $b = body();
    $action = strtolower(trim((string)($b['action'] ?? 'mark_read')));
    if ($action !== 'mark_read') {
        fail('Unknown action.');
    }

    $lastSeenId = (int)($b['last_seen_id'] ?? 0);
    if ($lastSeenId <= 0) {
        // Mark everything currently in the table as read.
        $lastSeenId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM system_activity_log')->fetchColumn();
    }
    activity_mark_seen($pdo, (int)$user['id'], $lastSeenId);
    $feed = fetch_activity_feed($pdo, (int)$user['id'], 15, 0);
    ok([
        'message' => 'Activity feed marked as read.',
        'unread_count' => $feed['unread_count'],
        'last_seen_id' => $feed['last_seen_id'],
    ]);
}

fail('Method not allowed.', 405);
