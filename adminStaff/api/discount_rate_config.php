<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity_log_helpers.php';
require_auth();

function ensure_discount_rate_config_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS discount_rate_config (
            id INT PRIMARY KEY,
            rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $exists = (int)$pdo->query('SELECT COUNT(*) FROM discount_rate_config WHERE id = 1')->fetchColumn();
    if ($exists === 0) {
        $pdo->exec('INSERT INTO discount_rate_config (id, rate) VALUES (1, 0)');
    }
}

$pdo = db();
ensure_discount_rate_config_schema($pdo);
$m = method();

if ($m === 'GET') {
    $rate = round((float)$pdo->query('SELECT rate FROM discount_rate_config WHERE id = 1 LIMIT 1')->fetchColumn(), 2);
    ok([
        'rate' => $rate > 0 ? $rate : null,
        'rate_value' => $rate,
    ]);
}

if ($m === 'PUT' || $m === 'POST') {
    $b = body();
    $rate = round((float)($b['rate'] ?? 0), 2);
    if ($rate < 0 || $rate > 100) {
        fail('Discount rate must be between 0 and 100.');
    }

    $stmt = $pdo->prepare('UPDATE discount_rate_config SET rate = :rate WHERE id = 1');
    $stmt->execute([':rate' => $rate]);

    log_system_activity($pdo, [
        'source_key' => 'set_discount_rate',
        'source_label' => 'Set Discount Rate',
        'action' => 'discount rate set to ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%',
    ]);

    ok([
        'message' => 'Discount rate saved.',
        'rate' => $rate > 0 ? $rate : null,
        'rate_value' => $rate,
    ]);
}

fail('Method not allowed.', 405);
