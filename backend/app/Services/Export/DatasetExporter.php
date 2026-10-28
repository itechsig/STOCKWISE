<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a header row + data rows into a downloadable file (xlsx / csv / pdf-print).
 * PHASE 10 — brief §AG. csv/pdf write straight to the output stream so large
 * datasets stay memory-light; xlsx can't (PhpSpreadsheet builds the whole
 * workbook in memory before the writer runs), so that path raises memory_limit
 * instead — see xlsx() below.
 *
 * xlsx/pdf (and the "Cetak" button, which opens the same pdf html to print
 * instead of downloading it) carry the company logo top-right, same as Kartu
 * Stock's print — csv is plain data, no logo. See logoFile()/logoDataUri().
 */
class DatasetExporter
{
    /** Absolute path to the company logo, or null if it isn't on disk. */
    private function logoFile(): ?string
    {
        $path = public_path('logo.png');

        return is_file($path) ? $path : null;
    }

    /** Base64 data URI — embedded so the exported/printed HTML never depends on a URL. */
    private function logoDataUri(): ?string
    {
        $path = $this->logoFile();

        return $path ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public function download(string $format, string $filename, array $headers, iterable $rows, string $title = ''): StreamedResponse
    {
        return match ($format) {
            'xlsx' => $this->xlsx($filename, $headers, $rows, $title),
            'pdf' => $this->pdf($filename, $headers, $rows, $title),
            default => $this->csv($filename, $headers, $rows),
        };
    }

    private function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(string $filename, array $headers, iterable $rows, string $title): StreamedResponse
    {
        // Datasets like Master Barang / NPBG run 8-9k+ rows — PhpSpreadsheet holds
        // the whole cell collection in memory (no true streaming writer here), so
        // the default 128M memory_limit reliably fatal-errors past a few thousand
        // rows. Same fix already applied to DATA.xlsx in LegacyExportController.
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(substr($title ?: 'Data', 0, 31));
        $lastColIdx = max(count($headers), 1);
        $lastCol = Coordinate::stringFromColumnIndex($lastColIdx);

        // Letterhead: title + company name, centered across every column, logo
        // top-right — same look as the printed/PDF version below, so whichever
        // format someone opens, the first thing on the page says what it is.
        $r = 1;
        if ($title !== '') {
            $sheet->setCellValue([1, $r], mb_strtoupper($title));
            $sheet->mergeCells([1, $r, $lastColIdx, $r]);
            $sheet->getStyle([1, $r, $lastColIdx, $r])->getFont()->setBold(true)->setSize(15);
            $sheet->getStyle([1, $r, $lastColIdx, $r])->getAlignment()->setHorizontal('center');
            $r++;

            $sheet->setCellValue([1, $r], 'PT SURYA INTI GAS');
            $sheet->mergeCells([1, $r, $lastColIdx, $r]);
            $sheet->getStyle([1, $r, $lastColIdx, $r])->getFont()->setSize(11);
            $sheet->getStyle([1, $r, $lastColIdx, $r])->getAlignment()->setHorizontal('center');
            $r++;

            $sheet->setCellValue([1, $r], 'Dicetak '.now()->format('d/m/Y H:i'));
            $sheet->mergeCells([1, $r, $lastColIdx, $r]);
            $sheet->getStyle([1, $r, $lastColIdx, $r])->getFont()->setSize(9)->getColor()->setRGB('666666');
            $sheet->getStyle([1, $r, $lastColIdx, $r])->getAlignment()->setHorizontal('center');
            $r += 2;
        }

        $headerRow = $r;
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $headerRow], $h);
        }
        $sheet->getStyle([1, $headerRow, $lastColIdx, $headerRow])->getFont()->setBold(true);
        $sheet->getStyle([1, $headerRow, $lastColIdx, $headerRow])->getFill()->setFillType('solid')->getStartColor()->setRGB('F1F5F9');
        $r++;
        $firstDataRow = $r;

        foreach ($rows as $row) {
            $c = 1;
            foreach ($row as $value) {
                $sheet->setCellValue([$c++, $r], $value);
            }
            $r++;
        }

        // setAutoSize(true) makes PhpSpreadsheet measure every cell in the
        // column at save time — fine for a few hundred rows, but it's what
        // actually pushes datasets this size (thousands of rows) into the
        // memory/time exhaustion this method just widened room for. A width
        // guessed from the header label is a lot cheaper and good enough.
        foreach ($headers as $i => $h) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth(max(12, min(40, strlen((string) $h) + 4)));
        }

        // Print setup: header row repeats on every printed page, title fits the
        // page width — so a long export looks as deliberate on paper as on screen.
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->freezePane('A'.$firstDataRow);

        if ($logoFile = $this->logoFile()) {
            (new Drawing)
                ->setPath($logoFile)
                ->setCoordinates("{$lastCol}1")
                ->setResizeProportional(true)
                ->setHeight(50)
                ->setOffsetX(4)
                ->setOffsetY(2)
                ->setWorksheet($sheet);
        }

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, "{$filename}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** A print-optimised HTML document; the browser's "Save as PDF" turns it into a PDF — also what the "Cetak" button opens directly to print. */
    private function pdf(string $filename, array $headers, iterable $rows, string $title): StreamedResponse
    {
        $logo = $this->logoDataUri();

        return response()->streamDownload(function () use ($headers, $rows, $title, $logo) {
            $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
            echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.$esc($title).'</title>';
            echo '<style>@media print{@page{size:A4 landscape;margin:8mm}}';
            echo '*{box-sizing:border-box}body{font:12px/1.4 Arial,sans-serif;color:#111;margin:0}';
            // Letterhead: logo top-left, title + company name centered across the
            // full page width — the first thing anyone sees on paper, same look
            // whether this came from "Cetak" or a downloaded PDF.
            echo '.letterhead{position:relative;text-align:center;padding:4mm 0 3mm;border-bottom:2px solid #1e3a5f;margin-bottom:4mm}';
            echo '.letterhead img{position:absolute;left:0;top:2mm;height:16mm;width:16mm;object-fit:contain}';
            echo '.letterhead h1{font-size:18px;margin:0 0 2px;letter-spacing:.5px}';
            echo '.letterhead .company{font-size:12px;font-weight:600;color:#1e3a5f;margin:0 0 2px}';
            echo '.letterhead .meta{font-size:10px;color:#777}';
            // table-layout:fixed is the actual fix for "tidak muat" — without it
            // a long Deskripsi/Keterangan cell pushes the whole table wider than
            // the page instead of wrapping, so the right-most columns get cut off
            // on paper. Fixed layout + word-wrap forces every cell to wrap within
            // its share of the page width instead.
            echo 'table{border-collapse:collapse;width:100%;table-layout:fixed}';
            echo 'thead{display:table-header-group}tr{break-inside:avoid}';
            echo 'th,td{border:1px solid #bbb;padding:3px 5px;text-align:left;font-size:9.5px;';
            echo 'word-wrap:break-word;overflow-wrap:break-word;white-space:normal}th{background:#f1f5f9}';
            echo 'tr:nth-child(even) td{background:#fafafa}</style></head><body onload="window.print()">';
            echo '<div class="letterhead">';
            if ($logo) {
                echo '<img src="'.$logo.'" alt="Logo">';
            }
            echo '<h1>'.$esc(mb_strtoupper($title ?: 'STOCKWISE')).'</h1>';
            echo '<div class="company">PT SURYA INTI GAS</div>';
            echo '<div class="meta">Dicetak '.now()->format('d/m/Y H:i').'</div>';
            echo '</div><table><thead><tr>';
            foreach ($headers as $h) {
                echo '<th>'.$esc($h).'</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $v) {
                    echo '<td>'.$esc($v).'</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></body></html>';
        }, "{$filename}.html", ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
