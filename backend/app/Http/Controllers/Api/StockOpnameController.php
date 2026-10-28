<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\StockOpnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockOpnameController extends Controller
{
    public function __construct(private readonly StockOpnameService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockOpname::query()
            ->with(['warehouse:id,code', 'counter:id,name'])
            ->withCount([
                'items',
                'items as counted_count' => fn ($q) => $q->where('count_status', 'COUNTED'),
                'items as diff_count' => fn ($q) => $q->whereRaw('abs(difference) > 0.001'),
            ])
            ->latest();
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('warehouse_id', fn ($v) => $query->where('warehouse_id', $v));
        // source=internal: scheduled & counted in STOCKWISE; source=accurate: ITEMADJ mirror
        $request->whenFilled('source', fn ($v) => $v === 'accurate'
            ? $query->whereNotNull('accurate_itemadj_id')
            : ($v === 'internal' ? $query->whereNull('accurate_itemadj_id') : null));
        $request->whenFilled('search', fn ($v) => $query->where('number', 'like', '%'.trim((string) $v).'%'));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('scheduled_date', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('scheduled_date', '<=', $v));

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        // First item of each document on this page, so the list says WHAT was counted
        // (Accurate adjustments are mostly one item each) without opening every document.
        $firstItems = StockOpnameItem::query()
            ->whereIn('stock_opname_id', collect($page->items())->pluck('id'))
            ->with('item:id,code,description')
            ->orderBy('id')
            ->get(['id', 'stock_opname_id', 'item_id'])
            ->groupBy('stock_opname_id')
            ->map(fn ($lines) => $lines->first()->item?->only(['code', 'description']));

        return response()->json([
            'data' => collect($page->items())->map(fn (StockOpname $o) => $this->summary($o) + [
                'first_item' => $firstItems[$o->id] ?? null,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * GET /api/stock-opnames/lines — every counted item across documents in one table
     * ("Per barang" view), newest first. Same blind-count rule as show(): system qty and
     * the difference only for opname.review.
     */
    public function lines(Request $request): JsonResponse
    {
        $canReview = $request->user()->hasPermission('opname.review');

        $query = StockOpnameItem::query()
            ->join('stock_opnames as so', 'so.id', '=', 'stock_opname_items.stock_opname_id')
            ->join('items as it', 'it.id', '=', 'stock_opname_items.item_id')
            ->select('stock_opname_items.*')
            ->with(['item:id,code,description,unit_id', 'item.unit:id,code', 'opname:id,number,status,scheduled_date,accurate_itemadj_id,warehouse_id', 'opname.warehouse:id,code'])
            ->orderByDesc('so.scheduled_date')
            ->orderByDesc('so.id')
            ->orderBy('stock_opname_items.id');

        $request->whenFilled('source', fn ($v) => $v === 'accurate'
            ? $query->whereNotNull('so.accurate_itemadj_id')
            : ($v === 'internal' ? $query->whereNull('so.accurate_itemadj_id') : null));
        $request->whenFilled('status', fn ($v) => $query->where('so.status', strtoupper((string) $v)));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('so.scheduled_date', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('so.scheduled_date', '<=', $v));
        $request->whenFilled('search', function ($v) use ($query) {
            $like = '%'.trim((string) $v).'%';
            $query->where(fn ($q) => $q->where('so.number', 'like', $like)
                ->orWhere('it.code', 'like', $like)
                ->orWhereLoose('it.description', (string) $v));
        });
        if ($canReview && $request->boolean('only_diff')) {
            $query->whereRaw('abs(stock_opname_items.difference) > 0.001');
        }

        $page = $query->paginate(min((int) $request->integer('per_page', 50), 200))->withQueryString();
        $this->usage = $this->usageCounts(collect($page->items())->map(fn ($l) => $l->item?->code), ...$this->usagePeriod($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (StockOpnameItem $l) => $this->lineRow($l, $canReview) + [
                'unit' => $l->item?->unit?->code,
                'opname' => [
                    'id' => $l->opname?->id,
                    'number' => $l->opname?->number,
                    'status' => $l->opname?->status,
                    'date' => $l->opname?->scheduled_date?->toDateString(),
                    'warehouse' => $l->opname?->warehouse?->code,
                    'accurate_itemadj_id' => $l->opname?->accurate_itemadj_id,
                ],
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $stockOpname->load([
            'warehouse:id,code,name', 'counter:id,name', 'scheduledBy:id,name', 'reviewedBy:id,name',
            'items.item:id,code,description',
        ])
            ->loadCount([
                'items',
                'items as counted_count' => fn ($q) => $q->where('count_status', 'COUNTED'),
                'items as diff_count' => fn ($q) => $q->whereRaw('abs(difference) > 0.001'),
            ]);

        // Blind count: whoever only counts (opname.count, e.g. admin lapangan) never sees the
        // master-data system_qty — otherwise they'd just copy it instead of counting for real.
        // Only the reviewer (opname.review, admin gudang) sees system_qty and the reconciliation
        // (system_qty and physical_qty combined would reveal system_qty to the counter anyway).
        $canReview = $request->user()->hasPermission('opname.review');
        $this->usage = $this->usageCounts($stockOpname->items->map(fn ($l) => $l->item?->code));

        return response()->json([
            'data' => $this->summary($stockOpname) + [
                'items' => $stockOpname->items->map(fn ($l) => $this->lineRow($l, $canReview)),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function lineRow(StockOpnameItem $l, bool $canReview): array
    {
        $row = [
            'id' => $l->id,
            'item_id' => $l->item_id,
            'item_code' => $l->item?->code,
            'description' => $l->item?->description,
            'physical_qty' => $l->physical_qty,
            'note' => $l->note,
            'count_status' => $l->count_status,
            'review_status' => $l->review_status,
            // how often the item appears in NPBG (goods out) / PPB (purchase requests) — lines, all time
            'npbg_count' => $this->usage['npbg'][$l->item?->code] ?? 0,
            'ppb_count' => $this->usage['ppb'][$l->item?->code] ?? 0,
        ];

        if ($canReview) {
            $counted = $l->physical_qty !== null;
            $row['system_qty'] = $l->system_qty;
            $row['difference'] = $l->difference;
            $row['match_status'] = ! $counted ? null : (abs((float) $l->difference) < 0.001 ? 'VALID' : 'INVALID');
            $row['diff_label'] = ! $counted ? null : $this->diffLabel((float) $l->difference);
        }

        return $row;
    }

    /**
     * GET /api/stock-opnames/{id}/usage?date_from=&date_to= — the NPBG / PPB counts of
     * the opname's items for a period (both dates optional; none = all time).
     * Kept apart from show(): show() is the cache every count / review action writes back.
     */
    public function usage(Request $request, StockOpname $stockOpname): JsonResponse
    {
        [$from, $to] = $this->usagePeriod($request, 'date_from', 'date_to');
        $codes = $stockOpname->items()->join('items', 'items.id', '=', 'stock_opname_items.item_id')->pluck('items.code');
        $usage = $this->usageCounts($codes, $from, $to);

        return response()->json(['data' => [
            'date_from' => $from, 'date_to' => $to,
            'counts' => $codes->filter()->unique()->mapWithKeys(fn ($c) => [$c => ['npbg' => $usage['npbg'][$c] ?? 0, 'ppb' => $usage['ppb'][$c] ?? 0]]),
        ]]);
    }

    /**
     * The NPBG / PPB period from the request. lines() already uses date_from/date_to
     * for the opname date, so there the period comes as usage_from/usage_to.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function usagePeriod(Request $request, string $fromKey = 'usage_from', string $toKey = 'usage_to'): array
    {
        $request->validate([$fromKey => ['nullable', 'date'], $toKey => ['nullable', 'date']]);

        return [$request->input($fromKey) ?: null, $request->input($toKey) ?: null];
    }

    /** @var array{npbg: array<string,int>, ppb: array<string,int>} */
    private array $usage = ['npbg' => [], 'ppb' => []];

    /**
     * NPBG / PPB lines per item code (kode_barang = Master Barang code), for the
     * "NPBG" / "PPB" columns — one grouped query each for the codes on screen.
     *
     * @return array{npbg: array<string,int>, ppb: array<string,int>}
     */
    private function usageCounts(\Illuminate\Support\Collection $codes, ?string $from = null, ?string $to = null): array
    {
        $codes = $codes->filter()->unique()->values();
        if ($codes->isEmpty()) {
            return ['npbg' => [], 'ppb' => []];
        }
        $count = fn (string $table, string $dateCol) => $codes->chunk(1000)->flatMap(fn ($chunk) => \Illuminate\Support\Facades\DB::table($table)
            ->whereIn('kode_barang', $chunk->all())
            ->when($from, fn ($q) => $q->whereDate($dateCol, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($dateCol, '<=', $to))
            ->selectRaw('kode_barang, count(*) c')->groupBy('kode_barang')->pluck('c', 'kode_barang'))
            ->map(fn ($c) => (int) $c)->all();

        return ['npbg' => $count('npbg', 'tgl_npbg'), 'ppb' => $count('ppb', 'tgl_ppb')];
    }

    private function diffLabel(float $diff): string
    {
        if (abs($diff) < 0.001) {
            return '0';
        }

        return ($diff > 0 ? '+' : '-').rtrim(rtrim(number_format(abs($diff), 2, '.', ''), '0'), '.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'scheduled_date' => ['required', 'date'],
            'type' => ['sometimes', 'in:FULL,PARTIAL,OPENING'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:items,id'],
        ]);

        $opname = $this->service->schedule(
            $request->user(),
            $data['warehouse_id'],
            $data['scheduled_date'],
            $data['type'] ?? 'PARTIAL',
            $data['item_ids'] ?? null,
        );

        return response()->json(['data' => $this->summary($opname->loadCount('items'))], 201);
    }

    public function start(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->service->start($stockOpname, $request->user());

        return $this->show($request, $stockOpname);
    }

    public function count(Request $request, StockOpname $stockOpname, int $item): JsonResponse
    {
        $data = $request->validate([
            'physical_qty' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $line = $stockOpname->items()->findOrFail($item);
        $this->service->count($line, $data['physical_qty'], $data['note'] ?? null);

        return $this->show($request, $stockOpname);
    }

    public function submit(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->service->submit($stockOpname);

        return $this->show($request, $stockOpname);
    }

    public function review(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.id' => ['required', 'integer'],
            'decisions.*.decision' => ['required', 'in:APPROVED,REJECTED,RECOUNT'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->review($stockOpname, $request->user(), $data['decisions'], $data['note'] ?? null);

        return $this->show($request, $stockOpname);
    }

    /**
     * Print-friendly HTML (browser's own "Save as PDF"/print dialog turns it into paper or PDF —
     * same convention as DatasetExporter::pdf()). One view serves two real needs with the same
     * data: printed right after scheduling, physical_qty is still empty so it doubles as a blank
     * count sheet to carry into the warehouse; printed after counting/review, the same columns are
     * filled in and it reads as the results report. Blind count still applies — a counter-only
     * session (no opname.review) never gets system_qty/selisih printed either, same as on screen.
     */
    public function print(Request $request, StockOpname $stockOpname): StreamedResponse
    {
        $stockOpname->load([
            'warehouse:id,code,name', 'counter:id,name', 'scheduledBy:id,name', 'reviewedBy:id,name',
            'items' => fn ($q) => $q->with('item:id,code,description')->orderBy('id'),
        ]);
        $canReview = $request->user()->hasPermission('opname.review');
        $o = $stockOpname;
        $title = "Stock Opname {$o->number}";

        return response()->streamDownload(function () use ($o, $canReview) {
            $esc = fn ($v) => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES);
            $fmt = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '—';

            echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.$esc($o->number).'</title>';
            echo '<style>@media print{@page{size:A4 portrait;margin:12mm}}';
            echo 'body{font:12px/1.4 Arial,sans-serif;color:#111}h1{font-size:16px;margin:0 0 4px}';
            echo '.meta{color:#666;margin-bottom:4px}';
            echo '.hdr{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px 16px;margin:12px 0;font-size:11px}';
            echo '.hdr div span{color:#666;display:block}';
            echo 'table{border-collapse:collapse;width:100%;margin-top:10px}';
            echo 'th,td{border:1px solid #bbb;padding:4px 6px;text-align:left;font-size:11px}th{background:#f1f5f9}';
            echo 'tr:nth-child(even) td{background:#fafafa}';
            echo '.sign{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:36px;font-size:11px}';
            echo '.sign .line{margin-top:48px;border-top:1px solid #333;padding-top:4px}';
            echo '</style></head><body onload="window.print()">';
            echo '<h1>STOCKWISE — Stock Opname '.$esc($o->number).'</h1>';
            echo '<div class="meta">PT Surya Inti Gas · dicetak '.now()->format('d/m/Y H:i').'</div>';

            echo '<div class="hdr">';
            echo '<div><span>Gudang</span>'.$esc($o->warehouse?->code).' — '.$esc($o->warehouse?->name).'</div>';
            echo '<div><span>Tipe</span>'.$esc($o->type === 'PARTIAL' ? 'CUSTOM' : $o->type).'</div>';
            echo '<div><span>Status</span>'.$esc($o->status).'</div>';
            echo '<div><span>Tgl Dijadwalkan</span>'.$esc($o->scheduled_date?->toDateString()).' (oleh '.$esc($o->scheduledBy?->name).')</div>';
            echo '<div><span>Mulai Hitung</span>'.$fmt($o->started_at).' (oleh '.$esc($o->counter?->name).')</div>';
            echo '<div><span>Direview</span>'.$fmt($o->reviewed_at).' (oleh '.$esc($o->reviewedBy?->name).')</div>';
            echo '</div>';

            $headers = ['No', 'Kode', 'Deskripsi'];
            if ($canReview) {
                $headers[] = 'Sistem';
            }
            $headers[] = 'Fisik';
            if ($canReview) {
                $headers[] = 'Selisih';
            }
            $headers[] = 'Catatan';

            echo '<table><thead><tr>';
            foreach ($headers as $h) {
                echo '<th>'.$esc($h).'</th>';
            }
            echo '</tr></thead><tbody>';

            $no = 1;
            foreach ($o->items as $l) {
                echo '<tr>';
                echo '<td>'.$no++.'</td>';
                echo '<td>'.$esc($l->item?->code).'</td>';
                echo '<td>'.$esc($l->item?->description).'</td>';
                if ($canReview) {
                    echo '<td>'.$esc($l->system_qty).'</td>';
                }
                echo '<td>'.$esc($l->physical_qty).'</td>';
                if ($canReview) {
                    $counted = $l->physical_qty !== null;
                    echo '<td>'.($counted ? $esc($this->diffLabel((float) $l->difference)) : '—').'</td>';
                }
                echo '<td>'.$esc($l->note).'</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<div class="sign">';
            echo '<div>Dihitung oleh<div class="line">'.$esc($o->counter?->name ?: '').'</div></div>';
            echo '<div>Direview oleh<div class="line">'.$esc($o->reviewedBy?->name ?: '').'</div></div>';
            echo '<div>Mengetahui<div class="line"></div></div>';
            echo '</div>';

            echo '</body></html>';
        }, 'stock-opname-'.str_replace('/', '-', $o->number).'.html', ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function summary(StockOpname $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status,
            'type' => $o->type,
            'warehouse' => $o->relationLoaded('warehouse') ? $o->warehouse?->only('id', 'code', 'name') : null,
            'scheduled_date' => $o->scheduled_date?->toDateString(),
            'scheduled_by' => $o->relationLoaded('scheduledBy') ? $o->scheduledBy?->name : null,
            'counter' => $o->relationLoaded('counter') ? $o->counter?->name : null,
            'reviewer' => $o->relationLoaded('reviewedBy') ? $o->reviewedBy?->name : null,
            'items_count' => $o->items_count,
            'counted_count' => $o->counted_count ?? null,
            'diff_count' => $o->diff_count ?? null,
            'created_at' => $o->created_at,
            'started_at' => $o->started_at,
            'submitted_at' => $o->submitted_at,
            'reviewed_at' => $o->reviewed_at,
            'review_note' => $o->review_note,
            'accurate_itemadj_id' => $o->accurate_itemadj_id,
            'accurate_synced_at' => $o->accurate_synced_at,
        ];
    }
}
