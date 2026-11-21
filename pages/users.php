<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';

requireAdmin();

$branches = getAllBranches();
$action   = $_GET['action'] ?? 'list';
$message  = '';
$error    = '';
$editUser = null;

// ─── CREATE USER ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'new') {
    $result = createUser([
        'name'      => trim($_POST['name']),
        'phone'     => trim($_POST['phone']),
        'pin'       => trim($_POST['pin']),
        'role'      => $_POST['role'],
        'branch_id' => $_POST['branch_id'] ?: null,
    ]);
    $message = $result['success'] ? 'User created successfully.' : '';
    $error   = $result['error'] ?? '';
    if ($result['success']) $action = 'list';
}

// ─── EDIT USER ─────────────────────────────────────────────────────────────────
if ($action === 'edit' && isset($_GET['id'])) {
    $editUser = getUserById((int)$_GET['id']);
    if (!$editUser) $action = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $uid    = (int)$_POST['user_id'];
    $result = updateUser($uid, [
        'name'      => trim($_POST['name']),
        'phone'     => trim($_POST['phone']),
        'pin'       => trim($_POST['pin']),
        'role'      => $_POST['role'],
        'branch_id' => $_POST['branch_id'] ?? '',   // updateUser() handles null logic
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ]);
    $message = $result['success'] ? 'User updated.' : '';
    $error   = $result['error'] ?? '';
    if ($result['success']) $action = 'list';
    else { $editUser = getUserById($uid); }
}

// ─── DEACTIVATE USER ──────────────────────────────────────────────────────────
if ($action === 'deactivate' && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    if ($uid !== (int)currentUser()['id']) { // don't self-deactivate
        deleteUser($uid);
        $message = 'User deactivated.';
    }
    $action = 'list';
}

$usersList = ($action === 'list') ? getAllUsers() : [];

$pageTitle = 'Users — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || ($action === 'edit' && $editUser)): ?>

<!-- ═══ USER FORM ══════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <h2 class="page-title"><?= $action === 'new' ? 'Add New User' : 'Edit User' ?></h2>
    <a href="<?= BASE_PATH ?>/pages/users.php" class="btn btn-outline">← Back</a>
</div>

<div class="card card-narrow">
    <form method="POST" action="<?= BASE_PATH ?>/pages/users.php?action=<?= $action ?><?= $action === 'edit' ? '&id=' . $editUser['id'] : '' ?>">
        <?php if ($action === 'edit'): ?>
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="name" class="form-control" required
                   value="<?= htmlspecialchars($editUser['name'] ?? $_POST['name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Phone Number *</label>
            <input type="tel" name="phone" class="form-control" required maxlength="15"
                   placeholder="e.g. 0821234567"
                   value="<?= htmlspecialchars($editUser['phone'] ?? $_POST['phone'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label><?= $action === 'edit' ? 'New PIN (leave blank to keep current)' : 'PIN *' ?></label>
            <input type="password" name="pin" class="form-control" <?= $action === 'new' ? 'required' : '' ?>
                   placeholder="4–10 digits" maxlength="10" inputmode="numeric">
        </div>

        <div class="form-group">
            <label>Role *</label>
            <select name="role" class="form-control" required id="roleSelect">
                <option value="technician" <?= ($editUser['role'] ?? 'technician') === 'technician' ? 'selected' : '' ?>>Technician</option>
                <option value="master_admin" <?= ($editUser['role'] ?? '') === 'master_admin' ? 'selected' : '' ?>>Master Admin</option>
            </select>
        </div>

        <div class="form-group" id="branchGroup">
            <label>Branch *</label>
            <select name="branch_id" class="form-control" id="branchSelect">
                <option value="">None (Admin)</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>"
                        <?= ($editUser['branch_id'] ?? $_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($action === 'edit'): ?>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1"
                       <?= ($editUser['is_active'] ?? 1) ? 'checked' : '' ?>>
                Active Account
            </label>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $action === 'new' ? 'Create User' : 'Save Changes' ?></button>
            <a href="<?= BASE_PATH ?>/pages/users.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
// Show/hide branch based on role
const roleSelect   = document.getElementById('roleSelect');
const branchGroup  = document.getElementById('branchGroup');
function toggleBranch() {
    branchGroup.style.display = roleSelect.value === 'master_admin' ? 'none' : 'block';
}
roleSelect.addEventListener('change', toggleBranch);
toggleBranch();
</script>

<?php else: ?>

<!-- ═══ USER LIST ══════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <h2 class="page-title">Users</h2>
    <a href="<?= BASE_PATH ?>/pages/users.php?action=new" class="btn btn-primary">+ Add User</a>
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Branch</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usersList as $u): ?>
            <tr class="<?= !$u['is_active'] ? 'row-inactive' : '' ?>">
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['phone']) ?></td>
                <td>
                    <span class="<?= $u['role'] === 'master_admin' ? 'badge-admin' : 'badge-mechanic' ?> user-badge">
                        <?= $u['role'] === 'master_admin' ? 'Admin' : 'Technician' ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($u['branch_name'] ?? '—') ?></td>
                <td>
                    <span class="status-badge <?= $u['is_active'] ? 'status-paid' : 'status-outstanding' ?>">
                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <a href="<?= BASE_PATH ?>/pages/users.php?action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <?php if ($u['id'] != currentUser()['id'] && $u['is_active']): ?>
                    <a href="<?= BASE_PATH ?>/pages/users.php?action=deactivate&id=<?= $u['id'] ?>"
                       class="btn btn-sm btn-danger"
                       onclick="return confirm('Deactivate this user?')">Deactivate</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
