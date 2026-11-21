<?php
// includes/timeclock.php — Clock-in / Clock-out business logic

require_once __DIR__ . '/../config/db.php';

/**
 * Get the currently active (not clocked out) entry for a user.
 */
function getActiveEntry(int $userId): ?array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM time_entries WHERE user_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Clock a user in.
 */
function clockIn(int $userId, int $branchId, ?string $notes = null): array {
    $db = getDB();

    // Prevent double clock-in
    $active = getActiveEntry($userId);
    if ($active) {
        return ['success' => false, 'error' => 'You are already clocked in since ' . date('H:i', strtotime($active['clock_in'])) . '.'];
    }

    $stmt = $db->prepare(
        'INSERT INTO time_entries (user_id, branch_id, clock_in, notes) VALUES (?, ?, NOW(), ?)'
    );
    $stmt->execute([$userId, $branchId, $notes]);
    return ['success' => true, 'id' => (int)$db->lastInsertId()];
}

/**
 * Clock a user out.
 */
function clockOut(int $userId): array {
    $db = getDB();
    $active = getActiveEntry($userId);
    if (!$active) {
        return ['success' => false, 'error' => 'You are not currently clocked in.'];
    }

    // Calculate total minutes
    $stmt = $db->prepare(
        'UPDATE time_entries
         SET clock_out = NOW(),
             total_minutes = TIMESTAMPDIFF(MINUTE, clock_in, NOW())
         WHERE id = ?'
    );
    $stmt->execute([$active['id']]);

    // Fetch updated record
    $stmt2 = $db->prepare('SELECT * FROM time_entries WHERE id = ?');
    $stmt2->execute([$active['id']]);
    $entry = $stmt2->fetch();

    return ['success' => true, 'total_minutes' => $entry['total_minutes']];
}

/**
 * Get all time entries for a user, optionally filtered by date range.
 */
function getUserTimeEntries(int $userId, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where = ['te.user_id = ?'];
    $params = [$userId];

    if ($dateFrom) { $where[] = 'DATE(te.clock_in) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(te.clock_in) <= ?'; $params[] = $dateTo; }

    $sql = 'SELECT te.*, u.name AS user_name, b.name AS branch_name
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            JOIN branches b ON b.id = te.branch_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY te.clock_in DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get all time entries across all users (admin view), optionally filtered.
 */
function getAllTimeEntries(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if ($branchId) { $where[] = 'te.branch_id = ?'; $params[] = $branchId; }
    if ($dateFrom) { $where[] = 'DATE(te.clock_in) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(te.clock_in) <= ?'; $params[] = $dateTo; }

    $sql = 'SELECT te.*, u.name AS user_name, b.name AS branch_name
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            JOIN branches b ON b.id = te.branch_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY te.clock_in DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get daily summary per user for admin payroll view.
 * Returns rows with user_name, branch_name, work_date, total_minutes for that day.
 */
function getDailySummary(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where = ['te.clock_out IS NOT NULL']; // Only completed sessions
    $params = [];

    if ($branchId) { $where[] = 'te.branch_id = ?'; $params[] = $branchId; }
    if ($dateFrom) { $where[] = 'DATE(te.clock_in) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(te.clock_in) <= ?'; $params[] = $dateTo; }

    $sql = 'SELECT
                u.id AS user_id,
                u.name AS user_name,
                b.name AS branch_name,
                DATE(te.clock_in) AS work_date,
                SUM(te.total_minutes) AS total_minutes,
                MIN(te.clock_in) AS first_in,
                MAX(te.clock_out) AS last_out,
                COUNT(te.id) AS sessions
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            JOIN branches b ON b.id = te.branch_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY u.id, DATE(te.clock_in)
            ORDER BY work_date DESC, u.name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Format minutes as H:MM
 */
function formatMinutes(int $minutes): string {
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h . 'h ' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Standard work day = 8 hours = 480 minutes.
 */
define('STANDARD_DAY_MINUTES', 480);
define('STANDARD_START', '08:00');
define('STANDARD_END',   '16:00');

/**
 * Get daily totals for a single user (for their own timeclock page).
 */
function getUserDailySummary(int $userId, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where  = ['te.user_id = ?', 'te.clock_out IS NOT NULL'];
    $params = [$userId];

    if ($dateFrom) { $where[] = 'DATE(te.clock_in) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(te.clock_in) <= ?'; $params[] = $dateTo; }

    $sql = 'SELECT
                DATE(te.clock_in) AS work_date,
                SUM(te.total_minutes) AS total_minutes,
                MIN(te.clock_in) AS first_in,
                MAX(te.clock_out) AS last_out,
                COUNT(te.id) AS sessions
            FROM time_entries te
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY DATE(te.clock_in)
            ORDER BY work_date DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get weekly summary per user for admin view.
 * One row per user per ISO week.
 */
function getWeeklySummary(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where  = ['te.clock_out IS NOT NULL'];
    $params = [];

    if ($branchId) { $where[] = 'te.branch_id = ?'; $params[] = $branchId; }
    if ($dateFrom) { $where[] = 'DATE(te.clock_in) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(te.clock_in) <= ?'; $params[] = $dateTo; }

    $sql = 'SELECT
                u.id AS user_id,
                u.name AS user_name,
                b.name AS branch_name,
                YEARWEEK(te.clock_in, 1) AS year_week,
                MIN(DATE(te.clock_in)) AS week_start,
                MAX(DATE(te.clock_in)) AS week_end,
                SUM(te.total_minutes) AS total_minutes,
                COUNT(DISTINCT DATE(te.clock_in)) AS days_worked
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            JOIN branches b ON b.id = te.branch_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY u.id, YEARWEEK(te.clock_in, 1)
            ORDER BY year_week DESC, u.name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get monthly summary per user for admin view.
 * One row per user per calendar month.
 */
function getMonthlySummary(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null): array {
    $db = getDB();
    $where  = ['te.clock_out IS NOT NULL'];
    $params = [];

    if ($branchId) { $where[] = 'te.branch_id = ?'; $params[] = $branchId; }
    if ($dateFrom) { $where[] = 'DATE(te.clock_in) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(te.clock_in) <= ?'; $params[] = $dateTo; }

    $sql = 'SELECT
                u.id AS user_id,
                u.name AS user_name,
                b.name AS branch_name,
                DATE_FORMAT(te.clock_in, "%Y-%m") AS work_month,
                SUM(te.total_minutes) AS total_minutes,
                COUNT(DISTINCT DATE(te.clock_in)) AS days_worked
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            JOIN branches b ON b.id = te.branch_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY u.id, DATE_FORMAT(te.clock_in, "%Y-%m")
            ORDER BY work_month DESC, u.name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
