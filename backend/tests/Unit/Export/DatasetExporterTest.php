<?php

namespace Tests\Unit\Export;

use App\Services\Export\DatasetExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Regression test for a real bug: exporting a large dataset (Master Barang,
 * ~9k rows; NPBG, ~8.5k rows) as .xlsx fatal-errored with "Allowed memory
 * size of 134217728 bytes exhausted" — PhpSpreadsheet builds the whole
 * workbook in memory (no true streaming writer), and this environment's
 * default memory_limit is 128M even under `php artisan serve`/CLI (verified
 * directly, not assumed). A true OOM fatal isn't safely reproducible inside
 * a PHPUnit run (it can kill the process rather than fail cleanly), so this
 * asserts the fix's actual mechanism instead: memory_limit gets raised, and
 * the expensive per-cell setAutoSize() pass (the other thing that made large
 * sheets slow/heavy, per LegacyExcelExportService's own prior fix for the
 * same class of problem) is gone.
 */
class DatasetExporterTest extends TestCase
{
    protected function tearDown(): void
    {
        ini_set('memory_limit', '128M'); // don't leak the raised limit into other tests
        parent::tearDown();
    }

    public function test_xlsx_export_raises_memory_limit_and_avoids_autosize(): void
    {
        ini_set('memory_limit', '128M');

        $response = (new DatasetExporter)->download(
            'xlsx',
            'test-export',
            ['Kode', 'Deskripsi'],
            [['A.0001', 'Barang Satu'], ['A.0002', 'Barang Dua']],
            'Test'
        );

        $this->assertSame('1024M', ini_get('memory_limit'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $content);
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertFalse($sheet->getColumnDimension('A')->getAutoSize(), 'autosize must be off — it is what made large exports slow/heavy');
        $this->assertGreaterThan(0, $sheet->getColumnDimension('A')->getWidth(), 'a fixed width must be set instead');

        // title (row 1) + blank spacer (row 2) + header (row 3) + 2 data rows
        $this->assertSame(5, $sheet->getHighestRow());
        $this->assertSame('A.0001', $sheet->getCell('A4')->getValue());
        $this->assertSame('A.0002', $sheet->getCell('A5')->getValue());
    }
}
