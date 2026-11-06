<?php

function gcash_receipt_upload_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'gcash_receipts';
}

function gcash_receipt_url_is_valid(string $url): bool
{
    return (bool) preg_match('#^uploads/gcash_receipts/[a-zA-Z0-9._-]+\.(jpe?g|png|webp)$#i', $url);
}

function gcash_receipt_absolute_path(string $url): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $url);
}

function ensure_gcash_receipt_schema(PDO $pdo): void
{
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'gcash_receipt_url'");
        if (!$chk || !$chk->fetch()) {
            $pdo->exec(
                "ALTER TABLE orders
                 ADD COLUMN gcash_receipt_url VARCHAR(255) NULL DEFAULT NULL AFTER gcash_ref"
            );
        }
    } catch (Throwable $e) {
        // Non-fatal if the column already exists.
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'gcash_receipt_seen_at'");
        if (!$chk || !$chk->fetch()) {
            $pdo->exec(
                "ALTER TABLE orders
                 ADD COLUMN gcash_receipt_seen_at DATETIME NULL DEFAULT NULL AFTER gcash_receipt_url"
            );
        }
    } catch (Throwable $e) {
        // Non-fatal if the column already exists.
    }
}
