# PHASE 7 — PPB + Purchasing — Report

## PHASE
7 — Procurement: request shortage → **PPB** (Permintaan Pembelian Barang) → **PO** (Purchase Order,
dibuat penuh di STOCKWISE) → **Receiving (RI)** → `RECEIVING` movement menambah `actual_qty`
**hanya setelah RI CONFIRMED** (ATURAN MUTLAK 8). Karyawan tidak bisa approve PPB/PO.

PPB flow: `DRAFT → SUBMITTED → REVIEW → APPROVED → ORDERED → PARTIAL_RECEIVED → RECEIVED`
(reject → `CANCELLED`).
PO flow: `DRAFT → APPROVED → SENT → PARTIAL_RECEIVED → RECEIVED` (cancel → `CANCELLED`).
RI flow: `CHECKING → CONFIRMED` (reject → `REJECTED`); stok bertambah di `confirm()`.

## FILES CREATED
**backend/**
- Migrasi `2026_09_09_140001_create_purchasing_tables` — `vendors`, `ppb`, `ppb_items`,
  `ppb_amendments`, `purchase_orders`, `purchase_order_items`, `receivings`, `receiving_items`
- Model `Vendor`, `Ppb`, `PpbItem`, `PpbAmendment`, `PurchaseOrder`, `PurchaseOrderItem`,
  `Receiving`, `ReceivingItem`
- `app/Services/PpbService.php` — createFromRequest / createManual / submit / review / approve /
  reject / amend; `addLine()` snapshot engine (safety stock, defisit, priority score/level)
- `app/Services/PurchaseOrderService.php` — create (subtotal/pajak/total, set ppb_item ORDERED +
  `qty_ordered`) / approve / send / cancel
- `app/Services/ReceivingService.php` — create (CHECKING) / confirm (movement `RECEIVING` /
  `STOCK_IN` / `RETURN` per `source_type`, `qty_received++`, `syncPo()`) / reject
- `app/Http/Controllers/Api/{PpbController,PurchaseOrderController,ReceivingController,VendorController}.php`
- Factories `VendorFactory`, `PpbFactory`, `PurchaseOrderFactory`, `ReceivingFactory`
- `tests/Feature/Purchasing/PurchasingFlowTest.php` (3 test)

**frontend/**
- `src/features/purchasing/api.ts` — hooks PPB / PO / Receiving / Vendor
- `src/pages/{PpbListPage,PpbDetailPage}.tsx` (+ form PPB manual)
- `src/pages/{PoListPage,PoCreatePage,PoDetailPage}.tsx`
- `src/pages/{ReceivingListPage,ReceivingCreatePage,ReceivingDetailPage}.tsx`
- Badge status baru (REVIEW/APPROVED/ORDERED/SENT/PARTIAL_RECEIVED/RECEIVED/CHECKING/CONFIRMED…)
- Nav "PPB", "Purchase Order", "Penerimaan"; tombol "Buat PPB" di RequestDetailPage

## API
`/api/vendors` (GET/POST/PUT) ·
`/api/ppb` (index/show/from-request/store/submit/review/approve/reject/amend) ·
`/api/purchase-orders` (index/show/store/approve/send/cancel) ·
`/api/receivings` (index/show/store/confirm/reject).

## TEST
| Test | Actual |
|------|--------|
| §AQ request 20 / tersedia 5 → shortage 15 → reserve PARTIAL | ✅ |
| PPB from-request → submit → review → approve (`qty` baris = 15) | ✅ |
| PO 15 × Rp1.000 → total **Rp15.000,00** → approve → send | ✅ |
| Receiving 10 dibuat → `actual` **tetap 5** (belum confirm) | ✅ |
| confirm RI-1 → `actual` **15**, PO `PARTIAL_RECEIVED` | ✅ |
| Receiving 5 → confirm → `actual` **20**, PO `RECEIVED`, PPB `RECEIVED` | ✅ |
| `stock_movements` punya `movement_type = RECEIVING` | ✅ |
| PPB manual → submit → reject → `CANCELLED` | ✅ |
| karyawan approve PPB → **403** | ✅ |
| `php artisan test` total | ✅ **64 passed** |
| Frontend `tsc -b` / `vitest` / `vite build` | ✅ clean / 5 passed / built |

## STATUS
**PASS**

## NEXT
PHASE 8 — Other Tracking (Borrow/Lend, STPP, Ban Luar, Maintenance Asset, Manufaktur &
Assembly, Pengembalian Bekas).
