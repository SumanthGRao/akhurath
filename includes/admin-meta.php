<?php

declare(strict_types=1);

const AKH_ADMIN_META_KV_KEY = 'admin_meta';

function akh_admin_meta_path(): string
{
    return AKH_ROOT . '/data/admin-meta.php';
}

function akh_admin_meta_require_kv(): void
{
    if (function_exists('akh_db') && !function_exists('akh_kv_get')) {
        require_once __DIR__ . '/app-kv.php';
    }
}

/**
 * Admin console notification / verification profile (MySQL app_kv when DB is on, else data/admin-meta.php).
 *
 * @return array{email: string, email_verified: bool, verify_token: ?string, verify_expires_at: ?int}
 */
function akh_admin_meta(): array
{
    $default = [
        'email' => '',
        'email_verified' => false,
        'verify_token' => null,
        'verify_expires_at' => null,
    ];

    if (function_exists('akh_kv_get')) {
        akh_admin_meta_require_kv();
        $raw = akh_kv_get(AKH_ADMIN_META_KV_KEY);
        if ($raw === null || $raw === '') {
            return $default;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $default;
        }
        if (!is_array($data)) {
            return $default;
        }

        return array_merge($default, array_intersect_key($data, $default));
    }

    $path = akh_admin_meta_path();
    if (!is_file($path)) {
        return $default;
    }
    $data = require $path;
    if (!is_array($data)) {
        return $default;
    }

    return array_merge($default, array_intersect_key($data, $default));
}

/**
 * @param array{email?: string, email_verified?: bool, verify_token?: ?string, verify_expires_at?: ?int} $patch
 */
function akh_admin_meta_save(array $patch): bool
{
    $cur = akh_admin_meta();
    $next = array_merge($cur, $patch);

    if (function_exists('akh_kv_set')) {
        akh_admin_meta_require_kv();
        try {
            akh_kv_set(AKH_ADMIN_META_KV_KEY, json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if (function_exists('akh_db') && isset($patch['email'])) {
                $em = strtolower(trim((string) $patch['email']));
                if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && mb_strlen($em) <= 120) {
                    $who = function_exists('akh_admin_current') ? akh_admin_current() : null;
                    if (is_string($who) && $who !== '') {
                        try {
                            akh_db()->prepare(
                                'UPDATE users SET email = ? WHERE role = ? AND username = ?'
                            )->execute([$em, 'admin', strtolower(trim($who))]);
                        } catch (\Throwable $e) {
                            // best-effort; app_kv is source of truth for notifications
                        }
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    $path = akh_admin_meta_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $export = var_export($next, true);
    $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n";
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $body) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

function akh_admin_meta_begin_verification(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $token = bin2hex(random_bytes(24));
    $expires = time() + 86400 * 2;
    if (!akh_admin_meta_save([
        'email' => $email,
        'email_verified' => false,
        'verify_token' => $token,
        'verify_expires_at' => $expires,
    ])) {
        return null;
    }

    return $token;
}

function akh_admin_meta_clear_verification(): bool
{
    return akh_admin_meta_save([
        'verify_token' => null,
        'verify_expires_at' => null,
    ]);
}

function akh_admin_meta_verify_token(?string $token): bool
{
    $t = is_string($token) ? trim($token) : '';
    if ($t === '') {
        return false;
    }
    $m = akh_admin_meta();
    $stored = $m['verify_token'] ?? null;
    $exp = $m['verify_expires_at'] ?? null;
    if (!is_string($stored) || $stored === '' || !is_int($exp) || $exp < time()) {
        return false;
    }
    if (!hash_equals($stored, $t)) {
        return false;
    }
    if (!akh_admin_meta_save([
        'email_verified' => true,
        'verify_token' => null,
        'verify_expires_at' => null,
    ])) {
        return false;
    }

    if (function_exists('akh_db')) {
        $m = akh_admin_meta();
        $em = strtolower(trim((string) ($m['email'] ?? '')));
        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && mb_strlen($em) <= 120) {
            try {
                akh_db()->prepare('UPDATE users SET email = ? WHERE role = ?')->execute([$em, 'admin']);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }

    return true;
}
