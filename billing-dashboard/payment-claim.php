<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/invoices.php';
require_once __DIR__ . '/payment-common.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function billing_claim_error(int $status, string $message): never
{
    http_response_code($status); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment claim · Akhurath Studio</title><main style="font:16px/1.6 system-ui;max-width:600px;margin:12vh auto;padding:24px;color:#27312c"><p style="letter-spacing:.15em;color:#547561;font-weight:700">AKHURATH STUDIO</p><h1>Payment claim</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p>You can return to the invoice page and contact Akhurath Studio if you need help.</p></main>'; exit;
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { header('Allow: POST'); billing_claim_error(405, 'This request method is not supported.'); }
$token = (string) ($_POST['t'] ?? ''); $utr = trim((string) ($_POST['utr'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) billing_claim_error(404, 'This payment link is invalid. Ask Akhurath Studio for a current invoice link.');
if ($utr !== '' && preg_match('/^[A-Za-z0-9-]{6,80}$/', $utr) !== 1) billing_claim_error(400, 'Enter a valid transaction reference, or leave it blank and contact the accountant.');
$config = billing_payment_config();
if ($config['base_url'] === '' || $config['secret'] === '') billing_claim_error(503, 'Payment claims are not configured yet. Please contact Akhurath Studio.');
$expectedOrigin = strtolower((string) parse_url($config['base_url'], PHP_URL_SCHEME) . '://' . parse_url($config['base_url'], PHP_URL_HOST));
$requestOrigin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
if ($requestOrigin === '' && isset($_SERVER['HTTP_REFERER'])) { $requestOrigin = strtolower((string) parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_SCHEME) . '://' . parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST)); }
if ($requestOrigin !== '' && !hash_equals($expectedOrigin, $requestOrigin)) billing_claim_error(403, 'This payment claim came from an untrusted website. Return to the invoice page and try again.');
$db = function_exists('akh_db') ? akh_db() : null;
if (!$db instanceof PDO) billing_claim_error(503, 'The payment service is temporarily unavailable. Please contact Akhurath Studio.');
try {
    // Per-client/IP-and-token database rate limit. The token component prevents one customer's traffic
    // from affecting other invoices when the site sits behind a shared reverse proxy.
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKey = hash_hmac('sha256', $remote . '|' . hash('sha256', $token), hash('sha256', $config['secret'], true));
    $db->beginTransaction();
    $seed = $db->prepare('INSERT IGNORE INTO billing_payment_claim_rate_limits (ip_hash,window_started_at,request_count) VALUES (?,UTC_TIMESTAMP(),0)'); $seed->execute([$rateKey]);
    $limit = $db->prepare('SELECT window_started_at,request_count FROM billing_payment_claim_rate_limits WHERE ip_hash=? FOR UPDATE');
    $limit->execute([$rateKey]); $rate = $limit->fetch(PDO::FETCH_ASSOC);
    if (!$rate) {
        $add = $db->prepare('INSERT INTO billing_payment_claim_rate_limits (ip_hash,window_started_at,request_count) VALUES (?,UTC_TIMESTAMP(),1)'); $add->execute([$rateKey]);
    } else {
        $start = strtotime((string) $rate['window_started_at'] . ' UTC');
        if ($start < time() - 3600) { $reset = $db->prepare('UPDATE billing_payment_claim_rate_limits SET window_started_at=UTC_TIMESTAMP(),request_count=1 WHERE ip_hash=?'); $reset->execute([$rateKey]); }
        elseif ((int) $rate['request_count'] >= 10) { $db->rollBack(); billing_claim_error(429, 'Too many claim attempts. Please wait or contact Akhurath Studio.'); }
        else { $inc = $db->prepare('UPDATE billing_payment_claim_rate_limits SET request_count=request_count+1 WHERE ip_hash=?'); $inc->execute([$rateKey]); }
    }
    $db->commit();
    $db->beginTransaction();
    $link = billing_payment_lookup($db, $token);
    if (!$link) { $db->rollBack(); billing_claim_error(404, 'This payment link is invalid. Ask Akhurath Studio for a current invoice link.'); }
    $lock = $db->prepare('SELECT * FROM billing_invoice_payment_links WHERE invoice_id=? FOR UPDATE'); $lock->execute([(int) $link['invoice_id']]); $lockedLink = $lock->fetch(PDO::FETCH_ASSOC);
    if (!is_array($lockedLink) || $lockedLink['status'] === 'revoked') { $db->rollBack(); billing_claim_error(410, 'This payment link has been cancelled. Contact Akhurath Studio.'); }
    if ($lockedLink['status'] === 'cleared') { $db->rollBack(); billing_claim_error(410, 'This invoice has already been confirmed as paid.'); }
    if ($lockedLink['status'] === 'active' && strtotime((string) $lockedLink['expires_at'] . ' UTC') <= time()) { $db->rollBack(); billing_claim_error(410, 'This payment link has expired. Contact Akhurath Studio for an updated link.'); }
    $invoiceId = (int) $lockedLink['invoice_id'];
    $invoice = akh_invoice_get($invoiceId);
    if (!$invoice || (string) ($invoice['status'] ?? '') !== 'sent') { $db->rollBack(); billing_claim_error(410, 'This invoice is no longer payable. Contact Akhurath Studio.'); }
    $q = $db->prepare("SELECT id,payment_status,phone,customer_name,task_code FROM final_delivery_billing WHERE source_invoice_id=? AND invoice_status='sent' FOR UPDATE"); $q->execute([$invoiceId]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) { $db->rollBack(); billing_claim_error(410, 'This invoice is no longer payable. Contact Akhurath Studio.'); }
    $states = array_unique(array_column($rows, 'payment_status'));
    if (in_array('cleared', $states, true)) { $db->rollBack(); billing_claim_error(410, 'This invoice has already been confirmed as paid.'); }
    $alreadyClaimed = $lockedLink['status'] === 'claimed' || in_array('payment_claimed', $states, true);
    if (!$alreadyClaimed) {
        foreach ($rows as $row) if ($row['payment_status'] !== 'unpaid') { $db->rollBack(); billing_claim_error(409, 'This invoice is not currently accepting payment claims. Contact Akhurath Studio.'); }
        $updateLink = $db->prepare("UPDATE billing_invoice_payment_links SET status='claimed',payment_claimed_at=UTC_TIMESTAMP(3),client_utr=NULLIF(?,''),claim_verification_status='unverified' WHERE invoice_id=? AND status='active'");
        $updateLink->execute([$utr, $invoiceId]);
        $ids = array_map('intval', array_column($rows, 'id')); $marks = implode(',', array_fill(0, count($ids), '?'));
        $updateBilling = $db->prepare("UPDATE final_delivery_billing SET payment_status='payment_claimed' WHERE id IN ($marks) AND payment_status='unpaid' AND invoice_status='sent'"); $updateBilling->execute($ids);
        if ($updateBilling->rowCount() !== count($ids)) { $db->rollBack(); billing_claim_error(409, 'Invoice status changed while recording your claim. Please reload the invoice page.'); }
        $actor = 'client:' . substr(hash('sha256', $token), 0, 16);
        foreach ($ids as $billingId) { $audit = $db->prepare('INSERT INTO billing_audit_log (billing_id,actor,action,details) VALUES (?,?,?,?)'); $audit->execute([$billingId, $actor, 'payment_claimed', json_encode(['invoice_id' => $invoiceId, 'client_utr_unverified' => $utr !== '' ? $utr : null], JSON_UNESCAPED_SLASHES)]); }
    }
    $db->commit();
    if (!$alreadyClaimed) {
        $representative = $rows[0];
        $event = ['event' => 'billing.invoice.payment_claimed', 'event_id' => 'payment-claimed-' . $invoiceId, 'billing_id' => (int) $representative['id'], 'invoice_id' => $invoiceId, 'invoice_number' => (string) $invoice['invoice_number'], 'customer_name' => (string) ($invoice['client_display_name'] ?: $representative['customer_name']), 'phone' => (string) $representative['phone'], 'task_code' => (string) $representative['task_code'], 'invoice_status' => 'sent', 'payment_status' => 'payment_claimed', 'client_utr_unverified' => $utr !== '' ? $utr : null, 'claimed_at' => gmdate('c'), 'verification_required' => true];
        try { if (!billing_payment_dispatch_event($event)) error_log('Payment claim saved, but n8n accountant notification was not accepted for invoice ' . $invoiceId); }
        catch (Throwable $e) { error_log('Payment claim saved, but n8n accountant notification failed for invoice ' . $invoiceId . ': ' . $e->getMessage()); }
    }
    header('Location: ' . $config['base_url'] . '/pay.php?t=' . rawurlencode($token), true, 303); exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Public invoice payment claim failed: ' . $e->getMessage());
    billing_claim_error(503, 'We could not record this claim right now. No payment status was cleared. Please try again or contact Akhurath Studio.');
}
