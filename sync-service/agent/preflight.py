"""Read-only health check of the production box around a live copy:
Accurate/Firebird services running, production and staging ports listening,
staging disk space. Uses only `sc query` and `netstat` — it never connects
to port 3051 or opens any database file.

Run before a live copy (a copy is refused unless everything is healthy) and
after it (a problem is logged loudly). Also: `python -m agent.run --preflight`.
"""
import os
import shutil
import subprocess

from config.settings import PRODUCTION_PORT, StagingSettings

REQUIRED_SERVICES = [
    s.strip()
    for s in os.environ.get(
        "PREFLIGHT_SERVICES", "FirebirdServer25,Acc5LicSvc,AccurateLicenseManager,STOCKWISE-FirebirdStaging"
    ).split(",")
    if s.strip()
]
REQUIRED_LISTEN_PORTS = [
    p.strip() for p in os.environ.get("PREFLIGHT_PORTS", f"{PRODUCTION_PORT},3050").split(",") if p.strip()
]


def _run(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=30).stdout
    except (OSError, subprocess.TimeoutExpired):
        return ""


def service_running(name: str) -> bool:
    out = _run(["sc", "query", name])
    return any("STATE" in ln and "RUNNING" in ln for ln in out.splitlines())


def listening_ports() -> set:
    ports = set()
    for ln in _run(["netstat", "-ano", "-p", "TCP"]).splitlines():
        parts = ln.split()
        if len(parts) >= 4 and parts[0] == "TCP" and parts[3] == "LISTENING":
            ports.add(parts[1].rsplit(":", 1)[-1])
    return ports


def check() -> list:
    """Returns a list of problems (empty = healthy)."""
    problems = [f"service {s} is not RUNNING" for s in REQUIRED_SERVICES if not service_running(s)]
    listening = listening_ports()
    problems += [f"port {p} is not LISTENING" for p in REQUIRED_LISTEN_PORTS if p not in listening]
    try:
        free_mb = shutil.disk_usage(StagingSettings.path).free // (1024 * 1024)
        if free_mb < StagingSettings.min_free_mb:
            problems.append(f"only {free_mb} MB free on the staging drive")
    except OSError as exc:
        problems.append(f"cannot read staging drive space: {exc}")
    return problems
