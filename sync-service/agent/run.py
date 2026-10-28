"""Sync Agent entrypoint — scan -> stability check -> restore -> read ->
push, on its own schedule (brief §3-17). Runs independently at the office;
never shells out from or is shelled out to by Laravel (docs/sync-architecture.md).

    python -m agent.run              # loop forever, waking at SYNC_TIMES
    python -m agent.run --sync-now   # run one pass immediately, then exit (brief §41)
    python -m agent.run --dry-run    # scan + log only, no restore/push/state changes

Live-database modes (direct GDB -> GDB copy, see agent/live_copy.py; need
SYNC_ENV=dummy with a dummy source, or SYNC_ENV=production + SOURCE_COPY_ENABLED=true):

    python -m agent.run --full       # live GDB -> temp .GDB -> integrity + validate -> swap -> push
    python -m agent.run --snapshot   # same, but stop after the swap (no push)
    python -m agent.run --sync-only  # push the current staging DB as-is (no new copy)

Every mode restores into a temp DB, validates it, and only then swaps it in
as the active staging DB (agent/staging.py); every mode holds one global
lock (agent/lock.py) so no two jobs ever run at the same time.

Not installed as a Windows Service yet (brief §42) — run as a plain
foreground process (or under a simple process supervisor) until this has
been observed stable over real scheduled ticks.
"""
import argparse
import base64
import datetime
import decimal
import logging
import os
import sys
import time
from logging.handlers import RotatingFileHandler

sys.path.insert(0, ".")

from agent import scanner
from agent import state as state_mod
from agent.api_client import StockwiseApiClient
from agent.lock import AlreadyRunning, single_instance
from agent.restore import restore_backup
from agent.scheduler import next_run_at, seconds_until
from agent import preflight
from agent import status as agent_status
from agent.guards import assert_source_allowed
from agent.live_copy import copy_live_to_staging
from agent.restore import check_integrity
from agent.staging import swap_in
from agent.validate import validate_gudang_staging
from config.settings import AgentSettings, BackupSettings, SourceSettings, StagingSettings
from firebird import introspect as fb
from mapping.tables import DERIVED_TABLES, MIRROR_TABLES, OPTIONAL_MIRROR_TABLES
from mapping.type_mapping import firebird_column_to_mysql_type

logger = logging.getLogger("agent")


def setup_logging() -> None:
    os.makedirs(AgentSettings.log_path, exist_ok=True)
    logger.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)s %(levelname)s: %(message)s")

    agent_handler = RotatingFileHandler(
        os.path.join(AgentSettings.log_path, "agent.log"), maxBytes=5_000_000, backupCount=5, encoding="utf-8"
    )
    agent_handler.setFormatter(fmt)
    logger.addHandler(agent_handler)

    error_handler = RotatingFileHandler(
        os.path.join(AgentSettings.log_path, "error.log"), maxBytes=5_000_000, backupCount=5, encoding="utf-8"
    )
    error_handler.setLevel(logging.ERROR)
    error_handler.setFormatter(fmt)
    logger.addHandler(error_handler)

    console = logging.StreamHandler()
    console.setFormatter(fmt)
    logger.addHandler(console)


def _sync_log_path() -> str:
    return os.path.join(AgentSettings.log_path, "sync.log")


def _append_sync_log(filename: str, status: str, error: str | None) -> None:
    with open(_sync_log_path(), "a", encoding="utf-8") as f:
        f.write(f"{datetime.datetime.now().isoformat()} backup={filename} status={status} error={error or '-'}\n")


def _json_safe(value):
    """Firebird rows can carry date/datetime/Decimal/bytes — none of those
    are JSON-serializable as-is (requests' json= uses json.dumps).
    """
    if value is None:
        return None
    if isinstance(value, (datetime.datetime, datetime.date, datetime.time)):
        return value.isoformat()
    if isinstance(value, decimal.Decimal):
        # as text: exact (a float would turn 0.1 into 0.1000000000000000055…)
        return format(value, "f")
    if isinstance(value, bytes):
        try:
            return value.decode("utf-8")
        except UnicodeDecodeError:
            return base64.b64encode(value).decode("ascii")
    return value


def prepare_staging(backup_path: str) -> str:
    """.GBK backup -> temp DB -> validate -> swap in as the active staging DB."""
    new_db = restore_backup(backup_path)
    logger.info("Restored %s -> %s", os.path.basename(backup_path), new_db)
    return finalize_staging(new_db)


def finalize_staging(new_db: str, gfix: bool = True) -> str:
    """Integrity check + identity validation of a temp staging DB, then swap
    it in. Any failure before the swap leaves the active staging DB exactly
    as it was (the rejected temp DB stays in tmp/ for inspection and is
    replaced by the next run).

    gfix=False for a direct file copy of Accurate's .GDB: gfix may only be run
    by the database owner/SYSDBA, which the Agent is not on Accurate's file.
    Its integrity comes from the copy being checksum-identical to the source
    (agent/file_snapshot.py) plus every mirrored table being read in full.
    """
    if gfix:
        check_integrity(new_db)
        logger.info("New staging copy passed gfix validation.")
    else:
        logger.info("Direct file copy: checksum-verified identical to the source (gfix skipped: not the DB owner).")

    fb_conn = fb.connect(new_db)
    try:
        # Second safety layer (brief §9 STEP 7, TEST 7) — the filename
        # filter alone (GUDANGSIG2025*.gbk) is not trusted, and the new copy
        # is checked BEFORE it replaces the active staging DB.
        validate_gudang_staging(fb_conn.cursor(), fb.fetch_columns, fb.fetch_rows)
    finally:
        fb_conn.close()
    logger.info("New staging copy validated: looks like Accurate's Gudang database.")

    staging_db = swap_in(new_db)
    logger.info("Swapped new copy in as %s (previous kept as STAGING_PREV.GDB)", staging_db)
    return staging_db


def push_staging(label: str) -> dict | None:
    """Reads the active staging DB and pushes every mirrored table to the
    API. Returns the completed batch, or None if another sync is already
    RUNNING on the API side. Raises on failure (after reporting it to the API).
    """
    client = StockwiseApiClient()
    batch_id = None
    try:
        fb_conn = fb.connect()
        try:
            cur = fb_conn.cursor()

            # Runs BEFORE start_session() on purpose: a validation failure
            # never touches the API at all, so it never leaves a stray
            # RUNNING batch behind.
            validate_gudang_staging(cur, fb.fetch_columns, fb.fetch_rows)

            session = client.start_session()
            batch_id = session["id"]
            if not session.get("is_new_session", True):
                logger.info("A sync is already RUNNING (batch %s) — skipping this tick, will retry next.", batch_id)
                return None

            for table in MIRROR_TABLES:
                _push_table(cur, table, client, batch_id)
            for table in OPTIONAL_MIRROR_TABLES:
                try:
                    _push_table(cur, table, client, batch_id)
                except Exception as exc:  # noqa: BLE001 — optional table must never fail the sync
                    logger.warning("Optional table %s skipped: %s", table, exc)
            for table in DERIVED_TABLES:
                try:
                    _push_derived(cur, table, client, batch_id)
                except Exception as exc:  # noqa: BLE001 — derived table must never fail the sync
                    logger.warning("Derived table %s skipped: %s", table, exc)
        finally:
            fb_conn.close()

        batch = client.complete(batch_id)
        logger.info("Sync complete for %s: batch %s status=%s", label, batch.get("sync_code"), batch.get("status"))
    except Exception as exc:
        if batch_id is not None:
            try:
                client.fail(batch_id, str(exc)[:2000])
            except Exception:
                logger.exception("Could not report the failure back to the API for batch %s either.", batch_id)
        raise

    # A batch that ended FAILED on the Laravel side (nothing applied, or its
    # worker died) is a failed sync, not a successful one: raising here makes
    # run_once() mark the backup FAILED so the next tick retries it, instead
    # of recording SUCCESS and never looking at that backup again. The batch
    # is already FAILED, so there is nothing to report back.
    if batch.get("status") == "FAILED":
        raise SyncBatchFailed(
            f"Batch {batch.get('sync_code')} FAILED: {batch.get('error_message') or 'lihat Sync History untuk detail'}"
        )
    return batch


class SyncBatchFailed(RuntimeError):
    pass


def run_once(dry_run: bool = False) -> bool:
    """One full tick from Accurate's own .GBK backups. Returns True if a sync actually ran (success or
    failure), False if there was nothing to do (already processed, still
    being written, or a sync is already in progress elsewhere).
    """
    state = state_mod.AgentState()

    files = scanner.list_backups()
    if not files:
        logger.info("No .GBK backups found in %s", BackupSettings.path)
        return False

    newest_synced = state.latest_success_mtime()
    candidate = scanner.pick_candidate(files, state.is_success, newer_than=newest_synced)
    if candidate is None:
        logger.info(
            "No backup newer than the last successfully synced one (%s). Nothing to do.",
            time.ctime(newest_synced) if newest_synced else "none yet",
        )
        return False

    logger.info(
        "Candidate backup: %s (%.1f MB, modified %s)",
        candidate.filename, candidate.size / 1_048_576, time.ctime(candidate.modified_at),
    )

    if dry_run:
        logger.info("[dry-run] Would check stability and process %s — stopping here.", candidate.filename)
        return False

    if not scanner.wait_until_stable(candidate.path, BackupSettings.stability_check_seconds):
        logger.info("%s is still being written (size changed) — will re-check next tick.", candidate.filename)
        return False

    state.mark_processing(candidate.filename, candidate.size, candidate.modified_at)

    try:
        prepare_staging(candidate.path)
        batch = push_staging(candidate.filename)
        if batch is None:
            return False
        state.mark_success(candidate.filename)
        _append_sync_log(candidate.filename, batch.get("status", "UNKNOWN"), None)
        return True

    except Exception as exc:
        logger.exception("Sync failed for %s", candidate.filename)
        state.mark_failed(candidate.filename, str(exc))
        _append_sync_log(candidate.filename, "FAILED", str(exc))
        return True


def uses_gdb_file_copy() -> bool:
    """True when scheduled/default runs should copy Accurate's .GDB directly
    (SOURCE_COPY_ENABLED=true + SOURCE_METHOD=filecopy) instead of restoring
    Accurate's own .gbk backups."""
    return SourceSettings.enabled and SourceSettings.method == "filecopy"


def default_tick() -> bool:
    """One default sync (--sync-now / loop mode): GDB file copy -> staging ->
    sync when enabled; otherwise the legacy .gbk path (always 'ok' exit, as before)."""
    if uses_gdb_file_copy():
        return run_live(push=True)
    return run_once() or True


def run_live(push: bool) -> bool:
    """--full (push=True) / --snapshot (push=False): direct consistent GDB ->
    GDB copy of the live database into staging temp, then the same
    integrity -> validate -> swap as run_once. Returns True if the job completed.
    """
    label = "LIVE-" + datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    try:
        assert_source_allowed()
        problems = preflight.check()
        if problems:
            raise RuntimeError("Preflight failed, live copy NOT started: " + "; ".join(problems))

        started = time.monotonic()
        try:
            new_db = copy_live_to_staging()
        finally:
            after = preflight.check()
            if after:
                logger.error("Preflight AFTER live copy reports problems: %s", "; ".join(after))
        logger.info(
            "Live copy done in %.0fs: %s (%.1f MB)",
            time.monotonic() - started, new_db, os.path.getsize(new_db) / 1_048_576,
        )
        finalize_staging(new_db, gfix=SourceSettings.method != "filecopy")
        if not push:
            _append_sync_log(label, "STAGED", None)
            return True
        batch = push_staging(label)
        if batch is None:
            return False
        _append_sync_log(label, batch.get("status", "UNKNOWN"), None)
        return True
    except Exception as exc:
        logger.exception("Live %s job failed (%s)", "full" if push else "snapshot", label)
        _append_sync_log(label, "FAILED", str(exc))
        return False


def run_sync_only() -> bool:
    """--sync-only: push whatever the active staging DB holds now (e.g. the
    21:00 snapshot, pushed at 06:30) — no new copy is taken.
    """
    staged_at = datetime.datetime.fromtimestamp(os.path.getmtime(StagingSettings.database_path()))
    label = "STAGING-" + staged_at.strftime("%Y%m%d_%H%M%S")
    try:
        batch = push_staging(label)
        if batch is None:
            return False
        _append_sync_log(label, batch.get("status", "UNKNOWN"), None)
        return True
    except Exception as exc:
        logger.exception("Sync-only job failed for %s", label)
        _append_sync_log(label, "FAILED", str(exc))
        return False


def _derived_rows(cur, table: str) -> tuple[list[dict], list[str], list[dict]]:
    """Rows of a DERIVED_TABLES entry, computed on the staging copy by Accurate's
    own (read-only, selectable) stored procedures. Returns (columns, primary key, rows)."""
    if table == "SNLINE_BYITEMHIST":
        cur.execute("SELECT DISTINCT ITEMHISTID FROM SNHISTORY")
        ids = [r[0] for r in cur.fetchall()]
        rows = []
        for item_hist_id in ids:
            cur.execute("SELECT SNLINE FROM VIEW_SN_BYITEMHIST(?, 0)", (item_hist_id,))
            got = cur.fetchone()
            line = (got[0] or "") if got else ""
            if line != "":
                rows.append({"ITEMHISTID": item_hist_id, "SNLINE": line})
        columns = [
            {"name": "ITEMHISTID", "type": "INT", "length": None, "nullable": False},
            {"name": "SNLINE", "type": "TEXT", "length": None, "nullable": True},
        ]
        return columns, ["ITEMHISTID"], rows
    raise ValueError(f"Unknown derived table {table}")


def _push_derived(cur, table: str, client: StockwiseApiClient, batch_id: int, chunk_size: int = 1000) -> None:
    columns, primary_key, rows = _derived_rows(cur, table)
    if not rows:
        client.push_table(batch_id, table, columns, [], primary_key, is_first_chunk=True)
    for i in range(0, len(rows), chunk_size):
        client.push_table(batch_id, table, columns, rows[i:i + chunk_size], primary_key, is_first_chunk=(i == 0))
    logger.info("Pushed derived %s: %d row(s)", table, len(rows))


def _push_table(cur, table: str, client: StockwiseApiClient, batch_id: int, chunk_size: int = 1000) -> None:
    columns = fb.fetch_columns(cur, table)
    if not columns:
        logger.warning("Table %s not found in staging DB — skipping.", table)
        return

    primary_key = fb.fetch_primary_key(cur, table)
    api_columns = [{"name": c["name"], **firebird_column_to_mysql_type(c)} for c in columns]
    column_names = [c["name"] for c in columns]

    total = 0
    is_first = True
    chunk: list[dict] = []

    for row in fb.fetch_rows(cur, table, columns):
        chunk.append({name: _json_safe(v) for name, v in zip(column_names, row)})
        if len(chunk) >= chunk_size:
            client.push_table(batch_id, table, api_columns, chunk, primary_key, is_first_chunk=is_first)
            total += len(chunk)
            is_first = False
            chunk = []

    if chunk or is_first:
        # is_first still True with an empty chunk means the table has zero
        # rows right now — still push once so it gets (re)created empty.
        client.push_table(batch_id, table, api_columns, chunk, primary_key, is_first_chunk=is_first)
        total += len(chunk)

    logger.info("Pushed %s: %d row(s)", table, total)


def main() -> None:
    parser = argparse.ArgumentParser(description="Stockwise Sync Agent")
    parser.add_argument("--sync-now", action="store_true", help="Run one pass immediately and exit")
    parser.add_argument("--dry-run", action="store_true", help="Scan only, no restore/push")
    parser.add_argument("--full", action="store_true", help="Live GDB snapshot -> staging -> push, then exit")
    parser.add_argument("--snapshot", action="store_true", help="Live GDB snapshot -> staging only, then exit")
    parser.add_argument("--sync-only", action="store_true", help="Push the current staging DB, then exit")
    parser.add_argument("--preflight", action="store_true", help="Read-only health check of Accurate/Firebird/staging, then exit")
    args = parser.parse_args()

    if args.preflight:
        problems = preflight.check()
        print("\n".join(f"PROBLEM: {p}" for p in problems) or "OK: services running, ports listening, disk space fine")
        sys.exit(1 if problems else 0)

    setup_logging()

    if args.dry_run:
        run_once(dry_run=True)
        return

    # Live-GDB modes exit non-zero on failure so Task Scheduler shows it.
    # --sync-now (what the existing scheduled task runs) follows the
    # configured source: the direct .GDB file copy when enabled (user
    # decision 2026-09-26: no more Accurate .gbk backups), otherwise the
    # legacy .gbk path with its original always-0 exit.
    jobs = {
        "full": lambda: run_live(push=True),
        "snapshot": lambda: run_live(push=False),
        "sync_only": run_sync_only,
        "sync_now": default_tick,
    }
    chosen = [name for name in jobs if getattr(args, name)]
    if len(chosen) > 1:
        parser.error("choose only one of --full / --snapshot / --sync-only / --sync-now")
    if chosen:
        mode = chosen[0]
        try:
            with single_instance(AgentSettings.lock_path):
                # Status file for the web app only (agent/status.py); written
                # while holding the lock, so it always describes the job that
                # actually runs — a refused second job never overwrites it.
                agent_status.mark_started(mode)
                started_at = datetime.datetime.now().astimezone().isoformat()
                result = "failed"
                try:
                    ok = jobs[mode]()
                    result = "ok" if ok else "failed"
                finally:
                    agent_status.mark_finished(mode, result, started_at)
        except AlreadyRunning:
            logger.warning("Another Sync Agent job is still running — this one exits without doing anything.")
            sys.exit(2)
        sys.exit(0 if ok else 1)

    logger.info("Sync Agent starting. Schedule: %s", ", ".join(AgentSettings.sync_times))
    while True:
        target = next_run_at(datetime.datetime.now())
        wait = seconds_until(target, datetime.datetime.now())
        logger.info("Next run at %s (in %.0f minute(s))", target.isoformat(), wait / 60)
        time.sleep(wait)
        try:
            with single_instance(AgentSettings.lock_path):
                default_tick()
        except AlreadyRunning:
            logger.warning("Another Sync Agent job is still running — skipping this tick.")
        except Exception:
            logger.exception("Unexpected error during scheduled run — will retry next tick.")


if __name__ == "__main__":
    main()
