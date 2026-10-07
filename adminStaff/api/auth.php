<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/staff_shifts_helpers.php';

$m = method();

// ─── POST /api/auth.php  →  login or verify ───────────────────────────────────
if ($m === 'POST') {
    $b = body();
    $action = strtolower(trim((string)($b['action'] ?? 'login')));

    // Re-auth check for sensitive actions (e.g. close shift) without logging in again.
    if ($action === 'verify') {
        if (empty($_SESSION['user_id'])) {
            fail('Not authenticated.', 401);
        }
        $password = (string)($b['password'] ?? '');
        if ($password === '') {
            fail('Password is required.');
        }

        $staffId = (int)($b['staff_id'] ?? 0);
        if ($staffId <= 0) {
            $staffId = (int)$_SESSION['user_id'];
        }

        $sessionId = (int)$_SESSION['user_id'];
        $sessionRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
        if ($staffId !== $sessionId && $sessionRole !== 'admin') {
            fail('Not allowed to verify another account.', 403);
        }

        $stmt = db()->prepare(
            'SELECT id, full_name, username, email, password, role, is_active
               FROM users
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $staffId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            fail('Incorrect password.', 401);
        }
        if (!$user['is_active']) {
            fail('Account is deactivated. Contact administrator.', 403);
        }

        ok([
            'verified' => true,
            'user' => [
                'id' => (int)$user['id'],
                'full_name' => $user['full_name'],
                'username' => $user['username'],
                'role' => strtolower(trim((string)($user['role'] ?? 'staff'))),
            ],
        ]);
    }

    // Beacon / explicit logout (sendBeacon can only POST)
    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?: '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        ok(['message' => 'Logged out.']);
    }

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
    $_SESSION['username']  = (string)($user['username'] ?? '');

    $shift = null;
    if ($roleNorm === 'staff') {
        $shift = register_staff_shift_login(db(), (int)$user['id']);
    }

    ok([
        'user' => [
            'id'        => (int)$user['id'],
            'name'      => $user['full_name'],
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
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    ok(['message' => 'Logged out.']);
}

// ─── GET /api/auth.php  →  session check ─────────────────────────────────────
if ($m === 'GET') {
    if (empty($_SESSION['user_id'])) {
        fail('Not authenticated.', 401);
    }
    $uid = (int)$_SESSION['user_id'];
    $username = trim((string)($_SESSION['username'] ?? ''));
    $fullName = (string)($_SESSION['user_name'] ?? '');
    if ($username === '' && $uid > 0) {
        try {
            $stmt = db()->prepare('SELECT username, full_name FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $username = trim((string)($row['username'] ?? ''));
                if ($username !== '') {
                    $_SESSION['username'] = $username;
                }
                if ($fullName === '' && !empty($row['full_name'])) {
                    $fullName = (string)$row['full_name'];
                    $_SESSION['user_name'] = $fullName;
                }
            }
        } catch (Throwable $e) {
            // Session check should still succeed.
        }
    }
    ok([
        'user' => [
            'id'        => $uid,
            'role'      => $_SESSION['user_role'],
            'name'      => $fullName,
            'full_name' => $fullName,
            'username'  => $username,
        ],
    ]);
}

fail('Method not allowed.', 405);
