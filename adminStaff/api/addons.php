<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/addons_helpers.php';

ensure_addons_schema(db());
$m = method();

function resolve_addon_inventory_link(PDO $pdo, $inventoryItemIdRaw, $inventoryQtyRaw): array
{
    $inventoryItemId = (int)($inventoryItemIdRaw ?? 0);
    $inventoryQty = normalize_addon_inventory_qty($inventoryQtyRaw ?? 1);
    if ($inventoryItemId <= 0) {
        return [null, 1.0];
    }
    $chk = $pdo->prepare('SELECT id FROM inventory_items WHERE id = :id AND is_active = 1 LIMIT 1');
    $chk->execute([':id' => $inventoryItemId]);
    if (!$chk->fetch()) {
        fail('Linked inventory item not found.');
    }
    return [$inventoryItemId, $inventoryQty];
}

if ($m === 'GET') {
    require_auth();
    ensure_addon_menu_items_synced(db());
    $mainCategoryId = isset($_GET['main_category_id']) ? (int)$_GET['main_category_id'] : null;
    $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1';
    ok([
        'addons' => fetch_addons_rows(db(), $mainCategoryId, !$includeInactive),
        'main_categories' => fetch_active_main_categories(db()),
    ]);
}

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    $price = isset($b['price']) ? (float)$b['price'] : 0.0;
    $mainCategoryId = (int)($b['main_category_id'] ?? 0);

    if ($name === '') {
        fail('Addon name is required.');
    }
    if (strlen($name) > 120) {
        fail('Addon name is too long.');
    }
    if ($price < 0) {
        fail('Addon price cannot be negative.');
    }
    $mainCategoryId = resolve_main_category_id(db(), $mainCategoryId);

    $pdo = db();
    [$inventoryItemId, $inventoryQty] = resolve_addon_inventory_link(
        $pdo,
        $b['inventory_item_id'] ?? null,
        $b['inventory_qty'] ?? 1
    );

    $dup = $pdo->prepare(
        'SELECT id FROM addons
          WHERE main_category_id = :mcid AND LOWER(TRIM(name)) = LOWER(:name) AND is_active = 1
          LIMIT 1'
    );
    $dup->execute([':mcid' => $mainCategoryId, ':name' => $name]);
    if ($dup->fetch()) {
        fail('An addon with that name already exists for this station.');
    }

    $nextOrder = (int)$pdo->query(
        'SELECT COALESCE(MAX(display_order), 0) + 1 FROM addons WHERE main_category_id = ' . (int)$mainCategoryId
    )->fetchColumn();

    $ins = $pdo->prepare(
        'INSERT INTO addons (name, price, main_category_id, inventory_item_id, inventory_qty, display_order, is_active)
         VALUES (:name, :price, :mcid, :inv, :qty, :ord, 1)'
    );
    $ins->execute([
        ':name' => $name,
        ':price' => round($price, 2),
        ':mcid' => $mainCategoryId,
        ':inv' => $inventoryItemId,
        ':qty' => $inventoryQty,
        ':ord' => $nextOrder,
    ]);

    $id = (int)$pdo->lastInsertId();
    sync_addon_menu_item($pdo, $id);
    ok(['id' => $id, 'message' => 'Addon created.', 'addons' => fetch_addons_rows($pdo)], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $name = trim((string)($b['name'] ?? ''));
    $price = isset($b['price']) ? (float)$b['price'] : 0.0;
    $mainCategoryId = (int)($b['main_category_id'] ?? 0);

    if (!$id) {
        fail('Addon id is required.');
    }
    if ($name === '') {
        fail('Addon name is required.');
    }
    if (strlen($name) > 120) {
        fail('Addon name is too long.');
    }
    if ($price < 0) {
        fail('Addon price cannot be negative.');
    }
    $mainCategoryId = resolve_main_category_id(db(), $mainCategoryId);

    $pdo = db();
    $row = $pdo->prepare('SELECT id FROM addons WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    if (!$row->fetch()) {
        fail('Addon not found.', 404);
    }

    [$inventoryItemId, $inventoryQty] = resolve_addon_inventory_link(
        $pdo,
        $b['inventory_item_id'] ?? null,
        $b['inventory_qty'] ?? 1
    );

    $dup = $pdo->prepare(
        'SELECT id FROM addons
          WHERE main_category_id = :mcid AND LOWER(TRIM(name)) = LOWER(:name) AND is_active = 1 AND id <> :id
          LIMIT 1'
    );
    $dup->execute([':mcid' => $mainCategoryId, ':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another addon already uses that name for this station.');
    }

    $upd = $pdo->prepare(
        'UPDATE addons
            SET name = :name,
                price = :price,
                main_category_id = :mcid,
                inventory_item_id = :inv,
                inventory_qty = :qty
          WHERE id = :id'
    );
    $upd->execute([
        ':name' => $name,
        ':price' => round($price, 2),
        ':mcid' => $mainCategoryId,
        ':inv' => $inventoryItemId,
        ':qty' => $inventoryQty,
        ':id' => $id,
    ]);

    sync_addon_menu_item($pdo, $id);
    ok(['id' => $id, 'message' => 'Addon updated.', 'addons' => fetch_addons_rows($pdo)]);
}

if ($m === 'DELETE') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) {
        fail('Addon id is required.');
    }

    $pdo = db();
    $row = $pdo->prepare('SELECT id FROM addons WHERE id = :id AND is_active = 1');
    $row->execute([':id' => $id]);
    if (!$row->fetch()) {
        fail('Addon not found.', 404);
    }

    hide_addon_menu_item($pdo, $id);
    $pdo->prepare('UPDATE addons SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);
    ok(['id' => $id, 'message' => 'Addon removed.', 'addons' => fetch_addons_rows($pdo)]);
}

fail('Method not allowed', 405);
