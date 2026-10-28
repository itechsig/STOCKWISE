# PHASE 5 — NPBG + Pickup — Report

## PHASE
5 — NPBG (Nota Pengeluaran Barang Gudang) dari request `RESERVED/PARTIAL` (atau manual untuk pemakaian
`UMUM`), PREPARING → READY_TO_PICKUP → PICKUP (verifikasi + tanda tangan) → **STOCK_OUT**. Di sinilah
`actual_qty` **pertama kali turun** (ATURAN MUTLAK 7). Request jadi `COMPLETED`.

## FILES CREATED
**backend/**
- Migrasi `2026_09_09_120001..120002` — `npbg`, `npbg_items`
- Model `Npbg`, `NpbgItem` (+ factory)
- `app/Services/NpbgService.php` — createFromRequest / createManual / prepare / ready / pickup / cancel
- `app/Http/Controllers/Api/NpbgController.php` + `NpbgResource`
- `tests/Feature/Npbg/PickupFlowTest.php` (4 test — brief §AO)

**frontend/**
- `src/features/npbg/api.ts`, `src/pages/{NpbgListPage,NpbgDetailPage}.tsx`
- Tombol "Buat NPBG" di RequestDetailPage (status RESERVED/PARTIAL)
- Logo: `src/assets/logo.png` (dari SIG.png) + `public/logo.png` favicon, komponen `<Logo />`

## API
`GET /api/npbg`, `GET /api/npbg/{id}`, `POST /api/npbg/from-request`, `POST /api/npbg` (manual),
`POST /api/npbg/{id}/{prepare|ready|pickup|cancel}`.

## TEST
| Test | Actual |
|------|--------|
| Full flow: request→reserve→NPBG→ready→pickup; actual turun **hanya** di pickup (100→95), reserved→0, reservasi CONSUMED, request COMPLETED | ✅ |
| NPBG manual (tanpa request) → pickup langsung kurangi actual (40→30) | ✅ |
| Cancel NPBG → request balik ke RESERVED | ✅ |
| Karyawan tidak bisa pickup | ✅ 403 |
| `php artisan test` total | ✅ **54 passed, 515 assertions** |
| Frontend `npm run test` / `tsc` | ✅ **5 passed** / clean |

## KEPUTUSAN
- NPBG nomor: `NPBG/{PFX}/{YY}/{ROMAWI}/{SEQ}` (pakai `DocumentNumberService`).
- Tanda tangan: string base64 data-URI → disimpan `storage/app/signatures/{number}.png`; atau ref eksternal.
- NPBG manual (`classification=UMUM`) tetap diizinkan untuk Admin Gudang (pemakaian harian, sesuai data Excel).

## STATUS
**PASS**

## NEXT
PHASE 6 — Stock Opname.
