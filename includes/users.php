<?php
// includes/users.php — User management helpers

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

function getAllUsers(): array {
    $db = getDB();
    $stmt = $db->query('SELECT u.id, u.name, u.phone, u.role, u.is_active, u.created_at,
                               u.branch_id, b.name AS branch_name
                        FROM users u
                        LEFT JOIN branches b ON b.id = u.branch_id
                        ORDER BY u.role DESC, u.name ASC');
    return $stmt->fetchAll();
}

function getUserById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function createUser(array $data): array {
    $db = getDB();
    $check = $db->prepare('SELECT id FROM users WHERE phone = ?');
    $check->execute([$data['phone']]);
    if ($check->fetch()) return ['success' => false, 'error' => 'Phone number already registered.'];

    $stmt = $db->prepare('INSERT INTO users (name, phone, pin_hash, role, branch_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['name'],
        $data['phone'],
        hashPin($data['pin']),
        $data['role'],
        $data['branch_id'] ?: null,
    ]);
    return ['success' => true, 'id' => $db->lastInsertId()];
}

function updateUser(int $id, array $data): array {
    $db = getDB();
    // Check phone unique (excluding self)
    $check = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
    $check->execute([$data['phone'], $id]);
    if ($check->fetch()) return ['success' => false, 'error' => 'Phone number already in use.'];

    $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== '0'
        ? (int)$data['branch_id']
        : null;

    if (!empty($data['pin'])) {
        $stmt = $db->prepare('UPDATE users SET name=?, phone=?, pin_hash=?, role=?, branch_id=?, is_active=? WHERE id=?');
        $stmt->execute([
            $data['name'],
            $data['phone'],
            hashPin($data['pin']),
            $data['role'],
            $branchId,
            (int)($data['is_active'] ?? 1),
            $id,
        ]);
    } else {
        $stmt = $db->prepare('UPDATE users SET name=?, phone=?, role=?, branch_id=?, is_active=? WHERE id=?');
        $stmt->execute([
            $data['name'],
            $data['phone'],
            $data['role'],
            $branchId,
            (int)($data['is_active'] ?? 1),
            $id,
        ]);
    }

    // If the updated user is currently logged in, sync their session immediately
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id) {
        $_SESSION['user_role']  = $data['role'];
        $_SESSION['branch_id']  = $branchId;
        $_SESSION['user_name']  = $data['name'];
    }

    return ['success' => true];
}

function deleteUser(int $id): bool {
    // Soft delete — deactivate
    $db = getDB();
    $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
    return $stmt->execute([$id]);
}

function getAllBranches(): array {
    $db = getDB();
    return $db->query('SELECT * FROM branches ORDER BY id')->fetchAll();
}
