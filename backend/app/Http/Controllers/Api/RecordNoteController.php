<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecordNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Catatan" column of the data tables: read the notes of the rows on screen,
 * write / clear one. Any signed-in user may note; who wrote it is kept.
 */
class RecordNoteController extends Controller
{
    private const ENTITY = ['required', 'string', 'regex:/^[a-z0-9_\-]{1,40}$/'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => self::ENTITY,
            'keys' => ['required', 'string', 'max:8000'],
        ]);
        $keys = array_slice(array_values(array_filter(array_map('trim', explode(',', $data['keys'])), fn ($k) => $k !== '')), 0, 300);

        $notes = RecordNote::query()->with('updater:id,name')
            ->where('entity', $data['entity'])->whereIn('entity_key', $keys)->get()
            ->mapWithKeys(fn (RecordNote $n) => [$n->entity_key => $this->out($n)]);

        return response()->json(['data' => (object) $notes->all()]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => self::ENTITY,
            'key' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = trim((string) ($data['note'] ?? ''));
        $where = ['entity' => $data['entity'], 'entity_key' => $data['key']];

        if ($note === '') {
            RecordNote::where($where)->delete();

            return response()->json(['data' => null]);
        }
        $row = RecordNote::updateOrCreate($where, ['note' => $note, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $this->out($row->load('updater:id,name'))]);
    }

    private function out(RecordNote $n): array
    {
        return ['note' => $n->note, 'by' => $n->updater?->name, 'at' => $n->updated_at?->toIso8601String()];
    }
}
