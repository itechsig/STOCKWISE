"""Hard safety checks run before anything is copied, restored or swapped.
They only raise — they never fix anything up or fall back to a default.
"""
import os
import shutil

from config.settings import (
    PRODUCTION_DATABASE,
    PRODUCTION_PORT,
    ApiSettings,
    FirebirdSettings,
    SourceSettings,
    StagingSettings,
)

# The real staging folder and the real Laravel API — a dummy run must never
# write to either (it would replace real staging / accurate_* rows with fake data).
REAL_STAGING_PATH = r"D:\01.STOCKWISE\Staging"
REAL_API_HOSTS = ("127.0.0.1:8001", "localhost:8001")


class GuardError(RuntimeError):
    pass


def _same_path(a: str, b: str) -> bool:
    return os.path.normcase(os.path.abspath(a)) == os.path.normcase(os.path.abspath(b))


def is_production_database(path: str) -> bool:
    return _same_path(path, PRODUCTION_DATABASE)


def assert_source_allowed() -> None:
    s = SourceSettings
    if s.env not in ("dummy", "production"):
        raise GuardError(f"SYNC_ENV must be 'dummy' or 'production', got '{s.env}'.")

    if s.env == "dummy":
        # Port only matters for SOURCE_METHOD=gbak (filecopy never connects to the source).
        if s.method == "gbak" and str(s.port).strip() == PRODUCTION_PORT:
            raise GuardError("SYNC_ENV=dummy refuses source port 3051 (production Firebird).")
        if is_production_database(s.database):
            raise GuardError(f"SYNC_ENV=dummy refuses {PRODUCTION_DATABASE} as a source.")
        if _same_path(StagingSettings.path, REAL_STAGING_PATH):
            raise GuardError("SYNC_ENV=dummy refuses the real staging folder — set STAGING_PATH to a dummy folder.")
        if any(h in ApiSettings.url for h in REAL_API_HOSTS):
            raise GuardError("SYNC_ENV=dummy refuses the real STOCKWISE API — set API_URL to a mock/test API.")
    elif not s.enabled:
        raise GuardError("Live GDB copy is disabled (SOURCE_COPY_ENABLED is not 'true').")

    if s.method not in ("filecopy", "gbak"):
        raise GuardError(f"SOURCE_METHOD must be 'filecopy' or 'gbak', got '{s.method}'.")
    if s.method == "gbak" and (not s.user or not s.password):
        raise GuardError("SOURCE_FIREBIRD_USER / SOURCE_FIREBIRD_PASSWORD are required for SOURCE_METHOD=gbak.")
    if s.method == "filecopy" and not os.path.isfile(s.database):
        raise GuardError(f"Source database file not found: {s.database}")


def assert_staging_target_safe(path: str) -> None:
    """A restore/copy target must be a file directly inside staging tmp/, on
    the staging server — never the production port or database."""
    if str(FirebirdSettings.port).strip() == PRODUCTION_PORT:
        raise GuardError("Staging FIREBIRD_PORT is 3051 (production) — refusing to write.")
    if is_production_database(path):
        raise GuardError(f"Refusing to write to {PRODUCTION_DATABASE}.")
    if not _same_path(os.path.dirname(os.path.abspath(path)), StagingSettings.tmp_path()):
        raise GuardError(f"Refusing to write outside {StagingSettings.tmp_path()}: {path}")


def assert_free_space() -> None:
    os.makedirs(StagingSettings.path, exist_ok=True)
    free_mb = shutil.disk_usage(StagingSettings.path).free // (1024 * 1024)
    if free_mb < StagingSettings.min_free_mb:
        raise GuardError(
            f"Only {free_mb} MB free on the staging drive (need {StagingSettings.min_free_mb} MB) — not copying."
        )
