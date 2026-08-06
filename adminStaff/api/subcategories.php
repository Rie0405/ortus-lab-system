<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';

ensure_subcategories_schema(db());

$m = method();

if ($m === 'GET') {
    require_auth();
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

    $sql = 'SELECT s.id, s.category_id, c.name AS category_name, s.name, s.display_order
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
        'INSERT INTO subcategories (category_id, name, display_order, is_active)
         VALUES (:cid, :name, :ord, :act)'
    )->execute([
        ':cid' => $categoryId,
        ':name' => $name,
        ':ord' => $nextOrder,
        ':act' => $isActive,
    ]);

    ok(['id' => (int)db()->lastInsertId(), 'message' => 'Subcategory created.'], 201);
}

if ($m === 'PUT') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $name = trim((string)($b['name'] ?? ''));
    if (!$id) fail('Subcategory id is required.');
    if ($name === '') fail('Subcategory name is required.');

    $pdo = db();
    $cur = $pdo->prepare('SELECT id, category_id, name FROM subcategories WHERE id = :id AND is_active = 1');
    $cur->execute([':id' => $id]);
    $row = $cur->fetch();
    if (!$row) {
        fail('Subcategory not found.', 404);
    }

    $dup = $pdo->prepare(
        'SELECT id FROM subcategories
          WHERE category_id = :cid AND LOWER(name) = LOWER(:name) AND is_active = 1 AND id <> :id
          LIMIT 1'
    );
    $dup->execute([':cid' => (int)$row['category_id'], ':name' => $name, ':id' => $id]);
    if ($dup->fetch()) {
        fail('Another subcategory under this category already uses that name.');
    }

    $upd = $pdo->prepare('UPDATE subcategories SET name = :name WHERE id = :id');
    $upd->execute([':name' => $name, ':id' => $id]);
    ok(['id' => $id, 'message' => 'Subcategory updated.']);
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
