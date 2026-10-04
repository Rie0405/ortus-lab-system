<?php
/**
 * System activity / change history for the admin notification feed.
 */

function ensure_activity_log_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_activity_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            source_key VARCHAR(64) NOT NULL,
            source_label VARCHAR(120) NOT NULL,
            action_text VARCHAR(255) NOT NULL,
            actor_user_id INT NULL,
            actor_name VARCHAR(140) NOT NULL DEFAULT \'Unknown\',
            actor_role VARCHAR(40) NOT NULL DEFAULT \'\',
            entity_type VARCHAR(64) NULL,
            entity_id INT NULL,
            meta_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_created (created_at),
            INDEX idx_activity_source (source_key),
            INDEX idx_activity_actor (actor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_activity_log_reads (
            user_id INT NOT NULL PRIMARY KEY,
            last_seen_id BIGINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function activity_actor_from_session(?array $user = null): array
{
    if ($user === null) {
        $user = [
            'id' => $_SESSION['user_id'] ?? null,
            'name' => $_SESSION['user_name'] ?? '',
            'role' => $_SESSION['user_role'] ?? '',
        ];
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    $name = trim((string)($user['name'] ?? ''));
    if ($name === '') {
        $name = $role !== '' ? ucfirst($role) : 'Unknown';
    }

    return [
        'id' => isset($user['id']) && (int)$user['id'] > 0 ? (int)$user['id'] : null,
        'name' => $name,
        'role' => $role,
    ];
}

/**
 * Best-effort write. Never throws to callers.
 *
 * @param array{
 *   source_key:string,
 *   source_label:string,
 *   action:string,
 *   user?:array|null,
 *   entity_type?:string|null,
 *   entity_id?:int|null,
 *   meta?:array|null
 * } $payload
 */
function log_system_activity(PDO $pdo, array $payload): void
{
    try {
        ensure_activity_log_schema($pdo);

        $sourceKey = trim((string)($payload['source_key'] ?? ''));
        $sourceLabel = trim((string)($payload['source_label'] ?? ''));
        $action = trim((string)($payload['action'] ?? ''));
        if ($sourceKey === '' || $sourceLabel === '' || $action === '') {
            return;
        }

        $actor = activity_actor_from_session(isset($payload['user']) && is_array($payload['user']) ? $payload['user'] : null);
        $meta = $payload['meta'] ?? null;
        $metaJson = null;
        if (is_array($meta) && $meta) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO system_activity_log
                (source_key, source_label, action_text, actor_user_id, actor_name, actor_role, entity_type, entity_id, meta_json)
             VALUES
                (:source_key, :source_label, :action_text, :actor_user_id, :actor_name, :actor_role, :entity_type, :entity_id, :meta_json)'
        );
        $stmt->execute([
            ':source_key' => substr($sourceKey, 0, 64),
            ':source_label' => substr($sourceLabel, 0, 120),
            ':action_text' => substr($action, 0, 255),
            ':actor_user_id' => $actor['id'],
            ':actor_name' => substr($actor['name'], 0, 140),
            ':actor_role' => substr($actor['role'], 0, 40),
            ':entity_type' => isset($payload['entity_type']) ? substr((string)$payload['entity_type'], 0, 64) : null,
            ':entity_id' => isset($payload['entity_id']) && (int)$payload['entity_id'] > 0
                ? (int)$payload['entity_id']
                : null,
            ':meta_json' => $metaJson,
        ]);
    } catch (Throwable $e) {
        // Activity logging must never break the main request.
    }
}

function activity_display_actor(array $row): string
{
    $name = trim((string)($row['actor_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $role = trim((string)($row['actor_role'] ?? ''));
    return $role !== '' ? ucfirst($role) : 'Unknown';
}

/** Format qty+unit like "8pcs" or "1.5kg". */
function activity_format_qty_unit($qty, string $unit = 'pcs'): string
{
    $n = (float)$qty;
    if (!is_finite($n)) {
        $n = 0.0;
    }
    if (abs($n - round($n)) < 0.0001) {
        $qtyText = (string)(int)round($n);
    } else {
        $qtyText = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
    $unitText = strtolower(trim($unit));
    if ($unitText === '') {
        $unitText = 'pcs';
    }
    return $qtyText . $unitText;
}

function activity_actor_from_restock_supplier(string $supplier, ?array $fallbackUser = null): array
{
    $supplier = trim($supplier);
    if (stripos($supplier, 'Restocked by ') === 0) {
        $name = trim(substr($supplier, strlen('Restocked by ')));
        if ($name !== '') {
            return [
                'id' => null,
                'name' => $name,
                'role' => 'staff',
            ];
        }
    }
    return activity_actor_from_session($fallbackUser);
}

function activity_format_line(array $row): string
{
    $source = trim((string)($row['source_label'] ?? 'System'));
    $actor = activity_display_actor($row);
    $action = trim((string)($row['action_text'] ?? 'updated'));
    return $source . ' - ' . $actor . ' - ' . $action;
}

function activity_last_seen_id(PDO $pdo, int $userId): int
{
    ensure_activity_log_schema($pdo);
    if ($userId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT last_seen_id FROM system_activity_log_reads WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    return max(0, (int)$stmt->fetchColumn());
}

function activity_mark_seen(PDO $pdo, int $userId, int $lastSeenId): void
{
    ensure_activity_log_schema($pdo);
    if ($userId <= 0 || $lastSeenId <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO system_activity_log_reads (user_id, last_seen_id)
         VALUES (:uid, :seen)
         ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id))'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':seen' => $lastSeenId,
    ]);
}

/**
 * @return array{entries:list<array>, unread_count:int, last_seen_id:int, has_more:bool}
 */
function fetch_activity_feed(PDO $pdo, int $userId, int $limit = 15, int $beforeId = 0): array
{
    ensure_activity_log_schema($pdo);
    $limit = max(1, min(50, $limit));
    $lastSeen = activity_last_seen_id($pdo, $userId);

    $params = [];
    $sql = 'SELECT id, source_key, source_label, action_text, actor_user_id, actor_name, actor_role,
                   entity_type, entity_id, created_at
            FROM system_activity_log';
    if ($beforeId > 0) {
        $sql .= ' WHERE id < :before_id';
        $params[':before_id'] = $beforeId;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . ((int)$limit + 1);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }

    $entries = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $entries[] = [
            'id' => $id,
            'source_key' => (string)$row['source_key'],
            'source_label' => (string)$row['source_label'],
            'action' => (string)$row['action_text'],
            'actor_name' => activity_display_actor($row),
            'actor_role' => (string)$row['actor_role'],
            'entity_type' => $row['entity_type'] !== null ? (string)$row['entity_type'] : null,
            'entity_id' => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
            'created_at' => (string)$row['created_at'],
            'is_unread' => $id > $lastSeen,
            'display' => activity_format_line($row),
        ];
    }

    $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM system_activity_log WHERE id > :seen');
    $unreadStmt->execute([':seen' => $lastSeen]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    return [
        'entries' => $entries,
        'unread_count' => $unreadCount,
        'last_seen_id' => $lastSeen,
        'has_more' => $hasMore,
    ];
}
