<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Services\Tracking\AccurateDocRefs;
use App\Services\Tracking\TrackingPhotoService;
use App\Services\Tracking\UsedReturnSheets as S;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Pengembalian Bekas — the Excel sheets "Spare Part" (matrix) and "Spare Part Lain" (per item, with photos). */
class UsedReturnSheetController extends Controller
{
    public function __construct(private readonly TrackingPhotoService $photos, private readonly AccurateDocRefs $refs) {}

    public function index(Request $request, string $sheet): JsonResponse
    {
        $table = $this->table($sheet);
        $q = DB::table($table)->orderByRaw('sheet_no IS NULL')->orderByDesc('sheet_no')->orderByDesc('id');
        $request->whenFilled('status', fn ($v) => $q->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $cols = $sheet === 'matrix' ? ['npbg_no', 'ri_no', 'note'] : ['npbg_no', 'ri_no', 'description', 'note', 'out_file_name', 'in_file_name'];
            $q->where(fn ($w) => collect($cols)->each(fn ($c) => $w->orWhere($c, 'like', "%{$s}%")));
        }
        $page = $q->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $rows = collect($page->items());
        $photos = $sheet === 'other' ? $this->photos->forRows(S::PHOTO_ENTITY, $rows->pluck('id')) : [];
        $riLinked = $this->riLinked($rows->pluck('ri_no'));

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($sheet, $r, $photos[$r->id] ?? [], $riLinked)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]
                + ($sheet === 'matrix' ? ['components' => S::COMPONENTS, 'totals' => $this->totals()] : []),
        ]);
    }

    public function show(string $sheet, int $id): JsonResponse
    {
        $r = DB::table($this->table($sheet))->find($id) ?? abort(404);

        return response()->json(['data' => $this->present($sheet, $r, $sheet === 'other' ? ($this->photos->forRows(S::PHOTO_ENTITY, [$id])[$id] ?? []) : [], $this->riLinked(collect([$r->ri_no]))) + [
            'npbg' => $r->npbg_id ? $this->refs->npbgSummary($r->npbg_no) : null,
            'ri' => $r->ri_no && $r->ri_no !== '-' ? $this->refs->riSummary($r->ri_no) : null,
        ]]);
    }

    public function save(Request $request, string $sheet, ?int $id = null): JsonResponse
    {
        $table = $this->table($sheet);
        $d = $request->validate($sheet === 'matrix' ? [
            'npbg_date' => ['nullable', 'date'], 'npbg_no' => ['nullable', 'string', 'max:40'], 'ri_date' => ['nullable', 'date'],
            'ri_no' => ['nullable', 'string', 'max:40'], 'components' => ['nullable', 'array'], 'components.*' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:2000'],
        ] : [
            'npbg_date' => ['nullable', 'date'], 'npbg_no' => ['nullable', 'string', 'max:40'], 'description' => ['nullable', 'string', 'max:400'],
            'qty' => ['nullable', 'numeric'], 'unit' => ['nullable', 'string', 'max:20'], 'item_no' => ['nullable', 'integer', 'min:1'],
            'out_file_name' => ['nullable', 'string', 'max:255'], 'ri_no' => ['nullable', 'string', 'max:40'],
            'return_item_no' => ['nullable', 'integer', 'min:1'], 'in_file_name' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['npbg_no', 'ri_no'] as $doc) {
            if (array_key_exists($doc, $d)) {
                $v = trim((string) $d[$doc]);
                $d[$doc] = $v === '' ? null : ($v === '-' ? '-' : AccurateDocRefs::normalizeNo($v));
                if ($d[$doc] && $d[$doc] !== '-') {
                    $doc === 'npbg_no' ? $this->refs->npbgId($d[$doc]) : $this->refs->riId($d[$doc], 'ri_no'); // must exist in Accurate
                }
            }
        }
        if (array_key_exists('npbg_no', $d)) {
            $d['npbg_id'] = $d['npbg_no'] && $d['npbg_no'] !== '-' ? $this->refs->npbgId($d['npbg_no']) : null;
        }
        if (array_key_exists('components', $d)) {
            $d['components'] = json_encode(collect($d['components'])->only(array_keys(S::COMPONENTS))->filter(fn ($q) => $q !== null && (float) $q != 0)
                ->map(fn ($q) => (float) $q)->all() ?: (object) []);
        }

        DB::transaction(function () use ($table, $sheet, &$id, $d, $request) {
            if ($id) {
                abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
                DB::table($table)->where('id', $id)->update($d + ['updated_at' => now()]);
            } else {
                $id = DB::table($table)->insertGetId($d + ['sheet_no' => 1 + (int) DB::table($table)->max('sheet_no'), 'source' => 'MANUAL',
                    'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $r = DB::table($table)->find($id);
            if ($sheet === 'matrix') {
                DB::table($table)->where('id', $id)->update(['status' => S::matrixStatus($r->npbg_no, $r->ri_no)]);
            } else {
                S::recomputeOther($id);
            }
        });

        return $this->show($sheet, $id)->setStatusCode($request->isMethod('post') ? 201 : 200);
    }

    public function destroy(string $sheet, int $id): JsonResponse
    {
        $table = $this->table($sheet);
        if ($sheet === 'other') {
            foreach (\App\Models\TrackingPhoto::where(['entity' => S::PHOTO_ENTITY, 'entity_id' => $id])->get() as $p) {
                $this->photos->delete($p);
            }
        }
        DB::table($table)->where('id', $id)->delete();

        return response()->json(['message' => 'Dihapus.']);
    }

    /** Sum per component over the sheet (the Excel "=SUM(H7:H4165)" block beside it). */
    private function totals(): array
    {
        $totals = array_fill_keys(array_keys(S::COMPONENTS), 0.0);
        foreach (DB::table(S::MATRIX)->pluck('components') as $json) {
            foreach ((array) json_decode((string) $json, true) as $code => $q) {
                if (isset($totals[$code])) {
                    $totals[$code] += (float) $q;
                }
            }
        }

        return $totals;
    }

    private function table(string $sheet): string
    {
        return match ($sheet) { 'matrix' => S::MATRIX, 'other' => S::OTHER, default => abort(404) };
    }

    private function riLinked(\Illuminate\Support\Collection $nos): array
    {
        $nos = $nos->filter(fn ($n) => $n && $n !== '-')->unique()->values();

        return $nos->isEmpty() ? [] : DB::table('ri')->whereIn('no_ri', $nos)->distinct()->pluck('no_ri')->flip()->map(fn () => true)->all();
    }

    private function present(string $sheet, object $r, array $photos, array $riLinked): array
    {
        $base = [
            'id' => $r->id, 'no' => $r->sheet_no, 'npbg_date' => $r->npbg_date, 'npbg_no' => $r->npbg_no, 'npbg_linked' => (bool) $r->npbg_id,
            'status' => $r->status, 'ri_no' => $r->ri_no, 'ri_linked' => isset($riLinked[$r->ri_no]), 'note' => $r->note, 'source' => $r->source,
        ];
        if ($sheet === 'matrix') {
            return $base + ['ri_date' => $r->ri_date, 'components' => (object) json_decode((string) $r->components, true)];
        }

        return $base + [
            'description' => $r->description, 'qty' => $r->qty !== null ? (float) $r->qty : null, 'unit' => $r->unit,
            'item_no' => $r->item_no, 'out_file_name' => $r->out_file_name, 'helper_out' => S::fileName($r->npbg_no, $r->item_no),
            'return_item_no' => $r->return_item_no, 'in_file_name' => $r->in_file_name, 'helper_in' => S::fileName($r->ri_no, $r->return_item_no),
            'photos' => (object) $photos,
        ];
    }
}
