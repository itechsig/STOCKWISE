"""MySQL side of the mirror: create/replace accurate_* tables and bulk-load rows."""
import pymysql

from config.settings import MySQLSettings
from mapping.type_mapping import firebird_column_to_mysql_ddl


def connect():
    return pymysql.connect(**MySQLSettings.connect_kwargs())


def create_mirror_table(conn, mysql_table: str, columns: list, primary_key: list):
    col_defs = [firebird_column_to_mysql_ddl(c) for c in columns]
    if primary_key:
        pk_cols = ", ".join(f"`{c}`" for c in primary_key)
        col_defs.append(f"PRIMARY KEY ({pk_cols})")

    ddl = (
        f"CREATE TABLE IF NOT EXISTS `{mysql_table}` (\n  "
        + ",\n  ".join(col_defs)
        + f"\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={MySQLSettings.collation}"
    )
    with conn.cursor() as cur:
        cur.execute(f"DROP TABLE IF EXISTS `{mysql_table}`")
        cur.execute(ddl)
    conn.commit()


def bulk_insert(conn, mysql_table: str, columns: list, rows_iter, batch_size=1000):
    col_names = ", ".join(f"`{c['name']}`" for c in columns)
    placeholders = ", ".join(["%s"] * len(columns))
    insert_sql = f"INSERT INTO `{mysql_table}` ({col_names}) VALUES ({placeholders})"

    total = 0
    batch = []
    with conn.cursor() as cur:
        for row in rows_iter:
            batch.append(row)
            if len(batch) >= batch_size:
                cur.executemany(insert_sql, batch)
                total += len(batch)
                batch.clear()
        if batch:
            cur.executemany(insert_sql, batch)
            total += len(batch)
    conn.commit()
    return total
