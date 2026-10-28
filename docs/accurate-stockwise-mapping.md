# Accurate → Stockwise Field Mapping

Scope: this phase (items only, per the "Accurate sync foundation" brief). Every mapping below was
verified against real data (see `docs/accurate-database-analysis.md`) before being implemented —
none of it is a guess.

## Items

```
Accurate.ITEM.ITEMNO
        ↓
items.code
        ↓
Direct mapping — verified 8,955/8,957 exact matches on the original Stockwise item list (99.98%).
This is also the only identifier Accurate has for a product (there is no separate numeric
"Accurate ID" for ITEM distinct from ITEMNO in this schema — unlike PERSONDATA, which does have a
numeric ID column). code IS the accurate identifier here.

Accurate.ITEM.ITEMDESCRIPTION
        ↓
items.description (new items only)
        ↓
Direct mapping, but ONLY used when creating a brand-new item. For an item that already exists in
Stockwise (matched by code), description is NEVER overwritten — Stockwise's own curated description
is the source of truth for existing items.

Accurate.ITEM.UNIT1
        ↓
items.unit_id (new items only)
        ↓
Looked up against units.code (case-insensitive). Left null if no matching unit exists — never
guessed. Also used as a FILTER: an ITEM row with no UNIT1 is treated as a category/placeholder node
(see PARENTITEM finding below), not a real product, and is never inserted as an item.

Accurate.ITEM.SUSPENDED
        ↓
items.is_active (new items only)
        ↓
Inverted (SUSPENDED=1 -> is_active=false).

Accurate.ITEM.QUANTITY
        ↓
items.accurate_qty_onhand
        ↓
Direct mapping, reference-only. Written for BOTH new and existing items, every sync. Deliberately
NOT merged into the per-warehouse `inventory` table — Accurate's quantity is a company/site-wide
total with no per-warehouse breakdown (WAREHS has only 1 row, "CENTRE" — see the database analysis
doc's Warehouse section). Shown for comparison, never replaces Stockwise's own actual/reserved/
available ledger.

Accurate.ITEM.ONORDER
        ↓
items.accurate_qty_onorder
        ↓
Direct mapping, same reference-only treatment as QUANTITY.

Accurate.ITEM.CATEGORYID / Accurate.ITEMCATEGORY
        ↓
(not mapped)
        ↓
ITEMCATEGORY is empty (0 rows) and CATEGORYID is null on all 9,600 ITEM rows — this company never
used Accurate's category feature. Stockwise's own category tree (from the Excel import) remains the
only category source. NOT mapped, not faked.

Accurate.ITEM.PARENTITEM (+ FIRSTPARENTITEM/INDENTLEVEL/LFT/RGT/ISROOT)
        ↓
(not mapped yet — documented for a future phase)
        ↓
Confirmed to be Accurate's *real* category/grouping mechanism for this company (self-referencing
tree inside ITEM, 420 distinct parent codes, dotted hierarchical naming e.g. AUT.01.01 = "BAN LUAR
(TIRES)"). Used in this phase only as a FILTER (rows with no unit + present as someone's PARENTITEM
are skipped, not inserted as products) — not yet transformed into Stockwise's category tree. That
mapping is real future work, not started.
```

## Not in scope this phase

`PERSONDATA` (customers/vendors), `PO`/`PODET`/`SO`/`SODET`/`ARINV`/`ARINVDET`/`APINV`/`APITMDET`/
`REQUISITION`/`REQUISITIONDET` are already mirrored into `accurate_*` MySQL staging tables (from
earlier session work) but have **no mapping to any Stockwise business table** — explicitly out of
scope per this phase's "items/stock/warehouse only, no business features yet" instruction.
