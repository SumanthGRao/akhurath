<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';
$html = file_get_contents(__DIR__ . '/public/index.html');
if (!is_string($html)) { http_response_code(500); exit('Billing dashboard files are missing.'); }
$html = str_replace('href="/styles.css"', 'href="public/styles.css"', $html);
$html = str_replace('src="/app.js"', 'src="public/app.js"', $html);
$bootstrap = '<script>window.BILLING_API_BASE="api.php?action=";window.BILLING_CSRF=' . json_encode(akh_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script><script src="public/app.js?v=20261016-4" defer></script>';
$html = str_replace('<script src="public/app.js" defer></script>', $bootstrap, $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
echo $html;
