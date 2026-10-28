# Accurate Database Analysis (GUDANGSIG2025.GDB)

STEP 1 deliverable per "PROJECT: STOCKWISE — ACCURATE DATABASE SYNC FOUNDATION" (2026-09-12).
Everything below comes from live queries against the real GDB (via Firebird 2.5.9, user `CPS#1`),
not assumptions. Where something genuinely isn't known yet, it's marked **UNKNOWN**. Full raw
metadata for all 234 tables (every column/PK/FK/index) is already dumped separately in
`docs/GDB_ANALYSIS.md` — this document summarizes and interprets that dump for the sync foundation
specifically, plus adds findings discovered afterward while testing.

## 1. Database Information

- File: `GUDANGSIG2025.GDB`, a copy of the Accurate 5 Deluxe production Firebird database.
- Engine: Firebird 2.5 (ODS from the original Accurate install; server used to read it here is a
  clean Firebird 2.5.9 instance at `D:\FirebirdLocal\Firebird_25`, port 3050 — the original
  Accurate-bundled Firebird was uninstalled from this dev machine, so this is a separate, local-only
  server that only exists to open this one file).
- Non-system tables: **234**.
- Working read credential on this local copy: user `CPS#1` (Accurate's own internal technical
  account — the only principal in this GDB found with real table-level SELECT grants; see
  `docs/ACCURATE_MAPPING.md` for how this was determined and why `SYSDBA` and the human login `ayu`
  both fail).

## 2. Tables

234 tables total. The ones actually relevant to inventory sync (used below) are a small subset:
`ITEM`, `ITEMCATEGORY`, `WAREHS`, `ITEMBALANCE`, `ITEMBALANCEWAREHOUSE`, `ITEMADJ`, `ITADJDET`,
`ITEMHIST`, plus `PERSONDATA`/`CUSTTYPE` (customers/vendors, not inventory but already staged) and
purchasing/sales tables (`PO`, `PODET`, `SO`, `SODET`, `ARINV`, `ARINVDET`, `APINV`, `APITMDET`,
`REQUISITION`, `REQUISITIONDET`) which are out of scope for *this* foundation phase per the "don't
build business features yet" instruction — they are already mirrored into MySQL as raw staging
(`accurate_*`, see §10) but nothing reads them yet.

## 3. Important Columns

**ITEM** (9,600 rows) — the item/product master:
`ITEMNO` (varchar, business code, e.g. `AST.0001`), `ITEMDESCRIPTION` (varchar, product name),
`QUANTITY` (bigint, current on-hand — see §7), `ONORDER` (bigint), `UNIT1`/`UNIT2`/`UNIT3` (varchar,
unit of measure + conversion units), `RATIO2`/`RATIO3` (bigint, unit conversion ratios), `SUSPENDED`
(smallint 0/1, inverse of active), `CATEGORYID` (integer, FK-shaped — see §6), `PARENTITEM` (varchar,
self-reference — see §6), `MINIMUMQTY` (bigint, Accurate's own reorder point, NOT the same concept as
Stockwise's safety_stock formula), plus GL-account and pricing columns not relevant to this phase.

**WAREHS** (1 row) — warehouse master: `WAREHOUSEID` (integer PK), `NAME` (varchar), `DESCRIPTION`,
`ADDRESS1-3`, `PIC`, `SUSPENDED`. See §8 for why there's only one row and what it means.

**ITEMBALANCE** (5,640 rows) — `ITEMNO`, `GLYEAR` (smallint), `OPENINGBAL` (bigint), `AMOUNT1`
through `AMOUNT12`+ (bigint, monthly-bucketed). **Semantics of `AMOUNT1..N` are UNKNOWN** — could be
quantity or currency value, column name alone doesn't disambiguate and this hasn't been cross-checked
against a known transaction yet.

**ITEMBALANCEWAREHOUSE** (0 rows) — same shape as ITEMBALANCE plus `WAREHOUSEID`. Present in the
schema but **entirely unused** in this company's data (confirms Accurate was never used with
per-warehouse stock tracking here).

**ITEMHIST** (16,045 rows) — structurally the largest item-related transaction table after `AUDIT`.
Strong candidate for a real stock-movement ledger, but **its exact semantics are UNKNOWN** — not yet
inspected row-by-row against a known movement.

## 4. Primary Keys

- `ITEM.ITEMNO` (varchar) — business key, not a surrogate integer.
- `WAREHS.WAREHOUSEID` (integer, surrogate).
- `ITEMBALANCE`: composite `(ITEMNO, GLYEAR)`.
- `ITEMCATEGORY.CATEGORYID` (integer, surrogate) — see §6, table is empty regardless.

## 5. Relationships

- `ITEM.CATEGORYID` → `ITEMCATEGORY.CATEGORYID` (FK-shaped, but **0 of 9,600 ITEM rows have this
  populated** — the relationship exists in the schema but was never used by this company).
- `ITEM.PARENTITEM` → `ITEM.ITEMNO` (self-reference, no formal FK constraint found, but real data:
  see §6).
- `ITEMBALANCE.ITEMNO` → `ITEM.ITEMNO` (no formal FK found either, but the join is clean via app-level
  convention — Accurate doesn't appear to declare FK constraints for most of these relationships,
  they're enforced only by the application layer).

## 6. Item/Product Source

**Table: `ITEM`.** Confirmed as the correct source — verified by directly joining Stockwise's own
`items.code` against `ITEM.ITEMNO` in MySQL after staging: **8,955 of Stockwise's original 8,957
items match exactly** (99.98%). `items.code` and `ITEM.ITEMNO` use the identical coding convention.

**Category finding (confirmed, not guessed):** `ITEMCATEGORY` is unused (0 rows, `CATEGORYID` always
null). Instead, Accurate's real category/grouping structure for this company lives *inside* `ITEM`
itself: `PARENTITEM` is populated on 9,492/9,600 rows (99%), with 420 distinct parent codes using a
dotted hierarchical scheme (e.g. `AUT.01` = "AUTOMOTIVE WHEELS & TIRES", `AUT.01.01` = "BAN LUAR
(TIRES)" — real descriptions, confirmed by direct query), alongside nested-set columns
`FIRSTPARENTITEM`/`INDENTLEVEL`/`LFT`/`RGT`/`ISROOT`. Further confirmed: every one of the 645 ITEM
rows that had no match in Stockwise's original item list, and had `UNIT1 IS NULL`, is *exactly* the
set of 423 rows that are used as a `PARENTITEM` value elsewhere — i.e. these are category/placeholder
nodes, not real stockable products (they carry no unit of measure). The remaining 222 unmatched rows
(which do have a unit) are genuine new products; these have since been added to Stockwise's `items`
table (see §10).

## 7. Stock Source

**Table: `ITEM.QUANTITY`** for a current, company-wide total per item (confirmed populated, real
numbers observed e.g. 5, 1, 19, etc. for known items). This is the field currently staged into
Stockwise (see §10) — not `ITEMBALANCE`, because `ITEMBALANCE`'s `AMOUNT1..N` semantics are still
**UNKNOWN** (§3) and using an unconfirmed field for "the stock number" would be worse than using the
one field whose meaning (current total on hand) is unambiguous from its name and behavior.

## 8. Warehouse Source

**Table: `WAREHS`, but only 1 row, named `CENTRE`.** Investigated rather than assumed: Stockwise's 7
warehouses (`GUDANG 1`-`5`, `ETALASE`, `ETALASE 1`) all belong to the same Stockwise *site*
(`SIG-SDA` / "SIG Sidoarjo"). Accurate's own `BRANCHCODES` table has exactly 1 row, still at its
unconfigured default value (`BRANCHNAME = "< Branch Name >"`). Conclusion: `CENTRE` represents a
**company/site-wide total**, not any one of the 7 physical sub-locations — this Accurate install was
never used with per-warehouse stock subdivision. Confirmed with the user, who agreed Stockwise's own
stock concept should also be thought of as total-per-item first. Practical effect: Accurate stock data
cannot be safely attributed to any single one of the 7 Stockwise warehouse rows — see §10 for how this
was handled (a reference field on `items`, not a row in the per-warehouse `inventory` table).

## 9. Transaction Source

**UNKNOWN — not yet determined.** Candidates identified structurally: `ITEMHIST` (16,045 rows, likely
per-transaction item movement), `ITEMADJ`/`ITADJDET` (2,544/2,794 rows, item adjustment header/detail
— a real stock-movement pattern by name), `ITEMBALANCE` (monthly buckets, possibly derived/aggregated
rather than transactional). None of these has been validated against a known real movement yet. This
is flagged as open work, not started, per this phase's "actual stock from Accurate first, movement
history later" scoping (§19 of the master prompt).

## 10. Mapping Recommendation

Given the "don't copy the GDB 1:1, but don't rebuild Stockwise's proven schema either" tension, and
given real overlap was already found between the two systems' item codes, the approach taken (and
already partially implemented — see note below) is:

```
ACCURATE ITEM (raw, Firebird)
        ↓  full mirror, read-only
accurate_item (MySQL staging table — exact Accurate column names/types)
        ↓  match on code = ITEMNO (verified 99.98%)
items (Stockwise's own table — existing schema, NOT replaced)
        ↓  adds 3 new reference columns only:
        ↓    accurate_synced_at, accurate_qty_onhand, accurate_qty_onorder
        ↓  222 genuinely-new Accurate products (has UNIT1) inserted as new items;
        ↓  423 category-placeholder nodes (no UNIT1) deliberately NOT inserted
```

This keeps Accurate's raw shape in staging (`accurate_*`, per the master prompt's "sync/mapping layer"
principle — not a 1:1 copy pretending to be the app's real schema) while Stockwise's own `items` table
stays the single source of truth the app actually reads, extended with clearly-source-labeled
reference columns rather than overwritten fields.

**Work already done in this area before this document was requested** (so it isn't duplicated in
STEP 2-4): `sync-service/` (Python, reads Firebird read-only, `.env`-configured, no hardcoded
credentials) mirrors 20 Accurate tables into MySQL `accurate_*` staging tables — currently a full
drop-and-reload each run, **not yet the UPSERT-with-sync_batches/sync_logs design this new prompt
specifies** (§6-8 of the new prompt) — that formal batch/log/audit-trail structure has NOT been built
yet and is the natural next piece of work once this analysis is approved. `items` has 3 new nullable
columns (migrations `2026_09_12_012111_...` and `2026_09_12_013435_...`) and a one-time backend
command (`accurate:import-new-items`) added the 222 real new products. None of this touches
Stockwise's other business tables (requests/npbg/ppb/tracking/etc.) — untouched, per this phase's
scope.

## 11. Unknown / Uncertain Fields

- `PERSONDATA.PERSONTYPE` (0 or 1) — which value means customer vs. vendor. Not relevant to *this*
  phase (inventory only) but staged already; flagged so it isn't silently assumed later.
- `ITEMBALANCE.AMOUNT1..N` — quantity or currency value. **UNKNOWN.**
- `ITEMHIST` row-level semantics — structurally promising as a stock-movement ledger, **not
  validated.**
- Whether `ITEM.QUANTITY` is refreshed live by Accurate or only at period-close — **UNKNOWN**, matters
  for how "fresh" a synced number can be trusted to be.
