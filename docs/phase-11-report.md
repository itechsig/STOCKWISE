# PHASE 11 — Sync Agent Redesign — Report

## PHASE

11 — Replace the co-located "Laravel shells out to a local Python script reading a live GDB" sync
mechanism with the brief's actual architecture (§3-17): an independent Agent, running at the office on
its own 04:00/10:30/20:30 schedule, that reads a **restored `.GBK` backup** (never the live production
file) and **pushes** the mirrored data to Laravel over HTTPS. Also: git history cleanup (a personal
Downloads folder, including a password file, had been committed).

Phases 1–10 (RBAC, Master Barang, Request/Reserve/Goods Issue, NPBG/PPB/RI Accurate mirrors, Stock
Opname, Purchasing, 7 Tracking modules, Dashboard, Export) were untouched — all already built and
working from earlier phases; this phase only replaced the staging-refresh mechanism underneath the
existing `AccurateSyncService` mapping/business logic, which itself did not need rewriting.

## PART A — Git history cleanup

`frontend/Downloads/` (275 files, including `0.Password.txt` and a SQL dump — the user's real Windows
Downloads folder, accidentally committed) and 3 stray dev-server log files + 12 Python `.pyc` files were
untracked. The 10 commits ahead of `origin/main` were rewritten in place (`git rebase -i`, safety-tagged
first) — none had been pushed, so this was a same-shape local rewrite, not a shared-history rewrite.
Verified via `git diff` against the pre-rewrite tag restricted to all source paths: **0 lines differ** —
no application code changed. All 307 files remain on disk, untracked only.

## PART B — Sync Agent redesign

### Files created

**backend/**
- `app/Services/Accurate/StagingTableWriter.php` — whitelist-checked (table/column name/type) create +
  chunked insert into `accurate_*` MySQL tables, replacing `sync-service/database/mysql_client.py`'s job
  server-side.
- `app/Http/Controllers/Api/Agent/AccurateIngestController.php` — `startSession` / `pushTable` /
  `complete` / `fail`, gated by the Sanctum `agent:sync` ability (not RBAC `permission:`).
- `app/Console/Commands/Accurate/AgentTokenCommand.php` — `php artisan stockwise:agent-token` issues the
  Agent's API token against a dedicated no-RBAC system user.
- `tests/Feature/Agent/AccurateIngestTest.php` — 7 tests (ability auth, table whitelist rejection, full
  push→complete flow reusing the same business-logic assertions as the manual-trigger tests, chunking,
  `fail()`, sync lock, mirror-table-list parity with the Python side).

**sync-service/**
- `agent/{__init__,state,scanner,restore,api_client,scheduler,run}.py` — the Agent itself.
- `tests/{test_scanner,test_state,test_scheduler}.py` — 17 tests, no real Firebird/MySQL/network needed.
- `requirements.txt`, `requirements-dev.txt`, `README.md`, `conftest.py`.

### Files modified

- `backend/config/accurate.php` — dropped the subprocess-shelling keys; added the `mirror_tables` and
  `column_types` whitelists.
- `backend/app/Services/Accurate/AccurateSyncService.php` — removed `refreshStaging()` (the
  `Process::run` subprocess call) entirely; split `run()` into `startBatch()` (shared lock/bookkeeping),
  `startForAgent()`, `finishFromStaging()` (all the existing mapping/upsert logic, unchanged), and
  `failBatch()`. `run()` (the manual "Sync Accurate" button) now re-runs `finishFromStaging()` against
  whatever is already staged — it no longer talks to Firebird/the Agent in any way.
- `backend/bootstrap/app.php`, `backend/routes/api.php` — registered the `abilities` Sanctum middleware
  alias and the new `POST /api/agent/sync/*` route group.
- `backend/tests/Feature/Accurate/AccurateSyncTest.php` — removed the now-inapplicable
  `Process::fake()`-based "unreachable Accurate" test (that failure mode moved to the Agent/ingest side);
  added two tests for the new reprocess-only `run()` semantics (empty accurate_* tables succeed with
  zero counts; concurrent calls share one lock).
- `sync-service/config/settings.py` — `FirebirdSettings.database` default repointed at the staging path
  (not the live GDB); added `BackupSettings`, `StagingSettings`, `ApiSettings`, `AgentSettings`.
- `sync-service/mapping/type_mapping.py` — added `firebird_column_to_mysql_type()` (structured
  `{type,length,nullable}`, what the Agent sends to the API); `firebird_column_to_mysql_ddl()` (the old,
  still-used-by-the-deprecated-path function) now builds on top of it instead of duplicating the
  type-decision logic.
- `sync-service/.env.example`, `sync-service/.env` — new Agent variables documented/filled in
  (`BACKUP_PATH`, `STAGING_PATH`, `GBAK_BIN`, `API_URL`, `API_TOKEN`, `SYNC_TIMES`,
  `AGENT_STATE_PATH`, `AGENT_LOG_PATH`, `STABILITY_CHECK_SECONDS`); old `MYSQL_*`/`FIREBIRD_DATABASE`
  (live-file) kept, marked deprecated, for the still-present legacy path.
- `frontend/src/pages/SyncPage.tsx` — copy only ("Sync Accurate" button relabeled "Proses Ulang" with an
  explanation that fresh data arrives via the scheduled Agent, not this button).
- `docs/api-sync.md`, `docs/sync-architecture.md` — updated to describe the push model and the new
  `/api/agent/sync/*` routes.

### Explicitly not touched

Everything from Phases 1–10 (models/services/controllers for Request, Goods Issue, Npbg/NpbgVerification,
Stock Opname, PurchaseProposal/Ppb/PurchaseOrder/Receiving/Ri, all 7 Tracking modules, Dashboard, Export,
RBAC), `D:\GUDANGSIG2025.GDB` (real production, outside the repo), `D:\STOCKWISE\GUDANGSIG2025.GDB`
(local dev copy — read-only), `security2.fdb`, `sync/initial_sync.py` + `database/mysql_client.py`
(left in place, deprecated, not deleted).

## Command to run

```bash
cd backend
php artisan test                                  # full suite
php artisan stockwise:agent-token                  # issue the Agent's API token

cd ../sync-service
pip install -r requirements-dev.txt
python -m pytest tests/
python -m agent.run --dry-run                       # safe: scans only, no restore/push

cd ../frontend
npm run typecheck && npm run build
```

## Test & Expected & Actual

| Test | Expected | Actual |
|------|----------|--------|
| `php artisan test` (full suite) | all green | ✅ **187 passed, 1263 assertions** |
| `AccurateIngestTest` (7 tests: auth, whitelist, full push→complete, chunking, fail, lock, mirror-table parity) | all green | ✅ **7 passed** |
| `AccurateSyncTest` (12 tests, updated) | all green | ✅ **12 passed** |
| `php artisan route:list --path=agent` | 4 new routes registered | ✅ 4 routes |
| `python -m pytest tests/` (scanner, state, scheduler, validate) | all green | ✅ **27 passed** |
| `npm run typecheck` | clean | ✅ |
| Full end-to-end (real `.gbk` fixture → restore → validate → Firebird read → API push → complete) | scans, restores, validates, syncs, marks SUCCESS | ✅ — see PART C |

## PART C — GUDANGSIG2025 filter, staging validation, and first successful end-to-end run

Follow-up work, same phase: D:\ on the real office server holds backups for several unrelated
databases side by side (`INTERN 1833*.gbk`, `GUDANG2021.GDB`, ...), so the backup filter needed
tightening, and a second (content) validation layer was added per the brief's own acceptance tests.

### What changed

- `config/settings.py` — `BackupSettings.pattern` default changed from `*.gbk` to `GUDANGSIG2025*.gbk`
  (also fixed in `.env`/`.env.example`, which still had the old value overriding the new default).
- New `agent/validate.py` — second safety layer: after restore, before any API call, confirms the
  staging DB's `ITEM` table has the expected columns AND at least one row matching the real product
  code pattern (`AUT.0001`-style) — not just a filename match. Wired into `agent/run.py` between
  restore and `start_session()`, so a validation failure never creates a stray `RUNNING` batch.
- 10 new scanner tests (GUDANGSIG2025 detected / INTERN ignored / `.GDB` never matches by construction
  / newest-only picked) + 6 new validate tests — **27 Python tests total**.

### First real end-to-end run — 3 bugs found and fixed

Got a local Firebird 2.5 server running on this dev machine (previously none was registered at all) by
running `fbserver.exe` directly as a plain process (not installed as a Windows service — reversible,
dev-machine-only). Built a fully synthetic test fixture (own local database, own `ITEM` table, own
credentials — `SYSDBA`/`masterkey` on a fresh local install, never any Accurate/production credential)
and backed it up as `GUDANGSIG2025 TestFixture.gbk`, then ran `agent.run --sync-now` against it for
real. This is what brief §25's "local testing strategy" describes — no production data was involved at
any point. Three real bugs surfaced, all fixed:

1. **`restore.py` used `gbak -r`, not `-rep`.** `-r` (`RECREATE_DATABASE`) fails if the staging file
   already exists from a previous tick; `-rep` (`REPLACE_DATABASE`) overwrites it, which is what "the
   staging DB is always overwritten fresh" was supposed to mean. Fixed.
2. **`FirebirdSettings.database` had its own independent default**, separate from
   `StagingSettings.database_path()` — a `STAGING_PATH` override would restore to one file and then try
   to read a different (stale/nonexistent) one. Removed the redundant `FIREBIRD_DATABASE` env var
   entirely; `FirebirdSettings.dsn()` now always derives from `StagingSettings`, so there is exactly one
   fact ("where does the staging DB live"), not two that can drift.
3. **`AccurateIngestController::complete()` had no `set_time_limit()`, and the Agent's own HTTP timeout
   was 30s for every call.** Confirmed against this dev machine's real local data (~20,839 existing
   NPBG/RI-derived records from earlier testing): `finishFromStaging()` legitimately takes ~90s. The
   manual "Sync Accurate" button (`SyncController::store()`) already knew this and sets
   `set_time_limit(300)` — `complete()` now does the same. Added `API_COMPLETE_TIMEOUT_SECONDS` (default
   310s) so the Agent's `complete()` call specifically waits long enough, while the smaller per-chunk
   `push_table` calls keep the short default.

After these fixes, a full run succeeded: scan → filter → stability check → restore → **staging identity
validated** → extract `ITEM` (5 rows) → push → `complete` (~94s) → `SYNC-...-004 status=SUCCESS`. 3 real
products landed in `items` correctly (`AAA.0001`/`AAA.0002`/`SSP.0001`, right description/qty), the 2
category nodes correctly skipped, and the backup's state was marked `SUCCESS` (won't be reprocessed).
Local Firebird install and its `security2.fdb` were left exactly as they started (a security2.fdb swap
attempted mid-debugging to test the repo-root copy's credentials was reverted — see conversation; no
Accurate/production credential was ever found or used anywhere in this process).

Full test suites re-run clean after these fixes: **187 backend + 27 Python**, no regressions.

## PART D — Real office backup test, and two real data-logic bugs found

The user placed real backup files on the local `D:\` (`GUDANGSIG2025 092026.gbk`, 36MB, plus two
unrelated backups — `DATA TABUNG SORONG...`, `GUDANG SIG SDA...` — that the filter correctly ignored).

**Bug 4 — `gbak -r` vs `-rep`.** Retried against the real backup; failed with
`"Your login SYSDBA is same as one of the SQL role name"` — this real GUDANGSIG2025 database's own data
defines a SQL ROLE literally named SYSDBA, which collides with authenticating AS SYSDBA during restore.
Fixed by creating a dedicated local Firebird user (`stockwise_agent`, on this dev PC's own local
Firebird install only — never Accurate/production credentials) and using that for `FIREBIRD_USER`
instead of SYSDBA.

**Bug 5 — stale partial restore blocked the retry.** A first failed restore left a partial 73MB
`STAGING.GDB`; the next `gbak -rep` attempt then hung for the full 900s subprocess timeout waiting on
it. Fixed operationally (clear stale staging file + restart the local Firebird process before retrying)
— not a code bug, but worth noting for anyone reading the Agent's logs if a restore seems to hang after
a previous failure.

**Bug 6 — Python's stdout buffers when redirected to a file.** A background run appeared "stuck" with
no new log lines for 4+ minutes; killing it turned out to be premature — logging output was sitting in
an unflushed buffer (Python defaults to block-buffering when stdout isn't a real terminal), not actually
hung. Re-ran with `PYTHONUNBUFFERED=1` / `python -u` for real-time visibility. Worth setting this
permanently for the Agent's actual deployment (or explicitly flushing the logging handlers) so its logs
are trustworthy in real time, not just in hindsight.

With those fixed, the real backup synced successfully end to end: all 20 tables, largest at 16,209 rows
(ITEMHIST), batch `SYNC-20260921-005` **SUCCESS, 30,583 records, 0 errors** — 9,198 Items / 8,587 NPBG /
2,497 PPB / 4,824 RI / 1,302 PO landed, each count matching its source table's pushed row count exactly.

**Bug 7 (data logic, not sync) — `item_safety_stocks`/`inventory` had regressed to near-empty.**
Spot-checking the real synced data (per the user's "test dan fix bug ulang dari logic data" instruction)
found `item_safety_stocks` at 4 rows and `inventory` at 13 rows, against ~5,357 / ~647 documented in
`docs/phase-3-report.md` — categories/units were still exactly correct (452/27), so this wasn't a full
reset, just these two. Root cause: the source Excel files (`DATA.xlsx` etc.) were no longer at
`stockwise.import_path`'s default location (`D:\STOCKWISE\`) — found intact at `D:\STOCKWISE_ST\DATAFIX\`
instead. Fixed by pointing `STOCKWISE_IMPORT_PATH` there and re-running `php artisan stockwise:import
item_safety_stocks` (safe — the importers upsert by item code, confirmed zero duplicate codes
afterward): restored to 5,357 / 2,272 rows.

**Bug 8 (data logic) — `stock_known=false` items were flagged TIDAK_AMAN even when Accurate showed real
stock.** `StockwiseEngine` treats an item with no `inventory` row at all as unknown-stock, which reads
as 0 for Selisih/Status purposes — by original Phase 3 design, meant to be resolved by a physical Stock
Opname. 257 of 863 TIDAK_AMAN items (30%) had a real, positive `accurate_qty_onhand` and were false
positives. Per explicit user instruction ("jangan berpatokan pada stok opname"), fixed in
`AccurateSyncService::syncOneItem()` (new `seedOpeningBalanceIfMissing()`): an item with zero inventory
rows anywhere gets a one-time `OPENING_BALANCE` movement seeded from `accurate_qty_onhand` via the
existing `StockLedgerService` (same mechanism the Excel importer already used) — checked on every sync,
not just first sight, so already-synced items self-heal too. Deliberately a **one-time** seed: once an
item has any inventory row, `stock_known` is true and later Accurate quantity changes stay
reference-only, same rule as every other Accurate-sourced field in this class — a real Stockwise
operation (reserve/pickup/opname) afterward is never silently overwritten by a later sync (covered by
`test_item_already_tracked_by_stockwise_is_never_overwritten_by_a_later_accurate_qty`). Backfilled
immediately against the real synced data: `inventory` rows 2,272 → **9,200** (exactly matching item
count — full coverage), TIDAK_AMAN count 863 → **705**. Two new tests added (14 total in
`AccurateSyncTest.php`); full suite **189 backend tests**, all green.

## Known issues / not yet done

1. **Not yet tested against a real office-produced `.gbk` backup running on the office server itself** —
   validated on this dev PC only (real backup file, but local Firebird/MySQL/Laravel). Deployment target
   changed mid-session: Laravel + MySQL + React will run on a **separate** machine at the office (not the
   Accurate server itself, confirmed explicitly with the user — installing MySQL/PHP on the Accurate
   production server was flagged as conflicting with the brief's own explicit rule against it), with the
   Agent (and its own separate, Stockwise-controlled Firebird instance) staying on the Accurate server.
   That second machine isn't provisioned/specified yet.
2. **Not a Windows Service yet** — intentional, per brief §42 (prove it stable as a plain process first).
   The local Firebird server started for this test is also a plain process, not an installed service.
3. **Legacy `sync/initial_sync.py` + `database/mysql_client.py`** are still present but unused by
   `agent/run.py` — left in place, deprecated, not deleted.
4. **`config/accurate.php`'s `mirror_tables`/`column_types`** must be kept in sync with
   `sync-service/mapping/tables.py` by hand — a test fails loudly if they drift, but there's no single
   source of truth yet.

## Status

**PASS** — mechanism proven end-to-end against a real 36MB office backup (30,583 records, 0 errors), two
real data-logic bugs found and fixed (safety stock/opening balance recovery; false TIDAK_AMAN alerts for
unknown-stock items), 189 backend + 27 Python tests green, no Accurate/Firebird production credential
ever requested or used.

## Next step

Deployment target is now two machines at the office: the Agent (+ its own separate local Firebird
instance) stays on the Accurate server; Laravel + MySQL + React go on a second, currently-unspecified
machine. Once that second machine is identified: deploy there, point the Agent's `API_URL` at it over
LAN, test with a real backup end-to-end on that topology, remove the legacy
`sync/initial_sync.py` + `database/mysql_client.py` path, then consider wrapping the Agent as a Windows
Service (§42).
