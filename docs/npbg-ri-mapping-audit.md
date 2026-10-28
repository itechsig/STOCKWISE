# Audit mapping NPBG & RI vs template Accurate terbaru — 2026-10-05

Status: **AUDIT SAJA**. Belum ada perubahan kode, migrasi, atau data. Menunggu persetujuan "LANJUT UPDATE".

Sumber pengecekan (semua read-only):
- mirror MySQL STOCKWISE (`accurate_*`);
- salinan staging `Staging\STAGING.GDB` lewat Firebird staging port 3050.

Production GDB / port 3051 tidak disentuh.

## 0. Bukti dari Accurate sendiri

Accurate menyimpan definisi form cetak di `TEMPLATE` / `TEMPLDET`:
- `CAPTION` = nama field di Accurate;
- `DEFAULTCAPTION` = label tercetak;
- `FIELDDATA` = field.

Datanya dihasilkan prosedur `TEMPLATE_ARINVDET` / `TEMPLATE_APITMDET`.

| Template | Dipakai | Label tercetak → field Accurate |
|---|---|---|
| **133 "NPBG [A5]"** (baru, 102 dok) | NPBG | Tipe NPBG → `[Terms]`; Klasifikasi → `[Ship Via]`; Divisi Peminta → `[PO Number]`; **Catatan → `[Reserved1]` (nama field di Accurate: "LOKASI GUDANG")**; Peminta → `[Department]`; No. Seri → `[SNLine]`; Keterangan → `[Invoice Description]` |
| 53 "NPBG KECIL NEW 1" (lama, 3.477 dok) | NPBG | Nama Peminta → `[Reserved1]`; Keterangan → `[Reserved2]`; TTD → `[Reserved3]` |
| **130 "RI [A5]"** (baru, 92 dok) | RI | **No. Surat Jalan → `[Invoice Number]`**; **No. RI → `[Form No]`**; Pengirim → `[Ship Via]`; FOB → `[FOB]`; Tgl. Kirim → `[Ship Date]`; No. PO → `[PO No]`; No. PPB → `[Request No]`; Departemen → `[Department]` (layar saja, tidak dicetak); No. Seri → `[SNLine]` |
| 56 "RECEIVE ITEM A5" (lama, 2.301 dok) | RI | RI No. → `[Invoice Number]`; No. Form → `[Form No]` (tidak dicetak) |

Field template yang **bukan kolom fisik**:
- `[Terms]` = `ARINV.TERMSID` → tabel `TERMOPMT` (`TERMNAME`: "C.O.D" / "Net 30"; `TERMMEMO`: "NON-PENJUALAN" / "PENJUALAN"). `TERMSDESC` tidak ada sebagai kolom.
- `[Request No]` = `APITMDET.POID + POSEQ` → `PODET.REQID` → `REQUISITION.REQNO` (dari `TEMPLATE_APITMDET`). `APITMDET.REQNO` tidak ada.
- `[SNLine]` = `VIEW_SN_BYITEMHIST(ITEMHISTID)` → `SNHISTORY` + `SERIALNUMBERS`, digabung jadi teks rentang (mis. "091801-091850 ; 091851-091900"). `SNLINE` tidak ada sebagai kolom. Terisi di 311 NPBG dan 238 RI.
- `[Form No]` = `APINV.SEQUENCENO`. Data:
  - template baru: 85 dok `SEQUENCENO` = `INVOICENO`; **7 dok berbeda**, mis. `INVOICENO` "SRT-07965" / `SEQUENCENO` "RI/NV/26/IX/050";
  - template lama: `SEQUENCENO` = angka urut ("2379").
- `[Department]` = `DEPARTMENT` lewat `DEPTID` (prosedur mengeluarkan `DEPTNO` dan `DEPTNAME`).

## 1. Skema saat ini

**`npbg`**:
- **Kunci & index:** PK `id`; UNIQUE (`accurate_arinvoice_id`, `accurate_seq`); index `no_npbg`, `tgl_npbg`, `peminta`, `divisi`, `pelanggan`, `kode_barang`, `jenis_pengeluaran`.
- **Kolom wajib:** `accurate_arinvoice_id` / `accurate_seq` NOT NULL, `unit_ratio` NOT NULL default 1. Semua kolom lain nullable.
- **FK masuk** (semua SET NULL, kecuali `npbg_verifications` CASCADE): lend, borrow, maintenance_order_subs, manufacturing_order_subs, stpp_transactions, stpp_maintenance_logs, tyre_changes, tyre_bpn_transfers, used_return_matrix_rows, used_return_other_rows.

**`ri`**:
- **Kunci & index:** PK `id`; UNIQUE (`accurate_apinvoice_id`, `accurate_seq`); index `no_ri`, `tgl_ri`, `kode_barang`, `vendor`, `divisi`.
- **FK keluar:** `accurate_po_item_id` → `purchase_order_items` SET NULL; `vendor_id` → `vendors` SET NULL.
- **FK masuk:** borrow.in_ri_id, maintenance/manufacturing ri_id, tyre_changes.in_ri_id (SET NULL).

**Isi kolom NPBG "manual"** (`tipe_npbg`, `klasifikasi`, `deskripsi`, `nama_proyek`, `no_seri_nopol`, `dikeluarkan_oleh`): **0 baris terisi**. Tidak ada data yang tertimpa bila diisi dari Accurate atau di-rename.

## 2–3. Mapping saat ini → target

### NPBG

| Current column | Target column | Accurate source | Lookup | Fallback | Action |
|---|---|---|---|---|---|
| accurate_arinvoice_id | (sama) | ARINV.ARINVOICEID | — | — | OK |
| no_npbg | (sama) | ARINV.INVOICENO | — | — | OK |
| tgl_npbg | (sama) | ARINV.INVOICEDATE | — | — | OK |
| shipdate / taxdate | (sama) | ARINV.SHIPDATE / TAXDATE | — | — | OK |
| divisi | (sama) | ARINV.PURCHASEORDERNO ("Divisi Peminta") | — | — | OK |
| tipe_npbg (manual, kosong) | tipe_npbg | ARINV.TERMSID | TERMOPMT | — | NEED_UPDATE + mirror TERMOPMT; **NEED_VERIFICATION**: TERMNAME ("C.O.D") atau TERMMEMO ("NON-PENJUALAN") |
| klasifikasi (manual, kosong) + jenis_pengeluaran | **klasifikasi** | ARINV.SHIPVIA | SHIPMENT.NAME | — | NEED_MIGRATION: isi klasifikasi, pindahkan pemakaian jenis_pengeluaran, lalu drop jenis_pengeluaran |
| pelanggan | (sama) | ARINV.SHIPTO1 + SHIPTO2 | — | — | OK. Template mencetak SHIPTO1..5; 3–5 belum dipakai, cek isi |
| keterangan | (sama) | ARINV.DESCRIPTION | — | — | OK |
| accurate_seq, kode_barang, deskripsi_barang, kuantitas, satuan | (sama) | ARINVDET.SEQ / ITEMNO / ITEMOVDESC / QUANTITY / ITEMUNIT | — | — | OK |
| unit_ratio | (sama) | ARINVDET.UNITRATIO | — | 1 | OK |
| no_seri_nopol (manual, kosong) | **no_seri** | SNHISTORY + SERIALNUMBERS via ARINVDET.ITEMHISTID | port VIEW_SN_BYITEMHIST | — | NEED_MIGRATION (rename + panjang ≥ text) + mirror 2 tabel |
| peminta | (sama) | ARINVDET.DEPTID ("Peminta" = [Department]) | DEPARTMENT | DEPTID baris lain di dokumen; format lama ITEMRESERVED1 | OK (sudah). **NEED_VERIFICATION**: tercetak DEPTNO (orang) atau DEPTNAME (divisi) |
| lokasi_gudang | (sama) | ARINVDET.ITEMRESERVED1 (field Accurate "LOKASI GUDANG", tercetak sebagai "Catatan") | — | — | OK, terkonfirmasi template |
| lokasi_rak | (sama) | ARINVDET.ITEMRESERVED2 | — | — | OK dari data ("RAK B.30"); tidak ada di template 133 |
| deskripsi, nama_proyek, dikeluarkan_oleh | (sama) | tidak ada di template | — | — | Tetap manual |

### RI

| Current column | Target column | Accurate source | Lookup | Fallback | Action |
|---|---|---|---|---|---|
| accurate_apinvoice_id | (sama) | APINV.APINVOICEID | — | — | OK |
| no_ri | (sama) | **APINV.SEQUENCENO** ("No. RI" = [Form No]) | — | INVOICENO bila SEQUENCENO bukan nomor RI (template lama: "2379") | **NEED_UPDATE**, 11 baris salah sekarang. NEED_VERIFICATION aturan fallback |
| — | **no_surat_jalan** (baru) | APINV.INVOICENO ("No. Surat Jalan") | — | — | NEED_MIGRATION |
| tgl_ri | (sama) | APINV.INVOICEDATE | — | — | OK |
| divisi (tampil "Jenis RI") | divisi (label "Jenis RI") | bagian ke-2 dari **no_ri** (bukan INVOICENO lagi) | — | — | NEED_UPDATE. Jangan rename (dipakai turunan Barang Bekas "NV", dashboard, ekspor) |
| shipdate | (sama) | APINV.SHIPDATE | — | — | OK |
| vendor | (sama) | APINV.VENDORID | PERSONDATA.NAME | — | OK |
| vendor_id | (sama) | — (FK internal ke vendors) | — | — | OK |
| no_po | (sama) | APINV.PURCHASEORDERNO | — | — | OK. Template detail punya [PO No] per baris (PO.PONO via POID) |
| keterangan | (sama) | APINV.DESCRIPTION | — | — | OK |
| — | **pengirim** (baru) | APINV.SHIPVIA | SHIPMENT.NAME | — | NEED_MIGRATION. Hanya 1 dok terisi |
| — | **fob** (baru) | APINV.FOB | — | — | NEED_MIGRATION. ~1.250 dok terisi |
| accurate_seq … harga_satuan | (sama) | APITMDET.* | — | — | OK |
| — | **no_seri** (baru) | SNHISTORY + SERIALNUMBERS via APITMDET.ITEMHISTID | port VIEW_SN_BYITEMHIST | — | NEED_MIGRATION + mirror |
| — | **no_ppb** (baru) | APITMDET.POID+POSEQ → PODET.REQID → REQUISITION.REQNO | PODET, REQUISITION (sudah dimirror) | — | NEED_MIGRATION |
| accurate_po_item_id | (sama) | APITMDET.POID + POSEQ | purchase_order_items | — | OK |
| pemeriksa | (sama) | APITMDET.DEPTID | DEPARTMENT | format lama ITEMRESERVED3 | OK (sudah). **NEED_VERIFICATION**: template menamai "Departemen" (tidak dicetak); RI/NV berisi orang Maintenance (pengembali?) |
| lokasi_gudang / lokasi_rak | (sama) | APITMDET.ITEMRESERVED1 / 2 | — | — | OK dari data. **NEED_VERIFICATION** ringan: Reserved RI tidak ada di template |

## 4. Rekomendasi rename

| Dari | Ke | Alasan / risiko |
|---|---|---|
| `npbg.jenis_pengeluaran` | gabung ke `npbg.klasifikasi` (drop `jenis_pengeluaran`) | Arti sama dengan "Klasifikasi [Ship Via]"; `klasifikasi` kosong. Dipakai di 10 file (sync, resource, controller filter + endpoint, ekspor, SyncDiff, migrasi, test, 3 file frontend). Sudah ada di production sejak 05-10 → migrasi salin + drop |
| `npbg.no_seri_nopol` | `npbg.no_seri` | Kosong (0 baris). Dipakai di 8 file (model fillable, controller update rule, resource, komentar sync, migrasi, test, frontend api + detail edit). Ubah ke TEXT (SNLine bisa sangat panjang) |
| `ri.divisi` | **tidak di-rename** | Dipakai turunan Barang Bekas (`divisi='NV'`), dashboard, ekspor, AccurateDocRefs, frontend. Cukup label "Jenis RI" (sudah) |

## 5. Kolom baru

- `ri.no_surat_jalan`, `ri.pengirim`, `ri.fob`, `ri.no_seri` (text), `ri.no_ppb`.
- `npbg.no_seri` lewat rename.
- **Tabel mirror baru (opsional):** `TERMOPMT`, `SNHISTORY`, `SERIALNUMBERS`. Whitelist agen `tables.py` + `config/accurate.php` + test.

## 6. Tidak boleh dihapus

- Semua tabel mirror `accurate_*` dan semua kolomnya. Tetap dimirror penuh, termasuk ITEMRESERVED1–10, SEQUENCENO, TEMPLATEID, ITEMHISTID.
- `npbg.peminta`, `npbg.divisi`, `ri.divisi`, `ri.pemeriksa`, `ri.vendor_id`, `ri.accurate_po_item_id`.
- `npbg.deskripsi`, `nama_proyek`, `dikeluarkan_oleh` (manual).
- Kolom unique/FK.

## 7. Field ambigu — NEED_VERIFICATION (cek di Accurate)

1. **Tipe NPBG [Terms]**: cetak NPBG format baru (mis. NA/26/X/022). Yang tertulis "C.O.D" (TERMNAME) atau "NON-PENJUALAN" (TERMMEMO)?
2. **Peminta [Department]**: pada NA/26/X/022 tercetak "NINING" (DEPTNO) atau "GENERAL SERVICES" (DEPTNAME)?
3. **No. RI [Form No]**:
   - Benarkan RI/NV/26/IX/050 adalah No. RI dan SRT-07965 adalah No. Surat Jalan untuk dokumen yang sama.
   - Kasus aneh: dokumen ber-INVOICENO "RI/NV/26/IX/045" punya Form No "RI/NV/26/IX/047", sementara dokumen lain (SRT-07968) ber-Form No "RI/NV/26/IX/045". Nomor RI/NV/26/IX/045 dipakai 2 dokumen.
   - Kasus serupa: RI/NA/26/IX/065 vs RI/NA/26/IX/076.
4. **Aturan no_ri dokumen lama**: `SEQUENCENO` hanya angka → tetap pakai INVOICENO?
5. **RI Departemen**: arti untuk RI/NV (pengembalian): pemeriksa atau "dikembalikan oleh"?
6. **RI ITEMRESERVED1/2 = lokasi**: tidak ada di template RI; hanya dari isi data (3 baris).

## 8. Risiko migrasi

- **Ganti no_ri ke SEQUENCENO:**
  - Mengubah nomor 11 baris (+ dokumen format lama bila aturan salah).
  - Lewat `DocumentRenumber`, salinan teks nomor RI di tracking ikut diganti.
  - **Risiko tabrakan:** RI/NV/26/IX/045 dipakai 2 dokumen (lihat 7.3); salinan teks tanpa FK bisa salah tunjuk. Perlu dicek manual 2 kasus itu.
  - Turunan Barang Bekas (`divisi='NV'`) akan **menambah** 4 dokumen RI/NV yang sekarang terlewat.
- **Drop `jenis_pengeluaran`:** frontend lama (cache browser) masih memanggil `?jenis_pengeluaran=` / `/npbg/jenis-pengeluaran` → sediakan alias sementara atau deploy frontend bersamaan.
- **Rename `no_seri_nopol`:** aman secara data (kosong). Form edit NPBG di frontend harus diganti bersamaan.
- **Mirror SNHISTORY/SERIALNUMBERS:** ±7 ribu baris, kecil. SNLine harus port persis prosedur `VIEW_SN_BYITEMHIST` (format rentang, batas 29.900 karakter) dan diverifikasi 100% terhadap prosedur Accurate seperti qty barang.
- **Kode di production langsung aktif** (backend hidup): urutan migrasi → kode → frontend harus dalam satu jendela di luar jadwal sync (06:30 / 09:00 / 12:20 / 21:00).

## 9. File yang akan diubah

- **Agen:** `sync-service/mapping/tables.py` (TERMOPMT, SNHISTORY, SERIALNUMBERS sebagai opsional).
- **Laravel config/test:** `backend/config/accurate.php`, `tests/Feature/Agent/AccurateIngestTest.php` (otomatis).
- **Migrasi:** baru `2026_10_xx_npbg_ri_template_fields` (ri: no_surat_jalan, pengirim, fob, no_seri, no_ppb; npbg: rename no_seri_nopol→no_seri + text, salin jenis_pengeluaran→klasifikasi, drop jenis_pengeluaran).
- **Service:**
  - `AccurateSyncService.php`: syncNpbg/syncOneNpbgLine, syncRi/syncOneRiLine, deriveDivisiFromDocNumber dipakai pada no_ri baru, select kolom existing;
  - `AccurateLineFields.php`: tipe NPBG, klasifikasi, pengirim;
  - baru `AccurateSerialNumbers.php` (port VIEW_SN_BYITEMHIST);
  - `DocumentRenumber.php` (cek kasus tabrakan nomor).
- **Model:** `Npbg.php` (fillable no_seri), `Ri.php`.
- **Controller / resource / ekspor:**
  - `NpbgController.php` (filter klasifikasi, endpoint opsi, update rule no_seri);
  - `RiController.php` (pencarian no_surat_jalan/no_ppb);
  - `NpbgResource.php`, `RiResource.php`;
  - `ExportController.php`, `Services/Export/Legacy/LegacyExcelExportService.php` (cek kolom NPBG/RI);
  - `Support/SyncDiff.php` (label).
- **Frontend:** `features/npbg/api.ts`, `features/ri/api.ts`, `pages/NpbgListPage.tsx`, `NpbgDetailPage.tsx` (form edit tipe/klasifikasi/no_seri → read-only dari Accurate), `RiListPage.tsx`, `RiDetailPage.tsx`.
- **Test:** `AccurateNpbgSyncTest`, `AccurateNewInputFormatTest`, `AccuratePpbRiDeleteSyncTest`, `AccurateUsedReturnFromRiTest`, baru test SNLine + Form No.

## 10. Test yang harus dilakukan

1. **Unit:**
   - aturan no_ri (SEQUENCENO vs INVOICENO, template lama/baru, kasus SRT);
   - tipe NPBG (TERMOPMT);
   - klasifikasi/pengirim (SHIPMENT);
   - no_ppb lewat PODET→REQUISITION;
   - SNLine.
2. **Verifikasi terhadap Accurate di salinan staging:**
   - hasil SNLine PHP = `VIEW_SN_BYITEMHIST` untuk semua ±550 dokumen bernomor seri;
   - no_ppb = `TEMPLATE_APITMDET.REQNO` untuk semua baris RI.
3. **Sync:**
   - RI ber-SRT jadi no_ri RI/NV/…, divisi NV, masuk Barang Bekas;
   - renumber salinan tracking tidak salah tunjuk;
   - re-sync kedua tidak menghasilkan UPDATE palsu.
4. **Regresi:** suite penuh backend + frontend; ekspor NPBG/RI; filter klasifikasi; form edit NPBG.
5. **Pasca-sync live:** perbandingan baris per baris NPBG/RI vs staging (seperti audit 04-10).

---

## Update 2026-10-05 (setelah "lanjut")

**Dikerjakan:**
- **Agen:**
  - mirror opsional `TERMOPMT`, `SNHISTORY`, `SERIALNUMBERS`;
  - tabel turunan `SNLINE_BYITEMHIST`, dihitung di salinan staging oleh prosedur Accurate `VIEW_SN_BYITEMHIST(ITEMHISTID, 0)`. Urutan nomor seri ditentukan mesin Firebird sehingga tidak bisa ditiru dari tabel mentah; port PHP hanya cocok 810/841.
- **Migrasi `2026_10_05_120000_npbg_ri_accurate_template_fields`** (live, backup `Backup/npbg-ri-template-20261005_025143`):
  - `ri` + `no_surat_jalan`, `pengirim`, `fob`, `no_ppb`, `no_seri`;
  - `npbg.jenis_pengeluaran` disalin ke `klasifikasi` (245 baris) lalu di-drop;
  - `npbg.no_seri_nopol` (0 baris) → `no_seri` TEXT.
- **Sync:**
  - NPBG `klasifikasi` = SHIPVIA→SHIPMENT; `no_seri` = SNLine;
  - RI `no_surat_jalan` = INVOICENO; `fob` = FOB; `pengirim` = SHIPVIA→SHIPMENT; `no_ppb` = PODET.REQID→REQUISITION.REQNO; `no_seri` = SNLine;
  - bila tabel sumber tidak terkirim, kolomnya tidak dikosongkan.
- **API dan layar:**
  - `klasifikasi` dan `no_seri` NPBG tidak bisa diedit lagi (sumbernya Accurate);
  - endpoint `/api/npbg/klasifikasi` (alias lama `/jenis-pengeluaran` dan `?jenis_pengeluaran=` tetap diterima);
  - resource, pencarian, dan ekspor diperbarui; layar NPBG/RI diperbarui.

**Verifikasi terhadap prosedur template Accurate (salinan staging):**
- No. PPB: 4.997/4.997 baris RI cocok `TEMPLATE_APITMDET.REQNO`.
- No. Seri: RI 4.997/4.997 dan NPBG 8.896/8.896 cocok `TEMPLATE_APITMDET` / `TEMPLATE_ARINVDET.SNLINE`.

**Tes:** backend 329/329, frontend 26/26. Deploy frontend backup `Backup/frontend-public-20261005_095223`.

**Kapan terisi:** kolom baru terisi pada sync full berikutnya (12:20). Tabel SHIPMENT/PODET/REQUISITION sudah ada, jadi Klasifikasi/Pengirim/No. PPB juga ikut terisi pada sync terjadwal berikutnya.

**Masih menunggu verifikasi user (belum diubah):**
- No. RI = Form No (`SEQUENCENO`) dan kasus nomor dobel RI/NV/26/IX/045 dan RI/NA/26/IX/065;
- isi Tipe NPBG (TERMNAME vs TERMMEMO);
- Peminta DEPTNO vs DEPTNAME;
- arti Departemen di RI/NV.
