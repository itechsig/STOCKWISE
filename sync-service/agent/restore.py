"""Restores a .GBK/.FBK backup into a TEMPORARY staging database via gbak -c.
Never writes to the active staging DB directly (agent/staging.py swaps a
validated temp DB in), never the source backup file, and never
D:\\GUDANGSIG2025.GDB (brief §9, §12, §43).
"""
import os
import subprocess

from agent.guards import assert_free_space, assert_staging_target_safe
from config.settings import FirebirdSettings, StagingSettings


class RestoreError(RuntimeError):
    pass


class IntegrityError(RuntimeError):
    pass


def gbak_env(user: str, password: str) -> dict:
    """Credentials go to gbak through ISC_USER/ISC_PASSWORD (read by
    fbclient), not -user/-pass on the command line — argv is visible to
    anyone who can list processes.
    """
    env = dict(os.environ)
    env["ISC_USER"] = user
    env["ISC_PASSWORD"] = password
    return env


def check_integrity(db_path: str, timeout_seconds: int = 900) -> None:
    """Read-only structural validation of a staging temp DB on the staging
    server (`gfix -v -full -n`: -n = no_update, report only). Any output
    means gfix found something — the copy is rejected.
    """
    assert_staging_target_safe(db_path)
    cmd = [StagingSettings.gfix_bin, "-v", "-full", "-n", FirebirdSettings.dsn(db_path)]
    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout_seconds,
            env=gbak_env(FirebirdSettings.user, FirebirdSettings.password),
        )
    except FileNotFoundError as exc:
        raise IntegrityError(f"gfix binary not found at '{StagingSettings.gfix_bin}': {exc}") from exc
    except subprocess.TimeoutExpired as exc:
        raise IntegrityError(f"gfix validation timed out after {timeout_seconds}s") from exc

    output = (result.stdout + result.stderr).strip()
    if result.returncode != 0 or output:
        raise IntegrityError(f"gfix validation failed (exit {result.returncode}): {output[:1000]}")


def restore_backup(backup_path: str, timeout_seconds: int = 900) -> str:
    """Restores `backup_path` into StagingSettings.new_database_path() (a
    fresh file — any leftover from an earlier failed run is removed first).
    Returns that temp DB path on success; raises RestoreError otherwise. The
    active staging DB is untouched either way.
    """
    FirebirdSettings.require_credentials()
    target_db = StagingSettings.new_database_path()
    assert_staging_target_safe(target_db)
    assert_free_space()
    os.makedirs(StagingSettings.tmp_path(), exist_ok=True)

    if os.path.exists(target_db):
        os.remove(target_db)

    cmd = [
        StagingSettings.gbak_bin,
        # -c (CREATE_DATABASE): fails rather than overwrite if the target
        # exists — the temp target is always fresh (removed above), so this
        # can never clobber the active staging DB by accident.
        "-c",
        backup_path,
        # Target must be the network DSN (host/port:path), not a bare local
        # path — confirmed against real gbak on this install: a bare path
        # makes gbak resolve the connection through its own local/embedded
        # provider instead of the running fb_inet_server on FirebirdSettings
        # host/port, which fails with "Your user name and password are not
        # defined" even for a valid stockwise_agent login. introspect.py
        # already connects via FirebirdSettings.dsn() for reads; the restore
        # target must match so both sides talk to the same running instance.
        FirebirdSettings.dsn(target_db),
    ]

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout_seconds,
            env=gbak_env(FirebirdSettings.user, FirebirdSettings.password),
        )
    except FileNotFoundError as exc:
        raise RestoreError(f"gbak binary not found at '{StagingSettings.gbak_bin}': {exc}") from exc
    except subprocess.TimeoutExpired as exc:
        _discard(target_db)
        raise RestoreError(f"gbak restore timed out after {timeout_seconds}s") from exc

    if result.returncode != 0:
        _discard(target_db)
        detail = (result.stderr or result.stdout or "").strip()
        raise RestoreError(f"gbak restore failed (exit {result.returncode}): {detail}")

    return target_db


def _discard(path: str) -> None:
    try:
        os.remove(path)
    except OSError:
        pass
