<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowTransaction;
use App\Models\Inventory;
use App\Models\InventoryAnalysisRun;
use App\Models\InventorySnapshot;
use App\Models\LendTransaction;
use App\Models\MaintenanceOrder;
use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\Ri;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ringkasan per-peran untuk halaman Dashboard (brief §AE, §W).
 * Satu endpoint; blok yang dikembalikan menyesuaikan permission user.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $cards = [];
        $lists = [];
        $charts = [];

        // ---- Inventory / analisa (semua peran gudang + eksekutif) ----
        if ($user->hasPermission('inventory.view') || $user->hasPermission('inventory.view_analysis')) {
            $run = InventoryAnalysisRun::latest('computed_at')->first();
            $belowSafety = Inventory::query()
                ->join('items', 'items.id', '=', 'inventory.item_id')
                ->leftJoin('item_safety_stocks', function ($j) {
                    $j->on('item_safety_stocks.item_id', '=', 'items.id')->where('item_safety_stocks.is_effective', true);
                })
                ->whereRaw('(inventory.actual_qty - inventory.reserved_qty) < COALESCE(item_safety_stocks.safety_stock, 0)')
                ->where('inventory.stock_known', true)
                ->distinct('inventory.item_id')
                ->count('inventory.item_id');

            $cards[] = ['key' => 'items_tracked', 'label' => 'Item dianalisa', 'value' => $run?->item_count ?? 0, 'tone' => 'default'];
            $cards[] = ['key' => 'below_safety', 'label' => 'Di bawah Safety Stock', 'value' => $belowSafety, 'tone' => $belowSafety > 0 ? 'danger' : 'success'];
            if ($run) {
                // Business rule (user, 2026-09-29): BEP (stok habis, tidak dipakai)
                // counts as AMAN — only TIDAK_AMAN is a problem.
                $byStatus = InventorySnapshot::query()->whereNull('warehouse_id')
                    ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
                $cards[] = ['key' => 'tidak_aman', 'label' => 'Status TIDAK AMAN', 'value' => (int) ($byStatus['TIDAK_AMAN'] ?? 0), 'tone' => 'danger'];
                $cards[] = ['key' => 'aman', 'label' => 'Status AMAN', 'value' => (int) ($byStatus['AMAN'] ?? 0) + (int) ($byStatus['BEP'] ?? 0), 'tone' => 'success'];
            }
        }

        // ---- Opname ----
        if ($user->hasPermission('opname.view')) {
            $cards[] = ['key' => 'opname_active', 'label' => 'Opname aktif', 'tone' => 'default',
                // STOCKWISE's own opnames still in the count/review flow ("SUBMITTED"
                // never existed — a submitted opname is PENDING_REVIEW). Accurate
                // adjustments are finished documents, never "active".
                'value' => StockOpname::whereNull('accurate_itemadj_id')
                    ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS', 'PENDING_REVIEW', 'RECOUNT_REQUIRED'])->count()];
        }

        // ---- Purchasing ----
        if ($user->hasPermission('po.view')) {
            $cards[] = ['key' => 'po_open', 'label' => 'PO berjalan', 'tone' => 'default',
                'value' => PurchaseOrder::whereIn('status', ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED'])->count()];
        }

        // ---- Purchasing dari Accurate (PPB/PO/RI mirror) ----
        if ($user->hasPermission('ppb.view') || $user->hasPermission('ri.view') || $user->hasPermission('po.view')) {
            if ($user->hasPermission('ppb.view')) {
                // lines still waiting for goods: Requested + Shortage (Excel "1. PPB - RI")
                $cards[] = ['key' => 'ppb_accurate_open', 'label' => 'PPB menunggu barang', 'tone' => 'warning',
                    // an amended line is not going to be bought as asked — not "waiting"
                    'value' => Ppb::whereIn('line_status', ['REQUESTED', 'SHORTAGE'])->where('is_amend', false)->count()];
            }
            if ($user->hasPermission('ri.view')) {
                $cards[] = ['key' => 'ri_accurate_month', 'label' => 'RI bulan ini (Accurate)', 'tone' => 'default',
                    // company calendar (WIB), not the app's UTC clock
                    'value' => Ri::whereBetween('tgl_ri', [
                        now('Asia/Jakarta')->startOfMonth()->toDateString(),
                        now('Asia/Jakarta')->endOfMonth()->toDateString(),
                    ])->count()];
            }
        }

        // PO status chart is part of the dashboard visualisation every role sees (user decision 2026-09-29).
        $poStatus = PurchaseOrder::query()->whereNotNull('accurate_po_id')
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        if ($poStatus->isNotEmpty()) {
            $charts['po_status'] = $poStatus->map(fn ($c, $s) => ['name' => $s, 'value' => (int) $c])->values();
        }

        // ---- Tracking ----
        if ($user->hasPermission('lend.view')) {
            $cards[] = ['key' => 'lend_open', 'label' => 'Barang dipinjamkan', 'tone' => 'default',
                'value' => LendTransaction::whereIn('status', ['ON_LOAN', 'PARTIAL_RETURN', 'OVERDUE'])->count()];
            $overdue = LendTransaction::where('status', 'OVERDUE')->count();
            if ($overdue > 0) {
                $cards[] = ['key' => 'lend_overdue', 'label' => 'Pinjaman lewat tempo', 'tone' => 'danger', 'value' => $overdue];
            }
        }
        if ($user->hasPermission('borrow.view')) {
            $cards[] = ['key' => 'borrow_open', 'label' => 'Pinjaman luar aktif', 'tone' => 'default',
                'value' => BorrowTransaction::whereIn('status', ['BORROWED', 'PARTIAL'])->count()];
        }
        if ($user->hasPermission('maintenance.view')) {
            $cards[] = ['key' => 'spk_open', 'label' => 'SPK berjalan', 'tone' => 'default',
                'value' => MaintenanceOrder::whereIn('status', ['REQUESTED', 'ON_GOING'])->count()];
        }

        // ---- Executive: pergerakan stok 14 hari ----
        if ($user->hasPermission('dashboard.executive') || $user->hasPermission('report.stock_movement')) {
            $since = Carbon::today()->subDays(13);
            $rows = StockMovement::query()
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) d, direction, count(*) c')
                ->groupBy('d', 'direction')->get();
            $charts['stock_movement_14d'] = collect(range(0, 13))->map(function ($i) use ($since, $rows) {
                $d = $since->copy()->addDays($i)->toDateString();

                return [
                    'name' => $d,
                    'in' => (int) $rows->where('d', $d)->where('direction', 1)->sum('c'),
                    'out' => (int) $rows->where('d', $d)->where('direction', -1)->sum('c'),
                ];
            });
        }

        return response()->json([
            'data' => [
                'role' => $user->roles->pluck('slug'),
                'generated_at' => now()->toIso8601String(),
                'cards' => $cards,
                'charts' => $charts,
                'lists' => $lists,
            ],
        ]);
    }
}
