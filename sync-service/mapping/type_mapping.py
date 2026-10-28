"""Firebird -> MySQL column type mapping.

This mirrors Accurate's data as literally as reasonable (per the user's request to
keep the same format), only translating types where Firebird and MySQL genuinely
differ (e.g. Firebird has no native BOOLEAN, MySQL has no BLOB sub-type distinction).
Values themselves are copied verbatim. Firebird stores NUMERIC/DECIMAL(p,s) as a
SMALLINT/INTEGER/BIGINT with a negative RDB$FIELD_SCALE (e.g. Accurate's quantities,
prices and ratios are NUMERIC(18,4) = BIGINT scale -4): those become MySQL
DECIMAL(p,s) — before 2026-10-04 they were mirrored as BIGINT and every decimal
was rounded away (qty 0.75 -> 1, ratio 0.5 -> 1, prices).
"""

MYSQL_MAX_VARCHAR = 16000  # conservative ceiling before falling back to TEXT (utf8mb4 row-size limits)


def firebird_column_to_mysql_type(col: dict) -> dict:
    """Structured {type, length, nullable} decision — the single source of
    truth both firebird_column_to_mysql_ddl() (legacy direct-MySQL path) and
    agent/run.py (pushed to Laravel's ingest API as JSON, never raw SQL —
    see backend/app/Services/Accurate/StagingTableWriter.php) build from.
    `type` is always one of the values Laravel's config('accurate.column_types')
    whitelist accepts — keep the two lists in sync if either changes.
    """
    ftype = col["type"]
    length = col["length"] or 0
    nullable = not col["not_null"]
    scale = -(col.get("scale") or 0)

    # NUMERIC / DECIMAL (an integer type with a scale): keep its exact precision and scale
    if ftype in ("SMALLINT", "INTEGER", "BIGINT") and scale > 0:
        default_precision = {"SMALLINT": 4, "INTEGER": 9, "BIGINT": 18}[ftype]
        precision = col.get("precision") or default_precision
        precision = max(precision, scale)
        return {"type": "DECIMAL", "length": precision, "scale": scale, "nullable": nullable}

    if ftype == "SMALLINT":
        return {"type": "SMALLINT", "length": None, "nullable": nullable}
    elif ftype == "INTEGER":
        return {"type": "INT", "length": None, "nullable": nullable}
    elif ftype == "BIGINT":
        return {"type": "BIGINT", "length": None, "nullable": nullable}
    elif ftype == "FLOAT":
        return {"type": "FLOAT", "length": None, "nullable": nullable}
    elif ftype == "DOUBLE PRECISION":
        return {"type": "DOUBLE", "length": None, "nullable": nullable}
    elif ftype == "DATE":
        return {"type": "DATE", "length": None, "nullable": nullable}
    elif ftype == "TIME":
        return {"type": "TIME", "length": None, "nullable": nullable}
    elif ftype == "TIMESTAMP":
        return {"type": "DATETIME", "length": None, "nullable": nullable}
    elif ftype in ("VARCHAR", "CSTRING"):
        if 0 < length <= MYSQL_MAX_VARCHAR:
            return {"type": "VARCHAR", "length": length, "nullable": nullable}
        return {"type": "TEXT", "length": None, "nullable": nullable}
    elif ftype == "CHAR":
        if 0 < length <= 255:
            return {"type": "CHAR", "length": length, "nullable": nullable}
        return {"type": "TEXT", "length": None, "nullable": nullable}
    elif ftype == "BLOB":
        if col.get("sub_type") == 1:
            return {"type": "LONGTEXT", "length": None, "nullable": nullable}
        return {"type": "LONGBLOB", "length": None, "nullable": nullable}
    else:
        # Unknown Firebird type code -> safest lossless fallback
        return {"type": "LONGTEXT", "length": None, "nullable": nullable}


def firebird_column_to_mysql_ddl(col: dict) -> str:
    """Legacy direct-MySQL path (sync-service/database/mysql_client.py) —
    unused by the Agent's push flow (agent/run.py), kept until that old path
    is fully retired. Builds on firebird_column_to_mysql_type() so the type
    decision itself lives in exactly one place.
    """
    decided = firebird_column_to_mysql_type(col)
    if decided["type"] == "DECIMAL":
        sql_type = f"DECIMAL({decided['length']},{decided['scale']})"
    else:
        sql_type = f"{decided['type']}({decided['length']})" if decided["length"] else decided["type"]
    not_null = " NULL" if decided["nullable"] else " NOT NULL"

    return f"`{col['name']}` {sql_type}{not_null}"
