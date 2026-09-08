<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';

function ensure_gcash_config_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gcash_config (
            id INT PRIMARY KEY,
            gcash_number VARCHAR(80) NOT NULL,
            gcash_name VARCHAR(160) NULL,
            qr_mime VARCHAR(80) NULL,
            qr_image_b64 LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    try {
        $chk = $pdo->query("SHOW COLUMNS FROM gcash_config LIKE 'gcash_name'");
        if (!$chk->fetch()) {
            $pdo->exec('ALTER TABLE gcash_config ADD COLUMN gcash_name VARCHAR(160) NULL AFTER gcash_number');
        }
    } catch (Throwable $e) {
        // Non-fatal if migration races.
    }

    $exists = (int)$pdo->query('SELECT COUNT(*) FROM gcash_config WHERE id = 1')->fetchColumn();
    if ($exists === 0) {
        $stmt = $pdo->prepare('INSERT INTO gcash_config (id, gcash_number, gcash_name, qr_mime, qr_image_b64) VALUES (1, :num, NULL, NULL, NULL)');
        $stmt->execute([':num' => '09XXXXXXXXXX']);
    }
}

$pdo = db();
ensure_gcash_config_schema($pdo);

if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$stmt = $pdo->query('SELECT gcash_number, gcash_name, qr_mime, qr_image_b64 FROM gcash_config WHERE id = 1 LIMIT 1');
$row = $stmt->fetch();

$gcashNumber = (string)($row['gcash_number'] ?? '09XXXXXXXXXX');
$gcashName = trim((string)($row['gcash_name'] ?? ''));
$qrMime = $row['qr_mime'] ?? null;
$qrB64 = $row['qr_image_b64'] ?? null;

$qrDataUrl = null;
if (!empty($qrMime) && !empty($qrB64)) {
    $qrDataUrl = 'data:' . $qrMime . ';base64,' . $qrB64;
}

ok([
    'gcash_number' => $gcashNumber,
    'gcash_name' => $gcashName,
    'qr_data_url' => $qrDataUrl,
]);

