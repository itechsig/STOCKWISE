"""Direct file copy of the live Accurate .GDB (SOURCE_METHOD=filecopy) — no
Firebird production account involved: the file is only READ.

A plain copy of a database that is being written can mix pages from
different moments. So every copy is verified: the source is read a second
time and its SHA-256 must equal the copy's. Equal => no write happened
while copying => the copy is a point-in-time image of the file, the same
state Firebird is designed to survive after a power loss. Different =>
the copy is thrown away and retried; after `attempts` tries the caller
falls back (the active staging DB is never touched here).

The source is opened with Python's open(), which on Windows shares
read+write access — Firebird and Accurate users are never locked out
while the copy runs.
"""
import hashlib
import os
import time

CHUNK = 4 * 1024 * 1024


class SnapshotUnstable(RuntimeError):
    """Source kept changing during every copy attempt."""


def _copy_with_hash(src: str, dst: str) -> str:
    digest = hashlib.sha256()
    with open(src, "rb") as fin, open(dst, "wb") as fout:
        for chunk in iter(lambda: fin.read(CHUNK), b""):
            digest.update(chunk)
            fout.write(chunk)
        fout.flush()
        os.fsync(fout.fileno())
    return digest.hexdigest()


def file_sha256(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(CHUNK), b""):
            digest.update(chunk)
    return digest.hexdigest()


def snapshot_file(src: str, dst: str, attempts: int = 3, wait_seconds: float = 5.0, sleep=time.sleep) -> dict:
    """Copies `src` to `dst` and proves the copy is a point-in-time image.
    Returns {"attempts": n, "bytes": size, "sha256": hash}; raises
    SnapshotUnstable (dst removed) if the source changed during every attempt.
    """
    last_error = None
    for attempt in range(1, attempts + 1):
        copied = _copy_with_hash(src, dst)
        if file_sha256(src) == copied:
            return {"attempts": attempt, "bytes": os.path.getsize(dst), "sha256": copied}
        last_error = f"attempt {attempt}: source changed while copying"
        _discard(dst)
        if attempt < attempts:
            sleep(wait_seconds)

    raise SnapshotUnstable(f"Source {src} kept changing during {attempts} copy attempts ({last_error}).")


def _discard(path: str) -> None:
    try:
        os.remove(path)
    except OSError:
        pass
