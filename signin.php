<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_PATH . '/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $pin   = trim($_POST['pin'] ?? '');

    if (empty($phone) || empty($pin)) {
        $error = 'Please enter your phone number and PIN.';
    } elseif (($wait = loginLockedFor($phone)) > 0) {
        $error = 'Too many failed attempts. Please try again in ' . ceil($wait / 60) . ' minute(s).';
    } elseif (!login($phone, $pin)) {
        recordLoginFailure($phone);
        usleep(400000);
        $error = 'Invalid phone number or PIN. Please try again.';
    } else {
        clearLoginFailures($phone);
        header('Location: ' . BASE_PATH . '/pages/dashboard.php');
        exit;
    }
}

$timeout = !empty($_GET['timeout']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Conscious Solutions</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="login-body">

<div class="login-container">
    <div class="login-card">
        <div class="login-logo">
            <div class="logo-icon">⚙</div>
            <h1 class="login-title">Conscious Solutions</h1>
            <p class="login-subtitle">Service Management System</p>
        </div>

        <?php if ($timeout): ?>
            <div class="alert alert-warning">Session expired. Please log in again.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_PATH ?>/signin.php" class="login-form">
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="form-control"
                       placeholder="e.g. 0821234567" maxlength="15" required
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="pin">PIN</label>
                <input type="password" id="pin" name="pin" class="form-control"
                       placeholder="Enter your PIN" maxlength="10" required inputmode="numeric">
            </div>
            <button type="submit" class="btn btn-primary btn-full">Login</button>
        </form>

        <div class="login-branches">
            <p>East Rand &bull; Kempton Park &bull; The Glen</p>
        </div>
    </div>
</div>

</body>
</html>
