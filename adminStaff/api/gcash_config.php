<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity_log_helpers.php';
require_auth();

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

    // Single-row config (id=1).
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM gcash_config WHERE id = 1')->fetchColumn();
    if ($exists === 0) {
        $stmt = $pdo->prepare('INSERT INTO gcash_config (id, gcash_number, gcash_name, qr_mime, qr_image_b64) VALUES (1, :num, NULL, NULL, NULL)');
        $stmt->execute([':num' => '09XXXXXXXXXX']);
    }
}

$pdo = db();
ensure_gcash_config_schema($pdo);

$m = method();

if ($m === 'GET') {
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
}

if ($m !== 'POST') {
    fail('Method not allowed.', 405);
}

$gcashNumber = trim((string)($_POST['gcash_number'] ?? ''));
if ($gcashNumber === '' && empty($_FILES) && empty($_POST)) {
    $b = body();
    $gcashNumber = trim((string)($b['gcash_number'] ?? ''));
} else {
    $b = [];
}
if ($gcashNumber === '') fail('GCash number is required.');
if (strlen($gcashNumber) > 11) fail('GCash number must be at most 11 characters.');
$gcashNumber = substr($gcashNumber, 0, 11);
$gcashNameRaw = trim((string)($_POST['gcash_name'] ?? $b['gcash_name'] ?? ''));
if ($gcashNameRaw === '') fail('GCash name is required.');
$gcashName = substr($gcashNameRaw, 0, 160);
$clearQr = !empty($_POST['clear_qr']) || !empty($b['clear_qr']);

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
        $clearQr = false;
    }
}

if ($clearQr && !($qrMime && $qrB64)) {
    fail('GCash QR image is required.');
}

if (!($qrMime && $qrB64)) {
    $existingQr = $pdo->query('SELECT qr_mime, qr_image_b64 FROM gcash_config WHERE id = 1 LIMIT 1')->fetch();
    $hasExistingQr = !empty($existingQr['qr_mime']) && !empty($existingQr['qr_image_b64']);
    if (!$hasExistingQr) {
        fail('GCash QR image is required.');
    }
}

try {
    $pdo->beginTransaction();
    if ($qrMime && $qrB64) {
        $stmt = $pdo->prepare(
            'UPDATE gcash_config
             SET gcash_number = :num,
                 gcash_name = :name,
                 qr_mime = :mime,
                 qr_image_b64 = :b64
             WHERE id = 1'
        );
        $stmt->execute([
            ':num' => $gcashNumber,
            ':name' => $gcashName,
            ':mime' => $qrMime,
            ':b64' => $qrB64,
        ]);
    } elseif ($clearQr) {
        $stmt = $pdo->prepare(
            'UPDATE gcash_config
             SET gcash_number = :num,
                 gcash_name = :name,
                 qr_mime = NULL,
                 qr_image_b64 = NULL
             WHERE id = 1'
        );
        $stmt->execute([
            ':num' => $gcashNumber,
            ':name' => $gcashName,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE gcash_config
             SET gcash_number = :num,
                 gcash_name = :name
             WHERE id = 1'
        );
        $stmt->execute([
            ':num' => $gcashNumber,
            ':name' => $gcashName,
        ]);
    }
    $pdo->commit();

    log_system_activity($pdo, [
        'source_key' => 'set_gcash',
        'source_label' => 'Set GCash',
        'action' => 'GCash settings updated',
    ]);

    ok(['message' => 'GCash kiosk config updated.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Failed to update config: ' . $e->getMessage(), 500);
}

