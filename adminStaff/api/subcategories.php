<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';

ensure_subcategories_schema(db());
ensure_catalog_icon_columns(db());

$m = method();

function normalize_subcategory_icon_url($raw): ?string
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

if ($m === 'GET') {
    require_auth();
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

    $sql = 'SELECT s.id, s.category_id, c.name AS category_name, s.name, s.icon_url, s.display_order
              FROM subcategories s
              JOIN categories c ON c.id = s.category_id
             WHERE s.is_active = 1 AND c.is_active = 1';
    $params = [];
    if ($categoryId) {
        $sql .= ' AND s.category_id = :cid';
        $params[':cid'] = $categoryId;
    }
    $sql .= ' ORDER BY c.display_order, s.display_order, s.name';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['category_id'] = (int)$row['category_id'];
        $row['display_order'] = (int)$row['display_order'];
        $row['icon_url'] = isset($row['icon_url']) && $row['icon_url'] !== null && $row['icon_url'] !== ''
            ? (string)$row['icon_url']
            : null;
    }
    unset($row);

    ok(['subcategories' => $rows]);
}

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    $categoryId = (int)($b['category_id'] ?? 0);
    $isActive = isset($b['is_active']) ? (int)(bool)$b['is_active'] : 1;
    $iconUrl = array_key_exists('icon_url', $b) ? normalize_subcategory_icon_url($b['icon_url']) : null;

    if ($name === '') {
        fail('Subcategory name is required.');
    }
    if (!$categoryId) {
        fail('Parent category is required.');
    }

    $cat = db()->prepare('SELECT id, name FROM categories WHERE id = :id AND is_active = 1');
    $cat->execute([':id' => $categoryId]);
    if (!$cat->fetch()) {
        fail('Parent category not found.');
    }

    $existing = db()->prepare(
        'SELECT id, name FROM subcategories
          WHERE category_id = :cid AND LOWER(name) = LOWER(:name) AND is_active = 1
          LIMIT 1'
    );
    $existing->execute([':cid' => $categoryId, ':name' => $name]);
    $row = $existing->fetch();
    if ($row) {
        ok([
            'id' => (int)$row['id'],
            'message' => 'Subcategory already exists.',
        ], 200);
    }

    $orderStmt = db()->prepare(
        'SELECT COALESCE(MAX(display_order), 0) + 1 FROM subcategories WHERE category_id = :cid'
    );
    $orderStmt->execute([':cid' => $categoryId]);
    $nextOrder = (int)$orderStmt->fetchColumn();

    db()->prepare(
        'INSERT INTO subcategories (category_id, name, icon_url, display_order, is_active)
         VALUES (:cid, :name, :icon, :ord, :act)'
    )->execute([
        ':cid' => $categoryId,
        ':name' => $name,
        ':icon' => $iconUrl,
        ':ord' => $nextOrder,
        ':act' => $isActive,
    ]);

    ok(['id' => (int)db()->lastInsertId(), 'message' => 'Subcategory created.'], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Subcategory id is required.');

    $pdo = db();
    $cur = $pdo->prepare('SELECT id, category_id, name, icon_url FROM subcategories WHERE id = :id AND is_active = 1');
    $cur->execute([':id' => $id]);
    $row = $cur->fetch();
    if (!$row) {
        fail('Subcategory not found.', 404);
    }

    $hasName = array_key_exists('name', $b);
    $hasIcon = array_key_exists('icon_url', $b);
    if (!$hasName && !$hasIcon) {
        fail('Nothing to update.');
    }

    $name = $hasName ? trim((string)$b['name']) : (string)$row['name'];
    if ($name === '') fail('Subcategory name is required.');

    $dup = $pdo->prepare(
        'SELECT id FROM subcategories
          WHERE category_id = :cid AND LOWER(name) = LOWER(:name) AND is_active = 1 AND id <> :id
          LIMIT 1'
    );
    $dup->execute([':cid' => (int)$row['category_id'], ':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another subcategory under this category already uses that name.');
    }

    $iconUrl = $hasIcon
        ? normalize_subcategory_icon_url($b['icon_url'])
        : (isset($row['icon_url']) && $row['icon_url'] !== null && $row['icon_url'] !== ''
            ? (string)$row['icon_url']
            : null);

    $upd = $pdo->prepare('UPDATE subcategories SET name = :name, icon_url = :icon WHERE id = :id');
    $upd->execute([':name' => $name, ':icon' => $iconUrl, ':id' => $id]);
    ok([
        'id' => $id,
        'icon_url' => $iconUrl,
        'message' => 'Subcategory updated.',
    ]);
}

if ($m === 'DELETE') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Subcategory id is required.');

    $pdo = db();
    $cur = $pdo->prepare('SELECT id, name FROM subcategories WHERE id = :id AND is_active = 1');
    $cur->execute([':id' => $id]);
    $row = $cur->fetch();
    if (!$row) {
        fail('Subcategory not found.', 404);
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM menu_items WHERE subcategory_id = :id');
    $inUse->execute([':id' => $id]);
    if ((int)$inUse->fetchColumn() > 0) {
        fail('Cannot delete a subcategory that is still used by menu items.');
    }

    $upd = $pdo->prepare('UPDATE subcategories SET is_active = 0 WHERE id = :id');
    $upd->execute([':id' => $id]);
    ok(['id' => $id, 'message' => 'Subcategory deleted.']);
}

fail('Method not allowed.', 405);
