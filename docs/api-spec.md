# STOCKWISE — API Architecture & Spec (draft STEP 2)

> REST, JSON, prefix `/api`. Auth: Sanctum bearer token (SPA/token mode). Semua endpoint (kecuali
> `login`) butuh `auth:sanctum`. Otorisasi via Policy/Gate + middleware `permission:{slug}`.
> Frontend: Axios + TanStack Query. Finalisasi endpoint menyusul setelah ERD di-approve.

## 1. Konvensi

- **Response sukses (koleksi):**
  ```json
  { "data": [...], "meta": { "page":1, "per_page":25, "total":123, "last_page":5 },
    "links": { "next": "...", "prev": null } }
  ```
- **Response sukses (item):** `{ "data": { ... } }`
- **Error:** `{ "message": "...", "errors": { "field": ["..."] } }` — 422 validasi, 401 auth,
  403 forbidden, 404, 409 konflik state, 429 rate limit.
- **Query params umum:** `?page=`, `?per_page=` (max 100), `?sort=field|-field`, `?search=`,
  `?filter[x]=`, `?include=rel1,rel2`.
- **Idempotensi aksi state:** transisi yang sudah terjadi → 409 dengan pesan jelas (bukan error 500).
- **Rate limit:** `login` 5/menit/IP; API umum 120/menit/user; export 10/menit/user.
- **Timestamp:** ISO-8601 UTC. **Uang:** integer rupiah / decimal string.
- **Audit:** semua `POST/PUT/PATCH/DELETE` penting tercatat di `audit_logs`.

## 2. Auth

| Method | Path | Perm | Ket |
|---|---|---|---|
| POST | `/api/login` | — | `{username,password}` → `{token, user, roles, permissions}` |
| POST | `/api/logout` | auth | revoke token aktif |
| GET | `/api/me` | auth | user + roles + permissions + site |
| PUT | `/api/me` | `profile.update_own` | nama, password lama/baru |
| GET | `/api/notifications` | `notification.view_own` | list; `?filter[unread]=1` |
| POST | `/api/notifications/{id}/read` | own | |
| POST | `/api/notifications/read-all` | own | |

## 3. Master data

Pola CRUD seragam (`GET list`, `GET /{id}`, `POST`, `PUT /{id}`, `DELETE /{id}`) untuk:
`/api/categories`, `/api/units`, `/api/warehouses`, `/api/warehouse-locations`, `/api/sites`,
`/api/vendors`, `/api/customers`, `/api/projects`, `/api/workshops`, `/api/departments`,
`/api/employees`, `/api/assets`, `/api/serial-units`.

Tambahan:
| Method | Path | Perm |
|---|---|---|
| GET | `/api/categories/tree` | `master.category.view` |
| GET | `/api/vendors/{id}/aliases` · POST/DELETE | `master.vendor.update` |
| GET | `/api/dropdowns` | auth | bundel list ringan (units, departments, warehouses, …) utk form |

## 4. Item & Inventory

| Method | Path | Perm | Ket |
|---|---|---|---|
| GET | `/api/items` | `item.view` | filter: kategori 1-4, uom, gudang, status inv, priority_level, needs_blueprint, lead_time range, selisih range, kode, deskripsi (brief §AF) |
| GET | `/api/items/{id}` | `item.view` | + safety stock, lead time, alias, inventory per gudang |
| POST/PUT/DELETE | `/api/items/{id}` | `item.create/update/delete` | |
| POST | `/api/items/import` | `item.import` | upload xlsx → `import_batches` report |
| GET | `/api/items/{id}/safety-stocks` | `item.safety_stock.view` | semua baris + yg `is_effective` |
| PUT | `/api/items/{id}/safety-stock` | `item.safety_stock.update` | set nilai efektif |
| GET | `/api/safety-stock/conflicts` | `item.safety_stock.resolve_conflict` | daftar item SS ganda |
| POST | `/api/safety-stock/conflicts/{itemId}/resolve` | idem | pilih baris efektif |
| GET | `/api/item-aliases?filter[status]=PENDING` | `item.alias.view` | antrian Cocokkan Barang |
| POST | `/api/item-aliases/match` | `item.alias.match` | `{alias_id \| description, item_id}` |
| GET | `/api/inventory` | `inventory.view` | per (item,gudang): actual/reserved/available |
| GET | `/api/inventory/{itemId}` | `inventory.view` | detail + histori movement |
| GET | `/api/inventory/analysis` | `inventory.view_analysis` | selisih/status/deficit/priority/rekomendasi + params run (median, threshold) |
| POST | `/api/inventory/analysis/recompute` | `inventory.view_analysis` | trigger job (Admin) |
| POST | `/api/inventory/projected` | `request.create`/`ppb.create` | body `[{item_id,warehouse_id,qty}]` → projected_stock + warning (calc-engine §4) |
| POST | `/api/inventory/transfer` | `inventory.transfer` | antar gudang → 2 movement |
| GET | `/api/stock-movements` | `stock_movement.view` | filter item, gudang, type, tanggal, reference |

## 5. Material Request

| Method | Path | Perm | Ket |
|---|---|---|---|
| GET | `/api/requests` | `request.view` / `request.view_own` | scope otomatis by role |
| POST | `/api/requests` | `request.create` | header + items; auto snapshot stok + projected |
| GET | `/api/requests/{id}` | view/own | timeline, items dgn status stok |
| PUT | `/api/requests/{id}` | `request.update_own` (DRAFT saja) | |
| POST | `/api/requests/{id}/submit` | `request.update_own` | DRAFT→SUBMITTED |
| POST | `/api/requests/{id}/review` | `request.review` | SUBMITTED→UNDER_REVIEW, assign reviewer |
| POST | `/api/requests/{id}/items/{itemId}/physical-check` | `request.physical_check` | `{status: MATCH\|MISMATCH, qty, note}` |
| POST | `/api/requests/{id}/reserve` | `request.reserve` | semua baris READY → RESERVATION movement |
| POST | `/api/requests/{id}/need-purchase` | `request.set_need_purchase` | buat PPB utk kekurangan |
| POST | `/api/requests/{id}/cancel` | `request.cancel_own`/`cancel_any` | release reservation, alasan wajib |

## 6. NPBG

| Method | Path | Perm |
|---|---|---|
| GET | `/api/npbg` · `/api/npbg/{id}` | `npbg.view` / `npbg.view_own` |
| POST | `/api/npbg` | `npbg.create` (dari request `RESERVED/PARTIAL`, atau manual) |
| PUT | `/api/npbg/{id}` | `npbg.update` (DRAFT) |
| POST | `/api/npbg/{id}/prepare` | `npbg.prepare` |
| POST | `/api/npbg/{id}/ready` | `npbg.ready` |
| POST | `/api/npbg/{id}/pickup` | `npbg.pickup` — body `{picked_up_by, signature(file), items:[{npbg_item_id, qty}]}` → STOCK_OUT |
| POST | `/api/npbg/{id}/cancel` | `npbg.cancel` |
| GET | `/api/npbg/{id}/pdf` | `npbg.print` |
| GET | `/api/npbg/export?format=xlsx\|csv\|pdf` | `npbg.export` |

## 7. PPB / RFQ / PO / Receiving

| Method | Path | Perm |
|---|---|---|
| GET/POST | `/api/ppb` , GET/PUT `/api/ppb/{id}` | `ppb.view(_own)` / `ppb.create` / `ppb.update` |
| POST | `/api/ppb/{id}/submit` `/review` `/approve` `/reject` | `ppb.*` |
| POST | `/api/ppb/{id}/amendments` | `ppb.amend` / `ppb.close` |
| GET/POST | `/api/rfqs` , `/api/rfqs/{id}` | `rfq.*` |
| POST | `/api/rfqs/{id}/send` `/quotes` `/select` | `rfq.send` / `rfq.select` |
| GET/POST | `/api/purchase-orders` , `/{id}` | `po.view` / `po.create` / `po.update` |
| POST | `/api/purchase-orders/{id}/approve` `/send` `/cancel` | `po.approve` / `po.send` / `po.cancel` |
| GET/POST | `/api/receivings` , `/{id}` | `receiving.view` / `receiving.create` / `receiving.update` |
| POST | `/api/receivings/{id}/confirm` | `receiving.confirm` → RECEIVING/RETURN/STOCK_IN |
| POST | `/api/receivings/{id}/reject` | `receiving.reject` |
| GET | `/api/purchase-orders/{id}/pdf` , `/api/receivings/{id}/pdf` | `export.pdf` |

## 8. Stock Opname

| Method | Path | Perm |
|---|---|---|
| GET/POST | `/api/stock-opnames` , `/{id}` | `opname.view` / `opname.schedule` |
| POST | `/api/stock-opnames/{id}/start` | `opname.count` (DRAFT/SCHEDULED→IN_PROGRESS) |
| PUT | `/api/stock-opnames/{id}/items/{itemId}` | `opname.count` — `{physical_qty, note}` |
| POST | `/api/stock-opnames/{id}/submit` | `opname.submit` — tolak bila selisih tanpa note (422) |
| POST | `/api/stock-opnames/{id}/approve` | `opname.approve` → stock_adjustments + STOCK_ADJUSTMENT |
| POST | `/api/stock-opnames/{id}/reject` | `opname.reject` (alasan wajib) |
| POST | `/api/stock-opnames/{id}/recount` | `opname.request_recount` — `{item_ids:[]}` |
| GET | `/api/stock-adjustments` | `stock_movement.view` |

## 9. Tracking modules (pola seragam)

Untuk `lend`, `borrow`, `stpp`, `tyre-changes`, `maintenance-orders`, `manufacturing-orders`,
`used-returns`:

| Method | Path | Perm |
|---|---|---|
| GET | `/api/{module}` · `/{id}` | `{module}.view` |
| POST | `/api/{module}` | `{module}.create` |
| PUT | `/api/{module}/{id}` | `{module}.update` |
| POST | `/api/{module}/{id}/return` \| `/close` \| `/complete` | `{module}.return/close/complete` |

Sub-resource: `POST /api/maintenance-orders/{id}/subs`, `POST /api/manufacturing-orders/{id}/subs`,
`POST /api/used-returns/{id}/items`.
Attachment: `POST /api/{module}/{id}/attachments` (multipart), `DELETE /api/attachments/{id}`.

## 10. Dashboard & Report

| Method | Path | Perm | Isi |
|---|---|---|---|
| GET | `/api/dashboard/karyawan` | `dashboard.karyawan` | request saya, status, ready pickup, notif |
| GET | `/api/dashboard/gudang` | `dashboard.gudang` | KPI inventory (brief §AE), request masuk, opname pending, high priority |
| GET | `/api/dashboard/lapangan` | `dashboard.lapangan` | barang harus disiapkan, NPBG ready, pickup hari ini |
| GET | `/api/dashboard/opname` | `dashboard.opname` | jadwal, progres, riwayat |
| GET | `/api/dashboard/purchasing` | `dashboard.purchasing` | PPB baru, high priority PPB, PO overdue, pending receiving, lead time |
| GET | `/api/dashboard/executive` | `dashboard.executive` | brief §W: total inventory, health, request/procurement trend, fast/slow moving, total deficit |
| GET | `/api/reports/{type}` | `report.{type}` | inventory\|request\|npbg\|ppb\|opname\|stock-movement\|procurement — dgn filter |
| GET | `/api/reports/{type}/export?format=xlsx\|csv\|pdf` | `export.{format}` | |

Charts (brief §AE): endpoint dashboard mengembalikan seri siap-pakai
(`status_breakdown`, `top_deficit`, `stock_vs_safety`, `warehouse_status`, `category_status`,
`lead_time_vs_deficit`, `request_trend`, `procurement_trend`).

## 11. Admin / sistem

| Method | Path | Perm |
|---|---|---|
| CRUD | `/api/users` , `/api/roles` , `/api/permissions` | `user.*` / `role.manage` / `permission.manage` |
| POST | `/api/users/{id}/roles` | `user.assign_role` |
| GET | `/api/audit-logs` | `audit_log.view` — filter user, module, tanggal, reference |
| GET/PUT | `/api/settings` | `settings.view` / `settings.update` |
| GET | `/api/import-batches` · `/{id}` | `item.import` — laporan migrasi Excel |

## 12. Middleware stack (Laravel)

```
api  →  throttle:api  →  auth:sanctum  →  ensure_active_user  →  set_site_scope
     →  (per-route) permission:{slug} / can:{policy}
     →  audit (after)  →  json response
```

- `set_site_scope`: user non–super-admin otomatis ter-scope ke `site_id` untuk data yang site-specific
  (request BPN tak melihat inventory SDA kecuali punya permission lintas-site).
- Semua write dibungkus `DB::transaction`; movement pakai `lockForUpdate` pada baris `inventory` +
  `document_sequences`.

## 13. Postman / HTTP tests

- Koleksi Postman `docs/postman/STOCKWISE.postman_collection.json` (dibuat di fase implementasi).
- Laravel HTTP tests (`tests/Feature/Api/*`) = sumber kebenaran; Postman untuk eksplorasi manual.
