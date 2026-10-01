<?php

declare(strict_types=1);

require_once __DIR__ . '/site-datetime.php';

/**
 * MySQL for task_status_changes (same host as whatsapp_tasks / task_updates when tasks load from n8n bridge).
 */
function akh_task_status_log_pdo(): ?PDO
{
    if (function_exists('akh_notify_db_is_available') && akh_notify_db_is_available()) {
        $pdo = akh_notify_db();

        return $pdo instanceof PDO ? $pdo : null;
    }
    if (function_exists('akh_db_is_pdo') && akh_db_is_pdo()) {
        $main = akh_db();

        return $main instanceof PDO ? $main : null;
    }

    return null;
}

function akh_task_status_log_table_exists(): bool
{
    $pdo = akh_task_status_log_pdo();
    if ($pdo === null) {
        return false;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'task_status_changes'");

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

/**
 * Canonical studio status code from a log column or task_updates label.
 */
function akh_task_status_log_resolve_code(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $norm = akh_task_status_log_normalize($raw);
    if ($norm !== '') {
        return $norm;
    }

    return akh_task_status_log_label_to_status($raw);
}

function akh_task_status_log_label_to_status(string $label): string
{
    $key = strtolower(trim($label));
    if ($key === 'returned for revision' || str_contains($key, 'returned for revision')) {
        return 'reverted';
    }
    $map = [
        'new' => 'new',
        'assigned' => 'assigned',
        'editing' => 'in_progress',
        'in progress' => 'in_progress',
        'internal review' => 'review',
        'review' => 'review',
        'preview sent' => 'preview_sent',
        'preview_sent' => 'preview_sent',
        'delivered' => 'delivered',
        'closed' => 'closed',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'reverted' => 'reverted',
    ];

    if (isset($map[$key])) {
        return $map[$key];
    }

    if (str_contains($key, 'revert') || str_contains($key, 'revision')) {
        return 'reverted';
    }

    return akh_task_status_log_normalize($key);
}

function akh_task_status_log_normalize_source(string $source): string
{
    $s = strtolower(trim($source));

    return in_array($s, ['editor', 'whatsapp', 'admin'], true) ? $s : 'editor';
}

/**
 * task_status_changes.created_at is stored/read as UTC (GMT). Analytics only — do not use elsewhere.
 */
function akh_analytics_parse_log_timestamp(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $utc = new DateTimeZone('UTC');
    if (akh_datetime_has_timezone($raw)) {
        try {
            return (new DateTimeImmutable($raw))->setTimezone(akh_site_timezone());
        } catch (Throwable) {
            return null;
        }
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $utc);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->setTimezone(akh_site_timezone());
        }
    }

    return null;
}

/**
 * @return array{0: string, 1: string} UTC SQL bounds for a site-local month range
 */
function akh_analytics_month_sql_bounds_utc(DateTimeImmutable $startSite, DateTimeImmutable $endSite): array
{
    $utc = new DateTimeZone('UTC');

    return [
        $startSite->setTimezone($utc)->format('Y-m-d H:i:s'),
        $endSite->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/**
 * @return list<array{key: string, label: string, from: string, to: string}>
 */
function akh_task_status_log_editor_stage_definitions(): array
{
    return [
        ['key' => 'new_assigned', 'label' => 'New → Assigned', 'from' => 'new', 'to' => 'assigned'],
        ['key' => 'assigned_in_progress', 'label' => 'Assigned → Editing', 'from' => 'assigned', 'to' => 'in_progress'],
        ['key' => 'in_progress_review', 'label' => 'Editing → Review', 'from' => 'in_progress', 'to' => 'review'],
        ['key' => 'review_preview', 'label' => 'Review → Preview', 'from' => 'review', 'to' => 'preview_sent'],
        ['key' => 'preview_delivered', 'label' => 'Preview → Delivered', 'from' => 'preview_sent', 'to' => 'delivered'],
        ['key' => 'create_delivered', 'label' => 'New → Delivered', 'from' => '_created', 'to' => 'delivered'],
    ];
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
    $from = akh_task_status_log_resolve_code($fromStatus);
    $to = akh_task_status_log_resolve_code($toStatus);
    if ($from === '') {
        $from = 'new';
    }
    if ($taskId === '' || $to === '' || $from === $to) {
        return;
    }

    $source = akh_task_status_log_normalize_source($source);
    $changedBy = mb_substr(trim($changedBy), 0, 64);
    $comment = trim($comment);
    if (mb_strlen($comment) > 4000) {
        $comment = mb_substr($comment, 0, 3997) . '…';
    }

    $pdo = akh_task_status_log_pdo();
    if ($pdo === null) {
        return;
    }

    try {
        $pdo->prepare(
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
            $to = akh_task_status_log_resolve_code((string) ($row['status'] ?? ''));
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
    return array_merge(akh_task_status_log_editor_stage_definitions(), [
        ['key' => 'preview_reverted', 'label' => 'Preview → Returned', 'from' => 'preview_sent', 'to' => 'reverted'],
        ['key' => 'delivered_reverted', 'label' => 'Delivered → Returned', 'from' => 'delivered', 'to' => 'reverted'],
        ['key' => 'review_reverted', 'label' => 'Review → Returned', 'from' => 'review', 'to' => 'reverted'],
    ]);
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
        [$sqlStart, $sqlEnd] = akh_analytics_month_sql_bounds_utc($start, $end);
        $st = akh_db()->prepare(
            'SELECT task_id, from_status, to_status, source, created_at
             FROM task_status_changes
             WHERE created_at >= ? AND created_at < ?
             ORDER BY task_id ASC, created_at ASC, id ASC'
        );
        $st->execute([$sqlStart, $sqlEnd]);
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
        $from = akh_task_status_log_resolve_code((string) ($row['from_status'] ?? ''));
        $to = akh_task_status_log_resolve_code((string) ($row['to_status'] ?? ''));
        $key = $from . '|' . $to;
        if (isset($defIndex[$key])) {
            $defKey = $defIndex[$key]['key'];
            ++$transitionCounts[$defKey];

            $taskId = (string) ($row['task_id'] ?? '');
            $createdRaw = (string) ($row['created_at'] ?? '');
            $toDt = akh_analytics_parse_log_timestamp($createdRaw);
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

        return is_string($raw) && $raw !== '' ? akh_analytics_parse_log_timestamp($raw) : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array<string, array<string, DateTimeImmutable>>
 */
function akh_task_status_log_first_status_hits_by_task(): array
{
    if (!akh_task_status_log_table_exists()) {
        return [];
    }

    try {
        $st = akh_db()->query(
            'SELECT task_id, to_status, created_at
             FROM task_status_changes
             ORDER BY task_id ASC, created_at ASC, id ASC'
        );
        if ($st === false) {
            return [];
        }

        require_once __DIR__ . '/tasks.php';

        /** @var array<string, array<string, DateTimeImmutable>> */
        $firstHit = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $to = akh_task_status_log_resolve_code((string) ($row['to_status'] ?? ''));
            $dt = akh_analytics_parse_log_timestamp((string) ($row['created_at'] ?? ''));
            if ($code === '' || $to === '' || $dt === null) {
                continue;
            }
            if (!isset($firstHit[$code][$to]) || $dt < $firstHit[$code][$to]) {
                $firstHit[$code][$to] = $dt;
            }
        }

        return $firstHit;
    } catch (Throwable) {
        return [];
    }
}

/**
 * @param array<string, DateTimeImmutable> $hits
 * @param array{from: string, to: string} $stage
 */
function akh_task_status_log_stage_hours(array $hits, DateTimeImmutable $created, array $stage): ?float
{
    $toStatus = $stage['to'];
    if (!isset($hits[$toStatus])) {
        return null;
    }
    $toDt = $hits[$toStatus];
    if ($stage['from'] === '_created') {
        $fromDt = $created;
    } elseif (isset($hits[$stage['from']])) {
        $fromDt = $hits[$stage['from']];
    } else {
        return null;
    }
    if ($toDt <= $fromDt) {
        return null;
    }
    $hours = ($toDt->getTimestamp() - $fromDt->getTimestamp()) / 3600;
    if ($hours < 0 || $hours >= 24 * 366) {
        return null;
    }

    return $hours;
}

/**
 * Editor turnaround from status log (tasks delivered in the report month).
 *
 * @return array{
 *   available: bool,
 *   stage_columns: list<array{key: string, label: string}>,
 *   by_editor: list<array{username: string, delivered: int, averages: array<string, float|null>}>,
 *   tasks: list<array{task_id: string, editor: string, delivered_label: string, stages: array<string, float|null>}>
 * }
 */
function akh_task_status_log_editor_performance_report(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $stageDefs = akh_task_status_log_editor_stage_definitions();
    $stageColumns = array_map(static fn (array $s): array => [
        'key' => (string) $s['key'],
        'label' => (string) $s['label'],
    ], $stageDefs);

    $empty = [
        'available' => false,
        'stage_columns' => $stageColumns,
        'by_editor' => [],
        'tasks' => [],
    ];

    if (!akh_task_status_log_table_exists()) {
        return $empty;
    }

    require_once __DIR__ . '/tasks.php';

    $firstHit = akh_task_status_log_first_status_hits_by_task();
    if ($firstHit === []) {
        return $empty;
    }

    /** @var array<string, list<float>> */
    $editorBuckets = [];
    /** @var list<array{task_id: string, editor: string, delivered_ts: int, delivered_label: string, stages: array<string, float|null>}> */
    $taskRows = [];

    foreach (akh_tasks_load() as $t) {
        if (!is_array($t)) {
            continue;
        }
        $code = akh_task_normalize_id((string) ($t['id'] ?? ''));
        if ($code === '' || !isset($firstHit[$code])) {
            continue;
        }
        $hits = $firstHit[$code];
        $deliveredDt = akh_task_status_log_delivery_milestone_at($hits);
        if ($deliveredDt === null) {
            continue;
        }
        if ($deliveredDt < $start || $deliveredDt >= $end) {
            continue;
        }

        $editor = strtolower(trim((string) ($t['assigned_editor'] ?? '')));
        if ($editor === '') {
            continue;
        }

        $created = akh_parse_datetime_to_site((string) ($t['created_at'] ?? ''));
        if ($created === null) {
            continue;
        }

        /** @var array<string, float|null> */
        $stageHours = [];
        foreach ($stageDefs as $stage) {
            $hours = akh_task_status_log_stage_hours($hits, $created, $stage);
            $stageHours[(string) $stage['key']] = $hours;
            if ($hours !== null) {
                if (!isset($editorBuckets[$editor])) {
                    $editorBuckets[$editor] = [];
                    foreach ($stageDefs as $sd) {
                        $editorBuckets[$editor][(string) $sd['key']] = [];
                    }
                }
                $editorBuckets[$editor][(string) $stage['key']][] = $hours;
            }
        }

        $taskRows[] = [
            'task_id' => $code,
            'editor' => $editor,
            'delivered_ts' => $deliveredDt->getTimestamp(),
            'delivered_label' => $deliveredDt->format('M j, Y g:i A'),
            'stages' => $stageHours,
        ];
    }

    usort($taskRows, static fn (array $a, array $b): int => $b['delivered_ts'] <=> $a['delivered_ts']);

    $byEditor = [];
    foreach ($editorBuckets as $username => $buckets) {
        $averages = [];
        foreach ($stageDefs as $stage) {
            $key = (string) $stage['key'];
            $averages[$key] = akh_task_status_log_avg_hours($buckets[$key] ?? []);
        }
        $deliveredCount = 0;
        foreach ($taskRows as $tr) {
            if ($tr['editor'] === $username) {
                ++$deliveredCount;
            }
        }
        $byEditor[] = [
            'username' => $username,
            'delivered' => $deliveredCount,
            'averages' => $averages,
        ];
    }

    usort($byEditor, static function (array $a, array $b): int {
        $cmp = ($b['delivered'] ?? 0) <=> ($a['delivered'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
    });

    $tasksOut = [];
    foreach (array_slice($taskRows, 0, 50) as $tr) {
        $tasksOut[] = [
            'task_id' => (string) $tr['task_id'],
            'editor' => (string) $tr['editor'],
            'delivered_label' => (string) $tr['delivered_label'],
            'stages' => $tr['stages'],
        ];
    }

    return [
        'available' => true,
        'stage_columns' => $stageColumns,
        'by_editor' => $byEditor,
        'tasks' => $tasksOut,
    ];
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
    $milestones = akh_task_status_log_editor_stage_definitions();

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
        $firstHit = akh_task_status_log_first_status_hits_by_task();
        if ($firstHit === []) {
            return $out;
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
                $hours = akh_task_status_log_stage_hours($hits, $created, $m);
                if ($hours !== null) {
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
 * First delivery instant for reporting; ignores delivery if the task was later returned for revision.
 *
 * @param array<string, DateTimeImmutable> $hits
 */
function akh_task_status_log_delivery_milestone_at(array $hits): ?DateTimeImmutable
{
    $delivered = null;
    if (isset($hits['delivered'])) {
        $delivered = $hits['delivered'];
    } elseif (isset($hits['closed'])) {
        $delivered = $hits['closed'];
    }
    if ($delivered === null) {
        return null;
    }
    if (isset($hits['reverted']) && $hits['reverted'] >= $delivered) {
        return null;
    }

    return $delivered;
}

/**
 * @return array<string, DateTimeImmutable> task code => first delivered/closed (not voided by reverted)
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
            $dt = akh_analytics_parse_log_timestamp($raw);
            if ($dt !== null) {
                $out[$code] = $dt;
            }
        }

        $revSt = akh_db()->query(
            "SELECT task_id, MIN(created_at) AS reverted_at
             FROM task_status_changes
             WHERE to_status = 'reverted'
             GROUP BY task_id"
        );
        if ($revSt !== false) {
            foreach ($revSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
                $raw = trim((string) ($row['reverted_at'] ?? ''));
                if ($code === '' || $raw === '' || !isset($out[$code])) {
                    continue;
                }
                $revDt = akh_analytics_parse_log_timestamp($raw);
                if ($revDt !== null && $revDt >= $out[$code]) {
                    unset($out[$code]);
                }
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Fix legacy rows where "returned for revision" was stored as closed in task_status_changes.
 */
function akh_task_status_log_repair_reverted_mislogged_as_closed(): int
{
    if (!akh_task_status_log_table_exists()) {
        return 0;
    }

    try {
        $st = akh_db()->prepare(
            "UPDATE task_status_changes
             SET to_status = 'reverted'
             WHERE to_status = 'closed'
               AND from_status IN ('preview_sent', 'delivered', 'review', 'in_progress', 'assigned')
               AND (
                 comment LIKE '%revision%'
                 OR comment LIKE '%revert%'
                 OR comment LIKE '%feedback%'
                 OR comment LIKE '%preview%'
                 OR comment LIKE '%returned%'
               )"
        );
        $st->execute();

        return (int) $st->rowCount();
    } catch (Throwable $e) {
        error_log('akh_task_status_log_repair_reverted_mislogged_as_closed: ' . $e->getMessage());

        return 0;
    }
}

/**
 * @return array<string, DateTimeImmutable> task code => first cancelled
 */
function akh_task_status_log_first_cancelled_at_map(): array
{
    if (!akh_task_status_log_table_exists()) {
        return [];
    }

    require_once __DIR__ . '/tasks.php';

    try {
        $st = akh_db()->query(
            "SELECT task_id, MIN(created_at) AS cancelled_at
             FROM task_status_changes
             WHERE to_status = 'cancelled'
             GROUP BY task_id"
        );
        if ($st === false) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $raw = trim((string) ($row['cancelled_at'] ?? ''));
            if ($code === '' || $raw === '') {
                continue;
            }
            $dt = akh_analytics_parse_log_timestamp($raw);
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
