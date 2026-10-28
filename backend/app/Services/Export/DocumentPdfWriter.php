<?php

namespace App\Services\Export;

/**
 * A real PDF of one NPBG/PPB/PO/RI document — data only, for pre-printed paper —
 * built by hand (no library): the page is nothing but positioned text, which
 * the PDF base fonts Helvetica/Helvetica-Bold (= Arial metrics, not embedded)
 * cover fully.
 *
 * Why it exists: the HP LaserJet P1102 (a host-based printer — its Windows
 * driver renders every page itself, inside the printing application) crashes
 * Chrome and Firefox alike on Print, taking the whole browser down, while
 * Accurate prints the very same 210×147mm page fine. Accurate is a desktop
 * program printing straight through Windows (GDI); this PDF lets a desktop PDF
 * reader (Adobe Reader, SumatraPDF, …) do the same, bypassing the browser's
 * print engine entirely.
 *
 * Coordinates mirror DocumentPrintRenderer's HTML exactly (same mm, same
 * calibration offsets, Arial-equal metrics, line-height 1.15, text wrapped to
 * its box, table cells clipped to their column) so the data lands on the same
 * spots of the pre-printed form.
 */
class DocumentPdfWriter
{
    private const PT_PER_MM = 72 / 25.4;

    /** Helvetica / Helvetica-Bold advance widths (1/1000 em) for ASCII 32..126 — the standard AFM metrics. */
    private const WIDTHS = [
        false => [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584],
        true => [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584],
    ];

    /** @var list<string> page content operators */
    private array $ops = [];

    public function __construct(private float $pageWidthMm, private float $pageHeightMm) {}

    /** Text in a box (top-left x/y, width, optional height) — wrapped to the width, cut at the height, like the HTML .t div. */
    public function text(float $x, float $y, float $w, ?float $h, ?string $text, float $sizePt, bool $bold, string $align): void
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text), "\n");
        if ($text === '') {
            return;
        }
        $lineMm = $sizePt * 1.15 / self::PT_PER_MM;
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            array_push($lines, ...$this->wrap($para, $w, $sizePt, $bold));
        }
        if ($h !== null) {
            $lines = array_slice($lines, 0, max(1, (int) floor(($h + 0.01) / $lineMm)));
        }
        foreach ($lines as $i => $line) {
            $this->line($x, $y + $i * $lineMm, $w, $line, $sizePt, $bold, $align);
        }
    }

    /** One table cell: a single line, clipped to the column width (HTML: white-space:nowrap; overflow:hidden). */
    public function cell(float $x, float $y, float $w, ?string $text, float $sizePt, string $align): void
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if ($text !== '') {
            $this->line($x, $y, $w, $this->fit($text, $w, $sizePt, false), $sizePt, false, $align);
        }
    }

    public function output(): string
    {
        $wPt = round($this->pageWidthMm * self::PT_PER_MM, 2);
        $hPt = round($this->pageHeightMm * self::PT_PER_MM, 2);
        $content = implode("\n", $this->ops);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R /ViewerPreferences << /PrintScaling /None >> >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$wPt} {$hPt}] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>",
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function line(float $x, float $topMm, float $w, string $text, float $sizePt, bool $bold, string $align): void
    {
        $textW = $this->width($text, $sizePt, $bold);
        $dx = match ($align) {
            'center' => max(0, ($w - $textW) / 2),
            'right' => max(0, $w - $textW),
            default => 0,
        };
        // baseline inside a 1.15 line box of Arial (ascent .905, descent .212): half-leading + ascent
        $baselineMm = $topMm + $sizePt * 0.92 / self::PT_PER_MM;
        $this->ops[] = sprintf(
            'BT /%s %s Tf %s %s Td (%s) Tj ET',
            $bold ? 'F2' : 'F1',
            $sizePt,
            round(($x + $dx) * self::PT_PER_MM, 2),
            round(($this->pageHeightMm - $baselineMm) * self::PT_PER_MM, 2),
            $this->escape($text),
        );
    }

    /** @return list<string> */
    private function wrap(string $para, float $w, float $sizePt, bool $bold): array
    {
        $words = preg_split('/ +/u', trim($para)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $word) {
            $try = $cur === '' ? $word : "{$cur} {$word}";
            if ($this->width($try, $sizePt, $bold) <= $w + 0.01) {
                $cur = $try;
                continue;
            }
            if ($cur !== '') {
                $lines[] = $cur;
            }
            // a single word wider than the box is broken by character (CSS overflow-wrap)
            while ($this->width($word, $sizePt, $bold) > $w + 0.01 && mb_strlen($word) > 1) {
                $part = $this->fit($word, $w, $sizePt, $bold);
                $lines[] = $part;
                $word = mb_substr($word, mb_strlen($part));
            }
            $cur = $word;
        }
        if ($cur !== '' || $lines === []) {
            $lines[] = $cur;
        }

        return $lines;
    }

    /** The longest prefix of $text that fits in $w mm. */
    private function fit(string $text, float $w, float $sizePt, bool $bold): string
    {
        $out = '';
        foreach (mb_str_split($text) as $ch) {
            if ($this->width($out.$ch, $sizePt, $bold) > $w + 0.01) {
                return $out === '' ? $ch : $out;
            }
            $out .= $ch;
        }

        return $out;
    }

    /** Width in mm. */
    private function width(string $text, float $sizePt, bool $bold): float
    {
        $units = 0;
        foreach (mb_str_split($text) as $ch) {
            $code = mb_ord($ch);
            $units += ($code >= 32 && $code <= 126) ? self::WIDTHS[$bold][$code - 32] : 556;
        }

        return $units / 1000 * $sizePt / self::PT_PER_MM;
    }

    private function escape(string $text): string
    {
        $win = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return strtr($win === false ? $text : $win, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
