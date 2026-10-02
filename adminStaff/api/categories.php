<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';

ensure_catalog_icon_columns(db());
ensure_main_categories_schema(db());

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
    $mainCategoryId = (int)($b['main_category_id'] ?? 0);

    if ($name === '') fail('Category name is required.');
    if (!$mainCategoryId) fail('Main category is required.');
    $mainCategoryId = resolve_main_category_id(db(), $mainCategoryId);

    $pdo = db();

    // Unique index is on categories.name (global). Soft-deleted rows still block inserts.
    $stmt = $pdo->prepare(
        'SELECT id, name, is_active, main_category_id, icon_url
           FROM categories
          WHERE LOWER(name) = LOWER(:name)
          LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $existing = $stmt->fetch();

    if ($existing) {
        $existingId = (int)$existing['id'];
        $wasActive = (int)($existing['is_active'] ?? 0) === 1;

        if ($wasActive) {
            ok([
                'id' => $existingId,
                'main_category_id' => isset($existing['main_category_id']) ? (int)$existing['main_category_id'] : $mainCategoryId,
                'message' => 'Category already exists.',
            ], 200);
        }

        // Reactivate soft-deleted category instead of colliding with UNIQUE(name).
        $reactivateIcon = $iconUrl !== null
            ? $iconUrl
            : (isset($existing['icon_url']) && $existing['icon_url'] !== null && $existing['icon_url'] !== ''
                ? (string)$existing['icon_url']
                : null);
        try {
            $upd = $pdo->prepare(
                'UPDATE categories
                    SET is_active = 1,
                        main_category_id = :mcid,
                        icon_url = :icon
                  WHERE id = :id'
            );
            $upd->execute([
                ':mcid' => $mainCategoryId,
                ':icon' => $reactivateIcon,
                ':id' => $existingId,
            ]);
        } catch (Throwable $e) {
            fail('Failed to restore category: ' . $e->getMessage());
        }

        ok([
            'id' => $existingId,
            'main_category_id' => $mainCategoryId,
            'restored' => true,
            'message' => 'Category restored.',
        ], 200);
    }

    $nextOrder = (int)($pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM categories')->fetchColumn());

    try {
        $ins = $pdo->prepare(
            'INSERT INTO categories (main_category_id, name, icon_url, display_order, is_active)
             VALUES (:mcid, :name, :icon, :ord, :act)'
        );
        $ins->execute([
            ':mcid' => $mainCategoryId,
            ':name' => $name,
            ':icon' => $iconUrl,
            ':ord'  => $nextOrder,
            ':act'  => $isActive,
        ]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            fail('A category with that name already exists.');
        }
        fail('Failed to create category: ' . $e->getMessage());
    }

    $id = (int)$pdo->lastInsertId();
    ok(['id' => $id, 'main_category_id' => $mainCategoryId, 'message' => 'Category created.'], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Category id is required.');

    $pdo = db();
    $row = $pdo->prepare('SELECT id, name, icon_url, main_category_id FROM categories WHERE id = :id AND is_active = 1');
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

    $mainCategoryId = isset($cat['main_category_id']) ? (int)$cat['main_category_id'] : 0;

    $dup = $pdo->prepare(
        'SELECT id FROM categories
         WHERE LOWER(name) = LOWER(:name)
           AND is_active = 1
           AND id <> :id
           AND (main_category_id = :mcid OR (:mcid2 = 0 AND (main_category_id IS NULL OR main_category_id = 0)))
         LIMIT 1'
    );
    $dup->execute([
        ':name' => $name,
        ':id' => $id,
        ':mcid' => $mainCategoryId,
        ':mcid2' => $mainCategoryId,
    ]);
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

    $itemsUpdated = 0;
    $applyToItems = !empty($b['apply_to_items']);
    $categoryIds = [];
    if (isset($b['category_ids']) && is_array($b['category_ids'])) {
        foreach ($b['category_ids'] as $rawId) {
            $cid = (int)$rawId;
            if ($cid > 0) {
                $categoryIds[$cid] = true;
            }
        }
    }
    $categoryIds = array_keys($categoryIds);
    if (!$categoryIds) {
        $categoryIds = [$id];
    }

    if ($applyToItems && $hasIcon && $iconUrl !== null && $iconUrl !== '') {
        try {
            // Native MySQL prepares need one unique placeholder per value.
            $placeholders = [];
            $params = [':img' => $iconUrl];
            foreach ($categoryIds as $i => $cid) {
                $key = ':cid' . $i;
                $placeholders[] = $key;
                $params[$key] = (int)$cid;
            }
            $sql = 'UPDATE menu_items SET image_url = :img WHERE category_id IN (' . implode(',', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $itemsUpdated = (int)$stmt->rowCount();
        } catch (Throwable $e) {
            fail('Category icon saved, but updating item pictures failed: ' . $e->getMessage());
        }
    }

    ok([
        'id' => $id,
        'icon_url' => $iconUrl,
        'items_updated' => $itemsUpdated,
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

    $itemIds = [];
    $subIds = [];

    try {
        $pdo->beginTransaction();

        $subStmt = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = :id AND is_active = 1');
        $subStmt->execute([':id' => $id]);
        $subIds = array_map('intval', $subStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        // Soft-delete all subcategories under this category.
        $pdo->prepare('UPDATE subcategories SET is_active = 0 WHERE category_id = :id')
            ->execute([':id' => $id]);

        $idsStmt = $pdo->prepare('SELECT id FROM menu_items WHERE category_id = :id');
        $idsStmt->execute([':id' => $id]);
        $itemIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        foreach ($itemIds as $menuItemId) {
            if ($menuItemId <= 0) {
                continue;
            }

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

            try {
                $pdo->prepare('DELETE FROM menu_items WHERE id = :mid')->execute([':mid' => $menuItemId]);
            } catch (Throwable $e) {
                // Keep sales history if FK blocks hard delete.
                $pdo->prepare('UPDATE menu_items SET is_available = 0 WHERE id = :mid')
                    ->execute([':mid' => $menuItemId]);
            }
        }

        $upd = $pdo->prepare('UPDATE categories SET is_active = 0 WHERE id = :id');
        $upd->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail('Failed to delete category: ' . $e->getMessage());
    }

    ok([
        'id' => $id,
        'deleted_subcategories' => count($subIds),
        'deleted_menu_items' => count($itemIds),
        'message' => 'Category deleted.',
    ]);
}

fail('Method not allowed.', 405);
