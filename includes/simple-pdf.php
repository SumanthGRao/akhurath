<?php

declare(strict_types=1);

/**
 * Minimal PDF writer (Helvetica + Helvetica-Bold, text, lines, filled rects).
 */
final class AkhSimplePdf
{
    private float $pageW = 595.28;

    private float $pageH = 841.89;

    /** @var list<string> */
    private array $graphOps = [];

    /** @var list<array{x: float, y: float, size: int, text: string, bold: bool, r: float, g: float, b: float}> */
    private array $textOps = [];

    public function fillRect(float $x, float $yFromTop, float $w, float $h, float $r, float $g, float $b): void
    {
        $y = $this->pageH - $yFromTop - $h;
        $this->graphOps[] = sprintf(
            'q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q',
            $r,
            $g,
            $b,
            $x,
            $y,
            $w,
            $h
        );
    }

    public function line(float $x1, float $y1FromTop, float $x2, float $y2FromTop, float $w = 0.5, float $r = 0.75, float $g = 0.65, float $b = 0.55): void
    {
        $y1 = $this->pageH - $y1FromTop;
        $y2 = $this->pageH - $y2FromTop;
        $this->graphOps[] = sprintf(
            'q %.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S Q',
            $w,
            $r,
            $g,
            $b,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /**
     * @param float $r $g $b Text color 0–1 (default near-black).
     */
    public function text(
        float $x,
        float $yFromTop,
        string $text,
        int $fontSize = 10,
        bool $bold = false,
        float $r = 0.12,
        float $g = 0.09,
        float $b = 0.07
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->textOps[] = [
            'x' => $x,
            'y' => $this->pageH - $yFromTop,
            'size' => max(6, min(24, $fontSize)),
            'text' => $this->escapePdfText($this->latin1($text)),
            'bold' => $bold,
            'r' => $r,
            'g' => $g,
            'b' => $b,
        ];
    }

    public function textRight(
        float $rightX,
        float $yFromTop,
        string $text,
        int $fontSize = 10,
        bool $bold = false,
        float $r = 0.12,
        float $g = 0.09,
        float $b = 0.07
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $approx = $fontSize * 0.52 * strlen($text);
        $this->text(max(40, $rightX - $approx), $yFromTop, $text, $fontSize, $bold, $r, $g, $b);
    }

    public function bytes(): string
    {
        $stream = implode("\n", $this->graphOps);
        if ($stream !== '') {
            $stream .= "\n";
        }
        foreach ($this->textOps as $op) {
            $font = $op['bold'] ? 'F2' : 'F1';
            $stream .= sprintf(
                "BT /%s %d Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
                $font,
                $op['size'],
                $op['r'],
                $op['g'],
                $op['b'],
                $op['x'],
                $op['y'],
                $op['text']
            );
        }

        $len = strlen($stream);
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            $this->pageW,
            $this->pageH
        );
        $objects[] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

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
