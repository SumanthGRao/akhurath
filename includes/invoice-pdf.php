<?php

declare(strict_types=1);

require_once __DIR__ . '/simple-pdf.php';
require_once __DIR__ . '/invoice-template.php';
require_once __DIR__ . '/invoice-services.php';

/** Akhurath invoice PDF palette (matches HTML template). */
final class AkhInvoicePdfTheme
{
    public const HEADER_R = 0.184;

    public const HEADER_G = 0.133;

    public const HEADER_B = 0.094;

    public const GOLD_R = 0.788;

    public const GOLD_G = 0.663;

    public const GOLD_B = 0.384;

    public const INK_R = 0.102;

    public const INK_G = 0.078;

    public const INK_B = 0.063;

    public const MUTED_R = 0.42;

    public const MUTED_G = 0.35;

    public const MUTED_B = 0.28;

    public const PAPER_R = 0.97;

    public const PAPER_G = 0.95;

    public const PAPER_B = 0.91;
}

/**
 * @return list<string>
 */
function akh_invoice_pdf_wrap_text(string $text, int $maxChars = 52): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return [];
    }
    $out = [];
    while (mb_strlen($text) > $maxChars) {
        $chunk = mb_substr($text, 0, $maxChars);
        $break = mb_strrpos($chunk, ' ');
        if ($break !== false && $break > 20) {
            $out[] = mb_substr($text, 0, $break);
            $text = trim(mb_substr($text, $break + 1));
        } else {
            $out[] = $chunk;
            $text = trim(mb_substr($text, $maxChars));
        }
    }
    if ($text !== '') {
        $out[] = $text;
    }

    return $out;
}

/**
 * @return list<string>
 */
function akh_invoice_pdf_wrap_multiline(string $text, int $maxChars = 48): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $paragraph) {
        $paragraph = trim((string) $paragraph);
        if ($paragraph === '') {
            continue;
        }
        $out = array_merge($out, akh_invoice_pdf_wrap_text($paragraph, $maxChars));
    }

    return $out;
}

/**
 * @param array<string, mixed> $invoice Must include lines[]
 */
function akh_invoice_pdf_bytes(array $invoice): string
{
    $vm = akh_invoice_template_view_model($invoice);
    $profile = $vm['profile'];
    $inv = $vm['invoice'];
    $pdf = new AkhSimplePdf();

    $left = 36.0;
    $right = 559.0;
    $width = $right - $left;
    $headerH = 78.0;

    $pdf->fillRect($left, 0, $width, $headerH, AkhInvoicePdfTheme::HEADER_R, AkhInvoicePdfTheme::HEADER_G, AkhInvoicePdfTheme::HEADER_B);
    $pdf->fillRect($left, $headerH, $width, 3, AkhInvoicePdfTheme::GOLD_R, AkhInvoicePdfTheme::GOLD_G, AkhInvoicePdfTheme::GOLD_B);

    $pdf->text($left + 10, 28, (string) $profile['name'], 15, true, 1, 1, 1);
    $pdf->textRight($right - 10, 26, (string) $vm['title'], 17, true, AkhInvoicePdfTheme::GOLD_R, AkhInvoicePdfTheme::GOLD_G, AkhInvoicePdfTheme::GOLD_B);
    $pdf->textRight($right - 10, 46, (string) ($inv['invoice_number'] ?? ''), 10, false, 0.95, 0.92, 0.88);

    $y = 92.0;
    $addrLines = 0;
    if (trim((string) $profile['address']) !== '') {
        foreach (preg_split('/\r\n|\r|\n/', (string) $profile['address']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pdf->text($left, $y, $line, 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
            $y += 11;
            ++$addrLines;
            if ($addrLines > 3) {
                break;
            }
        }
    }
    if (trim((string) $profile['gstin']) !== '') {
        $pdf->text($left, $y, 'GSTIN: ' . (string) $profile['gstin'], 9, false, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
        $y += 11;
    }
    if (trim((string) $profile['email']) !== '') {
        $pdf->text($left, $y, (string) $profile['email'], 9, false, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
        $y += 11;
    }

    $metaY = 92.0;
    $issued = (string) ($inv['issued_at'] ?? '');
    if ($issued !== '') {
        $pdf->textRight($right, $metaY, 'Date: ' . $issued, 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $metaY += 12;
    }
    $due = (string) ($inv['due_at'] ?? '');
    if ($due !== '') {
        $pdf->textRight($right, $metaY, 'Due: ' . $due, 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $metaY += 12;
    }

    $y = max($y, $metaY) + 10;
    $pdf->fillRect($left, $y, $width, 52, AkhInvoicePdfTheme::PAPER_R, AkhInvoicePdfTheme::PAPER_G, AkhInvoicePdfTheme::PAPER_B);
    $pdf->line($left, $y, $right, $y, 0.5);
    $pdf->text($left + 8, $y + 16, 'Bill to', 8, true, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
    $pdf->text($left + 8, $y + 30, (string) $vm['bill_name'], 12, true, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
    $billLine = 44;
    if ($vm['bill_email'] !== '') {
        $pdf->text($left + 8, $y + $billLine, $vm['bill_email'], 9, false, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
    }

    $y += 62;
    $projectDesc = trim((string) ($vm['project_description'] ?? ''));
    if ($projectDesc === '') {
        $projectDesc = trim((string) ($inv['notes'] ?? ''));
    }
    if ($projectDesc !== '') {
        $pdf->text($left, $y, 'Description', 8, true, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
        $y += 12;
        foreach (akh_invoice_pdf_wrap_multiline($projectDesc, 90) as $pLine) {
            $pdf->text($left, $y, $pLine, 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
            $y += 11;
        }
        $y += 6;
    }
    $tableTop = $y;
    $colNo = $left;
    $colDesc = $left + 24;
    $colQty = 358;
    $colRate = 418;
    $colAmt = $right;

    $pdf->fillRect($left, $tableTop, $width, 20, AkhInvoicePdfTheme::HEADER_R, AkhInvoicePdfTheme::HEADER_G, AkhInvoicePdfTheme::HEADER_B);
    $pdf->text($colNo + 6, $tableTop + 14, '#', 9, true, 1, 1, 1);
    $pdf->text($colDesc, $tableTop + 14, 'Description', 9, true, 1, 1, 1);
    $pdf->text($colQty, $tableTop + 14, 'Qty', 9, true, 1, 1, 1);
    $pdf->text($colRate, $tableTop + 14, 'Rate', 9, true, 1, 1, 1);
    $pdf->textRight($colAmt - 4, $tableTop + 14, 'Amount', 9, true, 1, 1, 1);
    $y = $tableTop + 26;

    foreach ($vm['lines'] as $row) {
        $desc = trim((string) ($row['description'] ?? ''));
        if ($desc === '') {
            $desc = akh_invoice_line_public_description($row);
        }
        $descLines = akh_invoice_pdf_wrap_multiline($desc, 48);
        if ($descLines === []) {
            $descLines = ['Service'];
        }
        $rowH = max(18, 12 + count($descLines) * 11);
        if ($y + $rowH > 700) {
            break;
        }

        $pdf->line($left, $y, $right, $y, 0.35, 0.88, 0.82, 0.76);
        $pdf->text($colNo + 6, $y + 12, (string) $row['no'], 9, false, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
        $lineY = $y + 12;
        foreach ($descLines as $dl) {
            $pdf->text($colDesc, $lineY, $dl, 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
            $lineY += 11;
        }
        $pdf->text($colQty, $y + 12, (string) $row['quantity'], 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $pdf->text($colRate, $y + 12, (string) $row['unit_display'], 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $pdf->textRight($colAmt, $y + 12, (string) $row['line_display'], 9, true, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $y += $rowH;
    }
    $pdf->line($left, $y, $right, $y, 0.8, AkhInvoicePdfTheme::HEADER_R, AkhInvoicePdfTheme::HEADER_G, AkhInvoicePdfTheme::HEADER_B);

    $y += 16;
    $totalsX = 380;
    $pdf->textRight($colAmt, $y, 'Subtotal: ' . (string) $vm['subtotal_display'], 10, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
    $y += 14;
    if ((int) $vm['tax_bps'] > 0) {
        $pdf->textRight($colAmt, $y, (string) $vm['tax_label'] . ': ' . (string) $vm['tax_display'], 10, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $y += 14;
    }
    $pdf->fillRect($totalsX, $y - 4, $right - $totalsX, 22, AkhInvoicePdfTheme::PAPER_R, AkhInvoicePdfTheme::PAPER_G, AkhInvoicePdfTheme::PAPER_B);
    $pdf->textRight($colAmt, $y + 10, 'TOTAL  ' . (string) $vm['total_display'], 12, true, AkhInvoicePdfTheme::HEADER_R, AkhInvoicePdfTheme::HEADER_G, AkhInvoicePdfTheme::HEADER_B);

    $y += 28;
    $pdf->text($left, $y, 'Amount in words', 8, true, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
    $y += 12;
    foreach (akh_invoice_pdf_wrap_text((string) $vm['amount_words'], 90) as $wLine) {
        $pdf->text($left, $y, $wLine, 9, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
        $y += 11;
    }

    $footY = 760.0;
    if ($vm['bank_details'] !== '') {
        $pdf->text($left, $footY, 'Bank details', 8, true, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
        $footY += 11;
        foreach (preg_split('/\r\n|\r|\n/', $vm['bank_details']) ?: [] as $bLine) {
            $bLine = trim($bLine);
            if ($bLine === '') {
                continue;
            }
            $pdf->text($left, $footY, mb_substr($bLine, 0, 95), 8, false, AkhInvoicePdfTheme::INK_R, AkhInvoicePdfTheme::INK_G, AkhInvoicePdfTheme::INK_B);
            $footY += 10;
        }
    }
    if ($vm['terms'] !== '') {
        $pdf->text($left, $footY + 4, 'Terms: ' . mb_substr(str_replace("\n", ' ', $vm['terms']), 0, 110), 8, false, AkhInvoicePdfTheme::MUTED_R, AkhInvoicePdfTheme::MUTED_G, AkhInvoicePdfTheme::MUTED_B);
    }

    return $pdf->bytes();
}

function akh_invoice_pdf_filename(array $invoice): string
{
    $num = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'invoice')) ?? 'invoice';

    return $num . '.pdf';
}
