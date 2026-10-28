"""
Initial (full) sync: Accurate Firebird GDB -> MySQL `stockwise` DB, mirrored tables.

Usage:
    python -m sync.initial_sync

Reads FIREBIRD_USER/FIREBIRD_PASSWORD from the environment (required, never
defaulted/guessed). MySQL connection defaults to the local `stockwise` DB
(root/no-pass, matching this project's dev environment) — override via
MYSQL_HOST/MYSQL_PORT/MYSQL_DATABASE/MYSQL_USER/MYSQL_PASSWORD if needed.

This is a full mirror, not an upsert — each run drops and recreates the
`accurate_*` tables from scratch. Incremental sync (upsert by primary key,
sync_logs, locking, scheduling) is a later phase, not built yet.
"""
import sys
import time

sys.path.insert(0, ".")

from firebird import introspect as fb
from database import mysql_client as mysql
from mapping.tables import MIRROR_TABLES, mysql_table_name


def sync_table(fb_conn, mysql_conn, table: str):
    cur = fb_conn.cursor()
    columns = fb.fetch_columns(cur, table)
    if not columns:
        return {"table": table, "status": "SKIPPED (table not found)", "rows": 0}

    primary_key = fb.fetch_primary_key(cur, table)
    mysql_table = mysql_table_name(table)

    mysql.create_mirror_table(mysql_conn, mysql_table, columns, primary_key)

    rows_iter = fb.fetch_rows(cur, table, columns)
    try:
        inserted = mysql.bulk_insert(mysql_conn, mysql_table, columns, rows_iter)
        return {"table": table, "status": "SUCCESS", "rows": inserted}
    except Exception as exc:
        return {"table": table, "status": f"FAILED: {exc}", "rows": 0}


def main():
    print("=" * 60)
    print("STOCKWISE INITIAL SYNC (Accurate -> MySQL)")
    print("=" * 60)

    fb_conn = fb.connect()
    mysql_conn = mysql.connect()

    results = []
    started = time.time()
    for table in MIRROR_TABLES:
        t0 = time.time()
        result = sync_table(fb_conn, mysql_conn, table)
        result["seconds"] = round(time.time() - t0, 2)
        results.append(result)
        print(
            f"{result['table']:<24} -> {mysql_table_name(result['table']):<28} "
            f"{result['status']:<12} rows={result['rows']:<8} ({result['seconds']}s)"
        )

    fb_conn.close()
    mysql_conn.close()

    print()
    print("=" * 60)
    ok = sum(1 for r in results if r["status"] == "SUCCESS")
    failed = sum(1 for r in results if r["status"] != "SUCCESS")
    total_rows = sum(r["rows"] for r in results)
    print(f"SYNC {'SUCCESS' if failed == 0 else 'COMPLETED WITH ERRORS'}")
    print(f"Tables OK: {ok} / {len(results)}   Total rows: {total_rows}")
    print(f"Elapsed: {round(time.time() - started, 1)}s")
    print("=" * 60)

    if failed:
        sys.exit(1)


if __name__ == "__main__":
    main()
