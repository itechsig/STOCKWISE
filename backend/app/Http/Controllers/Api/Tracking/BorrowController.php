<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\BorrowTransaction;
use App\Services\Tracking\BorrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BorrowController extends Controller
{
    /** Excel columns of sheet Borrow that the create / edit forms don't otherwise have. */
    private const EXCEL_RULES = [
        'unit_raw' => ['nullable', 'string', 'max:20'],
        'in_doc_no' => ['nullable', 'string', 'max:60'],
        'out_doc_no' => ['nullable', 'string', 'max:200'],
        'returned_at' => ['nullable', 'date'],
        'return_note' => ['nullable', 'string', 'max:500'],
    ];

    public function __construct(private readonly BorrowService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = BorrowTransaction::query()->with('item:id,code', 'unit:id,code', 'lenderVendor:id,name')
            ->orderByRaw('sheet_no IS NULL')->orderByDesc('sheet_no')->latest('borrowed_at')->latest('id');
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('lender_name', 'like', "%{$s}%")
                ->orWhere('description_raw', 'like', "%{$s}%")->orWhere('in_doc_no', 'like', "%{$s}%")->orWhere('out_doc_no', 'like', "%{$s}%")
                ->orWhere('condition_note', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $items = collect($page->items());
        $this->photos = app(\App\Services\Tracking\TrackingPhotoService::class)->forRows('borrow', $items->pluck('id'));

        return response()->json([
            'data' => $items->map(fn (BorrowTransaction $b) => $this->row($b)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(BorrowTransaction $borrow): JsonResponse
    {
        $this->photos = app(\App\Services\Tracking\TrackingPhotoService::class)->forRows('borrow', [$borrow->id]);

        return response()->json(['data' => $this->row($borrow->load('item:id,code', 'unit:id,code', 'lenderVendor:id,name', 'returnNpbg:id,no_npbg'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['required_without:item_id', 'nullable', 'string', 'max:400'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lender_vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'lender_name' => ['nullable', 'string', 'max:200'],
            'receipt_ref' => ['nullable', 'string', 'max:60'],
            'borrowed_at' => ['nullable', 'date'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ] + self::EXCEL_RULES);
        $borrow = $this->service->create($request->user(), $data);
        // a row typed straight into the table: its Excel columns too, status by the formula
        if (array_intersect_key($data, self::EXCEL_RULES)) {
            app(\App\Services\Tracking\LendBorrowExcel::class)->updateBorrow($borrow->refresh(), $data);
        }

        return response()->json(['data' => $this->row($borrow->refresh())], 201);
    }

    public function update(Request $request, BorrowTransaction $borrow): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'description_raw' => ['nullable', 'string', 'max:400'],
            'qty' => ['sometimes', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lender_vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'lender_name' => ['nullable', 'string', 'max:200'],
            'receipt_ref' => ['nullable', 'string', 'max:60'],
            'borrowed_at' => ['sometimes', 'nullable', 'date'], // emptied cell = keep the date
            'condition_note' => ['nullable', 'string', 'max:255'],
        ] + self::EXCEL_RULES);
        // rows from the Excel or typed in here: every column, status by the formula (like STPP)
        if (in_array($borrow->source, ['EXCEL', 'MANUAL'], true)) {
            return $this->show(app(\App\Services\Tracking\LendBorrowExcel::class)->updateBorrow($borrow, $data));
        }

        return response()->json(['data' => $this->row($this->service->update($borrow, $data))]);
    }

    public function destroy(BorrowTransaction $borrow): JsonResponse
    {
        $this->service->delete($borrow);

        return response()->json(['message' => 'Borrow dihapus.']);
    }

    public function return(Request $request, BorrowTransaction $borrow): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'return_npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'returned_at' => ['nullable', 'date'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->row($this->service->recordReturn($borrow, $data))]);
    }

    /** @var array<int, array<string, array{id:int, file_name:?string}>> photos of the rows being shown */
    private array $photos = [];

    private function row(BorrowTransaction $b): array
    {
        return [
            'id' => $b->id, 'number' => $b->number, 'status' => $b->status, 'no' => $b->sheet_no,
            'in_doc_no' => $b->in_doc_no ?? $b->receipt_ref,
            'out_doc_no' => $b->out_doc_no ?? $b->return_refs ?? ($b->relationLoaded('returnNpbg') ? $b->returnNpbg?->no_npbg : null),
            'unit_raw' => $b->unit_raw,
            'photos' => (object) ($this->photos[$b->id] ?? []),
            'item_code' => $b->relationLoaded('item') ? $b->item?->code : null,
            'description' => $b->description_raw, 'qty' => $b->qty, 'qty_returned' => $b->qty_returned,
            'unit' => $b->unit_raw ?? ($b->relationLoaded('unit') ? $b->unit?->code : null),
            'lender_name' => $b->relationLoaded('lenderVendor') ? ($b->lenderVendor?->name ?? $b->lender_name) : $b->lender_name,
            'receipt_ref' => $b->receipt_ref,
            'borrowed_at' => $b->borrowed_at?->toDateString(), 'returned_at' => $b->returned_at?->toDateString(),
            'return_npbg' => $b->relationLoaded('returnNpbg') ? $b->returnNpbg?->no_npbg : null,
            'source' => $b->source,
            'return_refs' => $b->return_refs,
            'return_note' => $b->return_note,
            'condition_note' => $b->condition_note, 'created_at' => $b->created_at,
        ];
    }
}
