<?php
require_once __DIR__ . '/config.php';
require_auth();

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$b = body();
$url = trim($b['url'] ?? '');

if ($url === '') {
    ok(['message' => 'No image to delete.']);
}

if (preg_match('#\.\./#', $url) || preg_match('#^https?://#i', $url)) {
    fail('Invalid image path.');
}

$normalized = str_replace('\\', '/', $url);
if (!preg_match('#^uploads/menu/[a-zA-Z0-9._-]+$#', $normalized)) {
    fail('Invalid image path.');
}

$uploadDir = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'menu');
if ($uploadDir === false) {
    fail('Upload directory not found.');
}

$filename = basename($normalized);
$filePath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
$realFile = realpath($filePath);

if ($realFile === false || !is_file($realFile) || strpos($realFile, $uploadDir) !== 0) {
    ok(['message' => 'Image already removed.']);
}

if (!unlink($realFile)) {
    fail('Could not delete image file.');
}

ok(['message' => 'Image deleted.']);
