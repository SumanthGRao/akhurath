<?php

declare(strict_types=1);

require_once __DIR__ . '/editor-attendance-report.php';

/**
 * Flat rows for spreadsheet / PDF export (one row per editor per day).
 *
 * @return list<array<string, string|int>>
 */
function akh_admin_attendance_export_rows(array $report): array
{
    $rows = [];
    foreach ($report['editors'] as $ed) {
        $editor = (string) ($ed['username'] ?? '');
        foreach ($ed['cells'] as $cell) {
            $status = 'Working day';
            if (!empty($cell['sunday'])) {
                $status = 'Sunday';
            } elseif (!empty($cell['future'])) {
                $status = 'Future';
            } elseif (!empty($cell['pleave'])) {
                $status = 'Approved leave (' . (string) ($cell['leave_part'] ?? 'full') . ')';
            } elseif (!empty($cell['leave'])) {
                $status = 'Absent';
            } elseif (!empty($cell['under8'])) {
                $status = 'Short hours';
            } elseif (!empty($cell['nine_plus'])) {
                $status = 'Full shift';
            } elseif ((int) ($cell['seconds'] ?? 0) > 0) {
                $status = 'Present';
            }

            $rows[] = [
                'editor' => $editor,
                'date' => (string) ($cell['ymd'] ?? ''),
                'day' => (string) ($cell['label'] ?? ''),
                'hours_worked' => akh_editor_attendance_format_hours((int) ($cell['seconds'] ?? 0)),
                'clock_in' => (string) ($cell['punch_in'] ?? '—'),
                'clock_out' => (string) ($cell['punch_out'] ?? '—'),
                'status' => $status,
            ];
        }

        $rows[] = [
            'editor' => $editor,
            'date' => '—',
            'day' => 'MONTH TOTAL',
            'hours_worked' => 'Present ' . (int) ($ed['present_working_days'] ?? 0)
                . ' / ' . (int) ($ed['working_days'] ?? 0)
                . ' · Short ' . (int) ($ed['days_under_8h'] ?? 0)
                . ' · Absent ' . (int) ($ed['leave_days'] ?? 0)
                . ' · Leave ok ' . akh_editor_attendance_format_leave_units((float) ($ed['excused_leave_days'] ?? 0)),
            'clock_in' => '—',
            'clock_out' => '—',
            'status' => 'Summary',
        ];
    }

    return $rows;
}

function akh_admin_attendance_export_csv(array $report): void
{
    $rows = akh_admin_attendance_export_rows($report);
    $monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());
    $filename = 'attendance-' . $report['year'] . '-' . sprintf('%02d', $report['month']) . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, ['Month', $monthLabel]);
    fputcsv($out, ['Editor', 'Date', 'Day', 'Hours worked', 'Clock in', 'Clock out', 'Status']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) $row['editor'],
            (string) $row['date'],
            (string) $row['day'],
            (string) $row['hours_worked'],
            (string) $row['clock_in'],
            (string) $row['clock_out'],
            (string) $row['status'],
        ]);
    }
    fclose($out);
}

function akh_admin_attendance_export_excel(array $report): void
{
    $rows = akh_admin_attendance_export_rows($report);
    $monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());
    $filename = 'attendance-' . $report['year'] . '-' . sprintf('%02d', $report['month']) . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1"><tr><th colspan="7">' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . ' — Editor attendance</th></tr>';
    echo '<tr><th>Editor</th><th>Date</th><th>Day</th><th>Hours worked</th><th>Clock in</th><th>Clock out</th><th>Status</th></tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (['editor', 'date', 'day', 'hours_worked', 'clock_in', 'clock_out', 'status'] as $key) {
            echo '<td>' . htmlspecialchars((string) $row[$key], ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
}

function akh_admin_attendance_export_pdf_html(array $report): void
{
    $rows = akh_admin_attendance_export_rows($report);
    $monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());
    $site = SITE_NAME;
    $tz = AKH_SITE_TIMEZONE === 'Asia/Kolkata' ? 'IST' : AKH_SITE_TIMEZONE;

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Attendance <?php echo htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    body { font-family: system-ui, sans-serif; font-size: 11px; color: #111; margin: 1rem; }
    h1 { font-size: 18px; margin: 0 0 0.25rem; }
    p { margin: 0 0 1rem; color: #444; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #f0ebe4; }
    tr:nth-child(even) td { background: #faf8f5; }
    .summary td { font-weight: 600; background: #efe8dc; }
    .noprint { margin-bottom: 1rem; }
    @media print {
      .noprint { display: none; }
      body { margin: 0.4rem; }
    }
  </style>
</head>
<body>
  <p class="noprint"><button type="button" onclick="window.print()">Save as PDF / Print</button></p>
  <h1><?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?> — Attendance</h1>
  <p><?php echo htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8'); ?> · Times <?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?></p>
  <table>
    <thead>
      <tr>
        <th>Editor</th><th>Date</th><th>Day</th><th>Hours</th><th>In</th><th>Out</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr class="<?php echo ($row['status'] ?? '') === 'Summary' ? 'summary' : ''; ?>">
          <td><?php echo htmlspecialchars((string) $row['editor'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $row['date'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $row['day'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $row['hours_worked'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $row['clock_in'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $row['clock_out'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
</body>
</html>
    <?php
}
