<?php
require_once __DIR__ . '/config.php';
require_auth();

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    fail('No image file uploaded.');
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail('Image upload failed.');
}
if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    fail('Invalid upload.');
}

$maxBytes = 5 * 1024 * 1024; // 5MB
$size = (int)($file['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
    fail('Image must be PNG/JPG up to 5MB.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']) ?: '';
$allowed = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    fail('Only PNG, JPG, or WEBP images are allowed.');
}

$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'menu';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    fail('Could not create upload directory.');
}

$filename = 'menu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
$dest = $uploadDir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    fail('Could not save uploaded image.');
}

// Path relative to adminStaff/ — works in admin/staff UI.
$url = 'uploads/menu/' . $filename;

ok([
    'url' => $url,
    'mime' => $mime,
    'message' => 'Image uploaded.',
]);
