<?php
// includes/payslips.php — Payslip business logic

require_once __DIR__ . '/../config/db.php';

function generatePayslipNumber(): string {
    $db   = getDB();
    $year = date('Y');
    $stmt = $db->prepare(
        "SELECT payslip_number FROM payslips WHERE payslip_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(["PS-{$year}-%"]);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -4) + 1) : 1;
    return 'PS-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function createPayslip(array $d): array {
    $db  = getDB();
    $num = generatePayslipNumber();

    $gross = (float)$d['basic_salary']     + (float)$d['overtime_pay']
           + (float)$d['bonus']            + (float)$d['travel_allowance']
           + (float)$d['other_allowances'];
    $deds  = (float)$d['paye']             + (float)$d['uif_employee']
           + (float)$d['pension_fund']     + (float)$d['loan_deduction']
           + (float)$d['other_deductions'];
    $net   = $gross - $deds;

    $stmt = $db->prepare('
        INSERT INTO payslips (
            payslip_number, employee_id,
            emp_name, emp_id_number, emp_job_title, emp_tax_number, emp_uif_number,
            emp_bank_name, emp_account_no, emp_account_type, emp_branch_code,
            salary_month, payment_date,
            w1_normal, w1_overtime, w1_leave,
            w2_normal, w2_overtime, w2_leave,
            w3_normal, w3_overtime, w3_leave,
            w4_normal, w4_overtime, w4_leave,
            w5_normal, w5_overtime, w5_leave,
            basic_salary, overtime_pay, bonus, travel_allowance, other_allowances,
            paye, uif_employee, pension_fund, loan_deduction, other_deductions,
            company_tel,
            gross_earnings, total_deductions, net_pay, created_by
        ) VALUES (
            :num, :eid,
            :ename, :eid_no, :ejob, :etax, :euif,
            :ebank, :eacno, :eactype, :ebrcode,
            :month, :paydate,
            :w1n, :w1o, :w1l, :w2n, :w2o, :w2l,
            :w3n, :w3o, :w3l, :w4n, :w4o, :w4l, :w5n, :w5o, :w5l,
            :basic, :ot, :bonus, :travel, :oallow,
            :paye, :uif, :pension, :loan, :oded,
            :comptel,
            :gross, :deds, :net, :created_by
        )
    ');

    $stmt->execute([
        'num'       => $num,
        'eid'       => !empty($d['employee_id']) ? (int)$d['employee_id'] : null,
        'ename'     => $d['emp_name'],
        'eid_no'    => $d['emp_id_number']    ?: null,
        'ejob'      => $d['emp_job_title']    ?: null,
        'etax'      => $d['emp_tax_number']   ?: null,
        'euif'      => $d['emp_uif_number']   ?: null,
        'ebank'     => $d['emp_bank_name']    ?: null,
        'eacno'     => $d['emp_account_no']   ?: null,
        'eactype'   => $d['emp_account_type'] ?: null,
        'ebrcode'   => $d['emp_branch_code']  ?: null,
        'month'     => $d['salary_month'],
        'paydate'   => $d['payment_date']     ?: null,
        'w1n' => (float)($d['w1_normal']   ?? 0), 'w1o' => (float)($d['w1_overtime'] ?? 0), 'w1l' => (float)($d['w1_leave'] ?? 0),
        'w2n' => (float)($d['w2_normal']   ?? 0), 'w2o' => (float)($d['w2_overtime'] ?? 0), 'w2l' => (float)($d['w2_leave'] ?? 0),
        'w3n' => (float)($d['w3_normal']   ?? 0), 'w3o' => (float)($d['w3_overtime'] ?? 0), 'w3l' => (float)($d['w3_leave'] ?? 0),
        'w4n' => (float)($d['w4_normal']   ?? 0), 'w4o' => (float)($d['w4_overtime'] ?? 0), 'w4l' => (float)($d['w4_leave'] ?? 0),
        'w5n' => (float)($d['w5_normal']   ?? 0), 'w5o' => (float)($d['w5_overtime'] ?? 0), 'w5l' => (float)($d['w5_leave'] ?? 0),
        'basic'  => (float)$d['basic_salary'],    'ot'     => (float)$d['overtime_pay'],
        'bonus'  => (float)$d['bonus'],            'travel' => (float)$d['travel_allowance'],
        'oallow' => (float)$d['other_allowances'],
        'paye'   => (float)$d['paye'],             'uif'    => (float)$d['uif_employee'],
        'pension'=> (float)$d['pension_fund'],     'loan'   => (float)$d['loan_deduction'],
        'oded'    => (float)$d['other_deductions'],
        'comptel' => $d['company_tel'] ?? null,
        'gross'   => $gross, 'deds' => $deds, 'net' => $net,
        'created_by' => $d['created_by'],
    ]);

    return ['success' => true, 'payslip_number' => $num, 'id' => (int)$db->lastInsertId()];
}

function getPayslips(?int $employeeId = null): array {
    $db     = getDB();
    $where  = ['1=1'];
    $params = [];
    if ($employeeId) { $where[] = 'p.employee_id = ?'; $params[] = $employeeId; }
    $stmt = $db->prepare(
        'SELECT p.*, u.name AS user_name
         FROM payslips p
         LEFT JOIN users u ON u.id = p.employee_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.salary_month DESC, p.id DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getPayslipById(int $id): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT p.*, u.name AS user_name, b.name AS branch_name
         FROM payslips p
         LEFT JOIN users u ON u.id = p.employee_id
         LEFT JOIN branches b ON b.id = u.branch_id
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getLastPayslipForEmployee(int $employeeId): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM payslips WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$employeeId]);
    return $stmt->fetch() ?: null;
}

function updatePayslip(int $id, array $d): array {
    $db = getDB();

    $gross = (float)$d['basic_salary']     + (float)$d['overtime_pay']
           + (float)$d['bonus']            + (float)$d['travel_allowance']
           + (float)$d['other_allowances'];
    $deds  = (float)$d['paye']             + (float)$d['uif_employee']
           + (float)$d['pension_fund']     + (float)$d['loan_deduction']
           + (float)$d['other_deductions'];
    $net   = $gross - $deds;

    $stmt = $db->prepare('
        UPDATE payslips SET
            employee_id    = :eid,
            emp_name       = :ename,  emp_id_number  = :eid_no,
            emp_job_title  = :ejob,   emp_tax_number = :etax,
            emp_uif_number = :euif,   emp_bank_name  = :ebank,
            emp_account_no = :eacno,  emp_account_type = :eactype,
            emp_branch_code = :ebrcode,
            salary_month   = :month,  payment_date   = :paydate,
            w1_normal = :w1n, w1_overtime = :w1o, w1_leave = :w1l,
            w2_normal = :w2n, w2_overtime = :w2o, w2_leave = :w2l,
            w3_normal = :w3n, w3_overtime = :w3o, w3_leave = :w3l,
            w4_normal = :w4n, w4_overtime = :w4o, w4_leave = :w4l,
            w5_normal = :w5n, w5_overtime = :w5o, w5_leave = :w5l,
            basic_salary      = :basic, overtime_pay     = :ot,
            bonus             = :bonus, travel_allowance = :travel,
            other_allowances  = :oallow,
            paye              = :paye,  uif_employee  = :uif,
            pension_fund      = :pension, loan_deduction = :loan,
            other_deductions  = :oded,
            company_tel       = :comptel,
            gross_earnings    = :gross, total_deductions = :deds, net_pay = :net
        WHERE id = :id
    ');

    $stmt->execute([
        'id'      => $id,
        'eid'     => !empty($d['employee_id']) ? (int)$d['employee_id'] : null,
        'ename'   => $d['emp_name'],
        'eid_no'  => $d['emp_id_number']    ?: null,
        'ejob'    => $d['emp_job_title']    ?: null,
        'etax'    => $d['emp_tax_number']   ?: null,
        'euif'    => $d['emp_uif_number']   ?: null,
        'ebank'   => $d['emp_bank_name']    ?: null,
        'eacno'   => $d['emp_account_no']   ?: null,
        'eactype' => $d['emp_account_type'] ?: null,
        'ebrcode' => $d['emp_branch_code']  ?: null,
        'month'   => $d['salary_month'],
        'paydate' => $d['payment_date']     ?: null,
        'w1n' => (float)($d['w1_normal']   ?? 0), 'w1o' => (float)($d['w1_overtime'] ?? 0), 'w1l' => (float)($d['w1_leave'] ?? 0),
        'w2n' => (float)($d['w2_normal']   ?? 0), 'w2o' => (float)($d['w2_overtime'] ?? 0), 'w2l' => (float)($d['w2_leave'] ?? 0),
        'w3n' => (float)($d['w3_normal']   ?? 0), 'w3o' => (float)($d['w3_overtime'] ?? 0), 'w3l' => (float)($d['w3_leave'] ?? 0),
        'w4n' => (float)($d['w4_normal']   ?? 0), 'w4o' => (float)($d['w4_overtime'] ?? 0), 'w4l' => (float)($d['w4_leave'] ?? 0),
        'w5n' => (float)($d['w5_normal']   ?? 0), 'w5o' => (float)($d['w5_overtime'] ?? 0), 'w5l' => (float)($d['w5_leave'] ?? 0),
        'basic'   => (float)$d['basic_salary'],   'ot'     => (float)$d['overtime_pay'],
        'bonus'   => (float)$d['bonus'],           'travel' => (float)$d['travel_allowance'],
        'oallow'  => (float)$d['other_allowances'],
        'paye'    => (float)$d['paye'],            'uif'    => (float)$d['uif_employee'],
        'pension' => (float)$d['pension_fund'],    'loan'   => (float)$d['loan_deduction'],
        'oded'    => (float)$d['other_deductions'],
        'comptel' => $d['company_tel'] ?? null,
        'gross'   => $gross, 'deds' => $deds, 'net' => $net,
    ]);

    return ['success' => true];
}

function deletePayslip(int $id): bool {
    return getDB()->prepare('DELETE FROM payslips WHERE id = ?')->execute([$id]);
}

/**
 * Monthly PAYE using SARS 2025/2026 tax tables, primary rebate R17,235, threshold R95,750.
 * NOTE: UIF is 1% of gross salary capped at R177.12/month (max salary = R17,712).
 *       For R8,000/month: UIF = R80.00 (NOT R177 — that is the cap for high earners).
 */
function calcPayeMonthly(float $monthlyGross): float {
    $a = $monthlyGross * 12;
    if ($a <= 95750) return 0.0;
    if      ($a <= 237100)  $tax = $a * 0.18;
    elseif  ($a <= 370500)  $tax = 42678   + ($a - 237100)  * 0.26;
    elseif  ($a <= 512800)  $tax = 77362   + ($a - 370500)  * 0.31;
    elseif  ($a <= 673000)  $tax = 121475  + ($a - 512800)  * 0.36;
    elseif  ($a <= 857900)  $tax = 179147  + ($a - 673000)  * 0.39;
    elseif  ($a <= 1817000) $tax = 251258  + ($a - 857900)  * 0.41;
    else                    $tax = 644489  + ($a - 1817000) * 0.45;
    $tax -= 17235;
    return max(0.0, round($tax / 12, 2));
}

function calcUifMonthly(float $monthlyGross): float {
    return round(min($monthlyGross * 0.01, 177.12), 2);
}
