<?php

namespace App\Services\Tracking\Import;

use App\Models\Npbg;
use App\Models\SerialUnit;
use App\Models\StppTransaction;
use App\Models\Unit;
use App\Services\DocumentNumberService;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "4. Tracking STPP.xlsx" into STPP. Sheet "STPP": one unit per row (No Seri …
 * Keterangan Kembali); an Excel row that is the same NPBG line as an STPP STOCKWISE
 * already made from Accurate takes that row over (no duplicate, and the Accurate
 * sync will not create it again). Bukti Keluar / Bukti Terima come from
 * DATA BUKTI/Foto - Tracking STPP/Pengeluaran|Penerimaan/<Nama File>.jpg.
 * Sheet "Maintenance" → stpp_maintenance_logs. Re-runnable (row = No Seri + No. NPBG + Item No).
 */
class StppExcelImporter
{
    use ReadsExcelCells;

    public const ENTITY = 'stpp';

    public function __construct(private readonly TrackingPhotoService $photos, private readonly DocumentNumberService $numbers) {}

    public function import(string $xlsx, ?string $photoDir, bool $dryRun = false): array
    {
        $reader = IOFactory::createReaderForFile($xlsx);
        $reader->setReadDataOnly(true);
        $book = $reader->load($xlsx);
        $ws = $book->getSheetByName('STPP') ?? throw new \RuntimeException('Sheet "STPP" tidak ada.');
        $outIdx = $photoDir ? TrackingPhotoService::indexFolder($photoDir.'/Pengeluaran') : [];
        $inIdx = $photoDir ? TrackingPhotoService::indexFolder($photoDir.'/Penerimaan') : [];
        $units = Unit::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper(trim($code)) => $id]);

        $st = ['rows' => 0, 'created' => 0, 'updated' => 0, 'replaced_accurate' => 0, 'npbg_linked' => 0,
            'photo_out' => 0, 'photo_in' => 0, 'missing_out' => [], 'missing_in' => [], 'ACTIVE' => 0, 'PASSIVE' => 0, 'INCOMPLETE' => 0,
            'maintenance_rows' => 0];
        $used = [];
        $sheetNo = 0;

        DB::beginTransaction();
        try {
            // rows STOCKWISE made from Accurate keep their NPBG number as text too
            StppTransaction::whereNotNull('out_npbg_id')->whereNull('out_npbg_no')->with('outNpbg:id,no_npbg')->get()
                ->each(fn ($s) => $s->forceFill(['out_npbg_no' => $s->outNpbg?->no_npbg, 'source' => $s->source ?? 'ACCURATE'])->saveQuietly());

            // The Excel is the record of every NPBG it lists: STPP rows STOCKWISE derived from
            // those documents (Accurate) are replaced by the Excel rows.
            $docs = [];
            for ($r = 6; $r <= $ws->getHighestDataRow('C'); $r++) {
                $no = $this->docNo($ws->getCell("J$r")->getValue());
                if ($no && $no !== '-') {
                    $docs[$no] = true;
                }
            }
            $replaced = StppTransaction::where(fn ($q) => $q->whereNull('source')->orWhere('source', 'ACCURATE'))
                ->whereIn('out_npbg_id', Npbg::whereIn('no_npbg', array_keys($docs))->pluck('id'))->get();
            foreach ($replaced as $old) {
                foreach (\App\Models\TrackingPhoto::where(['entity' => self::ENTITY, 'entity_id' => $old->id])->get() as $ph) {
                    $this->photos->delete($ph);
                }
                $old->delete();
            }
            $st['replaced_accurate'] = $replaced->count();

            for ($r = 6; $r <= $ws->getHighestDataRow('C'); $r++) {
                $v = fn (string $col) => $this->text($ws->getCell($col.$r)->getValue());
                $serial = $v('C');
                $desc = $v('D');
                if (! $serial && ! $desc) {
                    continue;
                }
                $st['rows']++;
                $sheetNo++;
                $npbgNo = $this->docNo($ws->getCell("J$r")->getValue());
                $itemNo = $this->num($ws->getCell("K$r")->getValue());
                $riNo = $this->docNo($ws->getCell("Q$r")->getValue());
                $date = $this->date($ws->getCell("I$r")->getValue(), $npbgNo);

                // this Excel row again (re-run)?
                $row = StppTransaction::where('source', 'EXCEL')->where('serial_no_raw', $serial)->where('out_npbg_no', $npbgNo)
                    ->when($itemNo !== null, fn ($q) => $q->where('out_item_no', (int) $itemNo))->first();
                // its NPBG line: one with quantity left, the most alike description
                $lines = $npbgNo && $npbgNo !== '-' ? Npbg::where('no_npbg', $npbgNo)->orderBy('accurate_seq')->get(['id', 'deskripsi_barang', 'kuantitas']) : collect();
                $lineId = $this->assignLine($lines, $desc, (float) ($this->num($ws->getCell("E$r")->getValue()) ?? 1), $used)?->id;
                $row ? $st['updated']++ : $st['created']++;
                $row ??= new StppTransaction(['number' => $this->numbers->next('STPP', 'SDA', \Illuminate\Support\Carbon::parse($date ?? now()))]);

                $unitRaw = $v('F');
                $row->fill([
                    'sheet_no' => $sheetNo,
                    'serial_no_raw' => $serial,
                    'description_raw' => $desc ?? '-',
                    'qty' => $this->num($ws->getCell("E$r")->getValue()) ?? 1,
                    'unit_raw' => $unitRaw,
                    'unit_id' => $unitRaw ? $units->get(mb_strtoupper($unitRaw)) : null,
                    'holder_name_raw' => $v('G'),
                    'placement_raw' => $v('H'),
                    'out_date' => $date ?? $row->out_date ?? now()->toDateString(),
                    'out_npbg_no' => $npbgNo,
                    'out_npbg_id' => $lineId,
                    'out_item_no' => $itemNo !== null ? (int) $itemNo : null,
                    'out_file_name' => $v('L'),
                    'out_note' => $v('N') ? mb_substr($v('N'), 0, 255) : null,
                    'return_date' => $this->date($ws->getCell("P$r")->getValue(), $riNo),
                    'return_ri_no' => $riNo,
                    'return_item_no' => ($n = $this->num($ws->getCell("R$r")->getValue())) !== null ? (int) $n : null,
                    'return_file_name' => $v('S'),
                    'return_note' => $v('U') ? mb_substr($v('U'), 0, 255) : null,
                    'source' => 'EXCEL',
                ]);
                if ($serial) {
                    $unit = SerialUnit::firstOrCreate(['serial_no' => $serial, 'kind' => 'STPP_TOOL'], ['description_raw' => $desc]);
                    $row->serial_unit_id = $unit->id;
                }
                $row->status = 'ACTIVE';
                $row->save();
                $st['npbg_linked'] += (bool) $lineId;

                foreach (['out' => [$outIdx, 'out_file_name'], 'in' => [$inIdx, 'return_file_name']] as $slot => [$idx, $col]) {
                    if (! $row->{$col} || $this->photos->has(self::ENTITY, $row->id, $slot)) {
                        continue;
                    }
                    $src = $this->findEvidence($idx, $row->{$col});
                    if (! $src) {
                        $st['missing_'.$slot][] = $row->{$col};

                        continue;
                    }
                    $dryRun || $this->photos->put(self::ENTITY, $row->id, $slot, file_get_contents($src), pathinfo($src, PATHINFO_EXTENSION), $row->{$col}, 'EXCEL');
                    $st['photo_'.$slot]++;
                }

                // Status: what the Excel formula shows; else the same formula
                $row->status = match ($this->cached($ws->getCell("O$r"))) {
                    'ACTIVE' => 'ACTIVE', 'PASSIVE' => 'PASSIVE',
                    default => StppTransaction::excelStatus($npbgNo, (bool) $row->out_file_name, $riNo, (bool) $row->return_file_name),
                };
                $row->save();
                $row->serialUnit?->update(['status' => $row->status === 'PASSIVE' ? 'IN_STOCK' : 'IN_USE']);
                $st[$row->status]++;
            }

            // STPP rows made from Accurate that the Excel does not have continue the "No" after it
            $next = (int) StppTransaction::max('sheet_no');
            StppTransaction::whereNull('sheet_no')->orderBy('out_date')->orderBy('id')->get()
                ->each(function ($s) use (&$next) { $s->forceFill(['sheet_no' => ++$next])->saveQuietly(); });

            $st['maintenance_rows'] = $this->maintenance($book->getSheetByName('Maintenance'));
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $st;
    }

    /** Sheet "Maintenance" (Maintenance STPP): No Seri, Peminta, Tgl NPBG, No NPBG, Keterangan. */
    private function maintenance(?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): int
    {
        if (! $ws) {
            return 0;
        }
        $n = 0;
        for ($r = 6; $r <= $ws->getHighestDataRow('C'); $r++) {
            $serial = $this->text($ws->getCell("C$r")->getValue());
            if (! $serial) {
                continue;
            }
            $n++;
            $npbg = $this->docNo($ws->getCell("I$r")->getValue());
            DB::table('stpp_maintenance_logs')->updateOrInsert(
                ['source' => 'EXCEL', 'sheet_no' => $n],
                [
                    'serial_no_raw' => $serial,
                    'requester' => $this->text($ws->getCell("G$r")->getValue()),
                    'npbg_date' => $this->date($ws->getCell("H$r")->getValue()),
                    'npbg_no' => $npbg,
                    'npbg_id' => $npbg && $npbg !== '-' ? Npbg::where('no_npbg', $npbg)->orderBy('id')->value('id') : null,
                    'note' => $this->text($ws->getCell("J$r")->getValue()),
                    'updated_at' => now(), 'created_at' => now(),
                ],
            );
        }

        return $n;
    }
}
