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

fail('Method not allowed', 405);
