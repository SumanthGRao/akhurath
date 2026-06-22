<?php

declare(strict_types=1);

function akh_wa_dashboard_enabled(): bool
{
    return defined('AKH_WA_DASHBOARD_ENABLED') && AKH_WA_DASHBOARD_ENABLED;
}

function akh_wa_dashboard_configured(): bool
{
    $user = defined('AKH_WA_DASHBOARD_USER') ? trim((string) AKH_WA_DASHBOARD_USER) : '';
    $hash = defined('AKH_WA_DASHBOARD_PASS_HASH') ? trim((string) AKH_WA_DASHBOARD_PASS_HASH) : '';

    return $user !== '' && $hash !== '';
}

function akh_wa_dashboard_current(): ?string
{
    $u = $_SESSION['akh_wa_dashboard_user'] ?? null;

    return is_string($u) && $u !== '' ? $u : null;
}

function akh_wa_dashboard_refresh_seconds(): int
{
    if (!defined('AKH_WA_DASHBOARD_REFRESH_SECONDS')) {
        return 300;
    }

    return max(60, (int) AKH_WA_DASHBOARD_REFRESH_SECONDS);
}

function akh_wa_dashboard_login(string $username, string $password): bool
{
    if (!akh_wa_dashboard_enabled() || !akh_wa_dashboard_configured()) {
        return false;
    }

    $key = strtolower(trim($username));
    $expected = strtolower(trim((string) AKH_WA_DASHBOARD_USER));
    if ($key === '' || $key !== $expected) {
        return false;
    }

    $hash = (string) AKH_WA_DASHBOARD_PASS_HASH;
    if (!password_verify($password, $hash)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['akh_wa_dashboard_user'] = $key;

    return true;
}

function akh_wa_dashboard_logout(): void
{
    unset($_SESSION['akh_wa_dashboard_user'], $_SESSION['wa_notify_watermark']);
}

function akh_wa_notification_watermark(): int
{
    require_once __DIR__ . '/task-notification-events.php';
    if (!isset($_SESSION['wa_notify_watermark'])) {
        $_SESSION['wa_notify_watermark'] = akh_task_notification_latest_id();
    }

    return max(0, (int) $_SESSION['wa_notify_watermark']);
}

function akh_wa_notification_mark_seen(int $eventId): void
{
    if ($eventId < 1) {
        return;
    }
    $cur = akh_wa_notification_watermark();
    if ($eventId > $cur) {
        $_SESSION['wa_notify_watermark'] = $eventId;
    }
}

function akh_wa_notification_mark_all_seen(): void
{
    require_once __DIR__ . '/task-notification-events.php';
    $_SESSION['wa_notify_watermark'] = akh_task_notification_latest_id();
}

/**
 * @return array{count: int, alerts: array<string, array{count: int, max_id: int, preview: string, kind: string, created_at: string}>, notify_sig: string}
 */
function akh_wa_client_notification_payload(): array
{
    require_once __DIR__ . '/task-notification-events.php';
    $wm = akh_wa_notification_watermark();
    $alerts = akh_task_notification_client_alerts_grouped($wm);
    $count = 0;
    foreach ($alerts as $a) {
        $count += (int) ($a['count'] ?? 0);
    }

    return [
        'count' => $count,
        'alerts' => $alerts,
        'notify_sig' => akh_task_notification_poll_signature(),
        'watermark' => $wm,
    ];
}

function akh_require_wa_dashboard(): void
{
    if (!akh_wa_dashboard_enabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'WhatsApp task dashboard is disabled.';
        exit;
    }

    if (akh_wa_dashboard_current() === null) {
        header('Location: ' . base_path('whatsapp/login.php'));
        exit;
    }
}
