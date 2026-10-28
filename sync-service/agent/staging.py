"""Swaps a validated temp staging DB in as the active one, keeping the
previous active DB for rollback (brief §4: never replace the active staging
DB before the new copy passes validation; on failure keep the old one).

Only ever renames files inside StagingSettings.path. A rename fails (and the
active DB stays as it was) if anything still holds the file open — that is
the "no connection is locking staging" check.
"""
import os

from config.settings import StagingSettings


class SwapError(RuntimeError):
    pass


def swap_in(new_db: str) -> str:
    active = StagingSettings.database_path()
    prev = StagingSettings.prev_database_path()

    if not os.path.isfile(new_db):
        raise SwapError(f"New staging DB not found: {new_db}")

    had_active = os.path.exists(active)
    if had_active:
        try:
            os.replace(active, prev)
        except OSError as exc:
            raise SwapError(f"Active staging DB is in use, keeping it as-is: {exc}") from exc

    try:
        os.replace(new_db, active)
    except OSError as exc:
        if had_active:
            os.replace(prev, active)
        raise SwapError(f"Could not move new staging DB into place, previous one restored: {exc}") from exc

    return active


def rollback() -> str:
    """Puts STAGING_PREV.GDB back as the active staging DB (manual recovery)."""
    active = StagingSettings.database_path()
    prev = StagingSettings.prev_database_path()
    if not os.path.isfile(prev):
        raise SwapError(f"No previous staging DB to roll back to: {prev}")
    os.replace(prev, active)
    return active
