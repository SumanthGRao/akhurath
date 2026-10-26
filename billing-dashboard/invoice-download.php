<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/invoices.php';
require_once dirname(__DIR__) . '/includes/invoice-pdf.php';
require_once __DIR__ . '/payment-common.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function billing_invoice_download_error(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status === 503 ? 'Invoice is temporarily unavailable.' : 'Invoice link is unavailable or expired.';
    exit;
}

$config = billing_payment_config();
if ($config['base_url'] === '') billing_invoice_download_error(503);

$forwardedHttps = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$requestHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedHttps;
$canonicalHost = strtolower((string) parse_url($config['base_url'], PHP_URL_HOST));
if (!$requestHttps || strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== $canonicalHost) {
    $tokenForRedirect = (string) ($_GET['t'] ?? '');
    if (!billing_payment_token_is_valid($tokenForRedirect)) billing_invoice_download_error(404);
    header('Location: ' . $config['base_url'] . '/invoice-download.php?t=' . rawurlencode($tokenForRedirect), true, 302);
    exit;
}

$token = (string) ($_GET['t'] ?? '');
if (!billing_payment_token_is_valid($token)) billing_invoice_download_error(404);
$db = function_exists('akh_db') ? akh_db() : null;
if (!$db instanceof PDO) billing_invoice_download_error(503);

try {
    $link = billing_payment_lookup($db, $token);
    if (!$link || $link['status'] === 'revoked') billing_invoice_download_error(404);
    $expires = new DateTimeImmutable((string) $link['expires_at'], new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($expires <= $now && $link['status'] !== 'claimed' && $link['status'] !== 'cleared') billing_invoice_download_error(410);

    $invoiceId = (int) $link['invoice_id'];
    $invoice = akh_invoice_get($invoiceId);
    if (!$invoice || (string) ($invoice['status'] ?? '') !== 'sent') billing_invoice_download_error(404);
    $billingRows = $db->prepare("SELECT COUNT(*) FROM final_delivery_billing WHERE source_invoice_id=? AND invoice_status='sent'");
    $billingRows->execute([$invoiceId]);
    if ((int) $billingRows->fetchColumn() < 1) billing_invoice_download_error(404);

    $bytes = akh_invoice_pdf_bytes($invoice);
    $filename = akh_invoice_pdf_filename($invoice);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
    header('Content-Length: ' . (string) strlen($bytes));
    echo $bytes;
} catch (Throwable $e) {
    error_log('Public invoice PDF generation failed: ' . $e->getMessage());
    billing_invoice_download_error(503);
}
