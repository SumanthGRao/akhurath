<?php

declare(strict_types=1);

/** Shared helpers for opaque, invoice-scoped public payment links. */
function billing_payment_config(): array
{
    $base = defined('AKH_PUBLIC_PAYMENT_BASE_URL') ? rtrim(trim((string) AKH_PUBLIC_PAYMENT_BASE_URL), '/') : '';
    $upi = defined('AKH_STUDIO_UPI_ID') ? trim((string) AKH_STUDIO_UPI_ID) : '';
    $payee = defined('AKH_STUDIO_UPI_PAYEE_NAME') ? trim((string) AKH_STUDIO_UPI_PAYEE_NAME) : '';
    $secret = defined('AKH_PUBLIC_PAYMENT_TOKEN_SECRET') ? (string) AKH_PUBLIC_PAYMENT_TOKEN_SECRET : '';
    $parts = $base !== '' ? parse_url($base) : false;
    $validBase = $base !== '' && filter_var($base, FILTER_VALIDATE_URL) && is_array($parts)
        && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && !empty($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
    $validUpi = $upi !== '' && preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/', $upi) === 1;
    $missing = [];
    if (!$validBase) $missing[] = 'AKH_PUBLIC_PAYMENT_BASE_URL';
    if (!$validUpi) $missing[] = 'AKH_STUDIO_UPI_ID';
    if ($payee === '') $missing[] = 'AKH_STUDIO_UPI_PAYEE_NAME';
    if (strlen($secret) < 32) $missing[] = 'AKH_PUBLIC_PAYMENT_TOKEN_SECRET';
    return ['base_url' => $validBase ? $base : '', 'upi_id' => $validUpi ? $upi : '', 'payee_name' => $payee, 'secret' => strlen($secret) >= 32 ? $secret : '', 'missing' => $missing];
}

function billing_payment_key(): string
{
    $config = billing_payment_config();
    if ($config['secret'] === '' || !function_exists('openssl_encrypt')) throw new RuntimeException('Payment token encryption is not configured.');
    return hash('sha256', $config['secret'], true);
}

function billing_payment_encrypt_token(string $token): string
{
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($token, 'aes-256-gcm', billing_payment_key(), OPENSSL_RAW_DATA, $iv, $tag, 'akhurath-payment-link-v1');
    if (!is_string($cipher) || strlen($tag) !== 16) throw new RuntimeException('Could not protect payment link token.');
    return $iv . $tag . $cipher;
}

function billing_payment_decrypt_token(string $value): ?string
{
    if (strlen($value) < 29) return null;
    $token = openssl_decrypt(substr($value, 28), 'aes-256-gcm', billing_payment_key(), OPENSSL_RAW_DATA, substr($value, 0, 12), substr($value, 12, 16), 'akhurath-payment-link-v1');
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? $token : null;
}

function billing_payment_token_is_valid(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

/** Returns an existing active token or creates one. The raw token is stored only in encrypted form. */
function billing_payment_public_link(PDO $db, int $invoiceId): array
{
    $config = billing_payment_config();
    if ($config['missing'] !== []) return ['status' => 'setup_required', 'url' => '', 'suffix' => '', 'missing' => $config['missing']];
    $select = $db->prepare('SELECT token_hash, token_ciphertext, expires_at, status FROM billing_invoice_payment_links WHERE invoice_id=? AND status IN (\'active\',\'claimed\') LIMIT 1');
    $select->execute([$invoiceId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && ($row['status'] === 'claimed' || strtotime((string) $row['expires_at'] . ' UTC') > time())) {
        $token = billing_payment_decrypt_token((string) $row['token_ciphertext']);
        if ($token !== null && hash_equals((string) $row['token_hash'], hash('sha256', $token))) return ['status' => 'ready', 'url' => $config['base_url'] . '/pay.php?t=' . rawurlencode($token), 'suffix' => $token, 'missing' => []];
    }
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $encrypted = billing_payment_encrypt_token($token);
    $upsert = $db->prepare("INSERT INTO billing_invoice_payment_links (invoice_id, token_hash, token_ciphertext, expires_at, status) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 DAY), 'active') ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), token_ciphertext=VALUES(token_ciphertext), expires_at=VALUES(expires_at), status='active'");
    $upsert->execute([$invoiceId, $hash, $encrypted]);
    return ['status' => 'ready', 'url' => $config['base_url'] . '/pay.php?t=' . rawurlencode($token), 'suffix' => $token, 'missing' => []];
}

function billing_payment_lookup(PDO $db, string $token): ?array
{
    if (!billing_payment_token_is_valid($token)) return null;
    $query = $db->prepare('SELECT * FROM billing_invoice_payment_links WHERE token_hash=? LIMIT 1');
    $query->execute([hash('sha256', $token)]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) return null;
    return $row;
}

function billing_payment_upi_uri(string $upi, string $payee, int $totalPaise, string $invoiceNumber, string $note): string
{
    if ($totalPaise < 1 || $invoiceNumber === '') throw new InvalidArgumentException('Invoice payment details are invalid.');
    $params = ['pa' => $upi, 'pn' => $payee, 'am' => number_format($totalPaise / 100, 2, '.', ''), 'cu' => 'INR', 'tr' => $invoiceNumber, 'tn' => $note];
    return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** Authenticated invoice/payment event dispatch; returns false when not configured or n8n does not accept it. */
function billing_payment_dispatch_event(array $payload): bool
{
    $url = defined('AKH_N8N_INVOICE_CREATED_WEBHOOK_URL') ? trim((string) AKH_N8N_INVOICE_CREATED_WEBHOOK_URL) : '';
    $secret = defined('AKH_N8N_INVOICE_WEBHOOK_SECRET') ? trim((string) AKH_N8N_INVOICE_WEBHOOK_SECRET) : '';
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || $secret === '' || !function_exists('curl_init')) return false;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) return false;
    $ch = curl_init($url);
    if ($ch === false) return false;
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Secret: ' . $secret], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8]);
    curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($status < 200 || $status >= 300) { error_log('Billing payment webhook failed: HTTP ' . $status . ($err !== '' ? ' (' . $err . ')' : '')); return false; }
    return true;
}

/** Notify the dedicated n8n delivery workflow after accountant-confirmed clearance. */
function billing_delivery_dispatch_event(array $payload): bool
{
    $defaultUrl = 'https://n8n.akhurathstudio.com/webhook/c17bf7be-c286-46e6-b270-9914d11e67e4';
    $url = defined('AKH_N8N_DELIVERY_WEBHOOK_URL') ? trim((string) AKH_N8N_DELIVERY_WEBHOOK_URL) : $defaultUrl;
    $secret = defined('AKH_N8N_DELIVERY_WEBHOOK_SECRET')
        ? trim((string) AKH_N8N_DELIVERY_WEBHOOK_SECRET)
        : trim((string) (getenv('AKH_N8N_DELIVERY_WEBHOOK_SECRET') ?: ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || $secret === '' || !function_exists('curl_init')) {
        error_log('Billing delivery webhook skipped: configure its HTTPS URL, shared secret, and PHP cURL.');
        return false;
    }
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) {
        error_log('Billing delivery webhook failed: could not encode event payload.');
        return false;
    }
    try {
        $ch = curl_init($url);
        if ($ch === false) return false;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            error_log('Billing delivery webhook failed: HTTP ' . $status . ($curlError !== '' ? ' (' . $curlError . ')' : ''));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Billing delivery webhook exception: ' . $e->getMessage());
        return false;
    }
}
