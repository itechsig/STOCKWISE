<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Export\Legacy\LegacyExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Replika file Excel lama, diisi data live STOCKWISE — lihat docs/excel-data-mapping.md.
 * Tujuan: migrasi penuh ke web; file ini hanya dipakai bila staf masih perlu bentuk Excel asli
 * (arsip, dibagikan ke pihak luar, dsb).
 */
class LegacyExportController extends Controller
{
    /** key => [method di service, nama file asli, permission modul] */
    private const MAP = [
        'data' => ['dataMaster', 'DATA.xlsx', 'report.inventory'],
        'npbg' => ['npbg', '2. NPBG.xlsx', 'report.npbg'],
        'borrow-lend' => ['borrowLend', '3. Tracking Borrow & Lend.xlsx', 'lend.view|borrow.view'],
        'stpp' => ['stpp', '4. Tracking STPP.xlsx', 'stpp.view'],
        'ban-luar' => ['banLuar', '5. Tracking Ban Luar.xlsx', 'tyre.view'],
        'maintenance-assets' => ['maintenanceAssets', '6. Tracking Maintenance Assets.xlsx', 'maintenance.view'],
        'manufaktur-assembly' => ['manufakturAssembly', '7. Tracking Manufaktur & Assembly.xlsx', 'manufacturing.view'],
        'pengembalian-bekas' => ['pengembalianBekas', '8. Tracking Pengembalian Bekas.xlsx', 'used_return.view'],
    ];

    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(self::MAP)->map(fn ($v, $k) => [
            'key' => $k, 'filename' => $v[1],
        ])->values()]);
    }

    public function __invoke(Request $request, string $key, LegacyExcelExportService $service): StreamedResponse
    {
        abort_unless(isset(self::MAP[$key]), 404);
        [$method, $filename, $permission] = self::MAP[$key];

        $user = $request->user();
        $allowed = collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermission($p));
        abort_unless($allowed, 403);
        abort_unless($user->hasPermission('export.excel'), 403);

        // DATA.xlsx meliput ~9.000 barang x 13 sheet — beri ruang seperti stockwise:import.
        ini_set('memory_limit', '1024M');
        set_time_limit(300);
        $book = $service->{$method}();

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
