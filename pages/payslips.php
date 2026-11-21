<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payslips.php';
require_once __DIR__ . '/../includes/users.php';

requireLogin();
$user   = currentUser();
$admin  = isAdmin();
$action = $_GET['action'] ?? 'list';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = ($action === 'edit');
$msg    = '';
$error  = '';

// $formData drives all form field values (payslip data or POST repopulation)
$formData    = [];
$editPayslip = null;

// ── Load payslip for editing (GET) ────────────────────────────────────────────
if ($isEdit && !$_POST) {
    requireAdmin();
    $editPayslip = getPayslipById($editId);
    if (!$editPayslip) {
        header('Location: ' . BASE_PATH . '/pages/payslips.php');
        exit;
    }
    $formData = $editPayslip;
}

// ── POST: create ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    requireAdmin();
    $d = $_POST;
    $d['created_by'] = (int)$user['id'];
    foreach ([
        'w1_normal','w1_overtime','w1_leave','w2_normal','w2_overtime','w2_leave',
        'w3_normal','w3_overtime','w3_leave','w4_normal','w4_overtime','w4_leave',
        'w5_normal','w5_overtime','w5_leave',
        'basic_salary','overtime_pay','bonus','travel_allowance','other_allowances',
        'paye','uif_employee','pension_fund','loan_deduction','other_deductions',
    ] as $f) { $d[$f] = (float)($d[$f] ?? 0); }
    $result = createPayslip($d);
    if ($result['success']) {
        header('Location: ' . BASE_PATH . '/pages/payslips.php?msg=created&ps=' . urlencode($result['payslip_number']));
        exit;
    }
    $error    = 'Could not save payslip. Please try again.';
    $formData = $_POST;
}

// ── POST: update ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit' && $editId) {
    requireAdmin();
    $d = $_POST;
    foreach ([
        'w1_normal','w1_overtime','w1_leave','w2_normal','w2_overtime','w2_leave',
        'w3_normal','w3_overtime','w3_leave','w4_normal','w4_overtime','w4_leave',
        'w5_normal','w5_overtime','w5_leave',
        'basic_salary','overtime_pay','bonus','travel_allowance','other_allowances',
        'paye','uif_employee','pension_fund','loan_deduction','other_deductions',
    ] as $f) { $d[$f] = (float)($d[$f] ?? 0); }
    $result = updatePayslip($editId, $d);
    if ($result['success']) {
        header('Location: ' . BASE_PATH . '/pages/payslips.php?msg=updated');
        exit;
    }
    $error       = 'Could not update payslip. Please try again.';
    $formData    = $_POST;
    $editPayslip = getPayslipById($editId);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $admin && $editId) {
    deletePayslip($editId);
    header('Location: ' . BASE_PATH . '/pages/payslips.php?msg=deleted');
    exit;
}

// ── Flash messages ────────────────────────────────────────────────────────────
switch ($_GET['msg'] ?? '') {
    case 'created': $msg = 'Payslip ' . htmlspecialchars($_GET['ps'] ?? '') . ' created successfully.'; break;
    case 'updated': $msg = 'Payslip updated successfully.'; break;
    case 'deleted': $msg = 'Payslip deleted.'; break;
}

// ── Data ──────────────────────────────────────────────────────────────────────
$payslips  = getPayslips($admin ? null : (int)$user['id']);
$employees = [];
if ($admin) {
    foreach (getAllUsers() as $u) {
        if ($u['role'] === ROLE_MECHANIC) $employees[] = $u;
    }
}

// Form helpers — read from $formData (which is either DB row, POST, or empty)
function psOld(string $key, string $default = ''): string {
    global $formData;
    return htmlspecialchars($formData[$key] ?? $default);
}
function psOldN(string $key, string $default = '0'): string {
    global $formData;
    $v = $formData[$key] ?? $default;
    return htmlspecialchars($v !== '' ? $v : $default);
}

$pageTitle = 'Payslips — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>
<style>
.ps-section { padding: 20px 24px; border-bottom: 1px solid var(--border); }
.ps-section-title {
    font-size: 10.5px; font-weight: 700; color: var(--primary);
    text-transform: uppercase; letter-spacing: 1.2px;
    margin-bottom: 16px; padding-bottom: 7px; border-bottom: 2px solid var(--border);
}
.ps-sub-title { font-size: 10px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px; }
.ps-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.ps-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.ps-grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; }
.ed-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; padding: 20px 24px 0; }
.r-pfx { display: flex; align-items: center; gap: 6px; }
.r-pfx > span { font-weight: 700; color: var(--text-muted); font-size: 14px; flex-shrink: 0; }
.r-pfx .form-control { flex: 1; min-width: 0; }
.calc-val { font-size: 18px; font-weight: 800; color: var(--primary); padding: 5px 0; }
.calc-val.red { color: #842029; }
.net-banner {
    margin: 14px 24px 20px; background: var(--primary); color: white;
    padding: 14px 22px; border-radius: 8px;
    display: flex; justify-content: space-between; align-items: center;
    font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
}
.net-banner .net-amt { font-size: 26px; font-weight: 900; }
.hours-table input.form-control { width: 82px; text-align: center; padding: 5px 6px; font-size: 13px; }
.calc-hint { font-size: 10px; color: var(--text-muted); font-style: italic; font-weight: 400; margin-left: 5px; }
.ps-form-actions {
    padding: 16px 24px; display: flex; justify-content: flex-end; gap: 10px;
    background: #f8fafc; border-top: 1px solid var(--border);
    border-radius: 0 0 var(--radius) var(--radius);
}
.link-acct-note { font-size: 11px; color: var(--text-muted); margin-top: 5px; }
.ps-number-badge {
    display: inline-block; background: #eef1fa; color: var(--primary);
    font-weight: 700; padding: 4px 12px; border-radius: 20px; font-size: 13px;
}
.ps-empty { text-align: center; padding: 48px 24px; color: var(--text-muted); }
</style>

<div class="page-header">
    <div>
        <h2 class="page-title">Payslips</h2>
        <p class="page-subtitle"><?= $admin ? 'All employee payslips' : 'Your payslips' ?></p>
    </div>
    <?php if ($admin && $action === 'list'): ?>
    <a href="?action=create" class="btn btn-primary">+ New Payslip</a>
    <?php endif; ?>
</div>

<?php if ($msg): ?>
<div class="alert alert-success"><?= $msg ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create','edit']) && $admin): ?>

<!-- ══ PAYSLIP FORM (create + edit) ══════════════════════════════════════════ -->
<div class="card" style="padding:0; overflow:hidden;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:12px;">
            <h3 class="card-title" style="margin:0;"><?= $isEdit ? 'Edit Payslip' : 'New Payslip' ?></h3>
            <?php if ($isEdit && $editPayslip): ?>
            <span class="ps-number-badge"><?= htmlspecialchars($editPayslip['payslip_number']) ?></span>
            <?php endif; ?>
        </div>
        <a href="<?= BASE_PATH ?>/pages/payslips.php" class="btn btn-sm btn-outline">&#8592; Back to List</a>
    </div>

    <form method="POST" action="?action=<?= $isEdit ? "edit&id={$editId}" : 'create' ?>">

        <!-- ── EMPLOYEE DETAILS ───────────────────────────────────────────── -->
        <div class="ps-section">
            <div class="ps-section-title">Employee Details</div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Full Name (as it appears on payslip) <span style="color:#c00">*</span></label>
                <input type="text" name="emp_name" class="form-control" required
                       value="<?= psOld('emp_name') ?>" placeholder="Employee full name">
            </div>

            <div class="ps-grid-3" style="margin-bottom:16px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">SA ID Number</label>
                    <input type="text" name="emp_id_number" class="form-control"
                           value="<?= psOld('emp_id_number') ?>" placeholder="13-digit ID">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Job Title</label>
                    <input type="text" name="emp_job_title" class="form-control"
                           value="<?= psOld('emp_job_title') ?>" placeholder="e.g. Automotive Technician">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">PAYE / Tax Number</label>
                    <input type="text" name="emp_tax_number" class="form-control"
                           value="<?= psOld('emp_tax_number') ?>" placeholder="SARS reference">
                </div>
            </div>

            <div class="ps-grid-4" style="margin-bottom:20px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">UIF Number</label>
                    <input type="text" name="emp_uif_number" class="form-control"
                           value="<?= psOld('emp_uif_number') ?>" placeholder="UIF reference">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="emp_bank_name" class="form-control"
                           value="<?= psOld('emp_bank_name') ?>" placeholder="e.g. FNB, Capitec">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="emp_account_no" class="form-control"
                           value="<?= psOld('emp_account_no') ?>" placeholder="Account number">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Account Type / Branch Code</label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" name="emp_account_type" class="form-control"
                               value="<?= psOld('emp_account_type') ?>" placeholder="Cheque / Savings">
                        <input type="text" name="emp_branch_code" class="form-control"
                               value="<?= psOld('emp_branch_code') ?>" placeholder="Branch" style="max-width:105px;">
                    </div>
                </div>
            </div>

            <div class="ps-grid-2">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Company Telephone <span class="calc-hint">shown on payslip header</span></label>
                    <input type="text" name="company_tel" class="form-control"
                           value="<?= psOld('company_tel') ?>" placeholder="e.g. 011 000 0000">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Link to Employee Account <span class="calc-hint">optional</span></label>
                    <select name="employee_id" class="form-control">
                        <option value="">— Not linked —</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>" <?= psOld('employee_id') == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="link-acct-note">Lets the employee view &amp; download this payslip from their account.</div>
                </div>
            </div>
        </div>

        <!-- ── PAY PERIOD ─────────────────────────────────────────────────── -->
        <div class="ps-section">
            <div class="ps-section-title">Pay Period</div>
            <div class="ps-grid-2">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Salary Month <span style="color:#c00">*</span></label>
                    <input type="month" name="salary_month" class="form-control" required
                           value="<?= psOld('salary_month', date('Y-m')) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= psOld('payment_date', date('Y-m-d')) ?>">
                </div>
            </div>
        </div>

        <!-- ── HOURS WORKED ───────────────────────────────────────────────── -->
        <div class="ps-section">
            <div class="ps-section-title">Hours Worked</div>
            <div class="table-responsive" style="max-width:520px;">
                <table class="data-table hours-table">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Normal (hrs)</th>
                            <th>Overtime (hrs)</th>
                            <th>Leave (hrs)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($w = 1; $w <= 5; $w++): ?>
                        <tr>
                            <td><strong>Week <?= $w ?></strong></td>
                            <td><input type="number" name="w<?= $w ?>_normal"   class="form-control" min="0" max="80" step="0.5" value="<?= psOldN("w{$w}_normal",   $isEdit ? '0' : '48') ?>"></td>
                            <td><input type="number" name="w<?= $w ?>_overtime" class="form-control" min="0" max="40" step="0.5" value="<?= psOldN("w{$w}_overtime", '0') ?>"></td>
                            <td><input type="number" name="w<?= $w ?>_leave"    class="form-control" min="0" max="40" step="0.5" value="<?= psOldN("w{$w}_leave",    '0') ?>"></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── EARNINGS & DEDUCTIONS ──────────────────────────────────────── -->
        <div class="ps-section" style="padding-bottom:0; border-bottom:none;">
            <div class="ps-section-title">Earnings &amp; Deductions</div>
        </div>
        <div class="ed-grid">
            <div>
                <div class="ps-sub-title">Earnings</div>
                <div class="form-group">
                    <label class="form-label">Basic Salary</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="basic_salary" id="basicSalary" class="form-control" min="0" step="0.01" value="<?= psOldN('basic_salary') ?>" oninput="autoCalc()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Overtime Pay</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="overtime_pay" id="overtimePay" class="form-control" min="0" step="0.01" value="<?= psOldN('overtime_pay') ?>" oninput="autoCalc()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Bonus</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="bonus" id="bonusAmt" class="form-control" min="0" step="0.01" value="<?= psOldN('bonus') ?>" oninput="autoCalc()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Travel Allowance</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="travel_allowance" id="travelAllow" class="form-control" min="0" step="0.01" value="<?= psOldN('travel_allowance') ?>" oninput="autoCalc()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Other Allowances</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="other_allowances" id="otherAllow" class="form-control" min="0" step="0.01" value="<?= psOldN('other_allowances') ?>" oninput="autoCalc()">
                    </div>
                </div>
                <div class="form-group" style="border-top:2px solid var(--border); padding-top:10px; margin-top:6px;">
                    <label class="form-label" style="color:var(--primary); font-weight:700;">Gross Earnings</label>
                    <div class="calc-val" id="grossDisplay">R 0.00</div>
                </div>
            </div>
            <div>
                <div class="ps-sub-title">Deductions</div>
                <div class="form-group">
                    <label class="form-label">PAYE<span class="calc-hint">auto-calculated · override if needed</span></label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="paye" id="payeAmt" class="form-control" min="0" step="0.01" value="<?= psOldN('paye') ?>" oninput="recalcTotals()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">UIF<span class="calc-hint">1% of gross, max R177.12</span></label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="uif_employee" id="uifAmt" class="form-control" min="0" step="0.01" value="<?= psOldN('uif_employee') ?>" oninput="recalcTotals()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Pension Fund</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="pension_fund" id="pensionAmt" class="form-control" min="0" step="0.01" value="<?= psOldN('pension_fund') ?>" oninput="recalcTotals()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Loan Deduction</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="loan_deduction" id="loanAmt" class="form-control" min="0" step="0.01" value="<?= psOldN('loan_deduction') ?>" oninput="recalcTotals()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Other Deductions</label>
                    <div class="r-pfx"><span>R</span>
                        <input type="number" name="other_deductions" id="otherDed" class="form-control" min="0" step="0.01" value="<?= psOldN('other_deductions') ?>" oninput="recalcTotals()">
                    </div>
                </div>
                <div class="form-group" style="border-top:2px solid var(--border); padding-top:10px; margin-top:6px;">
                    <label class="form-label" style="color:#842029; font-weight:700;">Total Deductions</label>
                    <div class="calc-val red" id="deducDisplay">R 0.00</div>
                </div>
            </div>
        </div>

        <div class="net-banner">
            <span>Net Pay</span>
            <span class="net-amt" id="netDisplay">R 0.00</span>
        </div>

        <div class="ps-form-actions">
            <a href="<?= BASE_PATH ?>/pages/payslips.php" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $isEdit ? '&#10003; Update Payslip' : '&#10003; Generate &amp; Save Payslip' ?>
            </button>
        </div>
    </form>
</div>

<script>
function calcPaye(g) {
    const a = g * 12;
    if (a <= 95750) return 0;
    let t;
    if      (a <= 237100)  t = a * 0.18;
    else if (a <= 370500)  t = 42678  + (a - 237100)  * 0.26;
    else if (a <= 512800)  t = 77362  + (a - 370500)  * 0.31;
    else if (a <= 673000)  t = 121475 + (a - 512800)  * 0.36;
    else if (a <= 857900)  t = 179147 + (a - 673000)  * 0.39;
    else if (a <= 1817000) t = 251258 + (a - 857900)  * 0.41;
    else                   t = 644489 + (a - 1817000) * 0.45;
    t -= 17235;
    return Math.max(0, Math.round(t / 12 * 100) / 100);
}
function calcUif(g) { return Math.round(Math.min(g * 0.01, 177.12) * 100) / 100; }
function fmt(n) {
    const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (n < 0 ? '-' : '') + 'R ' + s;
}
function getGross() {
    return (parseFloat(document.getElementById('basicSalary').value) || 0)
         + (parseFloat(document.getElementById('overtimePay').value) || 0)
         + (parseFloat(document.getElementById('bonusAmt').value)    || 0)
         + (parseFloat(document.getElementById('travelAllow').value) || 0)
         + (parseFloat(document.getElementById('otherAllow').value)  || 0);
}
function recalcTotals() {
    const g    = getGross();
    const deds = (parseFloat(document.getElementById('payeAmt').value)    || 0)
               + (parseFloat(document.getElementById('uifAmt').value)     || 0)
               + (parseFloat(document.getElementById('pensionAmt').value) || 0)
               + (parseFloat(document.getElementById('loanAmt').value)    || 0)
               + (parseFloat(document.getElementById('otherDed').value)   || 0);
    document.getElementById('grossDisplay').textContent = fmt(g);
    document.getElementById('deducDisplay').textContent = fmt(deds);
    document.getElementById('netDisplay').textContent   = fmt(g - deds);
}
function autoCalc() {
    const g = getGross();
    document.getElementById('payeAmt').value = calcPaye(g).toFixed(2);
    document.getElementById('uifAmt').value  = calcUif(g).toFixed(2);
    recalcTotals();
}
// On edit, don't auto-overwrite PAYE/UIF — just show running totals
<?php if ($isEdit): ?>
recalcTotals();
<?php else: ?>
autoCalc();
<?php endif; ?>
</script>

<?php else: ?>

<!-- ══ LIST VIEW ═══════════════════════════════════════════════════════════ -->
<div class="card" style="padding:0; overflow:hidden;">
    <?php if (empty($payslips)): ?>
    <div class="ps-empty">
        <?php if ($admin): ?>
            <p style="margin-bottom:12px;">No payslips yet.</p>
            <a href="?action=create" class="btn btn-primary">+ Create First Payslip</a>
        <?php else: ?>
            <p>You have no payslips on record yet. Please contact your administrator.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Payslip #</th>
                    <?php if ($admin): ?><th>Employee</th><?php endif; ?>
                    <th>Month</th>
                    <th>Gross</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Payment Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payslips as $ps): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ps['payslip_number']) ?></strong></td>
                    <?php if ($admin): ?>
                    <td><?= htmlspecialchars($ps['emp_name']) ?></td>
                    <?php endif; ?>
                    <td><?= date('F Y', strtotime($ps['salary_month'] . '-01')) ?></td>
                    <td>R<?= number_format((float)$ps['gross_earnings'],   2) ?></td>
                    <td>R<?= number_format((float)$ps['total_deductions'], 2) ?></td>
                    <td><strong>R<?= number_format((float)$ps['net_pay'], 2) ?></strong></td>
                    <td><?= $ps['payment_date'] ? date('d M Y', strtotime($ps['payment_date'])) : '—' ?></td>
                    <td style="white-space:nowrap; text-align:right;">
                        <a href="<?= BASE_PATH ?>/pages/print_payslip.php?id=<?= (int)$ps['id'] ?>"
                           class="btn btn-sm btn-primary" target="_blank">&#128438; View / Print</a>
                        <?php if ($admin): ?>
                        <a href="?action=edit&id=<?= (int)$ps['id'] ?>" class="btn btn-sm btn-outline">&#9998; Edit</a>
                        <a href="?action=delete&id=<?= (int)$ps['id'] ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Delete payslip <?= htmlspecialchars(addslashes($ps['payslip_number'])) ?>? This cannot be undone.')">
                            Delete
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
