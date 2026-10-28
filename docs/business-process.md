# STOCKWISE — Target Business Process (to-be)

> Proses existing (as-is) ada di [step1-analysis.md §5](step1-analysis.md). Dokumen ini = alur yang
> akan dibangun. Prinsip: backend sumber kebenaran, semua pergerakan stok transactional & ber-history,
> stok berkurang saat **pickup**, bertambah saat **receiving confirmed**, opname ubah stok hanya
> setelah **admin approve** (ATURAN MUTLAK 5–12).

## BP-1 Permintaan Barang (Request → Reserve/Purchase)

```
Karyawan (SDA/BPN)                Admin/Anak Gudang                 Lapangan Gudang
──────────────────               ─────────────────                ────────────────
buat Request ──────────────────► review tiap baris:
 • pilih barang / ketik           • sistem tampilkan available,
 • qty, keperluan, divisi,          safety stock, projected stock,
   lokasi kerja                     WARNING bila proj < SS
 • submit                         • cek fisik: barang benar ada &
                                    jumlah sesuai sistem? (VERIFIED_MATCH/MISMATCH)
                                  • tentukan per baris:
                                    - stok cukup  → READY
                                    - sebagian    → PARTIAL
                                    - kurang      → NEED_PURCHASE ──► PP­B (BP-3)
                                  • reserve baris READY (RESERVATION, actual TETAP)
                                          │
                                  buat NPBG (BP-2) ◄────────────────┘
```
- MISMATCH saat cek fisik → picu usul Stock Opname untuk item itu; baris tidak bisa di-reserve
  sampai selisih jelas.
- Request BPN: barang disiapkan & keluar dari gudang SDA (A5).

## BP-2 Pengeluaran Barang (NPBG → Pickup → Stock Out)

```
NPBG dibuat (dari Request RESERVED/PARTIAL, atau manual Admin utk pemakaian UMUM)
  → status PREPARING
Lapangan Gudang siapkan barang fisik → READY_TO_PICKUP  → notif Karyawan
Karyawan datang → Lapangan Gudang verifikasi: identitas peminta, barang, qty
  → Karyawan tanda tangan → confirm pickup
  → status PICKED_UP → STOCK_OUT (actual −, reserved −), reservation CONSUMED
  → semua item keluar → COMPLETED
  → bila NPBG.classification ≠ UMUM → baris modul tracking terkait dibuat (STPP/Lend/Ban/Maintenance/…)
```

## BP-3 Pengadaan (PPB → RFQ → PO → RI → Stock In)

```
Admin Gudang            Purchasing                         Gudang
───────────             ──────────                         ──────
PPB dari NEED_PURCHASE ► review PPB (lihat shortage,
 (atau PPB manual)        safety stock, deficit,
                          priority score & level)
                        ► approve
                        ► (opsional) RFQ ke vendor → quote → pilih
                        ► buat PO ke vendor → approve (≤ limit / BOS) → SENT
                        ► barang datang ──────────────────────────► buat RI
                                                                   cek qty & kondisi per baris
                                                                   ► CONFIRMED
                                                                   ► RECEIVING movement (actual +)
                        ► PO PARTIAL_RECEIVED / RECEIVED
                        ► PPB RECEIVED → COMPLETED
                        ► Request NEED_PURCHASE dinilai ulang → RESERVED → lanjut BP-2
```
Stok **tidak** bertambah sebelum RI `CONFIRMED`.

## BP-4 Stock Opname (Physical Count → Validasi → Adjustment)

```
Admin Gudang jadwalkan opname (gudang, tanggal)
Anak Gudang: START → input physical_qty per baris (system_qty ditampilkan)
  • physical ≠ system → note WAJIB
  • physical = system → note opsional
  → SUBMIT (ditolak bila ada selisih tanpa note)
Admin Gudang review: lihat system vs physical, difference, note, petugas, tanggal, gudang
  → per baris: APPROVED / REJECTED / RECOUNT_REQUIRED
  → APPROVED + Δ≠0 → stock_adjustment + STOCK_ADJUSTMENT movement, actual = physical
  → opname COMPLETED
```
Anak Gudang tidak pernah mengubah actual stock langsung.

## BP-5 Modul tracking (menempel pada NPBG/RI)

| Modul | Trigger keluar | Trigger balik |
|---|---|---|
| STPP | NPBG classification=STPP → alat ber-serial ke divisi (ACTIVE) | RI penarikan → PASSIVE |
| Lend | NPBG classification=LEND/BORROW, keperluan pinjam keluar | RI kembali → RETURNED (due_date lewat → OVERDUE) |
| Borrow | terima pinjaman dari vendor (RI) | NPBG saat dikembalikan |
| Ban Luar | NPBG ban baru → pasang di Nopol (PENDING_RI) | RI ban lama masuk → CLEAR |
| Maintenance Assets | SPK + Sub SPK → spare part keluar via NPBG per sub | sub COMPLETED; (opsional RI part sisa) |
| Manufaktur & Assembly | MA + Sub → material keluar via NPBG | RI produk jadi (serial SIG-xx) CONFIRMED → STOCK_IN |
| Manufaktur & Jasa | MJ + Sub → material keluar, dikerjakan vendor | RI hasil jasa CONFIRMED |
| Pengembalian Bekas | (barang bekas dari pemakaian) | RI bekas CONFIRMED → RETURN (bila reusable) / buang |

## BP-6 Notifikasi (brief §AA)

| Kejadian | Penerima |
|---|---|
| Request submitted | Admin Gudang |
| Request approved / rejected / ready pickup / completed | Karyawan pembuat |
| PPB baru / high-priority PPB | Purchasing |
| Stock opname pending review | Admin Gudang |
| Item high priority (analysis) | Admin Gudang |
| NPBG baru / barang harus disiapkan | Lapangan Gudang |
| Pickup selesai | Karyawan + Admin Gudang |
| PO overdue / receiving pending | Purchasing |

## BP-7 Audit (brief §Z)

Setiap aksi tulis penting → `audit_logs` (user, action, module, reference_no, before, after, ip).
Contoh: `ADMIN GUDANG · APPROVE STOCK OPNAME · SO-00123 · before {qty:100} after {qty:95} · note "5 PCS rusak"`.
