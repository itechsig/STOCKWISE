<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\TyreChange;
use App\Services\Tracking\AccurateDocRefs;
use App\Services\Tracking\TrackingPhotoService;
use App\Services\Tracking\TyreChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ban Luar — the Excel "5. Tracking Ban Luar": sheets "Ban Luar", "Ban Luar BPN", "Deliver & Receive Ban SIG-BPN". */
class TyreChangeController extends Controller
{
    public function __construct(private readonly TyreChangeService $service, private readonly TrackingPhotoService $photos) {}

    public function index(Request $request): JsonResponse
    {
        $query = TyreChange::query()->with('asset:id,code,name', 'outNpbg:id,no_npbg', 'inRi:id,no_ri')
            ->orderByRaw('sheet_no IS NULL')->orderByDesc('sheet_no')->latest('change_date')->latest('id');
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper(str_replace(' ', '_', (string) $v))));
        $request->whenFilled('asset_id', fn ($v) => $query->where('asset_id', $v));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('nopol_raw', 'like', "%{$s}%")
                ->orWhereLoose('new_tyre_desc', $s)->orWhere('new_serial_raw', 'like', "%{$s}%")
                ->orWhere('old_serial_raw', 'like', "%{$s}%")->orWhere('out_npbg_no', 'like', "%{$s}%")
                ->orWhere('in_ri_no', 'like', "%{$s}%")->orWhere('out_note', 'like', "%{$s}%")
                ->orWhereHas('outNpbg', fn ($n) => $n->where('no_npbg', 'like', "%{$s}%")));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $items = collect($page->items());
        $photos = $this->photos->forRows('tyre', $items->pluck('id'));

        return response()->json([
            'data' => $items->map(fn (TyreChange $t) => $this->row($t, $photos[$t->id] ?? [])),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(TyreChange $tyreChange): JsonResponse
    {
        $t = $tyreChange->load('asset:id,code,name', 'outNpbg:id,no_npbg', 'inRi:id,no_ri');
        $refs = app(AccurateDocRefs::class);

        return response()->json(['data' => $this->row($t, $this->photos->forRows('tyre', [$t->id])[$t->id] ?? []) + [
            'npbg' => $t->out_npbg_id ? $refs->npbgSummary($t->out_npbg_no ?? $t->outNpbg?->no_npbg) : null,
            'ri' => $t->in_ri_id ? $refs->riSummary($t->in_ri_no ?? $t->inRi?->no_ri) : null,
            // the life of these tyres: where the serials were before / after
            'serial_history' => TyreChange::query()->whereKeyNot($t->id)
                ->where(fn ($q) => $q->whereIn('new_serial_raw', array_filter([$t->new_serial_raw, $t->old_serial_raw]))
                    ->orWhereIn('old_serial_raw', array_filter([$t->new_serial_raw, $t->old_serial_raw])))
                ->orderBy('change_date')->limit(30)->get(['id', 'nopol_raw', 'change_date', 'out_npbg_no', 'new_serial_raw', 'old_serial_raw', 'status']),
        ]]);
    }

    /** Choices (Excel "Dropdown List": Nopol, Deskripsi Ban Baru / Lama, No Seri Baru / Lama) — values already used. */
    public function options(): JsonResponse
    {
        $used = fn (string $col) => TyreChange::whereNotNull($col)->where($col, '<>', '')->where($col, '<>', '-')
            ->selectRaw("{$col} as v, count(*) n")->groupBy($col)->orderByDesc('n')->limit(400)->pluck('v');

        return response()->json(['data' => [
            'nopols' => $used('nopol_raw'), 'new_descs' => $used('new_tyre_desc'), 'old_descs' => $used('old_tyre_desc'),
            'serials' => $used('new_serial_raw')->concat($used('old_serial_raw'))->unique()->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'is_opening' => ['sometimes', 'boolean'],
        ] + ['nopol_raw' => ['required_without:asset_id', 'nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->row($this->service->record($request->user(), $data)->load('outNpbg:id,no_npbg'), [])], 201);
    }

    public function update(Request $request, TyreChange $tyreChange): JsonResponse
    {
        $this->service->update($tyreChange, $request->validate($this->rules()));

        return $this->show($tyreChange->fresh());
    }

    public function destroy(TyreChange $tyreChange): JsonResponse
    {
        $this->service->delete($tyreChange);

        return response()->json(['message' => 'Data ban dihapus.']);
    }

    public function close(Request $request, TyreChange $tyreChange): JsonResponse
    {
        $data = $request->validate([
            'ri_no' => ['nullable', 'string', 'max:40'],
            'in_date' => ['nullable', 'date'],
            'old_tyre_desc' => ['nullable', 'string', 'max:300'],
            'old_serial_raw' => ['nullable', 'string', 'max:80'],
            'old_file_name' => ['nullable', 'string', 'max:255'],
            'in_note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->row($this->service->close($tyreChange, $data)->load('outNpbg:id,no_npbg', 'inRi:id,no_ri'), [])]);
    }

    // ---- sheets "Ban Luar BPN" and "Deliver & Receive Ban SIG-BPN" (read + edit) ----

    public function bpn(Request $request, string $sheet): JsonResponse
    {
        [$table, $entity, $cols] = $this->bpnSheet($sheet);
        $q = DB::table($table)->orderByRaw('sheet_no IS NULL')->orderByDesc('sheet_no')->orderByDesc('id');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($w) => collect($cols)->each(fn ($c) => $w->orWhere($c, 'like', "%{$s}%")));
        }
        $page = $q->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $items = collect($page->items());
        $photos = $this->photos->forRows($entity, $items->pluck('id'));

        return response()->json([
            'data' => $items->map(fn ($r) => (array) $r + ['no' => $r->sheet_no, 'photos' => (object) ($photos[$r->id] ?? [])]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function bpnSave(Request $request, string $sheet, ?int $id = null): JsonResponse
    {
        [$table] = $this->bpnSheet($sheet);
        $data = $request->validate($sheet === 'inventory' ? [
            'cutoff_date' => ['nullable', 'date'], 'nopol_raw' => ['nullable', 'string', 'max:120'], 'tyre_desc' => ['nullable', 'string', 'max:300'],
            'serial_raw' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:2000'],
        ] : [
            'nopol_raw' => ['nullable', 'string', 'max:120'], 'npbg_date' => ['nullable', 'date'], 'npbg_no' => ['nullable', 'string', 'max:40'],
            'new_tyre_desc' => ['nullable', 'string', 'max:300'], 'new_serial_raw' => ['nullable', 'string', 'max:80'], 'out_note' => ['nullable', 'string', 'max:2000'],
            'ri_date' => ['nullable', 'date'], 'ri_no' => ['nullable', 'string', 'max:40'], 'old_tyre_desc' => ['nullable', 'string', 'max:300'],
            'old_serial_raw' => ['nullable', 'string', 'max:80'], 'in_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['nopol_raw'])) {
            $data['nopol_raw'] = mb_strtoupper(trim($data['nopol_raw']));
        }
        if ($sheet === 'transfers' && array_key_exists('npbg_no', $data)) {
            $data['npbg_no'] = AccurateDocRefs::normalizeNo($data['npbg_no']);
            $data['npbg_id'] = $data['npbg_no'] ? app(AccurateDocRefs::class)->npbgId($data['npbg_no']) : null;
        }
        if ($id) {
            abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
            DB::table($table)->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            $id = DB::table($table)->insertGetId($data + ['sheet_no' => 1 + (int) DB::table($table)->max('sheet_no'), 'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['data' => ['id' => $id]], $request->isMethod('post') ? 201 : 200);
    }

    public function bpnDestroy(string $sheet, int $id): JsonResponse
    {
        [$table, $entity] = $this->bpnSheet($sheet);
        foreach (\App\Models\TrackingPhoto::where(['entity' => $entity, 'entity_id' => $id])->get() as $p) {
            $this->photos->delete($p);
        }
        DB::table($table)->where('id', $id)->delete();

        return response()->json(['message' => 'Dihapus.']);
    }

    private function bpnSheet(string $sheet): array
    {
        return match ($sheet) {
            'inventory' => ['tyre_bpn_inventory', 'tyre_bpn', ['nopol_raw', 'tyre_desc', 'serial_raw', 'note']],
            'transfers' => ['tyre_bpn_transfers', 'tyre_bpn_transfer', ['nopol_raw', 'npbg_no', 'ri_no', 'new_tyre_desc', 'new_serial_raw', 'old_serial_raw', 'out_note', 'in_note']],
            default => abort(404),
        };
    }

    private function rules(): array
    {
        return [
            'nopol_raw' => ['nullable', 'string', 'max:120'],
            'change_date' => ['nullable', 'date'],
            'npbg_no' => ['nullable', 'string', 'max:40'],
            'new_tyre_desc' => ['nullable', 'string', 'max:300'],
            'new_serial_raw' => ['nullable', 'string', 'max:80'],
            'new_file_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:20'],
            'tyre_no' => ['nullable', 'integer', 'min:1', 'max:99'],
            'replacement_no' => ['nullable', 'integer', 'min:1', 'max:99'],
            'reason' => ['nullable', 'string', 'max:255'],
            'out_note' => ['nullable', 'string', 'max:500'],
            'in_date' => ['nullable', 'date'],
            'ri_no' => ['nullable', 'string', 'max:40'],
            'old_tyre_desc' => ['nullable', 'string', 'max:300'],
            'old_serial_raw' => ['nullable', 'string', 'max:80'],
            'old_file_name' => ['nullable', 'string', 'max:255'],
            'in_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function row(TyreChange $t, array $photos): array
    {
        $npbgNo = $t->out_npbg_no ?? ($t->relationLoaded('outNpbg') ? $t->outNpbg?->no_npbg : null);
        $riNo = $t->in_ri_no ?? ($t->relationLoaded('inRi') ? $t->inRi?->no_ri : null);
        // Excel helpers: "NA.25.IX.184 6323 (M-1224)" / "RI.NV.25.VII.003 7586 (M-2820)"
        $helper = fn (?string $doc, ?string $serial) => $doc && $serial && $serial !== '-' ? str_replace('/', '.', $doc).' '.$serial : null;

        return [
            'id' => $t->id, 'no' => $t->sheet_no, 'status' => $t->status, 'source' => $t->source,
            'nopol' => $t->nopol_raw ?? ($t->relationLoaded('asset') ? $t->asset?->name : null),
            'asset_code' => $t->relationLoaded('asset') ? $t->asset?->code : null,
            'change_date' => $t->change_date?->toDateString(), 'position' => $t->position, 'change_seq' => $t->change_seq,
            'tyre_no' => $t->tyre_no, 'replacement_no' => $t->replacement_no,
            'new_tyre_desc' => $t->new_tyre_desc, 'new_serial_raw' => $t->new_serial_raw, 'new_file_name' => $t->new_file_name,
            'helper_out' => $helper($npbgNo, $t->new_serial_raw),
            'old_tyre_desc' => $t->old_tyre_desc, 'old_serial_raw' => $t->old_serial_raw, 'old_file_name' => $t->old_file_name,
            'helper_in' => $helper($riNo, $t->old_serial_raw),
            'is_opening' => $t->is_opening, 'reason' => $t->reason,
            'out_note' => $t->out_note, 'in_note' => $t->in_note,
            'in_date' => $t->in_date?->toDateString(),
            'out_npbg' => $npbgNo, 'npbg_linked' => (bool) $t->out_npbg_id,
            'in_ri' => $riNo, 'ri_linked' => (bool) $t->in_ri_id,
            'photos' => (object) $photos,
            'created_at' => $t->created_at,
        ];
    }
}
