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
 * When true (local only): client and editor portals accept username `test` / password `test` without account files.
 * Leave false in production; use data/customers.php and data/editors.php with real password hashes.
 */
const AKH_DEV_TEST_LOGIN = false;

/**
 * When true, /customer/register.php lets visitors create a client account (writes data/customers.php).
 * Disable on production if you only want manually provisioned accounts.
 */
const AKH_ALLOW_CLIENT_REGISTRATION = true;

/** Site identity */
const SITE_NAME = 'Akhurath Studio';
const SITE_TAGLINE = 'Wedding film editing — edit, color, sound, and story.';
const CONTACT_EMAIL = 'hello@akhurathstudio.com';

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
