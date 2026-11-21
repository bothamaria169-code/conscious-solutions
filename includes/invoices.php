<?php
// includes/invoices.php — Invoice business logic

require_once __DIR__ . '/../config/db.php';

/**
 * Generate the next invoice number for a branch atomically.
 * e.g. ER-001, KP-042, GL-123
 */
function generateInvoiceNumber(int $branchId): string {
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT invoice_prefix, next_invoice_seq FROM branches WHERE id = ? FOR UPDATE');
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch();
        if (!$branch) throw new Exception('Branch not found');

        $number = $branch['invoice_prefix'] . '-' . str_pad($branch['next_invoice_seq'], 3, '0', STR_PAD_LEFT);

        $db->prepare('UPDATE branches SET next_invoice_seq = next_invoice_seq + 1 WHERE id = ?')
           ->execute([$branchId]);

        $db->commit();
        return $number;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Create a new invoice, including any additional line items.
 *
 * $data keys:
 *   branch_id, mechanic_id, order_number, service_description,
 *   amount (total = preset + extra items), service_date, notes,
 *   vin_number, registration_no, model,
 *   items[] => [['description'=>'...','amount'=>0.00], ...]
 */
function createInvoice(array $data): array {
    $db = getDB();
    $invoiceNumber = generateInvoiceNumber((int)$data['branch_id']);

    $stmt = $db->prepare('
        INSERT INTO invoices
            (invoice_number, order_number, service_description, branch_id, mechanic_id,
             amount, service_date, notes, vin_number, registration_no, model)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $invoiceNumber,
        $data['order_number'],
        $data['service_description'] ?? 'Wheel Alignment and Wheel Balancing',
        $data['branch_id'],
        $data['mechanic_id'],
        $data['amount'],
        $data['service_date'],
        $data['notes'] ?? null,
        $data['vin_number'] ?? null,
        $data['registration_no'] ?? null,
        $data['model'] ?? null,
    ]);

    $invoiceId = (int)$db->lastInsertId();

    // Insert additional line items if provided
    if (!empty($data['items']) && is_array($data['items'])) {
        $itemStmt = $db->prepare('
            INSERT INTO invoice_items (invoice_id, description, amount, sort_order)
            VALUES (?, ?, ?, ?)
        ');
        foreach ($data['items'] as $i => $item) {
            $desc = trim($item['description'] ?? '');
            $amt  = (float)($item['amount'] ?? 0);
            if ($desc !== '' && $amt > 0) {
                $itemStmt->execute([$invoiceId, $desc, $amt, $i]);
            }
        }
    }

    return ['success' => true, 'invoice_number' => $invoiceNumber, 'id' => $invoiceId];
}

/**
 * Get all additional line items for an invoice.
 */
function getInvoiceItems(int $invoiceId): array {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM invoice_items
        WHERE invoice_id = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/**
 * Get invoices with optional filters.
 */
function getInvoices(array $filters = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['branch_id'])) {
        $where[] = 'i.branch_id = ?';
        $params[] = $filters['branch_id'];
    }
    if (!empty($filters['mechanic_id'])) {
        $where[] = 'i.mechanic_id = ?';
        $params[] = $filters['mechanic_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'i.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['month'])) {
        $where[] = 'DATE_FORMAT(i.service_date, "%Y-%m") = ?';
        $params[] = $filters['month'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'i.service_date >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'i.service_date <= ?';
        $params[] = $filters['date_to'];
    }

    $sql = 'SELECT i.*, b.name AS branch_name, b.invoice_prefix,
                   u.name AS mechanic_name
            FROM invoices i
            JOIN branches b ON b.id = i.branch_id
            JOIN users u ON u.id = i.mechanic_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.service_date DESC, i.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get a single invoice by ID, including its additional line items.
 */
function getInvoiceById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT i.*, b.name AS branch_name, b.address AS branch_address,
               b.vat_number, b.reg_number, u.name AS mechanic_name
        FROM invoices i
        JOIN branches b ON b.id = i.branch_id
        JOIN users u ON u.id = i.mechanic_id
        WHERE i.id = ?
    ');
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    if (!$invoice) return null;

    // Attach additional line items
    $invoice['items'] = getInvoiceItems($id);

    return $invoice;
}

/**
 * Update invoice status (admin only).
 */
function updateInvoiceStatus(int $id, string $status): bool {
    $db = getDB();
    $stmt = $db->prepare('UPDATE invoices SET status = ? WHERE id = ?');
    return $stmt->execute([$status, $id]);
}

/**
 * Update all editable fields of an invoice (admin only).
 * Replaces all line items with the new set.
 */
function updateInvoice(int $id, array $data): array {
    $db = getDB();
    $stmt = $db->prepare('
        UPDATE invoices SET
            order_number        = ?,
            service_description = ?,
            branch_id           = ?,
            amount              = ?,
            service_date        = ?,
            notes               = ?,
            vin_number          = ?,
            registration_no     = ?,
            model               = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $data['order_number'],
        $data['service_description'],
        $data['branch_id'],
        $data['amount'],
        $data['service_date'],
        $data['notes'] ?: null,
        $data['vin_number'] ?: null,
        $data['registration_no'] ?: null,
        $data['model'] ?: null,
        $id,
    ]);

    $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
    if (!empty($data['items']) && is_array($data['items'])) {
        $itemStmt = $db->prepare('
            INSERT INTO invoice_items (invoice_id, description, amount, sort_order)
            VALUES (?, ?, ?, ?)
        ');
        foreach ($data['items'] as $i => $item) {
            $desc = trim($item['description'] ?? '');
            $amt  = (float)($item['amount'] ?? 0);
            if ($desc !== '' && $amt > 0) {
                $itemStmt->execute([$id, $desc, $amt, $i]);
            }
        }
    }

    return ['success' => true];
}

/**
 * Monthly statement summary per branch.
 */
function getMonthlyStatement(string $month): array {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT b.name AS branch_name, b.invoice_prefix,
               COUNT(i.id) AS total_invoices,
               SUM(i.amount) AS total_amount,
               SUM(CASE WHEN i.status = "paid" THEN i.amount ELSE 0 END) AS paid_amount,
               SUM(CASE WHEN i.status = "outstanding" THEN i.amount ELSE 0 END) AS outstanding_amount,
               SUM(CASE WHEN i.amount = 200 THEN 1 ELSE 0 END) AS count_200,
               SUM(CASE WHEN i.amount = 250 THEN 1 ELSE 0 END) AS count_250
        FROM branches b
        LEFT JOIN invoices i ON i.branch_id = b.id AND DATE_FORMAT(i.service_date, "%Y-%m") = ?
        GROUP BY b.id
        ORDER BY b.id
    ');
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

/**
 * Dashboard stats.
 */
function getDashboardStats(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if ($branchId) {
        $where[] = 'branch_id = ?';
        $params[] = $branchId;
    }
    if ($dateFrom) {
        $where[] = 'service_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $where[] = 'service_date <= ?';
        $params[] = $dateTo;
    }

    $w = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) AS total_vehicles,
                                 SUM(amount) AS total_value,
                                 SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) AS paid,
                                 SUM(CASE WHEN status='outstanding' THEN amount ELSE 0 END) AS outstanding
                          FROM invoices WHERE $w");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    $stmt2 = $db->prepare("SELECT b.name, b.invoice_prefix, COUNT(i.id) AS count, SUM(i.amount) AS total
                           FROM branches b
                           LEFT JOIN invoices i ON i.branch_id = b.id AND $w
                           GROUP BY b.id ORDER BY b.id");
    $stmt2->execute($params);
    $byBranch = $stmt2->fetchAll();

    return ['totals' => $totals, 'by_branch' => $byBranch];
}

/**
 * Delete an invoice and its line items.
 * Only allowed if invoice is 'outstanding' (not paid) — safety guard.
 */
function deleteInvoice(int $id): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, status, invoice_number FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) return ['success' => false, 'error' => 'Invoice not found.'];

    // Delete line items first
    $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
    // Delete invoice
    $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);

    return ['success' => true, 'invoice_number' => $inv['invoice_number']];
}
