<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';

ensure_menu_serve_schema(db());
ensure_subcategories_schema(db());
ensure_main_categories_schema(db());

$m = method();

// ─── GET  →  list all items (with category) — admin + staff can read ─────────
if ($m === 'GET') {
    require_auth();   // admin or staff
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

    // Fetch categories
    $cats = db()->query(
        'SELECT id, name, display_order FROM categories WHERE is_active = 1 ORDER BY display_order'
    )->fetchAll();

    // Fetch items
    $subcats = fetch_active_subcategories(db());
    $mainCats = fetch_active_main_categories(db());

    $sql = 'SELECT m.id, m.category_id, m.main_category_id, mc.name AS main_category_name,
                   m.subcategory_id, sc.name AS subcategory_name,
                   c.name AS category_name,
                   m.name, m.description, m.price, m.image_url, m.is_available,
                   m.serve_hot, m.serve_cold,
                   m.created_at, m.updated_at
              FROM menu_items m
              JOIN categories c ON c.id = m.category_id
              LEFT JOIN main_categories mc ON mc.id = m.main_category_id
              LEFT JOIN subcategories sc ON sc.id = m.subcategory_id';
    $params = [];
    if ($categoryId) {
        $sql    .= ' WHERE m.category_id = :cid';
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

    $fastMoving = fetch_fast_moving_items(db(), 100, 5);

    ok([
        'categories' => $cats,
        'subcategories' => $subcats,
        'main_categories' => $mainCats,
        'items' => $items,
        'fast_moving' => $fastMoving,
        'fast_moving_item_ids' => array_map(static function ($row) {
            return (int)$row['menu_item_id'];
        }, $fastMoving),
    ]);
}

// ─── POST  →  create item (authenticated user) ────────────────────────────────
if ($m === 'POST') {
    require_auth();
    $b = body();
    $name        = trim($b['name'] ?? '');
    $categoryId  = (int)($b['category_id'] ?? 0);
    $price       = (float)($b['price'] ?? 0);
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

    $catName = db()->prepare('SELECT name FROM categories WHERE id = :id');
    $catName->execute([':id' => $categoryId]);
    $catRow = $catName->fetch();
    $isDrink = $catRow && strtolower((string)$catRow['name']) === 'drinks';

    if ($isDrink) {
        if ($serveHot === null || $serveCold === null) {
            $inferred = infer_serve_flags_from_description($description);
            $serveHot = $serveHot ?? (int)$inferred['hot'];
            $serveCold = $serveCold ?? (int)$inferred['cold'];
        }
        if (!$serveHot && !$serveCold) {
            fail('Select at least one drink subcategory: Hot or Cold.');
        }
    } else {
        $serveHot = 0;
        $serveCold = 0;
    }

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
        'INSERT INTO menu_items (category_id, main_category_id, subcategory_id, name, description, price, image_url, is_available, serve_hot, serve_cold)
         VALUES (:cid, :mcid, :scid, :name, :desc, :price, :img, :avail, :shot, :scold)'
    );
    $stmt->execute([
        ':cid'   => $categoryId,
        ':mcid'  => $mainCategoryId,
        ':scid'  => $subcategoryId,
        ':name'  => $name,
        ':desc'  => $description ?: null,
        ':price' => $price,
        ':img'   => $imageUrl ?: null,
        ':avail' => $isAvailable,
        ':shot'  => $serveHot,
        ':scold' => $serveCold,
    ]);

    $id = (int)db()->lastInsertId();
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

    if (isset($b['serve_hot']) || isset($b['serve_cold'])) {
        $cur = db()->prepare(
            'SELECT m.serve_hot, m.serve_cold, c.name AS category_name
               FROM menu_items m
               JOIN categories c ON c.id = m.category_id
              WHERE m.id = :id'
        );
        $cur->execute([':id' => $id]);
        $row = $cur->fetch();
        if (!$row) fail('Item not found.', 404);
        if (strtolower((string)$row['category_name']) === 'drinks') {
            $nextHot = isset($b['serve_hot']) ? (bool)$b['serve_hot'] : (bool)$row['serve_hot'];
            $nextCold = isset($b['serve_cold']) ? (bool)$b['serve_cold'] : (bool)$row['serve_cold'];
            if (!$nextHot && !$nextCold) {
                fail('Select at least one drink subcategory: Hot or Cold.');
            }
        }
    }

    $sql = 'UPDATE menu_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
    db()->prepare($sql)->execute($params);

    ok(['message' => 'Item updated.']);
}

// ─── DELETE  →  remove item (authenticated user) ──────────────────────────────
if ($m === 'DELETE') {
    require_auth();
    $b  = body();
    $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if (!$id) fail('Item ID is required.');

    // Hard delete: remove dependent order lines first, then the menu item.
    db()->prepare('DELETE FROM order_items WHERE menu_item_id = :id')->execute([':id' => $id]);
    db()->prepare('DELETE FROM menu_items WHERE id = :id')->execute([':id' => $id]);
    ok(['message' => 'Item deleted.']);
}

fail('Method not allowed.', 405);
