<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/timeclock.php';
require_once __DIR__ . '/../includes/users.php';

requireLogin();
$user    = currentUser();
$message = '';
$error   = '';

// ─── CLOCK IN ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clock_in') {
    $branchId = (int)($user['branch_id'] ?? $_POST['branch_id'] ?? 0);
    if (!$branchId) {
        $error = 'No branch assigned. Contact your admin.';
    } else {
        $result = clockIn((int)$user['id'], $branchId, trim($_POST['notes'] ?? ''));
        if ($result['success']) {
            $message = '✅ Clocked in successfully at ' . date('H:i') . '.';
        } else {
            $error = $result['error'];
        }
    }
}

// ─── CLOCK OUT ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clock_out') {
    $result = clockOut((int)$user['id']);
    if ($result['success']) {
        $message = '✅ Clocked out at ' . date('H:i') . '. Time worked: ' . formatMinutes($result['total_minutes']) . '.';
    } else {
        $error = $result['error'];
    }
}

// Active session
$active = getActiveEntry((int)$user['id']);

// This week (Monday → today)
$dow       = (int)date('N'); // 1=Mon, 7=Sun
$weekStart = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
$weekEnd   = date('Y-m-d');

// This month
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-d');

$weekDailySummary  = getUserDailySummary((int)$user['id'], $weekStart, $weekEnd);
$monthDailySummary = getUserDailySummary((int)$user['id'], $monthStart, $monthEnd);

$weekMinutes  = array_sum(array_column($weekDailySummary,  'total_minutes'));
$weekDays     = count($weekDailySummary);
$monthMinutes = array_sum(array_column($monthDailySummary, 'total_minutes'));
$monthDays    = count($monthDailySummary);

// Raw log for last 14 days
$recentEntries = getUserTimeEntries((int)$user['id'], date('Y-m-d', strtotime('-14 days')), date('Y-m-d'));

$branches  = getAllBranches();
$pageTitle = 'Time Clock — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">⏱ Time Clock</h2>
    <p class="page-subtitle">Track your working hours</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ═══ CLOCK WIDGET ═══════════════════════════════════════════════════════════ -->
<div class="card card-narrow" style="text-align:center; padding:32px 24px;">

    <div id="liveClock" style="font-size:3rem; font-weight:700; color:#003087; letter-spacing:2px; margin-bottom:4px;">--:--:--</div>
    <div style="color:#888; font-size:14px; margin-bottom:24px;" id="liveDate"></div>

    <?php if ($active): ?>
        <!-- CLOCKED IN STATE -->
        <div style="background:#e8f5e9; border:2px solid #2e7d32; border-radius:8px; padding:16px 24px; margin-bottom:24px; display:inline-block;">
            <div style="color:#2e7d32; font-weight:700; font-size:18px;">🟢 Currently Clocked In</div>
            <div style="color:#555; margin-top:4px;">
                Since <strong><?= date('H:i', strtotime($active['clock_in'])) ?></strong>
                on <?= date('d M Y', strtotime($active['clock_in'])) ?>
            </div>
            <div style="color:#888; font-size:13px; margin-top:4px;" id="elapsed">
                <!-- filled by JS -->
            </div>
        </div>

        <form method="POST" style="margin-top:8px;">
            <input type="hidden" name="action" value="clock_out">
            <button type="submit" class="btn btn-danger btn-lg" style="font-size:18px; padding:14px 40px;"
                    onclick="return confirm('Clock out now?')">
                ⏹ Clock Out
            </button>
        </form>

        <script>
        // Show elapsed time since clock-in
        const clockInTime = new Date('<?= str_replace(' ','T',$active['clock_in']) ?>');
        function updateElapsed() {
            const diff = Math.floor((Date.now() - clockInTime) / 1000);
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            document.getElementById('elapsed').textContent =
                'Elapsed: ' + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }
        setInterval(updateElapsed, 1000);
        updateElapsed();
        </script>

    <?php else: ?>
        <!-- NOT CLOCKED IN STATE -->
        <div style="background:#fff3e0; border:2px solid #e65100; border-radius:8px; padding:16px 24px; margin-bottom:24px; display:inline-block;">
            <div style="color:#e65100; font-weight:700; font-size:18px;">🔴 Not Clocked In</div>
            <div style="color:#777; margin-top:4px; font-size:13px;">Standard hours: <?= STANDARD_START ?> – <?= STANDARD_END ?></div>
        </div>

        <form method="POST" style="margin-top:8px;">
            <input type="hidden" name="action" value="clock_in">
            <?php if (isAdmin() && !$user['branch_id']): ?>
            <div class="form-group" style="text-align:left; max-width:300px; margin:0 auto 16px;">
                <label>Branch</label>
                <select name="branch_id" class="form-control" required>
                    <option value="">Select branch…</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group" style="text-align:left; max-width:300px; margin:0 auto 16px;">
                <label>Notes (optional)</label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Late start, half day…">
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="font-size:18px; padding:14px 40px;">
                ▶ Clock In
            </button>
        </form>
    <?php endif; ?>
</div>

<?php
// Helper: colour class based on whether target was met
function hoursColour(int $worked, int $target): string {
    return $worked >= $target ? '#2e7d32' : '#c62828';
}
function diffStr(int $worked, int $target): string {
    $diff = $worked - $target;
    return ($diff >= 0 ? '+' : '−') . formatMinutes(abs($diff));
}
?>

<!-- ═══ STATS ════════════════════════════════════════════════════════════════ -->
<div class="stats-grid stats-grid-4" style="margin-top:24px;">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📅</div>
        <div class="stat-value"><?= $weekDays ?></div>
        <div class="stat-label">Days This Week</div>
    </div>
    <div class="stat-card <?= $weekMinutes >= $weekDays * STANDARD_DAY_MINUTES ? 'stat-green' : 'stat-orange' ?>">
        <div class="stat-icon">⏱</div>
        <div class="stat-value"><?= formatMinutes($weekMinutes) ?></div>
        <div class="stat-label">Hours This Week</div>
        <div style="font-size:11px;color:#888;margin-top:4px;">
            Target: <?= formatMinutes($weekDays * STANDARD_DAY_MINUTES) ?>
        </div>
    </div>
    <div class="stat-card stat-blue">
        <div class="stat-icon">🗓</div>
        <div class="stat-value"><?= $monthDays ?></div>
        <div class="stat-label">Days This Month</div>
    </div>
    <div class="stat-card <?= $monthMinutes >= $monthDays * STANDARD_DAY_MINUTES ? 'stat-green' : 'stat-orange' ?>">
        <div class="stat-icon">📊</div>
        <div class="stat-value"><?= formatMinutes($monthMinutes) ?></div>
        <div class="stat-label">Hours This Month</div>
        <div style="font-size:11px;color:#888;margin-top:4px;">
            Target: <?= formatMinutes($monthDays * STANDARD_DAY_MINUTES) ?>
        </div>
    </div>
</div>

<!-- ═══ THIS WEEK BREAKDOWN ══════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
    <h3 class="card-title">
        This Week
        <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px;">
            <?= date('d M', strtotime($weekStart)) ?> – <?= date('d M Y', strtotime($weekEnd)) ?>
        </span>
    </h3>

    <?php if (empty($weekDailySummary)): ?>
        <div class="empty-state">No completed clock-ins this week.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Hours</th>
                    <th>vs 8h Target</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weekDailySummary as $row): ?>
                <?php
                    $diff  = (int)$row['total_minutes'] - STANDARD_DAY_MINUTES;
                    $dStr  = ($diff >= 0 ? '+' : '−') . formatMinutes(abs($diff));
                    $dCol  = $diff >= 0 ? '#2e7d32' : '#c62828';
                ?>
                <tr>
                    <td><?= date('D, d M', strtotime($row['work_date'])) ?></td>
                    <td><?= date('H:i', strtotime($row['first_in'])) ?></td>
                    <td><?= $row['last_out'] ? date('H:i', strtotime($row['last_out'])) : '—' ?></td>
                    <td><strong><?= formatMinutes((int)$row['total_minutes']) ?></strong></td>
                    <td style="color:<?= $dCol ?>;font-weight:600;"><?= $dStr ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:#f0f4ff;">
                    <td colspan="3"><strong>Week Total</strong></td>
                    <td><?= formatMinutes($weekMinutes) ?></td>
                    <td style="color:<?= hoursColour($weekMinutes, $weekDays * STANDARD_DAY_MINUTES) ?>;font-weight:600;">
                        <?= diffStr($weekMinutes, $weekDays * STANDARD_DAY_MINUTES) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ THIS MONTH BREAKDOWN ═════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
    <h3 class="card-title">
        This Month — <?= date('F Y') ?>
    </h3>

    <?php if (empty($monthDailySummary)): ?>
        <div class="empty-state">No completed clock-ins this month.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Hours</th>
                    <th>vs 8h Target</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthDailySummary as $row): ?>
                <?php
                    $diff = (int)$row['total_minutes'] - STANDARD_DAY_MINUTES;
                    $dStr = ($diff >= 0 ? '+' : '−') . formatMinutes(abs($diff));
                    $dCol = $diff >= 0 ? '#2e7d32' : '#c62828';
                ?>
                <tr>
                    <td><?= date('D, d M', strtotime($row['work_date'])) ?></td>
                    <td><?= date('H:i', strtotime($row['first_in'])) ?></td>
                    <td><?= $row['last_out'] ? date('H:i', strtotime($row['last_out'])) : '—' ?></td>
                    <td><strong><?= formatMinutes((int)$row['total_minutes']) ?></strong></td>
                    <td style="color:<?= $dCol ?>;font-weight:600;"><?= $dStr ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:#f0f4ff;">
                    <td colspan="3"><strong>Month Total (<?= $monthDays ?> days)</strong></td>
                    <td><?= formatMinutes($monthMinutes) ?></td>
                    <td style="color:<?= hoursColour($monthMinutes, $monthDays * STANDARD_DAY_MINUTES) ?>;font-weight:600;">
                        <?= diffStr($monthMinutes, $monthDays * STANDARD_DAY_MINUTES) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ RECENT CLOCK LOG ══════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
    <h3 class="card-title">Recent Clock Log (Last 14 Days)</h3>

    <?php if (empty($recentEntries)): ?>
        <div class="empty-state">No time entries found.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentEntries as $e): ?>
                <?php $isActive = $e['clock_out'] === null; ?>
                <tr>
                    <td><?= date('d M Y', strtotime($e['clock_in'])) ?></td>
                    <td><?= date('H:i', strtotime($e['clock_in'])) ?></td>
                    <td><?= $e['clock_out'] ? date('H:i', strtotime($e['clock_out'])) : '—' ?></td>
                    <td>
                        <?php if ($isActive): ?>
                            <em style="color:#2e7d32;">In progress…</em>
                        <?php else: ?>
                            <?= formatMinutes((int)$e['total_minutes']) ?>
                            <?php if ((int)$e['total_minutes'] < STANDARD_DAY_MINUTES): ?>
                                <span style="color:#e65100;font-size:11px;" title="Below 8h target">▾</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isActive): ?>
                            <span class="status-badge" style="background:#2e7d32;color:#fff;">Active</span>
                        <?php else: ?>
                            <span class="status-badge status-paid">Complete</span>
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

<script>
// Live clock
function tick() {
    const now = new Date();
    document.getElementById('liveClock').textContent =
        String(now.getHours()).padStart(2,'0') + ':' +
        String(now.getMinutes()).padStart(2,'0') + ':' +
        String(now.getSeconds()).padStart(2,'0');
    document.getElementById('liveDate').textContent =
        now.toLocaleDateString('en-ZA', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
}
setInterval(tick, 1000);
tick();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
