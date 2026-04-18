<?php

declare(strict_types=1);

/**
 * Project root (parent of /includes).
 */
define('AKH_ROOT', dirname(__DIR__));

/**
 * If the site lives in a subfolder, set to '/subfolder' (no trailing slash).
 * Leave '' when the site is at the domain root.
 */
const BASE_URL = '';

/**
 * PHP session lifetime (seconds) for admin, editor, and client portals: cookie max-age and
 * session.gc_maxlifetime. Default 9 hours. Each request refreshes the cookie (sliding window).
 */
const AKH_SESSION_LIFETIME_SECONDS = 9 * 3600;

/**
 * Editor portal: clock-in at login (optional checkbox), clock in/out on task board, auto clock-out on sign out.
 */
const AKH_EDITOR_ATTENDANCE_ENABLED = true;

/**
 * When true (local only): client and editor portals accept username `test` / password `test` without account files.
 * Leave false in production; use data/customers.php and data/editors.php with real password hashes.
 */
const AKH_DEV_TEST_LOGIN = false;

/**
 * When true, /customer/register.php lets visitors create a client account (writes data/customers.php).
 * Disable on production if you only want manually provisioned accounts.
 */
const AKH_ALLOW_CLIENT_REGISTRATION = true;

/**
 * When true, /admin/setup.php is available to create the first admin while no accounts exist.
 * Set to false to disable the web setup path (use scripts/seed-admin-console.php only).
 */
const AKH_ADMIN_SETUP_ENABLED = true;

/** Site identity */
const SITE_NAME = 'Akhurath Studio';
const SITE_TAGLINE = 'Wedding film editing — edit, color, sound, and story.';
const CONTACT_EMAIL = 'hello@akhurathstudio.com';

/**
 * Hostinger email (or any SMTP): set AKH_SMTP_ENABLED true and fill credentials to send
 * admin verification and security notifications. Port 465 + ssl is typical for Hostinger.
 */
const AKH_SMTP_ENABLED = false;
const AKH_SMTP_HOST = 'smtp.hostinger.com';
const AKH_SMTP_PORT = 465;
/** 'ssl' (e.g. port 465) or 'tls' (STARTTLS, e.g. port 587) */
const AKH_SMTP_ENCRYPTION = 'ssl';
const AKH_SMTP_USER = '';
const AKH_SMTP_PASS = '';
const AKH_SMTP_FROM_EMAIL = '';
const AKH_SMTP_FROM_NAME = SITE_NAME;

/** Contact form: messages are sent to this address. */
const LEADS_EMAIL = 'akhurathstudios@gmail.com';

/** WhatsApp (E.164 without +) — opens wa.me for web & app */
const WHATSAPP_MSISDN = '919483184620';

/**
 * Customer portal: web UI for your NAS / drive (Synology Drive, Nextcloud, etc.).
 * After login, the dashboard links here — users sign in on YOUR drive with the
 * credentials you create on the NAS (same username if you sync accounts, or separate).
 */
const DRIVE_PORTAL_URL = 'https://drive.akhurathstudio.com';

function base_path(string $path = ''): string
{
    $p = ltrim($path, '/');
    $b = BASE_URL === '' ? '' : rtrim(BASE_URL, '/');
    if ($b === '') {
        return '/' . $p;
    }

    return $b . '/' . $p;
}

/** Cookie path for PHP sessions (subfolder installs need the app prefix). */
function akh_session_cookie_path(): string
{
    $b = BASE_URL === '' ? '' : rtrim(BASE_URL, '/');

    return $b === '' ? '/' : $b . '/';
}

/** Absolute URL for the current request (links in emails, verification). */
function akh_absolute_url(string $path = ''): string
{
    $p = ltrim($path, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = BASE_URL === '' ? '' : rtrim(BASE_URL, '/');

    return $scheme . '://' . $host . $base . '/' . $p;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Public URL for assets/images/client-logos/{ $slug }.ext, or null if no file. */
function client_logo_url(string $slug): ?string
{
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
    if ($slug === '') {
        return null;
    }
    foreach (['webp', 'png', 'jpg', 'jpeg', 'svg'] as $ext) {
        $rel = 'assets/images/client-logos/' . $slug . '.' . $ext;
        if (is_file(AKH_ROOT . '/' . $rel)) {
            return base_path($rel);
        }
    }

    return null;
}

/**
 * Resolve a bottom-strip logo: optional exact `file` basename in assets/images/client-logos/,
 * then slug-based client_logo_url().
 *
 * @param array{name?: string, logo?: string, file?: string} $client
 */
function client_logo_src_for_entry(array $client): ?string
{
    $dir = AKH_ROOT . '/assets/images/client-logos/';
    $file = isset($client['file']) ? trim((string) $client['file']) : '';
    if ($file !== '') {
        $base = basename(str_replace('\\', '/', $file));
        if ($base !== '' && $base !== '.' && $base !== '..' && is_file($dir . $base)) {
            $enc = rawurlencode($base);

            return base_path('assets/images/client-logos/' . $enc);
        }
    }
    $slug = isset($client['logo']) ? (string) $client['logo'] : '';

    return $slug !== '' ? client_logo_url($slug) : null;
}

/** Open WhatsApp chat (optional pre-filled message). */
function whatsapp_chat_url(?string $message = null): string
{
    $url = 'https://wa.me/' . WHATSAPP_MSISDN;
    if ($message !== null && $message !== '') {
        $url .= '?text=' . rawurlencode($message);
    }

    return $url;
}
