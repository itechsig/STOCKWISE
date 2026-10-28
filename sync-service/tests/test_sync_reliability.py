import pytest

from agent import api_client as api_mod
from agent.api_client import ApiError, StockwiseApiClient
from agent.scanner import BackupFile, pick_candidate
from agent.state import AgentState


def _f(name, mtime):
    return BackupFile(path=name, filename=name, size=1, modified_at=mtime)


# ---------- never go back to older data ----------

def test_older_unprocessed_backup_is_never_picked_after_a_newer_success():
    # newest-first, as list_backups() returns them
    files = [_f("Friday.gbk", 2000), _f("Thursday.gbk", 1000)]
    done = {"Friday.gbk"}

    assert pick_candidate(files, lambda n: n in done, newer_than=2000) is None


def test_same_second_duplicate_of_an_already_synced_backup_is_skipped():
    files = [_f("092026.gbk", 2000), _f("Friday.gbk", 2000)]
    assert pick_candidate(files, lambda n: n == "Friday.gbk", newer_than=2000) is None


def test_a_genuinely_newer_backup_is_picked():
    files = [_f("Saturday.gbk", 3000), _f("Friday.gbk", 2000)]
    assert pick_candidate(files, lambda n: n == "Friday.gbk", newer_than=2000).filename == "Saturday.gbk"


def test_without_history_the_newest_backup_is_picked():
    files = [_f("Friday.gbk", 2000), _f("Thursday.gbk", 1000)]
    assert pick_candidate(files, lambda n: False, newer_than=None).filename == "Friday.gbk"


def test_latest_success_mtime_ignores_failed_and_processing(tmp_path):
    state = AgentState(db_path=str(tmp_path / "s.sqlite"))
    assert state.latest_success_mtime() is None

    state.mark_processing("a.gbk", 1, 1_700_000_000.0)
    state.mark_success("a.gbk")
    state.mark_processing("b.gbk", 1, 1_700_000_500.0)
    state.mark_failed("b.gbk", "boom")
    state.mark_processing("c.gbk", 1, 1_700_000_900.0)

    assert state.latest_success_mtime() == pytest.approx(1_700_000_000.0)


# ---------- complete() waits for background processing ----------

class _Resp:
    def __init__(self, status_code, payload):
        self.status_code = status_code
        self._payload = payload
        self.ok = status_code < 400
        self.text = str(payload)

    def json(self):
        return self._payload


def _client():
    return StockwiseApiClient(base_url="http://x/api", token="t", timeout=5)


def test_complete_polls_until_the_batch_leaves_running(monkeypatch):
    posts, gets, sleeps = [], [], []
    statuses = iter(["RUNNING", "RUNNING", "PARTIAL"])

    monkeypatch.setattr(api_mod.requests, "post", lambda url, **kw: posts.append(url) or _Resp(202, {"data": {"id": 1, "status": "RUNNING"}}))
    monkeypatch.setattr(api_mod.requests, "get", lambda url, **kw: gets.append(url) or _Resp(200, {"data": {"id": 1, "status": next(statuses)}}))

    data = _client().complete(1, poll_seconds=15, sleep=sleeps.append, clock=lambda: 0)

    assert data["status"] == "PARTIAL"
    assert posts == ["http://x/api/agent/sync/sessions/1/complete"]
    assert gets == ["http://x/api/agent/sync/sessions/1"] * 3
    assert sleeps == [15, 15, 15]


def test_complete_returns_immediately_when_laravel_already_finished(monkeypatch):
    monkeypatch.setattr(api_mod.requests, "post", lambda url, **kw: _Resp(200, {"data": {"id": 1, "status": "SUCCESS"}}))
    monkeypatch.setattr(api_mod.requests, "get", lambda *a, **kw: pytest.fail("must not poll"))

    assert _client().complete(1, sleep=lambda s: None)["status"] == "SUCCESS"


def test_complete_gives_up_after_the_timeout(monkeypatch):
    ticks = iter(range(0, 10_000_000, 1000))
    monkeypatch.setattr(api_mod.requests, "post", lambda url, **kw: _Resp(202, {"data": {"id": 1, "status": "RUNNING", "current_step": "npbg"}}))
    monkeypatch.setattr(api_mod.requests, "get", lambda url, **kw: _Resp(202, {"data": {"id": 1, "status": "RUNNING", "current_step": "npbg"}}))

    with pytest.raises(ApiError, match="still RUNNING"):
        _client().complete(1, sleep=lambda s: None, clock=lambda: next(ticks))


def test_status_poll_survives_a_transient_network_error(monkeypatch):
    calls = {"n": 0}

    def flaky_get(url, **kw):
        calls["n"] += 1
        if calls["n"] == 1:
            raise api_mod.requests.ConnectionError("blip")
        return _Resp(200, {"data": {"id": 1, "status": "SUCCESS"}})

    monkeypatch.setattr(api_mod.requests, "post", lambda url, **kw: _Resp(202, {"data": {"id": 1, "status": "RUNNING"}}))
    monkeypatch.setattr(api_mod.requests, "get", flaky_get)
    monkeypatch.setattr(api_mod.time, "sleep", lambda s: None)

    assert _client().complete(1, sleep=lambda s: None, clock=lambda: 0)["status"] == "SUCCESS"
