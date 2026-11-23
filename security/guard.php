<?php
/**
 * Conscious Solutions file guard. Lives OUTSIDE the web root, in ~/cs_guard.
 *
 *   php -n guard.php            check and repair (cron, every 5 minutes)
 *   php -n guard.php --deploy   publish the clean copy (run by deploy.sh)
 *   php -n guard.php --scan     read-only malware report for the whole account
 *
 * Repair puts changed files back from clean/ and MOVES unknown scripts to
 * quarantine/. Nothing unknown is ever deleted.
 */
if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
ini_set('display_errors', '1');

const SCRIPT_EXT = '/\.(php[0-9a-z]*|phtml|pht|phar|phps|inc|cgi|pl|py|rb|sh|shtml|shtm|stm|aspx?|jsp)(\.|$)/i';

// Made on the server by the app or the owner. Never overwritten, only checked.
const SERVER_FILES = ['config/db.php', 'config/company.json'];

// PHP settings cPanel may write into .htaccess without us removing them.
const SAFE_INI = 'allow_url_fopen|allow_url_include|asp_tags|date\.timezone|default_charset|display_errors|error_reporting|file_uploads|log_errors|max_execution_time|max_input_time|max_input_vars|memory_limit|post_max_size|session\.gc_maxlifetime|session\.save_path|short_open_tag|upload_max_filesize|zlib\.output_compression';

$guard = str_replace('\\', '/', __DIR__);
$web   = is_file("$guard/webroot") ? rtrim(trim((string)file_get_contents("$guard/webroot")), '/') : '';
$clean = "$guard/clean";
$mode  = $argv[1] ?? '';

if ($mode === '--scan') {
    exit(scanAccount(dirname($guard), $guard));
}

// Without a complete clean copy every real file would look unknown: stop.
foreach (['signin.php', 'index.php', '.htaccess', 'config/app.php', 'includes/auth.php', 'pages/dashboard.php'] as $must) {
    if (!is_file("$clean/$must")) {
        fwrite(STDERR, "Guard stopped: clean/$must is missing. Deploy HEAD Commit again.\n");
        exit(2);
    }
}
if ($web === '' || !is_dir($web)) {
    fwrite(STDERR, "Guard stopped: web root not found.\n");
    exit(2);
}

$deploy = $mode === '--deploy';
$lock   = fopen("$guard/guard.lock", 'c');
if (!$lock || !flock($lock, $deploy ? LOCK_EX : LOCK_EX | LOCK_NB)) {
    exit(0); // another run is busy
}

$events = [];
$failed = false;
$known  = [];

// 1. Every file we ship must match the clean copy.
foreach (listFiles($clean) as $rel) {
    $known[$rel] = true;
    $want = (string)file_get_contents("$clean/$rel");
    $live = "$web/$rel";
    $have = is_file($live) && !is_link($live) ? (string)@file_get_contents($live) : null;

    if ($rel === '.htaccess') {
        $want = withCpanelBlocks($want, $have ?? '');
    }
    if ($have === $want) {
        lockFile($live);
        continue;
    }
    $bad      = $have !== null && isMalware($have);
    $evidence = $have !== null || is_link($live) || is_dir($live);
    if ($evidence && (!$deploy || $bad || $have === null)) {
        // A real file is copied aside; a symlink or folder in its place is moved.
        quarantine($live, $rel, $have !== null);
    }
    if (!writeFile($live, $want)) {
        $failed = true;
        $events[] = "FAILED to restore $rel (check permissions and disk space)";
    } elseif (!$deploy || $bad) {
        $events[] = ($evidence ? 'RESTORED changed ' : 'RESTORED missing ') . $rel . ($bad ? ' (MALWARE found in it)' : '');
    }
    lockFile($live);
}

// 2. Anything extra in the web root that could run code is moved out.
foreach (listFiles($web) as $rel) {
    if (isset($known[$rel])) {
        continue;
    }
    $path = "$web/$rel";
    if (in_array($rel, SERVER_FILES, true) && !is_link($path)) {
        if (isMalware((string)@file_get_contents($path))) {
            $events[] = "WARNING $rel contains malware code. Replace it by hand";
        }
        continue;
    }
    $why = whyUnsafe($path, basename($rel));
    if ($why === '') {
        continue;
    }
    if (quarantine($path, $rel, false)) {
        $events[] = "QUARANTINED $rel ($why)";
    } else {
        $failed = true;
        $events[] = "FAILED to quarantine $rel ($why)";
    }
}

// 3. db.php is not in GitHub, so report any change to it.
$db  = "$web/config/db.php";
$sum = is_file($db) ? hash_file('sha256', $db) : 'missing';
$old = is_file("$guard/db.sha256") ? trim((string)file_get_contents("$guard/db.sha256")) : '';
if ($old !== $sum) {
    if ($old !== '' && !$deploy) {
        $events[] = 'WARNING config/db.php was changed or removed. If that was not you, change the database password';
    }
    file_put_contents("$guard/db.sha256", $sum);
}
if ($sum === 'missing' && $deploy) {
    $events[] = 'WARNING config/db.php is missing. Copy config/db.example.php to config/db.php and fill it in';
}

// 4. Lock the code folders. config/ stays writable for Settings, and
//    public_html itself is left alone so SSL renewal keeps working.
foreach (['assets', 'includes', 'pages'] as $dir) {
    foreach (array_merge([''], listDirs("$clean/$dir")) as $sub) {
        @chmod(rtrim("$web/$dir/$sub", '/'), 0555);
    }
}

file_put_contents("$guard/last_run", date('Y-m-d H:i:s') . "\n");
if ($events) {
    $text = '';
    foreach ($events as $e) {
        $text .= date('Y-m-d H:i:s') . "  $e\n";
    }
    file_put_contents("$guard/guard.log", $text, FILE_APPEND);
    echo $text;
    alert($guard, $text);
} elseif ($deploy) {
    echo "Clean copy published. No malware found in the files it replaced.\n";
}
if ($deploy) {
    echo is_file("$guard/UNLOCKED") ? "Site is OPEN.\n" : "Site is LOCKED (no cs_guard/UNLOCKED file).\n";
}
exit($failed ? 1 : 0);

// ── helpers ─────────────────────────────────────────────────────────────

function walk(string $root, bool $dirs): array {
    $out = [];
    if (!is_dir($root)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $f) {
        // A symlink counts as a file, whatever it points at.
        if (($f->isDir() && !$f->isLink()) === $dirs) {
            $out[] = substr(str_replace('\\', '/', $f->getPathname()), strlen(rtrim($root, '/')) + 1);
        }
    }
    sort($out);
    return $out;
}

function listFiles(string $root): array {
    return walk($root, false);
}

function listDirs(string $root): array {
    return walk($root, true);
}

function lockFile(string $path): void {
    if (is_file($path) && !is_link($path) && (fileperms($path) & 0777) !== 0444) {
        @chmod($path, 0444);
    }
}

// Writes next to the target and renames over it, so it also works when the
// attacker made the file read-only, and visitors never see half a file.
function writeFile(string $path, string $data): bool {
    $dir = dirname($path);
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        quarantine($dir, basename($dir), false);
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    unlockDir($dir);
    $tmp = "$dir/.guard" . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $data) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0444);
    if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) { // local testing only
        chmod($path, 0644);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

// Never touch the mode of public_html itself.
function unlockDir(string $dir): void {
    global $web;
    if ($dir !== $web) {
        @chmod($dir, 0755);
    }
}

function quarantine(string $path, string $rel, bool $copy): bool {
    global $guard;
    $to = "$guard/quarantine/" . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '_' . str_replace('/', '__', $rel) . '.txt';
    if ($copy) {
        $ok = @copy($path, $to);
    } else {
        unlockDir(dirname($path));
        if (!is_link($path)) {
            @chmod($path, is_dir($path) ? 0755 : 0644);
        }
        $ok = @rename($path, $to);
    }
    if ($ok && is_file($to) && !is_link($to)) {
        @chmod($to, 0400);
    }
    return $ok;
}

function whyUnsafe(string $path, string $name): string {
    if (is_link($path)) {
        return 'symlink';
    }
    if (preg_match('/^\.guard[0-9a-f]{12}$/', $name)) {
        return 'leftover temporary file';
    }
    if (strcasecmp($name, '.htaccess') === 0) {
        return 'unknown .htaccess';
    }
    if (preg_match(SCRIPT_EXT, $name)) {
        return 'unknown script';
    }
    $body = (string)@file_get_contents($path, false, null, 0, 3000000);
    if ($name === '.user.ini' || $name === 'php.ini') {
        return preg_match('/^\s*(auto_prepend_file|auto_append_file|extension|zend_extension)\s*=\s*["\']?(?!none\b)[^"\'\s;]/mi', $body)
            ? 'loads extra code' : '';
    }
    if ($name === 'error_log') {
        return '';
    }
    if (stripos($body, '<?php') !== false || isMalware($body)) {
        return 'PHP code hidden in it';
    }
    return '';
}

function isMalware(string $code): bool {
    static $sigs = [
        '/(\\\\(x[0-9a-f]{2}|[0-7]{2,3})){12,}/i',                         // "\150\x74\x74..."
        '/goto\s+\w{4,10}\s*;.{0,400}goto\s+\w{4,10}\s*;/is',              // goto scrambling
        '/\beval\s*\(\s*["\'$]/i',
        '/\b(eval|assert)\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|file_get_contents|curl_exec|hex2bin)/i',
        '/\$_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[[^\]]{1,40}\]\s*\(/i',   // $_POST['x'](...)
        '/\b(system|shell_exec|passthru|proc_open|popen|pcntl_exec|create_function)\s*\(\s*\$/i',
        '/(include|require)(_once)?\s*\(?\s*["\'](https?|data|php):/i',
        '/gzinflate\s*\(\s*base64_decode/i',
    ];
    foreach ($sigs as $s) {
        if (preg_match($s, $code)) {
            return true;
        }
    }
    return false;
}

// cPanel (MultiPHP) keeps its own blocks in .htaccess. Keep a block only when
// every line inside it is harmless.
function withCpanelBlocks(string $want, string $have): string {
    $have = str_replace("\r\n", "\n", $have);
    if (!preg_match_all('/^# (?:php -- )?BEGIN cPanel-generated[^\n]*\n.*?^# (?:php -- )?END cPanel-generated[^\n]*$/ms', $have, $m)) {
        return $want;
    }
    $keep = [];
    foreach ($m[0] as $block) {
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line !== ''
                && !preg_match('/^#[^\\\\]*$/', $line)
                && !preg_match('#^</?IfModule(\s+!?[\w.]+)?>$#i', $line)
                && !preg_match('#^AddHandler application/x-httpd-(ea|alt)-php\d+(___lsphp)?(\s+\.(php\d?|phtml))+$#i', $line)
                && !preg_match('#^php_(flag|value)\s+(' . SAFE_INI . ')\s+"?[\w./:+-]*"?$#i', $line)
            ) {
                continue 2; // drop the whole block
            }
        }
        $keep[] = $block;
    }
    if (!$keep) {
        return $want;
    }
    // Our content in cPanel's own order and spacing counts as unchanged.
    $lines = function (string $s) use ($keep): array {
        $s = str_replace($keep, '', str_replace("\r\n", "\n", $s));
        return array_values(array_filter(array_map('trim', explode("\n", $s)), 'strlen'));
    };
    if ($lines($have) === $lines($want)) {
        return $have;
    }
    return rtrim($want) . "\n\n" . implode("\n\n", $keep) . "\n";
}

function alert(string $guard, string $text): void {
    $to = is_file("$guard/alert_email") ? trim((string)file_get_contents("$guard/alert_email")) : '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    // At most one email every 30 minutes.
    $stamp = "$guard/last_alert";
    if (is_file($stamp) && time() - filemtime($stamp) < 1800) {
        return;
    }
    touch($stamp);
    @mail($to, 'Website guard: files were changed', $text . "\nFull history: cs_guard/guard.log\n");
}

// Changes nothing. Writes cs_guard/scan-report.txt.
function scanAccount(string $home, string $guard): int {
    $run = function (string $cmd): string {
        return function_exists('shell_exec') ? trim((string)@shell_exec($cmd . ' 2>&1')) : '(not available)';
    };
    $r = ["Scan of $home on " . date('Y-m-d H:i:s'), ''];
    $r[] = '== CRON JOBS (remove any you did not make) ==';
    $r[] = $run('crontab -l') ?: '(none)';
    $r[] = '';
    $r[] = '== RUNNING PROCESSES (a php process that never ends can rewrite files) ==';
    $r[] = $run('ps -u "$(id -un)" -o pid,etime,args');
    $r[] = '';
    $r[] = '== SSH KEYS (remove any you did not add) ==';
    foreach (['authorized_keys', 'authorized_keys2'] as $k) {
        if (is_file("$home/.ssh/$k")) {
            foreach (file("$home/.ssh/$k") as $line) {
                $p = preg_split('/\s+/', trim($line));
                $r[] = "$k: " . $p[0] . ' ... ' . ($p[2] ?? '(no name)');
            }
        }
    }
    $r[] = '';

    $bad = $recent = $ini = $sites = [];
    $skip = ['mail', '.cpanel', '.cphorde', 'virtfs', 'logs', 'access-logs', 'etc', basename($guard)];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
            function ($f) use ($home, $skip): bool {
                $top = dirname(str_replace('\\', '/', $f->getPathname())) === $home;
                return !$f->isLink() && !($top && in_array($f->getFilename(), $skip, true));
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        $n = $f->getFilename();
        // The guard's own source contains the signatures it looks for.
        if (!$f->isFile() || (substr($p, -19) === '/security/guard.php' && hash_file('sha256', $p) === hash_file('sha256', __FILE__))) {
            continue;
        }
        $isScript = (bool)preg_match(SCRIPT_EXT, $n);
        $when = date('Y-m-d H:i', $f->getMTime());
        $body = $f->getSize() < 3000000 ? (string)@file_get_contents($p) : '';
        if (($isScript || $n[0] === '.' || stripos($body, '<?php') !== false) && isMalware($body)) {
            $bad[] = "$when  $p";
        }
        if (in_array($n, ['.htaccess', '.user.ini', 'php.ini'], true)
            && preg_match('/auto_(prepend|append)_file\s*[= ]\s*["\']?(?!none\b)[^"\'\s;]|SetHandler\s+application\/x-httpd|AddType\s+application\/x-httpd/i', $body)) {
            $ini[] = "$when  $p";
        }
        if ($isScript && time() - $f->getMTime() < 14 * 86400) {
            $recent[] = "$when  $p";
        }
        if ($n === 'index.php' || $n === 'index.html') {
            $sites[] = dirname($p);
        }
    }
    rsort($recent);
    $sites = array_values(array_unique($sites));
    sort($sites);
    $parts = [
        'MALWARE CODE FOUND IN' => $bad,
        'SETTINGS FILES THAT LOAD OR ENABLE EXTRA CODE' => $ini,
        'SCRIPTS CHANGED IN THE LAST 14 DAYS' => array_slice($recent, 0, 300),
        'FOLDERS WITH AN INDEX PAGE (an old site here can be the way in)' => array_slice($sites, 0, 100),
    ];
    foreach ($parts as $title => $list) {
        $r[] = "== $title: " . count($list) . ' ==';
        $r[] = $list ? implode("\n", $list) : '(none)';
        $r[] = '';
    }
    $text = implode("\n", $r);
    file_put_contents("$guard/scan-report.txt", $text);
    @chmod("$guard/scan-report.txt", 0600);
    echo $text;
    return $bad ? 1 : 0;
}
