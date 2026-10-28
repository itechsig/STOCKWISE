# API — Accurate Sync

All endpoints require Sanctum auth (`Authorization: Bearer <token>`) plus the listed permission.

Fresh data only ever arrives via the office Sync Agent's own push, on its 04:00/10:30/20:30 schedule
— see `docs/sync-architecture.md` and the separate `POST /api/agent/sync/*` routes below (gated by the
`agent:sync` Sanctum ability, not a human permission — the Agent is not a user).

## `POST /api/sync/accurate`

Permission: `sync.accurate.trigger`.

Re-runs the mapping/upsert logic (`AccurateSyncService::finishFromStaging()`) against whatever is
**currently** sitting in the `accurate_*` staging tables. Does **not** talk to Firebird, the Agent, or
refresh staging in any way — useful for re-processing after a Stockwise-side bug fix without waiting
for the Agent's next tick. If nothing has been pushed yet, this is a no-op (`total_records: 0`,
`status: SUCCESS`). Synchronous — for ~9,600 items this takes roughly 35s. Returns the batch summary.

Response `201`:
```json
{ "data": {
    "id": 5, "sync_code": "SYNC-20260912-005", "source": "accurate", "status": "SUCCESS",
    "started_at": "...", "finished_at": "...", "duration_seconds": 49,
    "total_records": 9600, "inserted_records": 0, "updated_records": 0,
    "skipped_records": 9600, "error_records": 0, "error_message": null
} }
```

Response `502` (Firebird unreachable, bad credentials, sync-service crashed) — `status: "FAILED"`,
`error_message` carries the real underlying error (never a generic "something went wrong").

If a sync is already `RUNNING`, returns that in-progress batch instead of starting a second one
(sync lock).

## `GET /api/sync/status`

Permission: `sync.accurate.view`. Latest batch's summary (or `data: null` if never run).

## `GET /api/sync/history?page=1&per_page=20`

Permission: `sync.accurate.view`. Paginated list of past batches, newest first.

## `GET /api/sync/history/{id}`

Permission: `sync.accurate.view`. One batch's summary plus up to 500 of its `sync_logs` rows
(`entity`, `source_id`, `action`, `status`, `message`, `old_data`, `new_data`).

## Agent ingest — `POST /api/agent/sync/*` (office Agent only)

Ability: `agent:sync` (`php artisan stockwise:agent-token`), **not** a human permission. Called only by
`sync-service/agent/` — see `docs/sync-architecture.md`.

- `POST /api/agent/sync/sessions` — start (or reuse, if one is already `RUNNING`) a sync batch. Returns
  `{id, sync_code, status, is_new_session}`.
- `POST /api/agent/sync/sessions/{id}/tables/{table}` — push one chunk of one whitelisted table
  (`config('accurate.mirror_tables')`): `{columns: [{name,type,length,nullable}], primary_key: [...],
  rows: [...], is_first_chunk}`. The first chunk (re)creates the `accurate_<table>` MySQL table;
  later chunks just insert. Table/column names and types are checked against a strict whitelist
  (`App\Services\Accurate\StagingTableWriter`) — never built from raw SQL in the payload.
- `POST /api/agent/sync/sessions/{id}/complete` — staging is done; runs
  `AccurateSyncService::finishFromStaging()` (the same logic `POST /api/sync/accurate` re-runs
  manually) and returns the finished batch summary.
- `POST /api/agent/sync/sessions/{id}/fail` — the Agent couldn't get this far (backup restore or
  Firebird staging read failed) — `{message}` marks the batch `FAILED` with that message, without ever
  reaching Laravel's mapping step.
