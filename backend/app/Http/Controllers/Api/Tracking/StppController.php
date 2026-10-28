<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\StppTransaction;
use App\Services\Tracking\AccurateDocRefs;
use App\Services\Tracking\StppService;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** STPP — the Excel "4. Tracking STPP": sheet "STPP" (one unit per row) and sheet "Maintenance". */
class StppController extends Controller
{
    public function __construct(private readonly StppService $service, private readonly TrackingPhotoService $photos) {}

    public function index(Request $request): JsonResponse
    {
        $query = StppTransaction::query()->with('item:id,code', 'unit:id,code', 'outNpbg:id,no_npbg')
            ->orderByRaw('sheet_no IS NULL')->orderByDesc('sheet_no')->orderByDesc('id');
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('serial_no_raw', 'like', "%{$s}%")
                ->orWhere('description_raw', 'like', "%{$s}%")->orWhere('holder_name_raw', 'like', "%{$s}%")
                ->orWhere('placement_raw', 'like', "%{$s}%")->orWhere('out_npbg_no', 'like', "%{$s}%")
                ->orWhere('return_ri_no', 'like', "%{$s}%")->orWhere('out_note', 'like', "%{$s}%")
                ->orWhereHas('outNpbg', fn ($n) => $n->where('no_npbg', 'like', "%{$s}%")));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $items = collect($page->items());
        $photos = $this->photos->forRows('stpp', $items->pluck('id'));
        $riLinked = $this->riLinked($items->pluck('return_ri_no'));

        return response()->json([
            'data' => $items->map(fn (StppTransaction $s) => $this->row($s, $photos[$s->id] ?? [], $riLinked)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(StppTransaction $stpp): JsonResponse
    {
        $stpp->load('item:id,code', 'unit:id,code', 'outNpbg:id,no_npbg');
        $refs = app(AccurateDocRefs::class);

        return response()->json(['data' => $this->row($stpp, $this->photos->forRows('stpp', [$stpp->id])[$stpp->id] ?? [], $this->riLinked(collect([$stpp->return_ri_no]))) + [
            'npbg' => $stpp->out_npbg_id ? $refs->npbgSummary($stpp->out_npbg_no ?? $stpp->outNpbg?->no_npbg) : null,
            'ri' => $stpp->return_ri_no ? $refs->riSummary($stpp->return_ri_no) : null,
            // the unit's other hand-overs and its Maintenance STPP log (sheet "Maintenance")
            'history' => $stpp->serial_no_raw ? StppTransaction::where('serial_no_raw', $stpp->serial_no_raw)->whereKeyNot($stpp->id)
                ->orderBy('out_date')->get(['id', 'number', 'out_npbg_no', 'out_date', 'status', 'holder_name_raw', 'return_ri_no', 'return_date']) : [],
            'maintenance' => $stpp->serial_no_raw ? DB::table('stpp_maintenance_logs')->where('serial_no_raw', $stpp->serial_no_raw)->orderBy('npbg_date')->get() : [],
        ]]);
    }

    /** Choices of the inputs (Excel "Dropdown List": Peminta, Penempatan) — values already used. */
    public function options(): JsonResponse
    {
        $used = fn (string $col) => StppTransaction::whereNotNull($col)->where($col, '<>', '')
            ->selectRaw("{$col} as v, count(*) n")->groupBy($col)->orderByDesc('n')->limit(300)->pluck('v');

        return response()->json(['data' => ['holders' => $used('holder_name_raw'), 'placements' => $used('placement_raw'),
            'units' => $used('unit_raw'), 'descriptions' => $used('description_raw')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['out_npbg_id' => ['nullable', 'integer', 'exists:npbg,id']]);

        return response()->json(['data' => $this->row($this->service->issue($request->user(), $data), [], [])], 201);
    }

    public function update(Request $request, StppTransaction $stpp): JsonResponse
    {
        $this->service->update($stpp, $request->validate($this->rules()));

        return $this->show($stpp->fresh());
    }

    public function destroy(StppTransaction $stpp): JsonResponse
    {
        $this->service->delete($stpp);

        return response()->json(['message' => 'STPP dihapus.']);
    }

    public function withdraw(Request $request, StppTransaction $stpp): JsonResponse
    {
        $data = $request->validate([
            'return_ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
            'return_ri_no' => ['nullable', 'string', 'max:40'],
            'return_item_no' => ['nullable', 'integer', 'min:1'],
            'return_file_name' => ['nullable', 'string', 'max:255'],
            'return_date' => ['nullable', 'date'],
            'return_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->withdraw($stpp, $data), [], [])]);
    }

    public function reissue(Request $request, StppTransaction $stpp): JsonResponse
    {
        $data = $request->validate([
            'holder_id' => ['nullable', 'integer', 'exists:employees,id'],
            'holder_name_raw' => ['nullable', 'string', 'max:150'],
            'placement_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'placement_raw' => ['nullable', 'string', 'max:80'],
            'out_npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'out_npbg_no' => ['nullable', 'string', 'max:40'],
            'out_date' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->row($this->service->reissue($stpp, $request->user(), $data), [], [])], 201);
    }

    // ---- sheet "Maintenance" (Maintenance STPP) ----

    public function maintenanceIndex(Request $request): JsonResponse
    {
        $q = DB::table('stpp_maintenance_logs as m')->orderByRaw('m.sheet_no IS NULL')->orderByDesc('m.sheet_no')->orderByDesc('m.id')
            ->select('m.*')
            // Deskripsi / Kuantitas / Satuan come from the unit's STPP row, like the Excel INDEX/MATCH
            ->selectSub(StppTransaction::select('description_raw')->whereColumn('serial_no_raw', 'm.serial_no_raw')->orderBy('id')->limit(1), 'description')
            ->selectSub(StppTransaction::select('qty')->whereColumn('serial_no_raw', 'm.serial_no_raw')->orderBy('id')->limit(1), 'qty')
            ->selectSub(StppTransaction::select('unit_raw')->whereColumn('serial_no_raw', 'm.serial_no_raw')->orderBy('id')->limit(1), 'unit');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($w) => $w->where('m.serial_no_raw', 'like', "%{$s}%")->orWhere('m.requester', 'like', "%{$s}%")
                ->orWhere('m.npbg_no', 'like', "%{$s}%")->orWhere('m.note', 'like', "%{$s}%"));
        }
        $page = $q->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn ($m) => [
                'id' => $m->id, 'no' => $m->sheet_no, 'serial_no' => $m->serial_no_raw,
                'description' => $m->description ?? 'Not Found', 'qty' => $m->qty !== null ? (float) $m->qty : null, 'unit' => $m->unit,
                'requester' => $m->requester, 'npbg_date' => $m->npbg_date, 'npbg_no' => $m->npbg_no, 'npbg_linked' => (bool) $m->npbg_id,
                'note' => $m->note,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function maintenanceStore(Request $request): JsonResponse
    {
        $id = DB::table('stpp_maintenance_logs')->insertGetId($this->maintenanceData($request) + [
            'sheet_no' => 1 + (int) DB::table('stpp_maintenance_logs')->max('sheet_no'),
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function maintenanceUpdate(Request $request, int $log): JsonResponse
    {
        abort_unless(DB::table('stpp_maintenance_logs')->where('id', $log)->exists(), 404);
        DB::table('stpp_maintenance_logs')->where('id', $log)->update($this->maintenanceData($request) + ['updated_at' => now()]);

        return response()->json(['data' => ['id' => $log]]);
    }

    public function maintenanceDestroy(int $log): JsonResponse
    {
        DB::table('stpp_maintenance_logs')->where('id', $log)->delete();

        return response()->json(['message' => 'Dihapus.']);
    }

    private function maintenanceData(Request $request): array
    {
        $d = $request->validate([
            'serial_no' => ['required', 'string', 'max:80'],
            'requester' => ['nullable', 'string', 'max:150'],
            'npbg_date' => ['nullable', 'date'],
            'npbg_no' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $npbg = AccurateDocRefs::normalizeNo($d['npbg_no'] ?? null);

        return [
            'serial_no_raw' => trim($d['serial_no']), 'requester' => $d['requester'] ?? null, 'npbg_date' => $d['npbg_date'] ?? null,
            'npbg_no' => $npbg, 'npbg_id' => $npbg && $npbg !== '-' ? app(AccurateDocRefs::class)->npbgId($npbg) : null, 'note' => $d['note'] ?? null,
        ];
    }

    private function rules(): array
    {
        return [
            'serial_no' => ['nullable', 'string', 'max:80'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['nullable', 'string', 'max:400'],
            'qty' => ['nullable', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'unit_raw' => ['nullable', 'string', 'max:20'],
            'holder_id' => ['nullable', 'integer', 'exists:employees,id'],
            'holder_name_raw' => ['nullable', 'string', 'max:150'],
            'placement_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'placement_raw' => ['nullable', 'string', 'max:80'],
            'out_date' => ['nullable', 'date'],
            'out_npbg_no' => ['nullable', 'string', 'max:40'],
            'out_item_no' => ['nullable', 'integer', 'min:1'],
            'out_file_name' => ['nullable', 'string', 'max:255'],
            'out_note' => ['nullable', 'string', 'max:255'],
            'return_date' => ['nullable', 'date'],
            'return_ri_no' => ['nullable', 'string', 'max:40'],
            'return_item_no' => ['nullable', 'integer', 'min:1'],
            'return_file_name' => ['nullable', 'string', 'max:255'],
            'return_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, bool> RI number => it is in the Accurate data */
    private function riLinked(\Illuminate\Support\Collection $nos): array
    {
        $nos = $nos->filter(fn ($n) => $n && $n !== '-')->unique()->values();

        return $nos->isEmpty() ? [] : DB::table('ri')->whereIn('no_ri', $nos)->distinct()->pluck('no_ri')->flip()->map(fn () => true)->all();
    }

    private function row(StppTransaction $s, array $photos, array $riLinked): array
    {
        $npbgNo = $s->out_npbg_no ?? ($s->relationLoaded('outNpbg') ? $s->outNpbg?->no_npbg : null);
        $helper = fn (?string $doc, $item) => $doc && $item ? str_replace('/', '.', $doc).'-'.$item : null;

        return [
            'id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'no' => $s->sheet_no,
            // STPP from an NPBG line is identified by that NPBG's number (user decision 2026-09-28)
            'display_number' => $npbgNo ?? $s->number,
            'serial_no' => $s->serial_no_raw,
            'item_code' => $s->relationLoaded('item') ? $s->item?->code : null,
            'description' => $s->description_raw, 'qty' => (float) $s->qty,
            'unit' => $s->unit_raw ?? ($s->relationLoaded('unit') ? $s->unit?->code : null),
            'holder' => $s->holder_name_raw, 'placement' => $s->placement_raw,
            'out_date' => $s->out_date?->toDateString(),
            'out_npbg' => $npbgNo, 'npbg_linked' => (bool) $s->out_npbg_id,
            'out_item_no' => $s->out_item_no, 'out_file_name' => $s->out_file_name,
            'helper_out' => $helper($npbgNo, $s->out_item_no),
            'out_note' => $s->out_note,
            'return_date' => $s->return_date?->toDateString(),
            'return_ri' => $s->return_ri_no, 'ri_linked' => isset($riLinked[$s->return_ri_no]),
            'return_item_no' => $s->return_item_no, 'return_file_name' => $s->return_file_name,
            'helper_in' => $helper($s->return_ri_no, $s->return_item_no),
            'return_note' => $s->return_note, 'source' => $s->source,
            'photos' => (object) $photos, 'created_at' => $s->created_at,
        ];
    }
}
