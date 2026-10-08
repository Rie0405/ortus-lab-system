<?php
// Temporary debug ingest for flicker investigation (session e46449).
header('Content-Type: application/json');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty body']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = ['message' => 'non-json', 'raw' => substr($raw, 0, 2000)];
}

$data['sessionId'] = $data['sessionId'] ?? 'e46449';
$data['timestamp'] = $data['timestamp'] ?? (int) round(microtime(true) * 1000);
$data['receivedAt'] = date('c');

$logPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'debug-e46449.log';
$line = json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
$ok = @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);

if ($ok === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write failed', 'path' => $logPath]);
    exit;
}

echo json_encode(['ok' => true, 'bytes' => $ok]);
