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
