<?php
require_once __DIR__ . '/../../adminStaff/api/config.php';

$m = method();
if ($m !== 'GET') fail('Method not allowed.', 405);

$now = new DateTime('now');
ok([
    'server_time_iso' => $now->format(DateTime::ATOM),
    'server_hour_24' => (int)$now->format('G'),
]);

