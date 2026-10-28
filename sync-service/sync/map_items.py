"""
Transform step: accurate_item (raw staging) -> items (Stockwise business table).

Matches Accurate's ITEMNO against Stockwise's items.code (verified 8,955+/9,179
exact matches — see docs/ACCURATE_MAPPING.md) and updates only three columns:
`accurate_synced_at`, `accurate_qty_onhand` (from ITEM.QUANTITY), and
`accurate_qty_onorder` (from ITEM.ONORDER). These are cross-check reference
columns only — Stockwise's own per-warehouse `inventory` table (actual/reserved/
available) is untouched, since Accurate's stock figure is a company-wide total
with no per-warehouse breakdown (see the WAREHS finding in ACCURATE_MAPPING.md).
No description/category/blueprint/or any other pre-existing visible field is
touched by this script.

New Accurate items with no Stockwise match are NOT created here — that's a
separate, one-time step (`php artisan accurate:import-new-items`, backend side,
so Item's `description_normalized` save-hook runs correctly).

Safe to run repeatedly (idempotent UPDATE...JOIN, never inserts/deletes).

Usage:
    python -m sync.map_items
"""
import sys
from datetime import datetime

sys.path.insert(0, ".")

from database import mysql_client as mysql


def main():
    conn = mysql.connect()
    with conn.cursor() as cur:
        now = datetime.now()

        cur.execute(
            """
            UPDATE items i
            JOIN accurate_item a ON i.code = a.ITEMNO
            SET i.accurate_synced_at = %s,
                i.accurate_qty_onhand = a.QUANTITY,
                i.accurate_qty_onorder = a.ONORDER
            """,
            (now,),
        )
        matched_updated = cur.rowcount

        cur.execute(
            """
            SELECT COUNT(*) FROM accurate_item a
            LEFT JOIN items i ON i.code = a.ITEMNO
            WHERE i.id IS NULL
            """
        )
        unmatched_accurate = cur.fetchone()[0]

        cur.execute(
            """
            SELECT COUNT(*) FROM items i
            LEFT JOIN accurate_item a ON i.code = a.ITEMNO
            WHERE a.ITEMNO IS NULL
            """
        )
        unmatched_stockwise = cur.fetchone()[0]

        cur.execute("SELECT COUNT(*) FROM items")
        total_items = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM accurate_item")
        total_accurate = cur.fetchone()[0]

    conn.commit()
    conn.close()

    print("=" * 60)
    print("ITEM MAPPING (accurate_item -> items, code == ITEMNO)")
    print("=" * 60)
    print(f"Total items (Stockwise):        {total_items}")
    print(f"Total ITEM (Accurate):          {total_accurate}")
    print(f"Matched & stamped synced_at:    {matched_updated}")
    print(f"Accurate items with no match in Stockwise:  {unmatched_accurate}")
    print(f"Stockwise items with no match in Accurate:  {unmatched_stockwise}")
    print()
    print("No description/category/blueprint/other visible field was changed.")
    print("No new items were inserted. Only `accurate_synced_at` was stamped.")


if __name__ == "__main__":
    main()
