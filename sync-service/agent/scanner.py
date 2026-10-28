"""Scans BACKUP_PATH for Accurate's own .GBK backups (brief §8-9) and checks
whether the newest not-yet-processed one has finished being written (brief
§15). Accurate produces these backups itself — the Agent only ever reads
them; nothing here deletes, moves, renames, or modifies a backup file.
"""
import glob
import os
import time
from dataclasses import dataclass
from typing import Callable, List, Optional

from config.settings import BackupSettings


@dataclass(frozen=True)
class BackupFile:
    path: str
    filename: str
    size: int
    modified_at: float


def list_backups(directory: str | None = None, pattern: str | None = None) -> List[BackupFile]:
    directory = directory or BackupSettings.path
    pattern = pattern or BackupSettings.pattern

    # Windows filesystems are case-insensitive in practice, but glob() isn't
    # guaranteed to be — match both cases explicitly rather than rely on it.
    candidates = set(glob.glob(os.path.join(directory, pattern)))
    candidates |= set(glob.glob(os.path.join(directory, pattern.upper())))
    candidates |= set(glob.glob(os.path.join(directory, pattern.lower())))

    files: dict[str, BackupFile] = {}
    for path in candidates:
        try:
            stat = os.stat(path)
        except OSError:
            continue  # vanished between glob() and stat() — not fatal, just skip it this scan
        files[os.path.abspath(path)] = BackupFile(
            path=path,
            filename=os.path.basename(path),
            size=stat.st_size,
            modified_at=stat.st_mtime,
        )

    return sorted(files.values(), key=lambda f: f.modified_at, reverse=True)


def pick_candidate(
    files: List[BackupFile],
    is_already_done: Callable[[str], bool],
    newer_than: float | None = None,
) -> Optional[BackupFile]:
    """Newest backup whose filename isn't already marked SUCCESS in state —
    and, when `newer_than` is given (mtime of the newest backup already synced
    successfully), strictly newer than that one.

    Without that second rule an OLDER backup that simply was never processed
    (e.g. "Thursday.gbk" after "Friday.gbk" had already been synced) was
    picked next and rolled STOCKWISE back to older data (seen 2026-09-26).
    "Strictly newer" also skips Accurate's same-second duplicates (the daily
    and monthly copies of one backup, e.g. "Friday.gbk" / "092026.gbk").
    """
    for f in files:
        if newer_than is not None and f.modified_at <= newer_than:
            return None  # files are newest-first: nothing after this is newer either
        if not is_already_done(f.filename):
            return f
    return None


def wait_until_stable(
    path: str,
    interval_seconds: int,
    checks: int = 2,
    sleep: Callable[[float], None] = time.sleep,
    stat_fn: Callable[[str], os.stat_result] = os.stat,
) -> bool:
    """True once `checks` consecutive size readings, `interval_seconds`
    apart, agree — i.e. Accurate has stopped writing this backup. False
    (not an error) if the size is still changing, or the file disappears
    mid-check (e.g. it was itself a stale/incomplete leftover cleaned up
    elsewhere).
    """
    try:
        last_size = stat_fn(path).st_size
    except OSError:
        return False

    for _ in range(checks - 1):
        sleep(interval_seconds)
        try:
            current_size = stat_fn(path).st_size
        except OSError:
            return False
        if current_size != last_size:
            return False
        last_size = current_size

    return True
