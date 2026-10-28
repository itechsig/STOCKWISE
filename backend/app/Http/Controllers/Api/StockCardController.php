<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockCard;
use App\Models\StockCardEntry;
use App\Services\Inventory\StockCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Kartu Stock — the paper "KARTU STOCK BARANG" (e.g. for the CCTV installation
 * project): created from Master Barang, lines written by hand, printable and as PDF.
 * View: item.view or inventory.view. Create / edit / upload: item.update.
 */
class StockCardController extends Controller
{
    public function __construct(private readonly StockCardService $cards) {}

    /** GET /api/stock-cards?search= */
    public function index(Request $request): JsonResponse
    {
        self::canView($request);
        // select() first: called after withSum / withCount it would drop their columns
        $q = StockCard::query()->join('items', 'items.id', '=', 'stock_cards.item_id')->select('stock_cards.*')
            ->with('item:id,code,description,unit_id', 'item.unit:id,code', 'project:id,name,code')
            ->withSum('entries as total_in', 'qty_in')->withSum('entries as total_out', 'qty_out')->withCount('entries')
            ->orderBy('stock_cards.card_number');
        $request->whenFilled('project_id', fn ($v) => $v === 'none' ? $q->whereNull('stock_cards.project_id') : $q->where('stock_cards.project_id', (int) $v));
        if ($s = trim((string) $request->string('search'))) {
            $q->where(fn ($w) => $w->where('stock_cards.card_number', 'like', "%{$s}%")->orWhere('items.code', 'like', "%{$s}%")
                ->orWhereLoose('items.description', $s)->orWhere('stock_cards.person_in_charge', 'like', "%{$s}%"));
        }
        $page = $q->paginate(min((int) $request->integer('per_page', 50), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (StockCard $c) => [
                'id' => $c->id, 'card_number' => $c->card_number,
                'project' => $c->project ? ['id' => $c->project->id, 'name' => $c->project->name, 'code' => $c->project->code] : null,
                'item' => ['id' => $c->item?->id, 'code' => $c->item?->code, 'description' => $c->item?->description, 'unit' => $c->item?->unit?->code],
                'location_warehouse' => $c->location_warehouse, 'location_rack' => $c->location_rack, 'person_in_charge' => $c->person_in_charge,
                'entries_count' => $c->entries_count,
                'total_masuk' => (float) $c->total_in, 'total_keluar' => (float) $c->total_out,
                'sisa' => round((float) $c->total_in - (float) $c->total_out, 4),
                'updated_at' => $c->updated_at,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /api/stock-card/items?search= — pick the item from Master Barang by code or name (exact code, code prefix, then name). */
    public function items(Request $request): JsonResponse
    {
        self::canView($request);

        return response()->json(['data' => self::searchItems(trim((string) $request->string('search')))]);
    }

    /** Items of Master Barang matching a code or name, with the cards they already have. Also used by the share link. */
    public static function searchItems(string $search): \Illuminate\Support\Collection
    {
        if (mb_strlen($search) < 2) {
            return collect();
        }
        $words = array_values(array_filter(preg_split('/\s+/', $search)));
        $code = str_replace(' ', '', mb_strtoupper($search));
        $cards = StockCard::query()->with('project:id,name')->get(['item_id', 'card_number', 'project_id'])->groupBy('item_id')
            ->map(fn ($g) => $g->map(fn ($c) => ['project_id' => $c->project_id, 'project' => $c->project?->name, 'card_number' => $c->card_number])->values()->all());

        $items = Item::query()->with('unit:id,code')
            ->where(function ($q) use ($code, $words) {
                $q->whereRaw('REPLACE(UPPER(code), " ", "") LIKE ?', ["%{$code}%"])
                    ->orWhere(function ($q) use ($words) {
                        foreach ($words as $w) {
                            $q->whereLoose('description', $w);
                        }
                    });
            })
            ->orderByRaw('CASE WHEN REPLACE(UPPER(code), " ", "") = ? THEN 0 WHEN REPLACE(UPPER(code), " ", "") LIKE ? THEN 1 ELSE 2 END', [$code, "{$code}%"])
            ->orderByDesc('is_active')->orderBy('code')
            ->limit(30)->get()
            ->map(fn (Item $i) => [
                'id' => $i->id, 'code' => $i->code, 'description' => $i->description, 'unit' => $i->unit?->code,
                'is_active' => (bool) $i->is_active,
                'accurate_qty' => $i->accurate_qty_onhand !== null ? (float) $i->accurate_qty_onhand : null,
                // the Kartu Stock it already has, per project
                'cards' => $cards[$i->id] ?? [],
            ]);

        return $items;
    }

    /** GET /api/stock-cards/next-number?item_id=&project_id= — like the paper card: the item code (unique within the project). */
    public function nextNumber(Request $request): JsonResponse
    {
        self::canView($request);

        return response()->json(['data' => self::nextNumberFor(Item::findOrFail((int) $request->integer('item_id')), $request->integer('project_id') ?: null)]);
    }

    public static function nextNumberFor(Item $item, ?int $projectId): string
    {
        $number = $item->code;
        for ($n = 2; StockCard::where('project_id', $projectId)->where('card_number', $number)->exists(); $n++) {
            $number = "{$item->code}-{$n}";
        }

        return $number;
    }

    /** POST /api/stock-cards */
    public function store(Request $request): JsonResponse
    {
        self::canEdit($request);
        $projectId = (int) $request->input('project_id');
        $data = $request->validate(self::headerRules() + [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'item_id' => ['required', 'integer', 'exists:items,id', Rule::unique('stock_cards', 'item_id')->where('project_id', $projectId)],
            'card_number' => ['required', 'string', 'max:60', Rule::unique('stock_cards', 'card_number')->where('project_id', $projectId)],
        ], self::messages());
        $card = StockCard::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $this->cards->present($card)], 201);
    }

    /** GET /api/stock-cards/{stockCard} */
    public function show(Request $request, StockCard $stockCard): JsonResponse
    {
        self::canView($request);

        $edit = $request->user()->hasPermission('item.update');

        return response()->json(['data' => $this->cards->present($stockCard) + ['can_edit' => $edit, 'can_delete' => $edit]]);
    }

    /** PUT /api/stock-cards/{stockCard} — the header (item stays). */
    public function update(Request $request, StockCard $stockCard): JsonResponse
    {
        self::canEdit($request);
        $projectId = (int) $request->input('project_id', $stockCard->project_id);
        $data = $request->validate(self::headerRules() + [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'card_number' => ['required', 'string', 'max:60', Rule::unique('stock_cards', 'card_number')->where('project_id', $projectId)->ignore($stockCard->id)],
        ], self::messages());
        abort_if(StockCard::where('project_id', $projectId)->where('item_id', $stockCard->item_id)->whereKeyNot($stockCard->id)->exists(), 422,
            'Proyek itu sudah punya kartu stock untuk barang ini.');
        $stockCard->update($data + ['updated_by' => $request->user()->id]);

        return $this->show($request, $stockCard->fresh());
    }

    /** DELETE /api/stock-cards/{stockCard} */
    public function destroy(Request $request, StockCard $stockCard): JsonResponse
    {
        self::canEdit($request);
        $stockCard->delete();

        return response()->json(['message' => 'Kartu stock dihapus.']);
    }

    /** POST /api/stock-cards/{stockCard}/entries */
    public function storeEntry(Request $request, StockCard $stockCard): JsonResponse
    {
        self::canEdit($request);
        $stockCard->entries()->create(self::entryData($request) + ['created_by' => $request->user()->id]);
        $stockCard->touch();

        return $this->show($request, $stockCard->fresh());
    }

    /** PUT /api/stock-cards/{stockCard}/entries/{entry} */
    public function updateEntry(Request $request, StockCard $stockCard, StockCardEntry $entry): JsonResponse
    {
        self::canEdit($request);
        abort_unless($entry->stock_card_id === $stockCard->id, 404);
        $entry->update(self::entryData($request));
        $stockCard->touch();

        return $this->show($request, $stockCard->fresh());
    }

    /** DELETE /api/stock-cards/{stockCard}/entries/{entry} */
    public function destroyEntry(Request $request, StockCard $stockCard, StockCardEntry $entry): JsonResponse
    {
        self::canEdit($request);
        abort_unless($entry->stock_card_id === $stockCard->id, 404);
        $entry->delete();
        $stockCard->touch();

        return $this->show($request, $stockCard->fresh());
    }

    /** GET /api/stock-cards/{stockCard}/pdf */
    public function pdf(Request $request, StockCard $stockCard): Response
    {
        self::canView($request);
        $name = 'Kartu-Stock-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $stockCard->card_number).'.pdf';

        return response($this->cards->pdf($stockCard), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** @return array<string, mixed> */
    public static function entryData(Request $request): array
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'doc_no' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'qty_in' => ['nullable', 'numeric', 'min:0'],
            'qty_out' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['qty_in'] = (float) ($data['qty_in'] ?? 0);
        $data['qty_out'] = (float) ($data['qty_out'] ?? 0);
        abort_if($data['qty_in'] <= 0 && $data['qty_out'] <= 0, 422, 'Isi jumlah Masuk atau Keluar.');

        return $data;
    }

    /** @return array<string, mixed> */
    public static function headerRules(): array
    {
        return [
            'location_warehouse' => ['nullable', 'string', 'max:60'],
            'location_rack' => ['nullable', 'string', 'max:60'],
            'person_in_charge' => ['nullable', 'string', 'max:150'],
            'director_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'card_number.unique' => 'Nomor kartu sudah dipakai di proyek ini.', 'card_number.required' => 'Nomor kartu wajib diisi.',
            'item_id.unique' => 'Barang ini sudah punya kartu stock di proyek ini.', 'project_id.required' => 'Pilih proyek.',
        ];
    }

    public static function canView(Request $request): void
    {
        abort_unless($request->user()->hasPermission('item.view') || $request->user()->hasPermission('inventory.view'), 403);
    }

    public static function canEdit(Request $request): void
    {
        abort_unless($request->user()->hasPermission('item.update'), 403);
    }
}
