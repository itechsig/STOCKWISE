# STOCKWISE — Excel Data Mapping

> **STEP 1 deliverable.** Hasil inspeksi 9 file Excel existing milik PT Surya Inti Gas (SIG).
> Belum ada keputusan skema database di sini — dokumen ini hanya **memetakan apa yang ada di Excel**.
> Semua asumsi yang belum bisa dipastikan ditandai `[NEEDS CONFIRMATION]` dan dikumpulkan di
> [step1-analysis.md](step1-analysis.md) §12.

Metode: `openpyxl` read-only, `data_only=True` (membaca hasil kalkulasi, bukan formula).
Baca ulang bisa dilakukan dengan skrip di `scratchpad` sesi ini bila perlu.

Legend kolom tabel:
- **Tipe**: tipe nilai yang benar-benar muncul di sel (bukan tipe yang diformat).
- **M/T**: `M` master-ish, `T` transaksi, `H` helper/formula spreadsheet (bukan data), `A` attachment/foto.
- **Key**: `PK?` kandidat primary key, `FK→x` kandidat foreign key, `enum` nilai terbatas.

---

## Ringkasan file

| # | File | Sheet berisi data | Fungsi bisnis | Dokumen kunci |
|---|------|-------------------|---------------|---------------|
| — | `DATA.xlsx` | `DATABASE UTAMA` + 12× `SAFETY STOCK *` | **Master barang** + parameter safety stock (basis STOCKWISE lama) | Kode Barang |
| 1 | `1. PPB - RI.xlsx` | `PPB`, `RI`, `PPB Perubahan` | Permintaan pembelian → penerimaan barang | No PPB, No RI, No PO |
| 2 | `2. NPBG.xlsx` | `NPBG` | **Nota Pengeluaran Barang Gudang** (dokumen keluar pusat) | No NPBG |
| 3 | `3. Tracking Borrow & Lend.xlsx` | `Lend`, `Borrow` | Pinjam/meminjamkan barang ke/dari pihak luar | (ref NPBG/RI) |
| 4 | `4. Tracking STPP.xlsx` | `STPP`, `Maintenance` | Serah Terima Pinjam Pakai — alat ber-serial ke divisi | No Seri (SN-xxxx) |
| 5 | `5. Tracking Ban Luar.xlsx` | `Ban Luar`, `Ban Luar BPN`, `Deliver & Receive Ban SIG-BPN` | Tracking penggantian ban luar per kendaraan | No Seri ban, Nopol |
| 6 | `6. Tracking Maintenance Assets.xlsx` | `Maintenance Kendaraan` | Work order perbaikan aset/kendaraan | No. SPK + Sub SPK |
| 7 | `7. Tracking Manufaktur & Assembly.xlsx` | `Manufaktur & Assembly`, `Manufaktur & Jasa Lain-Lain` | Produksi cradle/manifold + jasa outsourced | No. MA + Sub MA / No. MJ |
| 8 | `8. Tracking Pengembalian Bekas.xlsx` | `Spare Part`, `Spare Part Lain` | Pengembalian barang bekas/rusak ke gudang | (ref NPBG/RI) |

**Setiap file juga punya sheet `Dropdown List`** = sumber data-validation Excel (list Peminta, Divisi,
Satuan, Vendor, Nopol, dll). Bukan entitas; berguna sebagai **seed master data**.

Konvensi umum di semua file:
- Baris 0–3/4 = judul + catatan cut-off (mis. `Cut-Off 15/04/2026`). Header sebenarnya ada di baris 4
  (kadang 3 atau 5). Kolom `A` selalu kosong; kolom `No` biasanya nomor urut formula (sering kosong saat dibaca).
- Ada 4.000–13.000 baris "hantu" (format ke-drag) di bawah data nyata.
- Kolom foto berisi teks `"Open File"` atau formula `=DISPIMG("ID_...",1)` → gambar ditempel di dalam
  workbook, **tidak terbaca sebagai data biasa**. Diperlakukan sebagai attachment. `[NEEDS CONFIRMATION]`
  apakah file foto asli tersedia terpisah.
- Kolom bernama `Helper *`, `HC *`, `Concat`, `cnt*`, `sum*`, `Column1`, `Kolom1` = hasil formula
  spreadsheet, **bukan data bisnis** → tidak dimigrasi.

---

## Nomor dokumen (document number) — pola & terminologi

| Dokumen | Contoh | Pola | Unik pada |
|---------|--------|------|-----------|
| NPBG | `NA/25/VIII/138`, `ATK/25/III/01` | `{PFX}/{YY}/{ROMAWI-BULAN}/{SEQ}` | (PFX, tahun, bulan, seq) — **bukan** global |
| PPB | `PPB/NA/25/III/01`, `PPB/ATK/25/II/07` | `PPB/{PFX}/{YY}/{ROMAWI}/{SEQ}` | idem |
| RI (Receive Item) | `RI/NV/25/III/92`, `RI/NA/25/III/01` | `RI/{PFX}/{YY}/{ROMAWI}/{SEQ}` | idem |
| PO | `BL/F/25/II/74`, `BL/25/II/67` | `BL[/F]/{YY}/{ROMAWI}/{SEQ}` | idem — **dokumen PO sendiri tidak ada di dataset** |
| SPK maintenance | `MK/25/VII/001` + `SUB-01..05` | `MK/{YY}/{ROMAWI}/{SEQ}` + sub | (No SPK, Sub SPK) |
| Manufaktur & Assembly | `MA/25/VII/001` + `SUB-01..03` | `MA/{YY}/{ROMAWI}/{SEQ}` + sub | (No MA, Sub MA) |
| Manufaktur & Jasa | `MJ/25/VIII/021` + `SUB-01..02` | `MJ/{YY}/{ROMAWI}/{SEQ}` + sub | (No MJ, Sub MJ) |

- Prefix (`PFX`) yang muncul: `NA` (umum/non-ATK), `ATK` (alat tulis kantor), `NV` (dominan di RI),
  `F`. Arti pasti prefix `[NEEDS CONFIRMATION]`.
- Sentinel `ORIGIN` dipakai sebagai `No NPBG`/`No RI` di Ban Luar untuk baris "pendataan awal" (bukan
  transaksi nyata).
- Nama file foto = nomor dokumen dengan `/` diganti `.` lalu `-{itemno}` atau `_{itemno}`
  (mis. `NA.25.VIII.138-87`).

---

## DATA.xlsx — master (basis STOCKWISE lama)

> Detail lengkap master ini sudah ada di memory `project_stockwise_expansion`. Ringkas di sini karena
> jadi tulang punggung modul Inventory + calculation engine.

### Sheet `DATABASE UTAMA` — ~5.900 barang, header baris 4

| Kol | Nama | Tipe | Contoh | Fungsi | M/T | Key |
|-----|------|------|--------|--------|-----|-----|
| 0 | Kode Barang | str | `PUI.0019`, `SSP.1234` | Kode internal barang | M | **PK** |
| 1 | Kategori Induk | str | `Post-Use Items`, `Small Spare Parts` | Kategori L1 (12 nilai) | M | FK→categories, enum |
| 2 | Kategori Anak 1 | str | `NEED ASSESSMENT (BEKAS)` | Kategori L2 (~77) | M | FK→categories |
| 3 | Kategori Anak 2 | str | `BAN LUAR (TIRES)`, `MUR` | Kategori L3 (~149) | M | FK→categories |
| 4 | Kategori Anak 3 | str | `MUR CARBON STEEL` | Kategori L4 (~99) | M | FK→categories |
| 5 | Deskripsi Barang | str | `(BEKAS) ACCU - ASPIRA / 145G51L ...` | Nama lengkap barang | M | **natural key** ke file transaksi |
| 6 | UoM | str | `PCS`, `BK`, `LTR` (16 nilai) | Satuan | M | FK→units, enum |
| 7 | Perlu Blueprint? | str | `Ya` / `Tidak` | Flag butuh blueprint | M | enum/bool |
| 8 | Nama Alias | str | hanya `Tidak` | **Kolom rusak** — isinya bukan alias | M | — (abaikan) |
| 9 | LETAK GUDANG | str | `GUDANG 1`, `GUDANG 1 `, `ETALASE` (9 varian, ada trailing space) | Lokasi gudang | M | FK→warehouses |
| 10 | LETAK RAK | str | `B.7.1` | Kode rak | M | FK→warehouse_locations |
| 11 | BLUEPRINT IMG | kosong | — | — | A | — |
| 12 | BLUEPRINT DETAIL PDF | kosong | — | — | A | — |
| 13 | BLUEPRINT 3D VIEW | str | `A.17.31` | (isinya justru kode rak) | M | `[NEEDS CONFIRMATION]` |
| 14 | SISA STOK (22/08/2026) | str | `STOK 0 PCS`, `STOK 2 PCS ` | **Stok fisik terakhir sebagai teks** — ~63% kosong | T | perlu parsing angka |
| 15 | LEAD TIME | float+date | `14`, `3`, `30`, `2026-07-04` (kotor) | Lead time hari | M | — |
| 16 | √LT | float | `0.4082` (hampir semua kosong) | akar(LT) faktor SS | H | derived |
| 17 | SAFETY STOCK | kosong | — | diisi dari sheet `SAFETY STOCK *` | M | — |
| 18 | MIN PR | kosong | — | minimum purchase request | M | — |

### Sheet `SAFETY STOCK <kategori>` × 12 — parameter SS per kategori

Header 2 baris (baris 0–2). Kolom: `NO`, `ITEM DESCRIPTION`, lalu **12 kolom bulan** `Agt..Juli`
(qty keluar per bulan dari NPBG), `RATA RATA PENGELUARAN` (`1/3/6/12 BLN`), `LT`, `√LT`, `SS`, `MIN PR`.

- Join ke barang **lewat `ITEM DESCRIPTION` (teks), bukan Kode Barang** → ambigu.
- 12 sheet: ASSETS, AUTOMOTIVE, BIG SPAREPARTS, ELECTRONICS & ELEC, ETALASE, HOUSE HOLD & NEEDS,
  MAINTENANCE & INDU, MANUFAKTUR & ASSEM, OFFICE APPAREL & A, OFFICE NEEDS, POST USE ITEM,
  SMALL SPAREPARTS. Deskripsi bisa muncul di >1 sheet dengan `SS` berbeda → konflik.
- Sheet lain di `DATA.xlsx` (`Sheet6`, `cetak`, `Sheet2`) belum diperiksa isinya `[NEEDS CONFIRMATION]`.

---

## File 1 — `1. PPB - RI.xlsx`

### Sheet `PPB` — Permintaan Pembelian Barang · ~3.773 baris item / **696 No PPB** · header baris 4

| Kol | Nama | Tipe | Contoh | Fungsi | M/T | Key |
|-----|------|------|--------|--------|-----|-----|
| 2 | Tgl PPB | date | `2025-03-03` | Tanggal PPB | T | |
| 3 | No PPB | str | `PPB/NA/25/III/01` | Nomor PPB | T | **FK-group** (header) |
| 4 | Deskripsi Barang | str | `REFILL CARTRIDGE - HP / P1102 / HITAM` | Barang diminta | T | natural key→item |
| 5 | Kuantitas | num | `1`, `24`, `40` | Qty diminta | T | |
| 6 | Satuan | str | `PCS`, `MTR` (27 nilai) | UoM | T | FK→units |
| 7 | Peminta | str | `RAFI`, `PAK HANES` (~55) | Requester | T | FK→people |
| 8 | Divisi | str | `AKUNTING`, `MAINTENANCE` (13) | Divisi peminta | T | FK→departments, enum |
| 9 | Keterangan | str | `REFILL TONNER PRINTER GUDANG` | Alasan/keperluan | T | |
| 10 | Status | str | `Requested`, `Shortage`, `Amend`, `Close`, `Completed`, `Error` | Status PPB | T | **enum** |
| 11 | cntRI | int | 0–6 | # RI terkait (formula) | H | |
| 12 | sumRI | num | qty diterima total (formula) | H | |
| 13–14 | cntAmend / cntClose | int/`#REF!` | — | formula, ada error | H | |
| 15 | Concat | str | `No PPB`+`Deskripsi` | key match formula | H | |

Status berarti: `Requested`=baru, `Shortage`=stok kurang perlu beli, `Amend`=diubah,
`Close`=dibatalkan sebagian/penuh, `Completed`=selesai, `Error`=salah input. Konfirmasi transisi resmi
`[NEEDS CONFIRMATION]`.

### Sheet `RI` — Receive Item (penerimaan barang) · ~8.100 baris / **3.624 No RI** · header baris 4

| Kol | Nama | Tipe | Contoh | Fungsi | M/T | Key |
|-----|------|------|--------|--------|-----|-----|
| 2 | Tgl RI | date | `2025-03-01` | Tanggal terima | T | |
| 3 | No RI | str | `RI/NA/25/III/01` | Nomor RI | T | **FK-group** |
| 4 | Deskripsi Barang | str | `BAUT GALVANIS (ISO) - D=6mm...` | Barang diterima | T | natural key→item |
| 5 | Kuantitas | num/str | `102`, `9`, `-` | Qty diterima | T | |
| 6 | Satuan | str | `PCS`, `BK` (26) | UoM | T | FK→units |
| 7 | No PPB | str | `PPB/NA/25/II/68` (~43% terisi) | PPB sumber | T | **FK→PPB** (nullable) |
| 8 | No PO | str | `BL/F/25/II/74`, `-` | PO sumber | T | **FK→purchase_orders** (PO tak ada di data) |
| 9 | Vendor | str | `TOKO ANEKA BAUT`, `GUDANG (SIG SDA)` (~368) | Pemasok | T | FK→vendors |
| 10 | No Surat Jalan | str | `SRT-06267`, `-` | Nomor SJ vendor | T | |
| 11 | Pemeriksa | str | `AGUS`, `WAHYU` (7) | Petugas cek barang | T | FK→people, enum |
| 12 | Keterangan | str | `UNTUK STOK GUDANG` | Catatan | T | |

Beberapa RI tanpa `No PPB` (barang kembali dari project, barter, dsb) — RI bukan selalu hasil PPB.

### Sheet `PPB Perubahan` — amandemen PPB · 198 baris / 90 No PPB · header baris 4

Kolom: `Tgl Perubahan`, `No PPB` (FK→PPB), `Deskripsi Barang`, `Kuantitas`, `Satuan`, `Peminta`,
`Divisi`, **`Tipe Perubahan`** (`AMEND` / `CLOSE`), `Keterangan` (mis. `SISA STOK 6`, `TIDAK JADI DIPESAN`).

---

## File 2 — `2. NPBG.xlsx`

### Sheet `NPBG` — Nota Pengeluaran Barang Gudang · ~13.500 baris item / **5.053 No NPBG** · header baris 5

**Dokumen keluar-gudang pusat. Semua modul tracking merujuk ke sini.**

| Kol | Nama | Tipe | Contoh | Fungsi | M/T | Key |
|-----|------|------|--------|--------|-----|-----|
| 2 | Tgl NPBG | date | `2025-03-01` | Tanggal keluar | T | |
| 3 | No NPBG | str | `NA/25/III/01`, `ATK/25/III/01` | Nomor NPBG | T | **FK-group** (header) |
| 4 | Tipe NPBG | str | `PENJUALAN` / `NON-PENJUALAN` | Jenis | T | **enum** |
| 5 | Klasifikasi | str | `UMUM`, `PROYEK`, `MAINTENANCE KENDARAAN`, `LEND / BORROW`, `STPP`, `MANUFAKTUR`, `MAINTENANCE GEDUNG/MESIN/...`, `JASA` | **Menghubungkan NPBG ke modul** | T | **enum** |
| 6 | Pelanggan | str | `PT. RIMBA KENCANA` (~31) | Customer (kalau penjualan/proyek) | T | FK→customers (nullable) |
| 7 | Nama Proyek | str | `INSTALASI N2` (~31) | Proyek terkait | T | FK→projects (nullable) |
| 8 | No Seri / Nopol | str | `W 9863 NK (HINO DUTRO)`, `FORKLIFT 3 TON` (~45) | Kendaraan/aset terkait | T | FK→assets (nullable) |
| 9 | Deskripsi Barang | str | `ISI STAPLES KECIL - MAX / No.10-1M` | Barang keluar | T | natural key→item |
| 10 | Kuantitas | num | `3`, `102`, `30` | Qty keluar | T | |
| 11 | Satuan | str | `BK`, `PCK`, `LTR` (27) | UoM | T | FK→units |
| 12 | Peminta | str | `IIN`, `VANDIKA` (~107) | Peminta barang | T | FK→people |
| 13 | Dikeluarkan Oleh | str | `WAHYU` (hampir selalu kosong) | Petugas gudang | T | FK→people |
| 14 | Divisi | str | `AKUNTING`, `DISTRIBUSI` (9) | Divisi peminta | T | FK→departments, enum |
| 15 | Keterangan | str | `PEMAKAIAN HARIAN AKUNTING` | Catatan | T | |
| 16 | Kolom1 | kosong | — | — | H | |

Sheet `Export List_Klasifikasi` = tabel pivot rekap klasifikasi (89.787 baris "(Blanks)" = data lama
belum diklasifikasi). Sheet `Dropdown List` = master list Pelanggan, Proyek, Nopol, Satuan, Peminta,
Divisi, "Dikeluarkan Oleh".

---

## File 3 — `3. Tracking Borrow & Lend.xlsx`

### Sheet `Lend` — SIG **meminjamkan** barang ke pihak luar · ~360 transaksi · header baris 4

| Kol | Nama | Tipe | Contoh | Fungsi | Key |
|-----|------|------|--------|--------|-----|
| 2 | Tgl Pinjam | date | `2025-06-13` | Tgl keluar pinjaman | |
| 3 | Deskripsi Barang | str | `LASHING RACHET - SPANSET` | Barang | natural key→item |
| 4 | Kuantitas | num | `1`, `12`, `9.2` | Qty | |
| 5 | Satuan | str | `PCS`, `MTR`, `SET` | UoM | FK→units |
| 6 | Peminta | str | `RANDY`, `VANDIKA` (~50) | PIC internal | FK→people |
| 7 | Keperluan | str | `INTERNAL` / `PROJECT` / `RELASI` | Tujuan | **enum** |
| 8 | Est. Pinjam (hari) | int | `1,7,14,30,90,360` | Estimasi durasi | |
| 9 | Tanda Keluar | str | `NA/25/III/146`, `2025/SIG CV/1959-1960`, `=DISPIMG(...)` | Ref dok keluar (NPBG/nota) | FK→npbg (loose) / A |
| 10 | Keterangan Keluar | str | `U/ KIRIM W 8763 QC` | Catatan keluar | |
| 11 | Status | str | `SEDANG DIPINJAM`, `KEMBALI`, `DEADLINE`, `#REF!` | Status | **enum** |
| 12 | Tanda Kembali | str | `RI/NV/26/I/062`, `=DISPIMG(...)` | Ref dok kembali (RI) | FK→ri (loose) / A |
| 13 | Tgl Kembali | date | `2025-10-28` | Tgl kembali | |
| 14 | Keterangan Kembali | str | `KEMBALI LENGKAP`, `KEMBALI KURANG 1` | Kondisi saat kembali | |
| 15–16 | Helper 1 / Helper 2 | `TRUE/FALSE/#REF!` | — | formula | H |

### Sheet `Borrow` — SIG **meminjam** barang dari pihak luar · **27 transaksi** (modul baru, sangat sedikit)

Kolom: `Tgl Pinjam`, `Deskripsi Barang`, `Kuantitas`, `Satuan`, **`Vendor`** (pemberi pinjaman:
`DIREKTUR`, `PT. CANDI OXYGEN GASES`), `Keterangan`, `Tanda Terima` (ref RI/foto), `Status`
(`SEDANG DIPINJAM` / `LUNAS`), `Tanda Keluar` (ref NPBG saat dikembalikan), `Keterangan Barang Kembali`.

---

## File 4 — `4. Tracking STPP.xlsx`

### Sheet `STPP` — Serah Terima Pinjam Pakai · ~777 baris / **775 No Seri** · header baris 4

Alat/aset **ber-nomor-seri** yang dipinjam-pakaikan ke divisi (bukan habis pakai).

| Kol | Nama | Tipe | Contoh | Fungsi | Key |
|-----|------|------|--------|--------|-----|
| 1 | No | int | 1..776 | Urut | |
| 2 | No Seri | str | `SN-0001` | Serial alat | **PK** (master alat STPP) |
| 3 | Deskripsi Barang | str | `BETEL BAJA - 1"`, `GERINDA POTONG DUDUK - MAKITA / 2414NB / 14"` | Nama alat | natural key→item |
| 4 | Kuantitas | num | `1`, `4.5`, `180` | Qty | |
| 5 | Satuan | str | `PCS`, `SET`, `MTR`, `CM2` | UoM | FK→units |
| 6 | Peminta | str | `TOMY`, `SONY` (~55) | Pemegang | FK→people |
| 7 | Penempatan | str | `MAINTENANCE KENDARAAN`, `DISTRIBUSI`, `GUDANG` (~51) | Lokasi/divisi penempatan | FK→departments/locations |
| 8 | Tgl NPBG | date | `2025-08-15` | Tgl serah | |
| 9 | No. NPBG | str | `NA/25/VIII/138` | **NPBG serah** | **FK→npbg** |
| 10 | Item No | int | `87` | Nomor baris item di NPBG | |
| 11–12 | Nama File / Bukti Keluar | str | `NA.25.VIII.138-87` / `Open File` | Foto serah | A |
| 13 | Keterangan Keluar | str | `UNTUK STPP AWAL MAINTENANCE` | Catatan | |
| 14 | Status | str | `ACTIVE` / `PASSIVE` | Masih dipakai / sudah ditarik | **enum** |
| 15 | Tgl RI | date | `2026-03-25` | Tgl tarik/kembali | |
| 16 | Tanda Kembali | str | `RI/NV/26/III/052` | **RI penarikan** | **FK→ri** |
| 17–19 | Item No2 / Nama File2 / Bukti Terima | | foto terima | A |
| 20 | Keterangan Kembali | str | `KEMBALI DARI MAINTENANCE KARENA RUSAK` | Catatan tarik | |
| 21–23 | HC * | | formula | H |

### Sheet `Maintenance` — 16 baris · alat STPP yang sedang di-maintenance
Kolom: `No Seri` (FK→STPP), `Deskripsi Barang`, `Kuantitas`, `Satuan`, `Peminta`, `Tgl NPBG`,
`No NPBG` (FK→npbg), `Keterangan`.

---

## File 5 — `5. Tracking Ban Luar.xlsx`

### Sheet `Ban Luar` — penggantian ban luar SIG-Sidoarjo · ~606 baris · header baris 4

Model: setiap baris = **1 kejadian pasang ban baru + (opsional) copot ban lama** di 1 posisi kendaraan.

| Kol | Nama | Tipe | Contoh | Fungsi | Key |
|-----|------|------|--------|--------|-----|
| 2 | Nopol (Kendaraan) | str | `W 8246 NZ (NISSAN CWB)`, `EKOR TRAILER SIG-04` (~48) | Kendaraan | **FK→assets** |
| 3 | Tgl NPBG | date | `2025-01-26` | Tgl pasang | |
| 4 | No NPBG | str | `NA/25/II/07`, `ORIGIN` | NPBG ban keluar | FK→npbg |
| 5 | Deskripsi Ban Baru | str | `BAN LUAR STEEL - ROVELO / 11.00-R20 TT / SAM 3` (~59) | Tipe ban baru | natural key→item |
| 6 | No Seri Baru | str | `2320611829 (M-0523)` | **Serial ban baru** | PK ban-unit |
| 7 | Column1 | str | `NA.25.III.231 C221222799 (M-1322)` | key foto (formula) | H |
| 9 | Ban | int | 1–11 | Posisi/urut ban | |
| 10 | Pergantian | int | 1–6 | Ke-berapa kali ganti | |
| 11 | Keterangan Keluar | str | `BAN LAMA TIPIS & KAWATNYA KELUAR` | Alasan ganti | |
| 12 | Status | str | `CLEAR` / `PENDING RI` | Ban lama sudah dikembalikan? | **enum** |
| 13 | Tgl RI | date | `2025-03-27`, `-` | Tgl terima ban lama | |
| 14 | No. RI | str | `RI/NV/25/III/92`, `ORIGIN` | RI ban lama masuk | FK→ri |
| 15 | Deskripsi Ban Lama | str | `(BEKAS) BAN LUAR STEEL - GAJAH TUNGGAL / 185-R14 TL ...` | Tipe ban lama | natural key→item |
| 16 | No Seri Lama | str | `R0175-12 (M-0220)`, `-` | Serial ban lama | FK→ban-unit |
| 17–18 | Nama File / Foto Ban (In) | | foto | A |
| 19 | Keterangan Kembali | str | `BAN BEKAS DARI NOPOL W 8462 QB` | Catatan | |
| 20–21 | Helper * | | formula | H |

### Sheet `Ban Luar BPN` — 40 baris · pendataan awal ban armada SIG-Balikpapan (site berbeda)
Kolom: `NO`, `TANGGAL CUT OFF`, `NOPOL`, `DESKRIPSI BAN`, `NO SERI`, `Foto` (DISPIMG), `KETERANGAN`.

### Sheet `Deliver & Receive Ban SIG-BPN` — 18 baris · transfer ban antar-site SDA↔BPN
Dua blok: kirim (`Tgl NPBG`,`NO NPBG`,`Deskripsi`,`No seri Ban Baru`,`Foto`,`Ket`) + terima
(`Tgl RI`,`NO RI`,`Deskripsi`,`No seri Ban Bekas`,`Foto`,`Ket`). Menyiratkan **entitas gudang/site**
(`SIG-SDA`, `SIG-BPN`).

---

## File 6 — `6. Tracking Maintenance Assets.xlsx`

### Sheet `Maintenance Kendaraan` — work order perbaikan · ~365 baris / **314 No. SPK** · header baris 4

| Kol | Nama | Tipe | Contoh | Fungsi | Key |
|-----|------|------|--------|--------|-----|
| 2 | Tgl Laporan | date | `2025-07-25` | Tgl lapor kerusakan | |
| 3 | No. SPK | str | `MK/25/VII/001` | Nomor Surat Perintah Kerja | **FK-group** |
| 4 | Sub SPK | str | `SUB-01..05` | Sub-pekerjaan | bagian PK majemuk |
| 5 | Nopol (Kendaraan) | str | `W 8747 PD (HINO DUTRO)`, `EKOR TRAILER (SIG-01)` (~39) | Aset | **FK→assets** |
| 6 | Keterangan Awal | str | `REKONDISI ULANG BAK BELAKANG...` | Deskripsi kerusakan | |
| 7–9 | Nama File / (#.ext) / Foto Sebelum | str | `MK.25.VII.001_SUB-01_Before` / `.jpg`/`.mp4` / `Open File` | Foto sebelum | A |
| 10 | Bengkel | str | `SIG`, `CDO`, `Bengkel Ambon`, `-` (13) | Tempat pengerjaan | FK→workshops, enum |
| 11 | Permintaan | str | `=DISPIMG(...)` | (foto surat permintaan) | A |
| 12 | Status Hasil Pengerjaan | str | `COMPLETED` / `ON-GOING` | Status | **enum** |
| 13 | No. NPBG | str | `NA/25/VIII/009` | NPBG spare part keluar untuk perbaikan | **FK→npbg** |
| 14–16 | Nama File 2 / (#.ext2) / Foto Sesudah | | Foto sesudah | A |
| 17 | Tgl Selesai Pengerjaan | date | `2025-07-29` | Tgl selesai | |
| 18 | Keterangan Akhir | str | `TAHAP AWAL REPARASI ... SUDAH SELESAI` | Hasil | |
| 19–21 | Helper * | | formula | H |

`[NEEDS CONFIRMATION]`: apakah No. RI juga direlasikan di modul ini (di spec disebut, tapi kolom RI
tak ada di sheet ini — hanya NPBG).

---

## File 7 — `7. Tracking Manufaktur & Assembly.xlsx`

### Sheet `Manufaktur & Assembly` — produksi internal cradle/manifold · ~471 baris / **113 No. MA** · header baris 4

| Kol | Nama | Tipe | Contoh | Fungsi | Key |
|-----|------|------|--------|--------|-----|
| 2 | Tanggal | date | `2025-07-26` | Tgl proses | |
| 3 | No. Manufaktur & Assembly | str | `MA/25/VII/001` | Nomor MA | **FK-group** |
| 4 | Sub MA | str | `SUB-01..03` | Sub-order | PK majemuk |
| 5 | Lokasi | str | `SIG` (1 nilai) | Lokasi produksi | |
| 6 | Hasil Produk | str | `CRADLE CARBON STEEL 3x2`, `MANIFOLD CRADLE 4x4` (10) | Produk jadi | FK→products, enum |
| 7 | No. Seri | str | `SIG-58` (~114) | **Serial produk hasil** | PK produk-unit |
| 8 | Item No | int | 1..470 | Nomor item | |
| 9 | Proses | str | `CRA(1) - PEMBUATAN RANGKA`, `CRA(2) - PEMASANGAN KOMPONEN`, `MAN(1) - PEMBUATAN MANIFOLD`, `ADD(1) - PENGECATAN CRADLE`, `CRM(1) - BUNDLE CRADLE & MANIFOLD` | Langkah routing | **enum** |
| 10 | Keterangan Awal | str | `UNTUK FABRIKASI / PERAKITAN CRADLE 3X2 NO. SIG-58 DAN SIG-59 ...` | Instruksi | |
| 11 | Status Hasil Pengerjaan | str | `COMPLETED` / `ON-GOING` | Status | **enum** |
| 12 | No. NPBG | str | `NA/25/VII/251` | NPBG material keluar | **FK→npbg** |
| 13–14 | Nama File / Foto Hasil | | foto | A |
| 15 | Tgl Selesai Pengerjaan | date | `2025-08-06` | Tgl selesai | |
| 16 | No. RI | str | `RI/NV/25/VIII/019` | RI hasil produk masuk stok | **FK→ri** |
| 17 | Keterangan Akhir | str | `CRADLE 3x2 SUDAH SELESAI DIKERJAKAN LENGKAP...` | Hasil | |
| 18–19 | Helper * | | formula | H |

### Sheet `Manufaktur & Jasa Lain-Lain` — jasa outsourced · ~290 baris / **115 No. MJ** · header baris 4

Struktur mirip, plus **`Lokasi` = vendor jasa** (`CV. NURUL JAYA TEHNIK`, `PT. INTERMEDIA COMPUTER
INDONESIA`, dll — 15 nilai). `Proses` = `JASA FABRIKASI/PEMOTONGAN/PERBAIKAN/VULKANISIR/DRAD`.
`Status` menambah `REQUESTED`. Kolom: `No. MJ`, `Sub MJ`, `Item No`, `Hasil Produk`, `No. Seri`,
`No. NPBG` (FK), `No. RI` (FK), foto, keterangan awal/akhir.

---

## File 8 — `8. Tracking Pengembalian Bekas.xlsx`

### Sheet `Spare Part` — pengembalian spare part bekas (format matriks) · 196 baris · header baris 5

| Kol | Nama | Tipe | Contoh | Fungsi | Key |
|-----|------|------|--------|--------|-----|
| 1 | No | int | 1.. | Urut | |
| 2 | Tgl NPBG | date | `2025-10-03`, `-` | Tgl keluar terkait | |
| 3 | No NPBG | str | `NA/25/X/038`, `-` | **NPBG terkait** | FK→npbg |
| 4 | Status | str | `CLEAR` / `PENDING` | Sudah dikembalikan? | **enum** |
| 5 | Tgl RI | date | `2025-10-06` | Tgl terima bekas | |
| 6 | No RI | str | `RI/NV/25/X/015` | **RI bekas masuk** | FK→ri |
| 7–20 | Bonit BR, Pen BR, Pen SS, Valve BR, Cyl Cap, Mur Br/CS/GI/SS, Per CS, Baut BR/CS/GI/SS | int (bisa **negatif**) | `3`, `-4`, `102` | Qty per jenis komponen (surplus +, shortage −) | 14 tipe komponen |
| 21 | Keterangan | str | `1. Pengembalian Valve O2 untuk ditukar dengan ...` | Narasi | |

Kolom 23–24 = tabel bantu total per komponen (formula). Format matriks ini **perlu di-unpivot** jadi
baris (npbg_ref, ri_ref, component_type, qty).

### Sheet `Spare Part Lain` — pengembalian barang bekas lain (format baris) · 388 baris · header baris 4

Kolom: `Tgl NPBG`, `No NPBG` (FK), `Deskripsi Barang` (`(BUANG) LAMPU LED...`, `(RUSAK) PRESSURE
GAUGE...`, `(BEKAS) ...` — prefix menandai kondisi), `Kuantitas`, `Satuan`, `Item No`, foto keluar,
`Status` (`CLEAR`/`PENDING`), `No RI` (FK), `Item No2`, foto terima, `Keterangan`.
Prefix deskripsi `(BUANG)`/`(RUSAK)`/`(BEKAS)` = **kondisi barang** → menentukan masuk stok atau tidak.

---

## Katalog nilai enum (untuk seed & validasi)

| Domain | Nilai |
|--------|-------|
| NPBG Tipe | `PENJUALAN`, `NON-PENJUALAN` |
| NPBG Klasifikasi | `UMUM`, `PROYEK`, `MAINTENANCE KENDARAAN`, `MAINTENANCE GEDUNG`, `MAINTENANCE MESIN`, `MAINTENANCE PERALATAN`, `MAINTENANCE PROYEK`, `MAINTENANCE TABUNG`, `MAINTENANCE UMUM`, `LEND / BORROW`, `STPP`, `MANUFAKTUR`, `JASA` |
| PPB Status | `Requested`, `Shortage`, `Amend`, `Close`, `Completed`, `Error` |
| PPB Perubahan Tipe | `AMEND`, `CLOSE` |
| Lend Status | `SEDANG DIPINJAM`, `KEMBALI`, `DEADLINE` |
| Borrow Status | `SEDANG DIPINJAM`, `LUNAS` |
| Lend Keperluan | `INTERNAL`, `PROJECT`, `RELASI` |
| STPP Status | `ACTIVE`, `PASSIVE` |
| Ban Luar Status | `CLEAR`, `PENDING RI` |
| Maintenance/Manufaktur Status | `COMPLETED`, `ON-GOING` (+ `REQUESTED` di MJ) |
| Pengembalian Status | `CLEAR`, `PENDING` |
| Divisi (gabungan) | `ADMIN TABUNG`, `AKUNTING`, `DIREKTUR`, `DISTRIBUSI`, `DRIVER`, `GENERAL SERVICE`, `GUDANG`, `INVENTORY`, `IT DEVELOPER`, `MAINTENANCE`, `MARKETING`, `MGR. OPERASIONAL`, `SALES COUNTER`, `SECURITY` |
| Kategori Induk | `Assets`, `Automotive`, `Big Spare Parts`, `Electronics & Electricals`, `Etalase`, `Household Needs`, `Maintenance & Industry`, `Manufacture & Assembly`, `Office Apparel & Accessories`, `Office Needs`, `Post-Use Items`, `Small Spare Parts` |
| UoM (gabungan, perlu dibersihkan) | `BK, BOX, BTG, BTL, CM, CM2, CMS, DUS, GLN, JRG, KG, KLG, LBR, LTR, M2, M3, MTR, PCK, PCS, PSG, RIM, ROL/ROLL, SCK, SET, TBG` |
| Gudang | `GUDANG 1..5`, `ETALASE`, `ETALASE 1` (+ varian trailing-space); site: `SIG-SDA`, `SIG-BPN` |
| Bengkel | `SIG`, `CDO`, `Bengkel Ambon`, `Bengkel Bonek Jaya Diesel`, `Bengkel Candra Buana`, `Bengkel Mobil Slamet`, `Bengkel Pak Sueb`/`PAK SUEB`, `Bengkel Per Kecicang Jaya`, `JAYA MAKMUR (PAK YUSUF)`, `KLINIK MOTOR`, `-` |
| Manufaktur Produk | `CRADLE CARBON STEEL 3x2/4x4`, `CRADLE MANIFOLD 3x2/4x4`, `CRADLE MANIFOLD C2H2 4x4`, `CRADLE VGL`, `MANIFOLD CRADLE 2x2/3x2/4x4`, `MANIFOLD CRADLE C2H2 4x4` |

---

## Peta relasi antar-dokumen (ringkas)

```
                         ┌─────────────┐
              ┌─────────► │   items     │ ◄──────── (semua "Deskripsi Barang" = natural key, TANPA Kode Barang)
              │           │ (DATA.xlsx) │
              │           └─────┬───────┘
              │                 │ 1:N
              │           ┌─────▼───────────┐   N:1   ┌───────────────────┐
              │           │ item_safety_stock│ ◄─────► │ SAFETY STOCK *    │ (join by description — ambigu)
              │           └─────────────────┘         └───────────────────┘
              │
  ┌───────────┴───┐  No PPB   ┌──────────┐  No PPB / No PO   ┌──────────┐
  │ PPB          │ ─────────► │ PPB      │ ◄─────────────────│ RI       │ ──► Vendor, Surat Jalan, Pemeriksa
  │ (header+item)│           │ Perubahan│                   │(header+  │
  └──────────────┘           └──────────┘                   │ item)    │
                                                            └────┬─────┘
                                        No PO (dokumen PO TIDAK ADA di dataset) ──┘  [NEEDS CONFIRMATION]

  ┌──────────────┐
  │ NPBG         │  ◄───────── dirujuk oleh SEMUA modul di bawah (kolom "No NPBG" untuk leg KELUAR)
  │ (header+item)│  ──────────► Pelanggan, Proyek, Asset (Nopol), Divisi, Peminta
  │ .Klasifikasi │  ── menentukan modul tujuan ──►
  └──────────────┘
        ▲  "No RI" untuk leg MASUK/kembali (RI)
        │
        ├── Lend / Borrow        (Tanda Keluar=NPBG, Tanda Kembali=RI)
        ├── STPP                 (No NPBG serah, Tanda Kembali=RI tarik) + master No Seri SN-xxxx
        ├── Ban Luar             (No NPBG ban baru, No RI ban lama) + Nopol + serial ban
        ├── Maintenance Assets   (No. SPK+Sub, Nopol, No NPBG spare part) [RI? belum ada]
        ├── Manufaktur&Assembly  (No. MA+Sub, No NPBG material, No RI produk jadi) + serial SIG-xx
        ├── Manufaktur & Jasa    (No. MJ+Sub, vendor jasa, No NPBG, No RI)
        └── Pengembalian Bekas   (No NPBG, No RI) — matriks komponen / baris item

  ┌──────────────┐
  │ assets       │  ◄── Nopol "W 8747 PD (HINO DUTRO)" dari Ban Luar / Maintenance / NPBG
  │ (kendaraan)  │      + site SIG-SDA / SIG-BPN
  └──────────────┘
```

Detail entitas, tabel, FK, isu data, dan daftar `[NEEDS CONFIRMATION]` → [step1-analysis.md](step1-analysis.md).
