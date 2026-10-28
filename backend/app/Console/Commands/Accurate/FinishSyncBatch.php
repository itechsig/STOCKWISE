<?php

namespace App\Console\Commands\Accurate;

use App\Models\SyncBatch;
use App\Services\Accurate\AccurateSyncService;
use App\Services\Inventory\AccurateDerivedAnalysis;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs AccurateSyncService::finishFromStaging() for one RUNNING batch, in its
 * own process (started by App\Support\BackgroundArtisan from
 * AccurateIngestController::complete() / SyncController::store()), so the
 * web server stays responsive during a sync.
 */
class FinishSyncBatch extends Command
{
    protected $signature = 'accurate:finish-batch {batch : sync_batches.id}';

    protected $description = 'Process the staged Accurate data of one RUNNING sync batch (normally started in the background).';

    public function handle(AccurateSyncService $service, AccurateDerivedAnalysis $analysis): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $batch = SyncBatch::find($this->argument('batch'));
        if (! $batch || $batch->status !== 'RUNNING') {
            $this->warn('Batch not found or no longer RUNNING — nothing to do.');

            return self::SUCCESS;
        }

        try {
            $batch = $service->finishFromStaging($batch);
            $this->info("Batch {$batch->sync_code} finished: {$batch->status}");
        } catch (Throwable $e) {
            report($e);
            $service->failBatch($batch->fresh(), mb_substr('Proses sync terhenti: '.$e->getMessage(), 0, 2000));
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Keep stock / lead time / Safety Stock and the dashboard analysis in
        // step with the data just synced. A failure here never turns a
        // successful sync into a failed one — it is only logged.
        try {
            $report = $analysis->run();
            Log::info('[sync] analysis refreshed after '.$batch->sync_code, $report);
        } catch (Throwable $e) {
            report($e);
        }

        return self::SUCCESS;
    }
}
