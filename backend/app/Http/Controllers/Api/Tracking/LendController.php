<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\LendTransaction;
use App\Services\Tracking\LendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LendController extends Controller
{
    /** Excel columns of sheet Lend that the create / edit forms don't otherwise have. */
    private const EXCEL_RULES = [
        'unit_raw' => ['nullable', 'string', 'max:20'],
        'out_doc_no' => ['nullable', 'string', 'max:60'],
        'return_doc_no' => ['nullable', 'string', 'max:200'],
        'return_date' => ['nullable', 'date'],
        'condition_in' => ['nullable', 'string', 'max:255'], // Excel "Tgl Kembali" written as a word: LOST / TIDAK JADI
        'return_note' => ['nullable', 'string', 'max:500'],
    ];

    public function __construct(private readonly LendService $service) {}

    public function index(Request $request): JsonResponse
    {
        // No task scheduler runs here: mark overdue loans when they are looked at.
        $this->service->markOverdue();
        $query = LendTransaction::query()->with('item:id,code', 'unit:id,code', 'outNpbg:id,no_npbg')
            ->orderByRaw('sheet_no IS NULL')->orderByDesc('sheet_no')->latest('out_date')->latest('id');
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('borrower_name', 'like', "%{$s}%")->orWhereLoose('description_raw', $s)
                ->orWhere('requester_name', 'like', "%{$s}%")->orWhere('out_doc_no', 'like', "%{$s}%")->orWhere('return_doc_no', 'like', "%{$s}%")
                ->orWhere('return_refs', 'like', "%{$s}%")->orWhere('condition_out', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $items = collect($page->items());
        $this->photos = app(\App\Services\Tracking\TrackingPhotoService::class)->forRows('lend', $items->pluck('id'));

        return response()->json([
            'data' => $items->map(fn (LendTransaction $l) => $this->row($l)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(LendTransaction $lend): JsonResponse
    {
        $this->service->markOverdue();
        $lend->refresh();
        $this->photos = app(\App\Services\Tracking\TrackingPhotoService::class)->forRows('lend', [$lend->id]);

        return response()->json(['data' => $this->row($lend->load('item:id,code', 'unit:id,code', 'outNpbg:id,no_npbg', 'returnRi:id,number'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['required_without:item_id', 'nullable', 'string', 'max:400'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'purpose' => ['nullable', 'in:INTERNAL,PROJECT,RELASI'],
            'borrower_name' => ['nullable', 'string', 'max:200'],
            'requester_name' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'est_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'out_npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'out_date' => ['nullable', 'date'],
            'condition_out' => ['nullable', 'string', 'max:255'],
        ] + self::EXCEL_RULES);
        $lend = $this->service->create($request->user(), $data);
        // a row typed straight into the table: its Excel columns too, status by the formula
        if (array_intersect_key($data, self::EXCEL_RULES)) {
            app(\App\Services\Tracking\LendBorrowExcel::class)->updateLend($lend->refresh(), $data);
        }

        return response()->json(['data' => $this->row($lend->refresh())], 201);
    }

    public function update(Request $request, LendTransaction $lend): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['nullable', 'string', 'max:400'],
            'qty' => ['sometimes', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'purpose' => ['sometimes', 'in:INTERNAL,PROJECT,RELASI'],
            'borrower_name' => ['nullable', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'est_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'out_date' => ['sometimes', 'nullable', 'date'], // emptied cell = keep the date
            'condition_out' => ['nullable', 'string', 'max:255'],
            'requester_name' => ['nullable', 'string', 'max:100'],
        ] + self::EXCEL_RULES);
        // rows from the Excel or typed in here: every column, status by its formula (like STPP)
        if (in_array($lend->source, ['EXCEL', 'MANUAL'], true)) {
            return $this->show(app(\App\Services\Tracking\LendBorrowExcel::class)->updateLend($lend, $data));
        }

        return response()->json(['data' => $this->row($this->service->update($lend, $data))]);
    }

    public function destroy(LendTransaction $lend): JsonResponse
    {
        $this->service->delete($lend);

        return response()->json(['message' => 'Lend dihapus.']);
    }

    public function return(Request $request, LendTransaction $lend): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'return_ri_id' => ['nullable', 'integer', 'exists:receivings,id'],
            'return_date' => ['nullable', 'date'],
            'condition_in' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->recordReturn($lend, $data))]);
    }

    /** @var array<int, array<string, array{id:int, file_name:?string}>> photos of the rows being shown */
    private array $photos = [];

    private function row(LendTransaction $l): array
    {
        $out = $l->out_doc_no ?? ($l->relationLoaded('outNpbg') ? $l->outNpbg?->no_npbg : null);
        $back = $l->return_doc_no ?? $l->return_refs;

        return [
            'id' => $l->id, 'number' => $l->number, 'status' => $l->status, 'no' => $l->sheet_no,
            'out_doc_no' => $out, 'return_doc_no' => $back,
            'unit_raw' => $l->unit_raw,
            'photos' => (object) ($this->photos[$l->id] ?? []),
            'item_code' => $l->relationLoaded('item') ? $l->item?->code : null,
            'description' => $l->description_raw, 'qty' => $l->qty, 'qty_returned' => $l->qty_returned,
            'unit' => $l->unit_raw ?? ($l->relationLoaded('unit') ? $l->unit?->code : null),
            'purpose' => $l->purpose, 'borrower_name' => $l->borrower_name, 'requester_name' => $l->requester_name,
            'est_days' => $l->est_days, 'return_note' => $l->return_note,
            'out_date' => $l->out_date?->toDateString(), 'due_date' => $l->due_date?->toDateString(),
            'return_date' => $l->return_date?->toDateString(),
            'out_npbg' => $l->relationLoaded('outNpbg') ? $l->outNpbg?->no_npbg : null,
            'source' => $l->source,
            'return_refs' => $l->return_refs,
            'return_ri' => $l->relationLoaded('returnRi') ? $l->returnRi?->number : null,
            'condition_out' => $l->condition_out, 'condition_in' => $l->condition_in,
            'created_at' => $l->created_at,
        ];
    }
}
