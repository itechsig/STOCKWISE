<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\SyncBatch;
use App\Services\Accurate\AccurateSyncService;
use App\Support\BackgroundArtisan;
use App\Support\SyncDiff;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function status()
    {
        $latest = SyncBatch::where('source', 'accurate')->latest('id')->first();

        return response()->json([
            'data' => $latest ? $this->batchSummary($latest) : null,
        ]);
    }

    public function history(Request $request)
    {
        $page = SyncBatch::where('source', 'accurate')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (SyncBatch $b) => $this->batchSummary($b))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * One sync with what it changed. Each log row carries its document number,
     * item and the fields that really changed (label, before → after) — computed
     * with the same rule the sync uses (App\Support\SyncDiff), so older logs read
     * right too: their "UPDATE" rows without a real change (e.g. unit_ratio
     * "1.0000" vs 1, logged on every NPBG line until 2026-10-01) are left out and
     * only counted.
     *
     * Filters: ?entity=npbg|ppb|ri|po|item|stock_opname|renumber|…  ?action=INSERT|UPDATE|DELETE|ERROR
     *          ?search= (document number / item code / text)
     */
    public function show(Request $request, SyncBatch $syncBatch)
    {
        $limit = 500;
        $entity = $request->string('entity')->toString();
        $action = strtoupper($request->string('action')->toString());
        $search = trim($request->string('search')->toString());

        $query = $syncBatch->logs()
            ->when($entity !== '', fn ($q) => $q->where('entity', $entity))
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('message', 'like', "%{$search}%")
                ->orWhere('source_id', 'like', "%{$search}%")
                ->orWhere('new_data', 'like', '%'.str_replace('/', '\\/', $search).'%')
                ->orWhere('old_data', 'like', '%'.str_replace('/', '\\/', $search).'%')))
            ->orderByRaw("CASE action WHEN 'ERROR' THEN 0 WHEN 'DELETE' THEN 1 WHEN 'UPDATE' THEN 2 WHEN 'INSERT' THEN 3 ELSE 4 END")
            ->orderByDesc('id');

        $logs = collect();
        $summary = [];
        $noChange = 0;
        $matched = 0;
        foreach ($query->lazy(1000) as $log) {
            $changes = match ($log->action) {
                'UPDATE' => SyncDiff::changes($log->old_data, $log->new_data),
                'DELETE' => SyncDiff::removed($log->old_data),
                default => [],
            };
            if ($log->action === 'UPDATE' && $changes === []) {
                $noChange++;

                continue;
            }
            $matched++;
            $summary[$log->entity][$log->action] = ($summary[$log->entity][$log->action] ?? 0) + 1;
            if ($logs->count() < $limit) {
                $log->setAttribute('changes', $changes);
                $logs->push($log);
            }
        }

        return response()->json([
            'data' => [
                ...$this->batchSummary($syncBatch),
                'logs' => $this->present($logs),
                'log_summary' => collect($summary)->map(fn ($a, $e) => ['entity' => $e, 'label' => SyncDiff::ENTITY_LABELS[$e] ?? $e, 'actions' => $a])->values(),
                'log_total' => $matched,
                'log_shown' => $logs->count(),
                'log_no_change' => $noChange,
            ],
        ]);
    }

    /**
     * Rows for the screen: document number (and the old one when it changed),
     * item code / name, changes. The item of a npbg/ppb/ri line is in its
     * payload as kode_barang; for 'item' it is the source_id; po / stock_opname
     * log a whole document, so no single item.
     */
    protected function present(\Illuminate\Support\Collection $logs): \Illuminate\Support\Collection
    {
        $codeOf = function ($log) {
            if ($log->entity === 'item') {
                return $log->source_id ?: null;
            }
            $data = $log->new_data ?? $log->old_data ?? [];

            return $data['kode_barang'] ?? null;
        };

        $codes = $logs->map($codeOf)->filter()->unique()->values();
        $itemsByCode = Item::whereIn('code', $codes)->select('id', 'code', 'description')->get()->keyBy('code');

        return $logs->map(function ($log) use ($codeOf, $itemsByCode) {
            $code = $codeOf($log);
            $item = $code ? $itemsByCode->get($code) : null;
            $new = $log->new_data ?? [];
            $old = $log->old_data ?? [];
            $data = $new ?: $old;
            $docField = SyncDiff::DOC_FIELD[$log->entity] ?? null;
            $doc = $docField ? ($new[$docField] ?? $old[$docField] ?? null) : null;
            $docOld = $docField && isset($old[$docField], $new[$docField]) && ! SyncDiff::same($old[$docField], $new[$docField]) ? $old[$docField] : null;

            return [
                'id' => $log->id,
                'entity' => $log->entity,
                'entity_label' => $log->entity === 'renumber' ? ($data['module'] ?? 'Tracking') : (SyncDiff::ENTITY_LABELS[$log->entity] ?? $log->entity),
                'source_id' => $log->source_id,
                'action' => $log->action,
                'status' => $log->status,
                'message' => $log->message,
                'document' => $doc !== null ? (string) $doc : null,
                'document_old' => $docOld !== null ? (string) $docOld : null,
                'item_code' => $code,
                'item_id' => $item?->id,
                'item_name' => $data['deskripsi_barang'] ?? $data['description'] ?? $item?->description ?? null,
                'changes' => $log->getAttribute('changes') ?? [],
                'created_at' => $log->created_at,
            ];
        });
    }

    public function store(Request $request, AccurateSyncService $service)
    {
        // Staging refresh (~15s for 20 tables) + ~9,600-row item upsert can
        // exceed PHP's default 30s limit; matches the pattern already used by
        // LegacyExportController for its own long-running operation.
        // On this production server, 300s was measured too low (~8.6 rows/sec
        // against real Accurate data needs ~19 min for a full batch — see
        // AccurateIngestController::complete() for the measurement), and
        // 1800s was ALSO hit (finishFromStaging()'s STPP/UsedReturn
        // derivation after the item loop adds real time beyond that
        // estimate). Raised again to match; keep in sync with
        // AccurateIngestController::complete().
        //
        // 2026-09-26: processed in a background process (see
        // AccurateIngestController::complete()) so pressing this button no
        // longer freezes the web app for everyone for up to an hour. The
        // response is the RUNNING batch (202); the UI follows progress via
        // GET /api/sync/status.
        $batch = $service->startManual($request->user()?->id);

        if ($batch->wasRecentlyCreated) {
            try {
                BackgroundArtisan::start('accurate:finish-batch', ['batch' => $batch->id]);
            } catch (\Throwable $e) {
                report($e);
                set_time_limit(7200);
                $service->finishFromStaging($batch);
            }
        }

        $batch = $batch->fresh();

        return response()->json([
            'data' => $this->batchSummary($batch),
        ], match ($batch->status) {
            'FAILED' => 502,
            'RUNNING' => 202,
            default => 201,
        });
    }

    protected function batchSummary(SyncBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'sync_code' => $batch->sync_code,
            'source' => $batch->source,
            'status' => $batch->status,
            'current_step' => $batch->current_step,
            'started_at' => $batch->started_at,
            'finished_at' => $batch->finished_at,
            'duration_seconds' => $batch->durationSeconds(),
            'total_records' => $batch->total_records,
            'inserted_records' => $batch->inserted_records,
            'updated_records' => $batch->updated_records,
            'skipped_records' => $batch->skipped_records,
            'deleted_records' => $batch->deleted_records,
            'error_records' => $batch->error_records,
            'error_message' => $batch->error_message,
        ];
    }
}
