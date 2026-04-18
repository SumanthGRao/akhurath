<?php

declare(strict_types=1);

require_once __DIR__ . '/editor-attendance.php';
require_once __DIR__ . '/editor-auth.php';
require_once __DIR__ . '/editor-leave.php';

/** Below this many hours worked (with presence), the day is highlighted red. */
const AKH_ATTENDANCE_EXPECTED_HOURS = 8;

/** Count as a “full shift” day when worked time reaches this many hours. */
const AKH_ATTENDANCE_FULL_SHIFT_HOURS = 9;

/** Cap an unclosed clock-in at this many hours for reporting only (Mon–Fri). */
const AKH_ATTENDANCE_OPEN_SHIFT_CAP_SECONDS = AKH_ATTENDANCE_FULL_SHIFT_HOURS * 3600;

/** Saturday: expected and “full shift” are half of weekday targets (same ratio as Mon–Fri). */
function akh_editor_attendance_expected_seconds_for_weekday(int $w): int
{
    if ($w === 0) {
        return 0;
    }
    if ($w === 6) {
        return (int) (AKH_ATTENDANCE_EXPECTED_HOURS * 3600 / 2);
    }

    return AKH_ATTENDANCE_EXPECTED_HOURS * 3600;
}

function akh_editor_attendance_full_shift_seconds_for_weekday(int $w): int
{
    if ($w === 0) {
        return 0;
    }
    if ($w === 6) {
        return (int) (AKH_ATTENDANCE_FULL_SHIFT_HOURS * 3600 / 2);
    }

    return AKH_ATTENDANCE_FULL_SHIFT_HOURS * 3600;
}

/**
 * @return list<array{editor: string, type: string, at: int}>
 */
function akh_editor_attendance_events_sorted(): array
{
    $doc = akh_editor_attendance_read_doc();
    $ev = $doc['events'] ?? [];
    if (!is_array($ev)) {
        return [];
    }
    usort($ev, static fn (array $a, array $b): int => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));

    return $ev;
}

/**
 * Closed + open (capped) work intervals for an editor.
 *
 * @return list<array{in: int, out: int, open: bool}>
 */
function akh_editor_attendance_work_intervals(string $editor, array $eventsSorted): array
{
    $key = strtolower(trim($editor));
    if ($key === '') {
        return [];
    }
    $intervals = [];
    $openIn = null;
    foreach ($eventsSorted as $e) {
        if (($e['editor'] ?? '') !== $key) {
            continue;
        }
        $ty = (string) ($e['type'] ?? '');
        $at = (int) ($e['at'] ?? 0);
        if ($at < 1) {
            continue;
        }
        if ($ty === 'clock_in') {
            $openIn = $at;
        } elseif ($ty === 'clock_out' && $openIn !== null) {
            if ($at > $openIn) {
                $intervals[] = ['in' => $openIn, 'out' => $at, 'open' => false];
            }
            $openIn = null;
        }
    }
    if ($openIn !== null) {
        $wOpen = (int) date('w', $openIn);
        $capSec = $wOpen === 6
            ? (int) (AKH_ATTENDANCE_FULL_SHIFT_HOURS * 3600 / 2)
            : AKH_ATTENDANCE_OPEN_SHIFT_CAP_SECONDS;
        $capOut = min(time(), $openIn + $capSec);
        if ($capOut > $openIn) {
            $intervals[] = ['in' => $openIn, 'out' => $capOut, 'open' => true];
        }
    }

    return $intervals;
}

/**
 * @param list<array{in: int, out: int, open: bool}> $intervals
 * @return array<string, int> ymd => seconds
 */
function akh_editor_attendance_seconds_by_day(array $intervals, int $monthStart, int $monthEnd): array
{
    $byDay = [];
    foreach ($intervals as $iv) {
        $a = (int) ($iv['in'] ?? 0);
        $b = (int) ($iv['out'] ?? 0);
        if ($b <= $a) {
            continue;
        }
        $a = max($a, $monthStart);
        $b = min($b, $monthEnd);
        if ($b <= $a) {
            continue;
        }
        for ($t = $a; $t < $b; ) {
            $ymd = date('Y-m-d', $t);
            $nextMid = strtotime($ymd . ' 00:00:00 +1 day');
            if ($nextMid === false) {
                break;
            }
            $segEnd = min($b, $nextMid);
            $byDay[$ymd] = ($byDay[$ymd] ?? 0) + ($segEnd - $t);
            $t = $segEnd;
        }
    }

    return $byDay;
}

/**
 * Dates in month with at least one clock_in (local).
 *
 * @param list<array{editor: string, type: string, at: int}> $eventsSorted
 * @return array<string, true>
 */
function akh_editor_attendance_clock_in_dates(string $editor, array $eventsSorted, int $monthStart, int $monthEnd): array
{
    $key = strtolower(trim($editor));
    $set = [];
    foreach ($eventsSorted as $e) {
        if (($e['editor'] ?? '') !== $key || ($e['type'] ?? '') !== 'clock_in') {
            continue;
        }
        $at = (int) ($e['at'] ?? 0);
        if ($at < $monthStart || $at > $monthEnd) {
            continue;
        }
        $set[date('Y-m-d', $at)] = true;
    }

    return $set;
}

/**
 * @return array{
 *   year: int,
 *   month: int,
 *   today_ymd: string,
 *   editors: list<array{
 *     username: string,
 *     working_days: int,
 *     sundays: int,
 *     clock_in_days: int,
 *     present_working_days: int,
 *     days_9h_plus: int,
 *     days_under_8h: int,
 *     leave_days: int,
 *     excused_leave_days: int,
 *     leave_pending_in_month: int,
 *     cells: list<array{ymd: string, dom: int, w: int, label: string, seconds: int, clock_in: bool, sunday: bool, leave: bool, under8: bool, nine_plus: bool, future: bool, today: bool, expected_sec: int, full_shift_sec: int, pleave: bool}>,
 *     bars: array{present_pct: float, clock_pct: float, nine_pct: float}
 *   }>
 * }
 */
function akh_editor_attendance_month_report(int $year, int $month): array
{
    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));
    $monthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
    if ($monthStart === false) {
        $monthStart = time();
    }
    $monthEndStr = date('Y-m-t 23:59:59', $monthStart);
    $monthEnd = is_string($monthEndStr) ? strtotime($monthEndStr) : false;
    if ($monthEnd === false) {
        $monthEnd = $monthStart + 86400 * 30;
    }
    $todayYmd = date('Y-m-d');

    $events = akh_editor_attendance_events_sorted();
    $editors = array_keys(akh_editor_accounts());
    sort($editors, SORT_STRING);

    $out = [
        'year' => $year,
        'month' => $month,
        'today_ymd' => $todayYmd,
        'editors' => [],
    ];

    foreach ($editors as $editor) {
        $approvedSet = akh_editor_leave_approved_dates_in_month($editor, $year, $month);
        $leaveMonthCounts = akh_editor_leave_counts_for_month_editor($editor, $year, $month);
        $intervals = akh_editor_attendance_work_intervals($editor, $events);
        $secondsByDay = akh_editor_attendance_seconds_by_day($intervals, $monthStart, $monthEnd);
        $clockInDates = akh_editor_attendance_clock_in_dates($editor, $events, $monthStart, $monthEnd);

        $cells = [];
        $workingDays = 0;
        $sundays = 0;
        $leaveDays = 0;
        $under8 = 0;
        $ninePlus = 0;
        $presentWorkingDays = 0;
        $clockInDays = count($clockInDates);

        $dom = (int) date('t', $monthStart);
        for ($d = 1; $d <= $dom; ++$d) {
            $ymd = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $ts = strtotime($ymd . ' 12:00:00');
            $w = $ts !== false ? (int) date('w', $ts) : 0;
            $isSunday = $w === 0;
            $isFuture = $ymd > $todayYmd;
            $isToday = $ymd === $todayYmd;
            $seconds = (int) ($secondsByDay[$ymd] ?? 0);
            $hadClockIn = isset($clockInDates[$ymd]);
            $isWorkingDay = !$isSunday;
            $expectedSec = akh_editor_attendance_expected_seconds_for_weekday($w);
            $fullShiftSec = akh_editor_attendance_full_shift_seconds_for_weekday($w);

            if ($isSunday) {
                ++$sundays;
            } else {
                ++$workingDays;
            }

            $pleave = isset($approvedSet[$ymd]);

            $leave = false;
            if ($isWorkingDay && !$isFuture && $ymd < $todayYmd) {
                if (!$pleave && $seconds < 1 && !$hadClockIn) {
                    $leave = true;
                    ++$leaveDays;
                }
            }

            $under = false;
            if (!$pleave && $isWorkingDay && $expectedSec > 0 && $seconds > 0 && $seconds < $expectedSec) {
                if ($ymd < $todayYmd) {
                    $under = true;
                    ++$under8;
                } elseif ($isToday && !akh_editor_attendance_is_clocked_in($editor)) {
                    $under = true;
                    ++$under8;
                }
            }

            $nine = $fullShiftSec > 0 && $seconds >= $fullShiftSec;
            if ($nine) {
                ++$ninePlus;
            }

            if ($isWorkingDay && ($seconds > 0 || $hadClockIn)) {
                ++$presentWorkingDays;
            }

            $cells[] = [
                'ymd' => $ymd,
                'dom' => $d,
                'w' => $w,
                'label' => date('D', $ts !== false ? $ts : time()),
                'seconds' => $seconds,
                'clock_in' => $hadClockIn,
                'sunday' => $isSunday,
                'leave' => $leave,
                'under8' => $under,
                'nine_plus' => $nine,
                'future' => $isFuture,
                'today' => $isToday,
                'expected_sec' => $expectedSec,
                'full_shift_sec' => $fullShiftSec,
                'pleave' => $pleave,
            ];
        }

        $wd = max(1, $workingDays);
        $bars = [
            'present_pct' => min(100, round($presentWorkingDays / $wd * 100, 1)),
            'clock_pct' => min(100, round($clockInDays / $wd * 100, 1)),
            'nine_pct' => min(100, round($ninePlus / $wd * 100, 1)),
        ];

        $out['editors'][] = [
            'username' => $editor,
            'working_days' => $workingDays,
            'sundays' => $sundays,
            'clock_in_days' => $clockInDays,
            'present_working_days' => $presentWorkingDays,
            'days_9h_plus' => $ninePlus,
            'days_under_8h' => $under8,
            'leave_days' => $leaveDays,
            'excused_leave_days' => count($approvedSet),
            'leave_pending_in_month' => $leaveMonthCounts['pending'],
            'cells' => $cells,
            'bars' => $bars,
        ];
    }

    return $out;
}

function akh_editor_attendance_format_hours(int $sec): string
{
    if ($sec <= 0) {
        return '—';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);

    return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
}

/**
 * Today’s punches for the admin list row (highlight In / Out separately).
 *
 * @return array{kind: string, in: string, out: string} kind: empty|on_shift|full|in_only
 */
function akh_editor_attendance_today_punch_parts(string $editor): array
{
    $today = date('Y-m-d');
    $start = strtotime($today . ' 00:00:00');
    $end = strtotime($today . ' 23:59:59');
    if ($start === false || $end === false) {
        return ['kind' => 'empty', 'in' => '', 'out' => ''];
    }
    $key = strtolower(trim($editor));
    $events = akh_editor_attendance_events_sorted();
    $ins = [];
    $outs = [];
    foreach ($events as $e) {
        if (($e['editor'] ?? '') !== $key) {
            continue;
        }
        $at = (int) ($e['at'] ?? 0);
        if ($at < $start || $at > $end) {
            continue;
        }
        if (($e['type'] ?? '') === 'clock_in') {
            $ins[] = $at;
        }
        if (($e['type'] ?? '') === 'clock_out') {
            $outs[] = $at;
        }
    }
    sort($ins);
    sort($outs);
    $on = akh_editor_attendance_is_clocked_in($editor);
    $firstIn = $ins[0] ?? null;
    $lastOut = $outs !== [] ? $outs[count($outs) - 1] : null;
    if ($firstIn === null) {
        return ['kind' => 'empty', 'in' => '', 'out' => ''];
    }
    $inStr = date('g:i A', $firstIn);
    if ($on) {
        return ['kind' => 'on_shift', 'in' => $inStr, 'out' => ''];
    }
    if ($lastOut !== null && $lastOut >= $firstIn) {
        return ['kind' => 'full', 'in' => $inStr, 'out' => date('g:i A', $lastOut)];
    }

    return ['kind' => 'in_only', 'in' => $inStr, 'out' => ''];
}

/** One-line summary for the list view (plain text). */
function akh_editor_attendance_today_summary(string $editor): string
{
    $p = akh_editor_attendance_today_punch_parts($editor);
    if ($p['kind'] === 'empty') {
        return 'No punches today';
    }
    if ($p['kind'] === 'on_shift') {
        return 'In ' . $p['in'] . ' · on shift';
    }
    if ($p['kind'] === 'full') {
        return 'In ' . $p['in'] . ' · Out ' . $p['out'];
    }

    return 'In ' . $p['in'];
}

/**
 * @return array{pending: int, approved: int}
 */
function akh_editor_leave_counts_for_month_editor(string $editor, int $year, int $month): array
{
    $key = strtolower(trim($editor));
    $pfx = sprintf('%04d-%02d-', $year, $month);
    $pending = 0;
    $approved = 0;
    foreach (akh_editor_leave_read()['requests'] as $r) {
        if (($r['editor'] ?? '') !== $key) {
            continue;
        }
        $d = (string) ($r['date'] ?? '');
        if (!str_starts_with($d, $pfx)) {
            continue;
        }
        if (($r['status'] ?? '') === 'pending') {
            ++$pending;
        }
        if (($r['status'] ?? '') === 'approved') {
            ++$approved;
        }
    }

    return ['pending' => $pending, 'approved' => $approved];
}
