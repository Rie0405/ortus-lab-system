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
    $entryMode = normalize_inventory_entry_mode($b['entry_mode'] ?? 'automatic');

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
                    SET is_active = 1, name = :name, entry_mode = :entry_mode, display_order = :ord
                  WHERE id = :id'
            )->execute([
                ':name' => $name,
                ':entry_mode' => $entryMode,
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
        'INSERT INTO inventory_category_types (slug, name, entry_mode, display_order, is_active)
         VALUES (:slug, :name, :entry_mode, :ord, 1)'
    );
    $ins->execute([
        ':slug' => $slug,
        ':name' => $name,
        ':entry_mode' => $entryMode,
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
    if (!$id) {
        fail('Category type id is required.');
    }

    $cur = $pdo->prepare(
        'SELECT id, slug, name, entry_mode
           FROM inventory_category_types
          WHERE id = :id AND is_active = 1'
    );
    $cur->execute([':id' => $id]);
    $row = $cur->fetch();
    if (!$row) {
        fail('Category type not found.', 404);
    }

    $hasName = array_key_exists('name', $b);
    $hasMode = array_key_exists('entry_mode', $b);
    if (!$hasName && !$hasMode) {
        fail('Nothing to update.');
    }

    $name = $hasName ? trim((string)$b['name']) : (string)$row['name'];
    if ($hasName) {
        if ($name === '') {
            fail('Category type name is required.');
        }
        $name = substr($name, 0, 80);
        $dup = $pdo->prepare(
            'SELECT id FROM inventory_category_types
              WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND id <> :id
              LIMIT 1'
        );
        $dup->execute([':name' => $name, ':id' => $id]);
        if ($dup->fetch()) {
            fail('Another category type already uses that name.');
        }
    }

    $entryMode = $hasMode
        ? normalize_inventory_entry_mode($b['entry_mode'])
        : normalize_inventory_entry_mode($row['entry_mode'] ?? 'automatic');
    $prevMode = normalize_inventory_entry_mode($row['entry_mode'] ?? 'automatic');
    $slug = (string)$row['slug'];

    // Keep slug stable so existing inventory_items.category_type values stay valid.
    $pdo->prepare(
        'UPDATE inventory_category_types
            SET name = :name, entry_mode = :entry_mode
          WHERE id = :id'
    )->execute([
        ':name' => $name,
        ':entry_mode' => $entryMode,
        ':id' => $id,
    ]);

    if ($hasMode && $entryMode !== $prevMode) {
        try {
            $cascade = $pdo->prepare(
                'UPDATE inventory_items
                    SET entry_mode = :entry_mode
                  WHERE is_active = 1
                    AND category_type = :slug'
            );
            $cascade->execute([
                ':entry_mode' => $entryMode,
                ':slug' => $slug,
            ]);
        } catch (Throwable $e) {
            // Non-fatal if inventory_items.entry_mode / category_type missing during bootstrap.
        }
    }

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
