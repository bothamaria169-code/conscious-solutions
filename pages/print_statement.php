<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/company.php';

requireAdmin();
$user = currentUser();
$cs   = getCompanySettings();

// ── Parameters ───────────────────────────────────────────────────────────────
$branchId = (int)($_GET['branch_id'] ?? 0);
$month    = $_GET['month']     ?? date('Y-m');   // e.g. "2026-03"
$dateFrom = $_GET['date_from'] ?? null;
$dateTo   = $_GET['date_to']   ?? null;

// Bail early if no branch was resolved
if ($branchId <= 0) {
    die('No branch specified.');
}

// ── Load branch ───────────────────────────────────────────────────────────────
// FIX: cast $b['id'] to int — PDO returns strings via FETCH_ASSOC,
//      so strict === comparison against an int always failed on the live server.
$branches = getAllBranches();
$branch   = null;
foreach ($branches as $b) {
    if ((int)$b['id'] === $branchId) {
        $branch = $b;
        break;
    }
}

if (!$branch) {
    die('Branch not found. (branch_id=' . $branchId . ')');
}

// ── Build filters & period label ─────────────────────────────────────────────
$filters = ['branch_id' => $branchId];

if ($dateFrom && $dateTo) {
    $filters['date_from'] = $dateFrom;
    $filters['date_to']   = $dateTo;
    $periodLabel = date('d M Y', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo));
} else {
    $filters['month'] = $month;
    $periodLabel = date('F Y', strtotime($month . '-01'));
}

$invoices = getInvoices($filters);

// ── Totals ────────────────────────────────────────────────────────────────────
$grandTotal = array_sum(array_column($invoices, 'amount'));
$totalPaid  = array_sum(array_map(fn($i) => $i['status'] === 'paid'        ? (float)$i['amount'] : 0, $invoices));
$totalOutst = array_sum(array_map(fn($i) => $i['status'] === 'outstanding' ? (float)$i['amount'] : 0, $invoices));

// Standard amounts tracked individually
$count200 = count(array_filter($invoices, fn($i) => (float)$i['amount'] === 200.00));
$count250 = count(array_filter($invoices, fn($i) => (float)$i['amount'] === 250.00));
$count350 = count(array_filter($invoices, fn($i) => (float)$i['amount'] === 350.00));

// Group all other amounts by value for detailed breakdown
$standardAmts  = [200.00, 250.00, 350.00];
$otherGroups   = [];  // [amount => count]
foreach ($invoices as $inv) {
    $amt = (float)$inv['amount'];
    if (!in_array($amt, $standardAmts, true)) {
        $key = number_format($amt, 2, '.', '');
        $otherGroups[$key] = ($otherGroups[$key] ?? 0) + 1;
    }
}
ksort($otherGroups);
$countOther     = array_sum($otherGroups);
$otherAmtTotal  = $grandTotal - ($count200 * 200) - ($count250 * 250) - ($count350 * 350);

// ── Branch display details ────────────────────────────────────────────────────
$branchDetails = [
    'East Rand'      => [
        'display' => 'Hyundai East Rand',
        'address' => '1 Pond Road, Boksburg 1508',
        'po_box'  => 'P O Box 6745',
        'reg'     => '1999/015934/07',
        'vat'     => '4170186698',
        'bank'    => 'FNB Cheque Account 62837511189',
    ],
    'Airport Branch' => [
        'display' => 'Hyundai Airport',
        'address' => '9 Catalina Avenue, Kempton Park 2190',
        'po_box'  => '',
        'reg'     => '1999/15934/07',
        'vat'     => '4170186698',
        'bank'    => 'FNB Cheque Account 62837511189',
    ],
    'The Glen'       => [
        'display' => 'Hyundai The Glen',
        'address' => '4 Boundary Road, Oakdene 2190',
        'po_box'  => '',
        'reg'     => '1999/015934/07',
        'vat'     => '4170186698',
        'bank'    => 'FNB Cheque Account 62837511189',
    ],
];

$bd = $branchDetails[$branch['name']] ?? [
    'display' => 'Hyundai ' . $branch['name'],
    'address' => $branch['address'],
    'po_box'  => '',
    'reg'     => $branch['reg_number'],
    'vat'     => $branch['vat_number'],
    'bank'    => '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement – <?= htmlspecialchars($bd['display']) ?> – <?= htmlspecialchars($periodLabel) ?></title>
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
        .stmt-wrap {
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
        .stmt-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-bottom: 2.5px solid #111;
            min-height: 120px;
        }
        .hdr-left {
            padding: 18px 24px;
            border-right: 2.5px solid #111;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .hdr-right {
            padding: 18px 24px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* CS logo */
        .cs-logo-inner { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .slash-grp { display: flex; gap: 5px; }
        .slash    { width: 18px; height: 52px; background: #003087; clip-path: polygon(28% 0%, 100% 0%, 72% 100%, 0% 100%); }
        .slash-sm { width: 11px; height: 52px; background: #003087; clip-path: polygon(28% 0%, 100% 0%, 72% 100%, 0% 100%); }
        .cs-name  { font-size: 20px; font-weight: 900; line-height: 1.15; letter-spacing: 1px; color: #003087; margin: 0 6px; }

        .cs-detail { font-size: 10.5px; line-height: 1.85; color: #333; }
        .cs-detail strong { font-size: 11px; color: #111; }

        /* Branch side */
        .branch-name   { font-size: 18px; font-weight: 900; color: #003087; margin-bottom: 6px; }
        .branch-detail { font-size: 10.5px; line-height: 1.85; color: #333; }
        .branch-detail strong { color: #111; }

        /* ── Title band ────────────────────────────────────────────────────── */
        .stmt-title-band {
            background: #003087;
            color: white;
            padding: 10px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stmt-title-band h2 { font-size: 15px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; }
        .stmt-period        { font-size: 11px; font-weight: 600; opacity: 0.85; }

        /* ── Summary bar ───────────────────────────────────────────────────── */
        .summary-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 1px solid #dde;
            background: #f6f8ff;
        }
        .sum-item { padding: 12px 16px; border-right: 1px solid #dde; text-align: center; }
        .sum-item:last-child { border-right: none; }
        .sum-label { font-size: 8px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: #666; margin-bottom: 4px; }
        .sum-value { font-size: 16px; font-weight: 900; color: #003087; }
        .sum-value.green  { color: #1a7a3a; }
        .sum-value.orange { color: #b05a00; }

        /* ── Invoice table ─────────────────────────────────────────────────── */
        .inv-table-wrap { flex: 1; }
        table.stmt-tbl  { width: 100%; border-collapse: collapse; }

        table.stmt-tbl thead th {
            background: #003087;
            color: white;
            padding: 9px 14px;
            text-align: left;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        table.stmt-tbl thead th:nth-child(3),
        table.stmt-tbl thead th:nth-child(4) { text-align: center; }
        table.stmt-tbl thead th:last-child    { text-align: right; }

        table.stmt-tbl tbody tr              { border-bottom: 1px solid #eef; }
        table.stmt-tbl tbody tr:nth-child(even) { background: #fafbff; }
        table.stmt-tbl tbody td              { padding: 7px 14px; font-size: 11.5px; vertical-align: middle; }
        table.stmt-tbl tbody td:nth-child(3),
        table.stmt-tbl tbody td:nth-child(4) { text-align: center; }
        table.stmt-tbl tbody td:last-child   { text-align: right; font-weight: 700; }

        .inv-num { font-weight: 700; color: #003087; }

        .s-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .s-paid        { background: #d4edda; color: #155724; }
        .s-outstanding { background: #fff3cd; color: #856404; }

        /* Totals footer row */
        .stmt-tbl tfoot td {
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 700;
            background: #eef1fa;
            border-top: 2px solid #003087;
        }
        .stmt-tbl tfoot td:last-child { text-align: right; }

        /* ── Breakdown + Remittance ────────────────────────────────────────── */
        .bottom-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-top: 2px solid #003087;
            margin-top: auto;
        }
        .breakdown-block  { padding: 16px 24px; border-right: 2px solid #dde; }
        .remittance-block { padding: 16px 24px; background: #f6f8ff; }

        .block-title {
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #003087;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ccd;
        }
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
            font-size: 11.5px;
        }
        .breakdown-row:last-child { border-bottom: none; }
        .breakdown-row.total-row {
            font-weight: 800;
            font-size: 13px;
            border-top: 2px solid #003087;
            margin-top: 4px;
            padding-top: 8px;
            border-bottom: none;
        }
        .breakdown-row.total-row span:last-child { color: #003087; }

        .remittance-total {
            background: #003087;
            color: white;
            padding: 12px 16px;
            border-radius: 4px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .rem-lbl { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .rem-amt { font-size: 22px; font-weight: 900; }

        /* ── Footer ────────────────────────────────────────────────────────── */
        .stmt-footer {
            background: #f0f3fa;
            border-top: 2px solid #003087;
            padding: 10px 24px;
            font-size: 9.5px;
            color: #888;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stmt-footer strong { color: #555; }

        /* ── Print ─────────────────────────────────────────────────────────── */
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-actions { display: none !important; }
            .stmt-wrap { width: 100%; min-height: 100vh; box-shadow: none; margin: 0; }
        }
    </style>
</head>
<body>

<div class="page-actions">
    <a href="javascript:history.back()" class="btn-action btn-back">&#8592; Back</a>
    <button onclick="window.print()" class="btn-action btn-print">&#128438; Print Statement</button>
</div>

<div class="stmt-wrap">

    <div class="accent-top"></div>

    <!-- ══ DUAL HEADER ══════════════════════════════════════════════════════ -->
    <div class="stmt-header">

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
                Registration No: <?= htmlspecialchars($cs['reg']) ?><br>
                <?= htmlspecialchars($cs['address']) ?><br>
                &#9993; <?= htmlspecialchars($cs['email']) ?> &nbsp;|&nbsp; &#9990; <?= htmlspecialchars($cs['phone']) ?>
            </div>
        </div>

        <!-- RIGHT: Hyundai Branch -->
        <div class="hdr-right">
            <div class="branch-name"><?= htmlspecialchars($bd['display']) ?></div>
            <div class="branch-detail">
                <strong>Registration No:</strong> <?= htmlspecialchars($bd['reg']) ?><br>
                <strong>VAT Reg No:</strong> <?= htmlspecialchars($bd['vat']) ?><br>
                <?= htmlspecialchars($bd['address']) ?>
                <?php if ($bd['po_box']): ?>
                    &nbsp;|&nbsp; <?= htmlspecialchars($bd['po_box']) ?>
                <?php endif; ?><br>
                <?php if ($bd['bank']): ?>
                    <?= htmlspecialchars($bd['bank']) ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ══ TITLE BAND ═══════════════════════════════════════════════════════ -->
    <div class="stmt-title-band">
        <h2>Remittance Statement</h2>
        <span class="stmt-period"><?= htmlspecialchars($periodLabel) ?></span>
    </div>

    <!-- ══ SUMMARY BAR ══════════════════════════════════════════════════════ -->
    <div class="summary-bar">
        <div class="sum-item">
            <div class="sum-label">Total Invoices</div>
            <div class="sum-value"><?= count($invoices) ?></div>
        </div>
        <div class="sum-item">
            <div class="sum-label">Total Invoiced</div>
            <div class="sum-value">R<?= number_format($grandTotal, 2) ?></div>
        </div>
        <div class="sum-item">
            <div class="sum-label">Paid</div>
            <div class="sum-value green">R<?= number_format($totalPaid, 2) ?></div>
        </div>
        <div class="sum-item">
            <div class="sum-label">Outstanding</div>
            <div class="sum-value orange">R<?= number_format($totalOutst, 2) ?></div>
        </div>
    </div>

    <!-- ══ INVOICE TABLE ════════════════════════════════════════════════════ -->
    <div class="inv-table-wrap">
        <?php if (empty($invoices)): ?>
            <div style="padding:40px 24px; text-align:center; color:#999; font-size:13px;">
                No invoices found for this branch and period.
            </div>
        <?php else: ?>
        <table class="stmt-tbl">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Order No</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><span class="inv-num"><?= htmlspecialchars($inv['invoice_number']) ?></span></td>
                    <td><?= htmlspecialchars($inv['order_number']) ?></td>
                    <td><?= date('d M Y', strtotime($inv['service_date'])) ?></td>
                    <td>
                        <span class="s-badge s-<?= htmlspecialchars($inv['status']) ?>">
                            <?= ucfirst(htmlspecialchars($inv['status'])) ?>
                        </span>
                    </td>
                    <td>R<?= number_format((float)$inv['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">
                        TOTAL &nbsp;&mdash;&nbsp; <?= count($invoices) ?> invoice(s)
                        <?php if ($count200 > 0): ?>&nbsp;|&nbsp; <?= $count200 ?> &times; R200<?php endif; ?>
                        <?php if ($count250 > 0): ?>&nbsp;|&nbsp; <?= $count250 ?> &times; R250<?php endif; ?>
                        <?php if ($count350 > 0): ?>&nbsp;|&nbsp; <?= $count350 ?> &times; R350<?php endif; ?>
                        <?php foreach ($otherGroups as $amt => $cnt): ?>
                            &nbsp;|&nbsp; <?= $cnt ?> &times; R<?= $amt ?>
                        <?php endforeach; ?>
                    </td>
                    <td>R<?= number_format($grandTotal, 2) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

    <!-- ══ BREAKDOWN + REMITTANCE ═══════════════════════════════════════════ -->
    <div class="bottom-section">

        <!-- Amount breakdown -->
        <div class="breakdown-block">
            <div class="block-title">Amount Breakdown</div>
            <?php if ($count200 > 0): ?>
            <div class="breakdown-row">
                <span><?= $count200 ?> &times; R200.00 invoices</span>
                <span>R<?= number_format($count200 * 200, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($count250 > 0): ?>
            <div class="breakdown-row">
                <span><?= $count250 ?> &times; R250.00 invoices</span>
                <span>R<?= number_format($count250 * 250, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($count350 > 0): ?>
            <div class="breakdown-row">
                <span><?= $count350 ?> &times; R350.00 invoices</span>
                <span>R<?= number_format($count350 * 350, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php foreach ($otherGroups as $amt => $cnt): ?>
            <div class="breakdown-row">
                <span><?= $cnt ?> &times; R<?= $amt ?> invoices</span>
                <span>R<?= number_format($cnt * (float)$amt, 2) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="breakdown-row" style="border-top:1px solid #ccd; margin-top:4px; padding-top:6px;">
                <span>Paid</span>
                <span style="color:#1a7a3a">R<?= number_format($totalPaid, 2) ?></span>
            </div>
            <div class="breakdown-row">
                <span>Outstanding</span>
                <span style="color:#b05a00">R<?= number_format($totalOutst, 2) ?></span>
            </div>
            <div class="breakdown-row total-row">
                <span>Total Remittance</span>
                <span>R<?= number_format($grandTotal, 2) ?></span>
            </div>
        </div>

        <!-- Remittance box -->
        <div class="remittance-block">
            <div class="block-title">Remittance</div>
            <div class="branch-detail" style="margin-bottom:10px;">
                Please remit payment to:<br><br>
                <strong><?= htmlspecialchars($cs['name']) ?></strong><br>
                <?php if ($bd['bank']): ?>
                    <?= htmlspecialchars($bd['bank']) ?><br>
                <?php endif; ?>
                Ref: <?= htmlspecialchars($bd['display']) ?> &ndash; <?= htmlspecialchars($periodLabel) ?>
            </div>
            <div class="remittance-total">
                <span class="rem-lbl">Total Remittance</span>
                <span class="rem-amt">R<?= number_format($grandTotal, 2) ?></span>
            </div>
        </div>

    </div>

    <!-- ══ FOOTER ════════════════════════════════════════════════════════════ -->
    <div class="stmt-footer">
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
