<?php
require_once __DIR__ . '/config.php';
require_auth();

function ensure_gcash_config_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gcash_config (
            id INT PRIMARY KEY,
            gcash_number VARCHAR(80) NOT NULL,
            qr_mime VARCHAR(80) NULL,
            qr_image_b64 LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Single-row config (id=1).
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM gcash_config WHERE id = 1')->fetchColumn();
    if ($exists === 0) {
        $stmt = $pdo->prepare('INSERT INTO gcash_config (id, gcash_number, qr_mime, qr_image_b64) VALUES (1, :num, NULL, NULL)');
        $stmt->execute([':num' => '09XXXXXXXXXX']);
    }
}

$pdo = db();
ensure_gcash_config_schema($pdo);

$m = method();

if ($m === 'GET') {
    $stmt = $pdo->query('SELECT gcash_number, qr_mime, qr_image_b64 FROM gcash_config WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    $gcashNumber = (string)($row['gcash_number'] ?? '09XXXXXXXXXX');
    $qrMime = $row['qr_mime'] ?? null;
    $qrB64 = $row['qr_image_b64'] ?? null;

    $qrDataUrl = null;
    if (!empty($qrMime) && !empty($qrB64)) {
        $qrDataUrl = 'data:' . $qrMime . ';base64,' . $qrB64;
    }

    ok([
        'gcash_number' => $gcashNumber,
        'qr_data_url' => $qrDataUrl,
    ]);
}

if ($m !== 'POST') {
    fail('Method not allowed.', 405);
}

$gcashNumber = trim((string)($_POST['gcash_number'] ?? ($b = body())['gcash_number'] ?? ''));
if ($gcashNumber === '') fail('GCash number is required.');

$qrMime = null;
$qrB64 = null;

if (isset($_FILES['qr_image']) && is_array($_FILES['qr_image'])) {
    $f = $_FILES['qr_image'];
    if ($f['error'] === UPLOAD_ERR_OK && isset($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
        $maxBytes = 800 * 1024; // ~800KB to keep DB reasonably sized.
        if (!empty($f['size']) && (int)$f['size'] > $maxBytes) {
            fail('QR image is too large (max ~800KB).');
        }

        $tmp = (string)$f['tmp_name'];
        $mime = $f['type'] ?: (function () use ($tmp) {
            try {
                return mime_content_type($tmp);
            } catch (Throwable $e) {
                return '';
            }
        })();

        $allowedMimes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
        if (!in_array(strtolower($mime), $allowedMimes, true)) {
            fail('Invalid QR image type.');
        }

        $raw = file_get_contents($tmp);
        if ($raw === false || $raw === '') {
            fail('Could not read QR image.');
        }

        $qrMime = $mime;
        $qrB64 = base64_encode($raw);
    }
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'UPDATE gcash_config
         SET gcash_number = :num,
             qr_mime = :mime,
             qr_image_b64 = :b64
         WHERE id = 1'
    );

    $stmt->execute([
        ':num' => $gcashNumber,
        ':mime' => $qrMime,
        ':b64' => $qrB64,
    ]);
    $pdo->commit();

    ok(['message' => 'GCash kiosk config updated.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Failed to update config: ' . $e->getMessage(), 500);
}

