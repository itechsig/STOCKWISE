from datetime import datetime

from agent.scheduler import next_run_at, parse_times, seconds_until


def test_parse_times_matches_the_brief_exactly():
    assert parse_times(["04:00", "10:30", "20:30"]) == [(4, 0), (10, 30), (20, 30)]


def test_parse_times_sorts_regardless_of_input_order():
    assert parse_times(["20:30", "04:00", "10:30"]) == [(4, 0), (10, 30), (20, 30)]


def test_next_run_at_picks_the_next_slot_today():
    now = datetime(2026, 9, 21, 6, 0)
    assert next_run_at(now, ["04:00", "10:30", "20:30"]) == datetime(2026, 9, 21, 10, 30)


def test_next_run_at_rolls_over_to_tomorrows_first_slot():
    # After 20:30 (and definitely after the 22:00 auto-shutdown, which is
    # deliberately NOT a sync time — brief §11/§44), the next tick is
    # tomorrow's 04:00, never 22:00/22:30.
    now = datetime(2026, 9, 21, 21, 0)
    assert next_run_at(now, ["04:00", "10:30", "20:30"]) == datetime(2026, 9, 22, 4, 0)


def test_seconds_until_never_negative():
    now = datetime(2026, 9, 21, 10, 31)
    target = datetime(2026, 9, 21, 10, 30)  # already passed
    assert seconds_until(target, now) == 0.0
