"""One job at a time, across every mode and every scheduled task. The OS
releases the lock if the process dies, so a crash never leaves it stuck.
"""
import os
from contextlib import contextmanager

try:
    import msvcrt
except ImportError:  # non-Windows dev machines
    msvcrt = None
    import fcntl


class AlreadyRunning(RuntimeError):
    pass


@contextmanager
def single_instance(path: str):
    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, exist_ok=True)
    f = open(path, "a+")
    try:
        f.seek(0)
        try:
            if msvcrt:
                msvcrt.locking(f.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                fcntl.flock(f.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            raise AlreadyRunning(f"Another Sync Agent job holds {path}") from exc

        try:
            yield
        finally:
            f.seek(0)
            if msvcrt:
                msvcrt.locking(f.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                fcntl.flock(f.fileno(), fcntl.LOCK_UN)
    finally:
        f.close()
