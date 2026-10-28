<?php

namespace App\Services\Tracking\Import;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderSub;
use App\Models\Npbg;
use App\Models\Site;
use App\Services\Tracking\MaintenanceService;
use App\Services\Tracking\TrackingPhotoService;
use App\Support\MaintenanceExcel;
use App\Support\ManufacturingExcel;
use App\Support\WpsCellImages;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "6. Tracking Maintenance Assets.xlsx" (sheet "Maintenance Kendaraan") into
 * Maintenance: one Sub SPK per Excel row, as typed. Permintaan (the picture in the
 * cell), Foto Sebelum (DATA BUKTI/…/Laporan kerusakan/<Nama File>) and Foto Sesudah
 * (…/Selesai pengerjaan/<Nama File 2>) become tracking photos. Re-runnable: a row
 * is its No. SPK + Sub SPK (+ how many times that pair came before).
 */
class MaintenanceExcelImporter
{
    use ReadsExcelCells;

    public function __construct(private readonly TrackingPhotoService $photos, private readonly MaintenanceService $service) {}

    /** @return array<string, int|array> */
    public function import(string $xlsx, ?string $photoDir, bool $dryRun = false): array
    {
        $reader = IOFactory::createReaderForFile($xlsx);
        $reader->setReadDataOnly(true);
        $ws = $reader->load($xlsx)->getSheetByName('Maintenance Kendaraan')
            ?? throw new \RuntimeException('Sheet "Maintenance Kendaraan" tidak ada.');
        $images = new WpsCellImages($xlsx);
        $before = $photoDir ? TrackingPhotoService::indexFolder($photoDir.'/Laporan kerusakan') : [];
        $after = $photoDir ? TrackingPhotoService::indexFolder($photoDir.'/Selesai pengerjaan') : [];

        $st = ['rows' => 0, 'orders' => 0, 'created' => 0, 'updated' => 0, 'npbg_linked' => 0,
            'photo_request' => 0, 'photo_before' => 0, 'photo_after' => 0, 'missing_before' => [], 'missing_after' => [],
            'REQUESTED' => 0, 'ON_GOING' => 0, 'COMPLETED' => 0, 'INCOMPLETE' => 0];
        $siteId = Site::query()->orderBy('id')->value('id');
        $orders = [];
        $seen = [];
        $sheetNo = 0;

        DB::beginTransaction();
        try {
            for ($r = 6; $r <= $ws->getHighestDataRow('D'); $r++) {
                $number = strtoupper(str_replace(' ', '', (string) $this->text($ws->getCell("D$r")->getValue())));
                if ($number === '') {
                    continue;
                }
                $st['rows']++;
                $sheetNo++;
                $v = fn (string $col) => $this->text($ws->getCell($col.$r)->getValue());
                $date = $this->date($ws->getCell("C$r")->getValue());
                $nopol = $v('F') ? mb_strtoupper($v('F')) : null;

                $order = $orders[$number] ??= MaintenanceOrder::firstOrNew(['number' => $number]);
                if (! $order->exists) {
                    $order->fill(['report_date' => $date ?? now()->toDateString(), 'nopol_raw' => $nopol, 'site_id' => $siteId,
                        'problem_summary' => mb_substr((string) $v('G'), 0, 255) ?: null, 'status' => ManufacturingExcel::REQUESTED])->save();
                    $st['orders']++;
                }

                $subNo = strtoupper((string) ($v('E') ?? 'SUB-01'));
                $nth = $seen[$number.'|'.$subNo] = ($seen[$number.'|'.$subNo] ?? 0) + 1;
                $sub = MaintenanceOrderSub::where(['maintenance_order_id' => $order->id, 'sub_no' => $subNo])->orderBy('id')->skip($nth - 1)->first()
                    ?? new MaintenanceOrderSub(['maintenance_order_id' => $order->id, 'sub_no' => $subNo]);
                $sub->exists ? $st['updated']++ : $st['created']++;

                $request = $ws->getCell("L$r")->getValue();
                $requestImage = WpsCellImages::idOf($request);
                $npbg = $this->docNo($ws->getCell("N$r")->getValue());
                $sub->fill([
                    'sheet_no' => $sheetNo,
                    'report_date' => $date,
                    'nopol_raw' => $nopol,
                    'problem_detail' => $v('G'),
                    'before_file_name' => $v('H'),
                    'workshop_raw' => $v('K'),
                    'request_note' => $requestImage ? null : $this->text($request),
                    'npbg_no' => $npbg,
                    'npbg_id' => $npbg && $npbg !== '-' ? Npbg::where('no_npbg', $npbg)->orderBy('id')->value('id') : null,
                    'after_file_name' => $v('O'),
                    'finish_date' => $this->date($ws->getCell("R$r")->getValue()),
                    'result_note' => $v('S'),
                    'source' => 'EXCEL',
                ]);
                $sub->save();
                $st['npbg_linked'] += (bool) $sub->npbg_id;

                // Permintaan: the picture placed in the cell
                if ($requestImage && ($img = $images->get($requestImage)) && ! $this->photos->has(MaintenanceService::ENTITY, $sub->id, 'request')) {
                    $dryRun || $this->photos->put(MaintenanceService::ENTITY, $sub->id, 'request', $img['bytes'], $img['ext'], 'Permintaan '.$number.' '.$subNo, 'EXCEL');
                    $st['photo_request']++;
                }
                foreach (['before' => [$before, 'before_file_name', 'I'], 'after' => [$after, 'after_file_name', 'P']] as $slot => [$index, $col, $extCol]) {
                    if (! $sub->{$col} || $this->photos->has(MaintenanceService::ENTITY, $sub->id, $slot)) {
                        continue;
                    }
                    $src = $this->pick($index[TrackingPhotoService::key($sub->{$col})] ?? []);
                    if (! $src) {
                        $st['missing_'.$slot][] = $sub->{$col}.$v($extCol);

                        continue;
                    }
                    $dryRun || $this->photos->put(MaintenanceService::ENTITY, $sub->id, $slot, file_get_contents($src), pathinfo($src, PATHINFO_EXTENSION), $sub->{$col}, 'EXCEL');
                    $st['photo_'.$slot]++;
                }

                // Status Hasil Pengerjaan: the value the Excel formula shows; else the same formula here
                $sub->status = match ($this->cached($ws->getCell("M$r"))) {
                    'COMPLETED' => ManufacturingExcel::COMPLETED, 'ON-GOING' => ManufacturingExcel::ON_GOING,
                    'REQUESTED' => ManufacturingExcel::REQUESTED,
                    default => MaintenanceExcel::status($sub->npbg_no, (bool) $sub->after_file_name, $sub->finish_date?->toDateString(), $requestImage || $sub->request_note),
                };
                $sub->save();
                $st[$sub->status]++;
                $this->bumpSequence($number);
            }
            foreach ($orders as $order) {
                $this->service->rollUp($order);
                $order->update(['report_date' => $order->subs()->min('report_date') ?? $order->report_date]);
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $st;
    }
}
