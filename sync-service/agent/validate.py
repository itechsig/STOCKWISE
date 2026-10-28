"""Second safety layer for a restored staging database (brief §9 STEP 7,
§22 TEST 7): the filename filter (GUDANGSIG2025*.gbk, see
config.settings.BackupSettings) is not trusted alone — after restore, this
confirms the staging DB actually IS Accurate's Gudang (warehouse) database
before any row is ever pushed to the Stockwise API. If this fails, the
caller (agent/run.py) stops the whole sync and reports FAILED — nothing
gets sent.

Checks structure (does ITEM have the columns Master Barang mapping needs)
and content (does at least one row look like a real product code, e.g.
AUT.0001) — a database that merely happens to share table/column names
(a different Accurate company file) would still fail the content check.
"""
import itertools
import re
from typing import Callable, Iterable

PRODUCT_CODE_PATTERN = re.compile(r"^[A-Za-z]{3}\.[0-9]{4,}$")

REQUIRED_ITEM_COLUMNS = {"ITEMNO", "ITEMDESCRIPTION", "UNIT1", "QUANTITY"}

SAMPLE_SIZE = 200


class StagingValidationError(RuntimeError):
    pass


def validate_gudang_staging(
    cur,
    fetch_columns: Callable[..., list],
    fetch_rows: Callable[..., Iterable],
) -> None:
    """Raises StagingValidationError if the restored staging database
    doesn't look like Accurate's Gudang database. Returns None otherwise.
    `fetch_columns`/`fetch_rows` are injected (normally
    firebird.introspect.fetch_columns/fetch_rows) so this is testable
    without a real Firebird connection.
    """
    columns = fetch_columns(cur, "ITEM")
    if not columns:
        raise StagingValidationError(
            "Staging database has no ITEM table — this is not a Gudang (Accurate) backup."
        )

    column_names = {c["name"] for c in columns}
    missing = REQUIRED_ITEM_COLUMNS - column_names
    if missing:
        raise StagingValidationError(
            f"Staging ITEM table is missing expected column(s): {', '.join(sorted(missing))} — "
            "this does not look like Accurate's Gudang database."
        )

    itemno_index = [c["name"] for c in columns].index("ITEMNO")
    sample = list(itertools.islice(fetch_rows(cur, "ITEM", columns), SAMPLE_SIZE))

    if not sample:
        raise StagingValidationError("Staging ITEM table is empty — refusing to sync zero products.")

    has_product_code = any(
        row[itemno_index] and PRODUCT_CODE_PATTERN.match(str(row[itemno_index]).strip())
        for row in sample
    )
    if not has_product_code:
        raise StagingValidationError(
            f"No row in the first {SAMPLE_SIZE} ITEM rows matches the expected product code pattern "
            "(e.g. AUT.0001) — this does not look like Accurate's Gudang database."
        )
