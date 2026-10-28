# STOCKWISE — Status Flow (state machines)

> Semua transisi diberlakukan di **service layer** (bukan kolom bebas). Setiap transisi menulis
> `audit_logs` + memicu `notifications` yang relevan. Aksi yang menggerakkan stok berjalan dalam
> satu DB transaction (lihat [calculation-engine.md §6](calculation-engine.md)).

---

## 1. Material Request (brief §G — 12 status)

```
DRAFT ──submit──▶ SUBMITTED ──admin ambil──▶ UNDER_REVIEW
                                   │
      per baris, saat review + cek fisik:
      ├─ semua baris stok cukup & fisik cocok ──▶ READY ──reserve──▶ RESERVED
      ├─ sebagian cukup                        ──▶ PARTIAL
      └─ ada baris stok kurang                 ──▶ NEED_PURCHASE  (buat PPB utk kekurangan)

RESERVED / PARTIAL ──buat NPBG──▶ PREPARING ──barang disiapkan──▶ READY_TO_PICKUP
READY_TO_PICKUP ──pickup + ttd──▶ PICKED_UP ──semua baris keluar──▶ COMPLETED

* dari state mana pun sebelum PICKED_UP: ──cancel──▶ CANCELLED  (reservation di-release)
* NEED_PURCHASE: setelah PO diterima & stok masuk → baris kembali dinilai → RESERVED/PARTIAL, lanjut normal
* PARTIAL: barang ready dikeluarkan dulu (NPBG #1), sisanya menunggu PO (NPBG #2) — request tetap
  PARTIAL sampai semua baris COMPLETED
```

| Transisi | Aktor | Prasyarat | Efek |
|---|---|---|---|
| DRAFT→SUBMITTED | Karyawan (pemilik) | ≥1 baris, qty>0 | snapshot stok per baris, notif Admin Gudang |
| SUBMITTED→UNDER_REVIEW | Admin/Anak Gudang | — | assign reviewer |
| review baris | Admin/Anak Gudang | tiap baris: set `warehouse_id`, `physical_check_status`, `qty_approved` | hitung projected & below_safety |
| →RESERVED | Admin Gudang | semua baris READY | `RESERVATION` movement per baris, `stock_reservations.ACTIVE` |
| →NEED_PURCHASE | Admin Gudang | ≥1 baris kurang | buat/again PPB (`source_request_id`), notif Purchasing |
| →PREPARING | Admin Gudang | NPBG dibuat | status NPBG=PREPARING |
| →READY_TO_PICKUP | Lapangan Gudang | barang fisik disiapkan | notif Karyawan |
| →PICKED_UP | Lapangan Gudang | verifikasi requester+barang+qty, ttd | `STOCK_OUT` (actual−, reserved−), reservation→CONSUMED |
| →COMPLETED | sistem | semua NPBG PICKED_UP | notif Karyawan |
| →CANCELLED | Karyawan (bila DRAFT/SUBMITTED) / Admin | belum PICKED_UP | `RELEASE_RESERVATION`, alasan wajib |

**Physical check (NC-10):** baris tak boleh masuk `RESERVED` bila `physical_check_status =
VERIFIED_MISMATCH` tanpa penyelesaian — mismatch memicu saran **Stock Opname** untuk item itu.

---

## 2. NPBG

```
DRAFT ──▶ PREPARING ──▶ READY_TO_PICKUP ──▶ PICKED_UP ──▶ COMPLETED
   └──────────────── CANCELLED (sebelum PICKED_UP) ────────────┘
```
- `DRAFT→PREPARING`: dibuat dari Request `RESERVED/PARTIAL` (atau manual oleh Admin Gudang utk
  klasifikasi non-request seperti `UMUM` pemakaian harian — **asumsi**, konfirmasi).
- `→PICKED_UP`: memicu `STOCK_OUT` untuk tiap `npbg_item`; wajib `signature_path` + `picked_up_by`.
- `→COMPLETED`: otomatis setelah semua item issued; modul tracking terkait (via `classification`)
  dibuat/di-link pada titik ini.
- `CANCELLED`: reservation terkait di-release.

**Mapping status Excel lama → baru:** NPBG Excel tak punya status eksplisit → semua baris historis
diimport sbg `COMPLETED` + `is_historical`.

---

## 3. PPB (Permintaan Pembelian Barang)

```
DRAFT ──submit──▶ SUBMITTED ──▶ REVIEW ──approve──▶ APPROVED
                                   └──reject──▶ CANCELLED
APPROVED ──Purchasing ambil──▶ PURCHASING ──buat PO──▶ ORDERED
ORDERED ──RI sebagian──▶ PARTIAL_RECEIVED ──RI penuh──▶ RECEIVED ──▶ COMPLETED
   * AMEND / CLOSE via ppb_amendments kapan saja sebelum COMPLETED
   * CLOSE seluruh baris ──▶ CANCELLED
```

| Status baru | Dari Excel `Status` |
|---|---|
| SUBMITTED / REVIEW | `Requested` |
| APPROVED / PURCHASING | `Requested` + di-flag shortage |
| (line) shortage | `Shortage` |
| — | `Amend` → ada `ppb_amendments` type AMEND |
| CANCELLED | `Close`, `Error` |
| COMPLETED | `Completed` |

- `REVIEW→APPROVED`: snapshot `safety_stock/deficit/priority_*` per baris (brief §M).
- `ORDERED`: sinkron dgn `purchase_orders.status`.
- Line `ppb_items.line_status`: `PENDING`/`APPROVED`/`ORDERED`/`PARTIAL_RECEIVED`/`RECEIVED`/`CLOSED`.

---

## 4. RFQ

```
DRAFT ──▶ SENT (ke ≥1 vendor) ──▶ QUOTED (quote masuk) ──pilih vendor──▶ CLOSED ──▶ (buat PO)
   └──▶ CANCELLED
```
RFQ opsional — Purchasing boleh langsung `PPB APPROVED → PO` bila vendor sudah pasti.

---

## 5. Purchase Order

```
DRAFT ──approve──▶ APPROVED ──kirim ke vendor──▶ SENT
SENT ──RI sebagian──▶ PARTIAL_RECEIVED ──RI penuh──▶ RECEIVED ──▶ CLOSED
   └────────────── CANCELLED (sebelum ada RI) ──────────────┘
```
- `APPROVED` butuh permission `po.approve` (Purchasing/BOS sesuai limit nilai — Settings).
- `qty_received` per `purchase_order_items` di-update dari `receiving_items` yang `CONFIRMED`.
- PO `RECEIVED` → PPB terkait dievaluasi ulang → `RECEIVED/COMPLETED`.

---

## 6. Receiving (RI)

```
DRAFT ──▶ CHECKING (cek qty & kondisi per baris) ──▶ CONFIRMED ──▶ (stock in)
                                    ├──sebagian diterima──▶ PARTIAL ──lanjut RI lain
                                    └──semua ditolak──▶ REJECTED
   └────────── CANCELLED (sebelum CONFIRMED) ──────────┘
```
- **Stok TIDAK bertambah sebelum `CONFIRMED`** (ATURAN MUTLAK 8).
- `CONFIRMED`: untuk tiap `receiving_item` dengan `into_stock=true` → movement
  `RECEIVING` (source PURCHASE) / `RETURN` (source retur) / `STOCK_IN` (manufaktur), `qty_accepted`.
- `source_type` menentukan modul mana yang di-update (mis. `STPP_RETURN` → set
  `stpp_transactions.status = PASSIVE`, `return_ri_id`).
- Excel RI historis → import sbg `CONFIRMED` + `is_historical` (tidak menggerakkan stok live).

---

## 7. Stock Opname (brief §I, §J, §AP)

```
DRAFT ──▶ SCHEDULED ──mulai──▶ IN_PROGRESS ──(input fisik semua baris)──▶ SUBMITTED
SUBMITTED ──▶ PENDING_REVIEW
PENDING_REVIEW ──Admin Gudang──┬─ APPROVED ──▶ (buat stock_adjustment + STOCK_ADJUSTMENT movement) ──▶ COMPLETED
                               ├─ REJECTED (alasan wajib) ──▶ COMPLETED (tanpa adjustment)
                               └─ RECOUNT_REQUIRED ──▶ IN_PROGRESS (hitung ulang baris tertentu)
```

Aturan submit (brief §I, ATURAN MUTLAK 10):
- `physical_qty` wajib terisi semua baris sebelum `SUBMITTED`.
- `physical_qty ≠ system_qty` ⟹ `note` **wajib**; bila kosong → submit ditolak (TC-SO-002).
- `physical_qty = system_qty` ⟹ `note` opsional (TC-SO-001).

Approve (TC-SO-004):
- Per `stock_opname_item` `review_status=APPROVED` & `difference≠0`:
  buat `stock_adjustments` (`qty_before=inventory.actual`, `qty_after=physical_qty`), movement
  `STOCK_ADJUSTMENT`, update `inventory.actual_qty = physical_qty`, `last_counted_at=now`.
- Anak Gudang **tidak boleh** mengubah `actual` langsung (brief §I).

---

## 8. Modul tracking

### Lend
```
ON_LOAN ──(RI kembali penuh)──▶ RETURNED
ON_LOAN ──(RI sebagian)──▶ PARTIAL_RETURN ──▶ RETURNED
ON_LOAN ──(due_date lewat, job)──▶ OVERDUE ──(RI)──▶ RETURNED
```
`due_date = out_date + est_days`. `RETURNED` butuh `return_ri_id` (source `LEND_RETURN`).
Mapping Excel: `SEDANG DIPINJAM`→ON_LOAN, `KEMBALI`→RETURNED, `DEADLINE`→OVERDUE, `#REF!`→ON_LOAN+review.

### Borrow
```
BORROWED ──(NPBG pengembalian)──▶ RETURNED   |   ──sebagian──▶ PARTIAL
```
Mapping: `SEDANG DIPINJAM`→BORROWED, `LUNAS`→RETURNED.

### STPP
```
ACTIVE ──(RI penarikan / rusak)──▶ PASSIVE
PASSIVE ──(diserahkan lagi, NPBG baru)──▶ ACTIVE   (baris STPP baru, serial sama)
```
`ACTIVE` butuh `out_npbg_id`. `PASSIVE` butuh `return_ri_id` atau alasan.

### Tyre Change
```
PENDING_RI ──(RI ban lama masuk)──▶ CLEAR
* is_opening=true → langsung CLEAR (pendataan awal, tanpa RI)
```

### Maintenance Order (+ subs)
```
order:  OPEN ──(ada sub ON_GOING)──▶ ON_GOING ──(semua sub COMPLETED)──▶ COMPLETED
sub:    ON_GOING ──(finish_date diisi + result_note)──▶ COMPLETED
```
Mapping Excel `Status Hasil Pengerjaan`: `ON-GOING`→ON_GOING, `COMPLETED`→COMPLETED.

### Manufacturing Order (+ subs)
```
order:  REQUESTED ──▶ ON_GOING ──(semua sub COMPLETED & RI hasil CONFIRMED)──▶ COMPLETED
sub:    ON_GOING ──▶ COMPLETED
```
`kind=ASSEMBLY` → hasil `serial_units` kind=MANUFACTURED, RI source `MANUFACTURING_OUTPUT` → `STOCK_IN`.
`kind=JASA` → `vendor_id` wajib.

### Used Return (Pengembalian Bekas)
```
PENDING ──(RI bekas CONFIRMED)──▶ CLEAR
```
Per `used_return_item`: `into_stock=true & condition∈(REUSABLE,USED)` → movement `RETURN` saat RI CONFIRMED;
`condition∈(SCRAP,DAMAGED)` → tidak masuk stok (dibuang / masuk kategori Post-Use Items sesuai bisnis).
`qty` negatif (shortage) → tidak menggerakkan stok, hanya catatan.

---

## 9. Ringkasan event → stock movement

| Transisi | Movement |
|---|---|
| Request → RESERVED | `RESERVATION` |
| Request/NPBG → CANCELLED | `RELEASE_RESERVATION` |
| NPBG → PICKED_UP | `STOCK_OUT` |
| RI (PURCHASE) → CONFIRMED | `RECEIVING` |
| RI (retur pinjam/STPP/bekas) → CONFIRMED | `RETURN` |
| RI (MANUFACTURING_OUTPUT) → CONFIRMED | `STOCK_IN` |
| Opname → APPROVED (Δ≠0) | `STOCK_ADJUSTMENT` |
| Transfer antar-gudang | `TRANSFER_OUT` + `TRANSFER_IN` |
| Migrasi / go-live | `OPENING_BALANCE` |
