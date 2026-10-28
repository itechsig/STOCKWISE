<?php

namespace App\Services\Tracking\Import;

use App\Models\Npbg;
use App\Models\Ri;
use App\Models\TyreChange;
use App\Services\Tracking\TrackingPhotoService;
use App\Services\Tracking\TyreFromAccurate;
use App\Support\WpsCellImages;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "5. Tracking Ban Luar.xlsx" into Ban Luar.
 *  - sheet "Ban Luar": one tyre per row. A row whose NPBG line STOCKWISE already
 *    turned into a tyre change (from Accurate) takes that row over (source EXCEL:
 *    the sync leaves it alone from then on); "ORIGIN" rows are the opening data.
 *    Foto Ban Out / In: DATA BUKTI/Foto - Tracking Ban Luar/NPBG Ban|RI Ban/<Nama File>.jpg
 *  - sheet "Ban Luar BPN" → tyre_bpn_inventory (+ the picture in the cell)
 *  - sheet "Deliver & Receive Ban SIG-BPN" → tyre_bpn_transfers (+ pictures)
 * Re-runnable.
 */
class TyreExcelImporter
{
    use ReadsExcelCells;

    public function __construct(private readonly TrackingPhotoService $photos) {}

    public function import(string $xlsx, ?string $photoDir, bool $dryRun = false): array
    {
        $reader = IOFactory::createReaderForFile($xlsx);
        $reader->setReadDataOnly(true);
        $book = $reader->load($xlsx);
        $ws = $book->getSheetByName('Ban Luar') ?? throw new \RuntimeException('Sheet "Ban Luar" tidak ada.');
        $images = new WpsCellImages($xlsx);
        $outIdx = $photoDir ? TrackingPhotoService::indexFolder($photoDir.'/NPBG Ban') : [];
        $inIdx = $photoDir ? TrackingPhotoService::indexFolder($photoDir.'/RI Ban') : [];

        $st = ['rows' => 0, 'opening' => 0, 'created' => 0, 'updated' => 0, 'replaced_accurate' => 0, 'npbg_linked' => 0, 'ri_linked' => 0,
            'photo_out' => 0, 'photo_in' => 0, 'missing_out' => [], 'missing_in' => [],
            'CLEAR' => 0, 'PENDING_RI' => 0, 'INCOMPLETE' => 0, 'bpn_inventory' => 0, 'bpn_transfers' => 0, 'bpn_photos' => 0];
        $sheetNo = 0;

        DB::beginTransaction();
        try {
            // The Excel is the record of every NPBG it lists: the tyres STOCKWISE derived
            // from those documents (Accurate) are replaced by the Excel rows, and the
            // Excel rows are matched to their NPBG lines afresh on every run.
            $docs = [];
            for ($r = 6; $r <= $ws->getHighestDataRow('C'); $r++) {
                $no = $this->docNo($ws->getCell("E$r")->getValue());
                if ($no && ! in_array($no, ['-', 'ORIGIN'], true)) {
                    $docs[$no] = true;
                }
            }
            $lineIds = Npbg::whereIn('no_npbg', array_keys($docs))->pluck('id');
            $replaced = TyreChange::where('source', TyreFromAccurate::SOURCE)->whereIn('out_npbg_id', $lineIds)->get();
            foreach ($replaced as $old) {
                foreach (\App\Models\TrackingPhoto::where(['entity' => 'tyre', 'entity_id' => $old->id])->get() as $ph) {
                    $this->photos->delete($ph);
                }
                $old->delete();
            }
            $st['replaced_accurate'] = $replaced->count();
            TyreChange::where('source', 'EXCEL')->update(['out_npbg_id' => null, 'change_seq' => null]);
            $used = [];

            for ($r = 6; $r <= $ws->getHighestDataRow('C'); $r++) {
                $v = fn (string $col) => $this->text($ws->getCell($col.$r)->getValue());
                $nopol = $v('C') ? mb_strtoupper($v('C')) : null;
                $newSerial = $v('G');
                if (! $nopol && ! $newSerial) {
                    continue;
                }
                $st['rows']++;
                $sheetNo++;
                $npbgNo = $this->docNo($ws->getCell("E$r")->getValue());
                $opening = $npbgNo === 'ORIGIN';
                $st['opening'] += $opening;
                $riNo = $this->docNo($ws->getCell("O$r")->getValue());
                $desc = $v('F');

                // this Excel row again (re-run)?
                $t = TyreChange::where('source', 'EXCEL')->where('sheet_no', $sheetNo)->where('nopol_raw', $nopol)->first();
                $t ? $st['updated']++ : $st['created']++;
                $t ??= new TyreChange;
                // its NPBG line: one with a tyre left, the most alike description (one tyre = qty 1)
                $lines = ! $opening && $npbgNo && $npbgNo !== '-'
                    ? Npbg::where('no_npbg', $npbgNo)->orderBy('accurate_seq')->get(['id', 'deskripsi_barang', 'kuantitas'])
                    : collect();
                // the tyre lines of the document (all its lines when none reads "BAN")
                $tyreLines = $lines->filter(fn ($l) => str_contains(mb_strtoupper((string) $l->deskripsi_barang), 'BAN'));
                $lines = $tyreLines->isNotEmpty() ? $tyreLines : $lines;
                $line = $this->assignLine($lines, $desc, 1, $used);
                // an NPBG line takes one slot per tyre (the sync counts them the same way)
                $t->change_seq = $line ? 1 + (int) TyreChange::where('out_npbg_id', $line->id)->max('change_seq') : null;

                $ri = $riNo && ! in_array($riNo, ['-', 'ORIGIN'], true) ? Ri::where('no_ri', $riNo)->orderBy('id')->first(['id']) : null;
                $t->fill([
                    'sheet_no' => $sheetNo,
                    'nopol_raw' => $nopol,
                    'change_date' => $this->date($ws->getCell("D$r")->getValue()) ?? $t->change_date ?? now()->toDateString(),
                    'out_npbg_no' => $npbgNo,
                    'out_npbg_id' => $line?->id,
                    'new_tyre_desc' => $desc ? mb_substr($desc, 0, 300) : null,
                    'new_serial_raw' => $newSerial ? mb_substr($newSerial, 0, 80) : null,
                    'new_file_name' => $v('H'),
                    'tyre_no' => ($n = $this->num($ws->getCell("J$r")->getValue())) !== null ? (int) $n : null,
                    'replacement_no' => ($n = $this->num($ws->getCell("K$r")->getValue())) !== null ? (int) $n : null,
                    'out_note' => $v('L') ? mb_substr($v('L'), 0, 500) : null,
                    'in_date' => $this->date($ws->getCell("N$r")->getValue()),
                    'in_ri_no' => $riNo,
                    'in_ri_id' => $ri?->id,
                    'old_tyre_desc' => $v('P') ? mb_substr($v('P'), 0, 300) : null,
                    'old_serial_raw' => $v('Q') ? mb_substr($v('Q'), 0, 80) : null,
                    'old_file_name' => $v('R'),
                    'in_note' => $v('T') ? mb_substr($v('T'), 0, 500) : null,
                    'is_opening' => $opening,
                    'source' => 'EXCEL',
                ]);
                // Status: what the Excel formula shows; else the same formula
                $t->status = match ($this->cached($ws->getCell("M$r"))) {
                    'CLEAR' => 'CLEAR', 'PENDING RI' => 'PENDING_RI',
                    default => TyreChange::excelStatus($npbgNo, $t->new_serial_raw, $riNo, $t->old_serial_raw),
                };
                $t->save();
                $st['npbg_linked'] += (bool) $t->out_npbg_id;
                $st['ri_linked'] += (bool) $t->in_ri_id;
                $st[$t->status]++;

                foreach (['out' => [$outIdx, 'new_file_name'], 'in' => [$inIdx, 'old_file_name']] as $slot => [$idx, $col]) {
                    if (! $t->{$col} || $t->{$col} === '-' || $this->photos->has('tyre', $t->id, $slot)) {
                        continue;
                    }
                    $src = $this->findEvidence($idx, $t->{$col});
                    if (! $src) {
                        $st['missing_'.$slot][] = $t->{$col};

                        continue;
                    }
                    $dryRun || $this->photos->put('tyre', $t->id, $slot, file_get_contents($src), pathinfo($src, PATHINFO_EXTENSION), $t->{$col}, 'EXCEL');
                    $st['photo_'.$slot]++;
                }
            }

            // tyres from Accurate the Excel does not have continue the "No" after it
            $next = (int) TyreChange::max('sheet_no');
            TyreChange::whereNull('sheet_no')->orderBy('change_date')->orderBy('id')->get()
                ->each(function ($t) use (&$next) { $t->forceFill(['sheet_no' => ++$next])->saveQuietly(); });

            [$st['bpn_inventory'], $p1] = $this->bpnInventory($book->getSheetByName('Ban Luar BPN'), $images, $dryRun);
            [$st['bpn_transfers'], $p2] = $this->bpnTransfers($book->getSheetByName('Deliver & Receive Ban SIG-BPN'), $images, $dryRun);
            $st['bpn_photos'] = $p1 + $p2;
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $st;
    }

    /** "Ban Luar BPN": NO | TANGGAL CUT OFF | NOPOL | DESKRIPSI BAN | NO SERI | Foto | KETERANGAN (from row 7). */
    private function bpnInventory(?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, WpsCellImages $images, bool $dryRun): array
    {
        $n = $photos = 0;
        for ($r = 7; $ws && $r <= $ws->getHighestDataRow('C'); $r++) {
            $nopol = $this->text($ws->getCell("C$r")->getValue());
            if (! $nopol) {
                continue;
            }
            $n++;
            $data = [
                'cutoff_date' => $this->date($ws->getCell("B$r")->getValue()),
                'nopol_raw' => mb_strtoupper($nopol),
                'tyre_desc' => $this->text($ws->getCell("D$r")->getValue()),
                'serial_raw' => $this->text($ws->getCell("E$r")->getValue()),
                'note' => $this->text($ws->getCell("G$r")->getValue()),
                'updated_at' => now(),
            ];
            $id = DB::table('tyre_bpn_inventory')->where(['source' => 'EXCEL', 'sheet_no' => $n])->value('id');
            $id ? DB::table('tyre_bpn_inventory')->where('id', $id)->update($data)
                : $id = DB::table('tyre_bpn_inventory')->insertGetId($data + ['source' => 'EXCEL', 'sheet_no' => $n, 'created_at' => now()]);
            $photos += $this->cellPicture($images, $ws->getCell("F$r")->getValue(), 'tyre_bpn', $id, 'photo', $data['serial_raw'], $dryRun);
        }

        return [$n, $photos];
    }

    /** "Deliver & Receive Ban SIG-BPN": No | NOPOL | Tgl NPBG | NO NPBG | Deskripsi | No seri baru | Foto | Ket | Tgl RI | NO RI | Deskripsi | No seri bekas | Foto | Ket. */
    private function bpnTransfers(?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, WpsCellImages $images, bool $dryRun): array
    {
        $n = $photos = 0;
        for ($r = 7; $ws && $r <= $ws->getHighestDataRow('B'); $r++) {
            $nopol = $this->text($ws->getCell("B$r")->getValue());
            if (! $nopol) {
                continue;
            }
            $n++;
            $npbg = $this->docNo($ws->getCell("D$r")->getValue());
            $data = [
                'nopol_raw' => mb_strtoupper($nopol),
                'npbg_date' => $this->date($ws->getCell("C$r")->getValue()),
                'npbg_no' => $npbg,
                'npbg_id' => $npbg && $npbg !== '-' ? Npbg::where('no_npbg', $npbg)->orderBy('id')->value('id') : null,
                'new_tyre_desc' => $this->text($ws->getCell("E$r")->getValue()),
                'new_serial_raw' => $this->text($ws->getCell("F$r")->getValue()),
                'out_note' => $this->text($ws->getCell("H$r")->getValue()),
                'ri_date' => $this->date($ws->getCell("I$r")->getValue()),
                'ri_no' => $this->docNo($ws->getCell("J$r")->getValue()),
                'old_tyre_desc' => $this->text($ws->getCell("K$r")->getValue()),
                'old_serial_raw' => $this->text($ws->getCell("L$r")->getValue()),
                'in_note' => $this->text($ws->getCell("N$r")->getValue()),
                'updated_at' => now(),
            ];
            $id = DB::table('tyre_bpn_transfers')->where(['source' => 'EXCEL', 'sheet_no' => $n])->value('id');
            $id ? DB::table('tyre_bpn_transfers')->where('id', $id)->update($data)
                : $id = DB::table('tyre_bpn_transfers')->insertGetId($data + ['source' => 'EXCEL', 'sheet_no' => $n, 'created_at' => now()]);
            $photos += $this->cellPicture($images, $ws->getCell("G$r")->getValue(), 'tyre_bpn_transfer', $id, 'out', $data['new_serial_raw'], $dryRun);
            $photos += $this->cellPicture($images, $ws->getCell("M$r")->getValue(), 'tyre_bpn_transfer', $id, 'in', $data['old_serial_raw'], $dryRun);
        }

        return [$n, $photos];
    }

    private function cellPicture(WpsCellImages $images, mixed $cell, string $entity, int $id, string $slot, ?string $name, bool $dryRun): int
    {
        $img = $images->get(WpsCellImages::idOf($cell));
        if (! $img || $this->photos->has($entity, $id, $slot)) {
            return 0;
        }
        $dryRun || $this->photos->put($entity, $id, $slot, $img['bytes'], $img['ext'], $name, 'EXCEL');

        return 1;
    }
}
