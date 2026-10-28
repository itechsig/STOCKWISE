import os
import pytest

from agent import run


class _Cursor:
    pass


class _Conn:
    def cursor(self):
        return _Cursor()

    def close(self):
        pass


class _Client:
    def __init__(self, final_status):
        self.final_status = final_status
        self.failed = []

    def start_session(self):
        return {"id": 7, "is_new_session": True}

    def complete(self, batch_id):
        return {"id": batch_id, "sync_code": "SYNC-T", "status": self.final_status, "error_message": "x"}

    def fail(self, batch_id, message):
        self.failed.append(batch_id)


@pytest.fixture
def wired(monkeypatch):
    def wire(status):
        client = _Client(status)
        monkeypatch.setattr(run, "StockwiseApiClient", lambda: client)
        monkeypatch.setattr(run.fb, "connect", lambda *a, **k: _Conn())
        monkeypatch.setattr(run, "validate_gudang_staging", lambda *a, **k: None)
        monkeypatch.setattr(run, "MIRROR_TABLES", [])
        return client

    return wire


@pytest.mark.parametrize("status", ["SUCCESS", "PARTIAL"])
def test_finished_batch_is_returned(wired, status):
    wired(status)
    assert run.push_staging("x")["status"] == status


def test_failed_batch_raises_so_the_backup_is_retried(wired):
    client = wired("FAILED")
    with pytest.raises(run.SyncBatchFailed, match="SYNC-T FAILED"):
        run.push_staging("x")
    assert client.failed == [], "batch is already FAILED — nothing to report back"


def test_status_file_describes_the_running_then_finished_job(tmp_path, monkeypatch):
    import json
    from agent import status as agent_status
    from config.settings import AgentSettings

    monkeypatch.setattr(AgentSettings, "log_path", str(tmp_path))
    agent_status.mark_started("snapshot")
    s = json.load(open(agent_status.status_path()))
    assert s["running"] is True and s["mode"] == "snapshot" and s["pid"] == os.getpid()

    agent_status.mark_finished("snapshot", "ok", s["started_at"])
    s = json.load(open(agent_status.status_path()))
    assert s["running"] is False and s["result"] == "ok" and s["finished_at"]


@pytest.mark.parametrize("enabled,method,expected", [
    (True, "filecopy", "live"),   # user decision: scheduled runs copy the .GDB, no .gbk
    (False, "filecopy", "gbk"),   # switched off -> legacy .gbk path
    (True, "gbak", "gbk"),        # gbak method is only for explicit --full/--snapshot
])
def test_default_tick_uses_the_gdb_file_copy_when_enabled(monkeypatch, enabled, method, expected):
    from config.settings import SourceSettings

    monkeypatch.setattr(SourceSettings, "enabled", enabled)
    monkeypatch.setattr(SourceSettings, "method", method)
    called = []
    monkeypatch.setattr(run, "run_live", lambda push: called.append(("live", push)) or True)
    monkeypatch.setattr(run, "run_once", lambda: called.append(("gbk", None)) or True)

    assert run.default_tick() is True
    assert called[0][0] == expected
    if expected == "live":
        assert called[0][1] is True, "scheduled runs also sync to STOCKWISE"
