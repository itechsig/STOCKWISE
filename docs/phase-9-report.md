# PHASE 9 — Dashboard per Peran — Report

## PHASE
9 — Halaman Dashboard ringkasan yang menyesuaikan peran user (brief §AE, §W). Satu endpoint
`GET /api/dashboard`; blok kartu/chart/list yang dikembalikan dipilih berdasar permission.

## FILES CREATED
**backend/**
- `app/Http/Controllers/Api/DashboardController.php` — kartu KPI + chart + list, filter per permission:
  - inventory/analisa: item dianalisa, di bawah safety stock, jumlah TIDAK AMAN / AMAN
  - request: berjalan, menunggu review, distribusi status (pie), 6 request terbaru
  - NPBG: siap diambil, disiapkan
  - opname: opname aktif
  - purchasing: PPB menunggu, PO berjalan, penerimaan diperiksa
  - tracking: barang dipinjamkan (+lewat tempo), pinjaman luar aktif, SPK berjalan
  - executive / report.stock_movement: bar chart pergerakan stok masuk/keluar 14 hari
- Route `GET /api/dashboard`

**frontend/**
- `src/features/dashboard/api.ts` — `useDashboard()`
- `src/pages/DashboardPage.tsx` — grid kartu KPI (warna per tone), PieChart status request,
  BarChart pergerakan stok (recharts), daftar request terbaru
- `PageHeader` komponen bersama, dipakai lintas halaman

## TEST
| Test | Actual |
|------|--------|
| `/api/dashboard` admin_gudang → struktur `{role,cards,charts,lists}`, kartu tidak kosong | ✅ |
| dashboard karyawan tidak memuat kartu purchasing (`po_open`) | ✅ |
| Verifikasi manual data nyata (8.957 item): 865 TIDAK AMAN, 8.092 AMAN, 41 di bawah SS | ✅ |
| `php artisan test` total | ✅ **77 passed** |

## STATUS
**PASS**

## NEXT
PHASE 10 — Laporan & Export.
