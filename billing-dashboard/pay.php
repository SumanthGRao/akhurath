<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/invoices.php';
require_once __DIR__ . '/payment-common.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
function billing_pay_h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function billing_pay_error(int $status, string $title, string $message): never { http_response_code($status); echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . billing_pay_h($title) . ' · Akhurath Studio</title><main style="font:16px/1.6 system-ui;max-width:600px;margin:12vh auto;padding:24px;color:#27312c"><p style="letter-spacing:.15em;color:#547561;font-weight:700">AKHURATH STUDIO</p><h1>' . billing_pay_h($title) . '</h1><p>' . billing_pay_h($message) . '</p></main>'; exit; }

$config = billing_payment_config();
if ($config['base_url'] === '') billing_pay_error(503, 'Payment page setup required', 'The secure public payment URL has not been configured yet. Please contact Akhurath Studio for payment instructions.');
$forwardedHttps = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$requestHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedHttps;
$canonicalHost = strtolower((string) parse_url($config['base_url'], PHP_URL_HOST));
if (!$requestHttps || strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== $canonicalHost) {
    $tokenForRedirect = (string) ($_GET['t'] ?? '');
    $target = $config['base_url'] . '/pay.php' . ($tokenForRedirect !== '' ? '?t=' . rawurlencode($tokenForRedirect) : '');
    header('Location: ' . $target, true, 302); exit;
}
$token = (string) ($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) billing_pay_error(404, 'Payment link unavailable', 'This invoice payment link is invalid. Ask Akhurath Studio for a current link.');
$db = function_exists('akh_db') ? akh_db() : null;
if (!$db instanceof PDO) billing_pay_error(503, 'Temporarily unavailable', 'Invoice details could not be loaded. Please try again later or contact Akhurath Studio.');
try { $link = billing_payment_lookup($db, $token); }
catch (Throwable $e) { error_log('Public invoice payment link lookup failed: ' . $e->getMessage()); billing_pay_error(503, 'Temporarily unavailable', 'Invoice payment service is not ready. Please contact Akhurath Studio.'); }
if (!$link) billing_pay_error(404, 'Payment link unavailable', 'This invoice payment link is invalid. Ask Akhurath Studio for a current link.');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expires = new DateTimeImmutable((string) $link['expires_at'], new DateTimeZone('UTC'));
if ($link['status'] === 'revoked' || ($expires <= $now && $link['status'] !== 'claimed')) billing_pay_error(410, 'Payment link expired', 'This invoice payment link has expired. Contact Akhurath Studio for an updated link.');
try { $invoice = akh_invoice_get((int) $link['invoice_id']); }
catch (Throwable $e) { error_log('Public invoice load failed: ' . $e->getMessage()); billing_pay_error(503, 'Temporarily unavailable', 'Invoice details could not be loaded. Please contact Akhurath Studio.'); }
if (!$invoice || (string) ($invoice['status'] ?? '') !== 'sent' || (string) ($invoice['currency'] ?? '') !== 'INR' || (int) ($invoice['total_paise'] ?? 0) < 1) billing_pay_error(410, 'Payment link unavailable', 'This invoice is no longer payable. Please contact Akhurath Studio.');
$q = $db->prepare("SELECT customer_name,project_name,payment_status FROM final_delivery_billing WHERE source_invoice_id=? AND invoice_status='sent' ORDER BY id");
$q->execute([(int) $link['invoice_id']]); $billingRows = $q->fetchAll(PDO::FETCH_ASSOC);
if ($billingRows === []) billing_pay_error(410, 'Payment link unavailable', 'This invoice is no longer payable. Please contact Akhurath Studio.');
$statuses = array_unique(array_column($billingRows, 'payment_status'));
if (in_array('cleared', $statuses, true) || $link['status'] === 'cleared') billing_pay_error(410, 'Payment already confirmed', 'This invoice has already been marked as paid. Contact Akhurath Studio if you believe this is an error.');
$claimed = $link['status'] === 'claimed' || in_array('payment_claimed', $statuses, true);
$upiConfigured = $config['upi_id'] !== '' && $config['payee_name'] !== '' && $config['secret'] !== '';
$upiUri = '';
$qrData = '';
if ($upiConfigured) {
    try {
        $upiUri = billing_payment_upi_uri($config['upi_id'], $config['payee_name'], (int) ($invoice['total_paise'] ?? 0), (string) $invoice['invoice_number'], 'Akhurath Studio invoice ' . (string) $invoice['invoice_number']);
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoload)) { require_once $autoload; if (class_exists('chillerlan\\QRCode\\QRCode')) $qrData = (new \chillerlan\QRCode\QRCode())->render($upiUri); }
    } catch (Throwable $e) { error_log('Local UPI QR generation failed: ' . $e->getMessage()); }
}
$amount = akh_invoice_money_format_paise((int) ($invoice['total_paise'] ?? 0), (string) ($invoice['currency'] ?? 'INR'));
$clientName = (string) ($billingRows[0]['customer_name'] ?? '');
$projectNames = array_values(array_unique(array_map(static fn($row) => trim((string) $row['project_name']), $billingRows)));
$due = (string) ($invoice['due_at'] ?? '');
$claimCsrf = '';
if (!$claimed && $upiConfigured) {
    if (!isset($_SESSION['billing_payment_claim_csrf']) || !is_array($_SESSION['billing_payment_claim_csrf'])) $_SESSION['billing_payment_claim_csrf'] = [];
    $claimCsrf = bin2hex(random_bytes(32));
    $_SESSION['billing_payment_claim_csrf'][hash('sha256', $token)] = ['value' => $claimCsrf, 'expires_at' => time() + 1800];
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Pay invoice <?php echo billing_pay_h((string) $invoice['invoice_number']); ?> · Akhurath Studio</title><style>
:root{color-scheme:light;--ink:#26312b;--muted:#718078;--green:#365b4b;--line:#e4e9e3;--paper:#f5f6f2}*{box-sizing:border-box}body{margin:0;padding:24px;background:var(--paper);color:var(--ink);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.card{max-width:760px;margin:4vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:clamp(22px,5vw,48px);box-shadow:0 15px 50px #24382b0c}.brand{color:#557866;font-size:12px;font-weight:800;letter-spacing:.17em;text-transform:uppercase}.card h1{margin:8px 0 0;font-size:clamp(26px,5vw,38px);letter-spacing:-.04em}.sub{color:var(--muted);margin:8px 0 25px}.facts{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fact{padding:15px;border-radius:10px;background:#f7f8f5}.fact span{display:block;color:var(--muted);font-size:12px}.fact strong{display:block;margin-top:4px;overflow-wrap:anywhere}.amount{font-size:27px;color:var(--green)}.pay{margin-top:24px;padding:22px;border:1px solid var(--line);border-radius:13px}.pay h2{margin:0 0 5px;font-size:19px}.qr-wrap{text-align:center;margin:16px 0}.qr-wrap img{width:min(250px,70vw);height:auto;image-rendering:pixelated}.upi{display:block;max-width:100%;overflow-wrap:anywhere;color:var(--green);font-weight:700}.button{display:inline-block;border:0;border-radius:9px;background:var(--green);color:#fff;text-decoration:none;padding:13px 18px;font:700 15px system-ui;cursor:pointer}.button.secondary{background:#eaf0eb;color:var(--green)}label{display:block;margin:18px 0 10px;font-size:13px;font-weight:700}input{width:100%;padding:12px;border:1px solid #d7dfd8;border-radius:8px;font:inherit}.instructions{padding-left:20px;color:#52645a}.notice{margin-top:20px;padding:14px;border-radius:10px;background:#f5f1e6;color:#675c3f;font-size:14px}.success{background:#eaf4eb;color:#356346}.small{font-size:12px;color:var(--muted)}@media(max-width:520px){body{padding:12px}.facts{grid-template-columns:1fr}.pay{padding:17px}.button{width:100%;text-align:center}}
</style></head><body><main class="card"><div class="brand">Akhurath Studio</div><h1>Invoice payment</h1><p class="sub">Review the invoice details before paying.</p><section class="facts"><div class="fact"><span>Invoice number</span><strong><?php echo billing_pay_h((string) $invoice['invoice_number']); ?></strong></div><div class="fact"><span>Customer</span><strong><?php echo billing_pay_h($clientName); ?></strong></div><div class="fact"><span>Project<?php echo count($projectNames) === 1 ? '' : 's'; ?></span><strong><?php echo billing_pay_h(implode(', ', $projectNames)); ?></strong></div><div class="fact"><span>Amount due</span><strong class="amount"><?php echo billing_pay_h($amount); ?></strong></div><div class="fact"><span>Due date</span><strong><?php echo billing_pay_h($due !== '' ? date('d M Y', strtotime($due)) : 'As agreed'); ?></strong></div></section>
<?php if ($claimed): ?><div class="notice success"><strong>Payment claim received.</strong><br>The accountant will verify the bank credit. This claim does not confirm payment or release final video files.</div>
<?php elseif (!$upiConfigured): ?><div class="notice"><strong>Payment setup is being completed.</strong><br>Please contact Akhurath Studio for verified payment instructions. Do not pay to an unverified UPI ID.</div>
<?php else: ?><section class="pay"><h2>Pay using UPI</h2><p class="small">Payee name: <strong><?php echo billing_pay_h($config['payee_name']); ?></strong></p><p>UPI ID: <span class="upi"><?php echo billing_pay_h($config['upi_id']); ?></span></p><?php if ($qrData !== ''): ?><div class="qr-wrap"><img src="<?php echo billing_pay_h($qrData); ?>" alt="UPI payment QR code for invoice <?php echo billing_pay_h((string) $invoice['invoice_number']); ?>"></div><?php else: ?><p class="small">Scan the UPI QR from another device when available, or use the UPI app button below.</p><?php endif; ?><p class="small">Before approving, verify the payee name and amount shown in your UPI app.</p><a class="button" href="<?php echo billing_pay_h($upiUri); ?>">Open UPI app</a><ol class="instructions"><li>Open a UPI app or scan this QR code using another device.</li><li>Check the payee is <strong><?php echo billing_pay_h($config['payee_name']); ?></strong> and the amount is <strong><?php echo billing_pay_h($amount); ?></strong>.</li><li>Complete the payment in your UPI app, then return here and submit your claim.</li></ol><form method="post" action="payment-claim.php"><input type="hidden" name="t" value="<?php echo billing_pay_h($token); ?>"><input type="hidden" name="csrf" value="<?php echo billing_pay_h($claimCsrf); ?>"><label for="utr">UPI UTR / transaction reference (optional)</label><input id="utr" name="utr" maxlength="80" autocomplete="off" pattern="[A-Za-z0-9-]{6,80}" placeholder="Enter the UTR from your payment app"><p class="small">This is unverified information and does not itself confirm payment.</p><button class="button secondary" type="submit">I've Paid</button></form></section><div class="notice">A payment claim is not automatically verified. Final delivery begins only after the Akhurath Studio accountant confirms the incoming payment in the bank account.</div><?php endif; ?><p class="small" style="margin-top:22px">Questions? Contact Akhurath Studio and mention invoice <?php echo billing_pay_h((string) $invoice['invoice_number']); ?>.</p></main></body></html>
