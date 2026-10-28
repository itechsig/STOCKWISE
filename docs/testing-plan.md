# STOCKWISE — Testing Plan

> Brief §AL–§AT, §BD-16..18. Tiap phase: implement → run → test → fix → tampilkan hasil → dokumentasi
> command → lanjut. **Test tidak boleh dihapus untuk membuat build "hijau".**

## 1. Layer & tools

| Layer | Tool | Lokasi | Cakupan |
|---|---|---|---|
| Unit (backend) | Pest / PHPUnit | `tests/Unit` | calculation engine, number generator, state machines, value objects |
| Feature / API (backend) | Pest + Laravel HTTP | `tests/Feature` | endpoint, validasi, otorisasi, DB transaction, movement ledger |
| Permission (backend) | Pest | `tests/Feature/Permission` | matriks role × endpoint (roles-permissions §4) |
| Integration (backend) | Pest + `RefreshDatabase` | `tests/Feature/Flow` | alur lintas-modul (request→pickup→stock out) |
| Unit (frontend) | Vitest + RTL | `resources/js/**/*.test.tsx` | komponen reusable, hooks, util, formatter |
| Component/integration (frontend) | Vitest + RTL + MSW | idem | halaman + data-fetch (mock API) |
| E2E | Playwright | `e2e/` | 3 skenario brief §AS (browser nyata, backend+frontend jalan) |
| API contract | Postman collection | `docs/postman/` | eksplorasi manual + smoke |
| Static | PHPStan/Larastan lvl 6, ESLint, `tsc --noEmit`, Pint | CI | |

## 2. Command (brief §AT)

```
# Backend
composer install
php artisan test                      # semua Pest/PHPUnit
php artisan test --filter=Stockwise    # satu grup
php artisan test --coverage --min=80   # gate coverage
vendor/bin/phpstan analyse
vendor/bin/pint --test

# Frontend
npm install
npm run test          # vitest run
npm run test:watch
npm run lint
npm run build         # tsc + vite build (harus sukses = acceptance)

# E2E (butuh backend :8000 + frontend :5173 hidup, DB test ter-seed)
npx playwright install
npx playwright test
npx playwright test --ui

# Reset DB dev/test (HANYA development)
php artisan migrate:fresh --seed
```

## 3. Test data / fixtures

- `DatabaseSeeder` (dev): akun per role (brief §AK) — password dev `password`, **bukan** kredensial produksi.
- `TestingSeeder` / factories: barang AMAN / TIDAK AMAN / BEP / HIGH / MEDIUM / LOW, lead time tinggi,
  deficit tinggi; request ready/pending/shortage/completed; PPB pending/approved/ordered/received.
- E2E pakai DB terpisah (`stockwise_e2e`) + `php artisan migrate:fresh --seed --env=e2e` sebelum run.

## 4. Test case wajib (dari brief)

### Calculation engine → `tests/Unit/StockwiseEngineTest.php`
TC-INV-001..009 → detail di [calculation-engine.md §7](calculation-engine.md).

### Request flow → `tests/Feature/Flow/RequestFlowTest.php`
| ID | Skenario | Expected |
|---|---|---|
| TC-REQ-001 | Karyawan submit request | status `SUBMITTED`, snapshot stok tiap baris |
| TC-REQ-002 | Admin review | status `UNDER_REVIEW` |
| TC-REQ-003 | Stok cukup + fisik cocok → reserve | baris `RESERVED`, movement `RESERVATION`, `inventory.reserved` naik, `actual` tetap |
| TC-REQ-004 | Stok kurang | baris `NEED_PURCHASE`, PPB otomatis dibuat, notif Purchasing |
| TC-REQ-005 | Cek fisik MISMATCH | baris tak bisa reserve, usul opname muncul |

### Pickup flow → `tests/Feature/Flow/PickupFlowTest.php` (brief §AO)
```
request → reserve → NPBG → prepare → ready → pickup
assert: inventory.actual berkurang HANYA setelah pickup (bukan saat reserve/NPBG/prepare/ready)
assert: reservation → CONSUMED, movement STOCK_OUT tercatat dgn before/after benar
assert: request → COMPLETED
```

### Stock opname → `tests/Feature/Flow/StockOpnameTest.php` (brief §AP)
| ID | Skenario | Expected |
|---|---|---|
| TC-SO-001 | system=100, physical=100 | difference 0, note opsional, submit OK |
| TC-SO-002 | system=100, physical=95, note kosong | submit **ditolak** (422) |
| TC-SO-003 | system=100, physical=95, note ada | submit OK |
| TC-SO-004 | Admin approve TC-SO-003 | `stock_adjustments` dibuat, movement `STOCK_ADJUSTMENT`, `inventory.actual = 95`, audit log berisi before 100 / after 95 |
| TC-SO-005 | Admin reject | tidak ada adjustment, opname COMPLETED |
| TC-SO-006 | Admin recount | baris kembali ke IN_PROGRESS |

### Purchasing → `tests/Feature/Flow/PurchasingFlowTest.php` (brief §AQ)
```
request qty 20, available 5 → shortage 15 → PPB (line shortage_qty=15)
→ PO → RI (partial 10) → CONFIRMED → inventory.actual +10, PO PARTIAL_RECEIVED
→ RI (5) → CONFIRMED → actual +5 total 15, PO RECEIVED, PPB COMPLETED
assert: actual TIDAK berubah sebelum tiap RI CONFIRMED
```

### Permission → `tests/Feature/Permission/*` — TC-SEC-001..010 di [roles-permissions.md §4](roles-permissions.md).

### E2E (Playwright) — brief §AS
| Skenario | Langkah |
|---|---|
| E2E-1 | Karyawan login → buat request → Admin login → review → reserve → buat NPBG → Lapangan login → prepare → ready → Karyawan lihat READY → pickup + ttd → complete. Assert stok turun hanya di pickup. |
| E2E-2 | Karyawan request (stok kurang) → PPB → Purchasing login → PO → Receiving → confirm → stok masuk → request dilanjutkan → reserve → pickup. |
| E2E-3 | Admin jadwalkan opname → Anak Gudang input physical (ada selisih) → note → submit → Admin review → approve → adjustment → stok ter-update. |

## 5. Definition of Done per phase (brief §BC output)

Setiap phase melapor: FILES CREATED / MODIFIED, COMMAND TO RUN, TEST, EXPECTED, ACTUAL, ERROR, FIX,
STATUS (PASS/FAIL/BLOCKED), NEXT STEP. Phase berikut **tidak** dimulai bila STATUS ≠ PASS.

## 6. CI (fase implementasi)

`.github/workflows/ci.yml`: matrix PHP 8.3 + Node 20 → `composer install`, `php artisan test`,
`phpstan`, `pint --test`, `npm ci`, `npm run lint`, `tsc`, `npm run test`, `npm run build`,
(nightly) `playwright test`. Semua hijau = syarat merge ke `develop`/`main`.

## 7. Acceptance criteria (brief §BA)

Checklist §BA dipetakan ke test suite di atas; aplikasi "selesai" saat seluruh checklist +
`npm run build` sukses + README lengkap.
