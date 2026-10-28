"""Tiny status file for the web app (Log Agen page): is an Agent job running
right now, which one, since when, and how the last one ended. Written
atomically at the start and end of every one-shot job; never read by the
Agent itself, so it can't influence any sync logic.
"""
import json
import os
from datetime import datetime

from config.settings import AgentSettings


def status_path() -> str:
    return os.path.join(AgentSettings.log_path, "agent-status.json")


def _write(data: dict) -> None:
    try:
        os.makedirs(AgentSettings.log_path, exist_ok=True)
        tmp = status_path() + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f)
        os.replace(tmp, status_path())
    except OSError:
        pass  # status is informational only — never fail a job over it


def mark_started(mode: str) -> None:
    _write({
        "running": True,
        "mode": mode,
        "pid": os.getpid(),
        "started_at": datetime.now().astimezone().isoformat(),
        "finished_at": None,
        "result": None,
    })


def mark_finished(mode: str, result: str, started_at: str | None = None) -> None:
    _write({
        "running": False,
        "mode": mode,
        "pid": os.getpid(),
        "started_at": started_at,
        "finished_at": datetime.now().astimezone().isoformat(),
        "result": result,  # "ok" | "failed" | "busy" | "nothing_to_do"
    })
