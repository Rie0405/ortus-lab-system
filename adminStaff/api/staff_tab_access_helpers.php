<?php

function ensure_user_tab_access_column(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM users LIKE 'tab_access'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN tab_access TEXT NULL');
        }
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function decode_user_tab_access($raw): ?array
{
    if ($raw === null) {
        return null;
    }
    $text = trim((string)$raw);
    if ($text === '') {
        return null;
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return null;
    }
    $keys = [];
    foreach ($decoded as $key) {
        $key = strtolower(trim((string)$key));
        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $key)) {
            continue;
        }
        $keys[$key] = $key;
    }
    return array_values($keys);
}

function user_tab_access_for_client(string $role, $raw): ?array
{
    if (strtolower(trim($role)) === 'admin') {
        return null;
    }
    return decode_user_tab_access($raw);
}

function normalize_tab_access_input($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_array($value)) {
        return null;
    }
    $keys = decode_user_tab_access(json_encode(array_values($value)));
    if ($keys === null) {
        $keys = [];
    }
    return json_encode(array_slice($keys, 0, 50));
}
