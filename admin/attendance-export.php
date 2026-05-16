<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance-report.php';
require_once AKH_ROOT . '/includes/admin-attendance-export.php';

akh_require_admin();

$y = max(2000, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$m = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));

$report = akh_editor_attendance_month_report($y, $m);

if ($format === 'xls' || $format === 'excel') {
    akh_admin_attendance_export_excel($report);
    exit;
}
if ($format === 'pdf') {
    akh_admin_attendance_export_pdf_html($report);
    exit;
}

akh_admin_attendance_export_csv($report);
