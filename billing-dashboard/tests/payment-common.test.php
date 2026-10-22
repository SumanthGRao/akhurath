<?php

declare(strict_types=1);

define('AKH_PUBLIC_PAYMENT_BASE_URL', 'https://payments.example.test/billing-dashboard');
define('AKH_STUDIO_UPI_ID', 'akhurath-test@upi');
define('AKH_STUDIO_UPI_PAYEE_NAME', 'Akhurath Studio Test');
define('AKH_PUBLIC_PAYMENT_TOKEN_SECRET', str_repeat('test-secret-', 4));
require_once dirname(__DIR__) . '/payment-common.php';

function check(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
}

$uri = billing_payment_upi_uri('akhurath-test@upi', 'Akhurath Studio Test', 200000, 'INV-2026-0020', 'Akhurath invoice INV-2026-0020');
parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);
check(str_starts_with($uri, 'upi://pay?'), 'UPI payment scheme');
check(($params['pa'] ?? '') === 'akhurath-test@upi', 'UPI payee address');
check(($params['pn'] ?? '') === 'Akhurath Studio Test', 'UPI payee name is URI encoded correctly');
check(($params['am'] ?? '') === '2000.00' && ($params['cu'] ?? '') === 'INR', 'Invoice amount and currency are fixed from server data');
check(($params['tr'] ?? '') === 'INV-2026-0020', 'Invoice number is used as payment reference');

$token = bin2hex(random_bytes(32));
$encrypted = billing_payment_encrypt_token($token);
check(billing_payment_decrypt_token($encrypted) === $token, 'Opaque payment token encrypt/decrypt round trip');
$encrypted[strlen($encrypted) - 1] = chr(ord($encrypted[strlen($encrypted) - 1]) ^ 1);
check(billing_payment_decrypt_token($encrypted) === null, 'Modified encrypted token is rejected');
check(billing_payment_token_is_valid($token) && !billing_payment_token_is_valid('1'), 'Opaque token format rejects malformed references');
check(billing_payment_config()['base_url'] === 'https://payments.example.test/billing-dashboard', 'Only configured HTTPS payment base URLs are accepted');
