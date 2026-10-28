import os

import pytest

from agent import file_snapshot, guards, live_copy
from agent.file_snapshot import SnapshotUnstable, snapshot_file
from config.settings import ApiSettings, FirebirdSettings, SourceSettings, StagingSettings


def _write(path, data: bytes):
    with open(path, "wb") as f:
        f.write(data)


def test_identical_copy_on_first_attempt(tmp_path):
    src, dst = tmp_path / "SRC.GDB", tmp_path / "COPY.GDB"
    _write(src, os.urandom(3 * 1024 * 1024))

    info = snapshot_file(str(src), str(dst))

    assert info["attempts"] == 1
    assert dst.read_bytes() == src.read_bytes()


def test_source_changing_during_copy_is_retried_then_accepted(tmp_path, monkeypatch):
    src, dst = tmp_path / "SRC.GDB", tmp_path / "COPY.GDB"
    _write(src, b"A" * 1000)
    reads = {"n": 0}
    real_hash = file_snapshot.file_sha256

    def hash_with_one_concurrent_write(path):
        reads["n"] += 1
        if reads["n"] == 1:  # a "Firebird write" lands between copy and verification
            _write(src, b"B" * 1000)
        return real_hash(path)

    monkeypatch.setattr(file_snapshot, "file_sha256", hash_with_one_concurrent_write)
    info = snapshot_file(str(src), str(dst), wait_seconds=0, sleep=lambda s: None)

    assert info["attempts"] == 2
    assert dst.read_bytes() == b"B" * 1000


def test_source_that_never_settles_is_rejected_and_nothing_is_left(tmp_path, monkeypatch):
    src, dst = tmp_path / "SRC.GDB", tmp_path / "COPY.GDB"
    _write(src, b"A" * 1000)
    monkeypatch.setattr(file_snapshot, "file_sha256", lambda p: "always-different")

    with pytest.raises(SnapshotUnstable):
        snapshot_file(str(src), str(dst), attempts=3, sleep=lambda s: None)
    assert not dst.exists()


def test_copy_never_blocks_a_writer_holding_the_source(tmp_path):
    """Firebird keeps the file open for writing while we copy it."""
    src, dst = tmp_path / "SRC.GDB", tmp_path / "COPY.GDB"
    _write(src, b"X" * 4096)
    with open(src, "r+b") as writer:
        snapshot_file(str(src), str(dst))
        writer.seek(0)
        writer.write(b"Y")  # still writable while/after our copy
        writer.flush()


# ---------- wiring: filecopy needs no production account ----------

@pytest.fixture
def filecopy_env(tmp_path, monkeypatch):
    monkeypatch.setattr(StagingSettings, "path", str(tmp_path / "staging"))
    monkeypatch.setattr(StagingSettings, "min_free_mb", 1)
    monkeypatch.setattr(FirebirdSettings, "port", "3050")
    monkeypatch.setattr(FirebirdSettings, "user", "stg")
    monkeypatch.setattr(FirebirdSettings, "password", "stg-secret")
    src = tmp_path / "SRC.GDB"
    _write(src, os.urandom(1024 * 1024))
    monkeypatch.setattr(SourceSettings, "env", "dummy")
    monkeypatch.setattr(SourceSettings, "method", "filecopy")
    monkeypatch.setattr(SourceSettings, "database", str(src))
    monkeypatch.setattr(SourceSettings, "user", None)
    monkeypatch.setattr(SourceSettings, "password", None)
    monkeypatch.setattr(ApiSettings, "url", "http://127.0.0.1:9999/api")
    return src


def test_filecopy_needs_no_source_firebird_account(filecopy_env):
    guards.assert_source_allowed()  # no SOURCE_FIREBIRD_USER/PASSWORD -> still allowed


def test_filecopy_lands_in_staging_tmp_and_matches_the_source(filecopy_env, monkeypatch):
    monkeypatch.setattr(live_copy.subprocess, "Popen", lambda *a, **k: pytest.fail("filecopy must not run gbak"))

    new_db = live_copy.copy_live_to_staging()

    assert new_db == StagingSettings.new_database_path()
    assert open(new_db, "rb").read() == filecopy_env.read_bytes()


def test_gbak_method_still_requires_production_account(filecopy_env, monkeypatch):
    monkeypatch.setattr(SourceSettings, "method", "gbak")
    monkeypatch.setattr(SourceSettings, "port", "3050")  # 3051 would be refused first in dummy mode
    with pytest.raises(guards.GuardError, match="SOURCE_FIREBIRD_USER"):
        guards.assert_source_allowed()


def test_dummy_mode_still_refuses_the_production_file(filecopy_env, monkeypatch):
    monkeypatch.setattr(SourceSettings, "database", r"D:\GUDANGSIG2025.GDB")
    with pytest.raises(guards.GuardError, match="GUDANGSIG2025"):
        guards.assert_source_allowed()
