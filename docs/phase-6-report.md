# PHASE 6 — Stock Opname — Report

## PHASE
6 — Stock Opname: jadwal → Anak Gudang hitung fisik → submit → Admin Gudang review
(APPROVE/REJECT/RECOUNT) → APPROVE + selisih membuat `stock_adjustment` + movement `STOCK_ADJUSTMENT`
(satu-satunya cara opname mengubah `actual_qty`). Anak Gudang **tidak pernah** ubah stok langsung.

## FILES CREATED
**backend/**
- Migrasi `2026_09_09_130001` — `stock_opnames`, `stock_opname_items` (`difference` generated col),
  `stock_adjustments`
- Model `StockOpname`, `StockOpnameItem`, `StockAdjustment`
- `app/Services/StockOpnameService.php` — schedule / start / count / submit / review
- `app/Http/Controllers/Api/StockOpnameController.php`
- `tests/Feature/Opname/StockOpnameTest.php` (7 test — TC-SO-001..006 + authz)

**frontend/**
- `src/features/opname/api.ts`, `src/pages/{OpnameListPage,OpnameDetailPage}.tsx`
- `useWarehouses` hook, nav "Stock Opname"

## API
`GET /api/stock-opnames`, `GET /api/stock-opnames/{id}`, `POST /api/stock-opnames`,
`POST .../{id}/start`, `PUT .../{id}/items/{item}`, `POST .../{id}/submit`, `POST .../{id}/review`.

## TEST
| Test | Actual |
|------|--------|
| TC-SO-001 fisik = sistem, catatan opsional → submit OK | ✅ |
| TC-SO-002 selisih tanpa catatan → submit **ditolak 422** | ✅ |
| TC-SO-003 selisih + catatan → submit OK | ✅ |
| TC-SO-004 approve → `stock_adjustment` (100→95) + `STOCK_ADJUSTMENT` movement, `actual = 95` | ✅ |
| TC-SO-005 reject → tidak ada adjustment, `actual` tetap 100 | ✅ |
| TC-SO-006 recount → status `RECOUNT_REQUIRED` | ✅ |
| Anak Gudang review → 403 | ✅ |
| `php artisan test` total | ✅ **61 passed, 553 assertions** |
| Frontend `npm run test` / `tsc` | ✅ 5 passed / clean |

## STATUS
**PASS**

## NEXT
PHASE 7 — PPB + Purchasing (PPB → RFQ → PO → Receiving → STOCK_IN).
