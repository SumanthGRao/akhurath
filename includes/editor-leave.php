<?php

declare(strict_types=1);

function akh_editor_leave_file(): string
{
    return AKH_ROOT . '/data/editor-leave.json';
}

/**
 * @return array{requests: list<array{id: string, editor: string, date: string, leave_part: string, note: string, status: string, created_at: int}>}
 */
function akh_editor_leave_read(): array
{
    $path = akh_editor_leave_file();
    if (!is_file($path)) {
        return ['requests' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['requests' => []];
    }
    try {
        $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        return ['requests' => []];
    }
    if (!is_array($j) || !isset($j['requests']) || !is_array($j['requests'])) {
        return ['requests' => []];
    }
    $out = [];
    foreach ($j['requests'] as $r) {
        if (!is_array($r)) {
            continue;
        }
        $id = (string) ($r['id'] ?? '');
        $ed = strtolower(trim((string) ($r['editor'] ?? '')));
        $dt = (string) ($r['date'] ?? '');
        if ($id === '' || $ed === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'editor' => $ed,
            'date' => $dt,
            'leave_part' => akh_editor_leave_part_normalize((string) ($r['leave_part'] ?? 'full')),
            'note' => trim((string) ($r['note'] ?? '')),
            'status' => (string) ($r['status'] ?? 'pending'),
            'created_at' => (int) ($r['created_at'] ?? 0),
        ];
    }

    return ['requests' => $out];
}

function akh_editor_leave_part_normalize(string $part): string
{
    $p = trim(strtolower($part));
    if ($p !== 'first_half' && $p !== 'second_half') {
        return 'full';
    }

    return $p;
}

function akh_editor_leave_part_label(string $part): string
{
    $p = akh_editor_leave_part_normalize($part);
    if ($p === 'first_half') {
        return 'Half day — first half';
    }
    if ($p === 'second_half') {
        return 'Half day — second half';
    }

    return 'Full day';
}

function akh_editor_leave_write(array $doc): bool
{
    $path = akh_editor_leave_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $out = json_encode($doc, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $out) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/** Remove every leave request (admin / production reset). */
function akh_editor_leave_clear_all(): bool
{
    return akh_editor_leave_write(['requests' => []]);
}

function akh_editor_leave_pending_count(): int
{
    $n = 0;
    foreach (akh_editor_leave_read()['requests'] as $r) {
        if (($r['status'] ?? '') === 'pending') {
            ++$n;
        }
    }

    return $n;
}

function akh_editor_leave_pending_for_editor(string $editor): int
{
    $key = strtolower(trim($editor));
    $n = 0;
    foreach (akh_editor_leave_read()['requests'] as $r) {
        if (($r['editor'] ?? '') === $key && ($r['status'] ?? '') === 'pending') {
            ++$n;
        }
    }

    return $n;
}

/**
 * @return list<array{id: string, editor: string, date: string, note: string, status: string, created_at: int}>
 */
function akh_editor_leave_pending_list(): array
{
    $list = [];
    foreach (akh_editor_leave_read()['requests'] as $r) {
        if (($r['status'] ?? '') === 'pending') {
            $list[] = $r;
        }
    }
    usort($list, static fn (array $a, array $b): int => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

    return $list;
}

/**
 * Approved leave map for editor in month (Y-m-d => full|first_half|second_half).
 *
 * @return array<string, string>
 */
function akh_editor_leave_approved_map_in_month(string $editor, int $year, int $month): array
{
    $key = strtolower(trim($editor));
    $map = [];
    $pfx = sprintf('%04d-%02d-', $year, $month);
    foreach (akh_editor_leave_read()['requests'] as $r) {
        if (($r['editor'] ?? '') !== $key || ($r['status'] ?? '') !== 'approved') {
            continue;
        }
        $d = (string) ($r['date'] ?? '');
        if (str_starts_with($d, $pfx)) {
            $part = akh_editor_leave_part_normalize((string) ($r['leave_part'] ?? 'full'));
            if (!isset($map[$d]) || $part === 'full') {
                $map[$d] = $part;
            }
        }
    }

    return $map;
}

function akh_editor_leave_apply(string $editor, string $ymd, string $note, string $leavePart = 'full'): ?string
{
    if (!AKH_EDITOR_ATTENDANCE_ENABLED) {
        return 'Attendance is disabled.';
    }
    $key = strtolower(trim($editor));
    if ($key === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return 'Invalid request.';
    }
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        return 'Invalid date.';
    }
    $w = (int) date('w', $ts);
    if ($w === 0) {
        return 'Sunday is already a fixed off day.';
    }
    $note = trim($note);
    if (mb_strlen($note) > 500) {
        return 'Note is too long.';
    }
    $leavePart = akh_editor_leave_part_normalize($leavePart);

    $path = akh_editor_leave_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return 'Could not create data directory.';
    }
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return 'Could not open leave file.';
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return 'Could not lock leave file.';
    }
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $doc = ['requests' => []];
        if ($raw !== false && $raw !== '') {
            try {
                $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($j) && isset($j['requests']) && is_array($j['requests'])) {
                    foreach ($j['requests'] as $r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        $doc['requests'][] = [
                            'id' => (string) ($r['id'] ?? ''),
                            'editor' => strtolower(trim((string) ($r['editor'] ?? ''))),
                            'date' => (string) ($r['date'] ?? ''),
                            'leave_part' => akh_editor_leave_part_normalize((string) ($r['leave_part'] ?? 'full')),
                            'note' => trim((string) ($r['note'] ?? '')),
                            'status' => (string) ($r['status'] ?? 'pending'),
                            'created_at' => (int) ($r['created_at'] ?? 0),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $doc = ['requests' => []];
            }
        }
        foreach ($doc['requests'] as $r) {
            if (($r['editor'] ?? '') === $key && ($r['date'] ?? '') === $ymd && ($r['status'] ?? '') === 'pending') {
                return 'You already have a pending request for that date.';
            }
        }
        $id = 'lv_' . bin2hex(random_bytes(8));
        $doc['requests'][] = [
            'id' => $id,
            'editor' => $key,
            'date' => $ymd,
            'leave_part' => $leavePart,
            'note' => $note,
            'status' => 'pending',
            'created_at' => time(),
        ];
        $max = 2000;
        if (count($doc['requests']) > $max) {
            $doc['requests'] = array_slice($doc['requests'], -$max);
        }
        $out = json_encode($doc, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $out);
        fflush($fp);
    } catch (\Throwable $e) {
        return 'Could not save leave request.';
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return null;
}

function akh_editor_leave_set_status(string $id, string $status): bool
{
    if ($status !== 'approved' && $status !== 'rejected') {
        return false;
    }
    $path = akh_editor_leave_file();
    if (!is_file($path)) {
        return false;
    }
    $fp = @fopen($path, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if ($fp !== false) {
            fclose($fp);
        }

        return false;
    }
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $doc = ['requests' => []];
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['requests']) && is_array($j['requests'])) {
                foreach ($j['requests'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $doc['requests'][] = [
                        'id' => (string) ($r['id'] ?? ''),
                        'editor' => strtolower(trim((string) ($r['editor'] ?? ''))),
                        'date' => (string) ($r['date'] ?? ''),
                            'leave_part' => akh_editor_leave_part_normalize((string) ($r['leave_part'] ?? 'full')),
                        'note' => trim((string) ($r['note'] ?? '')),
                        'status' => (string) ($r['status'] ?? 'pending'),
                        'created_at' => (int) ($r['created_at'] ?? 0),
                    ];
                }
            }
        }
        $found = false;
        foreach ($doc['requests'] as &$r) {
            if (($r['id'] ?? '') === $id && ($r['status'] ?? '') === 'pending') {
                $r['status'] = $status;
                $found = true;
                break;
            }
        }
        unset($r);
        if (!$found) {
            return false;
        }
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

/**
 * @return list<array{id: string, editor: string, date: string, leave_part: string, note: string, status: string, created_at: int}>
 */
function akh_editor_leave_for_editor(string $editor): array
{
    $key = strtolower(trim($editor));
    $list = [];
    foreach (akh_editor_leave_read()['requests'] as $r) {
        if (($r['editor'] ?? '') === $key) {
            $list[] = $r;
        }
    }
    usort($list, static fn (array $a, array $b): int => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

    return $list;
}
