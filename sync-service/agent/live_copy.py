"""Direct GDB -> GDB copy of the live database into a staging temp file,
with no intermediate backup file:

    gbak -b -g <source> stdout  |  gbak -c stdin <staging>/tmp/STAGING_NEW.GDB

Why this and not a file copy (docs/sync-agent-security-report.md):
- Copy-Item/robocopy of an open .GDB reads pages mid-write — not consistent.
- A VSS snapshot is only crash-consistent (Firebird 2.5 has no VSS writer).
- nbackup -L writes the source's header (backup lock + .delta file next to
  it) and needs local, not TCP, access — it changes the production DB state.
- gbak -b reads everything inside ONE snapshot transaction (MVCC): the copy
  is consistent, nothing is locked, Accurate keeps working, and -g skips
  garbage collection so the source is only read. The result is a fresh,
  valid .GDB (logically identical, not byte-identical to the source).

Stopping the backup gbak process ends its snapshot transaction on the
source server; a partial staging file is always removed.
"""
import os
import subprocess
import tempfile
import time

from agent.guards import assert_free_space, assert_source_allowed, assert_staging_target_safe
from agent.file_snapshot import SnapshotUnstable, snapshot_file
from agent.procjob import JobError, bind_to_agent
from agent.restore import gbak_env
from config.settings import FirebirdSettings, SourceSettings, StagingSettings


class LiveCopyError(RuntimeError):
    pass


def copy_live_to_staging() -> str:
    """Returns the path of a new, not-yet-validated staging temp .GDB, or
    raises. The active staging DB is never touched here."""
    assert_source_allowed()
    FirebirdSettings.require_credentials()
    new_db = StagingSettings.new_database_path()
    assert_staging_target_safe(new_db)
    assert_free_space()
    os.makedirs(StagingSettings.tmp_path(), exist_ok=True)
    _discard(new_db)

    s = SourceSettings
    if s.method == "filecopy":
        # Direct copy of the .GDB file: read-only on the source, verified by
        # re-reading it (agent/file_snapshot.py). The copy is used as-is.
        try:
            snapshot_file(s.database, new_db, attempts=s.copy_attempts)
        except SnapshotUnstable as exc:
            raise LiveCopyError(str(exc)) from exc
        except OSError as exc:
            _discard(new_db)
            raise LiveCopyError(f"Could not copy {s.database}: {exc}") from exc
        return new_db

    backup_cmd = [s.gbak_bin, "-b", "-g", s.dsn(), "stdout"]
    restore_cmd = [StagingSettings.gbak_bin, "-c", "stdin", FirebirdSettings.dsn(new_db)]

    with tempfile.TemporaryFile() as backup_err:
        try:
            backup = subprocess.Popen(
                backup_cmd, stdout=subprocess.PIPE, stderr=backup_err, env=gbak_env(s.user, s.password)
            )
        except FileNotFoundError as exc:
            raise LiveCopyError(f"gbak not found at '{s.gbak_bin}': {exc}") from exc
        try:
            restore = subprocess.Popen(
                restore_cmd,
                stdin=backup.stdout,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                env=gbak_env(FirebirdSettings.user, FirebirdSettings.password),
            )
        except FileNotFoundError as exc:
            backup.kill()
            backup.wait()
            raise LiveCopyError(f"gbak not found at '{StagingSettings.gbak_bin}': {exc}") from exc
        backup.stdout.close()  # restore owns the read end now

        try:
            for p in (backup, restore):
                bind_to_agent(p.pid)
        except JobError as exc:
            for p in (backup, restore):
                p.kill()
                p.wait()
            _discard(new_db)
            raise LiveCopyError(f"Could not tie gbak to the Agent process, copy stopped: {exc}") from exc

        try:
            _, restore_err = restore.communicate(timeout=s.timeout_seconds)
            backup.wait(timeout=60)
        except subprocess.TimeoutExpired:
            for p in (backup, restore):
                p.kill()
                p.wait()
            _discard(new_db)
            raise LiveCopyError(f"Live copy timed out after {s.timeout_seconds}s — both gbak processes stopped.")

        backup_err.seek(0)
        backup_msg = backup_err.read().decode(errors="replace").strip()

    if backup.returncode != 0 or restore.returncode != 0:
        _discard(new_db)
        detail = backup_msg or restore_err.decode(errors="replace").strip()
        raise LiveCopyError(
            f"Live copy failed (backup exit {backup.returncode}, restore exit {restore.returncode}): {detail[:1000]}"
        )
    if not os.path.isfile(new_db) or os.path.getsize(new_db) == 0:
        _discard(new_db)
        raise LiveCopyError(f"gbak reported success but {new_db} is missing or empty.")

    return new_db


def _discard(path: str, wait_seconds: float = 15.0) -> None:
    """Removes a partial temp DB. After a killed restore the staging server
    can hold the file open for a few more seconds — retry until it lets go
    (the next run removes any leftover anyway before it starts)."""
    deadline = time.monotonic() + wait_seconds
    while True:
        try:
            os.remove(path)
            return
        except FileNotFoundError:
            return
        except OSError:
            if time.monotonic() >= deadline:
                return
            time.sleep(0.5)
