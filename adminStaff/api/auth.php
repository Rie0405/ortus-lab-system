<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/staff_shifts_helpers.php';

$m = method();

// ─── POST /api/auth.php  →  login ────────────────────────────────────────────
if ($m === 'POST') {
    $b        = body();
    $login    = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';

    if (!$login || !$password) {
        fail('Username/email and password are required.');
    }

    $stmt = db()->prepare(
        'SELECT id, full_name, username, email, password, role, is_active
           FROM users
          WHERE (username = :u1 OR email = :u2)
          LIMIT 1'
    );
    $stmt->execute([':u1' => $login, ':u2' => $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        fail('Invalid credentials.', 401);
    }

    if (!$user['is_active']) {
        fail('Account is deactivated. Contact administrator.', 403);
    }

    $roleNorm = strtolower(trim((string)($user['role'] ?? '')));
    if (!in_array($roleNorm, ['admin', 'staff'], true)) {
        $roleNorm = 'staff';
    }

    // Start session (always lowercase so require_auth('admin') matches DB casing)
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $roleNorm;
    $_SESSION['user_name'] = $user['full_name'];

    $shift = null;
    if ($roleNorm === 'staff') {
        $shift = register_staff_shift_login(db(), (int)$user['id']);
    }

    ok([
        'user' => [
            'id'        => $user['id'],
            'full_name' => $user['full_name'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'role'      => $roleNorm,
        ],
        'shift' => $shift,
        'redirect' => $roleNorm === 'admin' ? 'admin_dashboard.html' : 'staff_dashboard.html',
    ]);
}

// ─── DELETE /api/auth.php  →  logout ─────────────────────────────────────────
if ($m === 'DELETE') {
    $_SESSION = [];
    session_destroy();
    ok(['message' => 'Logged out.']);
}

// ─── GET /api/auth.php  →  session check ─────────────────────────────────────
if ($m === 'GET') {
    if (empty($_SESSION['user_id'])) {
        fail('Not authenticated.', 401);
    }
    ok([
        'user' => [
            'id'   => $_SESSION['user_id'],
            'role' => $_SESSION['user_role'],
            'name' => $_SESSION['user_name'],
        ],
    ]);
}

fail('Method not allowed.', 405);
