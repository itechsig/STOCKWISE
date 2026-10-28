# Sync Flow

```
POST /api/sync/accurate  (permission: sync.accurate.trigger)
        │
        ▼
AccurateSyncService::run()
        │
        ├─ reap any RUNNING batch older than 10 min → FAILED (crash recovery)
        ├─ if a RUNNING batch already exists → return it (sync lock, no new run)
        │
        ▼
CREATE sync_batches row (status=RUNNING, sync_code=SYNC-YYYYMMDD-NNN)
        │
        ▼
refreshStaging()
        │  Process::run([python, -m, sync.initial_sync], cwd=sync-service/)
        │
        ├─ FAILS (Firebird unreachable / bad creds / timeout)
        │       → sync_batches.status = FAILED, error_message = real exception text
        │       → return early, no crash, no fake success
        │
        ▼ SUCCEEDS (all 20 accurate_* tables refreshed)
syncItems(batch)
        │  for each row in accurate_item:
        │    validate (ITEMNO not empty)
        │    match against items.code
        │    INSERT / UPDATE / SKIP / ERROR  →  sync_logs row per item
        │
        ▼
UPDATE sync_batches: finished_at, total/inserted/updated/skipped/error counts,
status = SUCCESS (0 errors) | PARTIAL (some errors, some success) | FAILED (all errored)
        │
        ▼
Response: { data: { ...batch summary } }  (HTTP 201, or 502 if the Firebird step failed)
```

## Reading results

- `GET /api/sync/status` — latest batch summary (polled by the frontend every 2s while RUNNING).
- `GET /api/sync/history` — paginated list of past batches.
- `GET /api/sync/history/{id}` — one batch's summary + up to 500 of its `sync_logs` rows.

## What a user sees

1. Dashboard → small "Accurate Sync" card (status badge + item count + last-sync time), links to the
   Sync page.
2. Sync Accurate page → big status card + "Sync Accurate" button (permission-gated).
3. Sync History page → table of every run, click a row for the per-item log detail.
