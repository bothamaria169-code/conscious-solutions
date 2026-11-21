<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/company.php';

requireAdmin();
$branches = getAllBranches();

// ─── FILTER PARAMS ────────────────────────────────────────────────────────────
$mode     = in_array($_GET['mode'] ?? 'month', ['month', 'range']) ? ($_GET['mode'] ?? 'month') : 'month';
$month    = $_GET['month']     ?? date('Y-m');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';

// Selected branch IDs (empty = all)
$selectedIds = isset($_GET['branch_ids'])
    ? array_values(array_filter(array_map('intval', (array)$_GET['branch_ids']), fn($id) => $id > 0))
    : [];
$filterBranches = empty($selectedIds)
    ? $branches
    : array_values(array_filter($branches, fn($b) => in_array((int)$b['id'], $selectedIds)));

// ─── PERIOD LABEL ─────────────────────────────────────────────────────────────
if ($mode === 'range') {
    $fromLabel    = $dateFrom ? date('d M Y', strtotime($dateFrom)) : '—';
    $toLabel      = $dateTo   ? date('d M Y', strtotime($dateTo))   : '—';
    $periodLabel  = "$fromLabel – $toLabel";
    $titleDisplay = "Statement &mdash; $fromLabel &ndash; $toLabel";
} else {
    $periodLabel  = date('F Y', strtotime($month . '-01'));
    $titleDisplay = "Monthly Statement &mdash; $periodLabel";
}

// ─── BUILD PER-BRANCH DATA ────────────────────────────────────────────────────
$branchData = [];
foreach ($filterBranches as $branch) {
    $invFilters = ['branch_id' => $branch['id']];
    if ($mode === 'range') {
        if ($dateFrom) $invFilters['date_from'] = $dateFrom;
        if ($dateTo)   $invFilters['date_to']   = $dateTo;
    } else {
        $invFilters['month'] = $month;
    }
    $invoices    = getInvoices($invFilters);
    $total       = array_sum(array_column($invoices, 'amount'));
    $paid        = array_sum(array_map(fn($i) => $i['status'] === 'paid'        ? (float)$i['amount'] : 0, $invoices));
    $outstanding = array_sum(array_map(fn($i) => $i['status'] === 'outstanding' ? (float)$i['amount'] : 0, $invoices));
    $count200    = count(array_filter($invoices, fn($i) => (float)$i['amount'] === 200.0));
    $count250    = count(array_filter($invoices, fn($i) => (float)$i['amount'] === 250.0));
    $count350    = count(array_filter($invoices, fn($i) => (float)$i['amount'] === 350.0));
    $countOther  = count($invoices) - $count200 - $count250 - $count350;

    $branchData[] = [
        'branch'      => $branch,
        'invoices'    => $invoices,
        'total'       => $total,
        'paid'        => $paid,
        'outstanding' => $outstanding,
        'count'       => count($invoices),
        'count200'    => $count200,
        'count250'    => $count250,
        'count350'    => $count350,
        'countOther'  => $countOther,
    ];
}

$pageTitle = 'Statement — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title"><?= $titleDisplay ?></h2>
</div>

<!-- ═══ FILTER BAR ══════════════════════════════════════════════════════════ -->
<div class="card filter-bar">
    <form method="GET" id="stmt-filter-form" class="filter-form" style="align-items:flex-start; flex-wrap:wrap; gap:16px;">

        <!-- Branches multi-select -->
        <div class="filter-group">
            <label>Branches</label>
            <div class="branch-check-group">
                <?php foreach ($branches as $b):
                    $checked = empty($selectedIds) || in_array((int)$b['id'], $selectedIds);
                ?>
                <label class="branch-check-label">
                    <input type="checkbox" name="branch_ids[]" value="<?= $b['id'] ?>"
                           <?= $checked ? 'checked' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Period type toggle -->
        <div class="filter-group">
            <label>Period Type</label>
            <div class="mode-toggle">
                <label class="toggle-btn <?= $mode === 'month' ? 'active' : '' ?>">
                    <input type="radio" name="mode" value="month" <?= $mode === 'month' ? 'checked' : '' ?>>
                    Monthly
                </label>
                <label class="toggle-btn <?= $mode === 'range' ? 'active' : '' ?>">
                    <input type="radio" name="mode" value="range" <?= $mode === 'range' ? 'checked' : '' ?>>
                    Date Range
                </label>
            </div>
        </div>

        <!-- Monthly picker -->
        <div class="filter-group" id="group-month" <?= $mode === 'range' ? 'style="display:none"' : '' ?>>
            <label>Month</label>
            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
        </div>

        <!-- Date range pickers -->
        <div class="filter-group" id="group-from" <?= $mode === 'month' ? 'style="display:none"' : '' ?>>
            <label>From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="filter-group" id="group-to" <?= $mode === 'month' ? 'style="display:none"' : '' ?>>
            <label>To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>

        <div style="display:flex; gap:8px; align-items:flex-end; padding-bottom:2px;">
            <button type="submit" class="btn btn-primary">View</button>
            <a href="<?= BASE_PATH ?>/pages/statement.php" class="btn btn-outline">Reset</a>
        </div>
    </form>
</div>

<!-- ═══ PER-BRANCH RESULTS ═══════════════════════════════════════════════════ -->
<?php if (empty($branchData)): ?>
    <div class="card"><div class="empty-state">No branches selected.</div></div>
<?php endif; ?>

<?php foreach ($branchData as $bd): ?>
<?php $b = $bd['branch']; ?>

<?php
// Build PDF URL with the correct period params
if ($mode === 'range') {
    $pdfQuery = http_build_query([
        'branch_id' => $b['id'],
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
    ]);
} else {
    $pdfQuery = http_build_query([
        'branch_id' => $b['id'],
        'month'     => $month,
    ]);
}
$pdfUrl = BASE_PATH . '/pages/print_statement.php?' . $pdfQuery;
?>

<div class="branch-statement-block">

    <div class="branch-statement-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <span class="branch-badge branch-<?= strtolower($b['invoice_prefix']) ?> branch-badge-lg">
                <?= htmlspecialchars($b['invoice_prefix']) ?>
            </span>
            <div>
                <h3 class="branch-statement-title">Hyundai <?= htmlspecialchars($b['name']) ?></h3>
                <div class="branch-statement-meta"><?= htmlspecialchars($b['address']) ?></div>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="<?= $pdfUrl ?>" class="btn btn-primary" target="_blank">
                View Statement PDF
            </a>
        </div>
    </div>

    <div class="stats-grid stats-grid-4" style="margin-bottom:16px;">
        <div class="stat-card stat-blue">
            <div class="stat-icon">📄</div>
            <div class="stat-value"><?= $bd['count'] ?></div>
            <div class="stat-label">Invoices</div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon">💰</div>
            <div class="stat-value"><?= formatMoney($bd['total']) ?></div>
            <div class="stat-label">Total Invoiced</div>
        </div>
        <div class="stat-card stat-teal">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= formatMoney($bd['paid']) ?></div>
            <div class="stat-label">Paid</div>
        </div>
        <div class="stat-card stat-orange">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?= formatMoney($bd['outstanding']) ?></div>
            <div class="stat-label">Outstanding</div>
        </div>
    </div>

    <?php if (empty($bd['invoices'])): ?>
        <div class="empty-state">No invoices for <?= htmlspecialchars($b['name']) ?> in this period.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Order #</th>
                    <th>Model</th>
                    <th>Reg No</th>
                    <th>Date</th>
                    <th>Technician</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bd['invoices'] as $inv): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                    <td><?= htmlspecialchars($inv['order_number']) ?></td>
                    <td><?= htmlspecialchars($inv['model'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($inv['registration_no'] ?? '—') ?></td>
                    <td><?= date('d M Y', strtotime($inv['service_date'])) ?></td>
                    <td><?= htmlspecialchars($inv['mechanic_name']) ?></td>
                    <td>R<?= number_format((float)$inv['amount'], 2) ?></td>
                    <td><span class="status-badge status-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                    <td><a href="<?= BASE_PATH ?>/pages/invoices.php?action=view&id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="totals-row">
                    <td colspan="6">
                        <strong>TOTAL</strong>
                        &nbsp;&mdash;&nbsp; <?= $bd['count'] ?> invoices
                        <?php if ($bd['count200'] > 0): ?>&nbsp;|&nbsp; <?= $bd['count200'] ?> &times; R200<?php endif; ?>
                        <?php if ($bd['count250'] > 0): ?>&nbsp;|&nbsp; <?= $bd['count250'] ?> &times; R250<?php endif; ?>
                        <?php if ($bd['count350'] > 0): ?>&nbsp;|&nbsp; <?= $bd['count350'] ?> &times; R350<?php endif; ?>
                        <?php if ($bd['countOther'] > 0): ?>&nbsp;|&nbsp; <?= $bd['countOther'] ?> other<?php endif; ?>
                    </td>
                    <td><strong><?= formatMoney($bd['total']) ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

</div>
<?php endforeach; ?>

<script>
(function() {
    var radios     = document.querySelectorAll('[name="mode"]');
    var monthGroup = document.getElementById('group-month');
    var fromGroup  = document.getElementById('group-from');
    var toGroup    = document.getElementById('group-to');

    function applyMode() {
        var isRange = document.querySelector('[name="mode"]:checked').value === 'range';
        monthGroup.style.display = isRange ? 'none' : '';
        fromGroup.style.display  = isRange ? '' : 'none';
        toGroup.style.display    = isRange ? '' : 'none';
        // Update active state on toggle buttons
        document.querySelectorAll('.toggle-btn').forEach(function(btn) {
            var r = btn.querySelector('input[type="radio"]');
            btn.classList.toggle('active', r && r.checked);
        });
    }

    radios.forEach(function(r) { r.addEventListener('change', applyMode); });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
