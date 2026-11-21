<?php
// includes/auth.php — Authentication helpers

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

function login(string $phone, string $pin): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, pin_hash, role, branch_id FROM users WHERE phone = ? AND is_active = 1');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if ($user && password_verify($pin, $user['pin_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user_name']     = $user['name'];
        $_SESSION['user_role']     = $user['role'];
        $_SESSION['branch_id']     = $user['branch_id'];
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

function logout(): void {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['user_role'] !== ROLE_ADMIN) {
        header('Location: ' . BASE_PATH . '/pages/dashboard.php?error=unauthorized');
        exit;
    }
}

function isAdmin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === ROLE_ADMIN;
}

function currentUser(): array {
    // Always fetch fresh from DB so branch reassignments take effect immediately
    // without requiring the user to log out and back in.
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return ['id' => null, 'name' => '', 'role' => '', 'branch_id' => null];
    }
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, role, branch_id FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$uid]);
    $row  = $stmt->fetch();
    if (!$row) {
        // User deactivated — force logout
        session_unset();
        session_destroy();
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
    // Always keep session fully in sync with DB
    $_SESSION['user_name'] = $row['name'];
    $_SESSION['user_role'] = $row['role'];
    $_SESSION['branch_id'] = $row['branch_id'];
    return [
        'id'        => $row['id'],
        'name'      => $row['name'],
        'role'      => $row['role'],
        'branch_id' => $row['branch_id'],
    ];
}

function hashPin(string $pin): string {
    return password_hash($pin, PASSWORD_BCRYPT);
}
