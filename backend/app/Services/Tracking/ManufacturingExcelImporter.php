<?php

namespace App\Services\Tracking;

use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderSub;
use App\Models\Npbg;
use App\Models\Ri;
use App\Models\Site;
use App\Support\ManufacturingExcel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Loads the rows of the workbook "7. Tracking Manufaktur & Assembly" (sheets
 * "Manufaktur & Assembly" → MA and "Manufaktur & Jasa Lain-Lain" → MJ) into
 * STOCKWISE, one sub row per Excel row, exactly as typed. Links are made where
 * the data exists: NPBG / RI numbers to the Accurate mirror, Hasil Produk to the
 * Accurate item of the same description, Lokasi (MJ) to the vendor.
 *
 * Re-runnable: a row is matched on its MA/MJ number + Excel "No" and updated.
 * The status is the Excel formula; the MA/MJ numbering continues after the
 * highest imported number of each month.
 */
class ManufacturingExcelImporter
{
    /** Excel columns per sheet (row 5 headers). */
    private const SHEETS = [
        'Manufaktur & Assembly' => ['kind' => 'ASSEMBLY',
            'cols' => ['no' => 'B', 'date' => 'C', 'number' => 'D', 'sub' => 'E', 'loc' => 'F', 'product' => 'G', 'serial' => 'H', 'item' => 'I', 'process' => 'J']],
        'Manufaktur & Jasa Lain-Lain' => ['kind' => 'JASA',
            'cols' => ['no' => 'B', 'date' => 'C', 'number' => 'D', 'sub' => 'E', 'item' => 'F', 'loc' => 'G', 'product' => 'H', 'serial' => 'I', 'process' => 'J']],
    ];

    /** Columns K..R are the same on both sheets. */
    private const TAIL = ['note_start' => 'K', 'npbg' => 'M', 'file' => 'N', 'finish' => 'P', 'ri' => 'Q', 'note_end' => 'R'];

    /** @return array<string, array<string, int>> stats per sheet */
    public function import(string $path, bool $dryRun = false, ?int $userId = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $stats = [];
        DB::beginTransaction();
        try {
            foreach (self::SHEETS as $title => $cfg) {
                $ws = $book->getSheetByName($title);
                if (! $ws) {
                    throw new \RuntimeException("Sheet \"{$title}\" tidak ada di file.");
                }
                $stats[$cfg['kind'] === 'JASA' ? 'MJ' : 'MA'] = $this->sheet($ws, $cfg['kind'], $cfg['cols'], $userId);
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    private function sheet(Worksheet $ws, string $kind, array $cols, ?int $userId): array
    {
        $st = ['rows' => 0, 'created' => 0, 'updated' => 0, 'orders' => 0, 'npbg_linked' => 0, 'ri_linked' => 0, 'item_linked' => 0,
            'REQUESTED' => 0, 'ON_GOING' => 0, 'COMPLETED' => 0, 'INCOMPLETE' => 0];
        $siteId = Site::query()->orderBy('id')->value('id');
        $orders = [];
        $cols += self::TAIL;

        for ($r = 6; $r <= $ws->getHighestDataRow('D'); $r++) {
            $v = fn (string $key) => $this->text($ws->getCell($cols[$key].$r)->getValue());
            $number = strtoupper(str_replace(' ', '', (string) $v('number')));
            if ($number === '') {
                continue;
            }
            $st['rows']++;
            $date = $this->date($ws->getCell($cols['date'].$r)->getValue());
            $location = $v('loc');
            $product = $v('product');

            $order = $orders[$number] ??= ManufacturingOrder::firstOrNew(['number' => $number]);
            if (! $order->exists) {
                $order->fill([
                    'kind' => $kind, 'date' => $date ?? now()->toDateString(), 'product_name' => $product,
                    'location' => $location, 'site_id' => $siteId, 'status' => ManufacturingExcel::REQUESTED, 'created_by' => $userId,
                    'vendor_id' => $kind === 'JASA' ? ManufacturingService::vendorIdFor($location) : null,
                ])->save();
                $st['orders']++;
            }

            $npbgNo = $this->docNo($v('npbg'));
            $riNo = $this->docNo($v('ri'));
            $sheetNo = (int) ($ws->getCell($cols['no'].$r)->getValue() ?: 0) ?: null;
            $row = [
                'sub_no' => strtoupper((string) ($v('sub') ?? 'SUB-01')),
                'row_date' => $date,
                'row_location' => $location,
                'product_name' => $product,
                'item_id' => ManufacturingService::itemIdFor($product),
                'serial_no_raw' => $v('serial'),
                'item_no' => is_numeric($v('item')) ? (int) $v('item') : null,
                'process' => $v('process'),
                'note_start' => $v('note_start'),
                'npbg_no' => $npbgNo,
                'npbg_id' => $npbgNo && $npbgNo !== '-' ? Npbg::where('no_npbg', $npbgNo)->orderBy('id')->value('id') : null,
                'file_name' => $v('file'),
                'finish_date' => $this->date($ws->getCell($cols['finish'].$r)->getValue()),
                'ri_no' => $riNo,
                'ri_id' => $riNo && $riNo !== '-' ? Ri::where('no_ri', $riNo)->orderBy('id')->value('id') : null,
                'note_end' => $v('note_end'),
                'source' => 'EXCEL',
            ];
            // Status Hasil Pengerjaan: the value the Excel formula shows in the file (it also counts "-"
            // typed in a date / RI cell as filled); rows without a cached value get the same formula here.
            $cached = strtoupper(trim((string) $ws->getCell('L'.$r)->getOldCalculatedValue()));
            $row['status'] = match ($cached) {
                'COMPLETED' => ManufacturingExcel::COMPLETED, 'ON-GOING' => ManufacturingExcel::ON_GOING,
                'REQUESTED' => ManufacturingExcel::REQUESTED,
                default => ManufacturingExcel::status($row['file_name'], $row['finish_date'] ?? $this->text($ws->getCell($cols['finish'].$r)->getValue()),
                    $row['ri_no'], $row['npbg_no'], $row['product_name'], $row['serial_no_raw'], $row['process']),
            };

            // a row is its Item No (unique per sheet); the Excel "No" column has repeats (e.g. 456 ×6)
            $sub = match (true) {
                $row['item_no'] !== null => ManufacturingOrderSub::firstOrNew(['manufacturing_order_id' => $order->id, 'item_no' => $row['item_no']]),
                $sheetNo !== null => ManufacturingOrderSub::firstOrNew(['manufacturing_order_id' => $order->id, 'sheet_no' => $sheetNo]),
                default => new ManufacturingOrderSub(['manufacturing_order_id' => $order->id]),
            };
            $sub->exists ? $st['updated']++ : $st['created']++;
            $sub->fill($row + ['sheet_no' => $sheetNo])->save();

            $st['npbg_linked'] += (bool) $row['npbg_id'];
            $st['ri_linked'] += (bool) $row['ri_id'];
            $st['item_linked'] += (bool) $row['item_id'];
            $st[$row['status']]++;
            $this->bumpSequence($number);
        }

        foreach ($orders as $order) {
            $statuses = $order->subs()->pluck('status');
            $status = ManufacturingExcel::orderStatus($statuses);
            $order->update([
                'status' => $status,
                'completed_at' => $status === ManufacturingExcel::COMPLETED ? $order->subs()->max('finish_date') : null,
                'date' => $order->subs()->min('row_date') ?? $order->date,
            ]);
        }

        return $st;
    }

    /**
     * Foto Hasil: the Excel links "DATA BUKTI/Foto - Manufaktur & Assembly/<sheet>/<Nama File>.jpg".
     * Each row's photo is found by its Nama File under $dir (any sub-folder; the sheet's own folder
     * wins when a name is in several) and copied into STOCKWISE's storage, so it opens from the app.
     * Photos already uploaded in STOCKWISE are kept.
     *
     * @return array{rows_with_name:int, linked:int, already:int, missing:list<string>}
     */
    public function linkPhotos(string $dir, bool $dryRun = false): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $found[$this->photoKey(pathinfo($f->getFilename(), PATHINFO_FILENAME))][] = $f->getPathname();
        }

        $st = ['rows_with_name' => 0, 'linked' => 0, 'already' => 0, 'missing' => []];
        ManufacturingOrderSub::query()->with('order:id,kind')->whereNotNull('file_name')->where('file_name', '<>', '')
            ->orderBy('id')->each(function (ManufacturingOrderSub $sub) use ($found, $dryRun, &$st) {
                $st['rows_with_name']++;
                if ($sub->photo_path && \Storage::disk('local')->exists($sub->photo_path)) {
                    $st['already']++;

                    return;
                }
                $paths = $found[$this->photoKey(preg_replace('/\.(jpe?g|png|webp)$/i', '', $sub->file_name))] ?? [];
                if (! $paths) {
                    $st['missing'][] = $sub->file_name;

                    return;
                }
                $sheetFolder = $sub->order->kind === 'JASA' ? 'Manufaktur & Jasa' : 'Manufaktur & Assembly';
                usort($paths, fn ($a, $b) => (int) ! str_contains($a, $sheetFolder) <=> (int) ! str_contains($b, $sheetFolder));
                $src = $paths[0];
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo($src, PATHINFO_EXTENSION));
                $name = \Illuminate\Support\Str::limit(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $sub->file_name), 150, '');
                $path = 'manufacturing/'.strtolower($sub->order->kind)."/{$sub->id}/{$name}.{$ext}";
                if (! $dryRun) {
                    \Storage::disk('local')->put($path, file_get_contents($src));
                    $sub->forceFill(['photo_path' => $path])->saveQuietly();
                }
                $st['linked']++;
            });

        return $st;
    }

    private function photoKey(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($name)));
    }

    /** New MA/MJ numbers continue after the imported ones (document_sequences per month). */
    private function bumpSequence(string $number): void
    {
        if (! preg_match('#^(MA|MJ)/(\d{2})/([IVX]+)/(\d{3})$#', $number, $m)) {
            return;
        }
        $month = array_search($m[3], ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'], true);
        if ($month === false) {
            return;
        }
        $key = ['doc_type' => $m[1], 'prefix' => '-', 'year' => (int) $m[2], 'month' => $month + 1];
        $seq = (int) $m[4];
        $current = DB::table('document_sequences')->where($key)->value('last_sequence');
        if ($current === null) {
            DB::table('document_sequences')->insert($key + ['last_sequence' => $seq, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($current < $seq) {
            DB::table('document_sequences')->where($key)->update(['last_sequence' => $seq, 'updated_at' => now()]);
        }
    }

    private function text(mixed $v): ?string
    {
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if ($v === null) {
            return null;
        }
        $v = trim(str_replace("\u{00A0}", ' ', (string) $v));

        return $v === '' ? null : $v;
    }

    private function docNo(?string $v): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', (string) $v));

        // "-" is kept: the Excel counts it as filled
        return $v === '' ? null : $v;
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        // a date typed as text is Indonesian day/month/year ("01/10/025" = 1 Oct 2025)
        if (preg_match('#^\s*(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})\s*$#', (string) $v, $m)) {
            $year = 2000 + (int) substr($m[3], -2);
            return checkdate((int) $m[2], (int) $m[1], $year) ? sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]) : null;
        }
        try {
            return Carbon::parse((string) $v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
