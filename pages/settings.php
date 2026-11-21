<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/company.php';

requireAdmin();
$user = currentUser();
$msg  = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (saveCompanySettings($_POST)) {
        header('Location: ' . BASE_PATH . '/pages/settings.php?msg=saved');
        exit;
    }
    $error = 'Could not save settings — check server file permissions.';
}

if (($_GET['msg'] ?? '') === 'saved') $msg = 'Company details saved successfully.';

$cs = getCompanySettings();
$pageTitle = 'Settings — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-title">Settings</h2>
        <p class="page-subtitle">Company details printed on payslips and statements</p>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card card-narrow">
    <h3 class="card-title">Company Details</h3>
    <form method="POST">
        <div class="form-group">
            <label class="form-label">Company Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cs['name']) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Registration Number</label>
            <input type="text" name="reg" class="form-control" value="<?= htmlspecialchars($cs['reg']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($cs['address']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($cs['email']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($cs['phone']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" name="hide_financials" value="1" style="width:16px; height:16px; cursor:pointer;" <?= !empty($cs['hide_financials']) ? 'checked' : '' ?>>
                Demo Mode — hide revenue totals
            </label>
            <p style="color:#6b7280; font-size:13px; margin:4px 0 0 26px;">
                Masks the Dashboard and Statement revenue totals (Total Invoiced, Paid, Outstanding, branch totals)
                and the invoice list total. Useful when showcasing the system without revealing how much the
                business has made. Individual invoice amounts and printed invoices are unaffected.
            </p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
