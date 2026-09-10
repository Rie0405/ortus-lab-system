<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';

ensure_main_categories_schema(db());
$m = method();

if ($m === 'GET') {
    require_auth();
    ok(['main_categories' => fetch_active_main_categories(db())]);
}

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        fail('Main category name is required.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, name FROM main_categories
          WHERE LOWER(TRIM(name)) = LOWER(:name) AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $existing = $stmt->fetch();
    if ($existing) {
        ok(['id' => (int)$existing['id'], 'name' => (string)$existing['name'], 'message' => 'Main category already exists.'], 200);
    }

    $nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM main_categories')->fetchColumn();
    $ins = $pdo->prepare(
        'INSERT INTO main_categories (name, display_order, is_active)
         VALUES (:name, :ord, 1)'
    );
    $ins->execute([':name' => $name, ':ord' => $nextOrder]);
    $id = (int)$pdo->lastInsertId();
    ok(['id' => $id, 'name' => $name, 'message' => 'Main category created.'], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $name = trim((string)($b['name'] ?? ''));
    if (!$id) fail('Main category id is required.');
    if ($name === '') fail('Main category name is required.');

    $pdo = db();
    ensure_main_categories_schema($pdo);

    $row = $pdo->prepare('SELECT id, name FROM main_categories WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    if (!$row->fetch()) {
        fail('Main category not found.', 404);
    }

    $dup = $pdo->prepare(
        'SELECT id FROM main_categories
         WHERE LOWER(TRIM(name)) = LOWER(:name) AND is_active = 1 AND id <> :id
         LIMIT 1'
    );
    $dup->execute([':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another main category already uses that name.');
    }

    $upd = $pdo->prepare('UPDATE main_categories SET name = :name WHERE id = :id');
    $upd->execute([':name' => $name, ':id' => $id]);
    ok(['id' => $id, 'name' => $name, 'message' => 'Main category updated.']);
}

if ($m === 'DELETE') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? ($_GET['id'] ?? 0));
    if (!$id) fail('Main category id is required.');

    $pdo = db();
    ensure_main_categories_schema($pdo);

    $row = $pdo->prepare('SELECT id, name FROM main_categories WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    $cat = $row->fetch();
    if (!$cat) {
        fail('Main category not found.', 404);
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM menu_items WHERE main_category_id = :id');
    $inUse->execute([':id' => $id]);
    if ((int)$inUse->fetchColumn() > 0) {
        fail('Cannot delete a main category that still has menu items.');
    }

    $upd = $pdo->prepare('UPDATE main_categories SET is_active = 0 WHERE id = :id');
    $upd->execute([':id' => $id]);
    ok(['id' => $id, 'message' => 'Main category deleted.']);
}

fail('Method not allowed', 405);
