import os
import time

from agent.scanner import BackupFile, list_backups, pick_candidate, wait_until_stable
from config.settings import BackupSettings


def _touch(path: str, size: int, mtime: float) -> None:
    with open(path, "wb") as f:
        f.write(b"0" * size)
    os.utime(path, (mtime, mtime))


def test_list_backups_finds_gbk_files_case_insensitively(tmp_path):
    _touch(str(tmp_path / "GUDANGSIG2025 Monday.gbk"), 10, time.time() - 100)
    _touch(str(tmp_path / "GUDANGSIG2025 Tuesday.GBK"), 20, time.time() - 50)
    _touch(str(tmp_path / "not-a-backup.txt"), 5, time.time())

    files = list_backups(directory=str(tmp_path), pattern="*.gbk")

    names = [f.filename for f in files]
    assert "GUDANGSIG2025 Monday.gbk" in names
    assert "GUDANGSIG2025 Tuesday.GBK" in names
    assert "not-a-backup.txt" not in names


def test_list_backups_sorts_newest_first(tmp_path):
    older = tmp_path / "older.gbk"
    newer = tmp_path / "newer.gbk"
    _touch(str(older), 10, time.time() - 1000)
    _touch(str(newer), 10, time.time() - 10)

    files = list_backups(directory=str(tmp_path), pattern="*.gbk")

    assert [f.filename for f in files] == ["newer.gbk", "older.gbk"]


def test_pick_candidate_skips_already_successful_backups():
    files = [
        BackupFile(path="/x/b.gbk", filename="b.gbk", size=1, modified_at=200),
        BackupFile(path="/x/a.gbk", filename="a.gbk", size=1, modified_at=100),
    ]

    done = {"b.gbk"}
    candidate = pick_candidate(files, is_already_done=lambda name: name in done)

    assert candidate.filename == "a.gbk"


def test_pick_candidate_returns_none_when_everything_is_done():
    files = [BackupFile(path="/x/a.gbk", filename="a.gbk", size=1, modified_at=100)]

    assert pick_candidate(files, is_already_done=lambda name: True) is None


def test_wait_until_stable_true_when_size_never_changes():
    sizes = iter([38_000_000, 38_000_000, 38_000_000])
    sleeps = []

    class FakeStat:
        def __init__(self, size):
            self.st_size = size

    stable = wait_until_stable(
        "irrelevant.gbk",
        interval_seconds=120,
        checks=3,
        sleep=lambda s: sleeps.append(s),
        stat_fn=lambda path: FakeStat(next(sizes)),
    )

    assert stable is True
    assert sleeps == [120, 120]


def test_wait_until_stable_false_when_size_is_still_growing():
    # 18:30 -> 35MB, 18:32 -> 36MB, 18:34 -> 38MB (still being written, per the brief's own example)
    sizes = iter([35_000_000, 36_000_000, 38_000_000])

    class FakeStat:
        def __init__(self, size):
            self.st_size = size

    stable = wait_until_stable(
        "irrelevant.gbk",
        interval_seconds=120,
        checks=3,
        sleep=lambda s: None,
        stat_fn=lambda path: FakeStat(next(sizes)),
    )

    assert stable is False


def test_default_pattern_is_gudangsig2025_only_not_all_gbk():
    # brief §6/§27: the office D:\ holds backups for several databases side
    # by side — *.gbk would pick up all of them, which is explicitly wrong.
    assert BackupSettings.pattern == "GUDANGSIG2025*.gbk"


def test_acceptance_test_1_and_2_gudang_detected_intern_ignored(tmp_path):
    # TEST 1: GUDANGSIG2025 Friday.gbk -> detected.
    _touch(str(tmp_path / "GUDANGSIG2025 Friday.gbk"), 38_000_000, time.time() - 10)
    # TEST 2: INTERN 1833 122024.gbk -> ignored (different database entirely).
    _touch(str(tmp_path / "INTERN 1833 122024.gbk"), 20_000_000, time.time() - 5)

    files = list_backups(directory=str(tmp_path), pattern="GUDANGSIG2025*.gbk")

    names = [f.filename for f in files]
    assert "GUDANGSIG2025 Friday.gbk" in names
    assert "INTERN 1833 122024.gbk" not in names
    assert len(names) == 1


def test_acceptance_test_3_and_4_gdb_files_never_match_the_backup_pattern(tmp_path):
    # TEST 3/4: GUDANGSIG2025.GDB and GUDANG2021.GDB (the live production
    # databases, wrong extension entirely) must never be picked up as
    # backup candidates — a .gbk-only glob can't match them by construction.
    _touch(str(tmp_path / "GUDANGSIG2025.GDB"), 130_000_000, time.time())
    _touch(str(tmp_path / "GUDANG2021.GDB"), 90_000_000, time.time())
    _touch(str(tmp_path / "GUDANGSIG2025 Friday.gbk"), 38_000_000, time.time())

    files = list_backups(directory=str(tmp_path), pattern="GUDANGSIG2025*.gbk")

    names = [f.filename for f in files]
    assert "GUDANGSIG2025.GDB" not in names
    assert "GUDANG2021.GDB" not in names
    assert names == ["GUDANGSIG2025 Friday.gbk"]


def test_multiple_gudang_backups_only_the_newest_unprocessed_one_is_picked(tmp_path):
    # §11 STEP 5: initial sync picks the newest valid Gudang backup, not
    # every historical one.
    _touch(str(tmp_path / "GUDANGSIG2025 062026.gbk"), 10, time.time() - 5000)
    _touch(str(tmp_path / "GUDANGSIG2025 Thursday.gbk"), 10, time.time() - 2000)
    _touch(str(tmp_path / "GUDANGSIG2025 Friday.gbk"), 10, time.time() - 10)
    _touch(str(tmp_path / "INTERN 1833 072024.gbk"), 10, time.time() - 1)

    files = list_backups(directory=str(tmp_path), pattern="GUDANGSIG2025*.gbk")
    candidate = pick_candidate(files, is_already_done=lambda name: False)

    assert candidate.filename == "GUDANGSIG2025 Friday.gbk"


def test_wait_until_stable_false_when_file_disappears():
    def raising_stat(path):
        raise OSError("file not found")

    assert wait_until_stable("gone.gbk", interval_seconds=1, sleep=lambda s: None, stat_fn=raising_stat) is False
