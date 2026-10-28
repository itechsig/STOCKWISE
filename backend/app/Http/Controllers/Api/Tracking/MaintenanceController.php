<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderSub;
use App\Services\DocumentNumberService;
use App\Services\Tracking\MaintenanceService;
use App\Services\Tracking\TrackingPhotoService;
use App\Support\MaintenanceExcel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Maintenance Assets — the Excel sheet "Maintenance Kendaraan": No. SPK + Sub SPK rows. */
class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceService $service,
        private readonly TrackingPhotoService $photos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaintenanceOrder::query()->with('asset:id,code,name')->withCount('subs')->latest('report_date')->latest('id');
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('nopol_raw', 'like', "%{$s}%")->orWhere('problem_summary', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (MaintenanceOrder $o) => $this->row($o)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** The Excel sheet: one row per Sub SPK, newest first. `id` = the SPK (opens its detail), `line_id` = the row. */
    public function lines(Request $request): JsonResponse
    {
        $query = DB::table('maintenance_order_subs as s')
            ->join('maintenance_orders as o', 'o.id', '=', 's.maintenance_order_id')
            ->leftJoin('workshops as w', 'w.id', '=', 's.workshop_id')
            ->select('s.*', 'o.number', 'o.nopol_raw as order_nopol', 'w.name as workshop_name')
            ->orderByDesc('s.sheet_no')->orderByDesc('s.id');
        $request->whenFilled('status', fn ($v) => $query->where('s.status', strtoupper(str_replace('-', '_', (string) $v))));
        if ($q = $request->string('search')->trim()->value()) {
            $query->where(fn ($w) => $w->where('o.number', 'like', "%{$q}%")->orWhere('s.nopol_raw', 'like', "%{$q}%")
                ->orWhere('o.nopol_raw', 'like', "%{$q}%")->orWhere('s.problem_detail', 'like', "%{$q}%")
                ->orWhere('s.workshop_raw', 'like', "%{$q}%")->orWhere('s.npbg_no', 'like', "%{$q}%")
                ->orWhere('s.result_note', 'like', "%{$q}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $rows = collect($page->items());
        $photos = $this->photos->forRows(MaintenanceService::ENTITY, $rows->pluck('id'));

        return response()->json([
            'data' => $rows->map(fn ($s) => ['id' => (int) $s->maintenance_order_id, 'line_id' => (int) $s->id]
                + $this->present($s, $s->number, $s->nopol_raw ?? $s->order_nopol, $s->workshop_name, $photos[$s->id] ?? [])),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                // Excel row 4: =TODAY() and the next No. SPK
                'today' => now('Asia/Jakarta')->toDateString(),
                'next_number' => app(DocumentNumberService::class)->peekShort('MK', now('Asia/Jakarta'))],
        ]);
    }

    public function show(MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $o = $maintenanceOrder->load(['asset:id,code,name', 'subs.workshop:id,name', 'subs' => fn ($q) => $q->orderBy('sheet_no')->orderBy('sub_no')->orderBy('id')]);
        $refs = app(\App\Services\Tracking\AccurateDocRefs::class);
        $photos = $this->photos->forRows(MaintenanceService::ENTITY, $o->subs->pluck('id'));

        return response()->json(['data' => $this->row($o) + [
            'subs' => $o->subs->map(fn ($s) => ['id' => $s->id]
                + $this->present((object) $s->getAttributes(), $o->number, $s->nopol_raw ?? $o->nopol_raw, $s->workshop?->name, $photos[$s->id] ?? [])
                + ['npbg' => $s->npbg_id ? $refs->npbgSummary($s->npbg_no) : null]),
        ]]);
    }

    /** Choices for the inputs (Excel "Dropdown List": Nopol, Bengkel) — values already used. */
    public function options(Request $request): JsonResponse
    {
        $used = fn (string $col) => DB::table('maintenance_order_subs')->whereNotNull($col)->where($col, '<>', '')
            ->selectRaw("{$col} as v, count(*) n")->groupBy($col)->orderByDesc('n')->limit(300)->pluck('v');

        return response()->json(['data' => ['nopols' => $used('nopol_raw'), 'workshops' => $used('workshop_raw')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'nopol_raw' => ['required_without:asset_id', 'nullable', 'string', 'max:120'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'report_date' => ['nullable', 'date'],
            'reported_by' => ['nullable', 'integer', 'exists:employees,id'],
            'problem_summary' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'array', 'max:50'],
            ...$this->rowRules('lines.*.'),
        ]);

        return response()->json(['data' => $this->row($this->service->createOrder($request->user(), $data)->loadCount('subs'))], 201);
    }

    public function update(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['sometimes', 'integer', 'exists:assets,id'],
            'nopol_raw' => ['sometimes', 'nullable', 'string', 'max:120'],
            'report_date' => ['sometimes', 'date'],
            'reported_by' => ['nullable', 'integer', 'exists:employees,id'],
            'problem_summary' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->updateOrder($maintenanceOrder, $data);

        return $this->show($maintenanceOrder->fresh());
    }

    public function destroy(MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $this->service->deleteOrder($maintenanceOrder);

        return response()->json(['message' => 'SPK dihapus.']);
    }

    public function updateSub(Request $request, MaintenanceOrderSub $sub): JsonResponse
    {
        $this->service->updateSub($sub, $request->validate($this->rowRules()));

        return $this->show($sub->order()->first());
    }

    public function destroySub(MaintenanceOrderSub $sub): JsonResponse
    {
        $order = $sub->order;
        $this->service->deleteSub($sub);

        return $this->show($order->fresh());
    }

    public function addSub(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $data = $request->validate([...$this->rowRules(), 'sub_no' => ['nullable', 'string', 'regex:/^SUB-\d{2}$/i']]);
        $this->service->addSub($maintenanceOrder, $data);

        return $this->show($maintenanceOrder->fresh());
    }

    public function completeSub(Request $request, MaintenanceOrderSub $sub): JsonResponse
    {
        $data = $request->validate([
            'finish_date' => ['nullable', 'date'],
            'result_note' => ['nullable', 'string', 'max:2000'],
            'npbg_no' => ['nullable', 'string', 'max:40'],
            'photo' => ['nullable', 'string', 'max:15000000'],
        ]);
        $this->service->completeSub($sub, $data, $request->user()->id);

        return $this->show($sub->order()->first());
    }

    private function rowRules(string $prefix = ''): array
    {
        return [
            "{$prefix}report_date" => ['nullable', 'date'],
            "{$prefix}nopol_raw" => ['nullable', 'string', 'max:120'],
            "{$prefix}problem_detail" => ['nullable', 'string', 'max:2000'],
            "{$prefix}workshop_raw" => ['nullable', 'string', 'max:80'],
            "{$prefix}request_note" => ['nullable', 'string', 'max:500'],
            "{$prefix}npbg_no" => ['nullable', 'string', 'max:40'],
            "{$prefix}before_file_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}after_file_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}finish_date" => ['nullable', 'date'],
            "{$prefix}result_note" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** One Excel row — same shape for the sheet list and the detail. */
    private function present(object $s, string $number, ?string $nopol, ?string $workshopName, array $photos): array
    {
        $d = fn ($v) => $v ? substr($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v, 0, 10) : null;

        return [
            'no' => $s->sheet_no !== null ? (int) $s->sheet_no : null,
            'report_date' => $d($s->report_date),
            'number' => $number, 'sub_no' => $s->sub_no, 'nopol' => $nopol,
            'problem_detail' => $s->problem_detail,
            'before_file_name' => $s->before_file_name,
            'helper_before' => MaintenanceExcel::fileName($number, $s->sub_no, 'before'),
            'workshop' => $workshopName ?? $s->workshop_raw,
            'request_note' => $s->request_note,
            'status' => $s->status,
            'npbg_no' => $s->npbg_no, 'npbg_linked' => (bool) $s->npbg_id,
            'after_file_name' => $s->after_file_name,
            'helper_after' => MaintenanceExcel::fileName($number, $s->sub_no, 'after'),
            'finish_date' => $d($s->finish_date), 'result_note' => $s->result_note,
            'source' => $s->source ?? null,
            // tracking_photos of this row: slot => {id, file_name}
            'photos' => (object) $photos,
        ];
    }

    private function row(MaintenanceOrder $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status,
            'asset_code' => $o->relationLoaded('asset') ? $o->asset?->code : null,
            'asset_name' => $o->relationLoaded('asset') ? $o->asset?->name : null,
            'nopol' => $o->nopol_raw ?? ($o->relationLoaded('asset') ? $o->asset?->name : null),
            'report_date' => $o->report_date?->toDateString(), 'completed_at' => $o->completed_at?->toDateString(),
            'problem_summary' => $o->problem_summary, 'subs_count' => $o->subs_count, 'created_at' => $o->created_at,
        ];
    }
}
