<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';
require_once __DIR__ . '/addons_helpers.php';
require_once __DIR__ . '/activity_log_helpers.php';

ensure_menu_serve_schema(db());
ensure_subcategories_schema(db());
ensure_main_categories_schema(db());

$m = method();

// ─── GET  →  list all items (with category) — admin + staff can read ─────────
if ($m === 'GET') {
    require_auth();   // admin or staff
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

    ensure_catalog_icon_columns(db());
    ensure_addon_menu_items_synced(db());

    // Fetch categories (scoped to main category / station)
    $cats = db()->query(
        'SELECT id, main_category_id, name, icon_url, display_order
           FROM categories
          WHERE is_active = 1
          ORDER BY display_order'
    )->fetchAll();
    foreach ($cats as &$cat) {
        $cat['id'] = (int)$cat['id'];
        $cat['main_category_id'] = isset($cat['main_category_id']) && $cat['main_category_id'] !== null
            ? (int)$cat['main_category_id']
            : null;
        $cat['display_order'] = (int)$cat['display_order'];
        $cat['icon_url'] = isset($cat['icon_url']) && $cat['icon_url'] !== null && $cat['icon_url'] !== ''
            ? (string)$cat['icon_url']
            : null;
    }
    unset($cat);

    // Fetch items
    $subcats = fetch_active_subcategories(db());
    $mainCats = fetch_active_main_categories(db());

    $sql = 'SELECT m.id, m.category_id, m.main_category_id, mc.name AS main_category_name,
                   m.subcategory_id, sc.name AS subcategory_name,
                   c.name AS category_name,
                   m.name, m.description, m.price, m.cost_price, m.image_url, m.is_available,
                   m.serve_hot, m.serve_cold,
                   m.created_at, m.updated_at
              FROM menu_items m
              JOIN categories c ON c.id = m.category_id AND c.is_active = 1
              LEFT JOIN main_categories mc ON mc.id = COALESCE(m.main_category_id, c.main_category_id)
              LEFT JOIN subcategories sc ON sc.id = m.subcategory_id AND sc.is_active = 1
             WHERE (c.main_category_id IS NULL OR c.main_category_id = 0
                    OR EXISTS (
                        SELECT 1 FROM main_categories mcx
                         WHERE mcx.id = c.main_category_id AND mcx.is_active = 1
                    ))';
    $params = [];
    if ($categoryId) {
        $sql    .= ' AND m.category_id = :cid';
        $params[':cid'] = $categoryId;
    }
    $sql .= ' ORDER BY c.display_order, m.name';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        cast_menu_item_row($item);
    }
    unset($item);
    annotate_menu_items_addon_flags(db(), $items);

    $mainCatsById = [];
    foreach ($mainCats as $mc) {
        $mainCatsById[(int)$mc['id']] = $mc;
    }
    foreach ($items as &$item) {
        apply_main_category_variants_to_menu_item($item, $mainCatsById);
    }
    unset($item);

    $fastMoving = fetch_fast_moving_items(db(), 100, 5);
    $bestSeller = fetch_best_seller(db(), 100);
    $unavailableProductsCount = (int)db()->query(
        'SELECT COUNT(*) FROM menu_items WHERE is_available = 0'
    )->fetchColumn();

    ok([
        'categories' => $cats,
        'subcategories' => $subcats,
        'main_categories' => $mainCats,
        'items' => $items,
        'fast_moving' => $fastMoving,
        'fast_moving_item_ids' => array_map(static function ($row) {
            return (int)$row['menu_item_id'];
        }, $fastMoving),
        'best_seller' => $bestSeller,
        'best_seller_menu_item_id' => $bestSeller ? (int)$bestSeller['menu_item_id'] : 0,
        'unavailable_products_count' => $unavailableProductsCount,
    ]);
}

// ─── POST  →  create item (authenticated user) ────────────────────────────────
if ($m === 'POST') {
    require_auth();
    $b = body();
    $name        = trim($b['name'] ?? '');
    $categoryId  = (int)($b['category_id'] ?? 0);
    $price       = (float)($b['price'] ?? 0);
    $costPrice   = max(0, (float)($b['cost_price'] ?? 0));
    $description = trim($b['description'] ?? '');
    $imageUrl    = trim($b['image_url'] ?? '');
    $isAvailable = isset($b['is_available']) ? (int)(bool)$b['is_available'] : 1;
    $serveHot = isset($b['serve_hot']) ? (int)(bool)$b['serve_hot'] : null;
    $serveCold = isset($b['serve_cold']) ? (int)(bool)$b['serve_cold'] : null;
    $subcategoryId = isset($b['subcategory_id']) && $b['subcategory_id'] !== ''
        ? (int)$b['subcategory_id']
        : null;
    $mainCategoryId = (int)($b['main_category_id'] ?? 0);

    if (!$name)        fail('Item name is required.');
    if (!$categoryId)  fail('Category is required.');
    if (!$mainCategoryId) fail('Main category is required.');
    if ($price <= 0)   fail('Price must be greater than zero.');

    $mainCategoryId = resolve_main_category_id(db(), $mainCategoryId);
    assert_category_belongs_to_main(db(), $categoryId, $mainCategoryId);

    // New products inherit the category icon when no product-specific image was uploaded.
    if ($imageUrl === '') {
        $iconStmt = db()->prepare(
            'SELECT icon_url FROM categories WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $iconStmt->execute([':id' => $categoryId]);
        $catIcon = $iconStmt->fetchColumn();
        if ($catIcon !== false && $catIcon !== null && trim((string)$catIcon) !== '') {
            $imageUrl = trim((string)$catIcon);
        }
    }

    $catName = db()->prepare('SELECT name FROM categories WHERE id = :id');
    $catName->execute([':id' => $categoryId]);
    $catRow = $catName->fetch();
    $isDrink = $catRow && (
        strtolower((string)$catRow['name']) === 'drinks'
        || strtolower((string)$catRow['name']) === 'beverages'
    );

    // Serve flags come from client / Variants: line (Hot/Iced in names). Optional for any category.
    if ($serveHot === null || $serveCold === null) {
        $inferred = infer_serve_flags_from_description($description);
        $serveHot = $serveHot ?? (int)$inferred['hot'];
        $serveCold = $serveCold ?? (int)$inferred['cold'];
    }
    $serveHot = (int)(bool)$serveHot;
    $serveCold = (int)(bool)$serveCold;

    if ($subcategoryId) {
        $sub = db()->prepare(
            'SELECT id, category_id, name FROM subcategories
              WHERE id = :id AND is_active = 1'
        );
        $sub->execute([':id' => $subcategoryId]);
        $subRow = $sub->fetch();
        if (!$subRow) {
            fail('Subcategory not found.');
        }
        if ((int)$subRow['category_id'] !== $categoryId) {
            fail('Subcategory does not belong to the selected category.');
        }
        if ($isDrink) {
            $subName = strtolower((string)$subRow['name']);
            if ($subName === 'hot') {
                $serveHot = 1;
            } elseif ($subName === 'cold') {
                $serveCold = 1;
            }
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO menu_items (category_id, main_category_id, subcategory_id, name, description, price, cost_price, image_url, is_available, serve_hot, serve_cold)
         VALUES (:cid, :mcid, :scid, :name, :desc, :price, :cost, :img, :avail, :shot, :scold)'
    );
    $stmt->execute([
        ':cid'   => $categoryId,
        ':mcid'  => $mainCategoryId,
        ':scid'  => $subcategoryId,
        ':name'  => $name,
        ':desc'  => $description ?: null,
        ':price' => $price,
        ':cost'  => $costPrice,
        ':img'   => $imageUrl ?: null,
        ':avail' => $isAvailable,
        ':shot'  => $serveHot,
        ':scold' => $serveCold,
    ]);

    $id = (int)db()->lastInsertId();
    log_system_activity(db(), [
        'source_key' => 'create_product',
        'source_label' => 'Create New Product',
        'action' => 'product created: ' . $name,
        'entity_type' => 'menu_item',
        'entity_id' => $id,
    ]);
    publish_realtime_event('catalog_updated', [
        'action' => 'menu_item_created',
        'menu_item_id' => $id,
    ]);
    ok(['id' => $id, 'message' => 'Item created.'], 201);
}

// ─── PUT  →  update item (authenticated user) ─────────────────────────────────
if ($m === 'PUT') {
    require_auth();
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Item ID is required.');

    $fields = [];
    $params = [':id' => $id];

    if (isset($b['name']))         { $fields[] = 'name = :name';          $params[':name']  = trim($b['name']); }
    if (isset($b['category_id']))  { $fields[] = 'category_id = :cid';    $params[':cid']   = (int)$b['category_id']; }
    if (isset($b['main_category_id'])) {
        $fields[] = 'main_category_id = :mcid';
        $params[':mcid'] = resolve_main_category_id(db(), (int)$b['main_category_id']);
    }
    if (isset($b['price']))        { $fields[] = 'price = :price';        $params[':price'] = (float)$b['price']; }
    if (isset($b['cost_price']))   { $fields[] = 'cost_price = :cost';   $params[':cost']  = max(0, (float)$b['cost_price']); }
    if (isset($b['description']))  { $fields[] = 'description = :desc';   $params[':desc']  = trim($b['description']); }
    if (isset($b['image_url']))    { $fields[] = 'image_url = :img';      $params[':img']   = trim($b['image_url']); }
    if (isset($b['is_available'])) { $fields[] = 'is_available = :avail'; $params[':avail'] = (int)(bool)$b['is_available']; }
    if (isset($b['serve_hot']))    { $fields[] = 'serve_hot = :shot';    $params[':shot']  = (int)(bool)$b['serve_hot']; }
    if (isset($b['serve_cold']))   { $fields[] = 'serve_cold = :scold';  $params[':scold'] = (int)(bool)$b['serve_cold']; }
    if (array_key_exists('subcategory_id', $b)) {
        $fields[] = 'subcategory_id = :scid';
        $params[':scid'] = ($b['subcategory_id'] === '' || $b['subcategory_id'] === null)
            ? null
            : (int)$b['subcategory_id'];
    }

    if (!$fields) fail('No fields to update.');

    if (isset($b['category_id']) || isset($b['main_category_id'])) {
        $cur = db()->prepare('SELECT category_id, main_category_id FROM menu_items WHERE id = :id');
        $cur->execute([':id' => $id]);
        $curRow = $cur->fetch();
        if (!$curRow) fail('Item not found.', 404);
        $effectiveCatId = isset($params[':cid']) ? (int)$params[':cid'] : (int)$curRow['category_id'];
        $effectiveMainId = isset($params[':mcid']) ? (int)$params[':mcid'] : (int)$curRow['main_category_id'];
        if ($effectiveCatId && $effectiveMainId) {
            assert_category_belongs_to_main(db(), $effectiveCatId, $effectiveMainId);
        }
    }

    if (array_key_exists('subcategory_id', $b) && $params[':scid'] ?? null) {
        $curCat = db()->prepare('SELECT category_id FROM menu_items WHERE id = :id');
        $curCat->execute([':id' => $id]);
        $itemCatId = (int)$curCat->fetchColumn();
        $sub = db()->prepare('SELECT category_id FROM subcategories WHERE id = :id AND is_active = 1');
        $sub->execute([':id' => (int)$params[':scid']]);
        $subCatId = (int)$sub->fetchColumn();
        $effectiveCatId = isset($params[':cid']) ? (int)$params[':cid'] : $itemCatId;
        if (!$subCatId || $subCatId !== $effectiveCatId) {
            fail('Subcategory does not belong to the selected category.');
        }
    }

    if (isset($b['serve_hot']) || isset($b['serve_cold']) || isset($b['description'])) {
        $cur = db()->prepare(
            'SELECT m.serve_hot, m.serve_cold, m.description
               FROM menu_items m
              WHERE m.id = :id'
        );
        $cur->execute([':id' => $id]);
        $row = $cur->fetch();
        if (!$row) fail('Item not found.', 404);

        $descForFlags = isset($b['description']) ? trim((string)$b['description']) : (string)($row['description'] ?? '');
        $inferred = infer_serve_flags_from_description($descForFlags);

        if (!isset($params[':shot'])) {
            $fields[] = 'serve_hot = :shot';
            $params[':shot'] = isset($b['serve_hot'])
                ? (int)(bool)$b['serve_hot']
                : (int)$inferred['hot'];
        }
        if (!isset($params[':scold'])) {
            $fields[] = 'serve_cold = :scold';
            $params[':scold'] = isset($b['serve_cold'])
                ? (int)(bool)$b['serve_cold']
                : (int)$inferred['cold'];
        }
    }

    $sql = 'UPDATE menu_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
    db()->prepare($sql)->execute($params);

    $nameStmt = db()->prepare('SELECT name FROM menu_items WHERE id = :id LIMIT 1');
    $nameStmt->execute([':id' => $id]);
    $updatedName = trim((string)$nameStmt->fetchColumn()) ?: ('#' . $id);
    log_system_activity(db(), [
        'source_key' => 'edit_menu_item',
        'source_label' => 'Edit Menu Item',
        'action' => 'menu item edited: ' . $updatedName,
        'entity_type' => 'menu_item',
        'entity_id' => $id,
    ]);

    publish_realtime_event('catalog_updated', [
        'action' => 'menu_item_updated',
        'menu_item_id' => $id,
    ]);
    ok(['message' => 'Item updated.']);
}

// ─── DELETE  →  remove item (authenticated user) ──────────────────────────────
if ($m === 'DELETE') {
    require_auth();
    $b  = body();
    $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if (!$id) fail('Item ID is required.');

    $pdo = db();

    $nameStmt = $pdo->prepare('SELECT name FROM menu_items WHERE id = :id LIMIT 1');
    $nameStmt->execute([':id' => $id]);
    $deletedName = trim((string)$nameStmt->fetchColumn()) ?: ('#' . $id);

    // Detach / deactivate linked addon cards outside the main delete txn so a
    // missing optional table cannot abort the transaction.
    try {
        ensure_addons_schema($pdo);
        $pdo->prepare(
            'UPDATE addons
                SET is_active = 0, menu_item_id = NULL
              WHERE menu_item_id = :mid'
        )->execute([':mid' => $id]);
    } catch (Throwable $e) {
        // Addons schema may be unavailable.
    }

    try {
        $hasRecipes = (bool)$pdo->query("SHOW TABLES LIKE 'recipes'")->fetchColumn();
        if ($hasRecipes) {
            $recipeIdsStmt = $pdo->prepare('SELECT id FROM recipes WHERE menu_item_id = :mid');
            $recipeIdsStmt->execute([':mid' => $id]);
            $recipeIds = array_map('intval', $recipeIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $hasRecipeIngredients = (bool)$pdo->query("SHOW TABLES LIKE 'recipe_ingredients'")->fetchColumn();
            if ($hasRecipeIngredients && $recipeIds) {
                $delIng = $pdo->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = :rid');
                foreach ($recipeIds as $rid) {
                    if ($rid > 0) {
                        $delIng->execute([':rid' => $rid]);
                    }
                }
            }
            $pdo->prepare('DELETE FROM recipes WHERE menu_item_id = :mid')->execute([':mid' => $id]);
        }
    } catch (Throwable $e) {
        // Recipes may be unavailable.
    }

    try {
        $hasInvMenu = (bool)$pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'menu_item_id'")->fetch();
        if ($hasInvMenu) {
            $pdo->prepare('UPDATE inventory_items SET menu_item_id = NULL WHERE menu_item_id = :mid')
                ->execute([':mid' => $id]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->beginTransaction();
        // Clear order lines first — menu_items is referenced by order_items.
        $pdo->prepare('DELETE FROM order_items WHERE menu_item_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM menu_items WHERE id = :id')->execute([':id' => $id]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail('Failed to delete item: ' . $e->getMessage());
    }

    log_system_activity($pdo, [
        'source_key' => 'delete_menu_item',
        'source_label' => 'Delete Menu Item',
        'action' => 'menu item deleted: ' . $deletedName,
        'entity_type' => 'menu_item',
        'entity_id' => $id,
    ]);

    publish_realtime_event('catalog_updated', [
        'action' => 'menu_item_deleted',
        'menu_item_id' => $id,
    ]);
    ok(['message' => 'Item deleted.']);
}

fail('Method not allowed.', 405);
