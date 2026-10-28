"""Parses SYNC_TIMES (brief §10: 04:00, 10:30, 20:30 — no other times) and
works out how long to sleep until the next scheduled tick.
"""
from datetime import datetime, timedelta
from typing import List, Tuple

from config.settings import AgentSettings


def parse_times(times: List[str] | None = None) -> List[Tuple[int, int]]:
    times = times if times is not None else AgentSettings.sync_times
    parsed = []
    for t in times:
        hh, mm = t.split(":")
        parsed.append((int(hh), int(mm)))
    if not parsed:
        raise ValueError("SYNC_TIMES is empty — refusing to guess a schedule.")
    return sorted(parsed)


def next_run_at(now: datetime, times: List[str] | None = None) -> datetime:
    """The earliest scheduled time strictly after `now` — today if one is
    still ahead, otherwise tomorrow's first slot.
    """
    for hh, mm in parse_times(times):
        candidate = now.replace(hour=hh, minute=mm, second=0, microsecond=0)
        if candidate > now:
            return candidate

    hh, mm = parse_times(times)[0]
    return (now + timedelta(days=1)).replace(hour=hh, minute=mm, second=0, microsecond=0)


def seconds_until(target: datetime, now: datetime) -> float:
    return max(0.0, (target - now).total_seconds())
