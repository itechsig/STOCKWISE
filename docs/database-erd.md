# Database ERD — Accurate Sync Tables

Scope: only the tables added/changed for the Accurate sync foundation. For the full existing
Stockwise schema (60+ tables from prior phases), see `docs/erd.md`.

```
┌─────────────────────┐        ┌──────────────────────────┐
│  accurate_item       │        │  items (EXISTING table)  │
│  (staging, MySQL)    │        │  — schema unchanged,      │
│  ITEMNO (PK, varchar)│        │    3 columns added:       │
│  ITEMDESCRIPTION     │───┐    │  id (PK)                  │
│  UNIT1                │   │    │  code UNIQUE  ◄───────────┼─ matched against ITEMNO
│  QUANTITY            │   └───▶│  description               │  (99.98% exact match,
│  ONORDER             │        │  ... (all existing cols)   │   verified — not guessed)
│  SUSPENDED           │        │  accurate_synced_at (new)  │
│  ...(30+ more cols,  │        │  accurate_qty_onhand (new) │
│   see GDB_ANALYSIS.md│        │  accurate_qty_onorder(new) │
│   for the full list) │        └──────────────┬────────────┘
└──────────────────────┘                       │
                                                │ 1
┌──────────────────────┐                       │
│  sync_batches         │                       │
│  id (PK)              │                       │
│  sync_code UNIQUE      │                       │
│  source (=accurate)    │                       │
│  status (enum)          │                       │
│  started_at/finished_at │                       │
│  total/inserted/updated/│                       │
│   skipped/error_records │                       │
│  error_message          │                       │
│  created_by → users.id  │                       │
└──────────┬───────────┘                       │
           │ 1                                  │
           │                                     │
           │ N                                   │ (referenced conceptually via
           ▼                                     │  sync_logs.source_id = items.code,
┌──────────────────────┐                       │  no formal FK — source_id is a
│  sync_logs             │                       │  business key, not a Stockwise id)
│  id (PK)               │                       │
│  sync_batch_id → sync_batches.id (FK, cascade) │
│  entity (="item")       │
│  source_id (=ITEMNO)    │
│  action (enum)          │
│  status (enum)          │
│  message                │
│  old_data / new_data (JSON) │
│  created_at             │
└──────────────────────┘
```

## Notes

- `accurate_item` (and 19 sibling `accurate_*` staging tables) have no formal foreign keys to
  Stockwise's schema — they're an independent mirror, matched at query time by business key
  (`code`/`ITEMNO`), not by a database-level constraint. This is deliberate: Accurate's own schema
  barely uses FK constraints either (see `docs/accurate-database-analysis.md` §5), and a hard FK from
  a business table into a staging table that gets dropped/recreated on every sync would be fragile.
- `sync_logs.old_data`/`new_data` store only the fields actually touched by that sync (currently just
  the 3 `accurate_*` reference columns) — not a full row snapshot, to keep log rows small.
