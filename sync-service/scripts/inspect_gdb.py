"""
Read-only Firebird GDB metadata inspector for STOCKWISE <-> Accurate integration.

Connects to the Accurate Firebird database (read-only: only RDB$ system tables
are queried, never business data) and dumps:
  - table list (with row counts)
  - columns per table (name, type, nullable, default)
  - primary keys
  - foreign keys
  - indexes

Usage:
    python scripts/inspect_gdb.py [--markdown docs/GDB_ANALYSIS.md]

Connection is configured via environment variables (never hardcode credentials):
    FIREBIRD_HOST       default: localhost
    FIREBIRD_PORT       default: 3050
    FIREBIRD_DATABASE   default: D:\\STOCKWISE\\GUDANGSIG2025.GDB
    FIREBIRD_USER       required
    FIREBIRD_PASSWORD   required
    FIREBIRD_CLIENT_LIB default: D:\\FirebirdLocal\\Firebird_25\\bin\\fbclient.dll
"""
import os
import sys
import argparse
from datetime import datetime

import fdb

FIREBIRD_TYPE_NAMES = {
    7: "SMALLINT",
    8: "INTEGER",
    9: "QUAD",
    10: "FLOAT",
    12: "DATE",
    13: "TIME",
    14: "CHAR",
    16: "BIGINT",
    27: "DOUBLE PRECISION",
    35: "TIMESTAMP",
    37: "VARCHAR",
    40: "CSTRING",
    45: "BLOB_ID",
    261: "BLOB",
}


def get_connection():
    host = os.environ.get("FIREBIRD_HOST", "localhost")
    port = os.environ.get("FIREBIRD_PORT", "3050")
    database = os.environ.get("FIREBIRD_DATABASE", r"D:\STOCKWISE\GUDANGSIG2025.GDB")
    user = os.environ.get("FIREBIRD_USER")
    password = os.environ.get("FIREBIRD_PASSWORD")
    client_lib = os.environ.get(
        "FIREBIRD_CLIENT_LIB", r"D:\FirebirdLocal\Firebird_25\bin\fbclient.dll"
    )

    if not user or not password:
        print(
            "ERROR: FIREBIRD_USER and FIREBIRD_PASSWORD environment variables are "
            "required. Refusing to guess or fall back to default credentials.",
            file=sys.stderr,
        )
        sys.exit(1)

    fdb.load_api(fb_library_name=client_lib)
    dsn = f"{host}/{port}:{database}"
    try:
        # See firebird/introspect.py::connect() — this GDB's text columns are
        # declared charset NONE but actually hold Windows-1252 bytes.
        return fdb.connect(dsn=dsn, user=user, password=password, charset="WIN1252")
    except fdb.fbcore.DatabaseError as exc:
        print(f"ERROR: could not connect to '{dsn}' as '{user}': {exc}", file=sys.stderr)
        sys.exit(1)


def fetch_tables(cur):
    cur.execute(
        """
        SELECT TRIM(r.RDB$RELATION_NAME) AS TABLE_NAME
        FROM RDB$RELATIONS r
        WHERE (r.RDB$SYSTEM_FLAG = 0 OR r.RDB$SYSTEM_FLAG IS NULL)
          AND r.RDB$VIEW_BLR IS NULL
        ORDER BY 1
        """
    )
    return [row[0] for row in cur.fetchall()]


def fetch_row_count(cur, table):
    try:
        cur.execute(f'SELECT COUNT(*) FROM "{table}"')
        return cur.fetchone()[0]
    except Exception as exc:
        return f"ERROR: {exc}"


def fetch_columns(cur, table):
    cur.execute(
        """
        SELECT
            TRIM(rf.RDB$FIELD_NAME) AS COLUMN_NAME,
            f.RDB$FIELD_TYPE AS FIELD_TYPE,
            f.RDB$FIELD_LENGTH AS FIELD_LENGTH,
            f.RDB$FIELD_PRECISION AS FIELD_PRECISION,
            f.RDB$FIELD_SCALE AS FIELD_SCALE,
            rf.RDB$NULL_FLAG AS NOT_NULL,
            rf.RDB$FIELD_POSITION AS FIELD_POSITION,
            rf.RDB$DEFAULT_SOURCE AS DEFAULT_SOURCE
        FROM RDB$RELATION_FIELDS rf
        JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
        WHERE rf.RDB$RELATION_NAME = ?
        ORDER BY rf.RDB$FIELD_POSITION
        """,
        (table,),
    )
    cols = []
    for row in cur.fetchall():
        name, ftype, flen, fprec, fscale, notnull, pos, default = row
        type_name = FIREBIRD_TYPE_NAMES.get(ftype, f"TYPE_{ftype}")
        cols.append(
            {
                "name": name.strip(),
                "type": type_name,
                "length": flen,
                "precision": fprec,
                "scale": fscale,
                "not_null": bool(notnull),
                "default": default.strip() if default else None,
            }
        )
    return cols


def fetch_primary_key(cur, table):
    cur.execute(
        """
        SELECT TRIM(s.RDB$FIELD_NAME)
        FROM RDB$RELATION_CONSTRAINTS rc
        JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
        WHERE rc.RDB$RELATION_NAME = ? AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
        ORDER BY s.RDB$FIELD_POSITION
        """,
        (table,),
    )
    return [row[0].strip() for row in cur.fetchall()]


def fetch_foreign_keys(cur, table):
    cur.execute(
        """
        SELECT
            TRIM(rc.RDB$CONSTRAINT_NAME),
            TRIM(s.RDB$FIELD_NAME),
            TRIM(rc2.RDB$RELATION_NAME),
            TRIM(s2.RDB$FIELD_NAME)
        FROM RDB$RELATION_CONSTRAINTS rc
        JOIN RDB$REF_CONSTRAINTS ref ON ref.RDB$CONSTRAINT_NAME = rc.RDB$CONSTRAINT_NAME
        JOIN RDB$RELATION_CONSTRAINTS rc2 ON rc2.RDB$CONSTRAINT_NAME = ref.RDB$CONST_NAME_UQ
        JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
        JOIN RDB$INDEX_SEGMENTS s2 ON s2.RDB$INDEX_NAME = rc2.RDB$INDEX_NAME
            AND s2.RDB$FIELD_POSITION = s.RDB$FIELD_POSITION
        WHERE rc.RDB$RELATION_NAME = ? AND rc.RDB$CONSTRAINT_TYPE = 'FOREIGN KEY'
        ORDER BY 1, s.RDB$FIELD_POSITION
        """,
        (table,),
    )
    fks = {}
    for name, col, ref_table, ref_col in cur.fetchall():
        fk = fks.setdefault(
            name.strip(), {"ref_table": ref_table.strip(), "columns": [], "ref_columns": []}
        )
        fk["columns"].append(col.strip())
        fk["ref_columns"].append(ref_col.strip())
    return fks


def fetch_indexes(cur, table):
    cur.execute(
        """
        SELECT
            TRIM(i.RDB$INDEX_NAME),
            i.RDB$UNIQUE_FLAG,
            TRIM(s.RDB$FIELD_NAME)
        FROM RDB$INDICES i
        JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
        WHERE i.RDB$RELATION_NAME = ? AND i.RDB$FOREIGN_KEY IS NULL
        ORDER BY 1, s.RDB$FIELD_POSITION
        """,
        (table,),
    )
    idxs = {}
    for name, unique, col in cur.fetchall():
        idx = idxs.setdefault(name.strip(), {"unique": bool(unique), "columns": []})
        idx["columns"].append(col.strip())
    return idxs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--markdown",
        default=None,
        help="Optional path to also write a Markdown report (e.g. docs/GDB_ANALYSIS.md)",
    )
    parser.add_argument(
        "--skip-row-counts",
        action="store_true",
        help="Skip COUNT(*) per table (faster, useful for a quick structure-only pass)",
    )
    args = parser.parse_args()

    con = get_connection()
    cur = con.cursor()

    print("=" * 60)
    print("FIREBIRD VERSION")
    print("=" * 60)
    version = con.server_version
    print(version)
    print()

    tables = fetch_tables(cur)
    print("=" * 60)
    print(f"TABLES ({len(tables)} non-system tables found)")
    print("=" * 60)

    report_lines = []
    report_lines.append("# GDB Analysis: GUDANGSIG2025.GDB (Accurate 5 / Firebird)\n")
    report_lines.append(f"Generated: {datetime.now().isoformat()}\n")
    report_lines.append(f"\nFirebird server version: `{version}`\n")
    report_lines.append(f"\nTotal non-system tables: **{len(tables)}**\n")
    report_lines.append("\n## Table list\n")
    report_lines.append("| Table | Row count |\n|---|---|\n")

    table_details = []
    for table in tables:
        row_count = "skipped" if args.skip_row_counts else fetch_row_count(cur, table)
        print(f"\nTABLE: {table}  (rows: {row_count})")

        columns = fetch_columns(cur, table)
        pk = fetch_primary_key(cur, table)
        fks = fetch_foreign_keys(cur, table)
        idxs = fetch_indexes(cur, table)

        print("  COLUMNS:")
        for c in columns:
            null_str = "NOT NULL" if c["not_null"] else "NULL"
            print(f"    - {c['name']} {c['type']} {null_str}")

        print(f"  PRIMARY KEY: {', '.join(pk) if pk else '(none)'}")

        print("  FOREIGN KEYS:")
        if fks:
            for name, fk in fks.items():
                print(
                    f"    - {name}: ({', '.join(fk['columns'])}) -> "
                    f"{fk['ref_table']}({', '.join(fk['ref_columns'])})"
                )
        else:
            print("    (none)")

        print("  INDEXES:")
        if idxs:
            for name, idx in idxs.items():
                uniq = "UNIQUE" if idx["unique"] else "NON-UNIQUE"
                print(f"    - {name} [{uniq}]: {', '.join(idx['columns'])}")
        else:
            print("    (none)")

        report_lines.append(f"| {table} | {row_count} |\n")
        table_details.append(
            {
                "table": table,
                "row_count": row_count,
                "columns": columns,
                "pk": pk,
                "fks": fks,
                "indexes": idxs,
            }
        )

    if args.markdown:
        report_lines.append("\n## Table detail\n")
        for d in table_details:
            report_lines.append(f"\n### {d['table']}\n")
            report_lines.append(f"Row count: {d['row_count']}\n")
            report_lines.append("\n| Column | Type | Nullable | Default |\n|---|---|---|---|\n")
            for c in d["columns"]:
                nullable = "NO" if c["not_null"] else "YES"
                default = c["default"] or ""
                report_lines.append(f"| {c['name']} | {c['type']} | {nullable} | {default} |\n")
            report_lines.append(
                f"\nPrimary key: {', '.join(d['pk']) if d['pk'] else '(none)'}\n"
            )
            if d["fks"]:
                report_lines.append("\nForeign keys:\n")
                for name, fk in d["fks"].items():
                    report_lines.append(
                        f"- `{name}`: ({', '.join(fk['columns'])}) -> "
                        f"`{fk['ref_table']}`({', '.join(fk['ref_columns'])})\n"
                    )
            if d["indexes"]:
                report_lines.append("\nIndexes:\n")
                for name, idx in d["indexes"].items():
                    uniq = "UNIQUE" if idx["unique"] else "NON-UNIQUE"
                    report_lines.append(f"- `{name}` [{uniq}]: {', '.join(idx['columns'])}\n")

        os.makedirs(os.path.dirname(args.markdown) or ".", exist_ok=True)
        with open(args.markdown, "w", encoding="utf-8") as f:
            f.writelines(report_lines)
        print(f"\nMarkdown report written to: {args.markdown}")

    con.close()


if __name__ == "__main__":
    main()
