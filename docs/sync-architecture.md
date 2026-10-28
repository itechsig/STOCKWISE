# Sync Architecture — Accurate → Stockwise

## Layers

```
ACCURATE 5 DELUXE (Firebird 2.5, D:\GUDANGSIG2025.GDB, office server)
        │  automatic .GBK backup (never read live — see "Why .GBK, not a live connection" below)
        ▼
sync-service/agent/ (Python, runs AT THE OFFICE, on its own 04:00/10:30/20:30
schedule — never co-located with Laravel) — scans D:\ for *.GBK, waits for a
backup's file size to stop changing, restores the newest stable one with
`gbak -r` into a local staging Firebird DB (D:\01.STOCKWISE\Staging), reads
the 20 whitelisted tables from THAT staging DB via `firebird/introspect.py`.
        │  POST /api/agent/sync/sessions/{id}/tables/{table} — chunked, HTTPS,
        │  Sanctum `agent:sync` token — the Agent never touches MySQL directly.
        ▼
App\Services\Accurate\StagingTableWriter (Laravel) — (re)creates
accurate_* staging tables (MySQL `stockwise` DB) from the pushed payload,
whitelist-checked table/column names and types (config('accurate.*')).
        │  POST .../complete triggers AccurateSyncService::finishFromStaging()
        ▼
items (+ 3 reference columns: accurate_synced_at, accurate_qty_onhand,
accurate_qty_onorder) — Stockwise's own existing table, untouched schema
otherwise. sync_batches + sync_logs record every run (Agent-pushed or the
manual "Sync Accurate" re-run via POST /api/sync/accurate).
        │
        ▼
Laravel API → React frontend (Sync Accurate / Sync History / Dashboard widget)
```

## Why Laravel doesn't connect to Firebird directly

Checked first, not assumed: this PHP 8.4.23 (ZTS, VC17, x64) install has no `pdo_firebird` or
`interbase` extension, and a recovered `php_pdo_firebird.dll` found on this machine turned out to be
built for a different PHP ABI (loads its dependencies fine, but PHP can't find the expected module
entry point — confirmed via direct test, not assumed). Rather than compile a custom extension (a real,
distinct, avoidable amount of risk), Firebird reading stays entirely in the already-working Python
`sync-service/` project.

## Why the Agent pushes over HTTPS instead of Laravel pulling

Earlier versions of this had Laravel shell out to the Python script as a subprocess on the same
machine. That only works when Laravel and the Firebird reader are co-located — it cannot survive
Laravel moving to Hostinger while Accurate stays at the office (brief §6: the office Agent may only
ever reach Laravel over its API; Laravel must never reach into the office LAN, and the Agent must never
connect to MySQL directly). The Agent now runs as its own independent, scheduled process
(`sync-service/agent/`) and pushes; Laravel only ever receives.

## Why the source is a `.GBK` backup, not a live connection

Reading the live production `D:\GUDANGSIG2025.GDB` while Accurate 5 has it open is exactly the kind of
interference the brief prohibits (§43 production safety). The Agent only ever reads a `.GBK` backup
Accurate itself already wrote to `D:\`, after confirming (two scans, file size unchanged) that Accurate
has finished writing it — see `sync-service/agent/scanner.py`. It restores that backup into its own
staging Firebird database and reads from there; the production file and the live Firebird service are
never touched.

**Windows-specific gotcha (found, not theoretical):** the shell that starts a Python process can hand
down a `PATH` the Firebird client library can't use (observed with Git Bash/MSYS — raw TCP connectivity
worked, but `fbclient.dll`'s own dependency resolution failed until an explicit clean `PATH` including
its directory was passed to the child process). `sync-service/.env`'s `FIREBIRD_CLIENT_LIB` /
`GBAK_BIN` point at the known-good local install (`D:\FirebirdLocal\Firebird_25\bin`).

## Why the stock number is a reference column, not a ledger entry

Accurate's `WAREHS` table has exactly one row, named `CENTRE` — a generic default, not a real
location. Stockwise's 7 warehouses all belong to one site (`SIG-SDA`), and Accurate's own
`BRANCHCODES` is a single, still-unconfigured placeholder row. Conclusion (confirmed with the user):
Accurate's stock figure is a site-wide total that cannot be safely attributed to any one of
Stockwise's 7 physical warehouses. Forcing it into the per-warehouse `inventory` table would fabricate
precision Accurate's own data doesn't have. It's stored on `items.accurate_qty_onhand` instead — a
cross-check number, not a replacement for Stockwise's own actual/reserved/available ledger.

## Idempotency / upsert

`AccurateSyncService::syncOneItem()` matches by `items.code = accurate_item.ITEMNO`:
- No match + has a unit (`UNIT1`) + has a description → **INSERT** a new item.
- No match + no unit → **SKIP** (Accurate's `PARENTITEM` category-placeholder node, not a real
  product — see `docs/accurate-database-analysis.md` §6).
- Match, values differ → **UPDATE** (only the 3 reference columns).
- Match, values identical → **SKIP** ("no change" — this is what makes running the sync twice safe;
  verified in `tests/Feature/Accurate/AccurateSyncTest.php`).
- Any row-level exception → **ERROR**, logged, does not abort the rest of the batch.

## Sync lock

`sync_batches.status = RUNNING` acts as the lock: a new sync request returns the in-progress batch
instead of starting a second one. A `RUNNING` batch older than 10 minutes is treated as a crashed
process and reaped to `FAILED` before the lock check runs, so a real crash can't hold the lock
forever (`AccurateSyncService::reapStaleRunningBatches()`).
