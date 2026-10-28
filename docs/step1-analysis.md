# STOCKWISE — STEP 1 Analysis (Excel → Domain Model)

> Turunan dari [excel-data-mapping.md](excel-data-mapping.md). **Belum ada migration / kode.**
> Tujuan dokumen: 13 output yang diminta di brief, untuk di-review sebelum masuk STEP 2 (ERD final).

---

## 1. Excel Data Mapping

Lengkap di [excel-data-mapping.md](excel-data-mapping.md). Ringkasan:

- **9 workbook**, 24 sheet berisi data + 8 sheet `Dropdown List` (sumber validasi) + 3 sheet scratch di `DATA.xlsx`.
- Satu master barang (`DATA.xlsx / DATABASE UTAMA`, ~5.900 baris) + 12 sheet parameter safety stock.
- Dokumen inti: **PPB** (permintaan beli), **RI** (terima barang), **NPBG** (keluar gudang), + 7 modul tracking yang semuanya "menggantung" pada NPBG (leg keluar) dan RI (leg masuk/kembali).
- File transaksi **tidak pernah menyimpan Kode Barang** — hanya `Deskripsi Barang` teks bebas.

Volume (baris data nyata / dokumen unik):

| Objek | Baris item | Dokumen unik |
|-------|-----------:|-------------:|
| Master barang | ~5.900 | ~5.900 Kode Barang |
| NPBG | ~13.500 | ~5.053 |
| PPB | ~3.773 | 696 |
| RI | ~8.100 | 3.624 |
| PPB Perubahan | 198 | 90 |
| Lend / Borrow | ~360 / 27 | — |
| STPP | ~777 | 775 serial |
| Ban Luar (SDA / BPN / transfer) | 606 / 40 / 18 | — |
| Maintenance Assets | ~365 | 314 SPK |
| Manufaktur & Assembly / Jasa | ~471 / ~290 | 113 / 115 |
| Pengembalian Bekas (matriks / baris) | 196 / 388 | — |

---

## 2. Master Data Identification

| Master | Sumber Excel | Natural key di Excel | Catatan |
|--------|--------------|----------------------|---------|
| **Barang / Item** | `DATA.xlsx/DATABASE UTAMA` | `Kode Barang` (PK), `Deskripsi Barang` (dipakai file transaksi) | 1 sumber; transaksi join by deskripsi |
| **Kategori** (4 level) | kolom Kategori Induk/Anak 1-3 | path `Induk > Anak1 > Anak2 > Anak3` | 12 induk, ~77/149/99 anak; hierarki adjacency |
| **UoM / Satuan** | kolom `UoM` / `Satuan` + Dropdown | kode (`PCS`, `BK`, …) | set beda antar file → perlu kanonikalisasi |
| **Gudang / Warehouse** | `LETAK GUDANG` + Ban Luar BPN | `GUDANG 1..5`, `ETALASE`, `ETALASE 1` | + dimensi **site**: `SIG-SDA`, `SIG-BPN` |
| **Rak / Location** | `LETAK RAK` (`B.7.1`) | kode rak dalam gudang | hanya ~9% barang terisi |
| **Asset / Kendaraan** | Nopol `W 8747 PD (HINO DUTRO)` di Ban Luar, Maintenance, NPBG, STPP | plat + `(merk/tipe)` dalam kurung | ~48 unit; ada non-plat: `FORKLIFT 3 TON`, `EKOR TRAILER SIG-01` |
| **Alat STPP** (serialized) | `4. STPP / No Seri` `SN-0001` | `SN-xxxx` | ~775; sub-tipe dari barang |
| **Produk Manufaktur** (serialized) | `7. MA / No. Seri` `SIG-58` | `SIG-xx` | ~114; output produksi, bisa jadi item stok |
| **Ban (tyre unit)** (serialized) | Ban Luar `No Seri Baru/Lama` `2320611829 (M-0523)` | serial + `(M-xxxx)` = kode pabrikan/minggu | ratusan unit |
| **Vendor / Supplier** | RI `Vendor`, Borrow `Vendor`, MJ `Lokasi` | nama (`CV. AMARTA TEHNIK`) | ~368 nama, banyak typo/duplikat |
| **Customer / Pelanggan** | NPBG `Pelanggan` | nama PT | ~31 |
| **Proyek** | NPBG `Nama Proyek` | nama proyek | ~31 |
| **Bengkel / Workshop** | Maintenance `Bengkel` | nama | 13, termasuk internal `SIG`, `CDO` |
| **Orang** (Peminta/Pemeriksa/Petugas) | banyak kolom | nama depan (`WAHYU`, `PAK HANES`) | ~110 nama unik, **first-name only, tanpa ID** |
| **Divisi / Department** | `Divisi` / `Penempatan` | nama | ~14 |

`Dropdown List` tiap file = seed siap pakai untuk master di atas.

---

## 3. Transaction Identification

| Transaksi | Grain (1 baris = ?) | Header fields | Line fields | Status field |
|-----------|---------------------|---------------|-------------|--------------|
| **PPB** | 1 item pada 1 PPB | No PPB, Tgl, Peminta, Divisi | Deskripsi, Qty, Satuan, Keterangan, Status | `Status` per baris |
| **PPB Perubahan** | 1 perubahan item PPB | No PPB, Tgl Perubahan | Deskripsi, Qty, Satuan, Tipe Perubahan | `Tipe Perubahan` |
| **RI** | 1 item pada 1 RI | No RI, Tgl, Vendor, No PO, No PPB, Surat Jalan, Pemeriksa | Deskripsi, Qty, Satuan, Keterangan | — (implisit "diterima") |
| **NPBG** | 1 item pada 1 NPBG | No NPBG, Tgl, Tipe, Klasifikasi, Pelanggan, Proyek, Nopol, Peminta, Divisi | Deskripsi, Qty, Satuan, Keterangan | — (implisit "keluar") |
| **Lend** | 1 barang dipinjamkan | Tgl Pinjam, Peminta, Keperluan, Est hari | Deskripsi, Qty, Satuan, Tanda Keluar, Tanda Kembali, Tgl Kembali | `Status` |
| **Borrow** | 1 barang dipinjam | Tgl, Vendor | Deskripsi, Qty, Satuan, Tanda Terima, Tanda Keluar | `Status` |
| **STPP** | 1 alat ber-serial diserahkan | No Seri, No NPBG, Tgl NPBG, Peminta, Penempatan | Keterangan Keluar/Kembali, Tanda Kembali, Tgl RI | `Status` ACTIVE/PASSIVE |
| **Ban Luar** | 1 penggantian ban di 1 posisi | Nopol, Tgl NPBG, No NPBG, No RI | Ban baru/lama (deskripsi+serial), Pergantian ke-n | `Status` CLEAR/PENDING RI |
| **Maintenance Assets** | 1 sub-pekerjaan (SPK+Sub) | No SPK, Sub SPK, Nopol, Tgl Laporan, Bengkel, No NPBG | Keterangan awal/akhir, foto, Tgl Selesai | `Status Hasil Pengerjaan` |
| **Manufaktur & Assembly** | 1 langkah proses pada 1 sub-order | No MA, Sub MA, No Seri produk, Hasil Produk, No NPBG, No RI | Proses, Keterangan, Tgl Selesai | `Status Hasil Pengerjaan` |
| **Manufaktur & Jasa** | 1 langkah jasa pada 1 sub-order | No MJ, Sub MJ, vendor (Lokasi), No NPBG, No RI | Proses, Keterangan | `Status` (+ REQUESTED) |
| **Pengembalian Bekas** | 1 event pengembalian (matriks / item) | No NPBG, No RI, Tgl | qty per komponen (bisa negatif) / item+kondisi | `Status` CLEAR/PENDING |

Semua transaksi `header+line` di Excel disimpan **flat** (header value diulang atau dikosongkan pakai
merge cell). Migrasi = pecah jadi tabel header + tabel item.

---

## 4. Document Relationship

Relasi yang **terbukti ada di data** (via nilai kolom yang cocok):

| Dari | Ke | Lewat kolom | Kardinalitas | Bukti |
|------|----|-------------|--------------|-------|
| RI | PPB | `RI.No PPB` → `PPB.No PPB` | N:1 (bisa null) | ~43% RI punya No PPB valid berformat `PPB/...` |
| RI | PO | `RI.No PO` → (PO) | N:1 | nilai `BL/F/25/II/74`; **PO tidak ada tabelnya** |
| PPB Perubahan | PPB | `No PPB` | N:1 | prefix `PPB/` sama |
| PPB | RI | agregat `cntRI`,`sumRI` | 1:N | kolom formula di PPB |
| STPP (keluar) | NPBG | `STPP.No. NPBG` → `NPBG.No NPBG` | N:1 | format `NA/25/VIII/138` identik |
| STPP (kembali) | RI | `STPP.Tanda Kembali` → `RI.No RI` | N:1 (null) | format `RI/NV/...` identik |
| Lend | NPBG / RI | `Tanda Keluar` / `Tanda Kembali` | N:1 (loose, kadang nilai non-standar) | sebagian cocok |
| Ban Luar | NPBG / RI | `No NPBG` / `No. RI` | N:1 | format identik (kecuali sentinel `ORIGIN`) |
| Ban Luar | Asset | `Nopol` | N:1 | list Nopol identik dengan Maintenance/Dropdown |
| Ban Luar (lama↔baru) | Ban unit | `No Seri Lama` / `No Seri Baru` | swap 1:1 | serial dilacak antar baris |
| Maintenance Assets | NPBG | `No. NPBG` | N:1 | format identik |
| Maintenance Assets | Asset | `Nopol` | N:1 | identik |
| Manufaktur A&A / Jasa | NPBG (material) | `No. NPBG` | N:1 | identik |
| Manufaktur A&A / Jasa | RI (produk jadi masuk) | `No. RI` | N:1 | identik |
| Pengembalian Bekas | NPBG / RI | `No NPBG` / `No RI` | N:1 | identik |
| NPBG | Customer / Proyek / Asset | `Pelanggan` / `Nama Proyek` / `No Seri / Nopol` | N:1 (null) | list cocok dengan Dropdown |
| NPBG.Klasifikasi | modul tujuan | nilai enum | — | `STPP`, `LEND / BORROW`, `MANUFAKTUR`, `MAINTENANCE KENDARAAN` = nama modul |
| Semua transaksi | Item | `Deskripsi Barang` ~ `DATABASE UTAMA.Deskripsi Barang` | N:1 | **hanya ~55–58% auto-match** (temuan STOCKWISE lama) |

**Model mental yang muncul:** `NPBG` adalah dokumen keluar-gudang **generik**; `Klasifikasi`-nya
menentukan dokumen tracking mana yang jadi "map" detail dari NPBG itu. Leg pengembalian selalu lewat
`RI`. Jadi NPBG & RI = tabel pergerakan stok pusat; modul tracking = tabel domain yang menempel padanya.

---

## 5. Business Process Existing (rekonstruksi dari data)

### 5a. Pengadaan (PPB → PO → RI)
1. Divisi butuh barang → **PPB** dibuat (`Status = Requested`).
2. Cek stok gudang. Jika kurang → `Status = Shortage`, lanjut beli.
3. Purchasing terbitkan **PO** ke vendor (nomor `BL/...`). *(dokumen PO tak tercatat di Excel ini)*
4. Barang datang → **RI** dibuat per kedatangan (`No PO`, `No PPB`, `Vendor`, `Surat Jalan`,
   `Pemeriksa`). Bisa **partial** → 1 PPB banyak RI (`cntRI` s/d 6).
5. PPB bisa di-**Amend** / **Close** (sheet PPB Perubahan) bila qty berubah / batal.
6. PPB `Completed` saat kebutuhan terpenuhi.

### 5b. Pengeluaran (NPBG)
1. Peminta/divisi ambil barang → **NPBG** (`Tipe`: PENJUALAN / NON-PENJUALAN).
2. `Klasifikasi` di-set: UMUM (pakai habis), PROYEK (+Pelanggan+Proyek), MAINTENANCE KENDARAAN (+Nopol),
   STPP, LEND/BORROW, MANUFAKTUR, dst.
3. Kalau bukan UMUM → dibuat baris di modul tracking terkait, mengutip `No NPBG`.

### 5c. Modul tracking (leg keluar via NPBG, leg balik via RI)
- **STPP**: alat ber-serial diserahkan ke divisi (`ACTIVE`); saat ditarik/rusak → `RI` + `PASSIVE`.
- **Lend**: barang dipinjamkan ke relasi/proyek; `SEDANG DIPINJAM` → `KEMBALI` (via RI) / `DEADLINE` (telat).
- **Borrow**: SIG pinjam dari luar; `SEDANG DIPINJAM` → `LUNAS` (dikembalikan, via NPBG keluar).
- **Ban Luar**: NPBG keluarkan ban baru → dipasang di Nopol; ban lama masuk lewat RI; `PENDING RI` → `CLEAR`.
- **Maintenance Assets**: laporan kerusakan → SPK (+ Sub SPK per pekerjaan) → spare part keluar via NPBG
  → dikerjakan di Bengkel (internal/eksternal) → `ON-GOING` → `COMPLETED`.
- **Manufaktur & Assembly**: order MA (+Sub) → material keluar via NPBG → proses bertahap
  (`CRA(1)` rangka → `CRA(2)` komponen → `MAN(1)` manifold → `ADD(1)` cat → `CRM(1)` bundle) →
  produk jadi ber-serial `SIG-xx` masuk stok via RI.
- **Manufaktur & Jasa**: sama tapi dikerjakan vendor luar (`Lokasi` = vendor), proses = jenis jasa.
- **Pengembalian Bekas**: sisa/bekas/rusak dari pemakaian dikembalikan; dicocokkan ke NPBG asal;
  masuk via RI; kondisi (`(BUANG)`/`(RUSAK)`/`(BEKAS)`) menentukan masuk stok atau dibuang.

### 5d. Safety stock (STOCKWISE lama — harus dipertahankan)
Rata-rata pengeluaran per bulan (dari NPBG) × faktor √LT → `SS` & `MIN PR` per barang per kategori.
Engine STOCKWISE menghitung Selisih / Status / Defisit / Priority (brief §C).

---

## 6. Proposed STOCKWISE Entities

Dikelompokkan. Nama final menyusul di STEP 2.

**Identity & RBAC** — `users`, `roles`, `permissions`, `role_user`, `permission_role`
**Org / master orang** — `departments`, `employees` (opsional, kalau Peminta perlu jadi entitas), `vendors`, `customers`, `projects`, `workshops`
**Katalog barang** — `categories` (self-ref 4 level), `units`, `items`, `item_aliases` (untuk deskripsi-varian → matching), `item_safety_stocks` (per periode/kategori)
**Lokasi** — `sites` (SIG-SDA, SIG-BPN), `warehouses`, `warehouse_locations` (rak)
**Inventory inti** — `inventory` (per item×warehouse: actual, reserved, available), `stock_movements`, `stock_reservations`
**Aset ber-serial** — `assets` (kendaraan/forklift/trailer), `serialized_units` (opsional generik) atau tabel khusus: `stpp_tools`, `tyres`, `manufactured_units`
**Permintaan & pengeluaran** — `material_requests` + `material_request_items` (modul Request baru), `npbg` + `npbg_items`, `goods_issues` (kalau NPBG dipisah dari dokumen)
**Pengadaan** — `ppb` + `ppb_items`, `ppb_amendments`, `purchase_orders` + `po_items`, `rfq` + `rfq_items` (baru), `receivings` (RI) + `receiving_items`
**Stock opname** — `stock_opnames` + `stock_opname_items`, `stock_adjustments`
**Modul tracking** — `borrow_transactions`, `lend_transactions`, `stpp_transactions`, `tyre_changes`, `maintenance_orders` + `maintenance_order_subs`, `manufacturing_orders` + `manufacturing_order_subs` (+ tipe internal/jasa), `used_returns` + `used_return_items`
**Lampiran & sistem** — `attachments` (foto/PDF polymorphic), `notifications`, `audit_logs`, `settings`

Perkiraan ~45–55 tabel. Kandidat di brief §Y dipakai hampir semua; tambahan yang muncul dari Excel:
`sites`, `item_aliases`, `ppb_amendments`, `workshops`, `projects`, `customers`, `tyres`/`tyre_changes`,
`maintenance_order_subs`, `manufacturing_order_subs`, `attachments`.

---

## 7. ERD Draft

```
users ──< role_user >── roles ──< permission_role >── permissions
users ──1:N── (created_by di hampir semua tabel transaksi)

sites ──1:N── warehouses ──1:N── warehouse_locations
categories ──self 1:N── categories        units ──1:N── items
items ──1:N── item_aliases
items ──1:N── item_safety_stocks (period, source_category)
items ──1:N── inventory ──N:1── warehouses
inventory ──1:N── stock_movements   (movement_type, qty, stock_before, stock_after, ref_type, ref_id)
inventory ──1:N── stock_reservations ──N:1── material_requests

departments ──1:N── material_requests ──1:N── material_request_items ──N:1── items
material_requests ──1:N── stock_reservations
material_requests ──1:1/N── npbg           (request yang siap diambil → NPBG)

npbg ──1:N── npbg_items ──N:1── items
npbg ──N:1── customers / projects / assets / departments / users(peminta)
npbg.klasifikasi ──> {lend,borrow,stpp,tyre_change,maintenance_order,manufacturing_order,used_return}

ppb ──1:N── ppb_items ──N:1── items
ppb ──1:N── ppb_amendments
ppb ──1:N── purchase_orders ──1:N── po_items      (via PPB→PO, saat ini nomor BL/…)
purchase_orders ──N:1── vendors
purchase_orders ──1:N── receivings ──1:N── receiving_items ──N:1── items
receivings ──N:1── ppb (nullable), vendors, users(pemeriksa)
receivings ──1:N── stock_movements (STOCK_IN)

stock_opnames ──1:N── stock_opname_items ──N:1── items, warehouses
stock_opname_items ──0:1── stock_adjustments ──1:1── stock_movements (STOCK_ADJUSTMENT)

assets ──1:N── tyre_changes ──N:1── npbg (ban baru), receivings (ban lama)
assets ──1:N── maintenance_orders ──1:N── maintenance_order_subs ──N:1── npbg
manufacturing_orders ──1:N── manufacturing_order_subs ──N:1── npbg, receivings
manufacturing_orders ──1:N── manufactured_units (serial SIG-xx) ──> items (as stock)
stpp_transactions ──N:1── npbg (serah), receivings (tarik), items, departments
lend_transactions / borrow_transactions ──N:1── npbg, receivings, items, (vendors|customers)
used_returns ──1:N── used_return_items ──N:1── npbg, receivings, items

attachments (attachable_type, attachable_id) ──> foto/pdf semua modul
audit_logs (user, action, model_type, model_id, before json, after json, ip, at)
notifications (user, type, data, read_at)
```

---

## 8. Potential Database Tables (dengan kolom kunci)

| Tabel | Kolom utama (selain id/timestamps) | Asal Excel |
|-------|-----------------------------------|-----------|
| `items` | kode_barang (uniq), description (uniq-ish), category_id, unit_id, needs_blueprint, default_warehouse_id, default_location, lead_time_days | DATABASE UTAMA |
| `item_aliases` | item_id, alias_description, source | dari deskripsi transaksi yg tak persis sama |
| `item_safety_stocks` | item_id, source_category, period_label, avg_usage_1m/3m/6m/12m, lead_time, sqrt_lt, safety_stock, min_pr, effective_date | SAFETY STOCK * |
| `categories` | name, parent_id, level, path | Kategori Induk/Anak 1-3 |
| `units` | code (uniq), name, is_active | UoM/Satuan |
| `sites` | code (SIG-SDA/SIG-BPN), name | Ban Luar BPN + transfer sheet |
| `warehouses` | site_id, code, name | LETAK GUDANG |
| `warehouse_locations` | warehouse_id, code (rak) | LETAK RAK |
| `inventory` | item_id, warehouse_id, actual_qty, reserved_qty, (available = actual-reserved) | **BARU** — belum ada di Excel |
| `stock_movements` | item_id, warehouse_id, type (enum), qty, stock_before, stock_after, reference_type, reference_id, note, created_by | **BARU** |
| `stock_reservations` | item_id, warehouse_id, request_id, qty, status | **BARU** |
| `vendors` | name (uniq, perlu dedupe), aliases | RI.Vendor |
| `customers` | name | NPBG.Pelanggan |
| `projects` | name, customer_id | NPBG.Nama Proyek |
| `workshops` | name, is_internal | Maintenance.Bengkel |
| `departments` | name (uniq) | Divisi |
| `assets` | code/nopol (uniq), name, type (kendaraan/forklift/trailer), site_id | Nopol |
| `ppb` | no_ppb (uniq per pfx/yy/mm/seq), prefix, date, requester, department_id, status | PPB |
| `ppb_items` | ppb_id, item_id, description_raw, qty, unit_id, note, status | PPB |
| `ppb_amendments` | ppb_id, date, type (AMEND/CLOSE), item ref, qty, note | PPB Perubahan |
| `purchase_orders` | no_po, prefix, date, vendor_id, ppb_id, status | RI.No PO (rekonstruksi) |
| `receivings` | no_ri, prefix, date, po_id, ppb_id, vendor_id, surat_jalan, checked_by, note | RI |
| `receiving_items` | receiving_id, item_id, description_raw, qty, unit_id, note | RI |
| `npbg` | no_npbg (uniq per pfx/yy/mm/seq), prefix, date, type, classification, customer_id, project_id, asset_id, requester, department_id, issued_by, status | NPBG |
| `npbg_items` | npbg_id, item_id, description_raw, qty, unit_id, item_no, note | NPBG |
| `material_requests` + items | requester_id, department_id, purpose, work_location, status (12 status brief §G) | **BARU** |
| `stock_opnames` + items | schedule, warehouse_id, counted_by, system_qty, physical_qty, difference, note, status | **BARU** |
| `stock_adjustments` | stock_opname_item_id, before, after, difference, reason, approved_by | **BARU** |
| `stpp_transactions` | serial (SN-xxxx), item_id, qty, unit_id, holder, placement, npbg_id, issue_date, return_ri_id, return_date, status | STPP |
| `lend_transactions` | item_id, qty, unit_id, borrower_pic, purpose, est_days, out_npbg_id, return_ri_id, return_date, status, condition_note | Lend |
| `borrow_transactions` | item_id, qty, unit_id, lender_vendor_id, note, receipt_ref, status, return_npbg_id | Borrow |
| `tyre_changes` | asset_id, change_date, out_npbg_id, new_tyre_desc, new_serial, position, change_seq, status, in_ri_id, old_tyre_desc, old_serial, note | Ban Luar |
| `maintenance_orders` + `_subs` | no_spk, report_date, asset_id / sub: sub_no, workshop_id, problem, npbg_id, status, finish_date, result | Maintenance Assets |
| `manufacturing_orders` + `_subs` | no_mo, kind (assembly/jasa), date, product, serial / sub: sub_no, process, npbg_id, ri_id, status, finish_date, vendor_id | Manufaktur A&A / Jasa |
| `used_returns` + `_items` | npbg_id, ri_id, date, status / item: component_type or item_id, qty (signed), condition, note | Pengembalian Bekas |
| `attachments` | attachable_type, attachable_id, kind (foto_before/after/bukti/…), path/filename, external_ref | kolom foto/DISPIMG |
| `audit_logs`, `notifications`, `settings` | (brief §Z, §AA) | **BARU** |

---

## 9. Potential Foreign Keys

```
categories.parent_id            → categories.id
items.category_id               → categories.id
items.unit_id                   → units.id
items.default_warehouse_id      → warehouses.id
item_aliases.item_id            → items.id
item_safety_stocks.item_id      → items.id
warehouses.site_id              → sites.id
warehouse_locations.warehouse_id→ warehouses.id
inventory.item_id / warehouse_id→ items.id / warehouses.id
stock_movements.item_id / warehouse_id / created_by → items / warehouses / users
stock_movements.(reference_type,reference_id)  → polymorphic (npbg_item, receiving_item, stpp, …)
stock_reservations.item_id / warehouse_id / request_id → items / warehouses / material_requests
material_request_items.request_id / item_id → material_requests / items
npbg.customer_id / project_id / asset_id / department_id / requester_id / issued_by → masing-masing
npbg_items.npbg_id / item_id    → npbg / items
projects.customer_id            → customers.id
ppb.department_id / requester_id → departments / users
ppb_items.ppb_id / item_id      → ppb / items
ppb_amendments.ppb_id           → ppb.id
purchase_orders.vendor_id / ppb_id → vendors / ppb
po_items.po_id / item_id        → purchase_orders / items
receivings.po_id / ppb_id / vendor_id / checked_by → purchase_orders / ppb / vendors / users
receiving_items.receiving_id / item_id → receivings / items
stock_opname_items.opname_id / item_id / warehouse_id → stock_opnames / items / warehouses
stock_adjustments.opname_item_id / approved_by → stock_opname_items / users
stpp_transactions.item_id / npbg_id / return_ri_id / holder_id / placement_dept_id → …
lend_transactions.item_id / out_npbg_id / return_ri_id → items / npbg / receivings
borrow_transactions.item_id / lender_vendor_id / return_npbg_id → items / vendors / npbg
tyre_changes.asset_id / out_npbg_id / in_ri_id → assets / npbg / receivings
maintenance_orders.asset_id     → assets.id
maintenance_order_subs.order_id / workshop_id / npbg_id → maintenance_orders / workshops / npbg
manufacturing_order_subs.order_id / npbg_id / ri_id / vendor_id → manufacturing_orders / npbg / receivings / vendors
manufactured_units.order_id / item_id → manufacturing_orders / items
used_returns.npbg_id / ri_id    → npbg / receivings
used_return_items.return_id / item_id → used_returns / items
attachments.(attachable_type, attachable_id) → polymorphic
audit_logs.user_id              → users.id
notifications.user_id           → users.id
```

Relasi berbasis nomor dokumen (`No NPBG`, `No RI`, `No PPB`, `No PO`) di Excel adalah **string match** —
saat migrasi diubah jadi FK id setelah dokumen induk dibuat. Sisa yang tak ketemu → simpan string mentah
di kolom `*_ref_raw` + tandai `unmatched` (jangan dibuang).

---

## 10. Potential Duplicate Data

| Duplikasi di Excel | Normalisasi |
|--------------------|-------------|
| `Deskripsi Barang`, `Kuantitas`, `Satuan` diulang di **setiap** sheet transaksi | FK `item_id` + simpan `description_raw` untuk audit |
| `Tgl NPBG` + `No NPBG` diulang di STPP/Ban Luar/Maintenance/Manufaktur/Pengembalian | FK `npbg_id` saja (tanggal ikut dari npbg) |
| `Nopol` string diulang di Ban Luar, Maintenance, NPBG | FK `asset_id` |
| `Peminta`/`Divisi`/`Pemeriksa`/`Vendor`/`Pelanggan` sebagai teks di banyak tempat | FK ke master masing-masing |
| Nama file foto disimpan 2–3× (`Nama File`, `Helper Nama File`, `HC Nama File`) | 1 baris `attachments` |
| `SAFETY STOCK` di `DATABASE UTAMA` (kosong) vs 12 sheet kategori (terisi) | 1 tabel `item_safety_stocks` |
| Deskripsi barang muncul di >1 sheet SAFETY STOCK dengan SS beda | resolusi konflik → 1 nilai efektif + log |
| `LETAK RAK` vs `BLUEPRINT 3D VIEW` (dua-duanya isi kode rak) | cek: kemungkinan 1 kolom salah tempat |
| Kolom bulan `Agt..Juli` (12 kolom) di sheet SS | unpivot jadi baris `(item, month, qty)` |
| Kolom komponen di `Spare Part` (14 kolom `Bonit BR`…`Baut SS`) | unpivot jadi `used_return_items` |
| `cntRI`, `sumRI`, `cntAmend`, `cntClose`, `Helper*`, `HC*`, `Concat`, `Column1` | **buang** — hasil formula, dihitung ulang di app |
| `ETALASE` vs `ETALASE 1 `, `GUDANG 1` vs `GUDANG 1 ` | trim + dedupe jadi 1 warehouse |
| Prefix NPBG/PPB/RI (`NA`,`ATK`,`NV`,`F`) di dalam nomor | simpan sebagai kolom `prefix` terpisah + `sequence` |

---

## 11. Data Issues

| # | Isu | Dampak | Rencana |
|---|-----|--------|---------|
| D1 | Transaksi tak punya `Kode Barang`, hanya deskripsi teks; auto-match ~55–58% | FK item tak lengkap | tabel `item_aliases` + halaman "Cocokkan Barang" (manual match), simpan `description_raw` |
| D2 | `SISA STOK` = teks `"STOK 0 PCS"`, ~63% kosong | tak ada stok awal numerik | parse regex; kosong = `UNKNOWN` (bukan 0); butuh stock opname awal |
| D3 | Tak ada konsep Actual vs Reserved di mana pun | engine reservation harus dibangun dari nol | `inventory` + `stock_movements` baru; migrasi = 1 movement `OPENING_BALANCE` per item |
| D4 | 12 sheet SAFETY STOCK, join by deskripsi, nilai SS bisa konflik | SS/priority ambigu | pilih aturan resolusi (lihat NC-7); simpan semua baris + `is_effective` |
| D5 | `LEAD TIME` campur angka & tanggal (`2026-07-04`) | parsing error | validasi: hanya integer hari; anomali → null + flag |
| D6 | `#REF!` di beberapa kolom (Borrow Status, PPB cntAmend/Close, MJ helper) | — (kolom helper, dibuang) | abaikan |
| D7 | Nomor dokumen tidak unik global (hanya per prefix/tahun/bulan) | PK naif salah | unique composite `(doc_type, prefix, year, month, sequence)` atau simpan full string sebagai natural key |
| D8 | Sentinel `ORIGIN` sebagai No NPBG/No RI di Ban Luar | FK gagal | perlakukan sebagai "opening record", `npbg_id = null`, `is_opening = true` |
| D9 | Qty non-integer / negatif / `-` (`9.2`, `1.5`, `4.5`, `-4`) | tipe kolom | `decimal(12,2)`; negatif hanya sah di Pengembalian Bekas (surplus/shortage) |
| D10 | Vendor ~368 nama, banyak duplikat/typo (`ATK MURAH`, `ATK Murah`) | master kotor | dedupe semi-manual saat seeding; `vendor_aliases` |
| D11 | Nama orang hanya first-name, tanpa ID, ambigu (`AGUS` bisa >1 orang) | FK requester lemah | seed `users`/`employees` dari Dropdown; tandai `needs_review` |
| D12 | Foto = `=DISPIMG(...)` / `"Open File"` embedded di xlsx | tak bisa diekstrak sebagai data | butuh file foto asli terpisah (NC-3); sementara simpan `external_ref` string |
| D13 | Sheet `Manufaktur & Assembly` deklarasi 16.383 kolom (korup) | parser lambat/bingung | batasi baca ke kolom ≤ 20 |
| D14 | 4.000–13.000 baris kosong "hantu" per sheet | over-count | filter baris yang semua kolom inti kosong |
| D15 | `Nama Alias` selalu `"Tidak"`; `BLUEPRINT 3D VIEW` isi kode rak | kolom salah pakai | jangan migrasi apa adanya; NC-8 |
| D16 | Header row tak konsisten (baris 3/4/5), ada merge cell | parsing | mapping header manual per sheet (sudah didokumentasikan) |
| D17 | Dua site (SIG-SDA, SIG-BPN) tercampur; kebanyakan sheet implisit SDA | scope stok | tambah dimensi `site_id`; default `SIG-SDA` + flag `[NEEDS CONFIRMATION]` |
| D18 | `NPBG.Klasifikasi` 89.787 baris lama `(Blanks)` | data historis tak terklasifikasi | migrasi hanya data in-scope (cut-off); sisanya `classification = UNCLASSIFIED` |
| D19 | RI tanpa No PPB & tanpa No PO (barang kembali/barter) | RI ≠ selalu hasil pengadaan | `receivings.source_type` enum (purchase / return / transfer / manufacturing / opening) |

---

## 12. NEEDS CONFIRMATION

| ID | Pertanyaan | Kenapa penting |
|----|-----------|----------------|
| NC-1 | Dokumen **PO (Purchase Order)** — apakah ada file/sistem terpisah? Format `BL/F/25/II/74` artinya apa (BL = ?, F = ?) | Menentukan apakah `purchase_orders` diisi dari sumber lain atau direkonstruksi dari RI |
| NC-2 | Arti **prefix** nomor dokumen: `NA`, `ATK`, `NV`, `F` | Untuk generator nomor dokumen baru + grouping |
| NC-3 | Apakah **file foto asli** (yang di-`DISPIMG`) tersedia terpisah? Dalam format/penamaan apa? | Modul attachment; kalau tidak, foto lama hilang |
| NC-4 | **Stok awal (opening balance)** per barang per gudang — dari mana? (`SISA STOK` 63% kosong) Apakah akan ada stock opname awal saat go-live? | Tanpa ini `inventory.actual_qty` tak bisa diisi |
| NC-5 | **Site / cabang**: apakah STOCKWISE ini untuk SIG-SDA saja, atau SDA + BPN? Stok BPN dikelola di sini? | Menentukan `sites`/`warehouses` dan scope |
| NC-6 | **Kategori**: level Anak 2 & Anak 3 sering kosong — hierarki wajib 4 level atau variabel? Ada master kategori resmi? | Desain tabel `categories` |
| NC-7 | **Safety Stock**: kalau 1 deskripsi barang punya SS beda di 2 sheet kategori, mana yang menang? Formula SS resmi = `rata2 pengeluaran × ? + √LT × ?` (brief tak beri rumus SS, hanya Priority) | Engine STOCKWISE |
| NC-8 | Kolom `Nama Alias` (isi "Tidak") & `BLUEPRINT 3D VIEW` (isi kode rak) — apa maksud aslinya? | Jangan migrasi kolom yang salah pakai |
| NC-9 | **Transisi status resmi** tiap dokumen (PPB, NPBG, tracking) — apakah 12 status Request di brief §G menggantikan status Excel, atau keduanya perlu dipetakan? | `status-flow.md`, state machine |
| NC-10 | **NPBG vs Request baru**: di sistem baru, apakah NPBG = output dari Request yang di-approve (jadi 1 request → 1 NPBG), atau NPBG tetap bisa dibuat manual tanpa request? | Alur inti modul Karyawan/Admin Gudang |
| NC-11 | **RI (Receiving)** di sistem baru: apakah tetap satu entitas untuk SEMUA barang masuk (pembelian, retur pinjam, hasil manufaktur, ban bekas), atau dipisah? Excel memakai 1 format RI untuk semua | Desain `receivings.source_type` |
| NC-12 | **Maintenance Assets**: apakah ada relasi ke RI/spare part masuk (brief §T sebut SPK/Sub SPK/NPBG/RI, tapi sheet hanya punya NPBG)? | FK modul maintenance |
| NC-13 | **STPP "Kuantitas"** kadang `4.5`, `1.4`, `180` untuk alat ber-serial — apakah 1 baris STPP bisa >1 unit / satuan panjang? | Grain tabel `stpp_transactions` |
| NC-14 | **Pengembalian Bekas sheet `Spare Part`**: 14 kolom komponen (`Bonit BR`, `Pen SS`, …) — apakah ini daftar tetap "consumable fitting tabung", atau harus dinamis? Nilai negatif = shortage barter, konfirmasi maknanya | Desain `used_return_items` |
| NC-15 | **Ban / Tyre**: apakah setiap ban di-track sebagai unit ber-serial seumur hidup (vulkanisir, pindah kendaraan), atau cukup event penggantian? | `tyres` entity vs `tyre_changes` saja |
| NC-16 | **Manufactured units (`SIG-58`)**: setelah jadi, apakah masuk `items`/`inventory` sebagai barang jual, atau jadi `assets`? | Relasi output produksi |
| NC-17 | **Cut-off**: tiap file punya tanggal cut-off beda (NPBG 15/04/2026, STPP 15/08/2025, Lend 09/08/2025, …). Data sebelum cut-off dimigrasi atau tidak? | Scope migrasi data historis |
| NC-18 | **Divisi vs Department vs Penempatan** — satu master atau beda konsep? (`Penempatan` STPP punya nilai seperti `MAINTENANCE KENDARAAN`, `GUDANG (PROJECT)`) | Master `departments` |
| NC-19 | **Users/roles**: pemetaan nama orang Excel (`WAHYU` = Admin Gudang? `AGUS`/`VALEN`/`ROSUL` = Pemeriksa/Purchasing?) ke 7 role di brief §F | Seeder & RBAC |
| NC-20 | **Lend "Tanda Keluar"** kadang bukan NPBG (`2025/SIG CV/1959-1960`, nomor nota penjualan) — dokumen apa itu? | FK modul Lend |
| NC-21 | Sheet `Sheet6`, `cetak`, `Sheet2` di `DATA.xlsx` — ada data penting? | Kelengkapan master |
| NC-22 | Apakah **UoM** perlu konversi (mis. `LTR`↔`GLN`, `MTR`↔`ROLL`) atau cukup 1 satuan per item? | Desain `units` / konversi |

---

## 13. Rekomendasi Struktur Database

1. **Item master tunggal** (`items`) dengan `kode_barang` sebagai PK bisnis. Semua transaksi FK ke
   `item_id`, **selalu** simpan `description_raw` (teks asli Excel) untuk jejak audit & matching ulang.
   Tabel `item_aliases` menampung deskripsi-varian → 1 item.

2. **Pisahkan pergerakan stok dari dokumen.** `stock_movements` = ledger tunggal (append-only,
   transactional) dengan `reference_type/reference_id` polymorphic. `inventory` = state turunan
   (`actual_qty`, `reserved_qty`; `available` = generated column). Tidak ada modul yang menyentuh
   `inventory` langsung — semua lewat movement + reservation. Ini yang mewujudkan aturan brief §D/§P
   (request → reserve, pickup → stock out, receiving confirmed → stock in).

3. **NPBG & RI jadi tabel pergerakan pusat**, bertipe `header + items`. Setiap modul tracking
   (`lend`, `borrow`, `stpp`, `tyre_changes`, `maintenance_*`, `manufacturing_*`, `used_returns`)
   menempel via FK ke `npbg` (leg keluar) dan `receivings` (leg masuk), **bukan** menyalin data barang.
   `npbg.classification` (enum) = diskriminator modul.

4. **Dokumen nomor**: kolom terpisah `doc_type`, `prefix`, `year`, `month`, `sequence`, + `number`
   (string penuh untuk tampilan). Unique `(doc_type, prefix, year, month, sequence)`. Generator nomor
   di backend (bukan DB default).

5. **Dimensi `site`** (`SIG-SDA` default, `SIG-BPN`) di atas `warehouses` — walau awalnya 1 site,
   struktur sudah muncul di data Ban Luar. `inventory` per `(item, warehouse)`.

6. **Safety stock** sebagai tabel time-series `item_safety_stocks` (bukan kolom di `items`), dengan
   `source_category`, `period`, `avg_usage_*`, `lead_time`, `safety_stock`, `min_pr`, `is_effective`.
   Halaman review konflik seperti STOCKWISE lama.

7. **Kategori** self-referencing (`parent_id`, `level`, `path` ter-denormalisasi untuk filter cepat).
   Level 2–4 nullable.

8. **Attachment polymorphic** (`attachable_type`, `attachable_id`, `kind`) untuk semua foto/PDF.
   Simpan `external_ref` (nama file Excel) selama file asli belum tersedia (NC-3).

9. **Kolom mentah untuk relasi tak-terpetakan**: `*_ref_raw` + boolean `is_matched`. Data yang belum
   ketemu FK-nya **tidak dibuang** — masuk antrian pencocokan manual (prinsip brief: jangan hilangkan
   informasi Excel).

10. **Enum di DB** sebagai string + di-`CHECK`/validasi Laravel, dengan tabel referensi untuk yang
    perlu dikelola user (status flow, kategori, unit). Status flow diberlakukan di service layer
    (state machine), bukan cuma kolom bebas.

11. **Audit & RBAC**: `audit_logs` (before/after JSON) di semua aksi tulis penting; permission dicek
    di Policy/Gate backend. Nama orang Excel → seed `users` + `employees`, semua `needs_review = true`
    sampai dikonfirmasi (NC-19).

12. **Migrasi Excel** dijalankan sebagai seeder/artisan command idempoten, per file, dengan laporan:
    baris masuk, baris di-skip (+alasan), FK tak ketemu. Bukan `INSERT` manual.

---

## Status & langkah berikutnya

**STOP di sini — menunggu approval.** Setelah brief §BB, STEP 2 baru boleh jalan:
ERD final + skema tabel + matriks role/permission + status-flow + arsitektur API, berdasarkan jawaban
atas §12 (NEEDS CONFIRMATION) di atas.

Belum dibuat: migration, model, kode aplikasi apa pun.
