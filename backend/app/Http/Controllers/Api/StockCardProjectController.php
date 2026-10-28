<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StockCard;
use App\Services\Inventory\StockCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Projects of the Kartu Stock (e.g. a CCTV installation): a project holds many
 * cards, one per item. Same permissions as the cards (see StockCardController).
 */
class StockCardProjectController extends Controller
{
    public function __construct(private readonly StockCardService $cards) {}

    /** GET /api/stock-card-projects?search=&status= */
    public function index(Request $request): JsonResponse
    {
        StockCardController::canView($request);
        $q = Project::query()->withCount('stockCards')->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 ELSE 1 END")->orderByDesc('id');
        $request->whenFilled('status', fn ($v) => $q->where('status', $v));
        if ($s = trim((string) $request->string('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }

        return response()->json([
            'data' => $q->limit(500)->get()->map(fn (Project $p) => $this->row($p)),
            // cards made before projects existed — shown so they can be moved into one
            'unassigned_cards' => StockCard::whereNull('project_id')->count(),
        ]);
    }

    /** GET /api/stock-card-projects/{project} — the project and every card of it (summary). */
    public function show(Request $request, Project $project): JsonResponse
    {
        StockCardController::canView($request);
        $edit = $request->user()->hasPermission('item.update');

        return response()->json(['data' => $this->row($project->loadCount('stockCards')) + [
            'can_edit' => $edit, 'can_create_card' => $edit,
            'cards' => $this->cardRows($project),
        ]]);
    }

    /** Every card of the project with its totals. Also used by the share link. */
    public function cardRows(Project $project): \Illuminate\Support\Collection
    {
        $cards = $project->stockCards()->join('items', 'items.id', '=', 'stock_cards.item_id')->select('stock_cards.*')
            ->with('item:id,code,description,unit_id', 'item.unit:id,code')
            ->withSum('entries as total_in', 'qty_in')->withSum('entries as total_out', 'qty_out')->withCount('entries')
            ->orderBy('stock_cards.card_number')->get();

        return $cards->map(fn (StockCard $c) => [
                'id' => $c->id, 'card_number' => $c->card_number,
                'item' => ['id' => $c->item?->id, 'code' => $c->item?->code, 'description' => $c->item?->description, 'unit' => $c->item?->unit?->code],
                'location_warehouse' => $c->location_warehouse, 'location_rack' => $c->location_rack, 'person_in_charge' => $c->person_in_charge,
                'entries_count' => $c->entries_count,
                'total_masuk' => (float) $c->total_in, 'total_keluar' => (float) $c->total_out,
                'sisa' => round((float) $c->total_in - (float) $c->total_out, 4),
                'updated_at' => $c->updated_at,
            ]);
    }

    /** POST /api/stock-card-projects */
    public function store(Request $request): JsonResponse
    {
        StockCardController::canEdit($request);
        $project = Project::create($this->validated($request) + ['status' => 'ACTIVE']);

        return response()->json(['data' => $this->row($project->loadCount('stockCards'))], 201);
    }

    /** PUT /api/stock-card-projects/{project} */
    public function update(Request $request, Project $project): JsonResponse
    {
        StockCardController::canEdit($request);
        $project->update($this->validated($request, $project));

        return $this->show($request, $project);
    }

    /** DELETE /api/stock-card-projects/{project} — only while it has no cards. */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        StockCardController::canEdit($request);
        abort_if($project->stockCards()->exists() || \Illuminate\Support\Facades\DB::table('lend_transactions')->where('project_id', $project->id)->exists(),
            422, 'Proyek masih punya kartu stock / data lain — hapus kartunya dulu.');
        $project->delete();

        return response()->json(['message' => 'Proyek dihapus.']);
    }

    /** GET /api/stock-card-projects/{project}/pdf — every card of the project, one per page. */
    public function pdf(Request $request, Project $project): Response
    {
        StockCardController::canView($request);
        $cards = $project->stockCards()->join('items', 'items.id', '=', 'stock_cards.item_id')->select('stock_cards.*')->orderBy('stock_cards.card_number')->get();
        abort_if($cards->isEmpty(), 422, 'Proyek ini belum punya kartu stock.');
        $name = 'Kartu-Stock-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $project->code ?: $project->name).'.pdf';

        return response($this->cards->pdf($cards, 'Kartu Stock — '.$project->name), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Project $project = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('projects', 'code')->ignore($project?->id)],
            'status' => ['nullable', 'in:ACTIVE,CLOSED'],
        ], ['code.unique' => 'Kode proyek sudah dipakai.', 'name.required' => 'Nama proyek wajib diisi.']);
    }

    /** @return array<string, mixed> */
    public function row(Project $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'status' => $p->status, 'cards_count' => (int) ($p->stock_cards_count ?? 0), 'created_at' => $p->created_at];
    }
}
