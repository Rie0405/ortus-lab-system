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

    $itemIds = [];

    try {
        $pdo->beginTransaction();

        // Soft-delete addons registered to this main category.
        try {
            $pdo->prepare('UPDATE addons SET is_active = 0 WHERE main_category_id = :id')
                ->execute([':id' => $id]);
        } catch (Throwable $e) {
            // Addons table may be unavailable on some installs.
        }

        $idsStmt = $pdo->prepare('SELECT id FROM menu_items WHERE main_category_id = :id');
        $idsStmt->execute([':id' => $id]);
        $itemIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        foreach ($itemIds as $menuItemId) {
            if ($menuItemId <= 0) {
                continue;
            }

            // Clear recipe rows first (FK to menu_items).
            try {
                $recipeIdsStmt = $pdo->prepare('SELECT id FROM recipes WHERE menu_item_id = :mid');
                $recipeIdsStmt->execute([':mid' => $menuItemId]);
                foreach ($recipeIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $recipeId) {
                    $rid = (int)$recipeId;
                    if ($rid <= 0) {
                        continue;
                    }
                    try {
                        $pdo->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = :rid')
                            ->execute([':rid' => $rid]);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                $pdo->prepare('DELETE FROM recipes WHERE menu_item_id = :mid')
                    ->execute([':mid' => $menuItemId]);
            } catch (Throwable $e) {
                // Recipes may not exist.
            }

            try {
                $pdo->prepare('UPDATE inventory_items SET menu_item_id = NULL WHERE menu_item_id = :mid')
                    ->execute([':mid' => $menuItemId]);
            } catch (Throwable $e) {
                // ignore
            }

            try {
                $pdo->prepare('UPDATE addons SET menu_item_id = NULL WHERE menu_item_id = :mid')
                    ->execute([':mid' => $menuItemId]);
            } catch (Throwable $e) {
                // ignore
            }

            // Remove the menu item. Keep order_items for sales history when possible.
            try {
                $pdo->prepare('DELETE FROM menu_items WHERE id = :mid')->execute([':mid' => $menuItemId]);
            } catch (Throwable $e) {
                $pdo->prepare(
                    'UPDATE menu_items
                        SET is_available = 0, main_category_id = NULL
                      WHERE id = :mid'
                )->execute([':mid' => $menuItemId]);
            }
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

    ok([
        'id' => $id,
        'deleted_menu_items' => count($itemIds),
        'message' => 'Main category deleted.',
    ]);
}

fail('Method not allowed', 405);
