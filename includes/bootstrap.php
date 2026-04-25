<?php

declare(strict_types=1);

$akhConfig = __DIR__ . '/config.php';
if (!is_file($akhConfig)) {
    $msg = 'Missing includes/config.php. Copy includes/config.example.php to includes/config.php and adjust for this server (not committed to git).';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;

    exit;
}
require_once $akhConfig;

/** When config/database.local.php exists, load MySQL (Hostinger, XAMPP with DB, etc.). Otherwise file-based JSON/tasks only. */
$dbLocal = AKH_ROOT . '/config/database.local.php';
if (is_file($dbLocal)) {
    require_once $dbLocal;
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/app-kv.php';
}

@date_default_timezone_set(AKH_SITE_TIMEZONE);

$life = max(60, (int) AKH_SESSION_LIFETIME_SECONDS);
@ini_set('session.gc_maxlifetime', (string) $life);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$cookiePath = akh_session_cookie_path();

session_set_cookie_params([
    'lifetime' => $life,
    'path' => $cookiePath,
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$name = session_name();
if ($name !== '' && session_status() === PHP_SESSION_ACTIVE) {
    setcookie($name, (string) session_id(), [
        'expires' => time() + $life,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
