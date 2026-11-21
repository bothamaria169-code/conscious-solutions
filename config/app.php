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

// ── Security hardening ──────────────────────────────────────────────────
// Never show PHP errors to visitors; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$csIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data:; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    if ($csIsHttps) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header_remove('X-Powered-By');
}

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $csIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Bind the session to the browser that logged in, and cap its total age.
if (!empty($_SESSION['user_id'])) {
    $uaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $tooOld = isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 43200; // 12 h
    if ((isset($_SESSION['ua_hash']) && !hash_equals($_SESSION['ua_hash'], $uaHash)) || $tooOld) {
        session_unset();
        session_destroy();
        if (PHP_SAPI !== 'cli') {
            header('Location: ' . BASE_PATH . '/index.php?timeout=1');
            exit;
        }
    }
}

// ── CSRF protection ─────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

function csrfValid(): bool {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrfToken(), $sent);
}

// Every POST must carry the token.
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrfValid()) {
    http_response_code(403);
    exit('Your session expired or the request was not valid. Please go back, reload the page and try again.');
}

// Add the token to every POST form and fetch() call on the page, including
// forms that JavaScript builds on the fly.
if (PHP_SAPI !== 'cli') {
    ob_start(function (string $html): string {
        if (stripos($html, '</body>') === false) {
            return $html;
        }
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                return $html;
            }
        }
        $t = json_encode(csrfToken());
        $js = '<script>(function(){var T=' . $t . ';'
            . 'document.addEventListener("submit",function(e){var f=e.target;'
            . 'if(!f||!f.method||f.method.toLowerCase()!=="post")return;'
            . 'if(f.querySelector("input[name=csrf_token]"))return;'
            . 'var i=document.createElement("input");i.type="hidden";i.name="csrf_token";i.value=T;f.appendChild(i);},true);'
            . 'var of=window.fetch;if(of){window.fetch=function(u,o){o=o||{};'
            . 'if(o.method&&o.method.toUpperCase()!=="GET"){o.headers=new Headers(o.headers||{});o.headers.set("X-CSRF-Token",T);}'
            . 'return of.call(this,u,o);};}})();</script>';
        return substr_replace($html, $js, strripos($html, '</body>'), 0);
    });
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
