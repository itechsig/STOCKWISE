<?php

namespace App\Services\Accurate;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Ri;
use App\Models\Site;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StppTransaction;
use App\Models\SyncBatch;
use App\Models\SyncLog;
use App\Models\Unit;
use App\Models\UsedReturn;
use App\Models\Vendor;
use App\Services\Notifications\NotificationDispatcher;
use App\Models\Warehouse;
use App\Services\DocumentNumberService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates the Stockwise side of one Accurate -> Stockwise sync run.
 *
 * Laravel never talks to Firebird directly and never shells out to a local
 * process either (see docs/sync-architecture.md): the sync-service/ Python
 * Agent — running independently at the office, on its own 04:00/10:30/20:30
 * schedule — restores the newest stable .GBK backup to a local staging
 * Firebird DB and PUSHES the mirrored rows here over HTTPS
 * (App\Http\Controllers\Api\Agent\AccurateIngestController writes them into
 * the accurate_* MySQL tables via StagingTableWriter). This class only ever
 * starts with those accurate_* tables already populated: match
 * accurate_item.ITEMNO against items.code and upsert, logging every row's
 * outcome to sync_logs. NPBG (accurate_arinv + accurate_arinvdet -> npbg),
 * PPB (accurate_requisition + accurate_requisitiondet -> ppb) and RI
 * (accurate_apinv + accurate_apitmdet -> ri) all follow the same flat
 * one-row-per-line pattern.
 *
 * Items: Accurate's stock figure is a company-wide total (no per-warehouse
 * breakdown — see docs/ACCURATE_MAPPING.md §Warehouses), so it is written to
 * the reference-only `items.accurate_qty_onhand`/`accurate_qty_onorder`
 * columns, never into the per-warehouse `inventory` table.
 */
class AccurateSyncService
{
    /** How long a RUNNING batch can go without finishing before it's considered dead (crashed/killed process, not a real lock holder). */
    protected const STALE_RUNNING_MINUTES = 20;

    /**
     * The code of an Accurate category/hierarchy node: 3 letters, then ".NN" levels (AUT, AUT.01,
     * AUT.01.01, SSP.27.02) — see docs/accurate-database-analysis.md §12. Products are AUT.0001 etc.,
     * but Accurate also holds products typed differently (OFN.300, "PUI. 0914") and products without
     * a unit (PUI.0924): those are products too — see isProductRow().
     */
    protected const CATEGORY_ITEMNO_PATTERN = '/^[A-Za-z]{3}(\.[0-9]{1,2})*$/';

    /** ITEMNOs that are some row's PARENTITEM (a node with children is never a product) */
    protected array $parentCodes = [];

    /**
     * Is this accurate_item row a product (→ Master Produk) or a category node? A node has children
     * or a category-shaped code (even with a unit: a data anomaly, §3 of the brief). Everything else
     * is a product, even with an odd code or without a unit — it must never stay out of the Master.
     */
    public static function isProductRow(string $itemno, bool $hasChildren): bool
    {
        return ! $hasChildren && ! preg_match(self::CATEGORY_ITEMNO_PATTERN, $itemno);
    }

    /** Max category levels resolved upward from a product's PARENTITEM (Anak 1/2/3 — see docs/accurate-database-analysis.md §12 on why there's no "Kategori Induk" level in this data). */
    protected const MAX_CATEGORY_LEVELS = 3;

    /**
     * Kategori Induk — NOT walked from Accurate's PARENTITEM chain (there is
     * no top-level node to read there, see CATEGORY_ITEMNO_PATTERN docblock).
     * Instead, per explicit user instruction, derived from the item code's
     * own 3-letter prefix through this fixed translation table.
     */
    protected const KATEGORI_INDUK_MAP = [
        'SSP' => 'Small Spare Parts',
        'PUI' => 'Post-Use Items',
        'OFN' => 'Office Needs',
        'OAA' => 'Office Apparel & Accessories',
        'MAA' => 'Manufacture & Assembly',
        'MAI' => 'Maintenance & Industry',
        'HHN' => 'Household Needs',
        'ETA' => 'Etalase',
        'EAE' => 'Electronics & Electricals',
        'BSP' => 'Big Spare Parts',
        'AUT' => 'Automotive',
        'AST' => 'Assets',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /**
     * Manual/admin re-run (POST /api/sync/accurate, the "Sync Accurate"
     * button) — reprocesses whatever is CURRENTLY in the accurate_* tables.
     * It does not talk to Firebird or the Agent at all: fresh data only ever
     * arrives via the Agent's own scheduled push (see startForAgent()). This
     * is still useful on its own — e.g. re-running the mapping/business
     * logic after a Stockwise-side bug fix, without waiting for the Agent's
     * next tick.
     */
    /**
     * Manual "Sync Accurate" button: only claims the sync lock and returns the
     * batch (RUNNING, step 'queued'); SyncController starts the actual
     * processing in a background process. Returns the already-running batch
     * instead if a sync is in progress.
     */
    public function startManual(?int $userId = null): SyncBatch
    {
        return $this->startBatch($userId, 'queued');
    }

    public function run(?int $userId = null): SyncBatch
    {
        $batch = $this->startBatch($userId, 'items');

        return $batch->wasRecentlyCreated ? $this->finishFromStaging($batch) : $batch;
    }

    /**
     * Called by AccurateIngestController::startSession() when the Agent
     * begins pushing a fresh staging refresh. Returns an already-RUNNING
     * batch instead of creating a new one if a sync (manual or another
     * Agent tick) is already in progress — same sync lock as run().
     */
    public function startForAgent(): SyncBatch
    {
        // the Agent started from the web (AgentRunController): a manual sync, by that user
        $userId = \Illuminate\Support\Facades\Cache::pull(StockwisePurge::MANUAL_AGENT_RUN);

        return $this->startBatch($userId ? (int) $userId : null, 'staging');
    }

    /**
     * The Agent calls this (AccurateIngestController::fail()) when it can't
     * even reach the point of pushing data — backup restore failed, Firebird
     * staging read failed, etc. Mirrors the FAILED shape the old
     * refreshStaging() try/catch used to produce, just triggered externally.
     */
    public function failBatch(SyncBatch $batch, string $message): SyncBatch
    {
        $batch->update([
            'status' => 'FAILED',
            'finished_at' => now(),
            'current_step' => null,
            'error_message' => $message,
        ]);

        NotificationDispatcher::toPermission(
            'sync.accurate.view', 'sync', 'danger',
            'Sync Accurate gagal',
            "Batch {$batch->sync_code} gagal sebelum sempat memproses data: {$message}",
            '/sync/history',
        );

        return $batch->fresh();
    }

    /** Sync lock + batch bookkeeping shared by run() and startForAgent(). */
    protected function startBatch(?int $userId, string $initialStep): SyncBatch
    {
        $this->reapStaleRunningBatches();

        $inProgress = SyncBatch::where('source', 'accurate')->where('status', 'RUNNING')->first();
        if ($inProgress) {
            return $inProgress; // sync lock: don't start a second run while one is genuinely in progress
        }

        return SyncBatch::create([
            'sync_code' => $this->nextSyncCode(),
            'source' => 'accurate',
            'started_at' => now(),
            'status' => 'RUNNING',
            'current_step' => $initialStep,
            'created_by' => $userId,
        ]);
    }

    /**
     * Everything after the accurate_* staging tables are already populated
     * (by the Agent's push, or already sitting there for a manual re-run):
     * match + upsert into Stockwise's own tables, in dependency order.
     *
     * Order matters: PO's accurate_ppb_id resolves against `ppb` rows, and
     * RI's accurate_po_item_id resolves against `purchase_order_items` rows
     * — both need their upstream link already synced in this same run (see
     * syncPo()/syncRi() chain-linking comments). current_step is a short
     * stable key (frontend maps it to a label), written before each phase
     * (not after) so a client polling GET /api/sync/status mid-run — see
     * useSyncStatus()'s fast refetch while RUNNING — can show real progress,
     * not a guess.
     */
    public function finishFromStaging(SyncBatch $batch): SyncBatch
    {
        $this->logBuffer = [];
        $this->currentBatch = $batch;
        $batch->update(['current_step' => 'items']);
        $itemCounts = $this->runStep($batch, fn () => $this->syncItems($batch));

        $batch->update(['current_step' => 'npbg']);
        $this->renumbered = [];
        $npbgCounts = $this->runStep($batch, fn () => $this->syncNpbg($batch));
        // a renumbered NPBG: its number in the tracking modules follows, before the derivations below match by number
        $batch->update(['current_step' => 'npbg_renumber']);
        $this->runStep($batch, fn () => $this->propagateRenumbers($batch, 'npbg'));

        // Derived from the NPBG rows just synced above, not from Accurate
        // staging tables directly — see deriveStppFromNpbg() docblock.
        $batch->update(['current_step' => 'stpp']);
        $stppCounts = $this->runStep($batch, fn () => $this->deriveStppFromNpbg($batch));

        $batch->update(['current_step' => 'ppb']);
        $ppbCounts = $this->runStep($batch, fn () => $this->syncPpb($batch));

        $batch->update(['current_step' => 'po']);
        $poCounts = $this->runStep($batch, fn () => $this->syncPo($batch));

        // PPB status depends on its POs (Accurate closes a PPB once it is all on a PO)
        $batch->update(['current_step' => 'ppb_status']);
        $this->runStep($batch, fn () => $this->deriveSafely($batch, 'ppb_status', fn () => \App\Support\PpbLineStatus::refreshAll()));

        // PPB approval before PO: new PPBs wait for approval, a PO Accurate made for an unapproved PPB is flagged
        $batch->update(['current_step' => 'ppb_approval']);
        // (not in Accurate-only mode: no approvals are made in STOCKWISE then)
        if (! StockwisePurge::enabled()) {
            $this->runStep($batch, fn () => $this->deriveSafely($batch, 'ppb_approval', fn () => app(\App\Services\PpbApprovalService::class)->scan()));
        }

        $batch->update(['current_step' => 'ri']);
        $riCounts = $this->runStep($batch, fn () => $this->syncRi($batch));
        $batch->update(['current_step' => 'ri_renumber']);
        $this->runStep($batch, fn () => $this->propagateRenumbers($batch, 'ri'));

        // Derived from the RI rows just synced above (divisi "NV" only, per
        // explicit user instruction) — see deriveUsedReturnsFromRi() docblock.
        $batch->update(['current_step' => 'used_returns']);
        $usedReturnCounts = $this->runStep($batch, fn () => $this->deriveUsedReturnsFromRi($batch));

        // Lend / Borrow recognised from the NPBG + RI keterangan just synced
        // (see App\Services\Tracking\LendBorrowFromAccurate).
        $batch->update(['current_step' => 'lend_borrow']);
        $lendBorrowCounts = $this->runStep($batch, fn () => $this->deriveSafely($batch, 'lend_borrow', fn () => app(\App\Services\Tracking\LendBorrowFromAccurate::class)->derive()));

        // Ban Luar: tyres issued to a vehicle (NPBG) and the old ones back (RI NV).
        $batch->update(['current_step' => 'tyres']);
        $tyreCounts = $this->runStep($batch, fn () => $this->deriveSafely($batch, 'tyre', fn () => app(\App\Services\Tracking\TyreFromAccurate::class)->derive()));
        foreach (['total', 'inserted', 'updated', 'skipped', 'errors'] as $k) {
            $lendBorrowCounts[$k] += $tyreCounts[$k] ?? 0;
        }

        $batch->update(['current_step' => 'stock_opname']);
        $soCounts = $this->runStep($batch, fn () => $this->syncStockOpname($batch));

        // Accurate-only mode, the scheduled night sync: whatever was made in STOCKWISE goes, the data is pure
        // Accurate (Kartu Stock & tracking stay); the day's syncs and every manual sync keep it
        if (StockwisePurge::dueNow($batch)) {
            $batch->update(['current_step' => 'stockwise_purge']);
            $this->runStep($batch, fn () => $this->deriveSafely($batch, 'stockwise_purge', function () use ($batch) {
                $removed = app(StockwisePurge::class)->run();
                if (array_sum($removed) > 0) {
                    $this->writeLog(['sync_batch_id' => $batch->id, 'entity' => 'stockwise_purge', 'source_id' => '-', 'action' => 'DELETE', 'status' => 'SUCCESS',
                        'message' => mb_substr('Data STOCKWISE dihapus: '.collect($removed)->filter()->map(fn ($n, $k) => "{$k} {$n}")->implode(', '), 0, 1000)]);
                }

                return ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
            }));
        }
        $counts = [
            'total' => $itemCounts['total'] + $npbgCounts['total'] + $stppCounts['total'] + $ppbCounts['total'] + $riCounts['total'] + $usedReturnCounts['total'] + $poCounts['total'] + $soCounts['total'] + $lendBorrowCounts['total'],
            'inserted' => $itemCounts['inserted'] + $npbgCounts['inserted'] + $stppCounts['inserted'] + $ppbCounts['inserted'] + $riCounts['inserted'] + $usedReturnCounts['inserted'] + $poCounts['inserted'] + $soCounts['inserted'] + $lendBorrowCounts['inserted'],
            'updated' => $itemCounts['updated'] + $npbgCounts['updated'] + $ppbCounts['updated'] + $riCounts['updated'] + $poCounts['updated'] + $soCounts['updated'] + $lendBorrowCounts['updated'],
            'skipped' => $itemCounts['skipped'] + $npbgCounts['skipped'] + $stppCounts['skipped'] + $ppbCounts['skipped'] + $riCounts['skipped'] + $usedReturnCounts['skipped'] + $poCounts['skipped'] + $soCounts['skipped'] + $lendBorrowCounts['skipped'],
            // Only npbg/ppb/ri ever produce a 'deleted' bucket (see deleteOrphans()) — item/po/stock_opname arrays don't have that key at all.
            'deleted' => ($npbgCounts['deleted'] ?? 0) + ($ppbCounts['deleted'] ?? 0) + ($riCounts['deleted'] ?? 0),
            'errors' => $itemCounts['errors'] + $npbgCounts['errors'] + $stppCounts['errors'] + $ppbCounts['errors'] + $riCounts['errors'] + $usedReturnCounts['errors'] + $poCounts['errors'] + $soCounts['errors'] + $lendBorrowCounts['errors'],
        ];

        $batch->update([
            'finished_at' => now(),
            'current_step' => null,
            'total_records' => $counts['total'],
            'inserted_records' => $counts['inserted'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'deleted_records' => $counts['deleted'],
            'error_records' => $counts['errors'],
            'status' => $counts['errors'] > 0
                ? ($counts['inserted'] + $counts['updated'] > 0 ? 'PARTIAL' : 'FAILED')
                : 'SUCCESS',
        ]);

        $status = $batch->status;
        if ($status !== 'SUCCESS') {
            NotificationDispatcher::toPermission(
                'sync.accurate.view', 'sync', $status === 'FAILED' ? 'danger' : 'warning',
                $status === 'FAILED' ? 'Sync Accurate gagal' : 'Sync Accurate selesai sebagian',
                "Batch {$batch->sync_code}: {$counts['total']} data diproses, {$counts['errors']} error.",
                '/sync/history',
            );
        }

        return $batch->fresh();
    }


    /**
     * Sync log rows are buffered and bulk-inserted (one INSERT per
     * LOG_FLUSH_EVERY rows instead of one autocommitted INSERT — two fsyncs
     * on this MySQL — per staged row, which was ~95% of a sync's runtime).
     * Unchanged rows (SKIP) are only counted in the batch totals, not logged
     * one by one: they were ~95% of all sync_logs rows and carry no
     * information. Every flush also bumps the batch's updated_at — the
     * heartbeat reapStaleRunningBatches() relies on.
     */
    protected const LOG_FLUSH_EVERY = 500;

    /** @var array<int, array<string, mixed>> */
    protected array $logBuffer = [];

    protected ?SyncBatch $currentBatch = null;

    /** Reads requester / checker, location and jenis pengeluaran of the NPBG / RI lines being synced. */
    protected ?AccurateLineFields $lineFields = null;

    /** PODET.REQID + REQUISITION staged → RI "No. PPB" can be read */
    protected bool $riHasReqNo = false;

    /** @var array<string, float>|null ITEMNO => qty as Accurate computes it (null: staging has no ITEMBALANCE/ITEMHIST) */
    protected ?array $accurateQty = null;

    /** @var array<string, float>|null ITEMNO => qty on order as Accurate computes it (null: no PO staging) */
    protected ?array $accurateOnOrder = null;

    /** @var array<string, array<string, array{new:string, ids:int[]}>> entity => old number => new number + line ids (DocumentRenumber) */
    protected array $renumbered = [];

    /** Line-level changes of the PO / opname being synced, logged with its header as new_data._lines. */
    protected array $lineChanges = [];

    protected float $lastHeartbeat = 0.0;

    /** "NPBG ATK/26/IX/046 → ATK/26/X/001 (nomor berubah) baris 1 diperbarui." */
    protected function docMessage(string $label, mixed $oldNo, mixed $newNo, mixed $seq): string
    {
        $no = \App\Support\SyncDiff::same($oldNo, $newNo) || $oldNo === null
            ? (string) $newNo
            : "{$oldNo} → {$newNo} (nomor berubah)";

        return "{$label} {$no}".($seq !== null ? " baris {$seq}" : '').' diperbarui.';
    }

    /** Carries renumbered NPBG / RI numbers into the tracking copies (see DocumentRenumber). */
    protected function propagateRenumbers(SyncBatch $batch, string $entity): array
    {
        $renames = $this->renumbered[$entity] ?? [];
        $this->renumbered[$entity] = [];
        if ($renames === []) {
            return ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        }

        return app(DocumentRenumber::class)->apply($entity, $renames, fn (array $row) => $this->writeLog($row + ['sync_batch_id' => $batch->id]));
    }

    protected function writeLog(array $attrs): void
    {
        // Time-based heartbeat too: SKIP rows are no longer written, so a long
        // all-unchanged stretch must still prove the process is alive.
        if (microtime(true) - $this->lastHeartbeat >= 30) {
            $this->flushLogs();
        }

        if (($attrs['action'] ?? null) === 'SKIP' && ($attrs['status'] ?? null) === 'SUCCESS') {
            return;
        }

        $this->logBuffer[] = [
            'sync_batch_id' => $attrs['sync_batch_id'],
            'entity' => $attrs['entity'],
            'source_id' => $attrs['source_id'] ?? null,
            'action' => $attrs['action'],
            'status' => $attrs['status'],
            'message' => $attrs['message'] ?? null,
            'old_data' => isset($attrs['old_data']) ? json_encode($attrs['old_data']) : null,
            'new_data' => isset($attrs['new_data']) ? json_encode($attrs['new_data']) : null,
            'created_at' => now(),
        ];

        if (count($this->logBuffer) >= self::LOG_FLUSH_EVERY) {
            $this->flushLogs();
        }
    }

    protected function flushLogs(): void
    {
        if ($this->logBuffer !== []) {
            foreach (array_chunk($this->logBuffer, self::LOG_FLUSH_EVERY) as $chunk) {
                DB::table('sync_logs')->insert($chunk);
            }
            $this->logBuffer = [];
        }

        if ($this->currentBatch) {
            SyncBatch::whereKey($this->currentBatch->id)->update(['updated_at' => now()]);
        }
        $this->lastHeartbeat = microtime(true);
    }

    /** Runs one sync step and always flushes its buffered logs, even if the step throws. */
    /**
     * Tracking records derived from the synced NPBG/RI (Lend/Borrow, Ban Luar)
     * are secondary: a failure there is logged as one error, never fails the sync.
     */
    protected function deriveSafely(SyncBatch $batch, string $entity, \Closure $derive): array
    {
        try {
            return $derive();
        } catch (Throwable $e) {
            report($e);
            $this->writeLog([
                'sync_batch_id' => $batch->id, 'entity' => $entity, 'source_id' => '-',
                'action' => 'ERROR', 'status' => 'FAILED', 'message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1];
        }
    }

    protected function runStep(SyncBatch $batch, \Closure $step): array
    {
        try {
            return $step();
        } finally {
            $this->flushLogs();
        }
    }

    /** Mark any RUNNING batch that's been open too long as FAILED — a real sync lock only makes sense if a crashed/killed process can't hold it forever. */
    public function reapStaleRunningBatches(): void
    {
        SyncBatch::where('source', 'accurate')
            ->where('status', 'RUNNING')
            // No heartbeat (updated_at is bumped on every log flush, i.e. at
            // least every LOG_FLUSH_EVERY rows and after every step) for this
            // long = the process is gone. A healthy sync can run far longer
            // than this in total — only silence counts, not age.
            ->where('updated_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES))
            ->get()
            ->each(fn (SyncBatch $b) => $b->update([
                'status' => 'FAILED',
                'finished_at' => now(),
                'current_step' => null,
                'error_message' => 'Interrupted — no progress recorded for '.self::STALE_RUNNING_MINUTES.' minutes (process likely crashed or was killed).',
            ]));
    }

    protected function nextSyncCode(): string
    {
        $today = now()->format('Ymd');
        $countToday = SyncBatch::whereDate('created_at', now()->toDateString())->count();

        return sprintf('SYNC-%s-%03d', $today, $countToday + 1);
    }

    /**
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncItems(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_item')) {
            return $counts;
        }

        $unitsByCode = Unit::pluck('id', 'code')
            ->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));

        // Preload existing items keyed by code once — avoids an N+1 query per
        // Accurate row (this loop runs over ~9,600 rows).
        $itemsByCode = Item::select(
            'id', 'code', 'description', 'accurate_qty_onhand', 'accurate_qty_onorder', 'accurate_synced_at',
            'accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3', 'accurate_category_induk',
            'product_kind'
        )
            ->get()
            ->keyBy('code');

        // Items with NO inventory row at all (never touched by any Stockwise
        // operation — request/reserve/pickup/opname) have stock_known=false
        // and are otherwise invisible to StockwiseEngine (treated as 0,
        // producing false TIDAK_AMAN alerts even when Accurate shows real
        // stock — per explicit user instruction, don't wait for a physical
        // opname to resolve this; seed one opening balance from Accurate's
        // own qty instead, exactly like the Excel importer already does for
        // items with a known "SISA STOK". Checked/backfilled on every sync,
        // not just first sight, so already-synced items self-heal too.
        $itemIdsWithInventory = Inventory::distinct()->pluck('item_id')->flip();
        // Opt-in (config/stockwise.php sync_seed_opening_balance): with a
        // warehouse now always present (resolveAccurateWarehouses()), seeding
        // would suddenly create opening balances for every item without an
        // inventory row — a mass change to STOCKWISE stock figures that must
        // be switched on deliberately, not as a side effect of a bug fix.
        $defaultWarehouseId = config('stockwise.sync_seed_opening_balance')
            ? Warehouse::query()->orderBy('id')->value('id')
            : null;

        $rows = DB::table('accurate_item')
            ->select(array_merge(['ITEMNO', 'ITEMDESCRIPTION', 'UNIT1', 'SUSPENDED', 'QUANTITY', 'ONORDER', 'PARENTITEM', 'WAREHOUSEID'],
                // Accurate ITEMTYPE 2 = Servis → Master Produk JASA (older staging without the column: all BARANG)
                DB::getSchemaBuilder()->hasColumn('accurate_item', 'ITEMTYPE') ? ['ITEMTYPE'] : []))
            ->orderBy('ITEMNO')
            ->get();

        // Item.WAREHOUSEID (Accurate's own default-warehouse assignment) was
        // never read here, so "Letak Gudang" stayed empty for every item even
        // though Accurate has it set on ~9,600 of them — resolved the same way
        // stock opname already resolves its own warehouse (resolveAccurateWarehouses()).
        $defaultSiteId = Site::query()->orderBy('id')->value('id');
        $warehouseIdsByAccurateId = $this->resolveAccurateWarehouses($defaultSiteId);

        // Every row (product AND category node) keyed by ITEMNO — needed to
        // walk PARENTITEM chains upward and read each ancestor's own
        // ITEMDESCRIPTION. Built once, reused for all ~9,600 rows below.
        $nodesByCode = $rows->keyBy('ITEMNO');
        $this->parentCodes = $rows->pluck('PARENTITEM')->filter()->map(fn ($p) => trim((string) $p))->flip()->all();

        // Stock = what Accurate itself shows: its GETITEMQUANTITY / GETITEMBAL over
        // ITEMBALANCE + ITEMHIST (AccurateItemQuantity). ITEM.QUANTITY is not kept
        // up to date by Accurate (1,826 of 9,222 products differed on 2026-10-04) —
        // only used when the staging has no ITEMBALANCE / ITEMHIST at all.
        $this->accurateQty = AccurateItemQuantity::available() ? app(AccurateItemQuantity::class)->all() : null;
        // qty on order likewise from Accurate's own GETITEMONORDER, not ITEM.ONORDER
        $this->accurateOnOrder = app(AccurateItemQuantity::class)->onOrder();

        foreach ($rows as $row) {
            $counts['total']++;

            try {
                $outcome = $this->syncOneItem($row, $unitsByCode, $itemsByCode, $nodesByCode, $itemIdsWithInventory, $defaultWarehouseId, $warehouseIdsByAccurateId);
                $counts[$outcome['bucket']]++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'item',
                    'source_id' => (string) ($row->ITEMNO ?? ''),
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'item',
                    'source_id' => (string) ($row->ITEMNO ?? ''),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOneItem(
        object $row,
        \Illuminate\Support\Collection $unitsByCode,
        \Illuminate\Support\Collection $itemsByCode,
        \Illuminate\Support\Collection $nodesByCode,
        \Illuminate\Support\Collection $itemIdsWithInventory,
        ?int $defaultWarehouseId,
        \Illuminate\Support\Collection $warehouseIdsByAccurateId
    ): array {
        $itemno = trim((string) ($row->ITEMNO ?? ''));
        if ($itemno === '') {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'accurate_id (ITEMNO) kosong.'];
        }

        // Category/hierarchy nodes (AUT, AUT.01, AUT.01.01 — or any row with children) are
        // never products; every other row is one (isProductRow()).
        if (! self::isProductRow($itemno, isset($this->parentCodes[$itemno]))) {
            return [
                'bucket' => 'skipped',
                'action' => 'SKIP',
                'message' => 'Bukan kode produk (node kategori/hierarki ITEMNO), digunakan hanya untuk resolusi kategori barang lain.',
            ];
        }

        $existing = $itemsByCode->get($itemno);
        $newQty = $this->accurateQty !== null
            ? (float) ($this->accurateQty[$itemno] ?? 0.0) // no balance and no history in Accurate = 0
            : (float) ($row->QUANTITY ?? 0);
        $newOnOrder = $this->accurateOnOrder !== null
            ? (float) ($this->accurateOnOrder[$itemno] ?? 0.0)
            : (float) ($row->ONORDER ?? 0);
        $category = $this->resolveCategoryHierarchy($row->PARENTITEM ?? null, $nodesByCode);
        $newDescription = trim((string) ($row->ITEMDESCRIPTION ?? ''));
        $newInduk = $this->deriveKategoriInduk($itemno);
        // Same two fields already set at INSERT time below — kept in sync for
        // existing items too now (previously only description/qty/category
        // were re-synced; unit and active status could silently drift from
        // Accurate after the first sync). null unit1/unitId = don't touch
        // unit_id (never regress a mapped unit to unknown over a blank row).
        $unit1 = trim((string) ($row->UNIT1 ?? ''));
        $newUnitId = $unit1 !== '' ? $unitsByCode->get(mb_strtoupper($unit1)) : null;
        $newIsActive = ! (bool) ($row->SUSPENDED ?? false);
        $newWarehouseId = $row->WAREHOUSEID !== null ? $warehouseIdsByAccurateId->get((string) $row->WAREHOUSEID) : null;
        // a service in Accurate is a JASA here; otherwise the kind is left as it is (a JASA set by hand stays)
        $newKind = (int) ($row->ITEMTYPE ?? 0) === 2 ? 'JASA' : null;

        if ($existing) {
            // description is included here (not just qty/category) so that a
            // corrected Firebird decode — e.g. the Ø/etc. that previously came
            // through as U+FFFD before the Windows-1252 charset fix — actually
            // reaches already-existing items on re-sync, not just brand-new ones.
            $unchanged = (float) $existing->accurate_qty_onhand === $newQty
                && (float) $existing->accurate_qty_onorder === $newOnOrder
                && $existing->accurate_category_anak_1 === $category['anak_1']
                && $existing->accurate_category_anak_2 === $category['anak_2']
                && $existing->accurate_category_anak_3 === $category['anak_3']
                && $existing->accurate_category_induk === $newInduk
                && ($newDescription === '' || $existing->description === $newDescription)
                && ($newUnitId === null || (int) $existing->unit_id === (int) $newUnitId)
                && (bool) $existing->is_active === $newIsActive
                && ($newWarehouseId === null || (int) $existing->default_warehouse_id === (int) $newWarehouseId)
                && ($newKind === null || $existing->product_kind === $newKind);

            if ($unchanged && $existing->accurate_synced_at !== null) {
                $this->seedOpeningBalanceIfMissing($existing->id, $newQty, $itemIdsWithInventory, $defaultWarehouseId);

                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $old = [
                'accurate_qty_onhand' => $existing->accurate_qty_onhand,
                'accurate_qty_onorder' => $existing->accurate_qty_onorder,
                'accurate_category_anak_1' => $existing->accurate_category_anak_1,
                'accurate_category_anak_2' => $existing->accurate_category_anak_2,
                'accurate_category_anak_3' => $existing->accurate_category_anak_3,
                'accurate_category_induk' => $existing->accurate_category_induk,
                'description' => $existing->description,
                'unit_id' => $existing->unit_id,
                'is_active' => $existing->is_active,
            ];

            $existing->forceFill([
                'accurate_synced_at' => now(),
                'accurate_qty_onhand' => $newQty,
                'accurate_qty_onorder' => $newOnOrder,
                'accurate_category_anak_1' => $category['anak_1'],
                'accurate_category_anak_2' => $category['anak_2'],
                'accurate_category_anak_3' => $category['anak_3'],
                'accurate_category_induk' => $newInduk,
                'is_active' => $newIsActive,
            ]);
            if ($newDescription !== '') {
                $existing->forceFill(['description' => $newDescription]);
            }
            if ($newKind !== null) {
                $existing->forceFill(['product_kind' => $newKind]);
            }
            if ($newUnitId !== null) {
                $existing->forceFill(['unit_id' => $newUnitId]);
            }
            if ($newWarehouseId !== null) {
                $existing->forceFill(['default_warehouse_id' => $newWarehouseId]);
            }
            $existing->save();

            $this->seedOpeningBalanceIfMissing($existing->id, $newQty, $itemIdsWithInventory, $defaultWarehouseId);

            return [
                'bucket' => 'updated',
                'action' => 'UPDATE',
                'message' => "Data referensi Accurate diperbarui: qty {$old['accurate_qty_onhand']} -> {$newQty}.",
                'old_data' => $old,
                'new_data' => [
                    'accurate_qty_onhand' => $newQty, 'accurate_qty_onorder' => $newOnOrder,
                    'accurate_category_induk' => $newInduk,
                    'description' => $newDescription !== '' ? $newDescription : $old['description'],
                    'unit_id' => $newUnitId ?? $old['unit_id'],
                    'is_active' => $newIsActive,
                ] + $category,
            ];
        }

        // No match: a new product — one without a unit in Accurate comes in too (its unit empty), it
        // must not stay out of the Master. Only a row without a name is left out (not a real item).
        $description = trim((string) ($row->ITEMDESCRIPTION ?? ''));
        if ($description === '') {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'nama_barang kosong.'];
        }

        $created = Item::create([
            'code' => $itemno,
            'description' => $description,
            'unit_id' => $newUnitId,
            'item_type' => 'CONSUMABLE',
            'product_kind' => $newKind ?? 'BARANG',
            'needs_blueprint' => false,
            'source' => 'accurate',
            'is_active' => $newIsActive,
            'accurate_synced_at' => now(),
            'accurate_qty_onhand' => $newQty,
            'accurate_qty_onorder' => $newOnOrder,
            'accurate_category_anak_1' => $category['anak_1'],
            'accurate_category_anak_2' => $category['anak_2'],
            'accurate_category_anak_3' => $category['anak_3'],
            'accurate_category_induk' => $newInduk,
            'default_warehouse_id' => $newWarehouseId,
        ]);

        $this->seedOpeningBalanceIfMissing($created->id, $newQty, $itemIdsWithInventory, $defaultWarehouseId);

        return [
            'bucket' => 'inserted',
            'action' => 'INSERT',
            'message' => 'Barang baru dari Accurate.',
            'new_data' => ['code' => $created->code, 'description' => $created->description, 'accurate_category_induk' => $newInduk] + $category,
        ];
    }

    /**
     * Seeds one OPENING_BALANCE movement from Accurate's own qty for an item
     * that has never had ANY inventory row (any warehouse) — i.e. never
     * touched by a Stockwise operation. Per explicit user instruction, this
     * no longer waits for a physical Stock Opname: StockwiseEngine treats
     * `stock_known = false` as unknown-not-zero, which was producing false
     * TIDAK_AMAN alerts for items Accurate shows real stock for.
     *
     * Deliberately a ONE-TIME seed, not a running sync of Accurate's qty:
     * once this item has any inventory row (`stock_known` becomes true via
     * StockLedgerService), later Accurate quantity changes stay
     * reference-only on `items.accurate_qty_onhand` — same rule as every
     * other Accurate sync in this class — so a request/reserve/pickup/opname
     * that happens afterward is never silently overwritten by a later sync.
     *
     * No per-warehouse attribution exists in Accurate's data (see
     * docs/sync-architecture.md "Why the stock number is a reference
     * column") — uses the same lowest-id default warehouse already used for
     * Accurate-derived stock opname/PO rows elsewhere in this class.
     */
    protected function seedOpeningBalanceIfMissing(
        int $itemId,
        float $qty,
        \Illuminate\Support\Collection $itemIdsWithInventory,
        ?int $defaultWarehouseId
    ): void {
        if ($defaultWarehouseId === null || $itemIdsWithInventory->has($itemId)) {
            return;
        }

        $this->ledger->openingBalance($itemId, $defaultWarehouseId, max($qty, 0));
        $itemIdsWithInventory->put($itemId, true);
    }

    /**
     * Kategori Induk — see KATEGORI_INDUK_MAP. Unrecognized prefixes (a new
     * category the user hasn't told us about yet) fall through to null
     * rather than guessing, same policy as every other derived field here.
     */
    protected function deriveKategoriInduk(string $itemno): ?string
    {
        $prefix = mb_strtoupper(substr($itemno, 0, 3));

        return self::KATEGORI_INDUK_MAP[$prefix] ?? null;
    }

    /**
     * NPBG/PPB/RI must end up an exact mirror of Accurate — per explicit user
     * instruction, a row that no longer appears in Accurate's current data
     * (deleted there) is hard-deleted here too, not just flagged. Safe to do:
     * traced every FK across the schema that once pointed at npbg/ppb — the
     * tracking tables (lend/borrow/stpp/tyre/maintenance/manufacturing/used-
     * return) were built against the OLD internal npbg/ppb tables, and
     * migrations 2026_09_15_090001/2026_09_16_100001 renamed those away to
     * goods_issues/purchase_proposals before these Accurate-mirror tables
     * were (re)created under the freed names — MySQL's RENAME TABLE moved
     * those FKs along with the rename, so they point at the renamed tables,
     * never at npbg/ppb/ri. The only real dependent is npbg_verifications
     * (cascadeOnDelete), which correctly disappears with its source document.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Model>  $existingByKey  every Stockwise row before this sync, keyed the same way as $seenKeys
     * @param  array<int, string>  $seenKeys  keys still present in Accurate's current data
     * @param  \Closure(\Illuminate\Database\Eloquent\Model): string  $describe  short human label for the log message, e.g. fn ($n) => "NPBG {$n->no_npbg} baris ini"
     * @return array{deleted:int,errors:int}
     */
    protected function deleteOrphans(\Illuminate\Support\Collection $existingByKey, array $seenKeys, SyncBatch $batch, string $entity, \Closure $describe): array
    {
        // A staging refresh that silently comes back empty (Python step "ran"
        // but wrote 0 rows — network blip, partial Firebird read, etc.) must
        // never be read as "Accurate deleted everything" — that would wipe the
        // whole table. Only trust the fetch enough to delete when it actually
        // returned SOME current rows; a suspicious all-empty fetch is refused
        // and flagged instead, leaving existing data untouched.
        if ($seenKeys === [] && $existingByKey->isNotEmpty()) {
            $this->writeLog([
                'sync_batch_id' => $batch->id,
                'entity' => $entity,
                'source_id' => '-',
                'action' => 'ERROR',
                'status' => 'FAILED',
                'message' => "Accurate tidak mengembalikan data {$entity} sama sekali padahal Stockwise sudah punya {$existingByKey->count()} baris — kemungkinan staging refresh gagal sebagian, bukan penghapusan massal di Accurate. Deteksi hapus dilewati demi keamanan data; periksa sync-service.",
            ]);

            return ['deleted' => 0, 'errors' => 1];
        }

        // $existingByKey is really an Eloquent\Collection (keyBy()'d by an
        // Accurate composite key, e.g. "REQID-SEQ") — its except()/only() are
        // overridden to filter by the model's PRIMARY KEY, not the collection
        // key, so calling except() directly here would silently match nothing
        // and treat every row as an orphan. Re-wrapping as a plain Collection
        // restores the normal by-array-key semantics we actually want.
        $orphans = collect($existingByKey->all())->except($seenKeys);

        foreach ($orphans as $key => $model) {
            $this->writeLog([
                'sync_batch_id' => $batch->id,
                'entity' => $entity,
                'source_id' => (string) $key,
                'action' => 'DELETE',
                'status' => 'SUCCESS',
                'message' => $describe($model).' sudah tidak ada di Accurate — dihapus dari Stockwise.',
                'old_data' => $model->toArray(),
            ]);
            $model->delete();
        }

        return ['deleted' => $orphans->count(), 'errors' => 0];
    }

    /**
     * NPBG = one row per ARINVDET line, joined back to its ARINV header.
     * Identity is (ARINVOICEID, SEQ) — verified as ARINVDET's real primary key
     * against the live schema (docs/GDB_ANALYSIS.md), not guessed; INVOICENO
     * alone is not unique since one invoice can carry many detail lines.
     *
     * Only Accurate-owned columns are ever written here (no_npbg, tgl_npbg,
     * shipdate, taxdate, divisi, pelanggan, keterangan, deskripsi_barang,
     * kuantitas, satuan, peminta, lokasi, and since 2026-10-05 klasifikasi
     * [Ship Via] and no_seri [SNLine], since 2026-10-21 tipe_npbg [Terms]) — the
     * Stockwise-owned enrichment columns (deskripsi, nama_proyek, dikeluarkan_oleh) are never touched
     * by sync, so a user's prior edits survive a re-sync (brief §11).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncNpbg(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_arinvdet') || ! DB::getSchemaBuilder()->hasTable('accurate_arinv')) {
            return $counts;
        }

        $existing = Npbg::query()
            ->select('id', 'accurate_arinvoice_id', 'accurate_seq', 'no_npbg', 'tgl_npbg', 'shipdate', 'taxdate',
                'divisi', 'pelanggan', 'keterangan', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'unit_ratio', 'peminta',
                'klasifikasi', 'no_seri', 'tipe_npbg', 'lokasi_gudang', 'lokasi_rak', 'accurate_synced_at')
            // only Accurate's own rows: a manual NPBG (made in Stockwise, no Accurate id) is never "gone from Accurate"
            ->whereNotNull('accurate_arinvoice_id')
            ->get()
            ->keyBy(fn ($n) => $n->accurate_arinvoice_id.'-'.$n->accurate_seq);

        // UNITRATIO / SHIPTO2 are optional so an older staging schema still syncs.
        $schema = DB::getSchemaBuilder();
        $optional = array_merge(
            $schema->hasColumn('accurate_arinvdet', 'UNITRATIO') ? ['d.UNITRATIO'] : [],
            $schema->hasColumn('accurate_arinv', 'SHIPTO2') ? ['h.SHIPTO2'] : [],
            $schema->hasColumn('accurate_arinv', 'SHIPVIA') ? ['h.SHIPVIA'] : [],
            $schema->hasColumn('accurate_arinv', 'TERMSID') ? ['h.TERMSID'] : [],
            $schema->hasColumn('accurate_arinvdet', 'DEPTID') ? ['d.DEPTID'] : [],
            $schema->hasColumn('accurate_arinvdet', 'ITEMRESERVED2') ? ['d.ITEMRESERVED2'] : [],
            $schema->hasColumn('accurate_arinvdet', 'ITEMHISTID') ? ['d.ITEMHISTID'] : [],
        );

        $rows = DB::table('accurate_arinvdet as d')
            ->join('accurate_arinv as h', 'd.ARINVOICEID', '=', 'h.ARINVOICEID')
            ->select(array_merge([
                'd.ARINVOICEID as ARINVOICEID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT', 'd.ITEMRESERVED1',
                'h.INVOICENO', 'h.INVOICEDATE', 'h.SHIPDATE', 'h.TAXDATE', 'h.PURCHASEORDERNO', 'h.SHIPTO1', 'h.DESCRIPTION',
            ], $optional))
            ->orderBy('d.ARINVOICEID')->orderBy('d.SEQ')
            ->get();
        // requester / location / jenis pengeluaran: old vs new Accurate input format (AccurateLineFields)
        $this->lineFields = app(AccurateLineFields::class);
        $this->lineFields->prepare($rows, 'ARINVOICEID');

        $seenKeys = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $seenKeys[] = $row->ARINVOICEID.'-'.$row->SEQ;

            try {
                $outcome = $this->syncOneNpbgLine($row, $existing);
                $counts[$outcome['bucket']]++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'npbg',
                    'source_id' => $row->ARINVOICEID.'-'.$row->SEQ,
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'npbg',
                    'source_id' => ($row->ARINVOICEID ?? '?').'-'.($row->SEQ ?? '?'),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $orphanResult = $this->deleteOrphans($existing, $seenKeys, $batch, 'npbg', fn ($n) => "NPBG {$n->no_npbg} baris ini");
        $counts['deleted'] = $orphanResult['deleted'];
        $counts['errors'] += $orphanResult['errors'];

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOneNpbgLine(object $row, \Illuminate\Support\Collection $existingByKey): array
    {
        if ($row->ARINVOICEID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'ARINVOICEID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->ARINVOICEID.'-'.$row->SEQ;

        $new = [
            'no_npbg' => $row->INVOICENO,
            'tgl_npbg' => $row->INVOICEDATE,
            'shipdate' => $row->SHIPDATE,
            'taxdate' => $row->TAXDATE,
            'divisi' => $row->PURCHASEORDERNO,
            // Accurate wraps a long "ship to" over SHIPTO1 + SHIPTO2
            // (e.g. "W 8348 QG (MITSUBISHI" + "RAGASA)") — keep it whole.
            'pelanggan' => mb_substr(trim(implode(' ', array_filter([
                trim((string) $row->SHIPTO1), trim((string) ($row->SHIPTO2 ?? '')),
            ], fn ($v) => $v !== ''))), 0, 200) ?: null,
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            // base units per transaction unit — Accurate moves stock by QUANTITY × UNITRATIO
            'unit_ratio' => ($ratio = (float) ($row->UNITRATIO ?? 0)) > 0 ? $ratio : 1.0,
            'peminta' => $this->lineFields->person($row, 'ARINVOICEID', 'ITEMRESERVED1'),
            'lokasi_gudang' => ($loc = $this->lineFields->location($row, 'ARINVOICEID', false))[0],
            'lokasi_rak' => $loc[1],
        ]
            // template "NPBG [A5]": Klasifikasi [Ship Via], No. Seri [SNLine] — only when their source was staged
            + ($this->lineFields->hasShipments() ? ['klasifikasi' => $this->lineFields->shipment($row->SHIPVIA ?? null)] : [])
            + ($this->lineFields->hasSerials() ? ['no_seri' => $this->lineFields->serial($row->ITEMHISTID ?? null)] : [])
            // Tipe NPBG [Terms]: Penjualan / Non Penjualan
            + ($this->lineFields->hasTerms() && property_exists($row, 'TERMSID') ? ['tipe_npbg' => $this->lineFields->npbgType($row->TERMSID)] : []);

        $existing = $existingByKey->get($key);

        if ($existing) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $existing->{$f} instanceof \Carbon\Carbon ? $existing->{$f}->toDateString() : $existing->{$f}])->all();
            $unchanged = \App\Support\SyncDiff::unchanged($new, $existing);

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            Npbg::where('id', $existing->id)->update($new + ['accurate_synced_at' => now()]);
            if (! \App\Support\SyncDiff::same($old['no_npbg'], $new['no_npbg'])) {
                // the tracking copies of the number follow after this step (DocumentRenumber)
                $this->renumbered['npbg'][(string) $old['no_npbg']]['new'] = (string) $new['no_npbg'];
                $this->renumbered['npbg'][(string) $old['no_npbg']]['ids'][] = $existing->id;
            }

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => $this->docMessage('NPBG', $old['no_npbg'], $row->INVOICENO, $row->SEQ),
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        Npbg::create($new + [
            'accurate_arinvoice_id' => $row->ARINVOICEID,
            'accurate_seq' => $row->SEQ,
            'accurate_synced_at' => now(),
        ]);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "NPBG baru dari invoice {$row->INVOICENO} baris {$row->SEQ}.",
            'new_data' => $new,
        ];
    }

    /**
     * STPP (alat ber-serial diserahkan ke divisi/holder, docs/status-flow.md
     * §8) had zero rows despite the workflow being real — its "issue" form
     * requires manually picking an out_npbg, and nothing ever surfaced which
     * NPBG lines were STPP placements, so nobody ever did. Per explicit user
     * instruction, they're identified by NPBG's own keterangan mentioning
     * "STPP" (189 real lines already do, e.g. "U/ DIJADIKAN STPP GUDANG..."),
     * so every synced NPBG line matching that is turned into an StppTransaction
     * automatically — one NPBG line, one STPP row, keyed by out_npbg_id so a
     * repeat sync never creates a duplicate. Runs after syncNpbg() (reads its
     * output), not against Accurate staging tables directly. Serial number,
     * holder_id and placement_department_id aren't in NPBG's data at all —
     * left null for a person to fill in, same as every other "don't guess
     * what isn't there" derived field this sync produces.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function deriveStppFromNpbg(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $unitsByCode = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));
        $itemIdsByCode = Item::pluck('id', 'code');
        $alreadyDerived = StppTransaction::whereNotNull('out_npbg_id')->pluck('out_npbg_id')->flip();

        // an NPBG the Excel tracking (or a person) already records unit by unit is theirs — not derived again
        $recorded = StppTransaction::where('source', 'EXCEL')->whereNotNull('out_npbg_no')->pluck('out_npbg_no')->flip();
        $rows = Npbg::where('keterangan', 'like', '%STPP%')->orderBy('id')->get()
            ->reject(fn (Npbg $n) => isset($recorded[$n->no_npbg]));

        foreach ($rows as $npbg) {
            $counts['total']++;

            try {
                if (isset($alreadyDerived[$npbg->id])) {
                    $counts['skipped']++;
                    $this->writeLog([
                        'sync_batch_id' => $batch->id, 'entity' => 'stpp', 'source_id' => (string) $npbg->id,
                        'action' => 'SKIP', 'status' => 'SUCCESS',
                        'message' => 'STPP sudah pernah dibuat dari baris NPBG ini.',
                    ]);

                    continue;
                }

                $unit1 = $npbg->satuan ? mb_strtoupper(trim($npbg->satuan)) : null;
                $stpp = StppTransaction::create([
                    'number' => $this->numbers->next('STPP', 'SDA', $npbg->tgl_npbg ?? now()),
                    'sheet_no' => 1 + (int) StppTransaction::max('sheet_no'), // next row of the STPP sheet
                    'item_id' => $npbg->kode_barang ? $itemIdsByCode->get($npbg->kode_barang) : null,
                    'description_raw' => $npbg->deskripsi_barang ?: '-',
                    'qty' => $npbg->kuantitas ?: 1,
                    'unit_id' => $unit1 ? $unitsByCode->get($unit1) : null,
                    'holder_name_raw' => $npbg->peminta,
                    'out_npbg_id' => $npbg->id,
                    'out_npbg_no' => $npbg->no_npbg,
                    'unit_raw' => $npbg->satuan,
                    'out_date' => $npbg->tgl_npbg ?? now(),
                    'status' => 'ACTIVE',
                    'source' => 'ACCURATE',
                    'out_note' => $npbg->keterangan,
                ]);

                $counts['inserted']++;
                $this->writeLog([
                    'sync_batch_id' => $batch->id, 'entity' => 'stpp', 'source_id' => (string) $npbg->id,
                    'action' => 'INSERT', 'status' => 'SUCCESS',
                    'message' => "STPP {$stpp->number} dibuat dari NPBG {$npbg->no_npbg} (keterangan menyebut STPP).",
                    'new_data' => [
                        'number' => $stpp->number, 'kode_barang' => $npbg->kode_barang,
                        'deskripsi_barang' => $stpp->description_raw, 'peminta' => $stpp->holder_name_raw,
                        'keterangan' => $stpp->out_note,
                    ],
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;
                $this->writeLog([
                    'sync_batch_id' => $batch->id, 'entity' => 'stpp', 'source_id' => (string) $npbg->id,
                    'action' => 'ERROR', 'status' => 'FAILED', 'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * PPB = one row per REQUISITIONDET line, joined back to its REQUISITION
     * header. Identity is (REQID, SEQ) — verified as REQUISITIONDET's real
     * primary key against the live schema (docs/GDB_ANALYSIS.md), not guessed;
     * REQNO alone is not unique since one requisition can carry many lines.
     *
     * Read-only mirror — unlike Npbg there are no Stockwise-owned columns to
     * protect here (no manual enrichment fields exist for PPB).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncPpb(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_requisitiondet') || ! DB::getSchemaBuilder()->hasTable('accurate_requisition')) {
            return $counts;
        }

        $existing = Ppb::query()
            ->select('id', 'accurate_reqid', 'accurate_seq', 'no_ppb', 'tgl_ppb', 'status', 'divisi', 'keterangan',
                'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'qty_dipesan', 'qty_diterima', 'peminta',
                'catatan_baris', 'line_status', 'is_amend', 'amend_source', 'accurate_synced_at')
            // only Accurate's own rows: a manual PPB (Tambah PPB / Request Barang, no Accurate id) is never "gone from Accurate"
            ->whereNotNull('accurate_reqid')
            ->get()
            ->keyBy(fn ($p) => $p->accurate_reqid.'-'.$p->accurate_seq);

        // Divisi = the Accurate Department on the line (REQUISITIONDET.DEPTID ->
        // DEPARTMENT.DEPTNAME), used by newer PPBs. The number segment
        // ("PPB/NA/..", "PPB/ATK/..") is only a numbering code, kept as the
        // fallback for older PPBs without a department. Both the DEPTID column
        // and the mirrored accurate_department table are optional here.
        $schema = DB::getSchemaBuilder();
        $hasDeptId = $schema->hasColumn('accurate_requisitiondet', 'DEPTID');
        $this->ppbDepartments = $hasDeptId && $schema->hasTable('accurate_department')
            ? DB::table('accurate_department')->get()
                ->mapWithKeys(fn ($d) => [(int) $d->DEPTID => trim((string) (($d->DEPTNAME ?? '') !== '' ? $d->DEPTNAME : ($d->DEPTNO ?? '')))])
                ->filter()
                ->all()
            : [];
        // Since late Sept 2026 Accurate users leave ITEMRESERVED3 empty and pick the
        // requester as the line's Department instead: a sub-department's DEPTNO is the
        // person (DEPTID 17 = "JORDY"), same convention as NPBG (AccurateLineFields).
        $this->ppbPeople = [];
        if ($hasDeptId && $schema->hasTable('accurate_department')) {
            $hasSub = $schema->hasColumn('accurate_department', 'ISSUBDEPT');
            foreach (DB::table('accurate_department')->get() as $d) {
                $no = trim((string) ($d->DEPTNO ?? ''));
                $isSub = $hasSub ? (int) ($d->ISSUBDEPT ?? 0) === 1 : ($no !== '' && ! ctype_digit($no));
                if ($isSub && $no !== '') {
                    $this->ppbPeople[(int) $d->DEPTID] = $no;
                }
            }
        }

        $rows = DB::table('accurate_requisitiondet as d')
            ->join('accurate_requisition as h', 'd.REQID', '=', 'h.REQID')
            ->select(array_merge([
                'd.REQID as REQID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT',
                'd.QTYORDERED', 'd.QTYRECEIVED', 'd.ITEMRESERVED3', 'd.NOTES',
                'h.REQNO', 'h.REQDATE', 'h.ISCLOSED', 'h.DESCRIPTION',
            ], $hasDeptId ? ['d.DEPTID'] : []))
            ->orderBy('d.REQID')->orderBy('d.SEQ')
            ->get();

        // Accurate users fill in the requester (ITEMRESERVED3) and department on
        // the first line only — carry them over to every line of the same PPB.
        $firstFilled = fn ($lines, string $field) => $lines->map(fn ($l) => trim((string) ($l->{$field} ?? '')))->first(fn ($v) => $v !== '');
        $byReq = $rows->groupBy('REQID');
        $this->ppbPemintaByReq = $byReq->map(fn ($lines) => $firstFilled($lines, 'ITEMRESERVED3'))->all();
        $this->ppbDeptByReq = $hasDeptId ? $byReq->map(fn ($lines) => $firstFilled($lines, 'DEPTID'))->all() : [];

        // PPB lines with a PO still open (from the POs synced so far; refreshed again after the PO step)
        $openIds = \App\Support\PpbLineStatus::openPoPpbIds();
        $this->ppbOpenPo = $existing->filter(fn ($p) => isset($openIds[$p->id]))->map(fn () => true)->all();

        $seenKeys = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $seenKeys[] = $row->REQID.'-'.$row->SEQ;

            try {
                $outcome = $this->syncOnePpbLine($row, $existing);
                $counts[$outcome['bucket']]++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'ppb',
                    'source_id' => $row->REQID.'-'.$row->SEQ,
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'ppb',
                    'source_id' => ($row->REQID ?? '?').'-'.($row->SEQ ?? '?'),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $orphanResult = $this->deleteOrphans($existing, $seenKeys, $batch, 'ppb', fn ($p) => "PPB {$p->no_ppb} baris ini");
        $counts['deleted'] = $orphanResult['deleted'];
        $counts['errors'] += $orphanResult['errors'];

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOnePpbLine(object $row, \Illuminate\Support\Collection $existingByKey): array
    {
        if ($row->REQID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'REQID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->REQID.'-'.$row->SEQ;

        $new = [
            'no_ppb' => $row->REQNO,
            'tgl_ppb' => $row->REQDATE,
            'status' => $row->ISCLOSED === null ? null : ((int) $row->ISCLOSED === 1 ? 'CLOSED' : 'OPEN'),
            'divisi' => $this->ppbDivisi($row),
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            'qty_dipesan' => $row->QTYORDERED !== null ? (float) $row->QTYORDERED : null,
            'qty_diterima' => $row->QTYRECEIVED !== null ? (float) $row->QTYRECEIVED : null,
            'peminta' => $this->ppbPeminta($row),
            'catatan_baris' => $row->NOTES,
            // status per line as Accurate holds it + the Excel "Amend" flag (App\Support\PpbLineStatus)
            'line_status' => \App\Support\PpbLineStatus::of(
                $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
                $row->QTYRECEIVED !== null ? (float) $row->QTYRECEIVED : null,
                (int) $row->ISCLOSED === 1,
                isset($this->ppbOpenPo[$key]),
            ),
            'amend_source' => $amend = \App\Support\PpbLineStatus::amendSource(
                $row->QTYRECEIVED !== null ? (float) $row->QTYRECEIVED : null,
                (int) $row->ISCLOSED === 1,
                in_array(mb_strtoupper((string) $row->ITEMNO), \App\Support\PpbLineStatus::amendedCodes($row->DESCRIPTION), true),
                isset($this->ppbOpenPo[$key]),
            ),
            'is_amend' => $amend !== null,
        ];

        $existing = $existingByKey->get($key);

        if ($existing) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $existing->{$f} instanceof \Carbon\Carbon ? $existing->{$f}->toDateString() : $existing->{$f}])->all();
            $unchanged = \App\Support\SyncDiff::unchanged($new, $existing);

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            Ppb::where('id', $existing->id)->update($new + ['accurate_synced_at' => now()]);

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => $this->docMessage('PPB', $old['no_ppb'] ?? null, $row->REQNO, $row->SEQ),
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        Ppb::create($new + [
            'accurate_reqid' => $row->REQID,
            'accurate_seq' => $row->SEQ,
            'accurate_synced_at' => now(),
        ]);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "PPB baru dari requisition {$row->REQNO} baris {$row->SEQ}.",
            'new_data' => $new,
        ];
    }

    /**
     * PPB and RI numbers are both literally formatted "{DOC}/{divisi}/{yy}/{roman}/{seq}"
     * (e.g. PPB/ATK/25/IX/004, RI/NV/25/IX/001) — confirmed against live
     * accurate_requisition and accurate_apinv data. Neither REQUISITION nor
     * APINV has a dedicated divisi column (unlike ARINV.SHIPTO1 for NPBG), so
     * it is parsed from the document number instead.
     */
    /** @var array<int, string> DEPTID => department name (see syncPpb()) */
    protected array $ppbDepartments = [];

    /** @var array<int|string, ?string> REQID => requester from any line of that PPB */
    protected array $ppbPemintaByReq = [];
    /** @var array<string, true> "REQID-SEQ" of PPB lines with a PO still open */
    protected array $ppbOpenPo = [];

    /** @var array<int|string, ?string> REQID => DEPTID from any line of that PPB */
    protected array $ppbDeptByReq = [];

    /** @var array<int, string> DEPTID => person (sub-department DEPTNO) */
    protected array $ppbPeople = [];

    /**
     * PPB requester: the line's own ITEMRESERVED3 or Department person, else the
     * PPB's (typed on the first line only) — so every line of a PPB carries it.
     */
    protected function ppbPeminta(object $row): ?string
    {
        $own = trim((string) ($row->ITEMRESERVED3 ?? ''));
        $person = fn ($deptId) => $deptId ? ($this->ppbPeople[(int) $deptId] ?? null) : null;
        $name = ($own !== '' ? $own : null)
            ?? $person($row->DEPTID ?? null)
            ?? ($this->ppbPemintaByReq[$row->REQID] ?? null)
            ?? $person($this->ppbDeptByReq[$row->REQID] ?? null);

        return $name !== null ? mb_substr($name, 0, 150) : null;
    }

    /** PPB divisi: the line's (or its PPB's) Accurate department, else the number segment. */
    protected function ppbDivisi(object $row): ?string
    {
        $deptId = ($row->DEPTID ?? null) ?: ($this->ppbDeptByReq[$row->REQID] ?? null);
        if ($deptId && isset($this->ppbDepartments[(int) $deptId])) {
            return mb_substr($this->ppbDepartments[(int) $deptId], 0, 30); // ppb.divisi is varchar(30)
        }

        return $this->deriveDivisiFromDocNumber($row->REQNO);
    }

    /**
     * An RI's own number and its supplier's surat jalan, from APINV: usually the admin types the RI number
     * in Invoice No (Form No is then just "001"); when the supplier's surat jalan is typed there instead,
     * the RI number is in Form No (SEQUENCENO, e.g. RI/ATK/26/X/010 with SJ 00425/JL-UTM/1026). No surat
     * jalan when Invoice No is the RI number itself.
     *
     * @return array{0: ?string, 1: ?string} [no_ri, no_surat_jalan]
     */
    public static function riNumbers(?string $invoiceNo, ?string $formNo): array
    {
        $invoiceNo = $invoiceNo !== null ? trim($invoiceNo) : null;
        $formNo = $formNo !== null ? trim($formNo) : null;
        $noRi = $formNo && preg_match('#^RI/#i', $formNo) ? $formNo : $invoiceNo;

        return [$noRi, $invoiceNo !== null && $invoiceNo !== '' && $invoiceNo !== $noRi ? $invoiceNo : null];
    }

    protected function deriveDivisiFromDocNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $parts = explode('/', $number);

        return $parts[1] ?? null;
    }

    /**
     * RI = one row per APITMDET line, joined back to its APINV header and to
     * the vendor's name (accurate_persondata). Identity is (APINVOICEID, SEQ)
     * — verified as APITMDET's real primary key against the live schema
     * (docs/GDB_ANALYSIS.md); INVOICENO alone is not unique since one AP
     * invoice can carry many item lines. APITMDET even carries a
     * self-referencing RIID FK back to APINV, confirming APINV really is the
     * RI document (its INVOICENO is literally "RI/{divisi}/{yy}/{roman}/{seq}").
     *
     * Read-only mirror — separate from the app's own `receivings` /
     * `receiving_items` tables (the internal DRAFT->CHECKING->CONFIRMED
     * workflow tied to a PO and to stock movements).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncRi(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_apitmdet') || ! DB::getSchemaBuilder()->hasTable('accurate_apinv')) {
            return $counts;
        }

        $existing = Ri::query()
            ->select('id', 'accurate_apinvoice_id', 'accurate_seq', 'no_ri', 'tgl_ri', 'divisi', 'vendor', 'vendor_id', 'no_po',
                'shipdate', 'keterangan', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'harga_satuan',
                'pemeriksa', 'lokasi_gudang', 'lokasi_rak', 'accurate_po_item_id', 'accurate_synced_at',
                'no_surat_jalan', 'pengirim', 'fob', 'no_ppb', 'no_seri')
            // only Accurate's own rows: a manual RI (no Accurate id) is never "gone from Accurate"
            ->whereNotNull('accurate_apinvoice_id')
            ->get()
            ->keyBy(fn ($r) => $r->accurate_apinvoice_id.'-'.$r->accurate_seq);

        // Accurate's own APITMDET.POID/POSEQ chains an RI line back to the PO
        // line it received against — verified against real data (an APITMDET
        // row's POID/POSEQ resolves to a real PODET row, same ITEMNO).
        // Resolved once into our own purchase_order_items' ids; only ~46% of
        // RI lines have this (the rest are internal stock-take style
        // receipts with no PO), which is expected, not a bug.
        $poItemIdByPoKey = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNotNull('purchase_order_items.accurate_seq')
            ->whereNotNull('purchase_orders.accurate_po_id')
            ->get(['purchase_order_items.id', 'purchase_orders.accurate_po_id', 'purchase_order_items.accurate_seq'])
            ->keyBy(fn ($i) => $i->accurate_po_id.'-'.$i->accurate_seq)
            ->map(fn ($i) => $i->id);

        // No. PPB [Request No] = PODET(POID, POSEQ).REQID → REQUISITION.REQNO (Accurate's TEMPLATE_APITMDET)
        $schemaRi = DB::getSchemaBuilder();
        $this->riHasReqNo = $schemaRi->hasTable('accurate_podet') && $schemaRi->hasColumn('accurate_podet', 'REQID')
            && $schemaRi->hasTable('accurate_requisition');
        $rows = DB::table('accurate_apitmdet as d')
            ->join('accurate_apinv as h', 'd.APINVOICEID', '=', 'h.APINVOICEID')
            ->leftJoin('accurate_persondata as v', 'h.VENDORID', '=', 'v.ID')
            ->when($this->riHasReqNo, fn ($q) => $q
                ->leftJoin('accurate_podet as pd', fn ($j) => $j->on('pd.POID', '=', 'd.POID')->on('pd.SEQ', '=', 'd.POSEQ'))
                ->leftJoin('accurate_requisition as rq', 'rq.REQID', '=', 'pd.REQID')
                ->addSelect('rq.REQNO as REQNO'))
            ->addSelect(array_merge([
                'd.APINVOICEID as APINVOICEID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT',
                'd.UNITPRICE', 'd.ITEMRESERVED3', 'd.POID', 'd.POSEQ',
                'h.INVOICENO', 'h.INVOICEDATE', 'h.PURCHASEORDERNO', 'h.SHIPDATE', 'h.DESCRIPTION',
                'v.NAME as VENDORNAME',
            ], array_values(array_filter([
                // Form No: the RI number when Invoice No holds the supplier's surat jalan (riNumbers())
                DB::getSchemaBuilder()->hasColumn('accurate_apinv', 'SEQUENCENO') ? 'h.SEQUENCENO' : null,
                DB::getSchemaBuilder()->hasColumn('accurate_apitmdet', 'DEPTID') ? 'd.DEPTID' : null,
                DB::getSchemaBuilder()->hasColumn('accurate_apitmdet', 'ITEMRESERVED1') ? 'd.ITEMRESERVED1' : null,
                DB::getSchemaBuilder()->hasColumn('accurate_apitmdet', 'ITEMRESERVED2') ? 'd.ITEMRESERVED2' : null,
                DB::getSchemaBuilder()->hasColumn('accurate_apitmdet', 'ITEMHISTID') ? 'd.ITEMHISTID' : null,
                // RI "Pengirim [Ship Via]" — aliased: SHIPVIA marks the NEW NPBG format, not an RI's
                DB::getSchemaBuilder()->hasColumn('accurate_apinv', 'SHIPVIA') ? 'h.SHIPVIA as RI_SHIPVIA' : null,
                DB::getSchemaBuilder()->hasColumn('accurate_apinv', 'FOB') ? 'h.FOB as FOB' : null,
            ]))))
            ->orderBy('d.APINVOICEID')->orderBy('d.SEQ')
            ->get();
        // checker / location: old vs new Accurate input format (AccurateLineFields)
        $this->lineFields = app(AccurateLineFields::class);
        $this->lineFields->prepare($rows, 'APINVOICEID');

        $seenKeys = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $seenKeys[] = $row->APINVOICEID.'-'.$row->SEQ;

            try {
                $outcome = $this->syncOneRiLine($row, $existing, $poItemIdByPoKey);
                $counts[$outcome['bucket']]++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'ri',
                    'source_id' => $row->APINVOICEID.'-'.$row->SEQ,
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'ri',
                    'source_id' => ($row->APINVOICEID ?? '?').'-'.($row->SEQ ?? '?'),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $orphanResult = $this->deleteOrphans($existing, $seenKeys, $batch, 'ri', fn ($r) => "RI {$r->no_ri} baris ini");
        $counts['deleted'] = $orphanResult['deleted'];
        $counts['errors'] += $orphanResult['errors'];

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOneRiLine(object $row, \Illuminate\Support\Collection $existingByKey, \Illuminate\Support\Collection $poItemIdByPoKey): array
    {
        if ($row->APINVOICEID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'APINVOICEID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->APINVOICEID.'-'.$row->SEQ;

        // Same PERSONDATA source as PO's vendor — resolve to the same
        // `vendors` row (via firstOrCreate's unique(name), idempotent whether
        // PO or RI sync gets to a given vendor first) rather than leaving the
        // RI<->PO vendor relationship as two separately-typed strings.
        $vendor = $this->findOrCreateAccurateVendor($row->VENDORNAME);

        [$noRi, $noSj] = self::riNumbers($row->INVOICENO, $row->SEQUENCENO ?? null);
        $new = [
            'no_ri' => $noRi,
            'tgl_ri' => $row->INVOICEDATE,
            'divisi' => $this->deriveDivisiFromDocNumber($noRi),
            'vendor' => $row->VENDORNAME,
            'vendor_id' => $vendor->id,
            'no_po' => $row->PURCHASEORDERNO,
            'shipdate' => $row->SHIPDATE,
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            'harga_satuan' => $row->UNITPRICE !== null ? (float) $row->UNITPRICE : null,
            'pemeriksa' => $this->lineFields->person($row, 'APINVOICEID', 'ITEMRESERVED3'),
            'lokasi_gudang' => ($loc = $this->lineFields->location($row, 'APINVOICEID', true))[0],
            'lokasi_rak' => $loc[1],
            // template "RI [A5]": No. Surat Jalan [Invoice Number], FOB [FOB] — see riNumbers()
            'no_surat_jalan' => $noSj,
            'fob' => ($fob = trim((string) ($row->FOB ?? ''))) !== '' ? mb_substr($fob, 0, 30) : null,
            'accurate_po_item_id' => ($row->POID !== null && $row->POSEQ !== null)
                ? $poItemIdByPoKey->get($row->POID.'-'.$row->POSEQ)
                : null,
        ]
            // Pengirim [Ship Via], No. PPB [Request No], No. Seri [SNLine] — only when their source was staged
            + ($this->lineFields->hasShipments() ? ['pengirim' => $this->lineFields->shipment($row->RI_SHIPVIA ?? null)] : [])
            + ($this->riHasReqNo ? ['no_ppb' => ($r = trim((string) ($row->REQNO ?? ''))) !== '' ? $r : null] : [])
            + ($this->lineFields->hasSerials() ? ['no_seri' => $this->lineFields->serial($row->ITEMHISTID ?? null)] : []);

        $existing = $existingByKey->get($key);

        if ($existing) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $existing->{$f} instanceof \Carbon\Carbon ? $existing->{$f}->toDateString() : $existing->{$f}])->all();
            $unchanged = \App\Support\SyncDiff::unchanged($new, $existing);

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            Ri::where('id', $existing->id)->update($new + ['accurate_synced_at' => now()]);
            if (! \App\Support\SyncDiff::same($old['no_ri'], $new['no_ri'])) {
                // the tracking copies of the number follow after this step (DocumentRenumber)
                $this->renumbered['ri'][(string) $old['no_ri']]['new'] = (string) $new['no_ri'];
                $this->renumbered['ri'][(string) $old['no_ri']]['ids'][] = $existing->id;
            }

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => $this->docMessage('RI', $old['no_ri'], $noRi, $row->SEQ),
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        Ri::create($new + [
            'accurate_apinvoice_id' => $row->APINVOICEID,
            'accurate_seq' => $row->SEQ,
            'accurate_synced_at' => now(),
        ]);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "RI baru {$noRi} baris {$row->SEQ}.",
            'new_data' => $new,
        ];
    }

    /**
     * PO — unlike NPBG/PPB/RI, `purchase_orders`/`purchase_order_items` already
     * represent the real thing (an order sent to a vendor), just not yet
     * reconciled against Accurate's own PO/PODET. Per explicit user decision,
     * there is no separate mirror page here: real Accurate POs are synced
     * straight into these tables as additional rows (accurate_po_id/accurate_seq
     * set), sitting alongside whatever the internal DRAFT->APPROVED->SENT->
     * RECEIVED workflow already created (accurate_po_id null) — no attempt is
     * made to match/merge the two, since there is no reliable key linking an
     * internally-generated PO number to a real Accurate PONO.
     *
     * Counted per PO header (not per PODET line, unlike the other syncers)
     * since PO/PODET is a real relational header+lines document, matching the
     * shape purchase_orders/purchase_order_items already has.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncPo(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_po') || ! DB::getSchemaBuilder()->hasTable('accurate_podet')) {
            return $counts;
        }

        $unitsByCode = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));
        $itemIdsByCode = Item::pluck('id', 'code');
        $defaultSiteId = Site::query()->orderBy('id')->value('id');

        // Accurate's own PODET.REQID/REQSEQ chains a PO line back to the PPB
        // (REQUISITIONDET) line it was raised from — verified against real
        // data (a PODET row's REQID/REQSEQ resolves to a real REQUISITIONDET
        // row, same ITEMNO). Resolved once into our own `ppb` mirror's ids.
        $ppbIdByReqKey = Ppb::query()->select('id', 'accurate_reqid', 'accurate_seq')->get()
            ->keyBy(fn ($p) => $p->accurate_reqid.'-'.$p->accurate_seq)
            ->map(fn ($p) => $p->id);

        $headers = DB::table('accurate_po as h')
            ->leftJoin('accurate_persondata as v', 'h.VENDORID', '=', 'v.ID')
            ->select(
                'h.POID as POID', 'h.PONO', 'h.PODATE', 'h.EXPECTED', 'h.CLOSED', 'h.POAMOUNT',
                'h.TAX1AMOUNT', 'h.TAX2AMOUNT', 'h.DESCRIPTION', 'v.NAME as VENDORNAME'
            )
            ->orderBy('h.POID')
            ->get();

        foreach ($headers as $h) {
            $counts['total']++;

            try {
                $outcome = $this->syncOnePo($h, $unitsByCode, $itemIdsByCode, $defaultSiteId, $ppbIdByReqKey);
                $counts[$outcome['bucket']]++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'po',
                    'source_id' => (string) $h->POID,
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'po',
                    'source_id' => (string) ($h->POID ?? '?'),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOnePo(
        object $h,
        \Illuminate\Support\Collection $unitsByCode,
        \Illuminate\Support\Collection $itemIdsByCode,
        ?int $defaultSiteId,
        \Illuminate\Support\Collection $ppbIdByReqKey
    ): array {
        if ($h->POID === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'POID kosong — data tidak valid.'];
        }

        $lines = DB::table('accurate_podet')
            ->where('POID', $h->POID)
            ->select('SEQ', 'ITEMNO', 'ITEMOVDESC', 'QUANTITY', 'QTYRECV', 'UNITPRICE', 'ITEMUNIT', 'CLOSED', 'REQID', 'REQSEQ')
            ->orderBy('SEQ')
            ->get();

        $totalQty = (float) $lines->sum('QUANTITY');
        $totalRecv = (float) $lines->sum('QTYRECV');
        // Accurate has no DRAFT/SUBMITTED concept — a PO recorded there is
        // already a committed document — so status is derived from received
        // quantity (richer than Accurate's own CLOSED flag alone).
        // A PO closed in Accurate after a partial receipt is finished (the rest
        // will never arrive) — CLOSED must win over PARTIAL_RECEIVED, or it keeps
        // counting as an open PO (13 such POs found in the 2026-09-29 audit).
        $status = match (true) {
            $totalQty > 0 && $totalRecv >= $totalQty - 1e-6 => 'RECEIVED',
            (int) $h->CLOSED === 1 => 'CLOSED',
            $totalRecv > 0 => 'PARTIAL_RECEIVED',
            default => 'SENT',
        };

        $tax = (float) (($h->TAX1AMOUNT ?? 0) + ($h->TAX2AMOUNT ?? 0));
        $total = (float) ($h->POAMOUNT ?? 0);

        $new = [
            'number' => $h->PONO,
            'date' => $h->PODATE,
            'expected_date' => $h->EXPECTED,
            'status' => $status,
            'subtotal' => round($total - $tax, 2),
            'tax' => $tax,
            'total' => $total,
            'notes' => $h->DESCRIPTION,
        ];

        $vendor = $this->findOrCreateAccurateVendor($h->VENDORNAME);

        $po = PurchaseOrder::where('accurate_po_id', $h->POID)->first();

        if ($po) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $po->{$f} instanceof \Carbon\Carbon ? $po->{$f}->toDateString() : $po->{$f}])->all();
            $headerUnchanged = \App\Support\SyncDiff::unchanged($new, $po) && $po->vendor_id === $vendor->id;

            // Lines are always (re)synced even when the header looks unchanged
            // — e.g. totalRecv can move from 5 to 8 out of 10 without the
            // derived header status changing, but the individual line's own
            // qty_received/line_status still needs to move.
            $this->lineChanges = [];
            $linesChanged = $this->syncPoLines($po, $lines, $unitsByCode, $itemIdsByCode, $ppbIdByReqKey);

            if ($headerUnchanged && ! $linesChanged && $po->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $po->forceFill($new + ['vendor_id' => $vendor->id, 'accurate_synced_at' => now()])->save();

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => $this->docMessage('PO', $old['number'] ?? null, $h->PONO, null),
                'old_data' => $old, 'new_data' => $new + ($this->lineChanges ? ['_lines' => $this->lineChanges] : []),
            ];
        }

        $po = PurchaseOrder::create($new + [
            'accurate_po_id' => $h->POID,
            'vendor_id' => $vendor->id,
            'site_id' => $defaultSiteId,
            'accurate_synced_at' => now(),
        ]);
        $this->syncPoLines($po, $lines, $unitsByCode, $itemIdsByCode, $ppbIdByReqKey);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "PO baru dari Accurate: {$h->PONO}.",
            'new_data' => $new,
        ];
    }

    /**
     * Upsert every PODET line for one PO header, keyed by (purchase_order_id,
     * accurate_seq). Returns whether anything actually changed, so the caller
     * can fold that into its own unchanged/skip decision.
     */
    protected function syncPoLines(PurchaseOrder $po, \Illuminate\Support\Collection $lines, \Illuminate\Support\Collection $unitsByCode, \Illuminate\Support\Collection $itemIdsByCode, \Illuminate\Support\Collection $ppbIdByReqKey): bool
    {
        $existingBySeq = PurchaseOrderItem::where('purchase_order_id', $po->id)
            ->whereNotNull('accurate_seq')
            ->get()
            ->keyBy('accurate_seq');

        $changed = false;

        foreach ($lines as $line) {
            $qty = (float) ($line->QUANTITY ?? 0);
            $unitPrice = (float) ($line->UNITPRICE ?? 0);
            $qtyReceived = (float) ($line->QTYRECV ?? 0);
            $lineStatus = match (true) {
                $qty > 0 && $qtyReceived >= $qty - 1e-6 => 'RECEIVED',
                $qtyReceived > 0 => 'PARTIAL_RECEIVED',
                (int) ($line->CLOSED ?? 0) === 1 => 'CLOSED',
                default => 'PENDING',
            };

            $attrs = [
                'item_id' => $itemIdsByCode->get($line->ITEMNO),
                'description_raw' => $line->ITEMOVDESC ?? '-',
                'qty' => $qty,
                'unit_id' => $unitsByCode->get(mb_strtoupper(trim((string) ($line->ITEMUNIT ?? '')))),
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'qty_received' => $qtyReceived,
                'line_status' => $lineStatus,
                'accurate_ppb_id' => ($line->REQID !== null && $line->REQSEQ !== null)
                    ? $ppbIdByReqKey->get($line->REQID.'-'.$line->REQSEQ)
                    : null,
            ];

            $existing = $existingBySeq->get($line->SEQ);
            if ($existing) {
                $lineUnchanged = \App\Support\SyncDiff::unchanged($attrs, $existing);
                if (! $lineUnchanged) {
                    array_push($this->lineChanges, ...\App\Support\SyncDiff::lineChanges($line->SEQ, $line->ITEMNO ?? null, $existing->only(array_keys($attrs)), $attrs));
                    $existing->forceFill($attrs)->save();
                    $changed = true;
                }
            } else {
                PurchaseOrderItem::create($attrs + ['purchase_order_id' => $po->id, 'accurate_seq' => $line->SEQ]);
                array_push($this->lineChanges, ...\App\Support\SyncDiff::lineChanges($line->SEQ, $line->ITEMNO ?? null, null, $attrs));
                $changed = true;
            }
        }

        // A line removed from the PO in Accurate (e.g. a revised PO) goes here too —
        // left behind it kept counting as "still on order" for its PPB. References to
        // it (RI accurate_po_item_id, receiving_items) are ON DELETE SET NULL.
        $accurateSeqs = $lines->pluck('SEQ')->map(fn ($v) => (int) $v)->flip();
        foreach ($existingBySeq as $seq => $gone) {
            if (! isset($accurateSeqs[(int) $seq])) {
                $this->lineChanges[] = ['seq' => $seq, 'item' => mb_substr((string) $gone->description_raw, 0, 40), 'field' => 'baris',
                    'from' => rtrim(rtrim(number_format((float) $gone->qty, 4, '.', ''), '0'), '.').' × '.$gone->description_raw, 'to' => 'dihapus di Accurate'];
                $gone->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Find a Vendor by exact name (case-sensitive, matching the unique
     * constraint on vendors.name) or create a placeholder flagged for human
     * review — same source='accurate'+needs_review=true pattern already used
     * by App\Console\Commands\Accurate\ImportNewItems for auto-created rows.
     */
    protected function findOrCreateAccurateVendor(?string $name): Vendor
    {
        $name = trim((string) $name) ?: 'VENDOR ACCURATE TANPA NAMA';

        return Vendor::firstOrCreate(
            ['name' => $name],
            ['is_active' => true, 'needs_review' => true, 'source' => 'accurate']
        );
    }

    /**
     * Pengembalian Bekas (UsedReturn) had the same problem as STPP: a real
     * workflow with no data behind it, because nothing ever surfaced which
     * Accurate records represented one. Per explicit user instruction, the
     * source here is the RI mirror's own divisi "NV" documents (e.g.
     * "RI/NV/25/IX/001") — confirmed against real data (2,534 lines across
     * 1,017 invoices) and, per the user's own follow-up answer, taken as-is
     * with no extra keyword filtering, even though many of them read as
     * warehouse data-migration or fabrication events rather than literally
     * "used item" returns — that's a business-content judgment call outside
     * what this sync can verify, not something to silently narrow down.
     *
     * One Accurate AP invoice (accurate_apinvoice_id) -> one UsedReturn
     * header, its lines -> used_return_items, keyed by accurate_apinvoice_id
     * so a repeat sync never duplicates it (see the "used_returns" migration
     * adding that column). Created PENDING, same as a manually-entered one —
     * still goes through the normal "Tutup & Masukkan ke Stok" review step
     * before anything touches stock_movements/inventory (never automatic,
     * matching this class's own "Accurate's own stock effect is reference-
     * only" rule, restated in syncStockOpname()'s docblock below).
     *
     * `condition`/`into_stock` have no equivalent in RI's data at all — per
     * the user's explicit instruction to derive them from Accurate's own
     * database rather than a fixed guess, they come from the item's own
     * Kategori Induk (see KATEGORI_INDUK_MAP): "Post-Use Items" (prefix PUI)
     * -> REUSABLE + into_stock, anything else -> USED + not into stock, left
     * for a person to correct on review — same "don't fabricate what isn't
     * there" policy as every other derived field in this class.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function uniqueUsedReturnNumber(string $noRi): string
    {
        if (! UsedReturn::where('number', $noRi)->exists()) {
            return $noRi;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = "{$noRi} ({$suffix})";
            if (! UsedReturn::where('number', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    protected function deriveUsedReturnsFromRi(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $itemsByCode = Item::select('id', 'code', 'accurate_category_induk')->get()->keyBy('code');
        $unitsByCode = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));
        $alreadyDerived = UsedReturn::whereNotNull('accurate_apinvoice_id')->pluck('accurate_apinvoice_id')->flip();
        $defaultSiteId = Site::query()->orderBy('id')->value('id');

        // only Accurate's RI: a manual one (no APINVOICEID) has no Accurate document to follow
        $grouped = Ri::where('divisi', 'NV')->whereNotNull('accurate_apinvoice_id')
            ->orderBy('accurate_apinvoice_id')->orderBy('accurate_seq')
            ->get()
            ->groupBy('accurate_apinvoice_id');

        foreach ($grouped as $apinvoiceId => $lines) {
            $counts['total']++;

            try {
                if (isset($alreadyDerived[$apinvoiceId])) {
                    $counts['skipped']++;
                    $this->writeLog([
                        'sync_batch_id' => $batch->id, 'entity' => 'used_return', 'source_id' => (string) $apinvoiceId,
                        'action' => 'SKIP', 'status' => 'SUCCESS',
                        'message' => 'Pengembalian Bekas sudah pernah dibuat dari RI ini.',
                    ]);

                    continue;
                }

                $first = $lines->first();
                $ur = UsedReturn::create([
                    // The RI's own number IS this record's number — per explicit user
                    // instruction, a separately generated "UR/SDA/..." number isn't
                    // wanted for rows that already have a real Accurate document number.
                    // used_returns.number is unique, and 5 real RI/NV numbers in
                    // production are reused across two different invoices (a data
                    // quirk in Accurate itself) — disambiguated with a suffix so the
                    // rare collision never silently drops a row.
                    'number' => $this->uniqueUsedReturnNumber($first->no_ri),
                    'accurate_apinvoice_id' => $apinvoiceId,
                    // npbg_ref_raw is specifically "reference NPBG number" (shown in the
                    // UI as "NPBG asal") — an RI-derived row has no NPBG; left null
                    // rather than overloading the wrong field.
                    'return_date' => $first->tgl_ri ?? now(),
                    'status' => 'PENDING',
                    'format' => 'ITEM_LINE',
                    'site_id' => $defaultSiteId,
                    'note' => $first->keterangan,
                ]);

                foreach ($lines as $i => $line) {
                    $item = $line->kode_barang ? $itemsByCode->get($line->kode_barang) : null;
                    $unit1 = $line->satuan ? mb_strtoupper(trim($line->satuan)) : null;
                    $isPostUse = $item?->accurate_category_induk === 'Post-Use Items';

                    $ur->items()->create([
                        'item_id' => $item?->id,
                        'description_raw' => $line->deskripsi_barang,
                        'qty' => $line->kuantitas ?: 1,
                        'unit_id' => $unit1 ? $unitsByCode->get($unit1) : null,
                        'condition' => $isPostUse ? 'REUSABLE' : 'USED',
                        'into_stock' => $isPostUse,
                        'item_no' => $i + 1,
                    ]);
                }

                $counts['inserted']++;
                $this->writeLog([
                    'sync_batch_id' => $batch->id, 'entity' => 'used_return', 'source_id' => (string) $apinvoiceId,
                    'action' => 'INSERT', 'status' => 'SUCCESS',
                    'message' => "Pengembalian Bekas {$ur->number} dibuat dari RI {$first->no_ri} ({$lines->count()} baris).",
                    'new_data' => ['number' => $ur->number, 'no_ri' => $first->no_ri, 'keterangan' => $first->keterangan],
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;
                $this->writeLog([
                    'sync_batch_id' => $batch->id, 'entity' => 'used_return', 'source_id' => (string) $apinvoiceId,
                    'action' => 'ERROR', 'status' => 'FAILED', 'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * Stock Opname — same situation as PO: `stock_opnames`/`stock_opname_items`
     * already represent the real thing (a physical count reconciled against
     * system qty), just not yet synced against Accurate's own record of it.
     * Accurate's ITEMADJ (header) + ITADJDET (lines) is that record — verified
     * against real data: the DESCRIPTION literally says e.g. "STOK OPNAME TGL
     * 10.09.2026", and ITADJDET.NEWQTY/CURRENTQTY/QTYDIFFERENCE map exactly
     * onto physical_qty/system_qty/difference.
     *
     * No line-level accurate_seq column: stock_opname_items already enforces
     * unique(stock_opname_id, item_id), which doubles as the right upsert key
     * here (one line per item per opname) — one known ITEMADJ has the same
     * ITEMNO twice across two SEQs; the second simply overwrites the first,
     * an acceptable resolution for a single edge case in the source data.
     *
     * Accurate's own stock effect is NOT re-applied to stock_movements/
     * inventory — same rule as every other Accurate sync in this app
     * (Accurate's qty is reference-only, see this class's docblock). This
     * only mirrors the opname record itself; no stock_adjustments row is
     * created and StockLedgerService is never called.
     *
     * Counted per ITEMADJ header (not per ITADJDET line), same reasoning as
     * syncPo(): a real relational header+lines document, matching the shape
     * stock_opnames/stock_opname_items already has.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncStockOpname(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_itemadj') || ! DB::getSchemaBuilder()->hasTable('accurate_itadjdet')) {
            return $counts;
        }

        $itemIdsByCode = Item::pluck('id', 'code');
        $defaultSiteId = Site::query()->orderBy('id')->value('id');
        $defaultWarehouseId = Warehouse::query()->orderBy('id')->value('id');

        // STOCKWISE had no warehouse at all on the production server, so every
        // opname failed with "warehouse_id cannot be null" (2547 errors per
        // sync, the reason every batch ended PARTIAL). Resolve each ITEMADJ's
        // own Accurate warehouse instead; if even that is impossible, record
        // ONE clear error instead of one per row.
        $warehouseIdsByAccurateId = $this->resolveAccurateWarehouses($defaultSiteId);
        $defaultWarehouseId = $defaultWarehouseId ?? $warehouseIdsByAccurateId->first();
        if ($defaultWarehouseId === null) {
            $counts['errors']++;
            $this->writeLog([
                'sync_batch_id' => $batch->id,
                'entity' => 'stock_opname',
                'source_id' => '-',
                'action' => 'ERROR',
                'status' => 'FAILED',
                'message' => 'Stock opname dilewati: belum ada gudang (warehouse) maupun site di STOCKWISE untuk menampung data opname Accurate.',
            ]);

            return $counts;
        }

        $headers = DB::table('accurate_itemadj')
            ->select('ITEMADJID', 'ADJNO', 'ADJDATE', 'ADJCHECK', 'DESCRIPTION', 'WAREHOUSEID')
            ->orderBy('ITEMADJID')
            ->get();

        foreach ($headers as $h) {
            $counts['total']++;

            try {
                $warehouseId = $warehouseIdsByAccurateId->get((string) ($h->WAREHOUSEID ?? ''), $defaultWarehouseId);
                $outcome = $this->syncOneStockOpname($h, $itemIdsByCode, $defaultSiteId, $warehouseId);
                $counts[$outcome['bucket']]++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'stock_opname',
                    'source_id' => (string) $h->ITEMADJID,
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                $this->writeLog([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'stock_opname',
                    'source_id' => (string) ($h->ITEMADJID ?? '?'),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }


    /**
     * Accurate WAREHS -> STOCKWISE warehouses (keyed by Accurate WAREHOUSEID
     * as string). A missing STOCKWISE warehouse is created once, on the
     * default (lowest-id) site, code "ACC-<NAME>" — Accurate has exactly one
     * ("CENTRE") on the production server. Never renames/deletes anything.
     */
    protected function resolveAccurateWarehouses(?int $siteId): \Illuminate\Support\Collection
    {
        if ($siteId === null || ! DB::getSchemaBuilder()->hasTable('accurate_warehs')) {
            return collect();
        }

        return DB::table('accurate_warehs')->get()->mapWithKeys(function ($w) use ($siteId) {
            $name = trim((string) ($w->NAME ?? '')) ?: 'GUDANG '.$w->WAREHOUSEID;
            $warehouse = Warehouse::firstOrCreate(
                ['site_id' => $siteId, 'code' => mb_substr('ACC-'.mb_strtoupper($name), 0, 20)],
                ['name' => mb_substr($name.' (Accurate)', 0, 80), 'is_active' => true],
            );

            return [(string) $w->WAREHOUSEID => $warehouse->id];
        });
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOneStockOpname(
        object $h,
        \Illuminate\Support\Collection $itemIdsByCode,
        ?int $defaultSiteId,
        ?int $defaultWarehouseId
    ): array {
        if ($h->ITEMADJID === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'ITEMADJID kosong — data tidak valid.'];
        }

        $lines = DB::table('accurate_itadjdet')
            ->where('ITEMADJID', $h->ITEMADJID)
            ->select('SEQ', 'ITEMNO', 'NEWQTY', 'CURRENTQTY')
            ->orderBy('SEQ')
            ->get();

        // Accurate has no SCHEDULED/IN_PROGRESS concept — a physical count
        // only ever reaches ITEMADJ once counted — so ADJCHECK (posted/final
        // in Accurate's own books) is the only signal available: checked ->
        // COMPLETED (matches our terminal reviewed-and-approved state),
        // unchecked -> PENDING_REVIEW (counts are in, not yet finalized).
        $status = (int) $h->ADJCHECK === 1 ? 'COMPLETED' : 'PENDING_REVIEW';

        $new = [
            'number' => (string) $h->ADJNO,
            'scheduled_date' => $h->ADJDATE,
            'status' => $status,
        ];

        $opname = StockOpname::where('accurate_itemadj_id', $h->ITEMADJID)->first();

        if ($opname) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $opname->{$f} instanceof \Carbon\Carbon ? $opname->{$f}->toDateString() : $opname->{$f}])->all();
            $headerUnchanged = \App\Support\SyncDiff::unchanged($new, $opname);

            // Lines are always (re)synced even when the header looks
            // unchanged — same reasoning as syncPoLines().
            $this->lineChanges = [];
            $linesChanged = $this->syncStockOpnameLines($opname, $lines, $itemIdsByCode, $status, $defaultWarehouseId);

            if ($headerUnchanged && ! $linesChanged && $opname->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $opname->forceFill($new + ['accurate_synced_at' => now()])->save();

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => $this->docMessage('Stock Opname', $old['number'] ?? null, (string) $h->ADJNO, null),
                'old_data' => $old, 'new_data' => $new + ($this->lineChanges ? ['_lines' => $this->lineChanges] : []),
            ];
        }

        $opname = StockOpname::create($new + [
            'accurate_itemadj_id' => $h->ITEMADJID,
            'site_id' => $defaultSiteId,
            'warehouse_id' => $defaultWarehouseId,
            'type' => 'PARTIAL',
            'accurate_synced_at' => now(),
        ]);
        $this->syncStockOpnameLines($opname, $lines, $itemIdsByCode, $status, $defaultWarehouseId);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "Stock Opname baru dari Accurate: {$h->ADJNO}.",
            'new_data' => $new,
        ];
    }

    /**
     * Upsert every ITADJDET line for one ITEMADJ header, keyed by
     * (stock_opname_id, item_id) — stock_opname_items' own unique
     * constraint. Returns whether anything actually changed.
     */
    protected function syncStockOpnameLines(StockOpname $opname, \Illuminate\Support\Collection $lines, \Illuminate\Support\Collection $itemIdsByCode, string $headerStatus, ?int $defaultWarehouseId): bool
    {
        $existingByItemId = StockOpnameItem::where('stock_opname_id', $opname->id)->get()->keyBy('item_id');
        // Never-mutated snapshot of the DB state as it was BEFORE this pass,
        // purely for the "did the final state actually change" check below.
        $originalByItemId = $existingByItemId->map(fn ($i) => $i->only(['item_id', 'warehouse_id', 'system_qty', 'physical_qty', 'count_status', 'review_status']));
        $reviewStatus = $headerStatus === 'COMPLETED' ? 'APPROVED' : 'PENDING';

        // One known ITEMADJID repeats the same ITEMNO across two SEQs (see
        // class docblock) — always write every line (last SEQ wins for that
        // item), and separately track just the final attrs actually reached
        // per item, so "changed" reflects the end result rather than
        // flip-flopping mid-pass on that one edge case.
        $finalAttrsByItemId = [];

        foreach ($lines as $line) {
            $itemId = $itemIdsByCode->get($line->ITEMNO);
            if ($itemId === null) {
                continue; // ITEMNO not a real Master Barang product (or not yet synced) — skip rather than guess.
            }

            $attrs = [
                'item_id' => $itemId,
                'warehouse_id' => $defaultWarehouseId,
                'system_qty' => (float) ($line->CURRENTQTY ?? 0),
                'physical_qty' => (float) ($line->NEWQTY ?? 0),
                'count_status' => 'COUNTED',
                'review_status' => $reviewStatus,
            ];
            $finalAttrsByItemId[$itemId] = $attrs;

            $existing = $existingByItemId->get($itemId);
            if ($existing) {
                $before = $originalByItemId->get($itemId);
                if ($before !== null && ! \App\Support\SyncDiff::unchanged($attrs, $before)) {
                    array_push($this->lineChanges, ...\App\Support\SyncDiff::lineChanges($line->SEQ, $line->ITEMNO, $before, $attrs));
                }
                $existing->forceFill($attrs)->save();
            } else {
                $existing = StockOpnameItem::create($attrs + ['stock_opname_id' => $opname->id]);
                array_push($this->lineChanges, ...\App\Support\SyncDiff::lineChanges($line->SEQ, $line->ITEMNO, null, $attrs));
                $existingByItemId->put($itemId, $existing);
            }
        }

        foreach ($finalAttrsByItemId as $itemId => $attrs) {
            $original = $originalByItemId->get($itemId);
            $unchanged = $original !== null && \App\Support\SyncDiff::unchanged($attrs, $original);
            if (! $unchanged) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kategori Anak 1/2/3 = ITEMDESCRIPTION of each real PARENTITEM ancestor,
     * walked upward from the product's immediate parent (deepest) to the
     * shallowest real ancestor. There is deliberately no "Kategori Induk"
     * slot: verified against the real GDB that a bare 0-dot ITEMNO node
     * (e.g. "AUT") never exists in this company's data, so that level has no
     * ITEMDESCRIPTION to read — see docs/accurate-database-analysis.md §12.
     * Chain depth varies per product (1-3 real ancestors are all observed in
     * the real data), so shallower products simply leave the deeper Anak
     * slots null — not a bug.
     *
     * @return array{anak_1: ?string, anak_2: ?string, anak_3: ?string}
     */
    protected function resolveCategoryHierarchy(?string $parentItem, \Illuminate\Support\Collection $nodesByCode): array
    {
        $chain = [];
        $current = $parentItem !== null ? trim($parentItem) : '';

        while ($current !== '' && count($chain) < self::MAX_CATEGORY_LEVELS) {
            $node = $nodesByCode->get($current);
            if (! $node) {
                break; // dangling PARENTITEM reference — stop rather than guess
            }

            $chain[] = trim((string) ($node->ITEMDESCRIPTION ?? '')) ?: null;
            $current = trim((string) ($node->PARENTITEM ?? ''));
        }

        // $chain is deepest-first (immediate parent first); reverse so the
        // shallowest real ancestor becomes Anak 1.
        $chain = array_reverse($chain);

        return [
            'anak_1' => $chain[0] ?? null,
            'anak_2' => $chain[1] ?? null,
            'anak_3' => $chain[2] ?? null,
        ];
    }
}
