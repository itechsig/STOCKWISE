<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard "Tracking & Pembelian" — GET /api/dashboard/operations.
 *
 * Aggregates are for every logged-in role (same as the inventory visualisation,
 * user decision 2026-09-29); the "perlu tindak lanjut" lists name documents, so
 * each one needs the view permission of its module.
 *
 * Months / ages use the company calendar (WIB). Month grouping is done in PHP so
 * the queries stay portable (MySQL + the SQLite test DB).
 */
class OperationsDashboardController extends Controller
{
    /** PPB lines still waiting for goods — same rule as the "PPB menunggu barang" card. */
    private const PPB_WAITING = ['REQUESTED', 'SHORTAGE'];

    /** Age buckets (days since the PPB date) of the waiting lines. */
    private const AGE_BUCKETS = [['0–7 hari', 0, 7], ['8–30 hari', 8, 30], ['31–60 hari', 31, 60], ['61–90 hari', 61, 90], ['> 90 hari', 91, PHP_INT_MAX]];

    private const LEND_OPEN = ['ON_LOAN', 'PARTIAL_RETURN', 'OVERDUE'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now('Asia/Jakarta')->startOfDay();
        // the 12 complete months before this one (a running month would read as a drop)
        $months = collect(range(12, 1))->map(fn ($i) => $today->copy()->startOfMonth()->subMonths($i)->format('Y-m'))->all();
        $from = $months[0].'-01';

        $attention = [];
        if ($user->hasPermission('ppb.view')) {
            $attention['ppb_oldest'] = $this->waitingPpb()->orderBy('tgl_ppb')->orderBy('id')->limit(6)
                ->get(['id', 'no_ppb', 'tgl_ppb', 'kode_barang', 'deskripsi_barang', 'line_status', 'kuantitas', 'qty_diterima', 'satuan'])
                ->map(fn ($p) => [
                    'id' => $p->id, 'no_ppb' => $p->no_ppb, 'date' => $p->tgl_ppb, 'item' => trim($p->kode_barang.' '.$p->deskripsi_barang),
                    'line_status' => $p->line_status, 'qty' => (float) $p->kuantitas, 'received' => (float) $p->qty_diterima, 'unit' => $p->satuan,
                    'age_days' => $this->age($p->tgl_ppb, $today),
                ]);
        }
        if ($user->hasPermission('lend.view')) {
            $attention['lend_overdue'] = $this->overdueLend($today)->orderBy('due_date')->orderBy('id')->limit(6)
                ->get(['id', 'number', 'out_doc_no', 'description_raw', 'borrower_name', 'due_date', 'qty', 'unit_raw'])
                ->map(fn ($l) => [
                    'id' => $l->id, 'number' => $l->number ?: $l->out_doc_no, 'item' => $l->description_raw, 'borrower' => $l->borrower_name,
                    'due_date' => $l->due_date, 'qty' => (float) $l->qty, 'unit' => $l->unit_raw,
                    'late_days' => $l->due_date ? $this->age($l->due_date, $today) : null,
                ]);
        }

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(),
            'tracking' => $this->tracking($today),
            'ppb_aging' => $this->ppbAging($today),
            'purchase_flow' => $this->purchaseFlow($months, $from),
            'maintenance_top_vehicles' => $this->maintenanceTopVehicles(),
            'tyre_per_month' => $this->perMonth('tyre_changes', 'change_date', $months, $from),
            'attention' => $attention,
        ]]);
    }

    /**
     * Rows of every tracking module split into done / running / needs action, with
     * the status words of the module's own Excel.
     */
    private function tracking(Carbon $today): array
    {
        $count = fn (string $table) => DB::table($table)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c);
        $sum = fn ($by, array $keys) => (int) collect($keys)->sum(fn ($k) => $by[$k] ?? 0);
        $row = fn (string $key, string $label, string $to, $by, array $done, array $running, array $attention, array $words) => [
            'key' => $key, 'label' => $label, 'to' => $to,
            'done' => $sum($by, $done), 'running' => $sum($by, $running), 'attention' => $sum($by, $attention),
            'total' => (int) $by->sum(), 'words' => $words,
        ];

        $usedReturn = $count('used_return_matrix_rows');
        foreach ($count('used_return_other_rows') as $s => $c) {
            $usedReturn[$s] = ($usedReturn[$s] ?? 0) + $c;
        }

        // lend: anything past its due date is overdue (Excel "DEADLINE"), even when the stored status still says ON_LOAN
        $overdue = $this->overdueLend($today)->count();
        $lend = $count('lend_transactions');
        $lendOpen = (int) collect(self::LEND_OPEN)->sum(fn ($k) => $lend[$k] ?? 0);
        $lend = collect(['RETURNED' => (int) ($lend['RETURNED'] ?? 0), 'ON_LOAN' => $lendOpen - $overdue, 'OVERDUE' => $overdue]);

        return [
            $row('maintenance', 'Maintenance (Sub SPK)', '/maintenance', $count('maintenance_order_subs'),
                ['COMPLETED'], ['REQUESTED', 'ON_GOING'], ['INCOMPLETE'], ['Completed', 'Requested / On going', 'Incomplete']),
            $row('manufacturing', 'Manufaktur & Assembly', '/manufacturing', $count('manufacturing_order_subs'),
                ['COMPLETED'], ['REQUESTED', 'ON_GOING'], ['INCOMPLETE'], ['Completed', 'Requested / On going', 'Incomplete']),
            $row('tyre', 'Penggantian Ban', '/tyre-changes', $count('tyre_changes'),
                ['CLEAR'], ['PENDING_RI'], ['INCOMPLETE'], ['Clear', 'Pending RI', 'Incomplete']),
            $row('stpp', 'STPP', '/stpp', $count('stpp_transactions'),
                ['PASSIVE'], ['ACTIVE'], ['INCOMPLETE'], ['Passive (kembali)', 'Active (dipakai)', 'Incomplete']),
            $row('used_return', 'Barang Bekas Kembali', '/used-returns', $usedReturn,
                ['CLEAR'], ['PENDING'], ['INCOMPLETE'], ['Clear', 'Pending', 'Incomplete']),
            $row('lend', 'Barang Dipinjamkan', '/lend', $lend,
                ['RETURNED'], ['ON_LOAN'], ['OVERDUE'], ['Kembali', 'Sedang dipinjam', 'Deadline (lewat tempo)']),
            $row('borrow', 'Pinjam dari Luar', '/borrow', $count('borrow_transactions'),
                ['RETURNED'], ['BORROWED', 'PARTIAL'], [], ['Kembali', 'Sedang dipinjam', '—']),
        ];
    }

    private function ppbAging(Carbon $today): array
    {
        $buckets = collect(self::AGE_BUCKETS)->mapWithKeys(fn ($b) => [$b[0] => ['name' => $b[0], 'requested' => 0, 'shortage' => 0]])->all();
        $this->waitingPpb()->selectRaw('tgl_ppb, line_status, count(*) c')->groupBy('tgl_ppb', 'line_status')->get()
            ->each(function ($r) use (&$buckets, $today) {
                $age = $r->tgl_ppb ? $this->age($r->tgl_ppb, $today) : PHP_INT_MAX;
                foreach (self::AGE_BUCKETS as [$name, $min, $max]) {
                    if ($age >= $min && $age <= $max) {
                        $buckets[$name][$r->line_status === 'SHORTAGE' ? 'shortage' : 'requested'] += (int) $r->c;
                        break;
                    }
                }
            });

        return array_values($buckets);
    }

    /** PPB / PO / RI lines per month — one unit (document lines), so one axis. */
    private function purchaseFlow(array $months, string $from): array
    {
        $ppb = $this->perMonthMap(DB::table('ppb'), 'tgl_ppb', $from);
        $po = $this->perMonthMap(DB::table('purchase_order_items')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNotNull('purchase_orders.accurate_po_id'), 'purchase_orders.date', $from);
        $ri = $this->perMonthMap(DB::table('ri'), 'tgl_ri', $from);

        return array_map(fn ($m) => ['month' => $m, 'ppb' => $ppb[$m] ?? 0, 'po' => $po[$m] ?? 0, 'ri' => $ri[$m] ?? 0], $months);
    }

    private function perMonth(string $table, string $column, array $months, string $from): array
    {
        $by = $this->perMonthMap(DB::table($table), $column, $from);

        return array_map(fn ($m) => ['month' => $m, 'count' => $by[$m] ?? 0], $months);
    }

    /** @return array<string, int> "Y-m" => rows */
    private function perMonthMap($query, string $column, string $from): array
    {
        $out = [];
        $query->where($column, '>=', $from)->selectRaw("$column d, count(*) c")->groupBy($column)->get()
            ->each(function ($r) use (&$out) {
                $m = substr((string) $r->d, 0, 7);
                $out[$m] = ($out[$m] ?? 0) + (int) $r->c;
            });

        return $out;
    }

    /** Top 10 vehicles by Sub SPK, split by status. Nopol as written in the Excel, normalised. */
    private function maintenanceTopVehicles(): array
    {
        $rows = DB::table('maintenance_order_subs as s')
            ->leftJoin('maintenance_orders as o', 'o.id', '=', 's.maintenance_order_id')
            ->selectRaw('COALESCE(s.nopol_raw, o.nopol_raw) nopol, s.status, count(*) c')
            ->groupBy('nopol', 's.status')->get();

        return $rows->groupBy(fn ($r) => preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $r->nopol))))
            ->reject(fn ($g, $k) => $k === '' || $k === '-')
            ->map(fn ($g, $k) => [
                'name' => $k,
                'completed' => (int) $g->where('status', 'COMPLETED')->sum('c'),
                'open' => (int) $g->where('status', '!=', 'COMPLETED')->sum('c'),
                'total' => (int) $g->sum('c'),
            ])
            ->sortByDesc('total')->take(10)->values()->all();
    }

    private function waitingPpb()
    {
        return DB::table('ppb')->whereIn('line_status', self::PPB_WAITING)->where('is_amend', false);
    }

    private function overdueLend(Carbon $today)
    {
        return DB::table('lend_transactions')->whereIn('status', self::LEND_OPEN)
            ->where(fn ($q) => $q->where('status', 'OVERDUE')->orWhere('due_date', '<=', $today->toDateString()));
    }

    private function age(string $date, Carbon $today): int
    {
        return max(0, (int) Carbon::parse(substr($date, 0, 10), 'Asia/Jakarta')->startOfDay()->diffInDays($today, false));
    }
}
