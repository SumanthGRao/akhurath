<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-tasks.php';
require_once __DIR__ . '/whatsapp-task-sync.php';
require_once __DIR__ . '/site-datetime.php';

/**
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
 */
function akh_wa_tasks_export_month_range(int $year, int $month): array
{
    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));
    $tz = akh_site_timezone();
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
    $end = $start->modify('+1 month');

    return [$start, $end];
}

function akh_wa_tasks_export_normalize_date_field(string $field): string
{
    return strtolower(trim($field)) === 'updated' ? 'updated_at' : 'created_at';
}

/**
 * @return list<array<string, mixed>>
 */
function akh_wa_tasks_list_for_export(int $year, int $month, string $dateField = 'created'): array
{
    if (!akh_wa_tasks_table_exists()) {
        return [];
    }

    $column = akh_wa_tasks_export_normalize_date_field($dateField);
    [$start, $end] = akh_wa_tasks_export_month_range($year, $month);

    try {
        $sql = 'SELECT * FROM whatsapp_tasks WHERE ' . $column . ' >= ? AND ' . $column . ' < ? ORDER BY ' . $column . ' DESC, id DESC';
        $st = akh_db()->prepare($sql);
        $st->execute([
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('akh_wa_tasks_list_for_export: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return list<array<string, string>>
 */
function akh_wa_tasks_export_rows(int $year, int $month, string $dateField = 'created'): array
{
    $editors = akh_wa_editors_for_select();
    $out = [];

    foreach (akh_wa_tasks_list_for_export($year, $month, $dateField) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $json = akh_wa_task_row_for_json($row, $editors);
        $taskCode = (string) ($json['task_code'] ?? '');
        $progress = '';
        $updates = akh_task_status_updates_for_display($taskCode, 1);
        if ($updates !== []) {
            $latest = $updates[0];
            $progress = trim((string) ($latest['status'] ?? '') . ' · ' . (string) ($latest['created_at_label'] ?? ''), ' ·');
        } elseif (trim((string) ($json['last_progress_label'] ?? '')) !== '') {
            $progress = (string) $json['last_progress_label'];
        }

        $createdLabel = trim((string) ($json['created_at_label'] ?? ''));
        if ($createdLabel === '') {
            $createdLabel = trim((string) ($json['created_at'] ?? ''));
        }
        $updatedLabel = trim((string) ($json['updated_at_label'] ?? ''));
        if ($updatedLabel === '') {
            $updatedLabel = trim((string) ($json['updated_at'] ?? ''));
        }

        $out[] = [
            'task_code' => $taskCode,
            'customer_name' => (string) ($json['customer_name'] ?? ''),
            'project_name' => (string) ($json['project_name'] ?? ''),
            'task_type' => (string) ($json['task_type'] ?? ''),
            'status' => (string) ($json['status_label'] ?? ''),
            'editor' => (string) ($json['assigned_editor_name'] ?? ''),
            'phone' => (string) ($json['phone'] ?? ''),
            'created_at' => $createdLabel !== '' ? $createdLabel : '—',
            'updated_at' => $updatedLabel !== '' ? $updatedLabel : '—',
            'last_progress' => $progress !== '' ? $progress : 'No update yet',
        ];
    }

    return $out;
}

/** @return list<string> */
function akh_wa_tasks_export_headers(): array
{
    return [
        'Task ID',
        'Customer',
        'Project',
        'Type',
        'Status',
        'Editor',
        'Phone',
        'Assigned',
        'Updated',
        'Last progress',
    ];
}

/** @return list<string> */
function akh_wa_tasks_export_row_keys(): array
{
    return [
        'task_code',
        'customer_name',
        'project_name',
        'task_type',
        'status',
        'editor',
        'phone',
        'created_at',
        'updated_at',
        'last_progress',
    ];
}

function akh_wa_tasks_export_month_label(int $year, int $month): string
{
    $ts = strtotime(sprintf('%04d-%02d-01', $year, $month));

    return $ts !== false ? date('F Y', $ts) : sprintf('%04d-%02d', $year, $month);
}

function akh_wa_tasks_export_filename(int $year, int $month, string $ext): string
{
    return 'whatsapp-tasks-' . $year . '-' . sprintf('%02d', $month) . '.' . $ext;
}

function akh_wa_tasks_export_csv(int $year, int $month, string $dateField = 'created'): void
{
    $rows = akh_wa_tasks_export_rows($year, $month, $dateField);
    $monthLabel = akh_wa_tasks_export_month_label($year, $month);
    $fieldLabel = akh_wa_tasks_export_normalize_date_field($dateField) === 'updated_at' ? 'Last updated' : 'Assigned / created';
    $filename = akh_wa_tasks_export_filename($year, $month, 'csv');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, ['Month', $monthLabel]);
    fputcsv($out, ['Filter by', $fieldLabel]);
    fputcsv($out, akh_wa_tasks_export_headers());
    foreach ($rows as $row) {
        $line = [];
        foreach (akh_wa_tasks_export_row_keys() as $key) {
            $line[] = (string) ($row[$key] ?? '');
        }
        fputcsv($out, $line);
    }
    fclose($out);
}

function akh_wa_tasks_export_excel(int $year, int $month, string $dateField = 'created'): void
{
    $rows = akh_wa_tasks_export_rows($year, $month, $dateField);
    $monthLabel = akh_wa_tasks_export_month_label($year, $month);
    $fieldLabel = akh_wa_tasks_export_normalize_date_field($dateField) === 'updated_at' ? 'Last updated' : 'Assigned / created';
    $filename = akh_wa_tasks_export_filename($year, $month, 'xls');
    $headers = akh_wa_tasks_export_headers();
    $keys = akh_wa_tasks_export_row_keys();

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<tr><th colspan="' . count($headers) . '">' . htmlspecialchars($monthLabel . ' — WhatsApp tasks (' . $fieldLabel . ')', ENT_QUOTES, 'UTF-8') . '</th></tr>';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($keys as $key) {
            echo '<td>' . htmlspecialchars((string) ($row[$key] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
}

function akh_wa_tasks_export_pdf_html(int $year, int $month, string $dateField = 'created'): void
{
    $rows = akh_wa_tasks_export_rows($year, $month, $dateField);
    $monthLabel = akh_wa_tasks_export_month_label($year, $month);
    $fieldLabel = akh_wa_tasks_export_normalize_date_field($dateField) === 'updated_at' ? 'Last updated' : 'Assigned / created';
    $site = SITE_NAME;
    $tz = AKH_SITE_TIMEZONE === 'Asia/Kolkata' ? 'IST' : AKH_SITE_TIMEZONE;
    $headers = akh_wa_tasks_export_headers();
    $keys = akh_wa_tasks_export_row_keys();

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>WhatsApp tasks <?php echo htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    body { font-family: system-ui, sans-serif; font-size: 10px; color: #111; margin: 1rem; }
    h1 { font-size: 18px; margin: 0 0 0.25rem; }
    p { margin: 0 0 1rem; color: #444; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 5px; text-align: left; vertical-align: top; }
    th { background: #f0ebe4; }
    tr:nth-child(even) td { background: #faf8f5; }
    .noprint { margin-bottom: 1rem; }
    @media print {
      .noprint { display: none; }
      body { margin: 0.4rem; }
    }
  </style>
</head>
<body>
  <p class="noprint"><button type="button" onclick="window.print()">Save as PDF / Print</button></p>
  <h1><?php echo htmlspecialchars($site, ENT_QUOTES, 'UTF-8'); ?> — WhatsApp tasks</h1>
  <p><?php echo htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8'); ?> · Filter: <?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?> · <?php echo count($rows); ?> task(s)</p>
  <table>
    <thead>
      <tr>
        <?php foreach ($headers as $header): ?>
          <th><?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <?php foreach ($keys as $key): ?>
            <td><?php echo htmlspecialchars((string) ($row[$key] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
</body>
</html>
    <?php
}
