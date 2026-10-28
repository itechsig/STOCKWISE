# Accurate → Stockwise Mapping (Preliminary)

Generated 2026-09-11 from a live read-only inspection of `GUDANGSIG2025.GDB` (Accurate 5 Deluxe,
Firebird 2.5, 234 non-system tables, verified against the actual copied company database — not
guessed). Full table/column/PK/FK/index detail: `docs/GDB_ANALYSIS.md`.

This is a **preliminary** mapping per the project's Phase-1 stop condition — it identifies candidate
tables and columns and their likely Stockwise destination, but does not commit to a final MySQL
schema. Anything not directly observed in the data is marked `[NEEDS CONFIRMATION]`.

## How access was established (context for later sync-service work)

- Accurate does not expose per-human-user Firebird logins. `USERS` (6 rows: USERID, USERNAME,
  USERPASSWORD, plus per-module CRUD permission flags like SALESC/SALESE/SALESR) is Accurate's own
  **application-level** login table — this is where a login like the app user "ayu" actually lives,
  not as a Firebird server principal.
- Real Firebird-level table privileges in this GDB are granted to only 4 principals: `CPS#1`
  (CPSSoft's internal technical account — the one actually used for real reads/writes), `GUEST`,
  `POS`, and `PUBLIC` (system tables only). This is why the sync service must connect as the
  **technical account**, not as any individual Accurate user.
- The GDB also contains a **SQL ROLE literally named `SYSDBA`** (an Accurate-specific role, unrelated
  to Firebird's real superuser) — connecting as the literal Firebird `SYSDBA` login to this specific
  database fails by design (Firebird refuses login when username == an existing role name in the
  target db). Not relevant to production; noted here so it isn't rediscovered as a mystery later.

## Accurate-Sourced Data

### Products / Items

```
Accurate: ITEM (9,600 rows)
  ITEMNO              -> stockwise.items.accurate_id / code   (primary business key, VARCHAR)
  ITEMDESCRIPTION      -> items.name
  ITEMTYPE, SUBITEM, PARENTITEM  -> [NEEDS CONFIRMATION] item type/BOM-parent semantics
  QUANTITY             -> current on-hand qty (company-wide; see Warehouse note below)
  ONORDER, ONSALES     -> committed/on-PO quantities
  UNITPRICE..5         -> multiple price tiers -> items price list (native Stockwise concept, or
                          new accurate-sourced price tiers table) [NEEDS CONFIRMATION which tier(s)
                          Stockwise actually needs]
  COST, COSTMETHOD     -> costing
  MINIMUMQTY           -> Accurate's own reorder point — DO NOT conflate with Stockwise's own
                          safety_stock (different formula, native to Stockwise per
                          docs/calculation-engine.md); keep both, clearly labeled by source
  UNIT1/2/3, RATIO2/3   -> unit of measure + conversion ratios -> items.unit + unit conversions
  INVENTORYGLACCNT, COGSGLACCNT, SALESGLACCNT, ... -> GL account codes, relevant only if Stockwise
                          ever needs accounting posting references (not currently a Stockwise
                          feature) [NEEDS CONFIRMATION whether in scope]
  SUSPENDED            -> items.is_active (inverse)
  PREFEREDVENDOR       -> FK-like to PERSONDATA.ID (vendor) — no enforced FK found; confirm via data
        ↓
Stockwise: items (extend existing table)
  accurate_id       (= ITEMNO, string business key — Accurate has no surrogate integer item id)
  name              (= ITEMDESCRIPTION)
  unit              (= UNIT1, extend for UNIT2/3 ratios if multi-UOM needed)
  accurate_qty_onhand, accurate_qty_onorder  (native columns to hold Accurate's own view of stock,
      separate from Stockwise's own Actual/Reserved/Available ledger — see Data Ownership doc)
  source = 'accurate' | 'stockwise' | 'excel'  (existing native/Excel items keep their own source)
  last_synced_at
```

Note: `ITEMCATEGORY` exists as a table but has **0 rows** in this dataset — Accurate's category
tree is unused/empty here. Item categorization for sync purposes cannot come from Accurate;
Stockwise's own 452-category tree (from the Excel import, see
[[project_stockwise_expansion]]/`docs/excel-data-mapping.md`) remains the source for category.

### Warehouses

```
Accurate: WAREHS (1 row only — this Accurate install is effectively single-warehouse)
  WAREHOUSEID  -> warehouses.accurate_id
  NAME         -> warehouses.name
  DESCRIPTION, ADDRESS1-3, PIC, SUSPENDED
        ↓
Stockwise: warehouses (extend existing table with accurate_id)
```

`ITEMBALANCEWAREHOUSE` (per-item-per-warehouse balance) exists structurally but has **0 rows** —
confirms this Accurate deployment doesn't track stock per-warehouse internally; real quantity lives
directly on `ITEM.QUANTITY` (company-wide) and in the monthly-bucketed `ITEMBALANCE` table below.
Since Stockwise already has multi-site/multi-warehouse (SDA/BPN) via its own Excel-derived data, this
is a real mismatch to resolve in the ERD/ownership discussion, not to paper over — Accurate's
single-warehouse total cannot be exploded into Stockwise's per-warehouse-location model without an
explicit decision on where the split comes from. `[NEEDS CONFIRMATION]`

### Item balances / stock history (candidate source for stock sync)

```
Accurate: ITEMBALANCE (5,640 rows) — one row per ITEMNO per fiscal year (GLYEAR),
  with OPENINGBAL + AMOUNT1..AMOUNT8+ (monthly-bucketed values, same shape as the Excel
  "SAFETY STOCK" sheets' monthly-usage columns already reverse-engineered in
  docs/excel-data-mapping.md — [NEEDS CONFIRMATION] whether AMOUNT1..12 here are quantity movements
  or value/currency amounts; column name alone doesn't disambiguate, must check against known-good
  ITEM data before use)
Accurate: ITEMADJ (2,544 rows) / ITADJDET — item adjustment headers/detail, a real stock-movement
  candidate (adjustments are exactly the kind of event Stockwise's own stock_movements ledger
  models)
Accurate: ITEMHIST (16,045 rows) — likely the actual per-transaction item movement ledger
  (largest item-related table after AUDIT); strong candidate for the real source of "what changed
  and when" that a sync service would tail. [NEEDS CONFIRMATION] of exact semantics — not yet
  inspected row-by-row, only structurally.
```

### People (customers / vendors — unified table, not separate)

```
Accurate: PERSONDATA (575 rows) — single table for all person/company entities
  ID            -> accurate_id
  PERSONNO      -> business code
  PERSONTYPE    -> discriminator: 0 (195 rows) vs 1 (380 rows) — [NEEDS CONFIRMATION] which value
                   means Customer vs Vendor; CUSTTYPE (1 row) exists separately and may hold the
                   actual label. Do not assume 0=vendor/1=customer without checking CUSTTYPE and/or
                   a few real records with the user's confirmation.
  NAME, ADDRESSLINE1/2, CITY, STATEPROV, ZIPCODE, COUNTRY, CONTACT, FAX, EMAIL, WEBPAGE, SUSPENDED
        ↓
Stockwise: split into customers / vendors only once PERSONTYPE is confirmed, or keep as one
  accurate_persons staging table with a `source_persontype` passthrough column until confirmed.
```

`CUSTOMEROBLIST` / `VENDOROBLIST` (0 rows each) are Accurate UI grouping trees, not master data —
not relevant to sync.

### Purchasing / Sales (lower priority for Phase 1, noted for later phases)

```
PO (1,270) / PODET (2,275)              -> purchasing (STOCKWISE already has its own PPB->PO->RI
                                            flow per docs/business-process.md; whether Accurate POs
                                            need to feed in, or stay Accurate-only, is a
                                            [NEEDS CONFIRMATION] product decision, not a data gap)
SO (69) / SODET (667)                    -> sales orders — Stockwise currently has no sales-order
                                            concept; [NEEDS CONFIRMATION] whether in scope at all
ARINV (3,396) / ARINVDET (8,465)         -> AR invoices (customer-facing)
APINV (2,277) / APITMDET (4,786)         -> AP invoices (vendor-facing)
REQUISITION (419) / REQUISITIONDET (2,415) -> internal purchase requisitions — conceptually close to
                                            Stockwise's own material_requests, but likely a separate
                                            Accurate-native flow; do not conflate without confirming
GLACCNT (98)                             -> chart of accounts — only relevant if GL posting ever
                                            becomes a Stockwise feature (not currently)
```

### Not relevant to sync (Accurate-internal / operational noise)

- `AUDIT` (35,242) / `AUDITDET` (27,282) — Accurate's own audit log of user actions inside the app.
- `TRANSHISTORY` (18,416), `GLHIST` (16,434) — Accurate's internal transaction/GL history, likely
  superseded for Stockwise purposes by the more specific `ITEMHIST`/`ITEMBALANCE` tables above.
- `USERS` (6), `USERS_MEMREPORTS` (34) — Accurate's own login/reporting-permission tables; Stockwise
  has its own independent RBAC (`docs/roles-permissions.md`) and must not import Accurate logins.
- `DELETED_TRANSACTIONS` (333), `LOCK_INDENT_TABLE` (4) — Accurate internal housekeeping.
- `TEMPLATE`/`TEMPLDET` (99/4,768) — Accurate print/document templates, not data.

## Stockwise Native Data (unchanged, no Accurate equivalent — keep as-is)

`safety_stock` (own formula, see [[project_stockwise_expansion]]), `purchase_requests`/`ppb`/
`ppb_items`, `warehouse_operations` (NPBG/pickup/stock opname), `cylinder`/tracking modules
(Lend/Borrow/STPP/TyreChange/Maintenance/Manufacturing/UsedReturn), `approval`/RBAC. None of these
concepts exist in Accurate's schema — confirmed by table-name search, not assumed.

## Open items before schema design (Phase 2)

1. Confirm `PERSONTYPE` 0/1 meaning (query `CUSTTYPE` content + ask user, don't infer).
2. Confirm `AMOUNT1..N` semantics in `ITEMBALANCE`/`ITEMBALANCEWAREHOUSE` (qty vs currency value).
3. Confirm whether `ITEMHIST` is genuinely the transactional stock-movement source (row-level check
   needed, not just structural).
4. Decide product scope: does Stockwise need Accurate's PO/SO/AR/AP data at all, or only
   Item/Warehouse/Stock-balance data? (Drives how much of `docs/GDB_ANALYSIS.md`'s 234 tables
   actually matter vs. can be ignored.)
5. Single-warehouse-in-Accurate vs. multi-warehouse-in-Stockwise mismatch (see Warehouses section)
   needs an explicit ownership decision, not a default assumption.
