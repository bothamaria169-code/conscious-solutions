<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payslips.php';
require_once __DIR__ . '/../includes/company.php';

requireLogin();
$user  = currentUser();
$admin = isAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { die('Invalid payslip ID.'); }

$ps = getPayslipById($id);
if (!$ps) { die('Payslip not found.'); }

// Technicians may only view their own payslips (must be linked via employee_id)
if (!$admin && ((int)$ps['employee_id'] !== (int)$user['id'] || !$ps['employee_id'])) {
    die('Access denied.');
}

$cs = getCompanySettings();
// Per-payslip telephone overrides the global setting
$headerTel = !empty($ps['company_tel']) ? $ps['company_tel'] : $cs['phone'];

// Hours totals
$totalNormal = $totalOvertime = $totalLeave = 0;
for ($w = 1; $w <= 5; $w++) {
    $totalNormal   += (float)$ps["w{$w}_normal"];
    $totalOvertime += (float)$ps["w{$w}_overtime"];
    $totalLeave    += (float)$ps["w{$w}_leave"];
}
$totalHours = $totalNormal + $totalOvertime + $totalLeave;

$periodLabel = date('F Y', strtotime($ps['salary_month'] . '-01'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip <?= htmlspecialchars($ps['payslip_number']) ?> — <?= htmlspecialchars($ps['emp_name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #111;
            background: #c8ccd4;
        }

        /* ── Screen action buttons ─────────────────────────────────────────── */
        .page-actions {
            max-width: 794px;
            margin: 24px auto 12px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-action {
            padding: 10px 22px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print { background: #003087; color: white; }
        .btn-back  { background: #555;    color: white; }

        /* ── A4 page ───────────────────────────────────────────────────────── */
        .ps-wrap {
            width: 794px;
            min-height: 1123px;
            margin: 0 auto 40px;
            background: white;
            display: flex;
            flex-direction: column;
            box-shadow: 0 6px 30px rgba(0,0,0,0.3);
        }

        .accent-top    { height: 8px; background: #003087; }
        .accent-bottom { height: 6px; background: linear-gradient(90deg, #003087 60%, #e63946 100%); }

        /* ── Dual header ───────────────────────────────────────────────────── */
        .ps-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-bottom: 2.5px solid #111;
            min-height: 110px;
        }
        .hdr-left {
            padding: 16px 22px;
            border-right: 2.5px solid #111;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .hdr-right {
            padding: 16px 22px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-end;
        }

        /* CS logo */
        .cs-logo-inner { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .slash-grp { display: flex; gap: 4px; }
        .slash    { width: 16px; height: 46px; background: #003087; clip-path: polygon(28% 0%, 100% 0%, 72% 100%, 0% 100%); }
        .slash-sm { width: 10px; height: 46px; background: #003087; clip-path: polygon(28% 0%, 100% 0%, 72% 100%, 0% 100%); }
        .cs-name  { font-size: 18px; font-weight: 900; line-height: 1.15; letter-spacing: 1px; color: #003087; margin: 0 5px; }
        .cs-detail { font-size: 10px; line-height: 1.8; color: #444; }
        .cs-detail strong { color: #111; }

        /* Right: PAYSLIP title */
        .ps-title-block { text-align: right; }
        .ps-label { font-size: 28px; font-weight: 900; color: #003087; letter-spacing: 2px; text-transform: uppercase; }
        .ps-number { font-size: 13px; color: #555; margin-top: 4px; font-weight: 600; }
        .ps-period { font-size: 11px; color: #888; margin-top: 2px; }

        /* ── Employee band ─────────────────────────────────────────────────── */
        .emp-band {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-bottom: 2px solid #003087;
            background: #f0f4ff;
        }
        .emp-col {
            padding: 12px 22px;
        }
        .emp-col:first-child { border-right: 1px solid #ccd; }
        .emp-band-title {
            font-size: 8px; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: #003087;
            margin-bottom: 8px; padding-bottom: 4px;
            border-bottom: 1px solid #ccd;
        }
        .emp-row { display: flex; gap: 6px; margin-bottom: 4px; font-size: 11px; line-height: 1.5; }
        .emp-row .lbl { font-weight: 700; color: #444; min-width: 110px; flex-shrink: 0; }
        .emp-row .val { color: #111; }

        /* ── Section titles ────────────────────────────────────────────────── */
        .section-title {
            background: #003087;
            color: white;
            padding: 6px 22px;
            font-size: 8.5px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* ── Hours table ───────────────────────────────────────────────────── */
        .hours-wrap { padding: 0 22px 12px; }
        table.hours-tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 8px;
        }
        table.hours-tbl th {
            background: #eef1fa;
            color: #003087;
            padding: 6px 10px;
            text-align: center;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid #dde;
        }
        table.hours-tbl th:first-child { text-align: left; }
        table.hours-tbl td {
            padding: 5px 10px;
            text-align: center;
            border: 1px solid #eee;
            color: #222;
        }
        table.hours-tbl td:first-child { text-align: left; font-weight: 600; color: #333; }
        table.hours-tbl tr:nth-child(even) td { background: #fafbff; }
        table.hours-tbl tfoot td {
            background: #eef1fa;
            font-weight: 800;
            border-top: 2px solid #003087;
        }

        /* ── Earnings / Deductions ─────────────────────────────────────────── */
        .ed-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-top: 1px solid #dde;
        }
        .ed-col { padding: 0; }
        .ed-col:first-child { border-right: 1px solid #dde; }
        table.ed-tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }
        table.ed-tbl td {
            padding: 6px 22px;
            border-bottom: 1px solid #f0f0f0;
        }
        table.ed-tbl td:last-child { text-align: right; font-weight: 600; }
        table.ed-tbl tr:last-child td { border-bottom: none; }
        table.ed-tbl tfoot td {
            padding: 8px 22px;
            border-top: 2px solid #003087;
            background: #eef1fa;
            font-weight: 800;
            font-size: 12px;
        }
        table.ed-tbl tfoot td:last-child { text-align: right; }

        /* ── Net pay box ───────────────────────────────────────────────────── */
        .net-box {
            background: #003087;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 22px;
        }
        .net-box .net-lbl { font-size: 12px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; }
        .net-box .net-amt { font-size: 28px; font-weight: 900; }

        /* ── Signatures ────────────────────────────────────────────────────── */
        .sig-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            padding: 20px 22px 14px;
            gap: 40px;
            border-top: 1px solid #dde;
        }
        .sig-block { }
        .sig-line {
            border-top: 1px solid #999;
            margin-top: 32px;
            padding-top: 5px;
            font-size: 10px;
            color: #666;
        }

        /* ── Footer ────────────────────────────────────────────────────────── */
        .ps-footer {
            background: #f0f3fa;
            border-top: 2px solid #003087;
            padding: 8px 22px;
            font-size: 9.5px;
            color: #888;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
        }
        .ps-footer strong { color: #555; }

        /* ── Print ─────────────────────────────────────────────────────────── */
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-actions { display: none !important; }
            .ps-wrap { width: 100%; min-height: 100vh; box-shadow: none; margin: 0; }
        }
    </style>
</head>
<body>

<div class="page-actions">
    <a href="javascript:history.back()" class="btn-action btn-back">&#8592; Back</a>
    <button onclick="window.print()" class="btn-action btn-print">&#128438; Print / Download PDF</button>
</div>

<div class="ps-wrap">

    <div class="accent-top"></div>

    <!-- ══ DUAL HEADER ══════════════════════════════════════════════════════ -->
    <div class="ps-header">

        <!-- LEFT: Conscious Solutions -->
        <div class="hdr-left">
            <div class="cs-logo-inner">
                <div class="slash-grp">
                    <div class="slash"></div>
                    <div class="slash"></div>
                </div>
                <div class="cs-name">CONSCIOUS<br>SOLUTIONS</div>
                <div class="slash-grp">
                    <div class="slash-sm"></div>
                    <div class="slash-sm"></div>
                    <div class="slash-sm"></div>
                </div>
            </div>
            <div class="cs-detail">
                <strong><?= htmlspecialchars($cs['name']) ?></strong><br>
                Reg: <?= htmlspecialchars($cs['reg']) ?><br>
                <?= htmlspecialchars($cs['address']) ?><br>
                &#9993; <?= htmlspecialchars($cs['email']) ?><br>
                &#9990; <?= htmlspecialchars($headerTel) ?>
            </div>
        </div>

        <!-- RIGHT: PAYSLIP -->
        <div class="hdr-right">
            <div class="ps-title-block">
                <div class="ps-label">Payslip</div>
                <div class="ps-number"><?= htmlspecialchars($ps['payslip_number']) ?></div>
                <div class="ps-period"><?= htmlspecialchars($periodLabel) ?></div>
                <?php if ($ps['payment_date']): ?>
                <div class="ps-period">Payment Date: <?= date('d F Y', strtotime($ps['payment_date'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ══ EMPLOYEE DETAILS BAND ══════════════════════════════════════════ -->
    <div class="emp-band">

        <!-- Personal details -->
        <div class="emp-col">
            <div class="emp-band-title">Employee Details</div>
            <div class="emp-row"><span class="lbl">Full Name:</span><span class="val"><?= htmlspecialchars($ps['emp_name']) ?></span></div>
            <?php if ($ps['emp_id_number']): ?>
            <div class="emp-row"><span class="lbl">ID Number:</span><span class="val"><?= htmlspecialchars($ps['emp_id_number']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['emp_job_title']): ?>
            <div class="emp-row"><span class="lbl">Job Title:</span><span class="val"><?= htmlspecialchars($ps['emp_job_title']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['emp_tax_number']): ?>
            <div class="emp-row"><span class="lbl">PAYE Number:</span><span class="val"><?= htmlspecialchars($ps['emp_tax_number']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['emp_uif_number']): ?>
            <div class="emp-row"><span class="lbl">UIF Number:</span><span class="val"><?= htmlspecialchars($ps['emp_uif_number']) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Banking details -->
        <div class="emp-col">
            <div class="emp-band-title">Banking Details</div>
            <?php if ($ps['emp_bank_name']): ?>
            <div class="emp-row"><span class="lbl">Bank:</span><span class="val"><?= htmlspecialchars($ps['emp_bank_name']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['emp_account_no']): ?>
            <div class="emp-row"><span class="lbl">Account Number:</span><span class="val"><?= htmlspecialchars($ps['emp_account_no']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['emp_account_type']): ?>
            <div class="emp-row"><span class="lbl">Account Type:</span><span class="val"><?= htmlspecialchars($ps['emp_account_type']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['emp_branch_code']): ?>
            <div class="emp-row"><span class="lbl">Branch Code:</span><span class="val"><?= htmlspecialchars($ps['emp_branch_code']) ?></span></div>
            <?php endif; ?>
            <?php if ($ps['branch_name']): ?>
            <div class="emp-row"><span class="lbl">Branch:</span><span class="val"><?= htmlspecialchars($ps['branch_name']) ?></span></div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ══ HOURS WORKED ══════════════════════════════════════════════════ -->
    <?php
    // Only show hours section if any hours were recorded
    if ($totalHours > 0):
    ?>
    <div class="section-title">Hours Worked</div>
    <div class="hours-wrap">
        <table class="hours-tbl">
            <thead>
                <tr>
                    <th>Week</th>
                    <th>Normal Hours</th>
                    <th>Overtime Hours</th>
                    <th>Leave Hours</th>
                    <th>Week Total</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($w = 1; $w <= 5; $w++):
                    $wn = (float)$ps["w{$w}_normal"];
                    $wo = (float)$ps["w{$w}_overtime"];
                    $wl = (float)$ps["w{$w}_leave"];
                    if ($wn + $wo + $wl == 0) continue;
                ?>
                <tr>
                    <td>Week <?= $w ?></td>
                    <td><?= number_format($wn, 1) ?></td>
                    <td><?= number_format($wo, 1) ?></td>
                    <td><?= number_format($wl, 1) ?></td>
                    <td><?= number_format($wn + $wo + $wl, 1) ?></td>
                </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL</td>
                    <td><?= number_format($totalNormal, 1) ?></td>
                    <td><?= number_format($totalOvertime, 1) ?></td>
                    <td><?= number_format($totalLeave, 1) ?></td>
                    <td><?= number_format($totalHours, 1) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══ EARNINGS & DEDUCTIONS ══════════════════════════════════════════ -->
    <div class="section-title">Earnings &amp; Deductions</div>

    <div class="ed-section">

        <!-- Earnings -->
        <div class="ed-col">
            <table class="ed-tbl">
                <thead>
                    <tr>
                        <td colspan="2" style="padding:8px 22px 6px; font-weight:800; font-size:9px; letter-spacing:1.5px; text-transform:uppercase; color:#003087; background:#f6f8ff; border-bottom:1px solid #dde;">Earnings</td>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Basic Salary</td>       <td>R<?= number_format((float)$ps['basic_salary'],     2) ?></td></tr>
                    <?php if ((float)$ps['overtime_pay'] > 0): ?>
                    <tr><td>Overtime Pay</td>        <td>R<?= number_format((float)$ps['overtime_pay'],    2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['bonus'] > 0): ?>
                    <tr><td>Bonus</td>               <td>R<?= number_format((float)$ps['bonus'],           2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['travel_allowance'] > 0): ?>
                    <tr><td>Travel Allowance</td>    <td>R<?= number_format((float)$ps['travel_allowance'],2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['other_allowances'] > 0): ?>
                    <tr><td>Other Allowances</td>    <td>R<?= number_format((float)$ps['other_allowances'],2) ?></td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr><td>Gross Earnings</td><td>R<?= number_format((float)$ps['gross_earnings'], 2) ?></td></tr>
                </tfoot>
            </table>
        </div>

        <!-- Deductions -->
        <div class="ed-col">
            <table class="ed-tbl">
                <thead>
                    <tr>
                        <td colspan="2" style="padding:8px 22px 6px; font-weight:800; font-size:9px; letter-spacing:1.5px; text-transform:uppercase; color:#842029; background:#fff8f8; border-bottom:1px solid #dde;">Deductions</td>
                    </tr>
                </thead>
                <tbody>
                    <?php if ((float)$ps['paye'] > 0): ?>
                    <tr><td>PAYE (Income Tax)</td>  <td>R<?= number_format((float)$ps['paye'],           2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['uif_employee'] > 0): ?>
                    <tr><td>UIF (Employee)</td>      <td>R<?= number_format((float)$ps['uif_employee'],   2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['pension_fund'] > 0): ?>
                    <tr><td>Pension Fund</td>        <td>R<?= number_format((float)$ps['pension_fund'],   2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['loan_deduction'] > 0): ?>
                    <tr><td>Loan Deduction</td>      <td>R<?= number_format((float)$ps['loan_deduction'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['other_deductions'] > 0): ?>
                    <tr><td>Other Deductions</td>    <td>R<?= number_format((float)$ps['other_deductions'],2)?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$ps['total_deductions'] == 0): ?>
                    <tr><td colspan="2" style="color:#888; font-style:italic;">No deductions</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr><td>Total Deductions</td><td>R<?= number_format((float)$ps['total_deductions'], 2) ?></td></tr>
                </tfoot>
            </table>
        </div>

    </div>

    <!-- ══ NET PAY ════════════════════════════════════════════════════════ -->
    <div class="net-box">
        <div>
            <div class="net-lbl">Net Pay — <?= htmlspecialchars($periodLabel) ?></div>
            <?php if ($ps['payment_date']): ?>
            <div style="font-size:10px; opacity:0.75; margin-top:3px;">Payment Date: <?= date('d F Y', strtotime($ps['payment_date'])) ?></div>
            <?php endif; ?>
        </div>
        <span class="net-amt">R<?= number_format((float)$ps['net_pay'], 2) ?></span>
    </div>

    <!-- ══ SIGNATURES ════════════════════════════════════════════════════ -->
    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-line">Employee Signature &nbsp;&nbsp;&nbsp; Date: _______________</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Authorised Signatory &nbsp;&nbsp;&nbsp; Date: _______________</div>
        </div>
    </div>

    <!-- ══ FOOTER ════════════════════════════════════════════════════════ -->
    <div class="ps-footer">
        <span>
            <strong><?= htmlspecialchars($cs['name']) ?></strong>
            &bull; Reg: <?= htmlspecialchars($cs['reg']) ?>
            &bull; <?= htmlspecialchars($cs['email']) ?>
        </span>
        <span>Generated <?= date('d M Y H:i') ?></span>
    </div>

    <div class="accent-bottom"></div>

</div>
</body>
</html>
