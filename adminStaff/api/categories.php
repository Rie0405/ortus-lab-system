<?php
require_once __DIR__ . '/config.php';

$m = method();

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    $isActive = isset($b['is_active']) ? (int)(bool)$b['is_active'] : 1;

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
        'INSERT INTO categories (name, display_order, is_active)
         VALUES (:name, :ord, :act)'
    );
    $ins->execute([
        ':name' => $name,
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
    $name = trim((string)($b['name'] ?? ''));
    if (!$id) fail('Category id is required.');
    if ($name === '') fail('Category name is required.');

    $pdo = db();
    $row = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    if (!$row->fetch()) {
        fail('Category not found.', 404);
    }

    $dup = $pdo->prepare(
        'SELECT id FROM categories
         WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND id <> :id
         LIMIT 1'
    );
    $dup->execute([':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another category already uses that name.');
    }

    $upd = $pdo->prepare('UPDATE categories SET name = :name WHERE id = :id');
    $upd->execute([':name' => $name, ':id' => $id]);
    ok(['id' => $id, 'message' => 'Category updated.']);
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

