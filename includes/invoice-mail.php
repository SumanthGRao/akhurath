<?php

declare(strict_types=1);

require_once __DIR__ . '/smtp-mail.php';
require_once __DIR__ . '/invoice-pdf.php';
require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/site-notify-mail.php';

/**
 * @return array{ok: bool, error: string, skipped: bool}
 */
function akh_invoice_email_to_client(int $invoiceId, ?string $overrideEmail = null): array
{
    if (!akh_site_notify_smtp_enabled()) {
        return akh_site_notify_skip_result('SMTP is not configured. Set AKH_SMTP_* in includes/config.php.');
    }
    $inv = akh_invoice_get($invoiceId);
    if ($inv === null) {
        return ['ok' => false, 'error' => 'Invoice not found.', 'skipped' => false];
    }
    if ((string) ($inv['status'] ?? '') === 'void') {
        return ['ok' => false, 'error' => 'This invoice was voided.', 'skipped' => false];
    }

    $to = $overrideEmail !== null ? trim($overrideEmail) : trim((string) ($inv['client_email'] ?? ''));
    if ($to === '') {
        $to = (string) (akh_customer_email_get((string) ($inv['client_username'] ?? '')) ?? '');
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Client has no valid email on file.', 'skipped' => false];
    }

    $pdf = akh_invoice_pdf_bytes($inv);
    $filename = akh_invoice_pdf_filename($inv);
    $num = (string) ($inv['invoice_number'] ?? '');
    $total = akh_invoice_money_format_paise((int) ($inv['total_paise'] ?? 0), (string) ($inv['currency'] ?? 'INR'));
    $subject = 'Invoice ' . $num . ' — ' . SITE_NAME;
    $body = 'Hello' . "\n\n"
        . 'Please find attached invoice ' . $num . ' for ' . $total . '.' . "\n\n"
        . 'If you have any questions, reply to this email or contact us at ' . CONTACT_EMAIL . '.' . "\n\n"
        . 'Thank you,' . "\n"
        . SITE_NAME . "\n";

    $result = akh_smtp_send($to, $subject, $body, [
        [
            'filename' => $filename,
            'mimetype' => 'application/pdf',
            'body' => $pdf,
        ],
    ]);
    if (!($result['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Mail failed.'),
            'skipped' => false,
        ];
    }

    if ((string) ($inv['status'] ?? '') === 'draft') {
        akh_invoice_mark_sent($invoiceId);
    }

    return ['ok' => true, 'error' => '', 'skipped' => false];
}
