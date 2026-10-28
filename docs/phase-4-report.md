# PHASE 4 — Material Request — Report

Format: brief §BC.

## PHASE
4 — Buat Request → Review + **cek fisik gudang** → Reserve. State machine 12-status
(docs/status-flow.md §1). Stok **tidak berkurang** saat reserve — hanya `reserved_qty` naik
(ATURAN MUTLAK 6). Pickup/NPBG menyusul PHASE 5.

## FILES CREATED

**backend/**
- Migrasi `2026_09_09_110001..110004` — `document_sequences`, `material_requests`,
  `material_request_items`, `stock_reservations`
- Model: `MaterialRequest`, `MaterialRequestItem`, `StockReservation`
- `app/Services/DocumentNumberService.php` — nomor `REQ/{SITE}/{YY}/{ROMAWI}/{SEQ}` (row-lock,
  reusable untuk NPBG/PPB/PO/RI nanti)
- `app/Services/RequestService.php` — state machine: create / update(draft) / submit(snapshot stok +
  projected + below_safety) / review / physicalCheck / reserve / needPurchase / cancel(release reservasi)
- `app/Http/Controllers/Api/MaterialRequestController.php` + `StoreMaterialRequest` +
  `MaterialRequestResource`
- Factory: `MaterialRequestFactory`, `MaterialRequestItemFactory`
- Test: `tests/Feature/Request/RequestFlowTest.php` (7 test, TC-REQ-001..004 + skenario)

**frontend/**
- `src/types/request.ts`, `src/features/requests/api.ts` (hooks + mutation aksi)
- `src/components/ItemPicker.tsx` (autocomplete barang), `src/components/ui/request-badge.tsx`
- `src/pages/{RequestListPage,RequestCreatePage,RequestDetailPage}.tsx` (+ list test)
- `RequirePermission` mendukung any-of; `AppLayout` nav "Request Barang"

## FILES MODIFIED
- `routes/api.php` — blok PHASE 4 (9 endpoint)
- `frontend/src/{router,components/AppLayout,auth/guards}.tsx`

## COMMAND TO RUN

```bash
cd backend && php artisan migrate && php artisan test
cd frontend && npm run test && npm run build && npm run e2e
```

## API

| Method | Endpoint | Permission |
|--------|----------|-----------|
| GET | `/api/requests` | `request.view` \| `request.view_own` (auto-scoped ke milik sendiri) |
| POST | `/api/requests` | `request.create` |
| GET | `/api/requests/{id}` | view / own |
| PUT | `/api/requests/{id}` | `request.update_own` (DRAFT saja) |
| POST | `/api/requests/{id}/submit` | `request.update_own` |
| POST | `/api/requests/{id}/review` | `request.review` |
| POST | `/api/requests/{id}/items/{item}/physical-check` | `request.physical_check` |
| POST | `/api/requests/{id}/reserve` | `request.reserve` |
| POST | `/api/requests/{id}/need-purchase` | `request.set_need_purchase` |
| POST | `/api/requests/{id}/cancel` | `request.cancel_own` \| `request.cancel_any` |

## TEST & EXPECTED & ACTUAL

| Test | Expected | Actual |
|------|----------|--------|
| TC-REQ-001 | Karyawan buat + submit → `SUBMITTED`, snapshot stok & projected | ✅ |
| TC-REQ-002/003 | Review → cek fisik MATCH → reserve → `RESERVED`; `actual` tetap, `reserved +5`; movement `RESERVATION` + row `stock_reservations` | ✅ |
| TC-REQ-004 | Stok kurang → reserve `PARTIAL` (qty_reserved 3, qty_to_purchase 7), status `PARTIAL` | ✅ |
| Projected < Safety | flag + warning "…di bawah Safety Stock." | ✅ |
| Cek fisik MISMATCH | catatan wajib (422) + baris tak bisa di-reserve (`PENDING`) | ✅ |
| Cancel | release semua reservasi (movement `RELEASE_RESERVATION`, `reserved_qty` kembali 0) | ✅ |
| Scope | karyawan lihat request sendiri saja; admin gudang lihat semua | ✅ |
| `php artisan test` total | hijau | ✅ **50 passed, 488 assertions** |
| Frontend `npm run test` / `build` / `tsc` | hijau / sukses | ✅ **5 passed** |
| E2E (auth 3 + health 1 + inventory 2 + request 1) | hijau | ✅ **7 passed** |
| curl end-to-end | REQ dibuat + submit projected −2 | ✅ |

**Tambahan:** endpoint `GET /api/items/lookup` (permission `item.lookup`) untuk pencarian barang oleh
Karyawan saat buat request — beda dari halaman "Master Barang" (`item.view`). `item.lookup`
ditambahkan ke role `karyawan`.

## Perubahan akun dev (permintaan user, 2026-09-09)
Login sekarang menerima **username ATAU email**. Akun dev di-set ulang, password semua = `Password@26`:
`superadmin@gmail.com`, `admingudang@gmail.com`, `adminlapangan@gmail.com` (role lapangan gudang),
`bos@gmail.com`, `purchasing@gmail.com`, `kariawan@gmail.com`, + `anakgudang1/2`, `karyawanbpn`.
Rate limit login dinaikkan `6→20`/menit; e2e dijalankan serial (`workers: 1`). README diperbarui.

## ERROR & FIX
1. `MaterialRequestResource` panggil `$model->whenLoaded()` (method Resource, bukan Model) → 500.
   **Fix:** pakai `$line->relationLoaded(...)`.
2. Guard frontend `RequirePermission` cuma 1 permission → admin_gudang (`request.view`) keblok dari
   halaman request. **Fix:** dukung array (any-of).
3. E2E `request.spec` kena rate-limit login (banyak login berturut dari 1 IP). **Fix:** throttle 20 +
   e2e serial.

## STATUS
**PASS** — 50 test backend + 5 frontend + 7 e2e hijau. Alur Request → Review → Cek Fisik → Reserve
terverifikasi lewat curl & browser. Stok terbukti **tidak berkurang** saat reserve (hanya `reserved_qty`
naik); cancel mengembalikannya.

## NEXT STEP
PHASE 5 — NPBG + Pickup: `npbg` + `npbg_items` dari request `RESERVED/PARTIAL`, prepare → ready →
pickup (verifikasi + tanda tangan) → `STOCK_OUT` (baru di sini `actual_qty` turun), request `COMPLETED`.
