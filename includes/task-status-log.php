<?php

declare(strict_types=1);

require_once __DIR__ . '/site-datetime.php';

function akh_task_status_log_table_exists(): bool
{
    if (!function_exists('akh_db') || !akh_db_is_pdo()) {
        return false;
    }
    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'task_status_changes'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_task_status_log_normalize(string $status): string
{
    $s = strtolower(trim($status));
    $allowed = [
        'new',
        'assigned',
        'in_progress',
        'review',
        'preview_sent',
        'delivered',
        'reverted',
        'closed',
        'cancelled',
    ];

    return in_array($s, $allowed, true) ? $s : '';
}

function akh_task_status_log_label_to_status(string $label): string
{
    $key = strtolower(trim($label));
    $map = [
        'new' => 'new',
        'assigned' => 'assigned',
        'editing' => 'in_progress',
        'in progress' => 'in_progress',
        'review' => 'review',
        'preview sent' => 'preview_sent',
        'preview_sent' => 'preview_sent',
        'delivered' => 'delivered',
        'closed' => 'closed',
        'cancelled' => 'cancelled',
        'reverted' => 'reverted',
    ];

    if (isset($map[$key])) {
        return $map[$key];
    }

    return akh_task_status_log_normalize($key);
}

function akh_task_status_log_normalize_source(string $source): string
{
    $s = strtolower(trim($source));

    return in_array($s, ['editor', 'whatsapp', 'admin'], true) ? $s : 'editor';
}

/**
 * Record a studio status transition (no-op when from === to or table missing).
 */
function akh_task_status_log_record(
    string $taskId,
    string $fromStatus,
    string $toStatus,
    string $source = 'editor',
    string $changedBy = '',
    string $comment = ''
): void {
    require_once __DIR__ . '/tasks.php';

    if (!akh_task_status_log_table_exists()) {
        return;
    }

    $taskId = akh_task_normalize_id(trim($taskId));
    $from = akh_task_status_log_normalize($fromStatus);
    $to = akh_task_status_log_normalize($toStatus);
    if ($taskId === '' || $to === '' || $from === $to) {
        return;
    }

    $source = akh_task_status_log_normalize_source($source);
    $changedBy = mb_substr(trim($changedBy), 0, 64);
    $comment = trim($comment);
    if (mb_strlen($comment) > 4000) {
        $comment = mb_substr($comment, 0, 3997) . '…';
    }

    try {
        akh_db()->prepare(
            'INSERT INTO task_status_changes (task_id, from_status, to_status, source, changed_by, comment)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $taskId,
            $from,
            $to,
            $source,
            $changedBy,
            $comment !== '' ? $comment : null,
        ]);
    } catch (Throwable $e) {
        error_log('akh_task_status_log_record: ' . $e->getMessage());
    }
}

/**
 * Best-effort import from legacy task_updates rows (status-only snapshots).
 */
function akh_task_status_log_backfill_from_task_updates(): int
{
    require_once __DIR__ . '/whatsapp-task-sync.php';
    require_once __DIR__ . '/tasks.php';

    if (!akh_task_status_log_table_exists() || !akh_wa_task_updates_table_exists()) {
        return 0;
    }

    try {
        $existing = (int) akh_db()->query('SELECT COUNT(*) FROM task_status_changes')->fetchColumn();
        if ($existing > 0) {
            return 0;
        }

        $st = akh_db()->query(
            'SELECT task_id, status, comment, updated_by, created_at
             FROM task_updates
             ORDER BY task_id ASC, created_at ASC, id ASC'
        );
        if ($st === false) {
            return 0;
        }

        $prevByTask = [];
        $insert = akh_db()->prepare(
            'INSERT INTO task_status_changes (task_id, from_status, to_status, source, changed_by, comment, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $to = akh_task_status_log_label_to_status((string) ($row['status'] ?? ''));
            if ($code === '' || $to === '') {
                continue;
            }
            $from = $prevByTask[$code] ?? 'new';
            if ($from === $to) {
                continue;
            }
            $by = trim((string) ($row['updated_by'] ?? ''));
            $source = stripos($by, 'whatsapp') !== false ? 'whatsapp' : 'editor';
            $insert->execute([
                $code,
                $from,
                $to,
                $source,
                mb_substr($by, 0, 64),
                trim((string) ($row['comment'] ?? '')) ?: null,
                (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $prevByTask[$code] = $to;
            ++$n;
        }

        return $n;
    } catch (Throwable $e) {
        error_log('akh_task_status_log_backfill_from_task_updates: ' . $e->getMessage());

        return 0;
    }
}

/**
 * @return list<array{key: string, label: string, from: string, to: string}>
 */
function akh_task_status_log_pipeline_definitions(): array
{
    return [
        ['key' => 'new_assigned', 'label' => 'New → Assigned', 'from' => 'new', 'to' => 'assigned'],
        ['key' => 'assigned_preview', 'label' => 'Assigned → Preview sent', 'from' => 'assigned', 'to' => 'preview_sent'],
        ['key' => 'preview_delivered', 'label' => 'Preview sent → Delivered', 'from' => 'preview_sent', 'to' => 'delivered'],
        ['key' => 'new_delivered', 'label' => 'New → Delivered', 'from' => 'new', 'to' => 'delivered'],
        ['key' => 'assigned_in_progress', 'label' => 'Assigned → In progress', 'from' => 'assigned', 'to' => 'in_progress'],
        ['key' => 'in_progress_review', 'label' => 'In progress → Review', 'from' => 'in_progress', 'to' => 'review'],
        ['key' => 'review_preview', 'label' => 'Review → Preview sent', 'from' => 'review', 'to' => 'preview_sent'],
    ];
}

/**
 * @return array{
 *   available: bool,
 *   transitions: list<array{key: string, label: string, count: int, avg_hours: float|null, median_hours: float|null}>,
 *   by_source: array{editor: int, whatsapp: int, admin: int},
 *   stage_durations: list<array{key: string, label: string, avg_hours: float|null, sample: int}>
 * }
 */
function akh_task_status_log_pipeline_report(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $empty = [
        'available' => false,
        'transitions' => [],
        'by_source' => ['editor' => 0, 'whatsapp' => 0, 'admin' => 0],
        'stage_durations' => [],
    ];

    if (!akh_task_status_log_table_exists()) {
        $defs = akh_task_status_log_pipeline_definitions();
        foreach ($defs as $d) {
            $empty['transitions'][] = [
                'key' => $d['key'],
                'label' => $d['label'],
                'count' => 0,
                'avg_hours' => null,
                'median_hours' => null,
            ];
        }

        return $empty;
    }

    $defs = akh_task_status_log_pipeline_definitions();
    $defIndex = [];
    foreach ($defs as $d) {
        $defIndex[$d['from'] . '|' . $d['to']] = $d;
    }

    try {
        $st = akh_db()->prepare(
            'SELECT task_id, from_status, to_status, source, created_at
             FROM task_status_changes
             WHERE created_at >= ? AND created_at < ?
             ORDER BY task_id ASC, created_at ASC, id ASC'
        );
        $st->execute([
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ]);
        $monthRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return $empty;
    }

    $bySource = ['editor' => 0, 'whatsapp' => 0, 'admin' => 0];
    /** @var array<string, list<float>> */
    $durationBuckets = [];
    foreach ($defs as $d) {
        $durationBuckets[$d['key']] = [];
    }

    /** @var array<string, int> */
    $transitionCounts = [];
    foreach ($defs as $d) {
        $transitionCounts[$d['key']] = 0;
    }

    foreach ($monthRows as $row) {
        $src = akh_task_status_log_normalize_source((string) ($row['source'] ?? 'editor'));
        if (isset($bySource[$src])) {
            ++$bySource[$src];
        }
        $from = akh_task_status_log_normalize((string) ($row['from_status'] ?? ''));
        $to = akh_task_status_log_normalize((string) ($row['to_status'] ?? ''));
        $key = $from . '|' . $to;
        if (isset($defIndex[$key])) {
            $defKey = $defIndex[$key]['key'];
            ++$transitionCounts[$defKey];

            $taskId = (string) ($row['task_id'] ?? '');
            $createdRaw = (string) ($row['created_at'] ?? '');
            $toDt = akh_parse_datetime_to_site($createdRaw);
            if ($taskId !== '' && $toDt !== null) {
                $prevAt = akh_task_status_log_previous_change_at($taskId, $createdRaw);
                if ($prevAt !== null) {
                    $hours = ($toDt->getTimestamp() - $prevAt->getTimestamp()) / 3600;
                    if ($hours >= 0 && $hours < 24 * 366) {
                        $durationBuckets[$defKey][] = $hours;
                    }
                }
            }
        }
    }

    $transitions = [];
    foreach ($defs as $d) {
        $hours = $durationBuckets[$d['key']];
        $transitions[] = [
            'key' => $d['key'],
            'label' => $d['label'],
            'count' => (int) ($transitionCounts[$d['key']] ?? 0),
            'avg_hours' => akh_task_status_log_avg_hours($hours),
            'median_hours' => akh_task_status_log_median_hours($hours),
        ];
    }

    $stageDurations = akh_task_status_log_milestone_durations($start, $end);

    return [
        'available' => true,
        'transitions' => $transitions,
        'by_source' => $bySource,
        'stage_durations' => $stageDurations,
    ];
}

function akh_task_status_log_previous_change_at(string $taskId, string $beforeCreatedRaw): ?DateTimeImmutable
{
    if (!akh_task_status_log_table_exists()) {
        return null;
    }

    try {
        $st = akh_db()->prepare(
            'SELECT created_at FROM task_status_changes
             WHERE task_id = ? AND created_at < ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $st->execute([$taskId, $beforeCreatedRaw]);
        $raw = $st->fetchColumn();

        return is_string($raw) && $raw !== '' ? akh_parse_datetime_to_site($raw) : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param list<float> $hours
 */
function akh_task_status_log_avg_hours(array $hours): ?float
{
    if ($hours === []) {
        return null;
    }

    return round(array_sum($hours) / count($hours), 1);
}

/**
 * @param list<float> $hours
 */
function akh_task_status_log_median_hours(array $hours): ?float
{
    if ($hours === []) {
        return null;
    }
    sort($hours, SORT_NUMERIC);
    $n = count($hours);
    $mid = (int) floor(($n - 1) / 2);
    if ($n % 2 === 1) {
        return round($hours[$mid], 1);
    }

    return round(($hours[$mid] + $hours[$mid + 1]) / 2, 1);
}

/**
 * Time from first milestone A to first milestone B for tasks with B reached in the report month.
 *
 * @return list<array{key: string, label: string, avg_hours: float|null, sample: int}>
 */
function akh_task_status_log_milestone_durations(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $milestones = [
        ['key' => 'create_to_assigned', 'label' => 'Created → Assigned', 'from' => '_created', 'to' => 'assigned'],
        ['key' => 'assigned_to_preview', 'label' => 'Assigned → Preview sent', 'from' => 'assigned', 'to' => 'preview_sent'],
        ['key' => 'preview_to_delivered', 'label' => 'Preview sent → Delivered', 'from' => 'preview_sent', 'to' => 'delivered'],
    ];

    $out = [];
    foreach ($milestones as $m) {
        $out[] = [
            'key' => $m['key'],
            'label' => $m['label'],
            'avg_hours' => null,
            'sample' => 0,
        ];
    }

    if (!akh_task_status_log_table_exists()) {
        return $out;
    }

    require_once __DIR__ . '/tasks.php';

    try {
        $st = akh_db()->query(
            'SELECT task_id, to_status, created_at
             FROM task_status_changes
             ORDER BY task_id ASC, created_at ASC, id ASC'
        );
        if ($st === false) {
            return $out;
        }

        /** @var array<string, array<string, DateTimeImmutable>> */
        $firstHit = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $to = akh_task_status_log_normalize((string) ($row['to_status'] ?? ''));
            $dt = akh_parse_datetime_to_site((string) ($row['created_at'] ?? ''));
            if ($code === '' || $to === '' || $dt === null) {
                continue;
            }
            if (!isset($firstHit[$code][$to]) || $dt < $firstHit[$code][$to]) {
                $firstHit[$code][$to] = $dt;
            }
        }

        /** @var array<string, list<float>> */
        $buckets = [];
        foreach ($milestones as $m) {
            $buckets[$m['key']] = [];
        }

        foreach (akh_tasks_load() as $t) {
            if (!is_array($t)) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($t['id'] ?? ''));
            if ($code === '' || !isset($firstHit[$code])) {
                continue;
            }
            $created = akh_parse_datetime_to_site((string) ($t['created_at'] ?? ''));
            if ($created === null) {
                continue;
            }
            $hits = $firstHit[$code];

            foreach ($milestones as $m) {
                $toStatus = $m['to'];
                if (!isset($hits[$toStatus])) {
                    continue;
                }
                $toDt = $hits[$toStatus];
                if ($toDt < $start || $toDt >= $end) {
                    continue;
                }
                if ($m['from'] === '_created') {
                    $fromDt = $created;
                } elseif (isset($hits[$m['from']])) {
                    $fromDt = $hits[$m['from']];
                } else {
                    continue;
                }
                if ($toDt <= $fromDt) {
                    continue;
                }
                $hours = ($toDt->getTimestamp() - $fromDt->getTimestamp()) / 3600;
                if ($hours >= 0 && $hours < 24 * 366) {
                    $buckets[$m['key']][] = $hours;
                }
            }
        }

        foreach ($milestones as $i => $m) {
            $hours = $buckets[$m['key']];
            $out[$i]['sample'] = count($hours);
            $out[$i]['avg_hours'] = akh_task_status_log_avg_hours($hours);
        }
    } catch (Throwable $e) {
        error_log('akh_task_status_log_milestone_durations: ' . $e->getMessage());
    }

    return $out;
}

/**
 * @return array<string, DateTimeImmutable> task code => first delivered/closed
 */
function akh_task_status_log_first_delivered_at_map(): array
{
    if (!akh_task_status_log_table_exists()) {
        return [];
    }

    try {
        $st = akh_db()->query(
            "SELECT task_id, MIN(created_at) AS delivered_at
             FROM task_status_changes
             WHERE to_status IN ('delivered', 'closed')
             GROUP BY task_id"
        );
        if ($st === false) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $raw = trim((string) ($row['delivered_at'] ?? ''));
            if ($code === '' || $raw === '') {
                continue;
            }
            $dt = akh_parse_datetime_to_site($raw);
            if ($dt !== null) {
                $out[$code] = $dt;
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

function akh_task_status_log_format_hours(?float $hours): string
{
    if ($hours === null) {
        return '—';
    }
    if ($hours < 1) {
        return (int) round($hours * 60) . ' min';
    }
    if ($hours < 48) {
        return round($hours, 1) . ' h';
    }

    return round($hours / 24, 1) . ' d';
}
