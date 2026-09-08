<?php
require_once __DIR__ . '/config.php';

function ensure_suppliers_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(140) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_suppliers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function normalize_supplier_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '' || strcasecmp($name, 'Unassigned') === 0) {
        return '';
    }
    if (stripos($name, 'Restocked by ') === 0) {
        return '';
    }
    return substr($name, 0, 140);
}

function list_saved_suppliers(PDO $pdo): array
{
    ensure_suppliers_table($pdo);
    $rows = $pdo->query(
        'SELECT id, name
         FROM inventory_suppliers
         WHERE is_active = 1
         ORDER BY name ASC'
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $name = normalize_supplier_name((string)($row['name'] ?? ''));
        if ($name === '') continue;
        $out[] = [
            'id' => (int)$row['id'],
            'name' => $name,
        ];
    }
    return $out;
}

$m = method();
$pdo = db();
ensure_suppliers_table($pdo);

if ($m === 'GET') {
    require_auth();
    ok(['suppliers' => list_saved_suppliers($pdo)]);
}

if ($m === 'POST') {
    require_auth();
    $b = body();
    $name = normalize_supplier_name((string)($b['name'] ?? ''));
    if ($name === '') {
        fail('Supplier name is required.');
    }

    $find = $pdo->prepare(
        'SELECT id, name, is_active
         FROM inventory_suppliers
         WHERE LOWER(name) = LOWER(:name)
         LIMIT 1'
    );
    $find->execute([':name' => $name]);
    $existing = $find->fetch();

    if ($existing) {
        if (!(int)$existing['is_active']) {
            $reactivate = $pdo->prepare(
                'UPDATE inventory_suppliers
                 SET is_active = 1, name = :name
                 WHERE id = :id'
            );
            $reactivate->execute([
                ':name' => $name,
                ':id' => (int)$existing['id'],
            ]);
        }
        ok([
            'id' => (int)$existing['id'],
            'name' => $name,
            'message' => 'Supplier already saved.',
            'suppliers' => list_saved_suppliers($pdo),
        ]);
    }

    $ins = $pdo->prepare(
        'INSERT INTO inventory_suppliers (name, is_active)
         VALUES (:name, 1)'
    );
    $ins->execute([':name' => $name]);

    ok([
        'id' => (int)$pdo->lastInsertId(),
        'name' => $name,
        'message' => 'Supplier saved.',
        'suppliers' => list_saved_suppliers($pdo),
    ], 201);
}

if ($m === 'DELETE') {
    require_auth();
    $b = body();
    $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        fail('Supplier id is required.');
    }

    $upd = $pdo->prepare(
        'UPDATE inventory_suppliers
         SET is_active = 0
         WHERE id = :id'
    );
    $upd->execute([':id' => $id]);
    if ($upd->rowCount() < 1) {
        fail('Supplier not found.', 404);
    }

    ok([
        'message' => 'Supplier removed.',
        'suppliers' => list_saved_suppliers($pdo),
    ]);
}

fail('Method not allowed.', 405);
