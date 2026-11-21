<?php
// config/app.php — Application settings

define('APP_NAME', 'Conscious Solutions');
define('APP_VERSION', '1.0.0');
define('SESSION_LIFETIME', 3600); // 1 hour in seconds

// Invoice amounts allowed
define('INVOICE_AMOUNTS', [200, 250]);

// Branch IDs (matches DB seed)
define('BRANCH_EAST_RAND', 1);
define('BRANCH_KEMPTON',   2);
define('BRANCH_GLEN',      3);

// Roles
define('ROLE_ADMIN',    'master_admin');
define('ROLE_MECHANIC', 'technician');

// Conscious Solutions company details
define('CS_NAME',    'Conscious Solutions (Pty) Ltd');
define('CS_REG',     '2018/579539/07');
define('CS_ADDRESS', '94 President Street, JHB, Gauteng');
define('CS_EMAIL',   'ncubenduduzo23@gmail.com');
define('CS_PHONE',   '0835168273');

// BASE_PATH: empty string if app is at the domain root,
// or e.g. "/conscious-solutions" if hosted in a subfolder.
if (!defined('BASE_PATH')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(preg_replace('#/(pages|includes|config|setup|assets)(/.*)?$#', '', $scriptDir), '/');
    define('BASE_PATH', $base === '.' ? '' : $base);
    unset($scriptDir, $base);
}

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Auto-logout after inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_PATH . '/index.php?timeout=1');
    exit;
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}
