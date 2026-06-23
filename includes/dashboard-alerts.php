<?php

declare(strict_types=1);

require_once __DIR__ . '/task-notification-events.php';
require_once __DIR__ . '/meeting-requests.php';

function akh_dashboard_alert_priority(string $kind): int
{
    return match ($kind) {
        'meeting_reminder' => 100,
        'meeting_request' => 90,
        default => 50,
    };
}

/**
 * @param ?array<string, mixed> $base
 * @param array<string, mixed> $incoming
 * @return array<string, mixed>
 */
function akh_dashboard_merge_alert(?array $base, array $incoming): array
{
    if ($base === null) {
        $incoming['priority'] = (int) ($incoming['priority'] ?? akh_dashboard_alert_priority((string) ($incoming['kind'] ?? '')));

        return $incoming;
    }

    $kind = (string) ($incoming['kind'] ?? '');
    $incomingPriority = (int) ($incoming['priority'] ?? akh_dashboard_alert_priority($kind));
    $basePriority = (int) ($base['priority'] ?? akh_dashboard_alert_priority((string) ($base['kind'] ?? '')));

    if ($incomingPriority >= $basePriority) {
        $merged = array_merge($base, $incoming);
        $merged['count'] = (int) ($base['count'] ?? 0) + (int) ($incoming['count'] ?? 0);
        $merged['priority'] = $incomingPriority;

        return $merged;
    }

    $base['count'] = (int) ($base['count'] ?? 0) + (int) ($incoming['count'] ?? 0);

    return $base;
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_dashboard_alerts_grouped(): array
{
    $merged = akh_task_notification_pending_alerts_grouped();
    foreach ($merged as $code => $alert) {
        $merged[$code]['priority'] = akh_dashboard_alert_priority((string) ($alert['kind'] ?? 'client_update'));
    }

    foreach (akh_meeting_request_pending_alerts_grouped() as $code => $alert) {
        $merged[$code] = akh_dashboard_merge_alert($merged[$code] ?? null, $alert);
    }

    foreach (akh_meeting_request_reminder_alert_highlights() as $code => $alert) {
        $merged[$code] = akh_dashboard_merge_alert($merged[$code] ?? null, $alert);
    }

    return $merged;
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_dashboard_alerts_for_editor(string $editorUsername): array
{
    require_once __DIR__ . '/tasks.php';

    $editorUsername = strtolower(trim($editorUsername));
    if ($editorUsername === '') {
        return [];
    }

    $assigned = [];
    foreach (akh_tasks_load() as $t) {
        if (strtolower(trim((string) ($t['assigned_editor'] ?? ''))) !== $editorUsername) {
            continue;
        }
        $id = akh_task_normalize_id((string) ($t['id'] ?? ''));
        if ($id !== '') {
            $assigned[$id] = true;
        }
    }

    if (function_exists('akh_db')) {
        try {
            $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_tasks'");
            if ($st !== false && $st->fetch(PDO::FETCH_NUM) !== false) {
                $waSt = akh_db()->prepare(
                    "SELECT wt.task_code, u.username
                     FROM whatsapp_tasks wt
                     INNER JOIN users u ON u.id = wt.assigned_editor AND u.role = 'editor'
                     WHERE wt.assigned_editor IS NOT NULL"
                );
                $waSt->execute();
                while ($row = $waSt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (strtolower(trim((string) ($row['username'] ?? ''))) !== $editorUsername) {
                        continue;
                    }
                    $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
                    if ($code !== '') {
                        $assigned[$code] = true;
                    }
                }
            }
        } catch (Throwable) {
            // best-effort
        }
    }

    $out = [];
    foreach (akh_dashboard_alerts_grouped() as $taskId => $alert) {
        if (isset($assigned[$taskId])) {
            $out[$taskId] = $alert;
        }
    }

    return $out;
}

function akh_dashboard_alerts_poll_signature(): string
{
    return hash(
        'sha256',
        akh_task_notification_poll_signature() . '|' . akh_meeting_request_poll_signature()
    );
}

/**
 * @return array{count: int, alerts: array<string, array<string, mixed>>, notify_sig: string, reminders: list<array<string, mixed>>}
 */
function akh_dashboard_notification_payload(): array
{
    $alerts = akh_dashboard_alerts_grouped();
    $count = 0;
    foreach ($alerts as $a) {
        $count += (int) ($a['count'] ?? 0);
    }

    return [
        'count' => $count,
        'alerts' => $alerts,
        'notify_sig' => akh_dashboard_alerts_poll_signature(),
        'reminders' => akh_meeting_request_upcoming_reminders(),
    ];
}

/**
 * @param array<string, array<string, mixed>> $alerts
 * @return ?array<string, mixed>
 */
function akh_dashboard_alert_for_code(array $alerts, string $taskCode): ?array
{
    require_once __DIR__ . '/tasks.php';

    $code = akh_task_normalize_id($taskCode);
    if ($code === '') {
        return null;
    }
    if (isset($alerts[$code])) {
        return $alerts[$code];
    }
    foreach ($alerts as $key => $alert) {
        if (akh_task_ids_match((string) $key, $code)) {
            return $alert;
        }
    }

    return null;
}

function akh_dashboard_alert_kind_label(array $alert): string
{
    $kind = (string) ($alert['kind'] ?? '');
    if (str_starts_with($kind, 'meeting_')) {
        return akh_meeting_request_kind_label($kind);
    }

    return akh_task_notification_kind_label($kind);
}

function akh_dashboard_mark_task_read(string $taskCode): void
{
    akh_task_notification_mark_task_read($taskCode);
    akh_meeting_request_mark_task_read($taskCode);
}

function akh_dashboard_mark_all_read(): void
{
    akh_task_notification_mark_all_read();
    akh_meeting_request_mark_all_read();
}
