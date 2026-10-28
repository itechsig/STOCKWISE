<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\TrackingPhoto;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Evidence photos of the tracking rows: open, upload (camera), remove. Access follows the module's permissions. */
class TrackingPhotoController extends Controller
{
    public function __construct(private readonly TrackingPhotoService $photos) {}

    public function show(Request $request, TrackingPhoto $photo): StreamedResponse
    {
        $this->authorizeFor($request, $photo->entity, 0);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, null, ['Content-Type' => $photo->mime ?? 'image/jpeg']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', 'string', 'in:'.implode(',', array_keys(TrackingPhotoService::ENTITIES))],
            'entity_id' => ['required', 'integer', 'min:1'],
            'slot' => ['required', 'string', 'max:20'],
            'photo' => ['required', 'string', 'max:15000000'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);
        $this->authorizeFor($request, $data['entity'], 1);
        $p = $this->photos->putDataUrl($data['entity'], (int) $data['entity_id'], $data['slot'], $data['photo'], $data['file_name'] ?? null, $request->user()->id);
        $this->changed($p->entity, $p->entity_id, $p->slot, true);

        return response()->json(['data' => ['id' => $p->id, 'slot' => $p->slot, 'file_name' => $p->file_name]], 201);
    }

    public function destroy(Request $request, TrackingPhoto $photo): JsonResponse
    {
        $this->authorizeFor($request, $photo->entity, 1);
        $this->photos->delete($photo);
        $this->changed($photo->entity, $photo->entity_id, $photo->slot, false);

        return response()->json(['message' => 'Foto dihapus.']);
    }

    /** Rows whose status depends on a photo (Excel "Foto …" columns) are recomputed. */
    private function changed(string $entity, int $id, string $slot, bool $present): void
    {
        if (in_array($entity, ['lend', 'borrow'], true)) {
            app(\App\Services\Tracking\LendBorrowExcel::class)->recompute($entity, $id);
        }
        if ($entity === \App\Services\Tracking\UsedReturnSheets::PHOTO_ENTITY) {
            \App\Services\Tracking\UsedReturnSheets::recomputeOther($id);
        }
        if ($entity === 'maintenance_sub' && ($sub = \App\Models\MaintenanceOrderSub::find($id))) {
            app(\App\Services\Tracking\MaintenanceService::class)->photoChanged($sub, $slot, $present);
        }
    }

    /** $level 0 = view, 1 = edit */
    private function authorizeFor(Request $request, string $entity, int $level): void
    {
        $perm = TrackingPhotoService::ENTITIES[$entity][$level] ?? null;
        abort_unless($perm && $request->user()->hasPermission($perm), 403);
    }
}
