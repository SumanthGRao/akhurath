<?php

declare(strict_types=1);

/**
 * Minimal single-page PDF writer (Helvetica, text only) for invoices and similar documents.
 */
final class AkhSimplePdf
{
    private float $pageW = 595.28;

    private float $pageH = 841.89;

    /** @var list<array{x: float, y: float, size: int, text: string}> */
    private array $ops = [];

    public function text(float $x, float $yFromTop, string $text, int $fontSize = 10): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->ops[] = [
            'x' => $x,
            'y' => $this->pageH - $yFromTop,
            'size' => max(6, min(24, $fontSize)),
            'text' => $this->escapePdfText($this->latin1($text)),
        ];
    }

    public function bytes(): string
    {
        $stream = "BT\n";
        foreach ($this->ops as $op) {
            $stream .= sprintf("/F1 %d Tf\n", $op['size']);
            $stream .= sprintf("%.2F %.2F Td\n", $op['x'], $op['y']);
            $stream .= '(' . $op['text'] . ") Tj\n";
            $stream .= sprintf("%.2F %.2F Td\n", -$op['x'], -$op['y']);
        }
        $stream .= "ET\n";

        $len = strlen($stream);
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            $this->pageW,
            $this->pageH
        );
        $objects[] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n %s', $offsets[$i], "\n");
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function latin1(string $s): string
    {
        $s = str_replace(['₹', '–', '—'], ['Rs.', '-', '-'], $s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                return $t;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
    }

    private function escapePdfText(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
