# STOCKWISE — Calculation Engine (backend)

> Wajib berada di **backend** (Laravel service). Frontend hanya menampilkan hasil.
> Sumber aturan: brief §C, §D, §E. Asumsi: [assumptions.md](assumptions.md) A1–A4.
> Legacy Streamlit parity: `utils/calc_engine.py` repo lama `[NEEDS CONFIRMATION]` (A4).

Lokasi rencana: `app/Services/Inventory/StockwiseEngine.php` (murni, tanpa I/O) +
`app/Services/Inventory/InventoryAnalyzer.php` (agregasi dataset: median, percentile) +
job `RecomputeInventoryAnalysis` (nightly + on-demand) → tabel `inventory_snapshots`.

---

## 1. Input per item (per warehouse)

| Simbol | Asal |
|--------|------|
| `actual` | `inventory.actual_qty` |
| `reserved` | `inventory.reserved_qty` |
| `available` | `actual - reserved` (generated column) |
| `safety_stock` (SS) | `item_safety_stocks` baris `is_effective` (NC-7) — 0 bila tak ada |
| `lead_time` (LT) | `items.lead_time_days` — 0/null → dianggap 0 untuk skor, tapi lihat threshold |
| `sisa_stok` | **= `available`** (asumsi A1) |

---

## 2. Per-item formulas (brief §C)

```
selisih  = sisa_stok - safety_stock

status:
    if sisa_stok == 0 AND safety_stock == 0 :  "BEP"
    elif selisih >= 0                        :  "AMAN"
    else                                    :  "TIDAK_AMAN"

deficit  = max(safety_stock - sisa_stok, 0)

priority_score:
    if status == "TIDAK_AMAN" :  (deficit * 2.0) + (lead_time * 1.0)
    else                      :  0

priority_level:
    if status in ("AMAN", "BEP")            :  "LOW"
    elif status == "TIDAK_AMAN":
        if deficit >= median_deficit_tidak_aman
           OR lead_time >= lead_time_threshold :  "HIGH"
        else                                   :  "MEDIUM"
```

- Semua qty `decimal(14,2)`. Perbandingan pakai toleransi `1e-6`.
- `priority_score` dibulatkan 2 desimal untuk tampilan; disimpan penuh.

---

## 3. Dataset-level parameters (brief §C + asumsi A2, A3)

Dihitung ulang setiap `RecomputeInventoryAnalysis` (nightly 01:00 + trigger manual + setelah
opname approve / receiving confirm besar):

```
lead_time_threshold =
    percentile_75( items.lead_time_days WHERE is_active AND lead_time_days > 0 )
    fallback 14   (bila jumlah item memenuhi < 4)

median_deficit_tidak_aman =
    median( deficit of all items WHERE status == "TIDAK_AMAN" in current scope )
    → scope = global; bila UI memfilter warehouse, dihitung per-warehouse
    → bila tidak ada item TIDAK_AMAN: 0
```

- `percentile_75`: metode *linear interpolation* (numpy `linear` / Excel `PERCENTILE.INC`), agar
  paritas dengan Streamlit.
- Nilai parameter disimpan di `inventory_analysis_runs` (id, scope, lead_time_threshold,
  median_deficit, item_count, tidak_aman_count, computed_at) untuk audit & reproducibility.

---

## 4. Projected Stock & warning (brief §E)

Dipakai saat **membuat / mereview Material Request** dan **PPB**:

```
projected_stock(item, warehouse, requested_qty) = available - requested_qty

below_safety = projected_stock < safety_stock
```

Response API menyertakan, per baris request:

```json
{
  "item_id": 123,
  "actual": 7, "reserved": 0, "available": 7,
  "safety_stock": 5, "lead_time_days": 7,
  "requested_qty": 5,
  "projected_stock": 2,
  "below_safety": true,
  "warning": "Request ini akan menyebabkan stok berada di bawah Safety Stock."
}
```

- `warning` non-null hanya bila `below_safety`.
- Projected stock **tidak** memblokir submit (hanya peringatan) — kecuali `projected_stock < 0`
  (stok tak cukup) → baris otomatis diarahkan ke jalur `NEED_PURCHASE` saat review.

---

## 5. Rekomendasi (sementara — minta `calc_engine.py` lama untuk paritas, A4)

```
if status == "AMAN":
    "Stok aman. Tidak perlu tindakan."
if status == "BEP":
    "Stok habis (BEP) — tidak perlu dibeli: tidak ada pemakaian, safety stock nol."
    # BEP = "Stok Habis": tidak dihitung sebagai kekurangan / perlu dibeli (keputusan user 28/09/2026)
if status == "TIDAK_AMAN":
    qty_saran = ceil(deficit + safety_stock - available)      # = deficit (karena available<=SS)
    if priority_level == "HIGH":
        "PRIORITAS TINGGI — buat PPB segera sejumlah {deficit} {uom}. Lead time {lead_time} hari."
    elif priority_level == "MEDIUM":
        "Buat PPB sejumlah {deficit} {uom} dalam waktu dekat."
```

Field `recommendation` (string) + `recommended_qty` (decimal) ikut di snapshot & API.

---

## 6. Inventory Engine — aturan pergerakan stok (brief §D, §P; ATURAN MUTLAK 5–11)

Semua perubahan stok **wajib** lewat `StockLedgerService` dalam **satu DB transaction**, menulis
`stock_movements` + meng-update `inventory` (baca `actual_before/after`, `reserved_before/after`).

| Event bisnis | movement_type | Efek |
|--------------|---------------|------|
| Opening balance (go-live / migrasi) | `OPENING_BALANCE` | actual += qty |
| Request di-approve & stok cukup | `RESERVATION` | reserved += qty (actual tetap) |
| Request dibatalkan / reservation expired | `RELEASE_RESERVATION` | reserved -= qty |
| Barang benar-benar diambil (NPBG `PICKED_UP`) | `STOCK_OUT` | actual -= qty, reserved -= qty |
| Penjualan langsung tanpa reserve (bila diizinkan) | `STOCK_OUT` | actual -= qty |
| RI dari PO `CONFIRMED` | `RECEIVING` | actual += qty_accepted |
| RI retur (pinjam/STPP/bekas) `CONFIRMED` & `into_stock` | `RETURN` | actual += qty |
| Hasil manufaktur `CONFIRMED` | `STOCK_IN` | actual += qty |
| Stock Opname `APPROVED` dgn selisih | `STOCK_ADJUSTMENT` | actual = physical_qty |
| Transfer antar-warehouse | `TRANSFER_OUT` / `TRANSFER_IN` | actual -/+ qty |

Invarian yang di-*assert* setiap transaksi:
- `actual_after >= 0`, `reserved_after >= 0`, `reserved_after <= actual_after`.
- `SUM(stock_movements.signed_qty)` per (item,warehouse) == `inventory.actual_qty` (job rekonsiliasi harian).
- Request **tidak pernah** menyentuh `actual` (ATURAN MUTLAK 6).
- Tidak ada `STOCK_IN` sebelum RI `CONFIRMED` (ATURAN MUTLAK 8).
- `STOCK_ADJUSTMENT` hanya setelah opname `APPROVED` (ATURAN MUTLAK 9) & item punya `note` bila selisih ≠ 0 (ATURAN MUTLAK 10).

---

## 7. Test cases (brief §AM, §E) — untuk `tests/Unit/StockwiseEngineTest.php` (Pest)

| ID | actual | reserved | SS | LT | requested | Expected |
|----|-------:|---------:|---:|---:|----------:|----------|
| TC-INV-001 | 100 | 0 | 50 | 7 | — | selisih 50, AMAN, deficit 0, score 0, level LOW |
| TC-INV-002 | 40 | 0 | 50 | 7 | — | selisih −10, TIDAK_AMAN, deficit 10, score = 10·2 + 7 = 27 |
| TC-INV-003 | 0 | 0 | 0 | 5 | — | status BEP, score 0, level LOW |
| TC-INV-004 | 7 | 0 | 5 | 7 | 5 | projected 2 < SS 5 → below_safety true, warning muncul |
| TC-INV-005 | 7 | 3 | 5 | 7 | 3 | available 4, projected 1, below_safety true |
| TC-INV-006 | 10 | 0 | 5 | 30 | — | AMAN (selisih 5) tapi cek score 0; level LOW |
| TC-INV-007 (level) | dataset 5 item TIDAK_AMAN deficit [2,4,10,10,50], LT threshold 20 | | | | | item deficit 10 → HIGH (≥ median 10); item deficit 4, LT 25 → HIGH (LT≥threshold); item deficit 2, LT 5 → MEDIUM |
| TC-INV-008 | percentile: LT list [3,5,7,14,30] | | | | | threshold_75 = 14 |
| TC-INV-009 | LT list [7,7] (< 4 item) | | | | | threshold = fallback 14 |

Property test: untuk sembarang input non-negatif, `deficit >= 0`, `score >= 0`,
`status == AMAN ⟹ score == 0`, `status == BEP ⟹ available == 0 && SS == 0`.
