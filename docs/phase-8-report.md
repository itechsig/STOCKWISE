# PHASE 8 — Modul Tracking — Report

## PHASE
8 — Modul tracking dari 8 file Excel operasional (docs/step1-analysis.md §J, erd.md Grup J):
Lend, Borrow, STPP, Ban Luar, Maintenance Asset, Manufaktur & Assembly / Jasa, Pengembalian Bekas.
Modul melacak *lifecycle* dokumen; pergerakan stok tetap lewat NPBG (STOCK_OUT saat pickup) dan
RI (RETURN / STOCK_IN saat confirm) — sesuai docs/status-flow.md §9.

## FILES CREATED
**backend/**
- Migrasi `2026_09_09_150001_create_tracking_masters_table` — `customers`, `projects`, `workshops`,
  `assets`, `serial_units`
- Migrasi `2026_09_09_150002_create_tracking_tables` — `lend_transactions`, `borrow_transactions`,
  `stpp_transactions`, `tyre_changes`, `maintenance_orders` (+`_subs`), `manufacturing_orders`
  (+`_subs`), `used_return_component_types`, `used_returns` (+`_items`)
- 16 model (`Customer`, `Project`, `Workshop`, `Asset`, `SerialUnit`, `LendTransaction`,
  `BorrowTransaction`, `StppTransaction`, `TyreChange`, `MaintenanceOrder`(+Sub),
  `ManufacturingOrder`(+Sub), `UsedReturn`(+Item), `UsedReturnComponentType`)
- `app/Services/Tracking/` — `LendService`, `BorrowService`, `StppService`, `TyreChangeService`,
  `MaintenanceService`, `ManufacturingService`, `UsedReturnService` (semua state-machine)
- `app/Http/Controllers/Api/Tracking/` — 7 controller + endpoint master di `MasterDataController`
  (`/customers`, `/projects`, `/workshops`, `/assets`, `/serial-units`)
- `database/seeders/TrackingSeeder.php` — 14 component type + 2 workshop (yang eksplisit di erd.md)
- Factory `AssetFactory`
- `tests/Feature/Tracking/TrackingFlowTest.php` (8 test)

**frontend/**
- `src/features/tracking/api.ts` — hook generik (`useTrackingList/Item/Create/Action`) + row types
- `src/components/tracking/TrackingModule.tsx` — scaffold list + modal create + modal detail
- `src/components/AssetPicker.tsx`
- `src/pages/tracking/` — `LendPage`, `BorrowPage`, `StppPage`, `TyrePage`, `MaintenancePage`,
  `ManufacturingPage`, `UsedReturnPage`
- Nav grup "Tracking" (7 menu), route + guard per permission

## STATE MACHINE (ringkas)
| Modul | Alur |
|---|---|
| Lend | `ON_LOAN → PARTIAL_RETURN → RETURNED` (·`OVERDUE` bila lewat `due_date`) |
| Borrow | `BORROWED → PARTIAL → RETURNED` |
| STPP | `ACTIVE → PASSIVE`; PASSIVE → reissue = baris baru serial sama |
| Ban Luar | `PENDING_RI → CLEAR`; `is_opening` → langsung CLEAR |
| Maintenance | order `OPEN → ON_GOING → COMPLETED`; sub `ON_GOING → COMPLETED` (roll-up otomatis) |
| Manufaktur | order `REQUESTED → ON_GOING → COMPLETED`; `JASA` wajib `vendor_id` |
| Bekas | `PENDING → CLEAR`; item `into_stock` + kondisi REUSABLE/USED → movement RETURN via RI |

## TEST
| Test | Actual |
|------|--------|
| Lend: create → partial return → full return → tolak kelebihan qty (422) | ✅ |
| Borrow: create → return penuh | ✅ |
| STPP: issue ACTIVE → withdraw PASSIVE → reissue (baris ke-2, serial sama) | ✅ |
| Ban Luar: PENDING_RI → close CLEAR; opening record langsung CLEAR | ✅ |
| Maintenance: order OPEN → +sub ON_GOING → complete sub → order COMPLETED | ✅ |
| Manufaktur JASA tanpa vendor → 422; dengan vendor → OK | ✅ |
| Pengembalian Bekas: create PENDING (qty negatif diizinkan) → close CLEAR | ✅ |
| karyawan buat Lend → 403 | ✅ |
| `php artisan test` total | ✅ **77 passed** |
| Frontend `tsc -b` / `vitest` / `vite build` | ✅ clean / 5 passed / built |

## STATUS
**PASS** — dengan catatan: master `assets` / `customers` / `projects` belum diimport dari Excel
(daftar Dropdown Ban Luar / Maintenance). Struktur & endpoint siap; import menyusul bila file
referensi tersedia. Ditandai `[NEEDS CONFIRMATION]` di `docs/assumptions.md`.

## NEXT
PHASE 9 — Dashboard per peran.
