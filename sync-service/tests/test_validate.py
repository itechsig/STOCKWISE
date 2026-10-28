import pytest

from agent.validate import StagingValidationError, validate_gudang_staging

ITEM_COLUMNS = [
    {"name": "ITEMNO", "type": "VARCHAR", "length": 20, "not_null": False},
    {"name": "ITEMDESCRIPTION", "type": "VARCHAR", "length": 255, "not_null": False},
    {"name": "UNIT1", "type": "VARCHAR", "length": 20, "not_null": False},
    {"name": "QUANTITY", "type": "DOUBLE PRECISION", "length": 0, "not_null": False},
]


def _fetch_columns_factory(columns):
    def fetch_columns(cur, table):
        return columns if table == "ITEM" else []

    return fetch_columns


def _fetch_rows_factory(rows):
    def fetch_rows(cur, table, columns):
        return iter(rows)

    return fetch_rows


def test_passes_for_a_real_looking_gudang_item_table():
    rows = [("AUT.0001", "BOLT M10 X 50", "PCS", 12.0)]

    validate_gudang_staging(None, _fetch_columns_factory(ITEM_COLUMNS), _fetch_rows_factory(rows))
    # no exception -> valid


def test_fails_when_item_table_does_not_exist():
    # TEST 7: staging isn't a Gudang database at all (e.g. INTERN's schema).
    with pytest.raises(StagingValidationError, match="no ITEM table"):
        validate_gudang_staging(None, _fetch_columns_factory([]), _fetch_rows_factory([]))


def test_fails_when_required_columns_are_missing():
    incomplete = [{"name": "ITEMNO", "type": "VARCHAR", "length": 20, "not_null": False}]

    with pytest.raises(StagingValidationError, match="missing expected column"):
        validate_gudang_staging(None, _fetch_columns_factory(incomplete), _fetch_rows_factory([]))


def test_fails_when_item_table_is_empty():
    with pytest.raises(StagingValidationError, match="empty"):
        validate_gudang_staging(None, _fetch_columns_factory(ITEM_COLUMNS), _fetch_rows_factory([]))


def test_fails_when_no_row_looks_like_a_real_product_code():
    # Right table/column shape, but content doesn't match AUT.0001-style
    # codes — e.g. a differently structured company database that happens
    # to reuse Accurate's generic ITEM table layout.
    rows = [("SOMETHING", "Not a product code", "PCS", 1.0), ("ALSO-NOT-IT", "x", "PCS", 2.0)]

    with pytest.raises(StagingValidationError, match="product code pattern"):
        validate_gudang_staging(None, _fetch_columns_factory(ITEM_COLUMNS), _fetch_rows_factory(rows))


def test_passes_when_only_some_rows_match_the_product_pattern():
    # Category/hierarchy nodes (AUT, AUT.01, ...) mixed with real products
    # is the normal shape of this data — one real product code is enough.
    rows = [
        ("AUT", "AUTOMOTIVE", None, 0),
        ("AUT.01", "ENGINE", None, 0),
        ("AUT.0001", "BOLT M10 X 50", "PCS", 12.0),
    ]

    validate_gudang_staging(None, _fetch_columns_factory(ITEM_COLUMNS), _fetch_rows_factory(rows))
