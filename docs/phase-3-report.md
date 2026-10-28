# PHASE 3 — Master Data + Inventory + Calculation Engine — Report

Format: brief §BC.

## PHASE
3 — Kategori, Satuan, Gudang, Rak, Barang, Safety Stock, Inventory (Actual/Reserved/Available +
movement ledger), + **STOCKWISE calculation engine** di backend. Semua master di-load dari `DATA.xlsx`.

## FILES CREATED

**backend/**
- Migrasi `2026_09_09_100001..100010` — `categories` (self-ref 4 level), `units`, `warehouses`,
  `warehouse_locations`, `items` (softDeletes), `item_aliases`, `item_safety_stocks`, `inventory`
  (kolom generated `available_qty = actual - reserved`), `stock_movements` (ledger append-only),
  `inventory_analysis_runs` + `inventory_snapshots`
- Model: `Category`, `Unit`, `Warehouse`, `WarehouseLocation`, `Item`, `ItemAlias`, `ItemSafetyStock`,
  `Inventory`, `StockMovement`, `InventorySnapshot`, `InventoryAnalysisRun`
- **Engine**: `app/Services/Inventory/StockwiseEngine.php` (murni), `ItemAnalysis` (VO),
  `InventoryAnalyzer.php` (parameter dataset: p75 lead time + median defisit; tulis snapshot),
  `StockLedgerService.php` (satu-satunya penulis stok, transactional)
- API: `ItemController` (index filter/search/sort/paginate, show, store, update, destroy),
  `InventoryController` (index, show, **analysis**, recompute, **projected**),
  `MasterDataController` (categories + tree, units, warehouses, warehouse-locations)
- Request: `StoreItemRequest`, `UpdateItemRequest`; Resource: `ItemResource`
- Importer: `UnitImporter`, `CategoryImporter`, `WarehouseImporter`, `ItemImporter` (bulk upsert +
  opening balance), `ItemSafetyStockImporter` (12 sheet, deteksi header, resolusi konflik NC-7)
- Command: `stockwise:analyze`; `Value` helper; `LimitFilter` (batasi baca Excel)
- Factory: `SiteFactory`, `CategoryFactory`, `UnitFactory`, `WarehouseFactory`, `ItemFactory`
- Tests: `tests/Unit/StockwiseEngineTest.php` (TC-INV-001..009),
  `tests/Feature/Inventory/{ItemApiTest,InventoryAnalysisTest}.php`

**frontend/**
- `src/types/inventory.ts`, `src/features/inventory/api.ts` (TanStack Query hooks)
- `src/components/DataTable.tsx` (+ `Pagination`), `src/components/ui/badge.tsx`
  (`Badge` / `StatusBadge` / `PriorityBadge`)
- `src/pages/ItemsPage.tsx` (+ `.test.tsx`), `src/pages/InventoryAnalysisPage.tsx`
- `AppLayout` sidebar nav (permission-filtered), `router.tsx` route baru per-permission

## FILES MODIFIED
- `app/Models/{Item,Category,Unit,Warehouse,Site}.php` — trait `HasFactory`
- `app/Support/Import/SpreadsheetReader.php` — `grid()` pakai `rangeToArray(calculateFormulas: false)`
  + `getHighestDataRow()` cap (perbaikan hang), `LimitFilter`
- `app/Console/Commands/StockwiseImportCommand.php` — daftar importer + `ini_set memory_limit`
- `database/seeders/DatabaseSeeder.php` — `stockwise:import` (semua) + `stockwise:analyze`
- `routes/api.php` — blok PHASE 3
- `frontend/src/{router,components/AppLayout}.tsx`

## COMMAND TO RUN

```bash
cd backend
php artisan migrate:fresh --seed     # migrasi + RBAC + akun + import Excel + analyze
php artisan stockwise:import         # ulang import (idempoten)
php artisan stockwise:analyze        # hitung ulang engine
php artisan test
php artisan serve --port=8001

cd frontend
npm run test && npm run build
npm run e2e
```

## TEST & EXPECTED & ACTUAL

| Test | Expected | Actual |
|------|----------|--------|
| `StockwiseEngineTest` (TC-INV-001..009 + invariants) | hijau | ✅ **8 passed, 49 assertions** |
| `ItemApiTest` (index/search/filter/paginate/CRUD/authz) | hijau | ✅ **7 passed** |
| `InventoryAnalysisTest` (analyzer snapshots, /analysis, /projected TC-INV-004, recompute, authz) | hijau | ✅ **5 passed** |
| `php artisan test` (total, PHASE 1–3) | hijau | ✅ **41 passed, 420 assertions** (SQLite in-memory) |
| Frontend `npm run test` | hijau | ✅ **4 passed** (ItemsPage + auth + health) |
| Frontend `tsc` / `oxlint` / `build` | bersih / sukses | ✅ (1 warning shadcn) |
| `migrate:fresh --seed` (MySQL) import Excel | lihat angka di bawah | ✅ ~2–3 menit |
| E2E (auth 3 + health 1 + inventory 2) | semua hijau | ✅ **6 passed** (karyawan diblokir dari /items terverifikasi) |

Hasil import `DATA.xlsx` ke MySQL:

| Objek | Jumlah |
|-------|-------:|
| Kategori (4 level) | 452 |
| Satuan (UoM) | 27 |
| Gudang / Rak | 7 / 164 |
| Barang (Kode Barang) | **8.957** (6 kode dobel + 1 kosong di-skip) |
| Opening balance (dari "SISA STOK") | 647 · sisanya `stock_known = false` (UNKNOWN) |
| Baris safety stock efektif | 933 item punya SS nyata (5.357 baris total, 46.132 baris SS=0 di-skip) |
| Snapshot analisis | AMAN 642 · BEP 7.450 · **TIDAK AMAN 865** · threshold LT p75 = 5 hari · median defisit = 1 |

BEP dominan karena ~92% barang belum punya stok awal (SISA STOK kosong → UNKNOWN) **dan** tanpa
safety stock → engine benar menandai BEP. Berubah setelah stock opname awal (PHASE 6).

## KEPUTUSAN & TEMUAN
1. **DATA.xlsx `DATABASE UTAMA` = 8.957 Kode Barang** (bukan ~5.900 seperti catatan lama — file
   bertambah). Semua diimport apa adanya (6 kode duplikat, 1 kosong → di-skip).
2. **"Sisa Stok" engine = Available** (A1). `SISA STOK` teks "STOK N PCS" → OPENING_BALANCE bila angka
   ada & gudang diketahui; kosong → `stock_known = false` (UNKNOWN, bukan 0 — D2/NC-4).
3. **Safety Stock**: 12 sheet, header tidak konsisten → dideteksi via "ITEM DESCRIPTION". Baris SS=0
   & MIN PR=0 di-skip (item muncul di banyak sheet untuk kategori yang bukan miliknya). Konflik
   (item punya SS di >1 sheet) → nilai terbesar jadi efektif, sisanya `needs_review` (NC-7).
4. **Excel read hang** (SS sheets): `toArray(calculateFormulas: true)` menghitung ulang ribuan formula.
   Fix: `rangeToArray(..., calculateFormulas: false)` + batas baris/kolom. Import penuh ~3–4 menit
   (one-time). Import command menaikkan `memory_limit` ke 1G.
5. `inventory.available_qty` = generated column `STORED` (jalan di MySQL & SQLite 3.31+).

## STATUS
**PASS** — 41 test backend + 4 test frontend + 6 e2e hijau; `migrate:fresh --seed` end-to-end sukses
(8.957 barang + 5.357 SS + 8.957 snapshot analisis di MySQL); endpoint `/api/items` &
`/api/inventory/analysis` terverifikasi via curl & browser.

## NEXT STEP
PHASE 4 — Request: `material_requests` + items, review + physical check + reserve, integrasi engine
(projected stock warning), status flow 12-state (docs/status-flow.md §1).
