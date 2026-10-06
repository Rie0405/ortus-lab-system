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
            publish_realtime_event('inventory_updated', [
                'action' => 'category_type_restored',
                'category_type_id' => (int)$existing['id'],
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

    $newId = (int)$pdo->lastInsertId();
    publish_realtime_event('inventory_updated', [
        'action' => 'category_type_created',
        'category_type_id' => $newId,
    ]);
    ok([
        'id' => $newId,
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

    publish_realtime_event('inventory_updated', [
        'action' => 'category_type_updated',
        'category_type_id' => $id,
    ]);
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

    // Only block on registered inventory (shown in Inventory UI).
    // Menu-linked / auto rows also default to category_type='main' and are hidden from the list.
    $inUse = $pdo->prepare(
        'SELECT COUNT(*) FROM inventory_items
          WHERE category_type = :slug
            AND is_active = 1
            AND menu_item_id IS NULL'
    );
    $inUse->execute([':slug' => $slug]);
    $usedCount = (int)$inUse->fetchColumn();
    if ($usedCount > 0) {
        fail(
            'Cannot delete a category type that is still used by ' . $usedCount .
            ' registered inventory item' . ($usedCount === 1 ? '' : 's') . '.'
        );
    }

    // Detach leftover non-registered rows so the slug can be retired cleanly.
    // When deleting "main", leave menu-linked defaults alone (they're not shown in Register UI).
    if ($slug !== 'main') {
        try {
            $pdo->prepare(
                'UPDATE inventory_items
                    SET category_type = \'main\'
                  WHERE category_type = :slug
                    AND menu_item_id IS NOT NULL'
            )->execute([':slug' => $slug]);
        } catch (Throwable $e) {
            // Non-fatal: delete can continue even if reassignment fails.
        }
    }

    $pdo->prepare('UPDATE inventory_category_types SET is_active = 0 WHERE id = :id')
        ->execute([':id' => $id]);

    publish_realtime_event('inventory_updated', [
        'action' => 'category_type_deleted',
        'category_type_id' => $id,
    ]);
    ok([
        'id' => $id,
        'message' => 'Category type deleted.',
        'category_types' => fetch_active_inventory_category_types($pdo),
    ]);
}

fail('Method not allowed.', 405);
