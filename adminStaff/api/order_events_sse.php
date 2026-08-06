<?php
require_once __DIR__ . '/config.php';

// Release session lock for long-lived SSE response.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$lastId = 0;
if (isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastId = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
} elseif (isset($_GET['last_id'])) {
    $lastId = (int) $_GET['last_id'];
}

echo "retry: 3000\n\n";
@ob_flush();
@flush();

$started = time();
$maxRunSeconds = 55; // allow client auto-reconnect
$statePath = realtime_event_state_path();

while (!connection_aborted() && (time() - $started) < $maxRunSeconds) {
    clearstatcache(true, $statePath);
    if (is_file($statePath)) {
        $raw = @file_get_contents($statePath);
        $evt = $raw ? json_decode($raw, true) : null;
        if (is_array($evt) && isset($evt['id']) && (int)$evt['id'] > $lastId) {
            $lastId = (int)$evt['id'];
            $data = json_encode($evt, JSON_UNESCAPED_SLASHES);
            echo "id: {$lastId}\n";
            echo "event: order_update\n";
            echo "data: {$data}\n\n";
            @ob_flush();
            @flush();
        }
    }

    echo ": ping\n\n";
    @ob_flush();
    @flush();
    sleep(2);
}

