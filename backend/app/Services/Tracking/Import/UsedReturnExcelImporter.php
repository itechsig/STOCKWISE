<?php

namespace App\Services\Tracking\Import;

use App\Models\Npbg;
use App\Services\Tracking\TrackingPhotoService;
use App\Services\Tracking\UsedReturnSheets as S;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "8. Tracking Pengembalian Bekas.xlsx" into the Pengembalian Bekas sheets:
 *   "Spare Part"      (row 7+)  → used_return_matrix_rows
 *   "Spare Part Lain" (row 6+)  → used_return_other_rows + Foto Keluar / Foto Terima
 *                                 (DATA BUKTI/Foto - Pengembalian Bekas/**\/<Nama File>.jpg)
 * Values exactly as typed; status = the value the Excel formula shows. Re-runnable (row = sheet position).
 */
class UsedReturnExcelImporter
{
    use ReadsExcelCells;

    public function __construct(private readonly TrackingPhotoService $photos) {}

    public function import(string $xlsx, ?string $photoDir, bool $dryRun = false): array
    {
        $reader = IOFactory::createReaderForFile($xlsx);
        $reader->setReadDataOnly(true);
        $book = $reader->load($xlsx);
        $idx = $photoDir ? TrackingPhotoService::indexFolder($photoDir) : [];
        $st = ['spare_part' => 0, 'spare_part_lain' => 0, 'npbg_linked' => 0, 'photo_out' => 0, 'photo_in' => 0,
            'missing_out' => [], 'missing_in' => [], 'CLEAR' => 0, 'PENDING' => 0, 'INCOMPLETE' => 0];
        $npbgId = fn (?string $no) => $no && $no !== '-' ? Npbg::where('no_npbg', $no)->orderBy('id')->value('id') : null;

        DB::beginTransaction();
        try {
            // ---- "Spare Part": No | Tgl NPBG | No NPBG | Status | Tgl RI | No RI | 14 components | Keterangan
            if ($ws = $book->getSheetByName('Spare Part')) {
                $n = 0;
                for ($r = 7; $r <= $ws->getHighestDataRow('D'); $r++) {
                    $npbg = $this->docNo($ws->getCell("D$r")->getValue());
                    $ri = $this->docNo($ws->getCell("G$r")->getValue());
                    if (! $npbg && ! $ri) {
                        continue;
                    }
                    $n++;
                    $components = [];
                    foreach (array_keys(S::COMPONENTS) as $i => $code) {
                        $q = $this->num($ws->getCell(chr(ord('H') + $i).$r)->getValue());
                        if ($q !== null && $q != 0) {
                            $components[$code] = $q;
                        }
                    }
                    $status = match ($this->cached($ws->getCell("E$r"))) {
                        'CLEAR' => 'CLEAR', 'PENDING' => 'PENDING', default => S::matrixStatus($npbg, $ri),
                    };
                    $data = [
                        'npbg_date' => $this->date($ws->getCell("C$r")->getValue(), $npbg),
                        'npbg_no' => $npbg, 'npbg_id' => $npbgId($npbg),
                        'status' => $status,
                        'ri_date' => $this->date($ws->getCell("F$r")->getValue(), $ri),
                        'ri_no' => $ri,
                        'components' => json_encode($components ?: (object) []),
                        'note' => $this->text($ws->getCell("V$r")->getValue()),
                        'updated_at' => now(),
                    ];
                    $id = DB::table(S::MATRIX)->where(['source' => 'EXCEL', 'sheet_no' => $n])->value('id');
                    $id ? DB::table(S::MATRIX)->where('id', $id)->update($data)
                        : DB::table(S::MATRIX)->insert($data + ['source' => 'EXCEL', 'sheet_no' => $n, 'created_at' => now()]);
                    $st['npbg_linked'] += (bool) $data['npbg_id'];
                    $st[$status]++;
                }
                $st['spare_part'] = $n;
            }

            // ---- "Spare Part Lain": No | Tgl NPBG | No NPBG | Deskripsi | Kuantitas | Satuan | Item No | Nama File | Foto Keluar
            //                          | Status | No RI | Item No2 | Nama File 2 | Foto Terima | Keterangan
            if ($ws = $book->getSheetByName('Spare Part Lain')) {
                $n = 0;
                for ($r = 6; $r <= $ws->getHighestDataRow('D'); $r++) {
                    $npbg = $this->docNo($ws->getCell("D$r")->getValue());
                    $desc = $this->text($ws->getCell("E$r")->getValue());
                    if (! $npbg && ! $desc) {
                        continue;
                    }
                    $n++;
                    $ri = $this->docNo($ws->getCell("L$r")->getValue());
                    $outName = $this->text($ws->getCell("I$r")->getValue());
                    $inName = $this->text($ws->getCell("N$r")->getValue());
                    $status = match ($this->cached($ws->getCell("K$r"))) {
                        'CLEAR' => 'CLEAR', 'PENDING' => 'PENDING', default => S::otherStatus($npbg, $ri, (bool) $inName),
                    };
                    $data = [
                        'npbg_date' => $this->date($ws->getCell("C$r")->getValue(), $npbg),
                        'npbg_no' => $npbg, 'npbg_id' => $npbgId($npbg),
                        'description' => $desc ? mb_substr($desc, 0, 400) : null,
                        'qty' => $this->num($ws->getCell("F$r")->getValue()),
                        'unit' => $this->text($ws->getCell("G$r")->getValue()),
                        'item_no' => ($x = $this->num($ws->getCell("H$r")->getValue())) !== null ? (int) $x : null,
                        'out_file_name' => $outName,
                        'status' => $status,
                        'ri_no' => $ri,
                        'return_item_no' => ($x = $this->num($ws->getCell("M$r")->getValue())) !== null ? (int) $x : null,
                        'in_file_name' => $inName,
                        // a word typed in the date column ("NGUTANG") is kept with the keterangan
                        'note' => $this->withDateWord($this->text($ws->getCell("P$r")->getValue()), $ws->getCell("C$r")->getValue(), 'Tgl NPBG'),
                        'updated_at' => now(),
                    ];
                    $id = DB::table(S::OTHER)->where(['source' => 'EXCEL', 'sheet_no' => $n])->value('id');
                    $id ? DB::table(S::OTHER)->where('id', $id)->update($data)
                        : $id = DB::table(S::OTHER)->insertGetId($data + ['source' => 'EXCEL', 'sheet_no' => $n, 'created_at' => now()]);
                    $st['npbg_linked'] += (bool) $data['npbg_id'];
                    $st[$status]++;

                    foreach (['out' => $outName, 'in' => $inName] as $slot => $name) {
                        if (! $name || $this->photos->has(S::PHOTO_ENTITY, $id, $slot)) {
                            continue;
                        }
                        $src = $this->findEvidence($idx, $name, $slot === 'out' ? 'Foto Keluar' : 'Foto Terima');
                        if (! $src) {
                            $st['missing_'.$slot][] = $name;

                            continue;
                        }
                        $dryRun || $this->photos->put(S::PHOTO_ENTITY, $id, $slot, file_get_contents($src), pathinfo($src, PATHINFO_EXTENSION), $name, 'EXCEL');
                        $st['photo_'.$slot]++;
                    }
                }
                $st['spare_part_lain'] = $n;
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $st;
    }
}
