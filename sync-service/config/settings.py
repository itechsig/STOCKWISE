"""Connection settings for the Accurate <-> Stockwise sync service.

All credentials come from environment variables — never hardcoded, never guessed.
"""
import os
import sys

from dotenv import load_dotenv

# Load sync-service/.env (or the file AGENT_ENV_FILE names, e.g. .env.dummy —
# dummy and production config never share a file) before any settings below
# are read. python-dotenv does not override variables already set in the real
# environment (e.g. by a scheduler), so this is safe to call unconditionally.
load_dotenv(
    os.environ.get("AGENT_ENV_FILE")
    or os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env")
)

# The live Accurate database and its server. Hard-coded on purpose: these are
# what the dummy-mode guards refuse, whatever the env says.
PRODUCTION_DATABASE = r"D:\GUDANGSIG2025.GDB"
PRODUCTION_PORT = "3051"


class FirebirdSettings:
    """Connection to the STAGING Firebird DB the Agent restores .GBK backups
    into (agent/restore.py) — never the live production D:\\GUDANGSIG2025.GDB.
    host/port default to localhost/3050 because the staging DB is always a
    local Firebird instance next to the Agent, restored fresh on every tick.
    """

    host = os.environ.get("FIREBIRD_HOST", "localhost")
    port = os.environ.get("FIREBIRD_PORT", "3050")
    user = os.environ.get("FIREBIRD_USER")
    password = os.environ.get("FIREBIRD_PASSWORD")
    # Account used to READ the staging DB (validation + push). A direct file
    # copy of Accurate's .GDB keeps Accurate's ownership, and only its granted
    # users (e.g. GUEST, read-only SELECT) can read it — so reads use this
    # account when set. Restores (gbak -c) keep using FIREBIRD_USER.
    reader_user = os.environ.get("SOURCE_READER_USER") or user
    reader_password = os.environ.get("SOURCE_READER_PASSWORD") or password
    client_lib = os.environ.get(
        "FIREBIRD_CLIENT_LIB", r"D:\FirebirdLocal\Firebird_25\bin\fbclient.dll"
    )

    @classmethod
    def dsn(cls, database_path: str | None = None):
        # Deliberately NOT a separate FIREBIRD_DATABASE env var — there is
        # exactly one fact ("where does the staging DB live") and
        # StagingSettings is its one source of truth. restore.py writes
        # there; this must read from the same place, always, or a
        # STAGING_PATH override could silently restore to one file and then
        # try to read a stale/nonexistent different one. `database_path` is
        # only ever another file inside StagingSettings (the temp DB a new
        # restore lands in before it's validated and swapped in).
        return f"{cls.host}/{cls.port}:{database_path or StagingSettings.database_path()}"

    @classmethod
    def require_credentials(cls):
        if not cls.user or not cls.password:
            print(
                "ERROR: FIREBIRD_USER and FIREBIRD_PASSWORD environment variables are "
                "required. Refusing to guess or fall back to default credentials.",
                file=sys.stderr,
            )
            sys.exit(1)


class BackupSettings:
    """Where the Agent looks for Accurate's own .GBK backups (brief §8-9) —
    read-only source, never written/moved/deleted/renamed by the Agent.

    D:\\ on the real office server holds backups for several different
    databases side by side (GUDANGSIG2025, INTERN 1833, GUDANG2021, ...), not
    just Gudang's — the pattern below is the first of two safety layers
    (filename filter here; database identity/content check in
    agent/validate.py) that keep the Agent from ever picking up the wrong
    one. Only GUDANGSIG2025*.gbk is a candidate; everything else (including
    GUDANGSIG2025.GDB / GUDANG2021.GDB themselves — wrong extension, not a
    backup at all) is invisible to the scanner by construction.
    """

    path = os.environ.get("BACKUP_PATH", "D:\\")
    pattern = os.environ.get("BACKUP_PATTERN", "GUDANGSIG2025*.gbk")
    # Two scans this many seconds apart must report the same file size before
    # a backup is considered "done writing" and safe to restore (brief §15).
    stability_check_seconds = int(os.environ.get("STABILITY_CHECK_SECONDS", "120"))


class StagingSettings:
    """Where .GBK backups get restored to before the Agent reads them —
    never GUDANGSIG2025.GDB itself (brief §12).
    """

    path = os.environ.get("STAGING_PATH", r"D:\01.STOCKWISE\Staging")
    db_name = os.environ.get("STAGING_DB_NAME", "STAGING.GDB")
    gbak_bin = os.environ.get(
        "GBAK_BIN", r"D:\FirebirdLocal\Firebird_25\bin\gbak.exe"
    )
    gfix_bin = os.environ.get(
        "GFIX_BIN", r"D:\FirebirdLocal\Firebird_25\bin\gfix.exe"
    )
    # Refuse to start a copy unless the staging drive has this much free space.
    min_free_mb = int(os.environ.get("STAGING_MIN_FREE_MB", "2048"))

    @classmethod
    def database_path(cls):
        return os.path.join(cls.path, cls.db_name)

    # A new copy is restored into tmp/ first, validated there, and only then
    # swapped in as database_path() — a failed restore/validation never
    # touches the active staging DB. The previous active DB is kept as
    # prev_database_path() for rollback.
    @classmethod
    def tmp_path(cls):
        return os.path.join(cls.path, "tmp")

    @classmethod
    def new_database_path(cls):
        return os.path.join(cls.tmp_path(), "STAGING_NEW.GDB")

    @classmethod
    def prev_database_path(cls):
        return os.path.join(cls.path, "STAGING_PREV.GDB")


class SourceSettings:
    """Source of the direct GDB -> GDB copy (agent/live_copy.py):
    `gbak -b -g <source> stdout | gbak -c stdin <staging temp .GDB>` — a
    consistent MVCC snapshot, no lock, no garbage collection, source never
    written to, no intermediate backup file.

    SYNC_ENV=dummy   -> source must be a dummy DB: port 3051 and
                        D:\\GUDANGSIG2025.GDB are refused outright.
    SYNC_ENV=production -> additionally requires SOURCE_COPY_ENABLED=true
                        (off by default — separate approval required).

    SOURCE_METHOD:
      filecopy (default) — copy the .GDB FILE itself (agent/file_snapshot.py,
                 checksum-verified point-in-time image). No production
                 Firebird account needed; the file is only read.
      gbak     — online backup from the production server (needs
                 SOURCE_FIREBIRD_USER/PASSWORD of production).
    """

    env = os.environ.get("SYNC_ENV", "production").strip().lower()
    enabled = os.environ.get("SOURCE_COPY_ENABLED", "false").strip().lower() == "true"
    method = os.environ.get("SOURCE_METHOD", "filecopy").strip().lower()
    copy_attempts = int(os.environ.get("SOURCE_COPY_ATTEMPTS", "3"))
    host = os.environ.get("SOURCE_FIREBIRD_HOST", "localhost")
    port = os.environ.get("SOURCE_FIREBIRD_PORT", PRODUCTION_PORT)
    database = os.environ.get("SOURCE_DATABASE", PRODUCTION_DATABASE)
    user = os.environ.get("SOURCE_FIREBIRD_USER")
    password = os.environ.get("SOURCE_FIREBIRD_PASSWORD")
    # Backup side uses the SOURCE server's own gbak (2.5.4 in production);
    # the restore side uses StagingSettings.gbak_bin (2.5.9, staging server).
    gbak_bin = os.environ.get("SOURCE_GBAK_BIN", r"C:\Program Files\Firebird\Firebird_25\bin\gbak.exe")
    timeout_seconds = int(os.environ.get("SOURCE_COPY_TIMEOUT_SECONDS", "1200"))

    @classmethod
    def dsn(cls):
        return f"{cls.host}/{cls.port}:{cls.database}"


class ApiSettings:
    """Stockwise Laravel API the Agent pushes staged rows to — the Agent
    never connects to MySQL directly (brief §6).
    """

    url = os.environ.get("API_URL", "http://127.0.0.1:8001/api")
    token = os.environ.get("API_TOKEN")
    timeout_seconds = int(os.environ.get("API_TIMEOUT_SECONDS", "30"))
    # Only for the .../complete call — matches Laravel's own set_time_limit(300)
    # in AccurateIngestController::complete() (confirmed slow against real
    # data: ~90s for ~20k records derived from the existing npbg/ri tables).
    complete_timeout_seconds = int(os.environ.get("API_COMPLETE_TIMEOUT_SECONDS", "310"))

    @classmethod
    def require_token(cls):
        if not cls.token:
            print(
                "ERROR: API_TOKEN is required (see `php artisan stockwise:agent-token`). "
                "Refusing to guess or run unauthenticated.",
                file=sys.stderr,
            )
            sys.exit(1)


class AgentSettings:
    """Schedule + state/log locations for the Agent itself (brief §10, §14, §17)."""

    sync_times = [
        t.strip() for t in os.environ.get("SYNC_TIMES", "04:00,10:30,20:30").split(",") if t.strip()
    ]
    state_path = os.environ.get(
        "AGENT_STATE_PATH", r"D:\01.STOCKWISE\Config\agent_state.sqlite"
    )
    log_path = os.environ.get("AGENT_LOG_PATH", r"D:\01.STOCKWISE\Logs")
    # Held for the whole of every job, whatever its mode — a second job
    # started while one is still running exits immediately instead.
    lock_path = os.environ.get("AGENT_LOCK_PATH", r"D:\01.STOCKWISE\Config\agent.lock")


class MySQLSettings:
    host = os.environ.get("MYSQL_HOST", "127.0.0.1")
    port = int(os.environ.get("MYSQL_PORT", "3306"))
    database = os.environ.get("MYSQL_DATABASE", "stockwise")
    user = os.environ.get("MYSQL_USER", "root")
    password = os.environ.get("MYSQL_PASSWORD", "")
    # Match Laravel's migration default so accurate_* staging tables can be
    # JOINed against Stockwise's own tables without a COLLATE clause.
    collation = "utf8mb4_unicode_ci"

    @classmethod
    def connect_kwargs(cls):
        return dict(
            host=cls.host,
            port=cls.port,
            database=cls.database,
            user=cls.user,
            password=cls.password,
            charset="utf8mb4",
        )
