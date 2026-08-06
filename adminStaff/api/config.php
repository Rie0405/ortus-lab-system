<?php
// ─── Database Configuration ───────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3308');
define('DB_NAME', 'ortus_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// ─── Session ─────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ─── CORS (same-origin during dev) ───────────────────────────────────────────
if (!defined('ORTUS_SKIP_JSON_HEADER') || !ORTUS_SKIP_JSON_HEADER) {
    header('Content-Type: application/json; charset=utf-8');
}
header('X-Content-Type-Options: nosniff');

// ─── PDO connection (lazy singleton) ─────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(503);
            echo json_encode(['success' => false, 'error' => 'Database unavailable: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// ─── Response helpers ─────────────────────────────────────────────────────────
function ok(array $data = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

// ─── Auth guard (include in protected endpoints) ──────────────────────────────
function require_auth(string $role = ''): array {
    if (empty($_SESSION['user_id'])) {
        fail('Unauthenticated', 401);
    }
    if ($role && strcasecmp((string)($_SESSION['user_role'] ?? ''), $role) !== 0) {
        fail('Forbidden', 403);
    }
    return [
        'id'   => $_SESSION['user_id'],
        'role' => $_SESSION['user_role'] ?? '',
        'name' => $_SESSION['user_name'] ?? '',
    ];
}

// ─── Realtime order events (SSE helper) ───────────────────────────────────────
function realtime_events_dir(): string {
    return __DIR__ . '/../runtime';
}

function realtime_event_state_path(): string {
    return realtime_events_dir() . '/order_events_state.json';
}

/**
 * Persist a lightweight realtime event snapshot for SSE consumers.
 * The latest event is enough because clients immediately refetch full state.
 */
function publish_realtime_event(string $type, array $payload = []): void {
    try {
        $dir = realtime_events_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $event = [
            'id' => (int) floor(microtime(true) * 1000),
            'type' => $type,
            'ts' => gmdate('c'),
            'payload' => $payload,
        ];
        @file_put_contents(realtime_event_state_path(), json_encode($event, JSON_UNESCAPED_SLASHES), LOCK_EX);
    } catch (Throwable $e) {
        // Realtime notifications are best-effort and must not break core flows.
    }
}
