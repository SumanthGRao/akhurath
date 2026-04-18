<?php

declare(strict_types=1);

function akh_editor_attendance_file(): string
{
    return AKH_ROOT . '/data/editor-attendance.json';
}

/**
 * @return array{events: list<array{editor: string, type: string, at: int}>}
 */
function akh_editor_attendance_default_doc(): array
{
    return ['events' => []];
}

/**
 * @param mixed $events
 * @return list<array{editor: string, type: string, at: int}>
 */
function akh_editor_attendance_normalize_events($events): array
{
    if (!is_array($events)) {
        return [];
    }
    $out = [];
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }
        $ed = strtolower(trim((string) ($e['editor'] ?? '')));
        $ty = (string) ($e['type'] ?? '');
        $at = (int) ($e['at'] ?? 0);
        if ($ed === '' || ($ty !== 'clock_in' && $ty !== 'clock_out') || $at < 1) {
            continue;
        }
        $out[] = ['editor' => $ed, 'type' => $ty, 'at' => $at];
    }

    return $out;
}

/**
 * @param list<array{editor: string, type: string, at: int}> $events
 */
function akh_editor_attendance_is_clocked_in_from_events(string $editor, array $events): bool
{
    $key = strtolower(trim($editor));
    if ($key === '') {
        return false;
    }
    $mine = [];
    foreach ($events as $e) {
        if (($e['editor'] ?? '') === $key) {
            $mine[] = $e;
        }
    }
    usort($mine, static fn (array $a, array $b): int => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));
    $in = false;
    foreach ($mine as $e) {
        if (($e['type'] ?? '') === 'clock_in') {
            $in = true;
        } elseif (($e['type'] ?? '') === 'clock_out') {
            $in = false;
        }
    }

    return $in;
}

/**
 * @param list<array{editor: string, type: string, at: int}> $events
 */
function akh_editor_attendance_open_shift_started_at(string $editor, array $events): ?int
{
    $key = strtolower(trim($editor));
    if ($key === '') {
        return null;
    }
    $mine = [];
    foreach ($events as $e) {
        if (($e['editor'] ?? '') === $key) {
            $mine[] = $e;
        }
    }
    usort($mine, static fn (array $a, array $b): int => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));
    $in = false;
    $started = null;
    foreach ($mine as $e) {
        if (($e['type'] ?? '') === 'clock_in') {
            $in = true;
            $started = (int) ($e['at'] ?? 0);
        } elseif (($e['type'] ?? '') === 'clock_out') {
            $in = false;
            $started = null;
        }
    }

    return $in && $started !== null && $started > 0 ? $started : null;
}

function akh_editor_attendance_is_clocked_in(string $editor): bool
{
    if (!AKH_EDITOR_ATTENDANCE_ENABLED) {
        return false;
    }
    $doc = akh_editor_attendance_read_doc();
    $events = $doc['events'] ?? [];

    return akh_editor_attendance_is_clocked_in_from_events($editor, $events);
}

function akh_editor_attendance_open_shift_started_at_for(string $editor): ?int
{
    if (!AKH_EDITOR_ATTENDANCE_ENABLED) {
        return null;
    }
    $doc = akh_editor_attendance_read_doc();
    $events = $doc['events'] ?? [];

    return akh_editor_attendance_open_shift_started_at($editor, $events);
}

/**
 * @return array{events: list<array{editor: string, type: string, at: int}>}
 */
function akh_editor_attendance_read_doc(): array
{
    $path = akh_editor_attendance_file();
    if (!is_file($path)) {
        return akh_editor_attendance_default_doc();
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return akh_editor_attendance_default_doc();
    }
    try {
        $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        return akh_editor_attendance_default_doc();
    }
    if (!is_array($j)) {
        return akh_editor_attendance_default_doc();
    }
    $events = akh_editor_attendance_normalize_events($j['events'] ?? []);

    return ['events' => $events];
}

function akh_editor_attendance_append(string $editor, string $type): bool
{
    if (!AKH_EDITOR_ATTENDANCE_ENABLED) {
        return true;
    }
    if ($type !== 'clock_in' && $type !== 'clock_out') {
        return false;
    }
    $editor = strtolower(trim($editor));
    if ($editor === '') {
        return false;
    }

    $path = akh_editor_attendance_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return false;
    }
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $doc = akh_editor_attendance_default_doc();
        if ($raw !== false && $raw !== '') {
            try {
                $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($j) && isset($j['events'])) {
                    $doc['events'] = akh_editor_attendance_normalize_events($j['events']);
                }
            } catch (\Throwable $e) {
                $doc = akh_editor_attendance_default_doc();
            }
        }

        $events = $doc['events'];
        if ($type === 'clock_in' && akh_editor_attendance_is_clocked_in_from_events($editor, $events)) {
            return true;
        }
        if ($type === 'clock_out' && !akh_editor_attendance_is_clocked_in_from_events($editor, $events)) {
            return true;
        }

        $events[] = ['editor' => $editor, 'type' => $type, 'at' => time()];
        $max = 8000;
        if (count($events) > $max) {
            $events = array_slice($events, -$max);
        }
        $doc['events'] = $events;
        $out = json_encode($doc, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $out);
        fflush($fp);
    } catch (\Throwable $e) {
        return false;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return true;
}

function akh_editor_attendance_auto_clock_out_on_logout(string $editor): void
{
    if (!AKH_EDITOR_ATTENDANCE_ENABLED) {
        return;
    }
    $key = strtolower(trim($editor));
    if ($key === '') {
        return;
    }
    if (akh_editor_attendance_is_clocked_in($key)) {
        akh_editor_attendance_append($key, 'clock_out');
    }
}
