<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockCard;
use App\Models\StockCardEntry;
use App\Models\StockCardShareLink;
use App\Models\TrackingPhoto;
use App\Services\Inventory\StockCardService;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kartu Stock opened through a share link — no account, /api/public/kartu-stock/{token}.
 * Everything stays inside the link's project. VIEW: look, print, open attachments.
 * EDIT: also write lines, the card header and attachments. FULL: also delete, and
 * make new cards. Same shapes as the logged-in API, so the same pages serve both.
 */
class PublicStockCardController extends Controller
{
    public function __construct(private readonly StockCardService $cards, private readonly TrackingPhotoService $photos) {}

    /** GET … — the project and its cards. */
    public function project(string $token): JsonResponse
    {
        $link = $this->link($token);
        $projects = app(StockCardProjectController::class);

        return response()->json(['data' => $projects->row($link->project->loadCount('stockCards')) + [
            'can_edit' => false, // the project itself is not changed through a link
            'can_create_card' => $link->isFull(),
            'share' => ['access' => $link->access, 'label' => $link->label],
            'cards' => $projects->cardRows($link->project),
        ]]);
    }

    /** GET …/cards/{card} */
    public function show(string $token, int $card): JsonResponse
    {
        $link = $this->link($token);

        return $this->present($link, $this->card($link, $card));
    }

    /** POST …/cards — FULL: a new card of an item of Master Barang. */
    public function store(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token, full: true);
        $pid = $link->project_id;
        $data = $request->validate(StockCardController::headerRules() + [
            'item_id' => ['required', 'integer', 'exists:items,id', Rule::unique('stock_cards', 'item_id')->where('project_id', $pid)],
            'card_number' => ['required', 'string', 'max:60', Rule::unique('stock_cards', 'card_number')->where('project_id', $pid)],
        ], StockCardController::messages());
        $card = StockCard::create($data + ['project_id' => $pid]);

        return response()->json(['data' => $this->outside($this->cards->present($card)) + $this->rights($link)], 201);
    }

    /** PUT …/cards/{card} — EDIT: the header (the card stays in the project). */
    public function update(Request $request, string $token, int $card): JsonResponse
    {
        $link = $this->link($token, edit: true);
        $c = $this->card($link, $card);
        $data = $request->validate(StockCardController::headerRules() + [
            'card_number' => ['required', 'string', 'max:60', Rule::unique('stock_cards', 'card_number')->where('project_id', $link->project_id)->ignore($c->id)],
        ], StockCardController::messages());
        $c->update($data);

        return $this->present($link, $c->fresh());
    }

    /** DELETE …/cards/{card} — FULL */
    public function destroy(string $token, int $card): JsonResponse
    {
        $link = $this->link($token, full: true);
        $this->card($link, $card)->delete();

        return response()->json(['message' => 'Kartu stock dihapus.']);
    }

    /** POST …/cards/{card}/entries — EDIT */
    public function storeEntry(Request $request, string $token, int $card): JsonResponse
    {
        $link = $this->link($token, edit: true);
        $c = $this->card($link, $card);
        $c->entries()->create(StockCardController::entryData($request) + ['share_link_id' => $link->id]);
        $c->touch();

        return $this->present($link, $c->fresh());
    }

    /** PUT …/cards/{card}/entries/{entry} — EDIT */
    public function updateEntry(Request $request, string $token, int $card, int $entry): JsonResponse
    {
        $link = $this->link($token, edit: true);
        $c = $this->card($link, $card);
        StockCardEntry::where('stock_card_id', $c->id)->findOrFail($entry)->update(StockCardController::entryData($request));
        $c->touch();

        return $this->present($link, $c->fresh());
    }

    /** DELETE …/cards/{card}/entries/{entry} — FULL */
    public function destroyEntry(string $token, int $card, int $entry): JsonResponse
    {
        $link = $this->link($token, full: true);
        $c = $this->card($link, $card);
        StockCardEntry::where('stock_card_id', $c->id)->findOrFail($entry)->delete();
        $c->touch();

        return $this->present($link, $c->fresh());
    }

    /** GET …/cards/{card}/pdf */
    public function cardPdf(string $token, int $card): Response
    {
        $c = $this->card($this->link($token), $card);

        return $this->pdfResponse($this->cards->pdf($c), $c->card_number);
    }

    /** GET …/pdf — every card of the project. */
    public function projectPdf(string $token): Response
    {
        $link = $this->link($token);
        $cards = $link->project->stockCards()->join('items', 'items.id', '=', 'stock_cards.item_id')->select('stock_cards.*')->orderBy('stock_cards.card_number')->get();
        abort_if($cards->isEmpty(), 422, 'Proyek ini belum punya kartu stock.');

        return $this->pdfResponse($this->cards->pdf($cards, 'Kartu Stock — '.$link->project->name), $link->project->code ?: $link->project->name);
    }

    /** GET …/items?search= — FULL: pick the item of a new card (cards of other projects are not shown). */
    public function items(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token, full: true);
        $items = StockCardController::searchItems(trim((string) $request->string('search')))
            ->map(fn ($i) => ['cards' => array_values(array_filter($i['cards'], fn ($c) => $c['project_id'] === $link->project_id)), 'accurate_qty' => null] + $i);

        return response()->json(['data' => $items]);
    }

    /** GET …/next-number?item_id= — FULL */
    public function nextNumber(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token, full: true);

        return response()->json(['data' => StockCardController::nextNumberFor(Item::findOrFail((int) $request->integer('item_id')), $link->project_id)]);
    }

    /** POST …/files — EDIT: a photo / PDF on a line of the project. */
    public function upload(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token, edit: true);
        $data = $request->validate([
            'entity_id' => ['required', 'integer'],
            'photo' => ['required', 'string', 'max:15000000'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);
        $this->entryOfProject($link, (int) $data['entity_id']);
        $p = $this->photos->putDataUrl(StockCardService::PHOTO_ENTITY, (int) $data['entity_id'], 'auto', $data['photo'], $data['file_name'] ?? null, null);

        return response()->json(['data' => ['id' => $p->id, 'slot' => $p->slot, 'file_name' => $p->file_name]], 201);
    }

    /** GET …/files/{photo} */
    public function file(string $token, int $photo): StreamedResponse
    {
        $p = $this->photoOfProject($this->link($token), $photo);
        abort_unless(Storage::disk('local')->exists($p->path), 404);

        return Storage::disk('local')->response($p->path, null, ['Content-Type' => $p->mime ?? 'image/jpeg']);
    }

    /** DELETE …/files/{photo} — FULL */
    public function deleteFile(string $token, int $photo): JsonResponse
    {
        $link = $this->link($token, full: true);
        $this->photos->delete($this->photoOfProject($link, $photo));

        return response()->json(['message' => 'Lampiran dihapus.']);
    }

    // ---------------------------------------------------------------- helpers

    /** The link of the token, else 404; 403 when the link may not do this. */
    private function link(string $token, bool $edit = false, bool $full = false): StockCardShareLink
    {
        $link = StockCardShareLink::with('project')->where('token', $token)->first();
        abort_unless($link && $link->project, 404, 'Link tidak ditemukan atau sudah dihapus.');
        abort_if($edit && ! $link->canEdit(), 403, 'Link ini hanya untuk melihat.');
        abort_if($full && ! $link->isFull(), 403, 'Link ini tidak punya akses penuh.');
        if (! $link->last_used_at || $link->last_used_at->lt(now()->subMinutes(5))) {
            $link->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $link;
    }

    private function card(StockCardShareLink $link, int $id): StockCard
    {
        return StockCard::where('project_id', $link->project_id)->findOrFail($id);
    }

    private function entryOfProject(StockCardShareLink $link, int $entryId): StockCardEntry
    {
        return StockCardEntry::whereHas('card', fn ($q) => $q->where('project_id', $link->project_id))->findOrFail($entryId);
    }

    private function photoOfProject(StockCardShareLink $link, int $id): TrackingPhoto
    {
        $p = TrackingPhoto::where('entity', StockCardService::PHOTO_ENTITY)->findOrFail($id);
        $this->entryOfProject($link, (int) $p->entity_id);

        return $p;
    }

    /** @return array{can_edit: bool, can_delete: bool} */
    private function rights(StockCardShareLink $link): array
    {
        return ['can_edit' => $link->canEdit(), 'can_delete' => $link->isFull()];
    }

    private function present(StockCardShareLink $link, StockCard $card): JsonResponse
    {
        return response()->json(['data' => $this->outside($this->cards->present($card)) + $this->rights($link)]);
    }

    /** A card as people outside see it: without the company's Accurate stock. */
    private function outside(array $card): array
    {
        $card['item']['accurate_qty'] = null;

        return $card;
    }

    private function pdfResponse(string $pdf, string $name): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Kartu-Stock-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $name).'.pdf"',
        ]);
    }
}
