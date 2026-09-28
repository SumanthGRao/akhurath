<?php

declare(strict_types=1);

require_once __DIR__ . '/simple-pdf.php';
require_once __DIR__ . '/invoices.php';

/**
 * @param array<string, mixed> $invoice Must include lines[]
 */
function akh_invoice_pdf_bytes(array $invoice): string
{
    $profile = akh_invoice_studio_profile();
    $pdf = new AkhSimplePdf();
    $y = 48.0;
    $pdf->text(48, $y, (string) ($profile['name'] ?? SITE_NAME), 16);
    $y += 20;
    if (trim((string) ($profile['address'] ?? '')) !== '') {
        foreach (preg_split('/\r\n|\r|\n/', (string) $profile['address']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pdf->text(48, $y, $line, 10);
            $y += 14;
        }
    }
    if (trim((string) ($profile['gstin'] ?? '')) !== '') {
        $pdf->text(48, $y, 'GSTIN: ' . (string) $profile['gstin'], 10);
        $y += 14;
    }
    if (trim((string) ($profile['email'] ?? '')) !== '') {
        $pdf->text(48, $y, (string) $profile['email'], 10);
        $y += 18;
    }

    $pdf->text(360, 48, 'INVOICE', 18);
    $pdf->text(360, 72, 'No. ' . (string) ($invoice['invoice_number'] ?? ''), 11);
    $issued = (string) ($invoice['issued_at'] ?? '');
    if ($issued !== '') {
        $pdf->text(360, 88, 'Issued: ' . $issued, 10);
    }
    $due = (string) ($invoice['due_at'] ?? '');
    if ($due !== '') {
        $pdf->text(360, 102, 'Due: ' . $due, 10);
    }

    $y = max($y + 10, 130);
    $pdf->text(48, $y, 'Bill to', 11);
    $y += 16;
    $billName = trim((string) ($invoice['client_display_name'] ?? ''));
    if ($billName === '') {
        $billName = (string) ($invoice['client_username'] ?? '');
    }
    $pdf->text(48, $y, $billName, 11);
    $y += 14;
    $billEmail = trim((string) ($invoice['client_email'] ?? ''));
    if ($billEmail !== '') {
        $pdf->text(48, $y, $billEmail, 10);
        $y += 14;
    }
    $y += 12;

    $pdf->text(48, $y, 'Description', 10);
    $pdf->text(320, $y, 'Qty', 10);
    $pdf->text(360, $y, 'Rate', 10);
    $pdf->text(440, $y, 'Amount', 10);
    $y += 16;

    $currency = (string) ($invoice['currency'] ?? 'INR');
    $lines = is_array($invoice['lines'] ?? null) ? $invoice['lines'] : [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $desc = (string) ($line['description'] ?? '');
        $code = trim((string) ($line['task_code'] ?? ''));
        if ($code !== '') {
            $desc = $code . ' — ' . $desc;
        }
        $qty = (int) ($line['quantity'] ?? 1);
        $unit = (int) ($line['unit_amount_paise'] ?? 0);
        $total = (int) ($line['line_total_paise'] ?? 0);
        $pdf->text(48, $y, mb_substr($desc, 0, 72), 9);
        $pdf->text(320, $y, (string) $qty, 9);
        $pdf->text(360, $y, akh_invoice_money_format_paise($unit, $currency), 9);
        $pdf->text(440, $y, akh_invoice_money_format_paise($total, $currency), 9);
        $y += 14;
        if ($y > 720) {
            break;
        }
    }

    $y += 10;
    $subtotal = (int) ($invoice['subtotal_paise'] ?? 0);
    $tax = (int) ($invoice['tax_paise'] ?? 0);
    $taxBps = (int) ($invoice['tax_rate_bps'] ?? 0);
    $grand = (int) ($invoice['total_paise'] ?? 0);
    $pdf->text(360, $y, 'Subtotal: ' . akh_invoice_money_format_paise($subtotal, $currency), 10);
    $y += 14;
    if ($taxBps > 0) {
        $pct = number_format($taxBps / 100, 2) . '%';
        $pdf->text(360, $y, 'Tax (' . $pct . '): ' . akh_invoice_money_format_paise($tax, $currency), 10);
        $y += 14;
    }
    $pdf->text(360, $y, 'Total: ' . akh_invoice_money_format_paise($grand, $currency), 12);

    $notes = trim((string) ($invoice['notes'] ?? ''));
    if ($notes !== '') {
        $y += 28;
        $pdf->text(48, $y, 'Notes', 10);
        $y += 14;
        foreach (preg_split('/\r\n|\r|\n/', $notes) ?: [] as $nLine) {
            $nLine = trim($nLine);
            if ($nLine === '') {
                continue;
            }
            $pdf->text(48, $y, mb_substr($nLine, 0, 90), 9);
            $y += 12;
        }
    }

    return $pdf->bytes();
}

function akh_invoice_pdf_filename(array $invoice): string
{
    $num = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'invoice')) ?? 'invoice';

    return $num . '.pdf';
}
