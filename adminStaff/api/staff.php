<?php
require_once __DIR__ . '/config.php';
require_auth('admin');

$m = method();

// ─── GET  →  list staff accounts ─────────────────────────────────────────────
if ($m === 'GET') {
    $stmt = db()->query(
        'SELECT id, full_name, username, email, role, is_active, created_at
           FROM users
          ORDER BY role DESC, full_name'
    );
    $users = $stmt->fetchAll();
    foreach ($users as &$u) {
        $u['id']        = (int)$u['id'];
        $u['is_active'] = (bool)$u['is_active'];
        unset($u['password']);
    }
    unset($u);
    ok(['users' => $users]);
}

// ─── POST  →  create staff / admin account ───────────────────────────────────
if ($m === 'POST') {
    $b         = body();
    $fullName  = trim($b['full_name']  ?? '');
    $username  = trim($b['username']   ?? '');
    $email     = trim($b['email']      ?? '');
    $password  = $b['password'] ?? '';
    $role      = in_array($b['role'] ?? '', ['admin', 'staff']) ? $b['role'] : 'staff';

    if (!$fullName) fail('Full name is required.');
    if (!$username) fail('Username is required.');
    if (strlen($password) < 6) fail('Password must be at least 6 characters.');

    $emailProvided = trim($b['email'] ?? '');
    if ($emailProvided !== '') {
        $email = $emailProvided;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Valid email is required.');
    } else {
        $base = preg_replace('/[^a-z0-9]+/i', '.', strtolower($username));
        $base = trim($base, '.') ?: 'user';
        $email = $base . '@ortus.local';
        $suffix = 0;
        while (true) {
            $candidate = $suffix === 0 ? $email : ($base . $suffix . '@ortus.local');
            $dupEmail = db()->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
            $dupEmail->execute([':e' => $candidate]);
            if (!$dupEmail->fetch()) {
                $email = $candidate;
                break;
            }
            $suffix++;
        }
    }

    // Check duplicates
    $dup = db()->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $dup->execute([':u' => $username]);
    if ($dup->fetch()) fail('Username already exists.');

    if ($emailProvided !== '') {
        $dupEmail = db()->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $dupEmail->execute([':e' => $email]);
        if ($dupEmail->fetch()) fail('Email already exists.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = db()->prepare(
        'INSERT INTO users (full_name, username, email, password, role)
         VALUES (:fn, :un, :em, :pw, :role)'
    );
    $stmt->execute([
        ':fn'   => $fullName,
        ':un'   => $username,
        ':em'   => $email,
        ':pw'   => $hash,
        ':role' => $role,
    ]);

    ok(['id' => (int)db()->lastInsertId(), 'message' => 'Account created.'], 201);
}

// ─── PUT  →  update account ───────────────────────────────────────────────────
if ($m === 'PUT') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('User ID is required.');

    $fields = [];
    $params = [':id' => $id];

    if (!empty($b['full_name'])) { $fields[] = 'full_name = :fn';  $params[':fn']   = trim($b['full_name']); }
    if (!empty($b['username']))  { $fields[] = 'username = :un';   $params[':un']   = trim($b['username']); }
    if (!empty($b['email']))     { $fields[] = 'email = :em';      $params[':em']   = trim($b['email']); }
    if (!empty($b['password']))  { $fields[] = 'password = :pw';   $params[':pw']   = password_hash($b['password'], PASSWORD_BCRYPT); }
    if (isset($b['is_active']))  { $fields[] = 'is_active = :act'; $params[':act']  = (int)(bool)$b['is_active']; }
    if (isset($b['role']) && in_array($b['role'], ['admin','staff'])) {
                                   $fields[] = 'role = :role';     $params[':role'] = $b['role']; }

    if (!$fields) fail('No fields to update.');

    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')
        ->execute($params);

    ok(['message' => 'Account updated.']);
}

// ─── DELETE  →  deactivate (soft delete) ─────────────────────────────────────
if ($m === 'DELETE') {
    $b  = body();
    $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if (!$id) fail('User ID is required.');

    // Prevent self-deletion
    if ($id === (int)$_SESSION['user_id']) fail('Cannot deactivate your own account.');

    db()->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);
    ok(['message' => 'Account deactivated.']);
}

fail('Method not allowed.', 405);
