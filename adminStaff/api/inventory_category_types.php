<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recipe_helpers.php';

$m = method();
$pdo = db();
ensure_inventory_category_types_schema($pdo);

if ($m === 'GET') {
    require_auth();
    ok(['category_types' => fetch_active_inventory_category_types($pdo)]);
}

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        fail('Category type name is required.');
    }
    $name = substr($name, 0, 80);
    $slug = slugify_inventory_category_type($name);
    if ($slug === '') {
        fail('Category type name is invalid.');
    }

    $find = $pdo->prepare(
        'SELECT id, name, is_active FROM inventory_category_types
          WHERE LOWER(slug) = LOWER(:slug) OR LOWER(name) = LOWER(:name)
          LIMIT 1'
    );
    $find->execute([':slug' => $slug, ':name' => $name]);
    $existing = $find->fetch();
    if ($existing) {
        if (!(int)$existing['is_active']) {
            $ord = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM inventory_category_types')->fetchColumn();
            $pdo->prepare(
                'UPDATE inventory_category_types
                    SET is_active = 1, name = :name, display_order = :ord
                  WHERE id = :id'
            )->execute([
                ':name' => $name,
                ':ord' => $ord,
                ':id' => (int)$existing['id'],
            ]);
            ok([
                'id' => (int)$existing['id'],
                'message' => 'Category type restored.',
                'category_types' => fetch_active_inventory_category_types($pdo),
            ]);
        }
        ok([
            'id' => (int)$existing['id'],
            'message' => 'Category type already exists.',
            'category_types' => fetch_active_inventory_category_types($pdo),
        ]);
    }

    $ord = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM inventory_category_types')->fetchColumn();
    $ins = $pdo->prepare(
        'INSERT INTO inventory_category_types (slug, name, display_order, is_active)
         VALUES (:slug, :name, :ord, 1)'
    );
    $ins->execute([
        ':slug' => $slug,
        ':name' => $name,
        ':ord' => $ord,
    ]);

    ok([
        'id' => (int)$pdo->lastInsertId(),
        'message' => 'Category type created.',
        'category_types' => fetch_active_inventory_category_types($pdo),
    ], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $name = trim((string)($b['name'] ?? ''));
    if (!$id) {
        fail('Category type id is required.');
    }
    if ($name === '') {
        fail('Category type name is required.');
    }
    $name = substr($name, 0, 80);

    $cur = $pdo->prepare('SELECT id, slug, name FROM inventory_category_types WHERE id = :id AND is_active = 1');
    $cur->execute([':id' => $id]);
    $row = $cur->fetch();
    if (!$row) {
        fail('Category type not found.', 404);
    }

    $dup = $pdo->prepare(
        'SELECT id FROM inventory_category_types
          WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND id <> :id
          LIMIT 1'
    );
    $dup->execute([':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another category type already uses that name.');
    }

    // Keep slug stable so existing inventory_items.category_type values stay valid.
    $pdo->prepare('UPDATE inventory_category_types SET name = :name WHERE id = :id')
        ->execute([':name' => $name, ':id' => $id]);

    ok([
        'id' => $id,
        'message' => 'Category type updated.',
        'category_types' => fetch_active_inventory_category_types($pdo),
    ]);
}

if ($m === 'DELETE') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) {
        fail('Category type id is required.');
    }

    $cur = $pdo->prepare('SELECT id, slug, name FROM inventory_category_types WHERE id = :id AND is_active = 1');
    $cur->execute([':id' => $id]);
    $row = $cur->fetch();
    if (!$row) {
        fail('Category type not found.', 404);
    }

    $slug = (string)$row['slug'];
    if ($slug === 'main') {
        fail('The Main category type cannot be deleted.');
    }

    $inUse = $pdo->prepare(
        'SELECT COUNT(*) FROM inventory_items WHERE category_type = :slug AND is_active = 1'
    );
    $inUse->execute([':slug' => $slug]);
    if ((int)$inUse->fetchColumn() > 0) {
        fail('Cannot delete a category type that is still used by inventory items.');
    }

    $pdo->prepare('UPDATE inventory_category_types SET is_active = 0 WHERE id = :id')
        ->execute([':id' => $id]);

    ok([
        'id' => $id,
        'message' => 'Category type deleted.',
        'category_types' => fetch_active_inventory_category_types($pdo),
    ]);
}

fail('Method not allowed.', 405);
