<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/timeclock.php';
require_once __DIR__ . '/../includes/users.php';

requireAdmin();
$user = currentUser();

$branches = getAllBranches();

// Filters
$filterBranch = (int)($_GET['branch_id'] ?? 0);
$dateFrom     = $_GET['date_from'] ?? date('Y-m-01');
$dateTo       = $_GET['date_to']   ?? date('Y-m-d');

$dailySummary   = getDailySummary($filterBranch ?: null, $dateFrom, $dateTo);
$weeklySummary  = getWeeklySummary($filterBranch ?: null, $dateFrom, $dateTo);
$monthlySummary = getMonthlySummary($filterBranch ?: null, $dateFrom, $dateTo);
$rawEntries     = getAllTimeEntries($filterBranch ?: null, $dateFrom, $dateTo);

// Standard hourly rate for calculation (can be edited by admin)
$hourlyRate = (float)($_GET['rate'] ?? 0); // 0 = not set

$pageTitle = 'Attendance & Hours — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">📋 Attendance & Hours</h2>
    <p class="page-subtitle">Staff clock-in records and payroll summary</p>
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
        <div class="filter-group">
            <label>Hourly Rate (R)</label>
            <input type="number" name="rate" class="form-control" min="0" step="0.01"
                   placeholder="e.g. 25.00" value="<?= $hourlyRate > 0 ? $hourlyRate : '' ?>"
                   style="width:130px;">
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_PATH ?>/pages/attendance.php" class="btn btn-outline">Reset</a>
    </form>
</div>

<!-- ═══ DAILY SUMMARY PER STAFF ════════════════════════════════════════════════ -->
<div class="card">
    <h3 class="card-title">Daily Summary
        <span style="font-size:13px; font-weight:400; color:#666; margin-left:8px;">
            Standard day: <?= STANDARD_START ?> – <?= STANDARD_END ?> (<?= STANDARD_DAY_MINUTES/60 ?> hrs)
        </span>
    </h3>

    <?php if (empty($dailySummary)): ?>
        <div class="empty-state">No completed clock-in records for this period.</div>
    <?php else: ?>

    <?php
    // Group by user for totals
    $userTotals = [];
    foreach ($dailySummary as $row) {
        $uid = $row['user_id'];
        if (!isset($userTotals[$uid])) {
            $userTotals[$uid] = ['name' => $row['user_name'], 'branch' => $row['branch_name'], 'total_minutes' => 0, 'days' => 0];
        }
        $userTotals[$uid]['total_minutes'] += $row['total_minutes'];
        $userTotals[$uid]['days']++;
    }
    ?>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Branch</th>
                    <th>Date</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Hours</th>
                    <th>vs Standard</th>
                    <?php if ($hourlyRate > 0): ?><th>Pay (R)</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailySummary as $row): ?>
                <?php
                    $diff = (int)$row['total_minutes'] - STANDARD_DAY_MINUTES;
                    $diffStr = ($diff >= 0 ? '+' : '') . formatMinutes(abs($diff));
                    $diffColor = $diff >= 0 ? '#2e7d32' : '#c62828';
                    $pay = $hourlyRate > 0 ? number_format(($row['total_minutes'] / 60) * $hourlyRate, 2) : null;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['user_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['branch_name']) ?></td>
                    <td><?= date('d M Y', strtotime($row['work_date'])) ?></td>
                    <td><?= date('H:i', strtotime($row['first_in'])) ?></td>
                    <td><?= date('H:i', strtotime($row['last_out'])) ?></td>
                    <td><strong><?= formatMinutes((int)$row['total_minutes']) ?></strong></td>
                    <td style="color:<?= $diffColor ?>; font-weight:600;"><?= $diffStr ?></td>
                    <?php if ($hourlyRate > 0): ?><td>R<?= $pay ?></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Totals per user -->
    <div style="margin-top:24px;">
        <h4 style="color:#003087; margin-bottom:12px;">Period Totals per Staff Member</h4>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Branch</th>
                        <th>Days Worked</th>
                        <th>Total Hours</th>
                        <th>Avg per Day</th>
                        <?php if ($hourlyRate > 0): ?><th>Total Pay (R)</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userTotals as $ut): ?>
                    <?php
                        $avgMin = $ut['days'] > 0 ? intdiv($ut['total_minutes'], $ut['days']) : 0;
                        $totalPay = $hourlyRate > 0 ? number_format(($ut['total_minutes'] / 60) * $hourlyRate, 2) : null;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ut['name']) ?></strong></td>
                        <td><?= htmlspecialchars($ut['branch']) ?></td>
                        <td><?= $ut['days'] ?></td>
                        <td><strong><?= formatMinutes($ut['total_minutes']) ?></strong></td>
                        <td><?= formatMinutes($avgMin) ?> / day</td>
                        <?php if ($hourlyRate > 0): ?><td><strong>R<?= $totalPay ?></strong></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- ═══ WEEKLY SUMMARY ══════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
    <h3 class="card-title">Weekly Summary
        <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px;">8h/day target per day worked</span>
    </h3>

    <?php if (empty($weeklySummary)): ?>
        <div class="empty-state">No completed clock-ins for this period.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Branch</th>
                    <th>Week</th>
                    <th>Days</th>
                    <th>Total Hours</th>
                    <th>Target</th>
                    <th>vs Target</th>
                    <?php if ($hourlyRate > 0): ?><th>Pay (R)</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weeklySummary as $row): ?>
                <?php
                    $target    = (int)$row['days_worked'] * STANDARD_DAY_MINUTES;
                    $diff      = (int)$row['total_minutes'] - $target;
                    $diffStr   = ($diff >= 0 ? '+' : '−') . formatMinutes(abs($diff));
                    $diffColor = $diff >= 0 ? '#2e7d32' : '#c62828';
                    $pay       = $hourlyRate > 0 ? number_format(($row['total_minutes'] / 60) * $hourlyRate, 2) : null;
                    $weekLabel = date('d M', strtotime($row['week_start'])) . ' – ' . date('d M Y', strtotime($row['week_end']));
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['user_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['branch_name']) ?></td>
                    <td><?= $weekLabel ?></td>
                    <td><?= (int)$row['days_worked'] ?></td>
                    <td><strong><?= formatMinutes((int)$row['total_minutes']) ?></strong></td>
                    <td><?= formatMinutes($target) ?></td>
                    <td style="color:<?= $diffColor ?>;font-weight:600;"><?= $diffStr ?></td>
                    <?php if ($hourlyRate > 0): ?><td>R<?= $pay ?></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ MONTHLY SUMMARY ══════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
    <h3 class="card-title">Monthly Summary
        <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px;">8h/day target per day worked</span>
    </h3>

    <?php if (empty($monthlySummary)): ?>
        <div class="empty-state">No completed clock-ins for this period.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Branch</th>
                    <th>Month</th>
                    <th>Days</th>
                    <th>Total Hours</th>
                    <th>Target</th>
                    <th>vs Target</th>
                    <?php if ($hourlyRate > 0): ?><th>Pay (R)</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlySummary as $row): ?>
                <?php
                    $target     = (int)$row['days_worked'] * STANDARD_DAY_MINUTES;
                    $diff       = (int)$row['total_minutes'] - $target;
                    $diffStr    = ($diff >= 0 ? '+' : '−') . formatMinutes(abs($diff));
                    $diffColor  = $diff >= 0 ? '#2e7d32' : '#c62828';
                    $pay        = $hourlyRate > 0 ? number_format(($row['total_minutes'] / 60) * $hourlyRate, 2) : null;
                    $monthLabel = date('F Y', strtotime($row['work_month'] . '-01'));
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['user_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['branch_name']) ?></td>
                    <td><?= $monthLabel ?></td>
                    <td><?= (int)$row['days_worked'] ?></td>
                    <td><strong><?= formatMinutes((int)$row['total_minutes']) ?></strong></td>
                    <td><?= formatMinutes($target) ?></td>
                    <td style="color:<?= $diffColor ?>;font-weight:600;"><?= $diffStr ?></td>
                    <?php if ($hourlyRate > 0): ?><td>R<?= $pay ?></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ RAW CLOCK LOG ═══════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
    <h3 class="card-title">Full Clock Log</h3>

    <?php if (empty($rawEntries)): ?>
        <div class="empty-state">No entries for this period.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Branch</th>
                    <th>Date</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rawEntries as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['user_name']) ?></td>
                    <td><?= htmlspecialchars($e['branch_name']) ?></td>
                    <td><?= date('d M Y', strtotime($e['clock_in'])) ?></td>
                    <td><?= date('H:i', strtotime($e['clock_in'])) ?></td>
                    <td><?= $e['clock_out'] ? date('H:i', strtotime($e['clock_out'])) : '—' ?></td>
                    <td><?= $e['total_minutes'] ? formatMinutes((int)$e['total_minutes']) : '<em>Active</em>' ?></td>
                    <td>
                        <?php if (!$e['clock_out']): ?>
                            <span class="status-badge" style="background:#2e7d32;color:#fff;">Active</span>
                        <?php else: ?>
                            <span class="status-badge status-paid">Done</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($e['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
