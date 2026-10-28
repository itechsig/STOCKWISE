# STOCKWISE — Assumptions Register (resolusi NEEDS CONFIRMATION)

> Jawaban & asumsi yang dipakai untuk STEP 2. **Semua baris `ASUMSI` bisa Anda koreksi di gate approval
> STEP 3.** Referensi: [step1-analysis.md §12](step1-analysis.md).

## Dikonfirmasi user (2026-09-08)

| ID | Keputusan |
|----|-----------|
| NC-5 | **Multi-site.** `SIG-SDA` mengelola inventory penuh. `SIG-BPN` **hanya bisa membuat Material Request** dulu (tidak ada inventory/opname/NPBG di BPN). Schema menyiapkan `sites` + `site_id` di mana-mana; hanya SDA yang `is_inventory_managed = true`. |
| NC-10 | **NPBG = hasil Request yang di-approve.** Saat Request dibuat, tiap baris otomatis menampilkan *stok terakhir menurut sistem* + *safety stock* + *projected stock*. Admin/Anak Gudang menambah langkah **validasi fisik**: apakah barang benar ada & jumlahnya sesuai sistem. Baru setelah itu → reserve → NPBG. |
| NC-1 | **Modul PO dibangun penuh** di STOCKWISE: `PPB → RFQ → PO → Receiving`. Nomor lama `BL/...` menjadi salah satu `prefix` PO. |
| NC-2..NC-4, NC-6..NC-9, NC-11..NC-22 | Pakai asumsi di bawah; dikoreksi saat review. |

## Asumsi kerja (default — mohon dikoreksi bila salah)

| ID | Topik | ASUMSI dipakai |
|----|-------|----------------|
| NC-2 | Prefix nomor dokumen | `NA` = umum/non-ATK, `ATK` = alat tulis kantor, `NV` = penerimaan (RI), `F` = varian PO. Prefix disimpan sbg kolom `prefix` + bisa dikelola di Settings. Generator nomor baru: default `NA` untuk NPBG/PPB, `NV` untuk RI, `PO` untuk PO. |
| NC-3 | File foto asli | Belum tersedia. Kolom foto lama disimpan sebagai `attachments.external_ref` (string). Upload foto baru via API multipart. Migrasi foto lama = fase terpisah bila filenya diserahkan. |
| NC-4 | Stok awal | Diisi lewat **Stock Opname awal (opening)** saat go-live per gudang. `SISA STOK` teks di `DATA.xlsx` di-parse jadi angka bila ada, sebagai *usulan* nilai opname; kosong = tidak diusulkan (bukan 0). Movement pertama tiap item = `OPENING_BALANCE`. |
| NC-6 | Kategori | Hierarki **maksimal 4 level**, level 2–4 nullable. Master kategori di-seed dari nilai unik `DATA.xlsx`. Bisa CRUD oleh Super Admin. `path` di-materialize untuk filter. |
| NC-7 | Safety Stock — konflik & rumus | Jika 1 deskripsi punya SS berbeda di >1 sheet kategori → **ambil nilai dari sheet yang kategori-nya cocok dengan `items.category_id`**; jika tetap ambigu → nilai terbesar, semua baris disimpan di `item_safety_stocks`, satu ditandai `is_effective`, sisanya muncul di halaman *review konflik*. Rumus SS tidak dihitung ulang oleh app untuk sekarang — nilai `SS` & `MIN PR` **diambil apa adanya** dari sheet (data-entry manual oleh tim). Engine hanya memakai `safety_stock` + `lead_time` (brief §C). |
| NC-8 | Kolom rusak | `Nama Alias` (isi "Tidak") **tidak dimigrasi**. `BLUEPRINT 3D VIEW` (isi kode rak) dimigrasi ke `items.blueprint_3d_ref` mentah + ditandai untuk review; tidak dipakai sebagai lokasi. |
| NC-9 | Status flow | 12 status Request (brief §G) adalah **status kanonik sistem baru**. Status Excel lama (PPB `Requested/Shortage/...`, dll) dipetakan saat migrasi (lihat [status-flow.md](status-flow.md) tabel mapping). |
| NC-11 | RI satu entitas | **Ya, satu entitas `receivings`** untuk semua barang masuk, dibedakan `source_type` (PURCHASE, LEND_RETURN, BORROW_RETURN, STPP_RETURN, TYRE_OLD, MANUFACTURING_OUTPUT, USED_RETURN, TRANSFER_IN, OPENING). Stok hanya bertambah saat `status = CONFIRMED`. |
| NC-12 | Maintenance & RI | Modul Maintenance Assets **boleh** mereferensikan RI (untuk part retur/sisa), kolom `ri_id` nullable di `maintenance_order_subs`, walau di Excel sekarang hanya ada NPBG. |
| NC-13 | STPP qty non-integer | 1 baris STPP bisa qty > 1 atau satuan non-unit (mtr, cm²). `serial_unit` di-link hanya bila `qty = 1` & kind = alat ber-serial; selebihnya baris STPP tanpa serial unit. |
| NC-14 | Pengembalian Bekas — matriks komponen | 14 tipe komponen (`Bonit BR`, `Pen SS`, dst) diperlakukan sebagai **daftar dinamis** `used_return_component_types` (seed 14 nilai, bisa ditambah). Nilai negatif = *shortage* (barang kurang saat barter/retur), positif = *surplus*. Disimpan di `used_return_items.qty` (signed). |
| NC-15 | Ban / Tyre | Ban **di-track sebagai unit ber-serial seumur hidup** (`serial_units` kind=tyre): bisa vulkanisir, pindah kendaraan, jadi bekas. `tyre_changes` = event; serial unit = master. |
| NC-16 | Produk manufaktur (`SIG-58`) | Masuk sebagai **`serial_units` kind=manufactured**, di-link ke `items` (barang jual/pakai). Saat RI hasil produksi `CONFIRMED` → `STOCK_IN` ke inventory. Bukan `assets`. |
| NC-17 | Cut-off migrasi | Migrasi **semua baris dalam file** (bukan hanya setelah cut-off), tapi baris tanpa relasi valid ditandai `is_historical / unmatched` dan **tidak** menggerakkan stok. Stok live dimulai dari opening balance go-live. |
| NC-18 | Divisi vs Penempatan | Satu master `departments`. `Penempatan` STPP yang bukan divisi (mis. `GUDANG (PROJECT)`, `MAINTENANCE KENDARAAN`) disimpan sebagai `placement_raw` + dipetakan ke department terdekat bila memungkinkan. |
| NC-19 | Users ↔ nama Excel | Seed `employees` dari semua nama di Dropdown List. `users` (login) dibuat manual oleh Super Admin dan di-link ke `employee`. Role di-assign manual; tidak ditebak dari data. Seeder dev membuat akun uji per role (brief §AK). |
| NC-20 | Lend "Tanda Keluar" non-NPBG | Nilai seperti `2025/SIG CV/1959-1960` = nomor nota penjualan/CV. Disimpan di `lend_transactions.out_ref_raw`; `out_npbg_id` null. Tidak menghalangi flow. |
| NC-21 | Sheet `Sheet6`/`cetak`/`Sheet2` di DATA.xlsx | Diasumsikan **scratch/print area, tidak dimigrasi.** Akan dicek isinya sebelum migrasi; bila ternyata berisi master → lapor. |
| NC-22 | Konversi UoM | Tidak ada konversi otomatis untuk sekarang — **1 item = 1 satuan dasar**. Tabel `unit_conversions` disiapkan (kosong) untuk kebutuhan nanti. |

## Asumsi tambahan (muncul saat desain STEP 2)

| # | Asumsi |
|---|--------|
| A1 | "Sisa Stok" pada calculation engine (brief §C) = **Available Stock** (`actual - reserved`), bukan Actual. Alasan: reserved sudah dijanjikan keluar. Nilai Actual-based tetap diekspos di API untuk perbandingan. |
| A2 | Lead Time Threshold (Priority Level) = **persentil-75 `lead_time_days` dari seluruh item aktif yang punya lead time**, dihitung ulang tiap kali analisis dijalankan (job nightly + on-demand), fallback **14 hari** bila data < 4 item. |
| A3 | Median Defisit (Priority Level) = median `deficit` dari **semua item berstatus TIDAK AMAN pada scope yang sama** (global, atau per-warehouse bila filter warehouse aktif). |
| A4 | Teks & ambang **Rekomendasi** legacy ada di `utils/calc_engine.py` repo Streamlit yang **tidak ada di repo ini**. Sementara dipakai rumusan di [calculation-engine.md §5](calculation-engine.md); minta file lama untuk paritas persis. `[NEEDS CONFIRMATION]` |
| A5 | Material Request dari BPN: barang disiapkan & dikirim dari gudang SDA; tidak ada pickup fisik di BPN — status berhenti di `READY_TO_PICKUP` lalu `PICKED_UP` saat kurir/PIC BPN ambil di SDA (atau saat barang dikirim). Detail logistik antar-site = fase lanjut. |
| A6 | Satu Material Request bisa menghasilkan **>1 NPBG** (sebagian barang ready sekarang, sebagian setelah PO) dan **>1 PPB**. |
| A7 | Reservation kadaluarsa otomatis (job) bila Request `CANCELLED` atau tidak di-pickup dalam N hari (default 14, Settings). |
| A8 | Semua nomor dokumen unik pada composite `(doc_type, prefix, year, month, sequence)`; string penuh disimpan di kolom `number` untuk tampilan & pencarian. |
| A9 | Currency tunggal **IDR**, tanpa pajak berjenjang (PPN 11% flat opsional di PO). Harga hanya di modul Purchasing; modul lain tidak menyimpan nilai rupiah. |
| A10 | Timezone `Asia/Jakarta`; semua timestamp disimpan UTC di DB. |
