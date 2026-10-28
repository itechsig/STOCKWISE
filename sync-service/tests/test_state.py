from agent.state import AgentState, FAILED, PROCESSING, SUCCESS


def _state(tmp_path):
    return AgentState(db_path=str(tmp_path / "agent_state.sqlite"))


def test_unknown_file_is_not_success(tmp_path):
    state = _state(tmp_path)

    assert state.is_success("never-seen.gbk") is False
    assert state.get("never-seen.gbk") is None


def test_mark_processing_then_success(tmp_path):
    state = _state(tmp_path)

    state.mark_processing("GUDANGSIG2025 Monday.gbk", size=38_000_000, modified_at=1_700_000_000.0, file_hash="abc123")
    row = state.get("GUDANGSIG2025 Monday.gbk")
    assert row["status"] == PROCESSING
    assert row["file_size"] == 38_000_000
    assert row["hash"] == "abc123"
    assert state.is_success("GUDANGSIG2025 Monday.gbk") is False

    state.mark_success("GUDANGSIG2025 Monday.gbk")

    assert state.is_success("GUDANGSIG2025 Monday.gbk") is True
    row = state.get("GUDANGSIG2025 Monday.gbk")
    assert row["status"] == SUCCESS
    assert row["processed_at"] is not None
    assert row["error_message"] is None


def test_mark_failed_records_the_error_and_is_retried(tmp_path):
    state = _state(tmp_path)

    state.mark_processing("bad.gbk", size=1000, modified_at=1_700_000_000.0)
    state.mark_failed("bad.gbk", "gbak restore failed (exit 1): backup file corrupt.")

    row = state.get("bad.gbk")
    assert row["status"] == FAILED
    assert "corrupt" in row["error_message"]
    # A FAILED backup is not SUCCESS — the next tick's pick_candidate() will
    # pick it again (brief §16: retry on the next scheduled run).
    assert state.is_success("bad.gbk") is False


def test_reprocessing_a_filename_overwrites_the_previous_attempt(tmp_path):
    state = _state(tmp_path)

    state.mark_processing("f.gbk", size=100, modified_at=1.0)
    state.mark_failed("f.gbk", "first attempt failed")

    # Next scheduled tick picks the same filename again (still not SUCCESS).
    state.mark_processing("f.gbk", size=105, modified_at=2.0)
    row = state.get("f.gbk")
    assert row["status"] == PROCESSING
    assert row["file_size"] == 105
    assert row["error_message"] is None  # cleared on the new attempt

    state.mark_success("f.gbk")
    assert state.is_success("f.gbk") is True


def test_state_persists_across_instances(tmp_path):
    db_path = str(tmp_path / "agent_state.sqlite")

    AgentState(db_path=db_path).mark_processing("p.gbk", size=1, modified_at=1.0)
    AgentState(db_path=db_path).mark_success("p.gbk")

    assert AgentState(db_path=db_path).is_success("p.gbk") is True
