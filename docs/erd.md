# STOCKWISE — ERD & Database Schema (draft STEP 2)

> MySQL 8. Laravel migrations (belum dibuat). Konvensi: `id` BIGINT unsigned PK auto-increment,
> `created_at/updated_at` di semua tabel kecuali pivot/ledger yang dicatat, `deleted_at` (soft delete)
> hanya di master. FK `ON DELETE RESTRICT` default; pivot `CASCADE`. Enum diimplementasikan sebagai
> `VARCHAR` + validasi Laravel + (opsional) tabel referensi.
> Semua kolom `*_raw` / `*_ref_raw` = teks asli Excel yang belum ter-resolve ke FK (jangan dibuang).

Diagram teks di bagian akhir. Sumber: [step1-analysis.md](step1-analysis.md), [assumptions.md](assumptions.md).

---

## Grup A — Identity & RBAC

### users
| kolom | tipe | ket |
|---|---|---|
| name | varchar(150) | |
| username | varchar(60) uniq | login |
| email | varchar(150) uniq null | |
| password | varchar(255) | bcrypt |
| employee_id | FK employees null | roster link |
| site_id | FK sites | home site |
| is_active | bool default 1 | |
| last_login_at | timestamp null | |

### roles
`name` varchar(80), `slug` varchar(80) uniq, `description` text null, `is_system` bool.
Seed: `super_admin`, `admin_gudang`, `anak_gudang`, `lapangan_gudang`, `purchasing`, `bos`, `karyawan`.

### permissions
`name` varchar(120), `slug` varchar(120) uniq, `group` varchar(60). Daftar lengkap → [roles-permissions.md](roles-permissions.md).

### role_user  (pivot)  `user_id`, `role_id` — uniq(user_id, role_id)
### permission_role (pivot) `permission_id`, `role_id` — uniq(...)
### personal_access_tokens — bawaan Sanctum.

---

## Grup B — Master: Organisasi & Pihak

### sites
`code` varchar(20) uniq (`SIG-SDA`,`SIG-BPN`), `name`, `is_inventory_managed` bool, `address` text null.

### departments
`name` varchar(100) uniq, `code` varchar(20) null, `is_active` bool. Seed dari Divisi (14 nilai).

### employees
`name` varchar(150), `department_id` FK null, `site_id` FK null, `user_id` FK null,
`role_hint` varchar(40) null (`peminta`/`pemeriksa`/`petugas_gudang`…), `is_active` bool,
`needs_review` bool default 1. Seed dari semua Dropdown List.

### vendors
`name` varchar(200), `code` varchar(30) null, `phone`, `address` text null, `email` null,
`is_active` bool, `notes` text null, `needs_review` bool. `vendor_aliases`(`vendor_id`,`alias`).

### customers
`name` varchar(200), `code` null, `is_active` bool.

### projects
`name` varchar(200), `code` null, `customer_id` FK null, `status` varchar(20) (`ACTIVE`/`CLOSED`).

### workshops
`name` varchar(150), `is_internal` bool, `site_id` FK null, `is_active` bool. Seed Bengkel (13).

---

## Grup C — Master: Katalog Barang

### categories
`parent_id` FK categories null, `name` varchar(150), `level` tinyint (1–4),
`path` varchar(600) (materialized `Induk > Anak1 > …`), `is_active` bool. index(parent_id), index(path).

### units
`code` varchar(15) uniq, `name` varchar(60), `is_active` bool.

### unit_conversions  *(disiapkan, kosong)*
`from_unit_id` FK, `to_unit_id` FK, `factor` decimal(14,6). uniq(from,to).

### items
| kolom | tipe | ket |
|---|---|---|
| code | varchar(40) uniq | Kode Barang |
| description | varchar(400) | index; natural key file transaksi |
| description_normalized | varchar(400) | index — hasil normalisasi utk matching |
| category_id | FK categories | |
| unit_id | FK units | |
| item_type | varchar(20) | `CONSUMABLE`/`SERIALIZED`/`ASSET_PART`/`TYRE`/`MANUFACTURED` |
| needs_blueprint | bool | Perlu Blueprint? |
| lead_time_days | smallint null | LEAD TIME (dibersihkan) |
| default_warehouse_id | FK warehouses null | LETAK GUDANG |
| default_location_id | FK warehouse_locations null | LETAK RAK |
| blueprint_img_path / blueprint_pdf_path | varchar(255) null | |
| blueprint_3d_ref | varchar(60) null | mentah, utk review (NC-8) |
| is_active | bool | |

### item_aliases
`item_id` FK, `alias_description` varchar(400), `alias_normalized` varchar(400) index,
`source` varchar(40) (nama file/sheet), `confidence` decimal(4,3) null, `match_status`
varchar(15) (`AUTO`/`MANUAL`/`PENDING`), `created_by` FK users null.

### item_safety_stocks
`item_id` FK, `source_category` varchar(60) (nama sheet SS), `period_label` varchar(30)
(`Agt2025-Jul2026`), `avg_usage_1m/3m/6m/12m` decimal(12,2) null, `lead_time_days` smallint null,
`sqrt_lt` decimal(8,4) null, `safety_stock` decimal(12,2), `min_pr` decimal(12,2) null,
`effective_date` date null, `is_effective` bool, `note` text null, `needs_review` bool.
index(item_id, is_effective).

### item_monthly_usage  *(derived, opsional — dari SS sheets & npbg_items)*
`item_id` FK, `year_month` char(7), `qty_out` decimal(12,2), `source` varchar(20). uniq(item_id, year_month, source).

---

## Grup D — Master: Lokasi & Unit Ber-serial

### warehouses
`site_id` FK, `code` varchar(20), `name` varchar(80), `is_active` bool. uniq(site_id, code).
Seed: GUDANG 1–5, ETALASE, ETALASE 1 (trailing space di-trim) di SIG-SDA.

### warehouse_locations
`warehouse_id` FK, `code` varchar(30) (rak `B.7.1`), `description` null, `is_active` bool. uniq(warehouse_id, code).

### assets
`code` varchar(40) uniq (Nopol / kode aset), `name` varchar(150), `asset_type` varchar(20)
(`VEHICLE`/`FORKLIFT`/`TRAILER_TAIL`/`OTHER`), `brand_model` varchar(120) null, `site_id` FK,
`is_active` bool, `notes` text null. Seed ~48 dari Dropdown Ban Luar/Maintenance.

### serial_units
| kolom | tipe | ket |
|---|---|---|
| kind | varchar(20) | `STPP_TOOL`/`TYRE`/`MANUFACTURED` |
| serial_no | varchar(80) | `SN-0001`, `2320611829`, `SIG-58` |
| serial_no_normalized | varchar(80) index | |
| item_id | FK items null | |
| manufacturer_code | varchar(20) null | ban `M-0523` |
| spec | json null | ukuran, tipe, dll |
| status | varchar(20) | `IN_STOCK`/`DEPLOYED`/`IN_MAINTENANCE`/`SCRAP`/`RETREAD` |
| current_holder_type / current_holder_id | morph null | asset / department / dll |
| site_id | FK sites | |
| acquired_at | date null | |
uniq(kind, serial_no).

---

## Grup E — Inventory Inti (BARU — tidak ada di Excel)

### inventory
`item_id` FK, `warehouse_id` FK, `actual_qty` decimal(14,2) default 0,
`reserved_qty` decimal(14,2) default 0,
`available_qty` decimal(14,2) **GENERATED ALWAYS AS (actual_qty - reserved_qty) STORED**,
`last_counted_at` timestamp null, `last_movement_at` timestamp null.
**uniq(item_id, warehouse_id)**. index(warehouse_id), index(available_qty).

### stock_movements  *(append-only, tak ada updated_at/deleted_at)*
| kolom | tipe | ket |
|---|---|---|
| item_id | FK | |
| warehouse_id | FK | |
| movement_type | varchar(24) | lihat calculation-engine §6 |
| direction | tinyint | +1 / -1 (utk actual) |
| qty | decimal(14,2) | selalu positif; arah dari `direction` |
| actual_before / actual_after | decimal(14,2) | |
| reserved_before / reserved_after | decimal(14,2) | |
| reference_type / reference_id | morph null | npbg_item, receiving_item, stock_adjustment, stock_reservation, … |
| batch_uuid | char(36) null | grup movement dalam 1 transaksi bisnis |
| note | varchar(255) null | |
| created_by | FK users | |
| created_at | timestamp | |
index(item_id, warehouse_id, created_at), index(reference_type, reference_id), index(batch_uuid).

### stock_reservations
`item_id` FK, `warehouse_id` FK, `request_id` FK null, `request_item_id` FK null,
`qty` decimal(14,2), `status` varchar(15) (`ACTIVE`/`RELEASED`/`CONSUMED`/`EXPIRED`),
`reserved_by` FK users, `reserved_at` timestamp, `released_at` timestamp null,
`expires_at` timestamp null, `note` varchar(255) null.
index(item_id, warehouse_id, status).

### inventory_snapshots  *(cache hasil engine utk dashboard)*
`item_id` FK, `warehouse_id` FK null (null = agregat semua gudang), `actual`, `reserved`,
`available`, `safety_stock`, `lead_time_days`, `selisih`, `status` varchar(12),
`deficit`, `priority_score` decimal(12,2), `priority_level` varchar(8),
`recommendation` varchar(255), `recommended_qty` decimal(12,2), `analysis_run_id` FK, `computed_at`.
uniq(item_id, warehouse_id).

### inventory_analysis_runs
`scope` varchar(20), `lead_time_threshold` decimal(8,2), `median_deficit` decimal(12,2),
`item_count` int, `tidak_aman_count` int, `computed_at`, `triggered_by` FK users null.

---

## Grup F — Material Request (modul Karyawan)

### material_requests
| kolom | tipe | ket |
|---|---|---|
| number | varchar(40) uniq | `REQ/{site}/{yy}/{rom}/{seq}` |
| requester_id | FK users | |
| department_id | FK departments | |
| site_id | FK sites | bisa SIG-BPN |
| purpose | varchar(255) | keperluan |
| work_location | varchar(150) null | lokasi pekerjaan |
| needed_date | date null | |
| status | varchar(20) | 12 status brief §G (lihat status-flow) |
| submitted_at / reviewed_at / completed_at | timestamp null | |
| reviewed_by | FK users null | |
| cancel_reason | varchar(255) null | |
| created_by | FK users | |

### material_request_items
| kolom | tipe | ket |
|---|---|---|
| request_id | FK | |
| item_id | FK items null | null bila belum ter-match |
| description_raw | varchar(400) | input karyawan |
| qty_requested | decimal(14,2) | |
| unit_id | FK units null | |
| warehouse_id | FK warehouses null | ditentukan saat review |
| system_stock_snapshot | decimal(14,2) null | available saat review |
| safety_stock_snapshot | decimal(12,2) null | |
| projected_stock | decimal(14,2) null | |
| below_safety_flag | bool default 0 | |
| physical_check_status | varchar(20) default 'NOT_CHECKED' | `VERIFIED_MATCH`/`VERIFIED_MISMATCH` |
| physical_check_qty | decimal(14,2) null | hasil cek fisik gudang |
| physical_check_note | varchar(255) null | |
| physical_checked_by | FK users null | |
| qty_approved | decimal(14,2) null | |
| qty_reserved | decimal(14,2) default 0 | |
| qty_to_purchase | decimal(14,2) default 0 | |
| line_status | varchar(20) | `PENDING`/`READY`/`PARTIAL`/`NEED_PURCHASE`/`RESERVED`/`ISSUED`/`CANCELLED` |
| note | varchar(255) null | |

---

## Grup G — NPBG (Goods Issue)

### npbg
| kolom | tipe | ket |
|---|---|---|
| number | varchar(40) uniq | |
| doc_type | char(4) default 'NPBG' | |
| prefix / year / month / sequence | varchar(8) / smallint / tinyint / int | uniq(doc_type,prefix,year,month,sequence) |
| date | date | |
| type | varchar(15) | `PENJUALAN`/`NON_PENJUALAN` |
| classification | varchar(25) | enum klasifikasi (step1 §Katalog) + `UNCLASSIFIED` |
| request_id | FK material_requests null | |
| customer_id / project_id / asset_id | FK null | |
| requester_id | FK users/employees | |
| department_id | FK departments null | |
| warehouse_id | FK warehouses | |
| site_id | FK sites | |
| issued_by | FK users null | petugas gudang |
| status | varchar(20) | `DRAFT`/`PREPARING`/`READY_TO_PICKUP`/`PICKED_UP`/`COMPLETED`/`CANCELLED` |
| signature_path | varchar(255) null | ttd pickup |
| picked_up_by | varchar(150) null | nama pengambil |
| picked_up_at | timestamp null | |
| notes | text null | |
| created_by | FK users | |

### npbg_items
`npbg_id` FK, `item_id` FK null, `description_raw` varchar(400), `item_no` int null,
`qty` decimal(14,2), `unit_id` FK null, `warehouse_id` FK, `location_id` FK null,
`reservation_id` FK stock_reservations null, `qty_issued` decimal(14,2) default 0,
`note` varchar(255) null.

---

## Grup H — Pengadaan (PPB → RFQ → PO → RI)

### ppb
`number` varchar(40) uniq, `prefix/year/month/sequence`, `date`, `requester_id` FK,
`department_id` FK, `site_id` FK, `source_request_id` FK material_requests null,
`status` varchar(20) (`DRAFT`/`SUBMITTED`/`REVIEW`/`APPROVED`/`PURCHASING`/`ORDERED`/`PARTIAL_RECEIVED`/`RECEIVED`/`COMPLETED`/`CANCELLED`),
`approved_by` FK null, `approved_at` null, `notes` text null, `created_by` FK.

### ppb_items
`ppb_id` FK, `item_id` FK null, `description_raw`, `qty` decimal(14,2), `unit_id` FK null,
`request_item_id` FK null, `shortage_qty` decimal(14,2) null,
`safety_stock_snapshot` / `deficit_snapshot` / `priority_score_snapshot` decimal(12,2) null,
`priority_level_snapshot` varchar(8) null,
`qty_ordered` decimal(14,2) default 0, `qty_received` decimal(14,2) default 0,
`line_status` varchar(20), `note` varchar(255) null.

### ppb_amendments
`ppb_id` FK, `ppb_item_id` FK null, `date`, `type` varchar(10) (`AMEND`/`CLOSE`),
`qty_before` / `qty_after` decimal(14,2) null, `reason` varchar(255), `created_by` FK.

### rfqs
`number` varchar(40) uniq, `ppb_id` FK null, `date`, `status` varchar(15)
(`DRAFT`/`SENT`/`QUOTED`/`CLOSED`/`CANCELLED`), `notes` text null, `created_by` FK.

### rfq_items  `rfq_id` FK, `ppb_item_id` FK null, `item_id` FK null, `description`, `qty`, `unit_id` FK null.
### rfq_vendors `rfq_id` FK, `vendor_id` FK, `sent_at` null, `status` varchar(15). uniq(rfq_id, vendor_id).
### rfq_quotes `rfq_id` FK, `rfq_item_id` FK, `vendor_id` FK, `unit_price` decimal(16,2), `lead_time_days` smallint null, `note` null, `is_selected` bool.

### purchase_orders
`number` varchar(40) uniq, `prefix/year/month/sequence`, `date`, `vendor_id` FK,
`ppb_id` FK null, `rfq_id` FK null, `site_id` FK, `expected_date` date null,
`status` varchar(20) (`DRAFT`/`APPROVED`/`SENT`/`PARTIAL_RECEIVED`/`RECEIVED`/`CLOSED`/`CANCELLED`),
`subtotal`/`tax`/`total` decimal(16,2) default 0, `approved_by` FK null, `approved_at` null,
`notes` text null, `created_by` FK.

### purchase_order_items
`po_id` FK, `ppb_item_id` FK null, `item_id` FK, `description`, `qty` decimal(14,2),
`unit_id` FK null, `unit_price` decimal(16,2) default 0, `line_total` decimal(16,2) default 0,
`qty_received` decimal(14,2) default 0, `line_status` varchar(20).

### receivings   *(RI)*
| kolom | tipe | ket |
|---|---|---|
| number | varchar(40) uniq | `RI/{prefix}/…` |
| prefix/year/month/sequence | | uniq composite |
| date | date | |
| source_type | varchar(24) | PURCHASE / LEND_RETURN / BORROW_RETURN / STPP_RETURN / TYRE_OLD / MANUFACTURING_OUTPUT / USED_RETURN / TRANSFER_IN / OPENING |
| po_id | FK null | |
| ppb_id | FK null | |
| vendor_id | FK null | |
| surat_jalan_no | varchar(60) null | |
| checked_by | FK users null | Pemeriksa |
| warehouse_id | FK | |
| site_id | FK | |
| status | varchar(15) | `DRAFT`/`CHECKING`/`CONFIRMED`/`PARTIAL`/`REJECTED`/`CANCELLED` |
| confirmed_by | FK null | |
| confirmed_at | timestamp null | |
| notes | text null | |
| created_by | FK | |

### receiving_items
`receiving_id` FK, `po_item_id` FK null, `item_id` FK null, `description_raw`,
`qty_expected` decimal(14,2) null, `qty_received` decimal(14,2), `qty_accepted` decimal(14,2),
`qty_rejected` decimal(14,2) default 0, `unit_id` FK null, `condition_note` varchar(255) null,
`into_stock` bool default 1, `warehouse_id` FK, `location_id` FK null, `note` varchar(255) null.

---

## Grup I — Stock Opname

### stock_opnames
`number` varchar(40) uniq, `warehouse_id` FK, `site_id` FK, `scheduled_date` date,
`type` varchar(12) (`FULL`/`PARTIAL`/`OPENING`),
`status` varchar(20) (`DRAFT`/`SCHEDULED`/`IN_PROGRESS`/`SUBMITTED`/`PENDING_REVIEW`/`APPROVED`/`REJECTED`/`RECOUNT_REQUIRED`/`COMPLETED`),
`counted_by` FK users null, `submitted_at` null, `reviewed_by` FK null, `reviewed_at` null,
`review_note` varchar(255) null, `created_by` FK.

### stock_opname_items
`opname_id` FK, `item_id` FK, `warehouse_id` FK, `location_id` FK null,
`system_qty` decimal(14,2) (snapshot saat mulai), `physical_qty` decimal(14,2) null,
`difference` decimal(14,2) **GENERATED (physical_qty - system_qty)** null,
`note` varchar(255) null, `count_status` varchar(10) (`PENDING`/`COUNTED`),
`review_status` varchar(12) (`PENDING`/`APPROVED`/`REJECTED`/`RECOUNT`).
**Constraint aplikasi:** `physical_qty <> system_qty` ⟹ `note` wajib (ATURAN MUTLAK 10).

### stock_adjustments
`stock_opname_item_id` FK null, `item_id` FK, `warehouse_id` FK,
`qty_before` decimal(14,2), `qty_after` decimal(14,2),
`difference` decimal(14,2), `reason` varchar(255), `movement_id` FK stock_movements null,
`approved_by` FK, `approved_at` timestamp, `created_by` FK.

---

## Grup J — Modul Tracking

### lend_transactions
`number` varchar(40) uniq, `item_id` FK null, `description_raw`, `qty` decimal(14,2),
`unit_id` FK null, `purpose` varchar(12) (`INTERNAL`/`PROJECT`/`RELASI`),
`borrower_name` varchar(200), `customer_id` FK null, `project_id` FK null, `est_days` smallint null,
`out_npbg_id` FK null, `out_ref_raw` varchar(60) null, `out_date` date,
`return_ri_id` FK null, `return_ref_raw` varchar(60) null, `return_date` date null,
`status` varchar(20) (`ON_LOAN`/`RETURNED`/`PARTIAL_RETURN`/`OVERDUE`),
`due_date` date null, `condition_out` varchar(255) null, `condition_in` varchar(255) null,
`created_by` FK.

### borrow_transactions
`number` varchar(40) uniq, `item_id` FK null, `description_raw`, `qty` decimal(14,2),
`unit_id` FK null, `lender_vendor_id` FK null, `lender_name` varchar(200),
`in_ri_id` FK null, `receipt_ref` varchar(60) null,
`status` varchar(15) (`BORROWED`/`RETURNED`/`PARTIAL`),
`return_npbg_id` FK null, `return_ref_raw` varchar(60) null,
`borrowed_at` date, `returned_at` date null, `condition_note` varchar(255) null, `created_by` FK.

### stpp_transactions
`number` varchar(40) uniq, `serial_unit_id` FK null, `serial_no_raw` varchar(80) null,
`item_id` FK null, `description_raw`, `qty` decimal(14,2), `unit_id` FK null,
`holder_id` FK employees null, `holder_name_raw` varchar(150) null,
`placement_department_id` FK null, `placement_raw` varchar(80) null,
`out_npbg_id` FK null, `out_npbg_ref_raw` varchar(60) null, `out_item_no` int null, `out_date` date,
`status` varchar(10) (`ACTIVE`/`PASSIVE`),
`return_ri_id` FK null, `return_ref_raw` varchar(60) null, `return_date` date null,
`out_note` varchar(255) null, `return_note` varchar(255) null, `created_by` FK.

### stpp_maintenance
`stpp_transaction_id` FK null, `serial_no_raw` varchar(80), `item_id` FK null, `description_raw`,
`qty` decimal(14,2), `unit_id` FK null, `requester_id` FK employees null,
`npbg_id` FK null, `npbg_ref_raw` varchar(60) null, `npbg_date` date null,
`note` varchar(255) null, `created_by` FK.

### tyre_changes
`asset_id` FK, `change_date` date, `position` varchar(20) null (`FRONT_L`/`FRONT_R`/`REAR_LO`/`REAR_LI`/`REAR_RO`/`REAR_RI`/`SPARE`/…),
`change_seq` smallint null,
`out_npbg_id` FK null, `out_ref_raw` varchar(60) null,
`new_serial_unit_id` FK serial_units null, `new_tyre_desc` varchar(300) null, `new_serial_raw` varchar(80) null,
`status` varchar(12) (`CLEAR`/`PENDING_RI`),
`in_ri_id` FK null, `in_ref_raw` varchar(60) null, `in_date` date null,
`old_serial_unit_id` FK serial_units null, `old_tyre_desc` varchar(300) null, `old_serial_raw` varchar(80) null,
`reason` varchar(255) null, `is_opening` bool default 0, `created_by` FK.

### tyre_transfers
`from_site_id` FK, `to_site_id` FK, `serial_unit_id` FK null, `tyre_desc` varchar(300) null,
`direction` varchar(10) (`DELIVER`/`RECEIVE`), `out_npbg_id` FK null, `in_ri_id` FK null,
`out_date` date null, `in_date` date null, `status` varchar(15), `note` varchar(255) null, `created_by` FK.

### maintenance_orders
`number` varchar(40) uniq (No SPK), `report_date` date, `asset_id` FK, `site_id` FK,
`reported_by` FK employees null, `problem_summary` varchar(255) null,
`status` varchar(12) (`OPEN`/`ON_GOING`/`COMPLETED`/`CANCELLED`), `completed_at` date null, `created_by` FK.

### maintenance_order_subs
`maintenance_order_id` FK, `sub_no` varchar(10) (`SUB-01`), `workshop_id` FK null,
`workshop_raw` varchar(80) null, `problem_detail` text null,
`npbg_id` FK null, `npbg_ref_raw` varchar(60) null, `ri_id` FK null (NC-12),
`status` varchar(12) (`ON_GOING`/`COMPLETED`), `finish_date` date null, `result_note` text null,
`photo_before_ref` varchar(120) null, `photo_after_ref` varchar(120) null.
uniq(maintenance_order_id, sub_no).

### manufacturing_orders
`number` varchar(40) uniq (No MA / No MJ), `kind` varchar(10) (`ASSEMBLY`/`JASA`), `date` date,
`product_name` varchar(200) null, `site_id` FK, `vendor_id` FK null (JASA),
`status` varchar(12) (`REQUESTED`/`ON_GOING`/`COMPLETED`/`CANCELLED`), `completed_at` date null, `created_by` FK.

### manufacturing_order_subs
`manufacturing_order_id` FK, `sub_no` varchar(10), `item_no` int null,
`serial_unit_id` FK serial_units null, `serial_no_raw` varchar(80) null,
`process` varchar(60) (routing step), `npbg_id` FK null, `npbg_ref_raw` varchar(60) null,
`ri_id` FK null, `ri_ref_raw` varchar(60) null,
`status` varchar(12) (`ON_GOING`/`COMPLETED`), `finish_date` date null,
`note_start` text null, `note_end` text null, `photo_ref` varchar(120) null.
uniq(manufacturing_order_id, sub_no).

### used_returns
`number` varchar(40) uniq, `npbg_id` FK null, `npbg_ref_raw` varchar(60) null,
`ri_id` FK null, `ri_ref_raw` varchar(60) null, `return_date` date null,
`status` varchar(10) (`CLEAR`/`PENDING`), `format` varchar(18) (`COMPONENT_MATRIX`/`ITEM_LINE`),
`note` text null, `created_by` FK.

### used_return_component_types  *(seed 14: Bonit BR, Pen BR, Pen SS, Valve BR, Cyl Cap, Mur Br/CS/GI/SS, Per CS, Baut BR/CS/GI/SS)*
`code` varchar(20) uniq, `name` varchar(80), `default_item_id` FK items null, `is_active` bool.

### used_return_items
`used_return_id` FK, `item_id` FK null, `component_type_id` FK used_return_component_types null,
`description_raw` varchar(400) null, `qty` decimal(12,2) (signed — negatif = shortage),
`unit_id` FK null, `condition` varchar(12) (`REUSABLE`/`SCRAP`/`DAMAGED`/`USED`),
`into_stock` bool default 0, `item_no` int null,
`photo_out_ref` varchar(120) null, `photo_in_ref` varchar(120) null, `note` varchar(255) null.

---

## Grup K — Sistem

### attachments  *(polymorphic)*
`attachable_type` / `attachable_id` (morph, index), `kind` varchar(30)
(`PHOTO_BEFORE`/`PHOTO_AFTER`/`SIGNATURE`/`BUKTI_KELUAR`/`BUKTI_TERIMA`/`BLUEPRINT`/`PDF`/`OTHER`),
`disk` varchar(20), `path` varchar(255) null, `original_name` varchar(255) null,
`external_ref` varchar(150) null (nama file Excel), `mime` varchar(100) null, `size` int null,
`uploaded_by` FK users null, `created_at`.

### notifications  — bawaan Laravel (`id` uuid, `type`, `notifiable`, `data` json, `read_at`).

### audit_logs
`user_id` FK null, `action` varchar(60), `module` varchar(40),
`auditable_type` / `auditable_id` morph null, `reference_no` varchar(40) null,
`before` json null, `after` json null, `ip_address` varchar(45) null,
`user_agent` varchar(255) null, `created_at`. index(auditable_type, auditable_id), index(user_id, created_at).

### settings
`key` varchar(80) uniq, `value` json, `group` varchar(40), `description` varchar(255) null,
`updated_by` FK null. Seed: `reservation_expiry_days=14`, `lead_time_threshold_fallback=14`,
`ppn_percent=11`, `doc_prefixes`, dll.

### document_sequences
`doc_type` varchar(10), `prefix` varchar(8), `year` smallint, `month` tinyint,
`last_sequence` int. uniq(doc_type, prefix, year, month). *(dikunci `SELECT ... FOR UPDATE` saat generate)*

### import_batches / import_rows  *(log migrasi Excel)*
batch: `file_name`, `sheet`, `target_table`, `rows_read`, `rows_imported`, `rows_skipped`,
`report` json, `run_by` FK, `finished_at`.
row: `import_batch_id` FK, `source_row_no` int, `status` varchar(12) (`IMPORTED`/`SKIPPED`/`ERROR`),
`reason` varchar(255) null, `payload` json null, `target_id` bigint null.

---

## Ringkasan relasi (diagram teks)

```
                                    ┌──────────┐
sites ──1:N── warehouses ──1:N── warehouse_locations
sites ──1:N── users, employees, assets, material_requests, npbg, ppb, purchase_orders, receivings, stock_opnames

users ──N:M── roles ──N:M── permissions            employees ──0:1── users
categories ──self 1:N── categories
units ──1:N── items ──N:1── categories
items ──1:N── item_aliases, item_safety_stocks, item_monthly_usage, serial_units
items ──1:N── inventory ──N:1── warehouses
inventory ──1:N── stock_movements   (reference → morph: npbg_item | receiving_item | stock_adjustment | stock_reservation)
inventory ──1:N── stock_reservations ──N:1── material_requests / material_request_items
items+warehouses ──1:1── inventory_snapshots ──N:1── inventory_analysis_runs

material_requests ──1:N── material_request_items ──N:1── items
material_requests ──1:N── npbg           (1 request → N npbg)         [A6]
material_requests ──1:N── ppb            (1 request → N ppb)          [A6]

npbg ──1:N── npbg_items ──N:1── items, units, warehouses
npbg ──N:1── customers, projects, assets, departments, users
npbg.classification ──diskriminator──► lend / borrow / stpp / tyre_changes / maintenance_orders / manufacturing_orders / used_returns

ppb ──1:N── ppb_items ──N:1── items ;  ppb ──1:N── ppb_amendments
ppb ──1:N── rfqs ──1:N── rfq_items / rfq_vendors / rfq_quotes ──N:1── vendors
ppb ──1:N── purchase_orders ──1:N── purchase_order_items ──N:1── items
purchase_orders ──N:1── vendors ; ──1:N── receivings
receivings ──1:N── receiving_items ──N:1── items, warehouses ; receivings ──N:1── po, ppb, vendors, users(checked_by)
receivings ──(source_type)── lend/borrow/stpp/tyre/manufacturing/used_returns  (leg masuk)

stock_opnames ──1:N── stock_opname_items ──0:1── stock_adjustments ──1:1── stock_movements

assets ──1:N── tyre_changes ──N:1── npbg, receivings, serial_units(new/old)
assets ──1:N── maintenance_orders ──1:N── maintenance_order_subs ──N:1── npbg, receivings, workshops
manufacturing_orders ──1:N── manufacturing_order_subs ──N:1── npbg, receivings, serial_units
manufacturing_orders ──N:1── vendors (JASA)
used_returns ──1:N── used_return_items ──N:1── items, used_return_component_types
stpp_transactions ──N:1── serial_units, npbg, receivings, employees, departments
lend_transactions ──N:1── npbg, receivings, customers, projects
borrow_transactions ──N:1── receivings, npbg, vendors

attachments ──morph──► (hampir semua modul)
audit_logs ──morph──► (semua write penting)
notifications ──N:1── users
document_sequences, settings, import_batches/rows ── standalone
```

**Perkiraan: 58 tabel** (7 RBAC/pivot, 16 master, 6 inventory, 3 request, 2 npbg, 12 pengadaan,
3 opname, 15 tracking, ~8 sistem).

Detail perilaku status → [status-flow.md](status-flow.md). Endpoint → [api-spec.md](api-spec.md).
