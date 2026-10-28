<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StockCardShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Share links of a Kartu Stock project (made by a user who may edit Kartu Stock):
 * the link opens the project's cards without an account — Lihat / Edit / Full.
 * Deleting a link takes the access away at once.
 */
class StockCardShareLinkController extends Controller
{
    /** GET /api/stock-card-projects/{project}/share-links */
    public function index(Request $request, Project $project): JsonResponse
    {
        StockCardController::canEdit($request);

        return response()->json(['data' => StockCardShareLink::where('project_id', $project->id)->with('creator:id,name')
            ->latest('id')->get()->map(fn ($l) => $this->row($l))]);
    }

    /** POST /api/stock-card-projects/{project}/share-links */
    public function store(Request $request, Project $project): JsonResponse
    {
        StockCardController::canEdit($request);
        $data = $this->validated($request);
        $link = StockCardShareLink::create($data + ['project_id' => $project->id, 'token' => StockCardShareLink::newToken(), 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($link->load('creator:id,name'))], 201);
    }

    /** PUT /api/stock-card-share-links/{link} — change the access or the label. */
    public function update(Request $request, StockCardShareLink $link): JsonResponse
    {
        StockCardController::canEdit($request);
        $link->update($this->validated($request));

        return response()->json(['data' => $this->row($link->load('creator:id,name'))]);
    }

    /** DELETE /api/stock-card-share-links/{link} */
    public function destroy(Request $request, StockCardShareLink $link): JsonResponse
    {
        StockCardController::canEdit($request);
        $link->delete();

        return response()->json(['message' => 'Link dihapus — tidak bisa dibuka lagi.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'access' => ['required', 'in:'.implode(',', StockCardShareLink::ACCESS)],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(StockCardShareLink $l): array
    {
        return [
            'id' => $l->id, 'token' => $l->token, 'access' => $l->access, 'label' => $l->label,
            'created_by' => $l->creator?->name, 'created_at' => $l->created_at, 'last_used_at' => $l->last_used_at,
        ];
    }
}
