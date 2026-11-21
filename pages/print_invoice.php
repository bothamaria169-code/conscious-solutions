<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';

requireLogin();

$invoice = getInvoiceById((int)($_GET['id'] ?? 0));
if (!$invoice) die('Invoice not found.');

$user = currentUser();
if (!isAdmin() && $invoice['branch_id'] != $user['branch_id']) die('Access denied.');

// Calculate base amount (total minus any additional items)
$extraItemsTotal = array_sum(array_column($invoice['items'], 'amount'));
$baseAmount      = (float)$invoice['amount'] - $extraItemsTotal;

// WhatsApp share URL
$waMessage = urlencode(
    "Invoice " . $invoice['invoice_number'] . "\n" .
    "Branch: Hyundai " . $invoice['branch_name'] . "\n" .
    "Order No: " . $invoice['order_number'] . "\n" .
    ($invoice['model']           ? "Model: "  . $invoice['model']           . "\n" : '') .
    ($invoice['registration_no'] ? "Reg No: " . $invoice['registration_no'] . "\n" : '') .
    ($invoice['vin_number']      ? "VIN: "    . $invoice['vin_number']      . "\n" : '') .
    "Amount: R" . number_format((float)$invoice['amount'], 2) . "\n" .
    "Date: " . date('d M Y', strtotime($invoice['service_date'])) . "\n" .
    "Status: " . ucfirst($invoice['status'])
);
$waLink = "https://wa.me/27835168273?text=" . $waMessage;

// ── BRANCH DETAILS (correct addresses) ─────────────────────────────────────
$branchDetails = [
    'East Rand'      => [
        'customer' => 'Hyundai East Rand',
        'address'  => '1 Pond Road, Boksburg 1508',
        'reg'      => '1999/015934/07',
        'vat'      => '4170186698',
    ],
    'Airport Branch' => [
        'customer' => 'Hyundai Airport',
        'address'  => '9 Catalina Avenue, Kempton Park 2190',
        'reg'      => '1999/15934/07',
        'vat'      => '4170186698',
    ],
    'The Glen'       => [
        'customer' => 'Hyundai The Glen',
        'address'  => '4 Boundary Road, Oakdene 2190',
        'reg'      => '1999/015934/07',
        'vat'      => '4170186698',
    ],
];

$branchInfo   = $branchDetails[$invoice['branch_name']] ?? [
    'customer' => 'Hyundai ' . $invoice['branch_name'],
    'address'  => $invoice['branch_address'],
    'reg'      => $invoice['reg_number'],
    'vat'      => $invoice['vat_number'],
];
$customerName = $branchInfo['customer'];
$customerAddr = $branchInfo['address'];
$customerReg  = $branchInfo['reg'];
$customerVat  = $branchInfo['vat'];

$serviceDesc = !empty($invoice['service_description'])
    ? $invoice['service_description']
    : 'Wheel Alignment and Wheel Balancing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #111;
            background: #c8ccd4;
        }

        /* ── Screen action buttons ── */
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
        .btn-wa    { background: #25D366; color: white; }

        /* ── A4 page ── */
        .invoice-wrap {
            width: 794px;
            min-height: 1123px;
            margin: 0 auto 40px;
            background: white;
            display: flex;
            flex-direction: column;
            box-shadow: 0 6px 30px rgba(0,0,0,0.3);
        }

        /* Top accent */
        .accent-top { height: 8px; background: #003087; }

        /* ── Header: logo | inv number | contact ── */
        .inv-header {
            display: grid;
            grid-template-columns: 1fr 210px 1fr;
            border-bottom: 2.5px solid #111;
            min-height: 115px;
        }
        .cs-logo-cell {
            padding: 20px 24px;
            border-right: 2.5px solid #111;
            display: flex;
            align-items: center;
        }
        .cs-logo-inner { display: flex; align-items: center; gap: 8px; }
        .slash-grp { display: flex; gap: 5px; }
        .slash {
            width: 20px; height: 60px; background: #003087;
            clip-path: polygon(28% 0%, 100% 0%, 72% 100%, 0% 100%);
        }
        .slash-sm {
            width: 13px; height: 60px; background: #003087;
            clip-path: polygon(28% 0%, 100% 0%, 72% 100%, 0% 100%);
        }
        .cs-name {
            font-size: 23px; font-weight: 900; line-height: 1.15;
            letter-spacing: 1px; color: #003087; margin: 0 8px;
        }

        .inv-num-cell {
            padding: 14px 12px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            border-right: 2.5px solid #111;
            gap: 5px;
        }
        .inv-no-label { font-size: 8px; font-weight: 800; letter-spacing: 2.5px; color: #666; text-transform: uppercase; }
        .inv-no-value {
            font-size: 28px; font-weight: 900; color: #003087;
            border: 3px solid #003087; padding: 4px 10px;
            text-align: center; width: 100%;
        }
        .inv-date { font-size: 10px; color: #555; font-weight: 600; }
        .status-pill {
            padding: 3px 14px; border-radius: 20px;
            font-size: 8.5px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase;
        }
        .sp-outstanding { background: #fff3cd; color: #856404; }
        .sp-paid        { background: #d4edda; color: #155724; }

        .cs-contact-cell {
            padding: 18px 22px;
            display: flex; flex-direction: column; justify-content: center;
            font-size: 11px; line-height: 1.9;
        }
        .c-row { display: flex; align-items: baseline; gap: 7px; }
        .c-icon { color: #003087; width: 16px; font-size: 12px; }
        .c-bold { font-weight: 700; font-size: 11.5px; }

        /* ── Parties band ── */
        .inv-parties {
            display: grid; grid-template-columns: 1fr 1fr;
            border-bottom: 2.5px solid #111;
            background: #f6f8ff;
        }
        .party-block { padding: 18px 24px; }
        .party-block + .party-block { border-left: 2.5px solid #111; }
        .party-lbl {
            font-size: 8px; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: #003087;
            padding-bottom: 5px; border-bottom: 1px solid #ccd; margin-bottom: 7px;
        }
        .party-name { font-size: 16px; font-weight: 800; margin-bottom: 5px; }
        .party-meta { font-size: 11.5px; line-height: 1.8; color: #444; }

        /* ── Vehicle details ── */
        .vehicle-section {
            padding: 16px 24px;
            border-bottom: 1px solid #dde;
        }
        .sec-heading {
            font-size: 8px; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: #003087;
            display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
        }
        .sec-heading::after { content: ''; flex: 1; height: 1px; background: #003087; opacity: 0.25; }
        .veh-grid {
            display: grid; grid-template-columns: repeat(3, 1fr);
            border: 1px solid #dde; border-radius: 5px; overflow: hidden;
        }
        .veh-item { padding: 11px 16px; border-right: 1px solid #dde; }
        .veh-item:last-child { border-right: none; }
        .veh-lbl { font-size: 8px; color: #999; display: block; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
        .veh-val { font-size: 14px; font-weight: 700; }

        /* ── Service table ── */
        .inv-table-wrap { flex: 1; }
        table.inv-tbl { width: 100%; border-collapse: collapse; }
        table.inv-tbl thead th {
            background: #003087; color: white;
            padding: 12px 24px; text-align: left;
            font-size: 9.5px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
        }
        table.inv-tbl thead th:last-child { text-align: right; }
        table.inv-tbl tbody td {
            padding: 14px 24px; border-bottom: 1px solid #eef;
            font-size: 13px; vertical-align: middle;
        }
        table.inv-tbl tbody td:last-child { text-align: right; font-weight: 700; }
        table.inv-tbl tbody tr.extra-row td { background: #fafbff; color: #444; font-size: 12px; }
        table.inv-tbl tbody tr.note-row td { color: #888; font-style: italic; font-size: 11px; padding: 8px 24px; }

        /* ── Spacer pushes total to bottom ── */
        .inv-spacer { flex: 1; min-height: 60px; }

        /* ── Total bar ── */
        .total-bar {
            display: flex; align-items: center; justify-content: space-between;
            background: #003087; color: white;
            padding: 18px 24px;
        }
        .total-lbl { font-size: 13px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; }
        .total-amt { font-size: 30px; font-weight: 900; letter-spacing: 1px; }

        /* ── Footer ── */
        .inv-footer {
            background: #f0f3fa; border-top: 2px solid #003087;
            padding: 10px 24px; font-size: 9.5px; color: #888;
            display: flex; justify-content: space-between; align-items: center;
        }
        .inv-footer strong { color: #555; }

        /* Bottom accent */
        .accent-bottom { height: 6px; background: linear-gradient(90deg, #003087 60%, #e63946 100%); }

        /* ── PRINT ── */
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-actions { display: none !important; }
            .invoice-wrap { width: 100%; min-height: 100vh; box-shadow: none; margin: 0; }
        }
    </style>
</head>
<body>

<div class="page-actions">
    <a href="<?= $waLink ?>" target="_blank" class="btn-action btn-wa">📲 Share on WhatsApp</a>
    <button onclick="window.print()" class="btn-action btn-print">🖨 Print Invoice</button>
</div>

<div class="invoice-wrap">

    <div class="accent-top"></div>

    <!-- HEADER -->
    <div class="inv-header">
        <div class="cs-logo-cell">
            <div class="cs-logo-inner">
                <div class="slash-grp"><div class="slash"></div><div class="slash"></div></div>
                <div class="cs-name">CONSCIOUS<br>SOLUTIONS</div>
                <div class="slash-grp"><div class="slash-sm"></div><div class="slash-sm"></div><div class="slash-sm"></div></div>
            </div>
        </div>
        <div class="inv-num-cell">
            <div class="inv-no-label">Invoice No</div>
            <div class="inv-no-value"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
            <div class="inv-date">Date: <?= date('d / m / Y', strtotime($invoice['service_date'])) ?></div>
            <span class="status-pill sp-<?= $invoice['status'] ?>"><?= strtoupper($invoice['status']) ?></span>
        </div>
        <div class="cs-contact-cell">
            <div class="c-row"><span class="c-icon">⊙</span><span class="c-bold">REG: <?= CS_REG ?></span></div>
            <div class="c-row"><span class="c-icon">⊛</span><span><?= CS_ADDRESS ?></span></div>
            <div class="c-row"><span class="c-icon">✉</span><span><?= CS_EMAIL ?></span></div>
            <div class="c-row"><span class="c-icon">☎</span><span><?= CS_PHONE ?></span></div>
        </div>
    </div>

    <!-- PARTIES -->
    <div class="inv-parties">
        <div class="party-block">
            <div class="party-lbl">From</div>
            <div class="party-name"><?= CS_NAME ?></div>
            <div class="party-meta">
                <?= CS_ADDRESS ?><br>
                Reg No: <?= CS_REG ?><br>
                ☎ <?= CS_PHONE ?> &nbsp;|&nbsp; <?= CS_EMAIL ?>
            </div>
        </div>
        <div class="party-block">
            <div class="party-lbl">Customer Details</div>
            <div class="party-name"><?= htmlspecialchars($customerName) ?></div>
            <div class="party-meta">
                Reg No: <?= htmlspecialchars($customerReg) ?><br>
                Vat Reg No: <?= htmlspecialchars($customerVat) ?><br>
                <?= htmlspecialchars($customerAddr) ?>
            </div>
        </div>
    </div>

    <!-- VEHICLE DETAILS -->
    <?php if ($invoice['model'] || $invoice['vin_number'] || $invoice['registration_no']): ?>
    <div class="vehicle-section">
        <div class="sec-heading">Vehicle Details</div>
        <div class="veh-grid">
            <div class="veh-item">
                <span class="veh-lbl">Model</span>
                <span class="veh-val"><?= htmlspecialchars($invoice['model'] ?? '—') ?></span>
            </div>
            <div class="veh-item">
                <span class="veh-lbl">Registration No</span>
                <span class="veh-val"><?= htmlspecialchars($invoice['registration_no'] ?? '—') ?></span>
            </div>
            <div class="veh-item">
                <span class="veh-lbl">VIN Number</span>
                <span class="veh-val"><?= htmlspecialchars($invoice['vin_number'] ?? '—') ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SERVICE TABLE -->
    <div class="inv-table-wrap">
        <table class="inv-tbl">
            <thead>
                <tr>
                    <th style="width:42%">Description</th>
                    <th>Order Number</th>
                    <th>Technician</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= htmlspecialchars($serviceDesc) ?></td>
                    <td><?= htmlspecialchars($invoice['order_number']) ?></td>
                    <td><?= htmlspecialchars($invoice['mechanic_name']) ?></td>
                    <td>R<?= number_format($baseAmount, 2) ?></td>
                </tr>
                <?php foreach ($invoice['items'] as $item): ?>
                <tr class="extra-row">
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td></td><td></td>
                    <td>R<?= number_format((float)$item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($invoice['notes']): ?>
                <tr class="note-row">
                    <td colspan="4">📝 Note: <?= htmlspecialchars($invoice['notes']) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- SPACER -->
    <div class="inv-spacer"></div>

    <!-- TOTAL -->
    <div class="total-bar">
        <div class="total-lbl">Total Due</div>
        <div class="total-amt">R<?= number_format((float)$invoice['amount'], 2) ?></div>
    </div>

    <!-- FOOTER -->
    <div class="inv-footer">
        <span><strong><?= CS_NAME ?></strong> &bull; Reg: <?= CS_REG ?> &bull; <?= CS_EMAIL ?></span>
        <span>Generated <?= date('d M Y H:i') ?></span>
    </div>

    <div class="accent-bottom"></div>

</div>
</body>
</html>
