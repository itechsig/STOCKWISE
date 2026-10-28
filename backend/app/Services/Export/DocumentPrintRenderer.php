<?php

namespace App\Services\Export;

/**
 * Single-document print view (NPBG/PPB/PO/RI) for pre-printed stationery —
 * 1. Dashboard Input Data\Template\*.frf (FastReport forms exported from
 * Accurate; a proprietary binary format, not renderable directly, but every
 * component's name, exact Left/Top/Width/Height — in 1/96" units, confirmed
 * by cross-checking band widths against the page's own declared mm size —
 * and nearby label text were extracted byte-by-byte and hardcoded into each
 * controller's print() method).
 *
 * One HTML document, one `@media print` rule — not two separately-rendered
 * documents (brief: "jangan membuat dua layout yang dikelola secara manual
 * secara terpisah"). On screen (and anywhere @media print doesn't apply) every
 * element renders: logo, company letterhead, box borders, every static label,
 * the item table's header row, signature action/role labels and their lines —
 * so opening the page looks exactly like the real print preview. The moment
 * the browser actually prints (window.print(), the print dialog's own preview
 * pane, or Ctrl+P) — `@media print` kicks in and every element carrying the
 * `.static` class disappears, leaving ONLY the transaction data, at the exact
 * same coordinates — because the physical paper this prints onto already has
 * the logo/labels/lines pre-printed; printing them again would double them up.
 *
 * offsetXMm/offsetYMm: a global nudge applied to every positioned element, for
 * printer/tray calibration without touching the underlying layout or any
 * transaction data (brief: "kalibrasi ... dapat diuji tanpa mengubah data
 * transaksi").
 *
 * No auto-print script of any kind is emitted — not an inline one in this
 * document, not one the caller runs against a reference to this window.
 * Both were tried and both reliably force-closed Chrome on this printer setup
 * (custom 210×147mm page, zero margin). The user presses Ctrl+P themselves
 * once the tab is open; that's the only variant that didn't crash.
 */
class DocumentPrintRenderer
{
    private function logoDataUri(): ?string
    {
        $path = public_path('logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }

    /** CSS @page size: a standard sheet name when the page is (within 2mm) A4/A5, else exact mm. */
    public static function cssPageSize(float $widthMm, float $heightMm): string
    {
        foreach (['A4' => [210, 297], 'A5' => [148, 210]] as $name => [$short, $long]) {
            $near = fn (float $a, float $b) => abs($a - $b) <= 2;
            if ($near($widthMm, $short) && $near($heightMm, $long)) {
                return "{$name} portrait";
            }
            if ($near($widthMm, $long) && $near($heightMm, $short)) {
                return "{$name} landscape";
            }
        }

        return "{$widthMm}mm {$heightMm}mm";
    }

    /**
     * @param  list<array{x:float,y:float,w:float,h?:float,text:?string,size?:float,bold?:bool,align?:string,static?:bool}>  $texts
     * @param  array{y:float,rowHeight:float,columns:list<array{x:float,w:float,header:string,align?:string}>}|null  $table
     * @param  list<list<string|int|float|null>>  $tableRows
     * @param  array{y:float,lineY?:float,roleY?:float,columns:list<array{x:float,w:float,action:string,role:string}>}|null  $signatures
     * @param  array{x:float,y:float,w:float,h:float}|null  $logoBox
     */
    public function render(
        float $pageWidthMm,
        float $pageHeightMm,
        array $texts,
        ?array $table,
        array $tableRows,
        ?array $signatures,
        ?array $logoBox = null,
        float $offsetXMm = 0,
        float $offsetYMm = 0,
        bool $autoPageSize = false,
        bool $dataOnly = false,
    ): string {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $ox = fn (float $v) => round($v + $offsetXMm, 2);
        $oy = fn (float $v) => round($v + $offsetYMm, 2);
        // $dataOnly: for the downloadable PDF (DocumentPdfRenderer) — static
        // elements are left out of the markup entirely rather than merely
        // hidden by @media print, since the PDF engine isn't a browser and
        // "what it rendered" IS the print output, with no on-screen/print
        // distinction to rely on.
        $logo = $dataOnly ? null : $this->logoDataUri();
        $logoBox ??= ['x' => 7.4, 'y' => 5.3, 'w' => 16.9, 'h' => 16.9];

        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Cetak Dokumen</title><style>';
        // autoPageSize: skip the explicit `size:` — some printer drivers (dot-
        // matrix / continuous-form drivers common for pre-printed stationery)
        // get stuck "Spooling" forever when asked for an exact custom mm size
        // they have no matching Windows "form" for. Leaving size unset lets the
        // print dialog fall back to whatever paper/form is already selected on
        // that printer; the absolute mm coordinates below are unaffected either
        // way (they're measured from the page's own top-left corner).
        // A page within 2mm of a standard sheet asks for that sheet BY NAME
        // ("A5 landscape" for the 210x147mm NPBG/PPB/RI templates, "A4" for
        // PO), so the browser picks the driver's own A5/A4 form — exactly what
        // Accurate prints on. "210mm 147mm" is 1mm off A5 and became a CUSTOM
        // paper size, which made the HP LaserJet P1102 (host-based) driver
        // crash the whole browser on Print, in Chrome and Firefox alike.
        $html .= $autoPageSize ? '@page{margin:0}' : '@page{size:'.self::cssPageSize($pageWidthMm, $pageHeightMm).';margin:0}';
        $html .= '*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#000}';
        $html .= ".page{position:relative;width:{$pageWidthMm}mm;height:{$pageHeightMm}mm;overflow:hidden;border:0.5pt dashed #ccc}";
        $html .= '.t{position:absolute;line-height:1.15;overflow:hidden;white-space:pre-wrap}';
        $html .= '.logo{position:absolute;object-fit:contain}';
        $html .= '.th,.td{position:absolute;overflow:hidden;white-space:nowrap}';
        $html .= '.th{font-weight:bold;border-top:0.5pt solid #000;border-bottom:0.5pt solid #000}';
        $html .= '.td{border-bottom:0.3pt solid #999}';
        $html .= '.sigline{position:absolute;border-top:0.5pt solid #000}';
        $html .= '.previewtag{position:fixed;right:4mm;top:2mm;font-size:7pt;color:#f59e0b;font-weight:bold;background:#fff;padding:1px 4px;border:0.5pt solid #f59e0b;border-radius:2px;z-index:9}';
        // The ONLY thing that differs between "on screen" and "actually
        // printed" — everything tagged .static (logo, labels, title, table
        // header, signature captions/lines, the preview banner, the dashed
        // page border) disappears under @media print; the browser's own print
        // preview pane already honors this, so it matches the paper exactly.
        $html .= '@media print{.static,.previewtag{display:none!important}.page{border:none}}';
        $html .= '</style></head><body><div class="page">';
        if (! $dataOnly) {
            $html .= '<div class="static previewtag">PREVIEW — label &amp; kop tidak ikut tercetak (kertas sudah pre-printed)</div>';
        }

        if ($logo) {
            $html .= sprintf(
                '<img class="static logo" src="%s" style="left:%smm;top:%smm;width:%smm;height:%smm">',
                $logo, $ox($logoBox['x']), $oy($logoBox['y']), $logoBox['w'], $logoBox['h'],
            );
        }

        foreach ($texts as $t) {
            if ($dataOnly && ($t['static'] ?? false)) {
                continue;
            }
            $static = ($t['static'] ?? false) ? ' static' : '';
            $style = sprintf(
                'left:%smm;top:%smm;width:%smm;%sfont-size:%spt;text-align:%s;%s',
                $ox($t['x']), $oy($t['y']), $t['w'],
                isset($t['h']) ? "height:{$t['h']}mm;" : '',
                $t['size'] ?? 8,
                $t['align'] ?? 'left',
                ($t['bold'] ?? false) ? 'font-weight:bold;' : '',
            );
            $html .= '<div class="t'.$static.'" style="'.$style.'">'.nl2br($esc($t['text'])).'</div>';
        }

        if ($table) {
            if (! $dataOnly) {
                foreach ($table['columns'] as $c) {
                    $html .= sprintf(
                        '<div class="th static" style="left:%smm;top:%smm;width:%smm;font-size:7.5pt;text-align:%s">%s</div>',
                        $ox($c['x']), $oy($table['y']), $c['w'], $c['align'] ?? 'left', $esc($c['header']),
                    );
                }
            }
            $y = $table['y'] + $table['rowHeight'];
            foreach ($tableRows as $row) {
                foreach ($table['columns'] as $i => $c) {
                    $html .= sprintf(
                        '<div class="td" style="left:%smm;top:%smm;width:%smm;font-size:7.5pt;text-align:%s">%s</div>',
                        $ox($c['x']), $oy($y), $c['w'], $c['align'] ?? 'left', $esc($row[$i] ?? null),
                    );
                }
                $y += $table['rowHeight'];
            }
        }

        if ($signatures && ! $dataOnly) {
            $lineY = $signatures['lineY'] ?? ($signatures['y'] + 15);
            $roleY = $signatures['roleY'] ?? ($signatures['y'] + 15.5);
            foreach ($signatures['columns'] as $c) {
                $html .= sprintf(
                    '<div class="t static" style="left:%smm;top:%smm;width:%smm;font-size:7.5pt;text-align:center">%s</div>',
                    $ox($c['x']), $oy($signatures['y']), $c['w'], $esc($c['action']),
                );
                $html .= sprintf(
                    '<div class="sigline static" style="left:%smm;top:%smm;width:%smm"></div>',
                    $ox($c['x'] + 1), $oy($lineY), $c['w'] - 2,
                );
                $html .= sprintf(
                    '<div class="t static" style="left:%smm;top:%smm;width:%smm;font-size:7.5pt;text-align:center">%s</div>',
                    $ox($c['x']), $oy($roleY), $c['w'], $esc($c['role']),
                );
            }
        }

        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * The same document as a real PDF, data only (what actually goes onto the
     * pre-printed paper) — printed from a desktop PDF reader instead of the
     * browser; see DocumentPdfWriter. Takes render()'s arguments as-is.
     *
     * @param  list<array{x:float,y:float,w:float,h?:float,text:?string,size?:float,bold?:bool,align?:string,static?:bool}>  $texts
     * @param  array{y:float,rowHeight:float,columns:list<array{x:float,w:float,header:string,align?:string}>}|null  $table
     * @param  list<list<string|int|float|null>>  $tableRows
     */
    public function pdf(
        float $pageWidthMm,
        float $pageHeightMm,
        array $texts,
        ?array $table,
        array $tableRows,
        ?array $signatures,
        ?array $logoBox = null,
        float $offsetXMm = 0,
        float $offsetYMm = 0,
        bool $autoPageSize = false,
    ): string {
        $pdf = new DocumentPdfWriter($pageWidthMm, $pageHeightMm);
        foreach ($texts as $t) {
            if (! ($t['static'] ?? false)) {
                $pdf->text($t['x'] + $offsetXMm, $t['y'] + $offsetYMm, $t['w'], $t['h'] ?? null, $t['text'] ?? null,
                    (float) ($t['size'] ?? 8), (bool) ($t['bold'] ?? false), $t['align'] ?? 'left');
            }
        }
        if ($table) {
            $y = $table['y'] + $table['rowHeight'];
            foreach ($tableRows as $row) {
                foreach ($table['columns'] as $i => $c) {
                    $v = $row[$i] ?? null;
                    $pdf->cell($c['x'] + $offsetXMm, $y + $offsetYMm, $c['w'], $v === null ? null : (string) $v, 7.5, $c['align'] ?? 'left');
                }
                $y += $table['rowHeight'];
            }
        }

        return $pdf->output();
    }
}
