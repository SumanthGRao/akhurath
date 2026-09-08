<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-dashboard-auth.php';
require_once AKH_ROOT . '/includes/whatsapp-tasks-export.php';

akh_require_wa_dashboard();

@set_time_limit(120);

try {
    $year = (int) ($_GET['year'] ?? 0);
    $month = (int) ($_GET['month'] ?? 0);
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        $now = new DateTimeImmutable('now', akh_site_timezone());
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
    }

    $dateField = trim((string) ($_GET['field'] ?? 'created'));
    $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));

    if ($format === 'xls' || $format === 'excel') {
        akh_wa_tasks_export_excel($year, $month, $dateField);
        exit;
    }

    if ($format === 'pdf') {
        akh_wa_tasks_export_pdf_html($year, $month, $dateField);
        exit;
    }

    akh_wa_tasks_export_csv($year, $month, $dateField);
} catch (Throwable $e) {
    error_log('whatsapp/export.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Export failed. Please try again or contact support.';
}
