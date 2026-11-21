<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/company.php';

requireLogin();
$user     = currentUser();
$branches = getAllBranches();

$action  = $_GET['action'] ?? 'list';
$message = '';
$error   = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $message = 'Invoice updated successfully.';
}

// ─── CREATE INVOICE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'new') {
    $branchId           = isAdmin() ? (int)$_POST['branch_id'] : (int)$user['branch_id'];
    $orderNumber        = trim($_POST['order_number'] ?? '');
    $serviceDescription = trim($_POST['service_description'] ?? 'Wheel Alignment and Wheel Balancing');
    $serviceDate        = $_POST['service_date'] ?? date('Y-m-d');
    $notes              = trim($_POST['notes'] ?? '');
    $vinNumber          = trim($_POST['vin_number'] ?? '');
    $regNo              = trim($_POST['registration_no'] ?? '');
    $model              = trim($_POST['model'] ?? '');

    // Amount: either a preset value or custom
    $amountType = $_POST['amount_type'] ?? 'preset';
    if ($amountType === 'custom') {
        $baseAmount = (float)($_POST['custom_amount'] ?? 0);
    } else {
        $baseAmount = (float)($_POST['amount'] ?? 0);
    }

    // Additional line items
    $extraItems = [];
    $itemDescs  = $_POST['item_desc']   ?? [];
    $itemAmts   = $_POST['item_amount'] ?? [];
    foreach ($itemDescs as $i => $desc) {
        $desc = trim($desc);
        $amt  = (float)($itemAmts[$i] ?? 0);
        if ($desc !== '' && $amt > 0) {
            $extraItems[] = ['description' => $desc, 'amount' => $amt];
        }
    }

    // Total = base + all extra items
    $extraTotal  = array_sum(array_column($extraItems, 'amount'));
    $totalAmount = $baseAmount + $extraTotal;

    // Validation
    if (empty($orderNumber)) {
        $error = 'Order number is required.';
    } elseif ($baseAmount <= 0) {
        $error = 'Please select or enter a valid service amount.';
    } elseif (empty($serviceDescription)) {
        $error = 'Service description is required.';
    } else {
        $result = createInvoice([
            'branch_id'           => $branchId,
            'mechanic_id'         => $user['id'],
            'order_number'        => $orderNumber,
            'service_description' => $serviceDescription,
            'amount'              => $totalAmount,
            'service_date'        => $serviceDate,
            'notes'               => $notes,
            'vin_number'          => $vinNumber ?: null,
            'registration_no'     => $regNo ?: null,
            'model'               => $model ?: null,
            'items'               => $extraItems,
        ]);
        if ($result['success']) {
            $message = 'Invoice ' . $result['invoice_number'] . ' created successfully.';
            $action  = 'list';
        } else {
            $error = $result['error'] ?? 'Failed to create invoice.';
        }
    }
}

// ─── UPDATE STATUS (admin only) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'status') {
    requireAdmin();
    $invoiceId = (int)$_POST['invoice_id'];
    $status    = $_POST['status'] ?? '';
    if (in_array($status, ['paid', 'outstanding'])) {
        updateInvoiceStatus($invoiceId, $status);
        $message = 'Invoice status updated.';
    }
    $action = 'list';
}

// ─── DELETE INVOICE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $invoiceId = (int)$_POST['invoice_id'];
    // Allow both admin and the technician who owns the invoice
    $inv = getInvoiceById($invoiceId);
    if (!$inv) {
        $error = 'Invoice not found.';
    } elseif (!isAdmin() && (int)$inv['mechanic_id'] !== (int)$user['id']) {
        $error = 'You can only delete your own invoices.';
    } else {
        $result = deleteInvoice($invoiceId);
        $message = $result['success'] ? 'Invoice ' . $result['invoice_number'] . ' deleted.' : ($result['error'] ?? 'Delete failed.');
        if (!$result['success']) $error = $message;
    }
    $action = 'list';
}

// ─── BULK DELETE (admin only) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_delete') {
    requireAdmin();
    $ids = array_filter(array_map('intval', $_POST['invoice_ids'] ?? []), fn($id) => $id > 0);
    $deleted = 0;
    foreach ($ids as $id) {
        $r = deleteInvoice($id);
        if ($r['success']) $deleted++;
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "$deleted invoice(s) deleted."]);
        exit;
    }
    $message = "$deleted invoice(s) deleted.";
    $action  = 'list';
}

// ─── BULK STATUS UPDATE (admin only) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_status') {
    requireAdmin();
    $ids = array_filter(array_map('intval', $_POST['invoice_ids'] ?? []), fn($id) => $id > 0);
    $newStatus = in_array($_POST['bulk_status'] ?? '', ['paid', 'outstanding']) ? $_POST['bulk_status'] : null;
    $updated = 0;
    if ($newStatus) {
        foreach ($ids as $id) { updateInvoiceStatus($id, $newStatus); $updated++; }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "$updated invoice(s) marked as $newStatus."]);
        exit;
    }
    $message = "$updated invoice(s) marked as " . ucfirst($newStatus ?? '') . ".";
    $action  = 'list';
}

// ─── EDIT INVOICE (admin only) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    requireAdmin();
    $invoiceId          = (int)$_POST['invoice_id'];
    $branchId           = (int)$_POST['branch_id'];
    $orderNumber        = trim($_POST['order_number'] ?? '');
    $serviceDescription = trim($_POST['service_description'] ?? 'Wheel Alignment and Wheel Balancing');
    $serviceDate        = $_POST['service_date'] ?? date('Y-m-d');
    $notes              = trim($_POST['notes'] ?? '');
    $vinNumber          = trim($_POST['vin_number'] ?? '');
    $regNo              = trim($_POST['registration_no'] ?? '');
    $model              = trim($_POST['model'] ?? '');

    $amountType = $_POST['amount_type'] ?? 'preset';
    $baseAmount = $amountType === 'custom' ? (float)($_POST['custom_amount'] ?? 0) : (float)($_POST['amount'] ?? 0);

    $extraItems = [];
    foreach ($_POST['item_desc'] ?? [] as $i => $desc) {
        $desc = trim($desc);
        $amt  = (float)(($_POST['item_amount'] ?? [])[$i] ?? 0);
        if ($desc !== '' && $amt > 0) {
            $extraItems[] = ['description' => $desc, 'amount' => $amt];
        }
    }
    $totalAmount = $baseAmount + array_sum(array_column($extraItems, 'amount'));

    if (empty($orderNumber)) {
        $error = 'Order number is required.';
    } elseif ($baseAmount <= 0) {
        $error = 'Please select or enter a valid service amount.';
    } else {
        $result = updateInvoice($invoiceId, [
            'branch_id'           => $branchId,
            'order_number'        => $orderNumber,
            'service_description' => $serviceDescription,
            'amount'              => $totalAmount,
            'service_date'        => $serviceDate,
            'notes'               => $notes ?: null,
            'vin_number'          => $vinNumber ?: null,
            'registration_no'     => $regNo ?: null,
            'model'               => $model ?: null,
            'items'               => $extraItems,
        ]);
        if ($result['success']) {
            header('Location: ' . BASE_PATH . '/pages/invoices.php?action=view&id=' . $invoiceId . '&msg=updated');
            exit;
        }
        $error = 'Failed to update invoice.';
    }
}

// ─── VIEW SINGLE ─────────────────────────────────────────────────────────────
$singleInvoice = null;
if ($action === 'view' && isset($_GET['id'])) {
    $singleInvoice = getInvoiceById((int)$_GET['id']);
    if (!$singleInvoice) { $action = 'list'; }
}

// ─── LOAD EDIT INVOICE ────────────────────────────────────────────────────────
$editInvoice = null;
if ($action === 'edit') {
    requireAdmin();
    $editId      = (int)(isset($_POST['invoice_id']) ? $_POST['invoice_id'] : ($_GET['id'] ?? 0));
    $editInvoice = getInvoiceById($editId);
    if (!$editInvoice) { $action = 'list'; }
}

// ─── LIST FILTERS ─────────────────────────────────────────────────────────────
$filters = [];
if (!isAdmin()) {
    $filters['branch_id'] = $user['branch_id'];
}
if (!empty($_GET['branch_id']) && isAdmin()) {
    $filters['branch_id'] = (int)$_GET['branch_id'];
}
if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

$invoicesList = ($action === 'list') ? getInvoices($filters) : [];

// ─── AJAX FILTER RESPONSE ─────────────────────────────────────────────────────
if ($action === 'list'
    && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach ($invoicesList as $inv) {
        $out[] = [
            'id'             => (int)$inv['id'],
            'invoice_number' => $inv['invoice_number'],
            'order_number'   => $inv['order_number'],
            'branch_name'    => $inv['branch_name'],
            'invoice_prefix' => strtolower($inv['invoice_prefix']),
            'prefix_label'   => $inv['invoice_prefix'],
            'service_date'   => $inv['service_date'],
            'mechanic_name'  => $inv['mechanic_name'],
            'amount'         => (float)$inv['amount'],
            'status'         => $inv['status'],
        ];
    }
    echo json_encode([
        'success'      => true,
        'invoices'     => $out,
        'count'        => count($out),
        'total_display' => formatMoney((float)array_sum(array_column($invoicesList, 'amount'))),
    ]);
    exit;
}

$pageTitle = 'Invoices — Conscious Solutions';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($action === 'new'): ?>
<!-- ═══ CREATE INVOICE FORM ════════════════════════════════════════════════════ -->
<div class="page-header">
    <h2 class="page-title">New Invoice</h2>
    <a href="<?= BASE_PATH ?>/pages/invoices.php" class="btn btn-outline">← Back</a>
</div>

<div class="card card-narrow">
    <form method="POST" action="<?= BASE_PATH ?>/pages/invoices.php?action=new" id="invoiceForm">

        <?php if (isAdmin()): ?>
        <div class="form-group">
            <label>Branch *</label>
            <select name="branch_id" class="form-control" required>
                <option value="">Select branch</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
            <?php
            $mechBranch = array_filter($branches, fn($b) => $b['id'] == $user['branch_id']);
            $mechBranch = reset($mechBranch);
            ?>
            <div class="form-group">
                <label>Branch</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($mechBranch['name'] ?? '') ?>" readonly>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Order Number *</label>
            <input type="text" name="order_number" class="form-control"
                   placeholder="e.g. 143033" required
                   value="<?= htmlspecialchars($_POST['order_number'] ?? '') ?>">
        </div>

        <fieldset class="form-fieldset">
            <legend>Vehicle Details</legend>
            <div class="form-group">
                <label>VIN Number</label>
                <input type="text" name="vin_number" class="form-control"
                       placeholder="17-character VIN" maxlength="17"
                       value="<?= htmlspecialchars($_POST['vin_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Registration No</label>
                <input type="text" name="registration_no" class="form-control"
                       placeholder="e.g. GP 123-456" maxlength="20"
                       value="<?= htmlspecialchars($_POST['registration_no'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Model</label>
                <input type="text" name="model" class="form-control"
                       placeholder="e.g. Hyundai Tucson 2.0" maxlength="100"
                       value="<?= htmlspecialchars($_POST['model'] ?? '') ?>">
            </div>
        </fieldset>

        <!-- ── PRIMARY SERVICE LINE ── -->
        <fieldset class="form-fieldset">
            <legend>Primary Service</legend>

            <div class="form-group">
                <label>Service Description *</label>
                <input type="text" name="service_description" class="form-control"
                       placeholder="e.g. Wheel Alignment and Wheel Balancing"
                       maxlength="255" required
                       value="<?= htmlspecialchars($_POST['service_description'] ?? 'Wheel Alignment and Wheel Balancing') ?>">
            </div>

            <div class="form-group">
                <label>Service Amount *</label>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <select name="amount" id="presetAmount" class="form-control" style="flex:1; min-width:140px;">
                        <option value="">— Custom amount —</option>
                        <option value="200" <?= ($_POST['amount'] ?? '') == '200' ? 'selected' : '' ?>>R200</option>
                        <option value="250" <?= ($_POST['amount'] ?? '') == '250' ? 'selected' : '' ?>>R250</option>
                        <option value="350" <?= ($_POST['amount'] ?? '') == '350' ? 'selected' : '' ?>>R350</option>
                    </select>
                    <input type="number" name="custom_amount" id="customAmount" class="form-control"
                           placeholder="Custom R amount" min="0.01" step="0.01"
                           style="flex:1; min-width:140px; display:none;"
                           value="<?= htmlspecialchars($_POST['custom_amount'] ?? '') ?>">
                    <input type="hidden" name="amount_type" id="amountType" value="<?= ($_POST['amount_type'] ?? 'preset') ?>">
                </div>
                <small style="color:#666; margin-top:4px; display:block;">
                    Select a preset price, or choose "Custom amount" to type any value.
                </small>
            </div>
        </fieldset>

        <!-- ── ADDITIONAL LINE ITEMS ── -->
        <fieldset class="form-fieldset">
            <legend>Additional Services / Items <small style="font-weight:400; color:#666;">(optional)</small></legend>

            <div id="extraItems">
                <?php
                // Repopulate extra items on validation failure
                $postDescs = $_POST['item_desc']   ?? [];
                $postAmts  = $_POST['item_amount'] ?? [];
                foreach ($postDescs as $i => $d):
                    if (trim($d) === '' && empty($postAmts[$i])) continue;
                ?>
                <div class="extra-item-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                    <input type="text" name="item_desc[]" class="form-control"
                           placeholder="Item description" style="flex:3;"
                           value="<?= htmlspecialchars($d) ?>">
                    <input type="number" name="item_amount[]" class="form-control item-amount"
                           placeholder="R amount" min="0.01" step="0.01" style="flex:1; min-width:110px;"
                           value="<?= htmlspecialchars($postAmts[$i] ?? '') ?>">
                    <button type="button" class="btn btn-outline btn-sm remove-item"
                            style="flex-shrink:0; color:#c00; border-color:#c00;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" id="addItemBtn" class="btn btn-outline btn-sm" style="margin-top:4px;">
                + Add Item
            </button>

            <div style="margin-top:12px; padding:10px 12px; background:#f0f4ff;
                        border-left:3px solid #003087; border-radius:3px;">
                <strong>Invoice Total: R<span id="totalDisplay">0.00</span></strong>
                <span style="color:#666; font-size:11px; margin-left:8px;">(primary service + all additional items)</span>
            </div>
        </fieldset>

        <div class="form-group">
            <label>Service Date *</label>
            <input type="date" name="service_date" class="form-control"
                   value="<?= htmlspecialchars($_POST['service_date'] ?? date('Y-m-d')) ?>" required>
        </div>

        <div class="form-group">
            <label>Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Any additional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create Invoice</button>
            <a href="<?= BASE_PATH ?>/pages/invoices.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const presetSel   = document.getElementById('presetAmount');
    const customInput = document.getElementById('customAmount');
    const amountType  = document.getElementById('amountType');
    const totalEl     = document.getElementById('totalDisplay');
    const extraItems  = document.getElementById('extraItems');
    const addBtn      = document.getElementById('addItemBtn');

    function getBaseAmount() {
        if (presetSel.value !== '') return parseFloat(presetSel.value) || 0;
        return parseFloat(customInput.value) || 0;
    }

    function getExtraTotal() {
        let t = 0;
        document.querySelectorAll('.item-amount').forEach(el => {
            t += parseFloat(el.value) || 0;
        });
        return t;
    }

    function updateTotal() {
        totalEl.textContent = (getBaseAmount() + getExtraTotal()).toFixed(2);
    }

    function toggleCustom() {
        if (presetSel.value === '') {
            customInput.style.display = '';
            customInput.required = true;
            amountType.value = 'custom';
        } else {
            customInput.style.display = 'none';
            customInput.required = false;
            customInput.value = '';
            amountType.value = 'preset';
        }
        updateTotal();
    }

    presetSel.addEventListener('change', toggleCustom);
    customInput.addEventListener('input', updateTotal);

    function makeItemRow() {
        const row = document.createElement('div');
        row.className = 'extra-item-row';
        row.style.cssText = 'display:flex; gap:10px; margin-bottom:8px; align-items:center;';
        row.innerHTML = `
            <input type="text" name="item_desc[]" class="form-control"
                   placeholder="Item description" style="flex:3;">
            <input type="number" name="item_amount[]" class="form-control item-amount"
                   placeholder="R amount" min="0.01" step="0.01" style="flex:1; min-width:110px;">
            <button type="button" class="btn btn-outline btn-sm remove-item"
                    style="flex-shrink:0; color:#c00; border-color:#c00;">✕</button>
        `;
        row.querySelector('.item-amount').addEventListener('input', updateTotal);
        row.querySelector('.remove-item').addEventListener('click', () => {
            row.remove();
            updateTotal();
        });
        return row;
    }

    addBtn.addEventListener('click', () => {
        extraItems.appendChild(makeItemRow());
    });

    // Wire up existing rows (repopulated after validation fail)
    document.querySelectorAll('.item-amount').forEach(el => {
        el.addEventListener('input', updateTotal);
    });
    document.querySelectorAll('.remove-item').forEach(btn => {
        btn.addEventListener('click', () => { btn.closest('.extra-item-row').remove(); updateTotal(); });
    });

    // Init
    toggleCustom();
    updateTotal();
})();
</script>

<?php elseif ($action === 'view' && $singleInvoice): ?>
<!-- ═══ VIEW SINGLE INVOICE ════════════════════════════════════════════════════ -->
<div class="page-header">
    <h2 class="page-title">Invoice <?= htmlspecialchars($singleInvoice['invoice_number']) ?></h2>
    <div>
        <a href="<?= BASE_PATH ?>/pages/invoices.php" class="btn btn-outline">← Back</a>
        <a href="<?= BASE_PATH ?>/pages/print_invoice.php?id=<?= $singleInvoice['id'] ?>" class="btn btn-outline" target="_blank">🖨 Print</a>
        <?php if (isAdmin()): ?>
        <a href="<?= BASE_PATH ?>/pages/invoices.php?action=edit&id=<?= $singleInvoice['id'] ?>" class="btn btn-primary">✏ Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="card card-narrow invoice-detail">
    <div class="invoice-header-block">
        <div>
            <div class="invoice-company">Hyundai <?= htmlspecialchars($singleInvoice['branch_name']) ?></div>
            <div class="invoice-meta"><?= htmlspecialchars($singleInvoice['branch_address']) ?></div>
            <div class="invoice-meta">VAT: <?= htmlspecialchars($singleInvoice['vat_number']) ?></div>
            <div class="invoice-meta">Reg: <?= htmlspecialchars($singleInvoice['reg_number']) ?></div>
        </div>
        <div class="invoice-number-block">
            <div class="inv-num"><?= htmlspecialchars($singleInvoice['invoice_number']) ?></div>
            <span class="status-badge status-<?= $singleInvoice['status'] ?>">
                <?= ucfirst($singleInvoice['status']) ?>
            </span>
        </div>
    </div>

    <table class="detail-table">
        <tr><th>Order Number</th><td><?= htmlspecialchars($singleInvoice['order_number']) ?></td></tr>
        <tr><th>Service Date</th><td><?= date('d M Y', strtotime($singleInvoice['service_date'])) ?></td></tr>
        <tr><th>Technician</th><td><?= htmlspecialchars($singleInvoice['mechanic_name']) ?></td></tr>

        <!-- Primary service line -->
        <tr><th colspan="2" class="section-subhead">Services</th></tr>
        <tr>
            <th><?= htmlspecialchars($singleInvoice['service_description'] ?? 'Wheel Alignment and Wheel Balancing') ?></th>
            <td class="amount-highlight">
                R<?= number_format((float)$singleInvoice['amount'] - array_sum(array_column($singleInvoice['items'], 'amount')), 2) ?>
            </td>
        </tr>

        <!-- Additional line items -->
        <?php foreach ($singleInvoice['items'] as $item): ?>
        <tr>
            <th><?= htmlspecialchars($item['description']) ?></th>
            <td>R<?= number_format((float)$item['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>

        <tr>
            <th><strong>TOTAL</strong></th>
            <td><strong class="amount-highlight">R<?= number_format((float)$singleInvoice['amount'], 2) ?></strong></td>
        </tr>

        <?php if ($singleInvoice['model'] || $singleInvoice['vin_number'] || $singleInvoice['registration_no']): ?>
        <tr><th colspan="2" class="section-subhead">Vehicle Details</th></tr>
        <?php if ($singleInvoice['model']): ?>
        <tr><th>Model</th><td><?= htmlspecialchars($singleInvoice['model']) ?></td></tr>
        <?php endif; ?>
        <?php if ($singleInvoice['registration_no']): ?>
        <tr><th>Registration No</th><td><?= htmlspecialchars($singleInvoice['registration_no']) ?></td></tr>
        <?php endif; ?>
        <?php if ($singleInvoice['vin_number']): ?>
        <tr><th>VIN Number</th><td><?= htmlspecialchars($singleInvoice['vin_number']) ?></td></tr>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($singleInvoice['notes']): ?>
        <tr><th>Notes</th><td><?= htmlspecialchars($singleInvoice['notes']) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if (isAdmin()): ?>
    <form method="POST" action="<?= BASE_PATH ?>/pages/invoices.php?action=status" class="status-form">
        <input type="hidden" name="invoice_id" value="<?= $singleInvoice['id'] ?>">
        <label>Update Status:</label>
        <select name="status" class="form-control form-control-sm">
            <option value="outstanding" <?= $singleInvoice['status'] === 'outstanding' ? 'selected' : '' ?>>Outstanding</option>
            <option value="paid" <?= $singleInvoice['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </form>
    <?php endif; ?>

    <?php if (isAdmin() || (int)$singleInvoice['mechanic_id'] === (int)$user['id']): ?>
    <form method="POST" action="<?= BASE_PATH ?>/pages/invoices.php?action=delete" style="margin-top:16px;"
          onsubmit="return confirm('Permanently delete invoice <?= htmlspecialchars($singleInvoice['invoice_number']) ?>? This cannot be undone.')">
        <input type="hidden" name="invoice_id" value="<?= $singleInvoice['id'] ?>">
        <button type="submit" class="btn btn-danger">🗑 Delete Invoice</button>
    </form>
    <?php endif; ?>
</div>

<?php elseif ($action === 'edit' && $editInvoice): ?>
<!-- ═══ EDIT INVOICE FORM (admin only) ══════════════════════════════════════════ -->
<div class="page-header">
    <h2 class="page-title">Edit Invoice <?= htmlspecialchars($editInvoice['invoice_number']) ?></h2>
    <a href="<?= BASE_PATH ?>/pages/invoices.php?action=view&id=<?= $editInvoice['id'] ?>" class="btn btn-outline">← Back</a>
</div>

<div class="card card-narrow">
    <form method="POST" action="<?= BASE_PATH ?>/pages/invoices.php?action=edit" id="editInvoiceForm">
        <input type="hidden" name="invoice_id" value="<?= $editInvoice['id'] ?>">

        <div class="form-group">
            <label>Branch *</label>
            <select name="branch_id" class="form-control" required>
                <option value="">Select branch</option>
                <?php foreach ($branches as $b):
                    $sel = (($_POST['branch_id'] ?? $editInvoice['branch_id']) == $b['id']) ? 'selected' : '';
                ?>
                    <option value="<?= $b['id'] ?>" <?= $sel ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Order Number *</label>
            <input type="text" name="order_number" class="form-control" required
                   value="<?= htmlspecialchars($_POST['order_number'] ?? $editInvoice['order_number']) ?>">
        </div>

        <fieldset class="form-fieldset">
            <legend>Vehicle Details</legend>
            <div class="form-group">
                <label>VIN Number</label>
                <input type="text" name="vin_number" class="form-control" maxlength="17"
                       value="<?= htmlspecialchars($_POST['vin_number'] ?? $editInvoice['vin_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Registration No</label>
                <input type="text" name="registration_no" class="form-control" maxlength="20"
                       value="<?= htmlspecialchars($_POST['registration_no'] ?? $editInvoice['registration_no'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Model</label>
                <input type="text" name="model" class="form-control" maxlength="100"
                       value="<?= htmlspecialchars($_POST['model'] ?? $editInvoice['model'] ?? '') ?>">
            </div>
        </fieldset>

        <fieldset class="form-fieldset">
            <legend>Primary Service</legend>
            <div class="form-group">
                <label>Service Description *</label>
                <input type="text" name="service_description" class="form-control" maxlength="255" required
                       value="<?= htmlspecialchars($_POST['service_description'] ?? $editInvoice['service_description'] ?? 'Wheel Alignment and Wheel Balancing') ?>">
            </div>
            <?php
            // Determine base amount (total minus extra items)
            $editExtraTotal = array_sum(array_column($editInvoice['items'], 'amount'));
            $editBaseAmt    = (float)$editInvoice['amount'] - $editExtraTotal;
            $editPresets    = [200, 250, 350];
            if (isset($_POST['amount_type'])) {
                $editAmtType   = $_POST['amount_type'];
                $editPresetVal = $_POST['amount'] ?? '';
                $editCustomVal = $_POST['custom_amount'] ?? '';
            } else {
                $editAmtType   = in_array($editBaseAmt, $editPresets) ? 'preset' : 'custom';
                $editPresetVal = in_array($editBaseAmt, $editPresets) ? (string)(int)$editBaseAmt : '';
                $editCustomVal = !in_array($editBaseAmt, $editPresets) ? number_format($editBaseAmt, 2, '.', '') : '';
            }
            ?>
            <div class="form-group">
                <label>Service Amount *</label>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <select name="amount" id="editPresetAmount" class="form-control" style="flex:1; min-width:140px;">
                        <option value="">— Custom amount —</option>
                        <option value="200" <?= $editPresetVal === '200' ? 'selected' : '' ?>>R200</option>
                        <option value="250" <?= $editPresetVal === '250' ? 'selected' : '' ?>>R250</option>
                        <option value="350" <?= $editPresetVal === '350' ? 'selected' : '' ?>>R350</option>
                    </select>
                    <input type="number" name="custom_amount" id="editCustomAmount" class="form-control"
                           placeholder="Custom R amount" min="0.01" step="0.01"
                           style="flex:1; min-width:140px; <?= $editAmtType !== 'custom' ? 'display:none;' : '' ?>"
                           value="<?= htmlspecialchars($editCustomVal) ?>">
                    <input type="hidden" name="amount_type" id="editAmountType" value="<?= htmlspecialchars($editAmtType) ?>">
                </div>
            </div>
        </fieldset>

        <fieldset class="form-fieldset">
            <legend>Additional Services / Items <small style="font-weight:400; color:#666;">(optional)</small></legend>

            <div id="editExtraItems">
                <?php
                $editPostDescs = $_POST['item_desc']   ?? null;
                $editPostAmts  = $_POST['item_amount'] ?? null;
                if ($editPostDescs !== null):
                    foreach ($editPostDescs as $i => $d):
                        if (trim($d) === '' && empty($editPostAmts[$i])) continue;
                ?>
                <div class="extra-item-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                    <input type="text" name="item_desc[]" class="form-control"
                           placeholder="Item description" style="flex:3;"
                           value="<?= htmlspecialchars($d) ?>">
                    <input type="number" name="item_amount[]" class="form-control item-amount"
                           placeholder="R amount" min="0.01" step="0.01" style="flex:1; min-width:110px;"
                           value="<?= htmlspecialchars($editPostAmts[$i] ?? '') ?>">
                    <button type="button" class="btn btn-outline btn-sm remove-item"
                            style="flex-shrink:0; color:#c00; border-color:#c00;">✕</button>
                </div>
                <?php endforeach; else:
                    foreach ($editInvoice['items'] as $item):
                ?>
                <div class="extra-item-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                    <input type="text" name="item_desc[]" class="form-control"
                           placeholder="Item description" style="flex:3;"
                           value="<?= htmlspecialchars($item['description']) ?>">
                    <input type="number" name="item_amount[]" class="form-control item-amount"
                           placeholder="R amount" min="0.01" step="0.01" style="flex:1; min-width:110px;"
                           value="<?= htmlspecialchars($item['amount']) ?>">
                    <button type="button" class="btn btn-outline btn-sm remove-item"
                            style="flex-shrink:0; color:#c00; border-color:#c00;">✕</button>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <button type="button" id="editAddItemBtn" class="btn btn-outline btn-sm" style="margin-top:4px;">
                + Add Item
            </button>

            <div style="margin-top:12px; padding:10px 12px; background:#f0f4ff;
                        border-left:3px solid #003087; border-radius:3px;">
                <strong>Invoice Total: R<span id="editTotalDisplay">0.00</span></strong>
            </div>
        </fieldset>

        <div class="form-group">
            <label>Service Date *</label>
            <input type="date" name="service_date" class="form-control" required
                   value="<?= htmlspecialchars($_POST['service_date'] ?? $editInvoice['service_date']) ?>">
        </div>

        <div class="form-group">
            <label>Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Any additional notes..."><?= htmlspecialchars($_POST['notes'] ?? $editInvoice['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?= BASE_PATH ?>/pages/invoices.php?action=view&id=<?= $editInvoice['id'] ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const presetSel   = document.getElementById('editPresetAmount');
    const customInput = document.getElementById('editCustomAmount');
    const amountType  = document.getElementById('editAmountType');
    const totalEl     = document.getElementById('editTotalDisplay');
    const extraItems  = document.getElementById('editExtraItems');
    const addBtn      = document.getElementById('editAddItemBtn');

    function getBase() {
        return presetSel.value !== '' ? parseFloat(presetSel.value) || 0 : parseFloat(customInput.value) || 0;
    }
    function getExtraTotal() {
        let t = 0;
        document.querySelectorAll('#editExtraItems .item-amount').forEach(el => { t += parseFloat(el.value) || 0; });
        return t;
    }
    function updateTotal() { totalEl.textContent = (getBase() + getExtraTotal()).toFixed(2); }
    function toggleCustom() {
        if (presetSel.value === '') {
            customInput.style.display = '';
            customInput.required = true;
            amountType.value = 'custom';
        } else {
            customInput.style.display = 'none';
            customInput.required = false;
            customInput.value = '';
            amountType.value = 'preset';
        }
        updateTotal();
    }

    presetSel.addEventListener('change', toggleCustom);
    customInput.addEventListener('input', updateTotal);

    function makeRow() {
        const row = document.createElement('div');
        row.className = 'extra-item-row';
        row.style.cssText = 'display:flex; gap:10px; margin-bottom:8px; align-items:center;';
        row.innerHTML = `
            <input type="text" name="item_desc[]" class="form-control"
                   placeholder="Item description" style="flex:3;">
            <input type="number" name="item_amount[]" class="form-control item-amount"
                   placeholder="R amount" min="0.01" step="0.01" style="flex:1; min-width:110px;">
            <button type="button" class="btn btn-outline btn-sm remove-item"
                    style="flex-shrink:0; color:#c00; border-color:#c00;">✕</button>
        `;
        row.querySelector('.item-amount').addEventListener('input', updateTotal);
        row.querySelector('.remove-item').addEventListener('click', () => { row.remove(); updateTotal(); });
        return row;
    }

    addBtn.addEventListener('click', () => { extraItems.appendChild(makeRow()); });

    document.querySelectorAll('#editExtraItems .item-amount').forEach(el => {
        el.addEventListener('input', updateTotal);
    });
    document.querySelectorAll('#editExtraItems .remove-item').forEach(btn => {
        btn.addEventListener('click', () => { btn.closest('.extra-item-row').remove(); updateTotal(); });
    });

    updateTotal();
})();
</script>

<?php else: ?>
<!-- ═══ INVOICE LIST ════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <h2 class="page-title">Invoices</h2>
    <a href="<?= BASE_PATH ?>/pages/invoices.php?action=new" class="btn btn-primary">+ New Invoice</a>
</div>

<div id="filter-notification" class="alert" style="display:none;"></div>

<!-- Filters -->
<div class="card filter-bar">
    <form method="GET" class="filter-form">
        <?php if (isAdmin()): ?>
        <div class="filter-group">
            <label>Branch</label>
            <select name="branch_id" class="form-control">
                <option value="">All Branches</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= ($_GET['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-group">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="">All</option>
                <option value="outstanding" <?= ($_GET['status'] ?? '') === 'outstanding' ? 'selected' : '' ?>>Outstanding</option>
                <option value="paid" <?= ($_GET['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
            </select>
        </div>
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_PATH ?>/pages/invoices.php" class="btn btn-outline">Reset</a>
    </form>
</div>

<?php if (isAdmin()): ?>
<div id="bulk-bar" class="bulk-bar" style="display:none;">
    <span><strong id="bulk-count">0</strong> selected</span>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <select id="bulk-status-sel" class="form-control form-control-sm" style="width:auto;">
            <option value="">— Mark as —</option>
            <option value="paid">Paid</option>
            <option value="outstanding">Outstanding</option>
        </select>
        <button type="button" id="bulk-status-btn" class="btn btn-sm bulk-apply-btn">Apply Status</button>
        <button type="button" id="bulk-delete-btn" class="btn btn-danger btn-sm">Delete Selected</button>
    </div>
</div>
<?php endif; ?>

<div class="card" id="invoice-table-card">
    <?php if (empty($invoicesList)): ?>
        <div class="empty-state">No invoices found for the selected filters.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <?php if (isAdmin()): ?>
                    <th class="col-check"><input type="checkbox" id="select-all" title="Select all" style="cursor:pointer; width:15px; height:15px;"></th>
                    <?php else: ?><th class="col-check"></th><?php endif; ?>
                    <th>Invoice #</th>
                    <th>Order #</th>
                    <th>Branch</th>
                    <th>Date</th>
                    <th>Technician</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoicesList as $inv): ?>
                <tr>
                    <?php if (isAdmin()): ?>
                    <td class="col-check"><input type="checkbox" class="row-check" value="<?= $inv['id'] ?>" style="width:15px; height:15px; cursor:pointer;"></td>
                    <?php else: ?><td class="col-check"></td><?php endif; ?>
                    <td><strong><?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                    <td><?= htmlspecialchars($inv['order_number']) ?></td>
                    <td>
                        <span class="branch-badge branch-<?= strtolower($inv['invoice_prefix']) ?>">
                            <?= htmlspecialchars($inv['invoice_prefix']) ?>
                        </span>
                        <?= htmlspecialchars($inv['branch_name']) ?>
                    </td>
                    <td><?= date('d M Y', strtotime($inv['service_date'])) ?></td>
                    <td><?= htmlspecialchars($inv['mechanic_name']) ?></td>
                    <td>R<?= number_format((float)$inv['amount'], 2) ?></td>
                    <td><span class="status-badge status-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                    <td>
                        <a href="<?= BASE_PATH ?>/pages/invoices.php?action=view&id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline">View</a>
                        <a href="<?= BASE_PATH ?>/pages/print_invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline" target="_blank">Print</a>
                        <?php if (isAdmin()): ?>
                        <a href="<?= BASE_PATH ?>/pages/invoices.php?action=edit&id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                        <?php endif; ?>
                        <form method="POST" action="<?= BASE_PATH ?>/pages/invoices.php?action=delete" style="display:inline;"
                              onsubmit="return confirm('Delete invoice <?= htmlspecialchars($inv['invoice_number']) ?>? This cannot be undone.')">
                            <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer">
        <strong><?= count($invoicesList) ?></strong> invoice(s)
        <?php if (isAdmin()): ?>
        &mdash; Total: <strong><?= formatMoney(array_sum(array_column($invoicesList, 'amount'))) ?></strong>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($action === 'list'): ?>
<script>
(function () {
    var basePath  = <?= json_encode(BASE_PATH) ?>;
    var adminUser = <?= json_encode(isAdmin()) ?>;

    var filterForm    = document.querySelector('.filter-form');
    var tableCard     = document.getElementById('invoice-table-card');
    var resetLink     = filterForm ? filterForm.querySelector('a.btn-outline') : null;
    var bulkBar       = document.getElementById('bulk-bar');
    var bulkCountEl   = document.getElementById('bulk-count');
    var bulkStatusSel = document.getElementById('bulk-status-sel');
    var bulkStatusBtn = document.getElementById('bulk-status-btn');
    var bulkDeleteBtn = document.getElementById('bulk-delete-btn');
    var notification  = document.getElementById('filter-notification');

    if (!filterForm || !tableCard) return;

    /* ── Helpers ────────────────────────────────────────────────────── */
    function formatDate(s) {
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var p = s.split('-');
        return parseInt(p[2],10) + ' ' + months[parseInt(p[1],10)-1] + ' ' + p[0];
    }
    function fmtAmt(n) {
        return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function showNote(msg, isErr) {
        if (!notification) return;
        notification.textContent = msg;
        notification.className = 'alert ' + (isErr ? 'alert-error' : 'alert-success');
        notification.style.display = '';
        clearTimeout(notification._t);
        notification._t = setTimeout(function(){ notification.style.display = 'none'; }, 4000);
    }

    /* ── Checkbox / bulk bar ─────────────────────────────────────────── */
    function getCheckedIds() {
        return Array.from(tableCard.querySelectorAll('.row-check:checked')).map(function(c){ return c.value; });
    }
    function syncBulkBar() {
        if (!bulkBar) return;
        var ids = getCheckedIds();
        bulkBar.style.display = ids.length ? '' : 'none';
        if (bulkCountEl) bulkCountEl.textContent = ids.length;
    }
    function reattachCheckboxes() {
        var selectAll = tableCard.querySelector('#select-all');
        var rowChecks = tableCard.querySelectorAll('.row-check');

        if (selectAll) {
            selectAll.checked = false;
            selectAll.addEventListener('change', function() {
                rowChecks.forEach(function(c){ c.checked = selectAll.checked; });
                syncBulkBar();
            });
        }
        rowChecks.forEach(function(c) {
            c.addEventListener('change', function() {
                var all = tableCard.querySelectorAll('.row-check');
                var chk = tableCard.querySelectorAll('.row-check:checked');
                if (selectAll) selectAll.checked = all.length > 0 && all.length === chk.length;
                syncBulkBar();
            });
        });
        syncBulkBar();
    }

    /* ── Table rendering ─────────────────────────────────────────────── */
    function renderTable(data) {
        ['table-responsive','empty-state','table-footer'].forEach(function(cls){
            var el = tableCard.querySelector('.' + cls);
            if (el) el.remove();
        });
        if (bulkBar) { bulkBar.style.display = 'none'; }

        if (!data.invoices.length) {
            tableCard.insertAdjacentHTML('beforeend',
                '<div class="empty-state">No invoices found for the selected filters.</div>');
            return;
        }

        var chkTh = adminUser
            ? '<th class="col-check"><input type="checkbox" id="select-all" title="Select all" style="cursor:pointer;width:15px;height:15px;"></th>'
            : '<th class="col-check"></th>';

        var rows = data.invoices.map(function(inv) {
            var chkTd = adminUser
                ? '<td class="col-check"><input type="checkbox" class="row-check" value="' + inv.id + '" style="width:15px;height:15px;cursor:pointer;"></td>'
                : '<td class="col-check"></td>';
            var editBtn = adminUser
                ? '<a href="' + basePath + '/pages/invoices.php?action=edit&id=' + inv.id + '" class="btn btn-sm btn-outline">Edit</a> '
                : '';
            var statusLabel = inv.status.charAt(0).toUpperCase() + inv.status.slice(1);
            return '<tr>' + chkTd +
                '<td><strong>' + esc(inv.invoice_number) + '</strong></td>' +
                '<td>' + esc(inv.order_number) + '</td>' +
                '<td><span class="branch-badge branch-' + esc(inv.invoice_prefix) + '">' + esc(inv.prefix_label) + '</span> ' + esc(inv.branch_name) + '</td>' +
                '<td>' + formatDate(inv.service_date) + '</td>' +
                '<td>' + esc(inv.mechanic_name) + '</td>' +
                '<td>R' + fmtAmt(inv.amount) + '</td>' +
                '<td><span class="status-badge status-' + inv.status + '">' + statusLabel + '</span></td>' +
                '<td>' +
                    '<a href="' + basePath + '/pages/invoices.php?action=view&id=' + inv.id + '" class="btn btn-sm btn-outline">View</a> ' +
                    '<a href="' + basePath + '/pages/print_invoice.php?id=' + inv.id + '" class="btn btn-sm btn-outline" target="_blank">Print</a> ' +
                    editBtn +
                    '<form method="POST" action="' + basePath + '/pages/invoices.php?action=delete" style="display:inline;"' +
                    ' onsubmit="return confirm(\'Delete invoice ' + esc(inv.invoice_number) + '? This cannot be undone.\')">' +
                    '<input type="hidden" name="invoice_id" value="' + inv.id + '">' +
                    '<button type="submit" class="btn btn-sm btn-danger">Delete</button>' +
                    '</form>' +
                '</td></tr>';
        }).join('');

        var totalLine = adminUser ? ' &mdash; Total: <strong>' + esc(data.total_display) + '</strong>' : '';

        tableCard.insertAdjacentHTML('beforeend',
            '<div class="table-responsive"><table class="data-table">' +
            '<thead><tr>' + chkTh +
            '<th>Invoice #</th><th>Order #</th><th>Branch</th><th>Date</th>' +
            '<th>Technician</th><th>Amount</th><th>Status</th><th>Actions</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<div class="table-footer"><strong>' + data.count + '</strong> invoice(s)' + totalLine + '</div>'
        );
        reattachCheckboxes();
    }

    /* ── Fetch ───────────────────────────────────────────────────────── */
    function fetchInvoices(params) {
        var url = basePath + '/pages/invoices.php' + (params.toString() ? '?' + params : '');
        history.pushState(null, '', url);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.success) renderTable(d); })
            .catch(function(e){ console.error('Filter failed', e); });
    }
    function refreshTable() {
        fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.success) renderTable(d); })
            .catch(function(){});
    }

    /* ── Filter form ─────────────────────────────────────────────────── */
    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var params = new URLSearchParams();
        new FormData(filterForm).forEach(function(val, key){ if (val !== '') params.set(key, val); });
        fetchInvoices(params);
    });
    if (resetLink) {
        resetLink.addEventListener('click', function(e) {
            e.preventDefault();
            filterForm.querySelectorAll('select').forEach(function(s){ s.value = ''; });
            filterForm.querySelectorAll('input[type="date"]').forEach(function(i){ i.value = ''; });
            fetchInvoices(new URLSearchParams());
        });
    }
    window.addEventListener('popstate', function() {
        var params = new URLSearchParams(window.location.search);
        filterForm.querySelectorAll('[name]').forEach(function(el){ el.value = params.get(el.name) || ''; });
        refreshTable();
    });

    /* ── Bulk actions ────────────────────────────────────────────────── */
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            var ids = getCheckedIds();
            if (!ids.length) return;
            if (!confirm('Permanently delete ' + ids.length + ' invoice(s)? This cannot be undone.')) return;
            var fd = new FormData();
            ids.forEach(function(id){ fd.append('invoice_ids[]', id); });
            fetch(basePath + '/pages/invoices.php?action=bulk_delete', {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r){ return r.json(); })
              .then(function(d){ showNote(d.message || 'Done.', false); refreshTable(); })
              .catch(function(){ showNote('Error deleting invoices.', true); });
        });
    }
    if (bulkStatusBtn) {
        bulkStatusBtn.addEventListener('click', function() {
            var ids = getCheckedIds();
            var status = bulkStatusSel ? bulkStatusSel.value : '';
            if (!status) { showNote('Please select a status to apply.', true); return; }
            if (!ids.length) return;
            var fd = new FormData();
            ids.forEach(function(id){ fd.append('invoice_ids[]', id); });
            fd.append('bulk_status', status);
            fetch(basePath + '/pages/invoices.php?action=bulk_status', {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r){ return r.json(); })
              .then(function(d){ showNote(d.message || 'Done.', false); refreshTable(); })
              .catch(function(){ showNote('Error updating status.', true); });
        });
    }

    /* ── Init ────────────────────────────────────────────────────────── */
    reattachCheckboxes();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
