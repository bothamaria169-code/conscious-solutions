<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/company.php';

requireLogin();
$user = currentUser();

// Filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // default: start of this month
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$filterBranch = isAdmin() ? (int)($_GET['branch_id'] ?? 0) : (int)$user['branch_id'];

$stats   = getDashboardStats($filterBranch ?: null, $dateFrom, $dateTo);
$totals  = $stats['totals'];
$byBranch = $stats['by_branch'];

$branches = getAllBranches();

$pageTitle = 'Dashboard — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Dashboard</h2>
    <p class="page-subtitle">Welcome back, <?= htmlspecialchars($user['name']) ?></p>
</div>

<!-- Filters -->
<div class="card filter-bar">
    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <?php if (isAdmin()): ?>
        <div class="filter-group">
            <label>Branch</label>
            <select name="branch_id" class="form-control">
                <option value="">All Branches</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_PATH ?>/pages/dashboard.php" class="btn btn-outline">Reset</a>
    </form>
</div>

<!-- Stat Cards -->
<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-icon">🔧</div>
        <div class="stat-value"><?= number_format((int)$totals['total_vehicles']) ?></div>
        <div class="stat-label">Vehicles Serviced</div>
    </div>
    <?php if (isAdmin()): ?>
    <div class="stat-card stat-green">
        <div class="stat-icon">💰</div>
        <div class="stat-value"><?= formatMoney((float)$totals['total_value']) ?></div>
        <div class="stat-label">Total Invoiced</div>
    </div>
    <div class="stat-card stat-teal">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?= formatMoney((float)$totals['paid']) ?></div>
        <div class="stat-label">Paid</div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?= formatMoney((float)$totals['outstanding']) ?></div>
        <div class="stat-label">Outstanding</div>
    </div>
    <?php endif; ?>
</div>

<!-- Branch Breakdown (admin only) -->
<?php if (isAdmin()): ?>
<div class="card">
    <h3 class="card-title">Branch Breakdown</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Branch</th>
                <th>Vehicles</th>
                <th>Total (R)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($byBranch as $row): ?>
            <tr>
                <td>
                    <span class="branch-badge branch-<?= strtolower($row['invoice_prefix']) ?>">
                        <?= htmlspecialchars($row['invoice_prefix']) ?>
                    </span>
                    <?= htmlspecialchars($row['name']) ?>
                </td>
                <td><?= (int)$row['count'] ?></td>
                <td><?= formatMoney((float)$row['total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Quick Links -->
<div class="quick-links">
    <a href="<?= BASE_PATH ?>/pages/invoices.php?action=new" class="btn btn-primary btn-lg">+ New Invoice</a>
    <a href="<?= BASE_PATH ?>/pages/invoices.php" class="btn btn-outline btn-lg">View All Invoices</a>
    <?php if (isAdmin()): ?>
    <a href="<?= BASE_PATH ?>/pages/statement.php" class="btn btn-outline btn-lg">Monthly Statement</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/timeclock.php" class="btn btn-outline btn-lg">⏱ Time Clock</a>
    <?php if (isAdmin()): ?>
    <a href="<?= BASE_PATH ?>/pages/attendance.php" class="btn btn-outline btn-lg">📋 Attendance</a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
