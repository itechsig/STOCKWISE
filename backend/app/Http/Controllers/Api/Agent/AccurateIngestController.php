<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\SyncBatch;
use App\Services\Accurate\AccurateSyncService;
use App\Services\Accurate\StagingTableWriter;
use App\Support\BackgroundArtisan;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives one staging-refresh push from the office Sync Agent
 * (sync-service/agent/) — the only place in the app a network payload is
 * allowed to shape the accurate_* MySQL tables. Never reachable by a human
 * user: gated by the `agent:sync` Sanctum ability, not the RBAC `permission:`
 * middleware (see routes/api.php's `agent` group).
 *
 * Flow: startSession() -> N x pushTable() (one call per chunk per table) ->
 * complete() (hands off to AccurateSyncService::finishFromStaging(), the
 * same mapping/business logic the manual "Sync Accurate" button already
 * uses) — or fail() if the Agent couldn't even get this far (backup restore
 * or Firebird read failed before any data was ready to push).
 */
class AccurateIngestController extends Controller
{
    public function startSession(AccurateSyncService $service)
    {
        $batch = $service->startForAgent();

        return response()->json([
            'data' => [
                'id' => $batch->id,
                'sync_code' => $batch->sync_code,
                'status' => $batch->status,
                // false means an already-RUNNING batch was returned instead
                // (another sync — manual or a previous Agent tick — is still
                // going) — the Agent should treat this tick as skipped, not
                // push into someone else's session.
                'is_new_session' => $batch->wasRecentlyCreated,
            ],
        ], 201);
    }

    public function pushTable(Request $request, SyncBatch $syncBatch, string $table, StagingTableWriter $writer)
    {
        if ($syncBatch->status !== 'RUNNING') {
            return response()->json(['message' => 'This sync session is no longer running.'], 409);
        }

        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.name' => ['required', 'string'],
            'columns.*.type' => ['required', 'string'],
            'columns.*.length' => ['nullable', 'integer', 'min:0'],
            'columns.*.scale' => ['nullable', 'integer', 'min:0', 'max:30'], // DECIMAL only
            'columns.*.nullable' => ['required', 'boolean'],
            'primary_key' => ['sometimes', 'array'],
            'primary_key.*' => ['string'],
            'rows' => ['present', 'array'], // an empty table in Accurate right now is a legitimate push, not an error
            'is_first_chunk' => ['required', 'boolean'],
        ]);

        try {
            if ($data['is_first_chunk']) {
                $writer->createTable($table, $data['columns'], $data['primary_key'] ?? []);
            }

            $inserted = $writer->insertRows($table, $data['rows']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['table' => $table, 'inserted' => $inserted]]);
    }

    public function complete(SyncBatch $syncBatch, AccurateSyncService $service)
    {
        if ($syncBatch->status !== 'RUNNING') {
            return response()->json(['message' => 'This sync session is no longer running.'], 409);
        }

        // Same reasoning as the manual "Sync Accurate" trigger
        // (SyncController::store()) — finishFromStaging() walks every staged
        // row plus derives STPP/UsedReturn over the existing npbg/ri tables;
        // confirmed against real local data this can take well past PHP's
        // default 30s (~90s observed for ~20k records on dev hardware).
        // On this production server (4-core, shared with Firebird), measured
        // throughput against real Accurate data was ~8.6 rows/sec — full
        // ~9,600-item batch needs ~19 min, not ~90s. 300s was hit and killed
        // the request mid-run twice (confirmed via storage/logs/laravel.log:
        // "Maximum execution time of 300 seconds exceeded"). Raised to 1800s,
        // which was STILL hit a third time — finishFromStaging() also derives
        // STPP/UsedReturn over the existing npbg/ri tables after the item
        // upsert loop, adding real time beyond the ~9,600-row estimate above
        // (confirmed: item upserts alone reached ~9,200/9,619 — 96% — before
        // the 1800s wall was hit). Raised again with much larger margin;
        // sync-service/.env's API_COMPLETE_TIMEOUT_SECONDS must stay >= this
        // value so the Agent doesn't give up first.
        //
        // 2026-09-26: no longer run inside this request. `php artisan serve`
        // is single-threaded, so a ~1 hour finishFromStaging() here froze the
        // web app for every user. The batch is claimed atomically (an Agent
        // retry never starts a second worker) and processed by
        // `accurate:finish-batch` in its own process; the Agent polls
        // GET .../sessions/{id} until the status leaves RUNNING.
        $claimed = SyncBatch::whereKey($syncBatch->id)
            ->where('status', 'RUNNING')
            ->where(fn ($q) => $q->whereNull('current_step')->orWhere('current_step', 'staging'))
            ->update(['current_step' => 'queued', 'updated_at' => now()]);

        if ($claimed === 1) {
            try {
                BackgroundArtisan::start('accurate:finish-batch', ['batch' => $syncBatch->id]);
            } catch (Throwable $e) {
                // Could not spawn a process: fall back to the old in-request
                // run rather than leave the batch stuck — slower, but it finishes.
                report($e);
                set_time_limit(7200);
                $service->finishFromStaging($syncBatch->fresh());
            }
        }

        return $this->sessionResponse($syncBatch->fresh());
    }

    /** Agent polls this after complete() until status is no longer RUNNING. */
    public function show(SyncBatch $syncBatch, AccurateSyncService $service)
    {
        // A worker that died (or never started) stops its heartbeat; reaping
        // here lets the polling Agent learn that within minutes instead of
        // waiting out its whole complete-timeout.
        $service->reapStaleRunningBatches();

        return $this->sessionResponse($syncBatch->fresh());
    }

    protected function sessionResponse(SyncBatch $batch)
    {
        return response()->json(['data' => [
            'id' => $batch->id,
            'sync_code' => $batch->sync_code,
            'status' => $batch->status,
            'current_step' => $batch->current_step,
            'total_records' => $batch->total_records,
            'error_records' => $batch->error_records,
            'error_message' => $batch->error_message,
        ]], $batch->status === 'RUNNING' ? 202 : 200);
    }

    public function fail(Request $request, SyncBatch $syncBatch, AccurateSyncService $service)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        if ($syncBatch->status !== 'RUNNING') {
            return response()->json(['message' => 'This sync session is no longer running.'], 409);
        }

        $batch = $service->failBatch($syncBatch, $data['message']);

        return response()->json(['data' => ['id' => $batch->id, 'sync_code' => $batch->sync_code, 'status' => $batch->status]]);
    }
}
