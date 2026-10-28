# PHASE 10 — Laporan & Export — Report

## PHASE
10 — Export dataset ke Excel (.xlsx), CSV, dan PDF-print (brief §AG).

## FILES CREATED
**backend/**
- `app/Services/Export/DatasetExporter.php` — streaming writer:
  - `xlsx` via PhpSpreadsheet (judul + header tebal + auto-size, `disconnectWorksheets` setelah save)
  - `csv` native + BOM UTF-8 (accent terbaca di Excel)
  - `pdf` — dokumen HTML A4 landscape `onload=print()` → browser "Simpan sebagai PDF"
- `app/Http/Controllers/Api/ExportController.php` — `GET /api/export/{dataset}?format=xlsx|csv|pdf`
  - dataset: `inventory`, `requests`, `npbg`, `ppb`, `stock-opnames`, `stock-movements`
  - cek permission dataset (`report.*`) **dan** permission format (`export.excel|csv|pdf`)
  - query `->lazy()` agar dataset besar tetap hemat memori
- Route `GET /api/export/{dataset}`

**frontend/**
- `src/pages/ReportsPage.tsx` — kartu per dataset, tombol Excel/CSV/PDF (difilter permission),
  download via axios blob + `URL.createObjectURL`
- Nav "Laporan & Export", route + guard

## TEST
| Test | Actual |
|------|--------|
| export inventory `csv` → 200 `text/csv`; `xlsx` → 200 `spreadsheetml` | ✅ |
| format tidak dikenal (`docx`) → 422 | ✅ |
| dataset tidak dikenal → 404 | ✅ |
| karyawan export inventory → 403 | ✅ |
| `php artisan test` total | ✅ **77 passed** |
| Frontend `tsc -b` / `vitest` / `vite build` | ✅ clean / 5 passed / built |

## STATUS
**PASS** — PDF memakai pendekatan print-view (tanpa dependency dompdf). Bila butuh PDF server-side
biner, tambahkan `barryvdh/laravel-dompdf` dan ganti method `pdf()` di `DatasetExporter`.

## CATATAN UI (PHASE 5–10)
- Tema di-refresh: font Inter, palet biru/teal profesional, sidebar gelap dengan grup menu + ikon,
  header sticky + avatar inisial, halaman login split-screen.
- Komponen baru: `Modal`, `Select`, `Textarea`, `PageHeader`, `TrackingModule`, `AssetPicker`.
- Semua halaman list utama memakai `PageHeader` + ikon konsisten.

## NEXT
Selesai — 10 fase. Sisa pekerjaan opsional: import master Excel modul tracking (assets/customers),
job harian `LendService::markOverdue`, notifikasi, dan hardening produksi.
