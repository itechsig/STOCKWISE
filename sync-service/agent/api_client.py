"""Client for the Sync Agent's push endpoints (POST /api/agent/sync/*,
docs/api-sync.md) — the only way the Agent ever talks to Stockwise. Never
connects to MySQL directly (brief §6). Network errors retry with backoff and
are always raised as ApiError, never left to crash the Agent process (brief
§16) — the caller (agent/run.py) decides what a failure means for this tick.
"""
import time

import requests

from config.settings import ApiSettings


class ApiError(RuntimeError):
    pass


class StockwiseApiClient:
    def __init__(self, base_url: str | None = None, token: str | None = None, timeout: int | None = None):
        self.base_url = (base_url or ApiSettings.url).rstrip("/")
        self.token = token or ApiSettings.token
        self.timeout = timeout or ApiSettings.timeout_seconds

    def start_session(self) -> dict:
        return self._post("/agent/sync/sessions")["data"]

    def push_table(
        self,
        batch_id: int,
        table: str,
        columns: list[dict],
        rows: list[dict],
        primary_key: list[str] | None = None,
        is_first_chunk: bool = False,
    ) -> dict:
        payload = {
            "columns": columns,
            "primary_key": primary_key or [],
            "rows": rows,
            "is_first_chunk": is_first_chunk,
        }
        return self._post(f"/agent/sync/sessions/{batch_id}/tables/{table}", json=payload)["data"]

    def complete(self, batch_id: int, poll_seconds: float = 15.0, sleep=time.sleep, clock=time.monotonic) -> dict:
        """Starts processing of the pushed data and waits for it to finish.

        Since 2026-09-26 Laravel processes the batch in a background process
        and answers right away (202, status RUNNING) instead of holding the
        request — and the single-threaded web server — for the whole sync.
        So: POST .../complete, then poll GET .../sessions/{id} until the
        status leaves RUNNING, up to API_COMPLETE_TIMEOUT_SECONDS. An older
        Laravel that still finishes inside the request returns the final
        status directly; no polling happens then.
        """
        data = self._post(f"/agent/sync/sessions/{batch_id}/complete", timeout=ApiSettings.complete_timeout_seconds)["data"]
        deadline = clock() + ApiSettings.complete_timeout_seconds

        while data.get("status") == "RUNNING":
            if clock() >= deadline:
                raise ApiError(
                    f"Batch {batch_id} still RUNNING after {ApiSettings.complete_timeout_seconds}s "
                    f"(step: {data.get('current_step')}) — gave up waiting."
                )
            sleep(poll_seconds)
            data = self._get(f"/agent/sync/sessions/{batch_id}")["data"]

        return data

    def session(self, batch_id: int) -> dict:
        return self._get(f"/agent/sync/sessions/{batch_id}")["data"]

    def fail(self, batch_id: int, message: str) -> dict:
        return self._post(f"/agent/sync/sessions/{batch_id}/fail", json={"message": message})["data"]

    def _headers(self) -> dict:
        if not self.token:
            raise ApiError("API_TOKEN is not configured (see `php artisan stockwise:agent-token`).")
        return {"Authorization": f"Bearer {self.token}", "Accept": "application/json"}

    def _post(self, path: str, json: dict | None = None, retries: int = 3, backoff_seconds: float = 2.0, timeout: int | None = None) -> dict:
        url = f"{self.base_url}{path}"
        last_error: Exception | None = None

        for attempt in range(1, retries + 1):
            try:
                resp = requests.post(url, json=json, headers=self._headers(), timeout=timeout or self.timeout)
            except requests.RequestException as exc:
                last_error = exc
                if attempt < retries:
                    time.sleep(backoff_seconds * attempt)
                continue

            if resp.ok:
                return resp.json()

            # 4xx/5xx is a real application error (bad payload, table not
            # whitelisted, session no longer running, etc.) — not transient,
            # so it's not worth retrying.
            raise ApiError(f"Stockwise API {path} returned {resp.status_code}: {resp.text[:500]}")

        raise ApiError(f"Could not reach Stockwise API at {url} after {retries} attempts: {last_error}")

    def _get(self, path: str, retries: int = 5, backoff_seconds: float = 3.0) -> dict:
        """Read-only status poll — safe to retry, so it retries harder than _post."""
        url = f"{self.base_url}{path}"
        last_error: Exception | None = None

        for attempt in range(1, retries + 1):
            try:
                resp = requests.get(url, headers=self._headers(), timeout=self.timeout)
            except requests.RequestException as exc:
                last_error = exc
                if attempt < retries:
                    time.sleep(backoff_seconds * attempt)
                continue

            if resp.ok:
                return resp.json()
            raise ApiError(f"Stockwise API {path} returned {resp.status_code}: {resp.text[:500]}")

        raise ApiError(f"Could not reach Stockwise API at {url} after {retries} attempts: {last_error}")
