import os
import subprocess

import pytest

from agent import guards, live_copy, lock, restore, staging
from config.settings import ApiSettings, FirebirdSettings, SourceSettings, StagingSettings


@pytest.fixture
def staging_dir(tmp_path, monkeypatch):
    monkeypatch.setattr(StagingSettings, "path", str(tmp_path))
    monkeypatch.setattr(StagingSettings, "min_free_mb", 1)
    monkeypatch.setattr(FirebirdSettings, "port", "3050")
    monkeypatch.setattr(FirebirdSettings, "user", "stg")
    monkeypatch.setattr(FirebirdSettings, "password", "stg-secret")
    os.makedirs(StagingSettings.tmp_path())
    return tmp_path


@pytest.fixture
def dummy_source(staging_dir, monkeypatch):
    monkeypatch.setattr(SourceSettings, "env", "dummy")
    monkeypatch.setattr(SourceSettings, "method", "gbak")  # these tests cover the gbak stream path
    monkeypatch.setattr(SourceSettings, "enabled", False)
    monkeypatch.setattr(SourceSettings, "port", "3050")
    monkeypatch.setattr(SourceSettings, "database", str(staging_dir / "SRC.GDB"))
    monkeypatch.setattr(SourceSettings, "user", "src")
    monkeypatch.setattr(SourceSettings, "password", "src-secret")
    monkeypatch.setattr(ApiSettings, "url", "http://127.0.0.1:9999/api")
    monkeypatch.setattr(live_copy, "bind_to_agent", lambda pid: None)


def _write(path, content):
    with open(path, "w") as f:
        f.write(content)


def _read(path):
    with open(path) as f:
        return f.read()


# ---------- swap / rollback ----------

def test_swap_in_replaces_active_and_keeps_previous(staging_dir):
    _write(StagingSettings.database_path(), "old")
    _write(StagingSettings.new_database_path(), "new")

    staging.swap_in(StagingSettings.new_database_path())

    assert _read(StagingSettings.database_path()) == "new"
    assert _read(StagingSettings.prev_database_path()) == "old"
    assert not os.path.exists(StagingSettings.new_database_path())


def test_swap_in_works_without_an_existing_active_db(staging_dir):
    _write(StagingSettings.new_database_path(), "new")

    staging.swap_in(StagingSettings.new_database_path())

    assert _read(StagingSettings.database_path()) == "new"


def test_swap_in_keeps_active_db_when_active_is_locked(staging_dir, monkeypatch):
    _write(StagingSettings.database_path(), "old")
    _write(StagingSettings.new_database_path(), "new")

    def locked(src, dst):
        raise PermissionError("in use")

    monkeypatch.setattr(staging.os, "replace", locked)
    with pytest.raises(staging.SwapError):
        staging.swap_in(StagingSettings.new_database_path())

    assert _read(StagingSettings.database_path()) == "old"


def test_swap_in_restores_previous_when_second_move_fails(staging_dir, monkeypatch):
    _write(StagingSettings.database_path(), "old")
    _write(StagingSettings.new_database_path(), "new")
    real_replace = os.replace

    def fail_on_new(src, dst):
        if src == StagingSettings.new_database_path():
            raise PermissionError("in use")
        real_replace(src, dst)

    monkeypatch.setattr(staging.os, "replace", fail_on_new)
    with pytest.raises(staging.SwapError):
        staging.swap_in(StagingSettings.new_database_path())

    assert _read(StagingSettings.database_path()) == "old"


def test_rollback_puts_previous_back(staging_dir):
    _write(StagingSettings.database_path(), "bad")
    _write(StagingSettings.prev_database_path(), "good")

    staging.rollback()

    assert _read(StagingSettings.database_path()) == "good"


def test_single_instance_blocks_a_second_job(tmp_path):
    path = str(tmp_path / "agent.lock")
    with lock.single_instance(path):
        with pytest.raises(lock.AlreadyRunning):
            with lock.single_instance(path):
                pass
    with lock.single_instance(path):
        pass


# ---------- guards ----------

def test_target_guard_refuses_outside_tmp_and_production(staging_dir):
    with pytest.raises(guards.GuardError):
        guards.assert_staging_target_safe(StagingSettings.database_path())
    with pytest.raises(guards.GuardError):
        guards.assert_staging_target_safe(r"D:\GUDANGSIG2025.GDB")
    guards.assert_staging_target_safe(StagingSettings.new_database_path())


def test_target_guard_refuses_staging_port_3051(staging_dir, monkeypatch):
    monkeypatch.setattr(FirebirdSettings, "port", "3051")
    with pytest.raises(guards.GuardError, match="3051"):
        guards.assert_staging_target_safe(StagingSettings.new_database_path())


def test_dummy_mode_refuses_production_port(dummy_source, monkeypatch):
    monkeypatch.setattr(SourceSettings, "port", "3051")
    with pytest.raises(guards.GuardError, match="3051"):
        guards.assert_source_allowed()


@pytest.mark.parametrize("path", [r"D:\GUDANGSIG2025.GDB", r"d:\gudangsig2025.gdb", r"D:\.\GUDANGSIG2025.GDB"])
def test_dummy_mode_refuses_production_database(dummy_source, monkeypatch, path):
    monkeypatch.setattr(SourceSettings, "database", path)
    with pytest.raises(guards.GuardError, match="GUDANGSIG2025"):
        guards.assert_source_allowed()


def test_dummy_mode_refuses_real_staging_and_real_api(dummy_source, monkeypatch):
    monkeypatch.setattr(StagingSettings, "path", r"D:\01.STOCKWISE\Staging")
    with pytest.raises(guards.GuardError, match="staging"):
        guards.assert_source_allowed()
    monkeypatch.undo()


def test_dummy_mode_refuses_real_api(dummy_source, monkeypatch):
    monkeypatch.setattr(ApiSettings, "url", "http://127.0.0.1:8001/api")
    with pytest.raises(guards.GuardError, match="API"):
        guards.assert_source_allowed()


def test_production_mode_refuses_unless_enabled(dummy_source, monkeypatch):
    monkeypatch.setattr(SourceSettings, "env", "production")
    with pytest.raises(guards.GuardError, match="disabled"):
        guards.assert_source_allowed()


def test_unknown_env_refused(dummy_source, monkeypatch):
    monkeypatch.setattr(SourceSettings, "env", "prod")
    with pytest.raises(guards.GuardError):
        guards.assert_source_allowed()


def test_free_space_guard(staging_dir, monkeypatch):
    monkeypatch.setattr(StagingSettings, "min_free_mb", 10**12)
    with pytest.raises(guards.GuardError, match="free"):
        guards.assert_free_space()


# ---------- live copy (subprocess faked) ----------

class _FakeProc:
    def __init__(self, cmd, rc, on_communicate=None):
        self.cmd, self.returncode, self._on = cmd, rc, on_communicate
        self.stdout = type("S", (), {"close": lambda self: None})()
        self.killed = False
        self.pid = 0

    def communicate(self, timeout=None):
        if self._on:
            self._on(self.cmd)
        return b"", b"restore-side error" if self.returncode else b""

    def wait(self, timeout=None):
        return self.returncode

    def kill(self):
        self.killed = True


def _fake_popen(monkeypatch, backup_rc=0, restore_rc=0, write_target=True, seen=None):
    def popen(cmd, **kwargs):
        if seen is not None:
            seen.append((cmd, kwargs.get("env")))
        if "-b" in cmd:
            return _FakeProc(cmd, backup_rc)
        return _FakeProc(cmd, restore_rc, (lambda c: _write(c[-1].split(":", 1)[1], "gdb")) if write_target else None)

    monkeypatch.setattr(live_copy.subprocess, "Popen", popen)


def test_live_copy_streams_gdb_to_gdb_without_intermediate_file(dummy_source, monkeypatch):
    seen = []
    _fake_popen(monkeypatch, seen=seen)

    new_db = live_copy.copy_live_to_staging()

    (backup_cmd, backup_env), (restore_cmd, restore_env) = seen
    assert backup_cmd[1:] == ["-b", "-g", SourceSettings.dsn(), "stdout"]
    assert restore_cmd[1:3] == ["-c", "stdin"] and restore_cmd[3].endswith(new_db)
    assert new_db == StagingSettings.new_database_path()
    assert os.listdir(StagingSettings.tmp_path()) == ["STAGING_NEW.GDB"]  # no .fbk anywhere
    argv = " ".join(backup_cmd + restore_cmd)
    assert "src-secret" not in argv and "stg-secret" not in argv
    assert backup_env["ISC_PASSWORD"] == "src-secret" and restore_env["ISC_PASSWORD"] == "stg-secret"


@pytest.mark.parametrize("backup_rc,restore_rc", [(1, 0), (0, 1), (1, 1)])
def test_live_copy_failure_removes_partial_file(dummy_source, monkeypatch, backup_rc, restore_rc):
    _fake_popen(monkeypatch, backup_rc=backup_rc, restore_rc=restore_rc)
    with pytest.raises(live_copy.LiveCopyError):
        live_copy.copy_live_to_staging()
    assert not os.path.exists(StagingSettings.new_database_path())


def test_live_copy_timeout_kills_both_and_cleans_up(dummy_source, monkeypatch):
    procs = []

    def popen(cmd, **kwargs):
        p = _FakeProc(cmd, 0)
        if "-c" in cmd:
            def boom(timeout=None):
                _write(StagingSettings.new_database_path(), "partial")
                raise subprocess.TimeoutExpired(cmd, timeout)
            p.communicate = boom
        procs.append(p)
        return p

    monkeypatch.setattr(live_copy.subprocess, "Popen", popen)
    with pytest.raises(live_copy.LiveCopyError, match="timed out"):
        live_copy.copy_live_to_staging()
    assert all(p.killed for p in procs)
    assert not os.path.exists(StagingSettings.new_database_path())


def test_live_copy_never_starts_when_guard_fails(dummy_source, monkeypatch):
    monkeypatch.setattr(SourceSettings, "port", "3051")
    started = []
    monkeypatch.setattr(live_copy.subprocess, "Popen", lambda *a, **k: started.append(a))
    with pytest.raises(guards.GuardError):
        live_copy.copy_live_to_staging()
    assert started == []


# ---------- integrity ----------

def test_integrity_rejects_any_gfix_output(staging_dir, monkeypatch):
    class R:
        returncode = 0
        stdout = "Summary of validation errors\n Number of record level errors: 1"
        stderr = ""

    monkeypatch.setattr(restore.subprocess, "run", lambda *a, **k: R())
    with pytest.raises(restore.IntegrityError):
        restore.check_integrity(StagingSettings.new_database_path())


def test_integrity_passes_on_clean_gfix(staging_dir, monkeypatch):
    class R:
        returncode = 0
        stdout = stderr = ""

    monkeypatch.setattr(restore.subprocess, "run", lambda *a, **k: R())
    restore.check_integrity(StagingSettings.new_database_path())


def test_live_copy_stops_children_if_they_cannot_be_bound_to_the_agent(dummy_source, monkeypatch):
    procs = []

    def popen(cmd, **kwargs):
        p = _FakeProc(cmd, 0)
        procs.append(p)
        return p

    def refuse(pid):
        raise live_copy.JobError("no job")

    monkeypatch.setattr(live_copy.subprocess, "Popen", popen)
    monkeypatch.setattr(live_copy, "bind_to_agent", refuse)
    with pytest.raises(live_copy.LiveCopyError, match="tie gbak"):
        live_copy.copy_live_to_staging()
    assert len(procs) == 2 and all(p.killed for p in procs)
    assert not os.path.exists(StagingSettings.new_database_path())


def test_preflight_reports_stopped_service_and_missing_port(staging_dir, monkeypatch):
    from agent import preflight

    monkeypatch.setattr(preflight, "service_running", lambda name: name != "FirebirdServer25")
    monkeypatch.setattr(preflight, "listening_ports", lambda: {"3050"})
    problems = preflight.check()
    assert any("FirebirdServer25" in p for p in problems)
    assert any("3051" in p for p in problems)


def test_preflight_healthy(staging_dir, monkeypatch):
    from agent import preflight

    monkeypatch.setattr(preflight, "service_running", lambda name: True)
    monkeypatch.setattr(preflight, "listening_ports", lambda: {"3050", "3051"})
    assert preflight.check() == []
