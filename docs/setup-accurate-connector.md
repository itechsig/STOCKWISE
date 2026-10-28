# Setup — Accurate Connector (Local Dev)

## 1. Firebird server

This dev machine no longer has Accurate installed (uninstalled), so a standalone Firebird 2.5.9 was
set up at `D:\FirebirdLocal\Firebird_25` purely to open the copied `GUDANGSIG2025.GDB`. It is **not**
a Windows service (installing one was blocked by this environment's permission model) — start it
manually before syncing:

```bash
D:\FirebirdLocal\Firebird_25\bin\fb_inet_server.exe -a
```

Verify it's listening: `netstat -an | findstr 3050` should show `0.0.0.0:3050 LISTENING`.

## 2. sync-service (Python)

```bash
cd sync-service
pip install fdb pymysql python-dotenv
cp .env.example .env   # then fill in FIREBIRD_PASSWORD
```

`.env` keys: `FIREBIRD_HOST` (use an IP, not `localhost` — see the Windows DNS-resolution gotcha in
`docs/sync-architecture.md`), `FIREBIRD_PORT`, `FIREBIRD_DATABASE`, `FIREBIRD_USER`,
`FIREBIRD_PASSWORD`, `FIREBIRD_CLIENT_LIB`, plus `MYSQL_*` for the target Stockwise database.

Test the connection + staging refresh standalone:
```bash
python -m sync.initial_sync
```

## 3. Laravel (backend)

`.env` keys (see `.env.example`):
```
ACCURATE_SYNC_SERVICE_PATH=D:/STOCKWISE/sync-service
ACCURATE_PYTHON_BIN=C:/path/to/python.exe
ACCURATE_FIREBIRD_CLIENT_DIR=D:/FirebirdLocal/Firebird_25/bin
```

Migrate: `php artisan migrate` (adds `items.accurate_synced_at`/`accurate_qty_onhand`/
`accurate_qty_onorder`, plus `sync_batches`/`sync_logs`). Reseed RBAC if upgrading an existing install
so the new `sync.accurate.view`/`sync.accurate.trigger` permissions exist:
```bash
php artisan db:seed --class=RbacSeeder --force
```

## 4. Run

```bash
php artisan serve --port=8001   # backend
npm run dev                      # frontend (in frontend/)
```

Log in, open **Sync Accurate** in the sidebar, click **Sync Accurate**.

## Troubleshooting

- **"Unable to connect to Accurate database"** — Firebird server isn't running, or
  `FIREBIRD_PASSWORD` in `sync-service/.env` is wrong. Test with `python -m sync.initial_sync`
  directly first.
- **Works from a terminal but fails via the API** — check `ACCURATE_FIREBIRD_CLIENT_DIR` is set; the
  shell that started `php artisan serve` may hand down a `PATH` `fbclient.dll` can't use (a real,
  observed issue on this machine with Git Bash — see `docs/sync-architecture.md`).
- **Request times out around 30s** — `SyncController::store()` already raises PHP's execution time
  limit for this one action; if it's still too slow, check `sync_logs`/`sync_batches.duration` for
  which phase (staging vs item matching) is slow.
