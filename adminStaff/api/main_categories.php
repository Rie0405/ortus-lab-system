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
    publish_realtime_event('catalog_updated', [
        'action' => 'main_category_created',
        'main_category_id' => $id,
    ]);
    ok(['id' => $id, 'name' => $name, 'message' => 'Main category created.'], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Main category id is required.');

    $pdo = db();
    ensure_main_categories_schema($pdo);

    $row = $pdo->prepare('SELECT id, name FROM main_categories WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    if (!$row->fetch()) {
        fail('Main category not found.', 404);
    }

    $fields = [];
    $params = [':id' => $id];

    if (array_key_exists('name', $b)) {
        $name = trim((string)$b['name']);
        if ($name === '') fail('Main category name is required.');
        $dup = $pdo->prepare(
            'SELECT id FROM main_categories
             WHERE LOWER(TRIM(name)) = LOWER(:name) AND is_active = 1 AND id <> :id
             LIMIT 1'
        );
        $dup->execute([':name' => $name, ':id' => $id]);
        if ($dup->fetch()) {
            fail('Another main category already uses that name.');
        }
        $fields[] = 'name = :name';
        $params[':name'] = $name;
    }

    if (array_key_exists('variants_enabled', $b) || array_key_exists('variants', $b)) {
        $enabled = !empty($b['variants_enabled']) ? 1 : 0;
        $variants = normalize_main_category_variants($b['variants'] ?? []);
        if ($enabled && !$variants) {
            fail('Add at least one variant, or turn off Enable variants.');
        }
        if (!$enabled) {
            $variants = [];
        }
        $fields[] = 'variants_enabled = :ve';
        $fields[] = 'variants_json = :vj';
        $params[':ve'] = $enabled;
        $params[':vj'] = $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null;
    }

    if (!$fields) {
        fail('No fields to update.');
    }

    $sql = 'UPDATE main_categories SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    $fresh = fetch_active_main_categories($pdo);
    $updated = null;
    foreach ($fresh as $mc) {
        if ((int)$mc['id'] === $id) {
            $updated = $mc;
            break;
        }
    }
    publish_realtime_event('catalog_updated', [
        'action' => 'main_category_updated',
        'main_category_id' => $id,
    ]);
    ok([
        'id' => $id,
        'main_category' => $updated,
        'message' => 'Main category updated.',
    ]);
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

    $itemIds = [];
    $catIds = [];

    try {
        $pdo->beginTransaction();

        // Soft-delete addons registered to this main category.
        try {
            $pdo->prepare('UPDATE addons SET is_active = 0 WHERE main_category_id = :id')
                ->execute([':id' => $id]);
        } catch (Throwable $e) {
            // Addons table may be unavailable on some installs.
        }

        // Categories under this station (include inactive mid-cleanup).
        try {
            $catIdsStmt = $pdo->prepare(
                'SELECT id FROM categories WHERE main_category_id = :id'
            );
            $catIdsStmt->execute([':id' => $id]);
            $catIds = unique_positive_ids($catIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            $catIds = [];
        }

        foreach ($catIds as $catId) {
            try {
                $pdo->prepare('UPDATE subcategories SET is_active = 0 WHERE category_id = :cid')
                    ->execute([':cid' => $catId]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        if ($catIds) {
            try {
                $pdo->prepare('UPDATE categories SET is_active = 0 WHERE main_category_id = :id')
                    ->execute([':id' => $id]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        // Menu items tied by main_category_id OR by category under this station.
        $itemIdMap = [];
        try {
            $byMain = $pdo->prepare('SELECT id FROM menu_items WHERE main_category_id = :id');
            $byMain->execute([':id' => $id]);
            foreach ($byMain->fetchAll(PDO::FETCH_COLUMN) ?: [] as $mid) {
                $itemIdMap[(int)$mid] = true;
            }
        } catch (Throwable $e) {
            // Column may be missing mid-migration.
        }
        if ($catIds) {
            $placeholders = [];
            $params = [];
            foreach ($catIds as $i => $cid) {
                $key = ':cid' . $i;
                $placeholders[] = $key;
                $params[$key] = $cid;
            }
            $byCat = $pdo->prepare(
                'SELECT id FROM menu_items WHERE category_id IN (' . implode(',', $placeholders) . ')'
            );
            $byCat->execute($params);
            foreach ($byCat->fetchAll(PDO::FETCH_COLUMN) ?: [] as $mid) {
                $itemIdMap[(int)$mid] = true;
            }
        }
        $itemIds = unique_positive_ids(array_keys($itemIdMap));

        foreach ($itemIds as $menuItemId) {
            purge_menu_item($pdo, $menuItemId);
        }

        $upd = $pdo->prepare('UPDATE main_categories SET is_active = 0 WHERE id = :id');
        $upd->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail('Failed to delete main category: ' . $e->getMessage());
    }

    publish_realtime_event('catalog_updated', [
        'action' => 'main_category_deleted',
        'main_category_id' => $id,
    ]);
    ok([
        'id' => $id,
        'deleted_categories' => count($catIds),
        'deleted_menu_items' => count($itemIds),
        'message' => 'Main category deleted.',
    ]);
}

fail('Method not allowed', 405);
