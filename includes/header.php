<?php
// includes/header.php — Shared HTML header and nav

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';

$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<nav class="navbar">
    <div class="nav-brand">
        <span class="brand-icon">✦</span>
        <span class="brand-text">Conscious Solutions</span>
    </div>
    <div class="nav-links">
        <a href="<?= BASE_PATH ?>/pages/dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="<?= BASE_PATH ?>/pages/invoices.php" class="nav-link <?= $currentPage === 'invoices' ? 'active' : '' ?>">Invoices</a>
        <a href="<?= BASE_PATH ?>/pages/payslips.php" class="nav-link <?= $currentPage === 'payslips' ? 'active' : '' ?>">Payslips</a>
        <?php if (isAdmin()): ?>
        <a href="<?= BASE_PATH ?>/pages/statement.php" class="nav-link <?= $currentPage === 'statement' ? 'active' : '' ?>">Statement</a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/pages/timeclock.php" class="nav-link <?= $currentPage === 'timeclock' ? 'active' : '' ?>">⏱ Time Clock</a>
        <?php if (isAdmin()): ?>
        <a href="<?= BASE_PATH ?>/pages/attendance.php" class="nav-link <?= $currentPage === 'attendance' ? 'active' : '' ?>">📋 Attendance</a>
        <a href="<?= BASE_PATH ?>/pages/users.php" class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">Users</a>
        <a href="<?= BASE_PATH ?>/pages/settings.php" class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>">&#9881; Settings</a>
        <?php endif; ?>
    </div>
    <div class="nav-user">
        <span class="user-badge <?= $user['role'] === ROLE_ADMIN ? 'badge-admin' : 'badge-mechanic' ?>">
            <?= $user['role'] === ROLE_ADMIN ? 'Admin' : 'Technician' ?>
        </span>
        <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-sm btn-logout">⏻ Logout</a>
    </div>
</nav>

<main class="main-content">
