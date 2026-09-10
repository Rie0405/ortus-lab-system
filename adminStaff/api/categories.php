<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';

ensure_catalog_icon_columns(db());

$m = method();

function normalize_catalog_icon_url($raw): ?string
{
    $url = trim((string)$raw);
    if ($url === '') {
        return null;
    }
    $normalized = str_replace('\\', '/', $url);
    if (preg_match('#\.\./#', $normalized) || preg_match('#^https?://#i', $normalized)) {
        fail('Invalid icon path.');
    }
    if (!preg_match('#^uploads/menu/[a-zA-Z0-9._-]+$#', $normalized)) {
        fail('Invalid icon path.');
    }
    return $normalized;
}

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    $isActive = isset($b['is_active']) ? (int)(bool)$b['is_active'] : 1;
    $iconUrl = array_key_exists('icon_url', $b) ? normalize_catalog_icon_url($b['icon_url']) : null;

    if ($name === '') fail('Category name is required.');

    // Check duplicates (case-insensitive match).
    $stmt = db()->prepare(
        'SELECT id, name FROM categories
         WHERE LOWER(name) = LOWER(:name) AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $existing = $stmt->fetch();
    if ($existing) {
        ok(['id' => (int)$existing['id'], 'message' => 'Category already exists.'], 200);
    }

    // Insert with next display_order.
    $nextOrder = (int)(db()->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM categories')->fetchColumn());

    $ins = db()->prepare(
        'INSERT INTO categories (name, icon_url, display_order, is_active)
         VALUES (:name, :icon, :ord, :act)'
    );
    $ins->execute([
        ':name' => $name,
        ':icon' => $iconUrl,
        ':ord'  => $nextOrder,
        ':act'  => $isActive,
    ]);

    $id = (int)db()->lastInsertId();
    ok(['id' => $id, 'message' => 'Category created.'], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Category id is required.');

    $pdo = db();
    $row = $pdo->prepare('SELECT id, name, icon_url FROM categories WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    $cat = $row->fetch();
    if (!$cat) {
        fail('Category not found.', 404);
    }

    $hasName = array_key_exists('name', $b);
    $hasIcon = array_key_exists('icon_url', $b);
    if (!$hasName && !$hasIcon) {
        fail('Nothing to update.');
    }

    $name = $hasName ? trim((string)$b['name']) : (string)$cat['name'];
    if ($name === '') fail('Category name is required.');

    $dup = $pdo->prepare(
        'SELECT id FROM categories
         WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND id <> :id
         LIMIT 1'
    );
    $dup->execute([':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another category already uses that name.');
    }

    $iconUrl = $hasIcon
        ? normalize_catalog_icon_url($b['icon_url'])
        : (isset($cat['icon_url']) && $cat['icon_url'] !== null && $cat['icon_url'] !== ''
            ? (string)$cat['icon_url']
            : null);

    $upd = $pdo->prepare('UPDATE categories SET name = :name, icon_url = :icon WHERE id = :id');
    $upd->execute([':name' => $name, ':icon' => $iconUrl, ':id' => $id]);
    ok([
        'id' => $id,
        'icon_url' => $iconUrl,
        'message' => 'Category updated.',
    ]);
}

if ($m === 'DELETE') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Category id is required.');

    $pdo = db();
    $row = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    $cat = $row->fetch();
    if (!$cat) {
        fail('Category not found.', 404);
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM menu_items WHERE category_id = :id');
    $inUse->execute([':id' => $id]);
    if ((int)$inUse->fetchColumn() > 0) {
        fail('Cannot delete a category that still has menu items.');
    }

    $upd = $pdo->prepare('UPDATE categories SET is_active = 0 WHERE id = :id');
    $upd->execute([':id' => $id]);
    ok(['id' => $id, 'message' => 'Category deleted.']);
}

fail('Method not allowed.', 405);
