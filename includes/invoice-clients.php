<?php

declare(strict_types=1);

require_once __DIR__ . '/invoice-text.php';

/**
 * Invoice billing clients — maintained in the admin invoice console (not portal users / users table).
 */
function akh_invoice_clients_storage_path(): string
{
    return AKH_ROOT . '/data/invoice-clients.json';
}

/**
 * @return array<string, array{display_name: string, email: string, phone: string, address: string, notes: string}>
 */
function akh_invoice_clients_load(): array
{
    $path = akh_invoice_clients_storage_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $slug => $row) {
        if (!is_string($slug) || !is_array($row)) {
            continue;
        }
        $norm = akh_invoice_client_normalize_slug($slug);
        if ($norm === '') {
            continue;
        }
        $out[$norm] = akh_invoice_client_normalize_row($row);
    }

    return $out;
}

/**
 * @param array<string, array<string, mixed>> $clients
 */
function akh_invoice_clients_save(array $clients): bool
{
    $path = akh_invoice_clients_storage_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $clean = [];
    foreach ($clients as $slug => $row) {
        if (!is_string($slug) || !is_array($row)) {
            continue;
        }
        $norm = akh_invoice_client_normalize_slug($slug);
        if ($norm === '') {
            continue;
        }
        $clean[$norm] = akh_invoice_client_normalize_row($row);
    }
    ksort($clean, SORT_STRING);
    try {
        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        return false;
    }

    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function akh_invoice_client_normalize_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');

    return strlen($slug) >= 2 && strlen($slug) <= 64 ? $slug : '';
}

/**
 * @param array<string, mixed> $row
 * @return array{display_name: string, email: string, phone: string, address: string, notes: string}
 */
function akh_invoice_client_normalize_row(array $row): array
{
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }

    return [
        'display_name' => akh_invoice_text_substr(trim((string) ($row['display_name'] ?? '')), 0, 255),
        'email' => akh_invoice_text_substr($email, 0, 255),
        'phone' => akh_invoice_text_substr(trim((string) ($row['phone'] ?? '')), 0, 40),
        'address' => akh_invoice_text_substr(trim((string) ($row['address'] ?? '')), 0, 2000),
        'notes' => akh_invoice_text_substr(trim((string) ($row['notes'] ?? '')), 0, 2000),
    ];
}

function akh_invoice_client_slug_from_name(string $name): string
{
    $base = akh_invoice_client_normalize_slug(str_replace(' ', '_', $name));
    if ($base === '') {
        $base = 'client';
    }
    $clients = akh_invoice_clients_load();
    if (!isset($clients[$base])) {
        return $base;
    }
    for ($i = 2; $i < 100; $i++) {
        $try = $base . '_' . $i;
        if (!isset($clients[$try])) {
            return $try;
        }
    }

    return $base . '_' . bin2hex(random_bytes(2));
}

/**
 * @return array{display_name: string, email: string, phone: string, address: string, notes: string}|null
 */
function akh_invoice_client_get(string $slug): ?array
{
    $slug = akh_invoice_client_normalize_slug($slug);
    if ($slug === '') {
        return null;
    }
    $all = akh_invoice_clients_load();

    return $all[$slug] ?? null;
}

/** Normalize Indian phone numbers so +91 and local 10-digit forms match. */
function akh_invoice_client_phone_identity(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    return $digits;
}

/**
 * Find an existing invoice client by stable contact details.
 * Name-only matching is used only when neither email nor phone is available.
 *
 * @return array{slug: string, display_name: string, email: string, phone: string, address: string, notes: string}|null
 */
function akh_invoice_client_find_for_billing(string $displayName, string $email, string $phone): ?array
{
    $email = strtolower(trim($email));
    $phoneIdentity = akh_invoice_client_phone_identity($phone);
    $nameIdentity = function_exists('mb_strtolower') ? mb_strtolower(trim($displayName), 'UTF-8') : strtolower(trim($displayName));
    $clients = akh_invoice_clients_list();

    // Email is the strongest identifier; prefer it when it is supplied.
    if ($email !== '') {
        foreach ($clients as $client) {
            if (strtolower(trim($client['email'])) === $email) return $client;
        }
    }
    if ($phoneIdentity !== '') {
        foreach ($clients as $client) {
            if (akh_invoice_client_phone_identity($client['phone']) === $phoneIdentity) return $client;
        }
    }
    if ($email === '' && $phoneIdentity === '' && $nameIdentity !== '') {
        foreach ($clients as $client) {
            $clientName = function_exists('mb_strtolower') ? mb_strtolower(trim($client['display_name']), 'UTF-8') : strtolower(trim($client['display_name']));
            if ($clientName === $nameIdentity) return $client;
        }
    }
    return null;
}

/**
 * @return list<array{slug: string, display_name: string, email: string, phone: string, address: string, notes: string}>
 */
function akh_invoice_clients_list(): array
{
    $out = [];
    foreach (akh_invoice_clients_load() as $slug => $row) {
        $out[] = [
            'slug' => $slug,
            'display_name' => (string) ($row['display_name'] ?? $slug),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
    });

    return $out;
}

function akh_invoice_client_add(string $displayName, string $email, string $phone, string $address, string $notes, ?string $slug = null): ?string
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return null;
    }
    $slug = $slug !== null && trim($slug) !== ''
        ? akh_invoice_client_normalize_slug($slug)
        : akh_invoice_client_slug_from_name($displayName);
    if ($slug === '') {
        return null;
    }
    $clients = akh_invoice_clients_load();
    if (isset($clients[$slug])) {
        return null;
    }
    $clients[$slug] = akh_invoice_client_normalize_row([
        'display_name' => $displayName,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'notes' => $notes,
    ]);

    return akh_invoice_clients_save($clients) ? $slug : null;
}

function akh_invoice_client_update(string $slug, string $displayName, string $email, string $phone, string $address, string $notes): bool
{
    $slug = akh_invoice_client_normalize_slug($slug);
    if ($slug === '') {
        return false;
    }
    $clients = akh_invoice_clients_load();
    if (!isset($clients[$slug])) {
        return false;
    }
    $clients[$slug] = akh_invoice_client_normalize_row([
        'display_name' => $displayName,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'notes' => $notes,
    ]);

    return akh_invoice_clients_save($clients);
}

function akh_invoice_client_delete(string $slug): bool
{
    $slug = akh_invoice_client_normalize_slug($slug);
    if ($slug === '') {
        return false;
    }
    $clients = akh_invoice_clients_load();
    if (!isset($clients[$slug])) {
        return false;
    }
    unset($clients[$slug]);

    return akh_invoice_clients_save($clients);
}

function akh_invoice_client_display_label(string $slug): string
{
    $row = akh_invoice_client_get($slug);
    if ($row === null) {
        return $slug;
    }
    $name = trim((string) ($row['display_name'] ?? ''));
    if ($name === '') {
        return $slug;
    }

    return $name;
}
