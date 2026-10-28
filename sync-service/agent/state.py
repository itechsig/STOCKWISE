"""Processed-backup state (brief §14): filename, file_size, last_modified,
hash, processed_at, status, error_message. Backed by a small SQLite file so
the Agent survives restarts without re-processing an already-SUCCESS backup,
and a FAILED backup is retried on the next scheduled tick rather than looped
on immediately (brief §16).
"""
import hashlib
import os
import sqlite3
from contextlib import closing
from datetime import datetime, timezone

from config.settings import AgentSettings

PENDING = "PENDING"
PROCESSING = "PROCESSING"
SUCCESS = "SUCCESS"
FAILED = "FAILED"

_SCHEMA = """
CREATE TABLE IF NOT EXISTS processed_backups (
    filename TEXT PRIMARY KEY,
    file_size INTEGER NOT NULL,
    last_modified TEXT NOT NULL,
    hash TEXT,
    status TEXT NOT NULL,
    processed_at TEXT,
    error_message TEXT
)
"""


class AgentState:
    def __init__(self, db_path: str | None = None):
        self.db_path = db_path or AgentSettings.state_path
        directory = os.path.dirname(self.db_path)
        if directory:
            os.makedirs(directory, exist_ok=True)
        with closing(self._connect()) as conn:
            conn.execute(_SCHEMA)
            conn.commit()

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        return conn

    def get(self, filename: str) -> sqlite3.Row | None:
        with closing(self._connect()) as conn:
            return conn.execute(
                "SELECT * FROM processed_backups WHERE filename = ?", (filename,)
            ).fetchone()

    def is_success(self, filename: str) -> bool:
        row = self.get(filename)
        return row is not None and row["status"] == SUCCESS

    def latest_success_mtime(self) -> float | None:
        """File mtime (epoch seconds) of the newest backup ever synced
        successfully — nothing older than this may be synced again."""
        with closing(self._connect()) as conn:
            rows = conn.execute(
                "SELECT last_modified FROM processed_backups WHERE status = ?", (SUCCESS,)
            ).fetchall()
        stamps = [datetime.fromisoformat(r["last_modified"]).timestamp() for r in rows if r["last_modified"]]
        return max(stamps) if stamps else None

    def mark_processing(self, filename: str, size: int, modified_at: float, file_hash: str | None = None) -> None:
        with closing(self._connect()) as conn:
            conn.execute(
                """
                INSERT INTO processed_backups (filename, file_size, last_modified, hash, status, processed_at, error_message)
                VALUES (?, ?, ?, ?, ?, NULL, NULL)
                ON CONFLICT(filename) DO UPDATE SET
                    file_size = excluded.file_size,
                    last_modified = excluded.last_modified,
                    hash = excluded.hash,
                    status = excluded.status,
                    error_message = NULL
                """,
                (filename, size, self._iso(modified_at), file_hash, PROCESSING),
            )
            conn.commit()

    def mark_success(self, filename: str) -> None:
        self._set_final(filename, SUCCESS, None)

    def mark_failed(self, filename: str, message: str) -> None:
        self._set_final(filename, FAILED, message)

    def _set_final(self, filename: str, status: str, message: str | None) -> None:
        with closing(self._connect()) as conn:
            conn.execute(
                "UPDATE processed_backups SET status = ?, processed_at = ?, error_message = ? WHERE filename = ?",
                (status, self._now(), message, filename),
            )
            conn.commit()

    @staticmethod
    def _iso(ts: float) -> str:
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()

    @staticmethod
    def _now() -> str:
        return datetime.now(tz=timezone.utc).isoformat()


def file_sha256(path: str, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(chunk_size), b""):
            digest.update(chunk)
    return digest.hexdigest()
