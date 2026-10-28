# PHASE 11 — Excel Klasik (replika file Excel lama)

## Tujuan

Selama ini staf SIG mengisi 9 file Excel manual (lihat [excel-data-mapping.md](excel-data-mapping.md)).
STOCKWISE sekarang jadi **satu-satunya tempat input** — request, NPBG, PPB, PO, RI, opname, dan 7
modul tracking semuanya dikerjakan lewat web. Fitur ini membuat **replika Excel dari data live**
untuk staf yang masih perlu bentuk file asli (dibagikan ke pihak luar, arsip, dsb.) — bukan
sebaliknya. Tidak ada lagi Excel yang diisi tangan; Excel yang dihasilkan tinggal diunduh.

Prinsip: **sheet, urutan kolom, header, dan rumus sama persis** dengan file sumber (diverifikasi
lewat inspeksi langsung file asli, bukan rekaan — lihat §Verifikasi di bawah), datanya diambil live
dari database STOCKWISE.

## Cara pakai

Halaman **Laporan & Export** → bagian **Excel Klasik** → tombol Unduh per file. Backend:
`GET /api/legacy-export/{key}` (butuh permission modul terkait + `export.excel`), daftar file:
`GET /api/legacy-export`.

| Key | File asli | Sheet |
|---|---|---|
| `data` | `DATA.xlsx` | `DATABASE UTAMA` + 12× `SAFETY STOCK <kategori>` |
| `ppb-ri` | `1. PPB - RI.xlsx` | `PPB`, `RI`, `PPB Perubahan` |
| `npbg` | `2. NPBG.xlsx` | `NPBG` |
| `borrow-lend` | `3. Tracking Borrow & Lend.xlsx` | `Lend`, `Borrow` |
| `stpp` | `4. Tracking STPP.xlsx` | `STPP` |
| `ban-luar` | `5. Tracking Ban Luar.xlsx` | `Ban Luar` |
| `maintenance-assets` | `6. Tracking Maintenance Assets.xlsx` | `Maintenance Kendaraan` |
| `manufaktur-assembly` | `7. Tracking Manufaktur & Assembly.xlsx` | `Manufaktur & Assembly`, `Manufaktur & Jasa Lain-Lain` |
| `pengembalian-bekas` | `8. Tracking Pengembalian Bekas.xlsx` | `Spare Part` (matriks 14 komponen), `Spare Part Lain` |

## Verifikasi rumus (bukan rekaan)

Formula sheet `SAFETY STOCK *` diambil dengan membaca langsung `DATA.xlsx` asli
(`setReadDataOnly(false)`, tanpa evaluasi) pada sheet `SAFETY STOCK ASSETS` baris 5:

```
O5 =SUM(C5:N5)*1/12        (rata-rata 1 bulan)
P5 =SUM(D5:N5)*3/12        (rata-rata 3 bulan)
Q5 =SUM(E5:N5)*6/12        (rata-rata 6 bulan)
R5 =SUM(F5:N5)*12/12       (rata-rata 12 bulan)
T5 =SQRT(S5/30)            (√LT)
U5 =ROUNDUP((2.33*O5)*T5,0)        (Safety Stock)
V5 =ROUNDUP((O5*S5/30)+1,0)        (MIN PR)
```

Formula ini ditulis apa adanya (termasuk pola SUM yang tidak simetris di P/Q/R — bukan bug yang
"diperbaiki", karena tujuannya replika persis) ke tiap baris hasil export, sehingga file yang
diunduh tetap bisa dihitung ulang di Excel.

## Sumber data per sheet

- **DATABASE UTAMA** — `items` (+ `categories.path` di-split jadi Kategori Induk/Anak1/2/3),
  `units`, `warehouses`/`warehouse_locations` (LETAK GUDANG/RAK), `inventory` (SISA STOK),
  `item_safety_stocks` efektif (SAFETY STOCK, MIN PR).
- **SAFETY STOCK <kategori>** — 12 kolom bulan dihitung dari `stock_movements` tipe `STOCK_OUT`
  12 bulan terakhir (pengganti pencatatan NPBG manual bulanan) per item dalam kategori tsb.
- **PPB / RI / PPB Perubahan** — `ppb`+`ppb_items`, `receivings`+`receiving_items`, `ppb_amendments`.
  Status PPB STOCKWISE dipetakan balik ke enum lama (`Requested`/`Shortage`/`Completed`/`Close`).
- **NPBG** — `npbg`+`npbg_items` (+ customer/project/asset bila ada).
- **Lend/Borrow/STPP/Ban Luar/Maintenance/Manufaktur/Pengembalian Bekas** — tabel tracking
  PHASE 8 masing-masing; kolom komponen matriks Pengembalian Bekas memakai 14 kode
  `used_return_component_types` yang sama dengan `docs/erd.md`.

## Catatan performa

`DATA.xlsx` meliput ~9.000 barang × 13 sheet — butuh ±1 menit dan memori 1 GB (dinaikkan di
`LegacyExportController`, sama seperti `stockwise:import`). File tracking lain jauh lebih kecil
(detik). Sheet besar (DATABASE UTAMA, SAFETY STOCK) memakai lebar kolom tetap, bukan auto-size,
supaya penulisan tidak berat di skala ribuan baris.

## Test

`tests/Feature/Export/LegacyExportTest.php` — 9 file unduh 200 + `content-type` xlsx, permission
per modul + `export.excel` ditegakkan (karyawan ditolak), key tak dikenal → 404, dan pemeriksaan
mendalam DATA.xlsx: header `DATABASE UTAMA`, isi baris kategori ter-split benar, serta 5 formula
`SAFETY STOCK` di atas sama persis string-nya.
