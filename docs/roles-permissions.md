# STOCKWISE — Roles & Permissions (RBAC)

> Backend adalah **satu-satunya sumber kebenaran** permission (brief §AC, ATURAN MUTLAK 13–14).
> Frontend hanya menyembunyikan/menonaktifkan UI; setiap request tetap dicek Laravel Policy/Gate +
> middleware `role`/`permission`. Token via Sanctum.

## 1. Roles (brief §F)

| slug | Nama | Ringkas |
|---|---|---|
| `super_admin` | Super Admin | user/role/permission, master data, settings, audit log. Semua akses. |
| `admin_gudang` | Admin Gudang | review request, inventory, stock opname (validasi/approve), NPBG, PPB, stock movement, master barang, laporan |
| `anak_gudang` | Anak Gudang / Stock Opname | jadwal & input stok fisik, riwayat opname. **Tidak** ubah actual stock |
| `lapangan_gudang` | Lapangan Gudang | siapkan barang, NPBG prepare→ready, verifikasi pickup, stock movement (view) |
| `purchasing` | Purchasing | PPB review, vendor, RFQ, PO, receiving, monitoring lead time |
| `bos` | BOS / Management | executive dashboard, semua laporan — **read-only** |
| `karyawan` | Karyawan / Requester | buat & lacak request sendiri, lihat NPBG sendiri, notifikasi, profil |

Seorang user bisa punya >1 role (union permission). BPN: user `karyawan` dengan `site_id = SIG-BPN`
hanya bisa modul Request.

## 2. Permission groups & daftar

Format slug: `{resource}.{action}`. Action umum: `view` (list+detail semua), `view_own`, `create`,
`update`, `delete`, plus aksi khusus.

### auth & profil
`profile.view_own`, `profile.update_own` — semua role.

### user management (Super Admin)
`user.view`, `user.create`, `user.update`, `user.delete`, `user.assign_role`,
`role.view`, `role.manage`, `permission.view`, `permission.manage`

### master data
`master.category.view/create/update/delete`
`master.unit.view/create/update/delete`
`master.warehouse.view/create/update/delete`
`master.location.view/create/update/delete`
`master.site.view/manage`
`master.vendor.view/create/update/delete`
`master.customer.view/create/update/delete`
`master.project.view/create/update/delete`
`master.workshop.view/create/update/delete`
`master.department.view/create/update/delete`
`master.employee.view/create/update/delete`

### item & inventory
`item.view` (halaman Master Barang), `item.lookup` (cari barang utk request/PPB), `item.create`, `item.update`, `item.delete`, `item.import`
`item.safety_stock.view`, `item.safety_stock.update`, `item.safety_stock.resolve_conflict`
`item.lead_time.update`
`item.alias.view`, `item.alias.match`  *(halaman Cocokkan Barang)*
`inventory.view`, `inventory.view_analysis` *(selisih/priority/rekomendasi)*
`inventory.transfer`
`stock_movement.view`

### material request
`request.create`, `request.view_own`, `request.update_own`, `request.cancel_own`
`request.view` *(semua)*, `request.review`, `request.physical_check`, `request.reserve`,
`request.set_need_purchase`, `request.cancel_any`

### NPBG
`npbg.view`, `npbg.view_own`, `npbg.create`, `npbg.update`, `npbg.cancel`
`npbg.prepare`, `npbg.ready`, `npbg.pickup`  *(pickup butuh verifikasi + ttd)*
`npbg.print`, `npbg.export`

### stock opname
`opname.view`, `opname.schedule`, `opname.count` *(input fisik)*, `opname.submit`
`opname.review`, `opname.approve`, `opname.reject`, `opname.request_recount`
`opname.adjust` *(hasil dari approve — bukan aksi manual bebas)*

### PPB
`ppb.view`, `ppb.view_own`, `ppb.create`, `ppb.update`, `ppb.submit`
`ppb.review`, `ppb.approve`, `ppb.reject`, `ppb.amend`, `ppb.close`

### purchasing
`vendor.view` *(sudah di master)*, `rfq.view/create/update/send/select`,
`po.view`, `po.create`, `po.update`, `po.approve`, `po.send`, `po.cancel`
`receiving.view`, `receiving.create`, `receiving.update`, `receiving.confirm`, `receiving.reject`

### tracking modules
`lend.view/create/update/return`
`borrow.view/create/update/return`
`stpp.view/create/update/return`
`tyre.view/create/update/close`
`maintenance.view/create/update/complete`
`manufacturing.view/create/update/complete`
`used_return.view/create/update/close`

### dashboard & report
`dashboard.karyawan`, `dashboard.gudang`, `dashboard.opname`, `dashboard.lapangan`,
`dashboard.purchasing`, `dashboard.executive`
`report.inventory`, `report.request`, `report.npbg`, `report.ppb`, `report.opname`,
`report.stock_movement`, `report.procurement`
`export.excel`, `export.csv`, `export.pdf`

### sistem
`audit_log.view`, `settings.view`, `settings.update`, `notification.view_own`

## 3. Matriks Role × Permission (ringkas)

`✔` = penuh · `O` = hanya milik sendiri (`*_own`) · `R` = read-only · `—` = tidak

| Grup | karyawan | lapangan_gudang | anak_gudang | admin_gudang | purchasing | bos | super_admin |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| profil sendiri | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| user/role/permission | — | — | — | — | — | — | ✔ |
| master data (kategori/unit/gudang/vendor/…) | — | — | — | R* | R (vendor ✔) | R | ✔ |
| item (CRUD) | — | — | — | ✔ | R | R | ✔ |
| item.import | — | — | — | ✔ | — | — | ✔ |
| safety stock / lead time (update) | — | — | — | ✔ | — | — | ✔ |
| item.alias.match (Cocokkan Barang) | — | — | R | ✔ | R | — | ✔ |
| inventory.view / analysis | — | R | R | ✔ | R | R | ✔ |
| inventory.transfer | — | — | — | ✔ | — | — | ✔ |
| stock_movement.view | — | R | R | ✔ | R | R | ✔ |
| request.create / view_own / cancel_own | ✔ | — | — | ✔ | — | — | ✔ |
| request.view (semua) | — | R | R | ✔ | R | R | ✔ |
| request.review / physical_check / reserve / need_purchase | — | — | physical_check ✔ | ✔ | — | — | ✔ |
| npbg.view_own | ✔ | ✔ | — | ✔ | — | R | ✔ |
| npbg.create / update / cancel | — | — | — | ✔ | — | — | ✔ |
| npbg.prepare / ready | — | ✔ | — | ✔ | — | — | ✔ |
| npbg.pickup | — | ✔ | — | ✔ | — | — | ✔ |
| npbg.print / export | ✔(own) | ✔ | — | ✔ | — | R | ✔ |
| opname.schedule | — | — | — | ✔ | — | — | ✔ |
| opname.count / submit | — | — | ✔ | ✔ | — | — | ✔ |
| opname.review / approve / reject / recount | — | — | — | ✔ | — | — | ✔ |
| ppb.create / view_own | ✔? (lihat catatan) | — | — | ✔ | ✔ | — | ✔ |
| ppb.review / approve / reject / amend / close | — | — | — | ✔ | ✔ (approve sesuai limit) | — | ✔ |
| rfq.* | — | — | — | R | ✔ | — | ✔ |
| po.view / create / update / send | — | — | — | R | ✔ | R | ✔ |
| po.approve | — | — | — | — | ✔ (≤ limit) | ✔ (> limit) | ✔ |
| receiving.create / update | — | — | — | ✔ | ✔ | — | ✔ |
| receiving.confirm / reject | — | — | — | ✔ | ✔ | — | ✔ |
| lend / borrow / used_return (CRUD) | — | ✔ | — | ✔ | — | R | ✔ |
| stpp (CRUD) | — | ✔ | R | ✔ | — | R | ✔ |
| tyre / maintenance / manufacturing (CRUD) | — | ✔ | — | ✔ | — | R | ✔ |
| dashboard.karyawan | ✔ | — | — | — | — | — | ✔ |
| dashboard.gudang | — | ✔ | ✔ | ✔ | — | — | ✔ |
| dashboard.lapangan | — | ✔ | — | ✔ | — | — | ✔ |
| dashboard.opname | — | — | ✔ | ✔ | — | — | ✔ |
| dashboard.purchasing | — | — | — | R | ✔ | R | ✔ |
| dashboard.executive | — | — | — | — | — | ✔ | ✔ |
| report.* | — | R (npbg) | R (opname) | ✔ | ✔ (procurement) | ✔ | ✔ |
| export.excel/csv/pdf | own | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| audit_log.view | — | — | — | R (module sendiri) | — | R | ✔ |
| settings.view / update | — | — | — | R | — | — | ✔ |

**Catatan:**
- `ppb.create` oleh Karyawan: **default OFF** — Karyawan hanya buat Request; PPB lahir dari review
  Admin Gudang (`NEED_PURCHASE`). Bisa dinyalakan lewat Settings bila SIG mau. `[NEEDS CONFIRMATION]`
- `po.approve` limit nilai: Settings `po_approval_limit` (default: Purchasing ≤ Rp 50 jt, di atas itu BOS).
  Angka contoh — `[NEEDS CONFIRMATION]`.
- `admin_gudang` master data = read (kecuali item + safety stock + alias = full). CRUD kategori/unit/
  gudang tetap di Super Admin agar konsisten.

## 4. Test permission wajib (brief §AR) → `tests/Feature/Permission/*`

| ID | Aktor | Aksi | Expect |
|---|---|---|---|
| TC-SEC-001 | karyawan | `PUT /api/items/{id}` | 403 |
| TC-SEC-002 | karyawan | `POST /api/stock-opnames/{id}/approve` | 403 |
| TC-SEC-003 | purchasing | `PUT /api/items/{id}` (ubah safety stock) | 403 |
| TC-SEC-004 | bos | `PUT /api/inventory/{id}` | 403 |
| TC-SEC-005 | admin_gudang | `GET /api/users` (tanpa permission) | 403 |
| TC-SEC-006 | anak_gudang | `POST /api/stock-adjustments` (manual) | 403 (adjustment hanya via approve) |
| TC-SEC-007 | karyawan BPN | `POST /api/requests` | 201 |
| TC-SEC-008 | karyawan | `GET /api/requests` (punya orang lain) | hanya lihat milik sendiri |
| TC-SEC-009 | tanpa token | endpoint mana pun (non-auth) | 401 |
| TC-SEC-010 | lapangan_gudang | `POST /api/npbg/{id}/pickup` | 200 ; `POST /api/npbg` | 403 |
