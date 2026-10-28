# RENCANA MIGRASI — STOCKWISE ke Server Produksi

**Server:** DESKTOP-CMEG5CR (192.168.2.41)
**Tanggal:** 22 September 2026
**Fase:** COMPATIBILITY CHECK + MIGRATION PLAN
**Status:** ⏳ WAITING APPROVAL — belum ada instalasi apa pun yang dilakukan

---

## 1. RINGKASAN

Repository resmi (`origin/main`, commit `c41a5c4`) sudah di-clone ke `D:\01.STOCKWISE` dan diinspeksi. Proyek ini **sudah dirancang secara eksplisit untuk server ini** — ditemukan `docs/deployment-single-server.md` yang ditulis khusus menyebut IP, service, dan port server produksi ini. Rencana migrasi ini **mengadopsi dokumen tersebut sebagai basis**, divalidasi ulang terhadap hasil audit nyata, dan disesuaikan di titik-titik yang perlu keputusan (lihat bagian 5).

**Kabar baik dari inspeksi arsitektur:** desain sync sudah menerapkan seluruh aturan keselamatan yang sama dengan brief ini — tidak pernah baca `GUDANGSIG2025.GDB` langsung, filter `GUDANGSIG2025*.gbk` wajib, Firebird staging terpisah, port staging 3050 (bukan 3051/3052), Agent tidak pernah konek MySQL langsung, dan `SYNC_TIMES` sudah disesuaikan dengan jadwal auto-shutdown 22:00.

---

## 2. COMPATIBILITY CHECK

| Komponen | Dibutuhkan Project | Kondisi Server Saat Ini | Status |
|---|---|---|---|
| **PHP** | ^8.3 (ext: pdo_mysql, mbstring, fileinfo, curl, zip, gd) | 🔴 Tidak terpasang | **Perlu install** |
| **Composer** | 2.x | 🔴 Tidak terpasang | **Perlu install** |
| **Node.js** | 20+ (hanya untuk build, tidak perlu jalan terus) | 🔴 Tidak terpasang | **Perlu install** |
| **npm** | Bundled dengan Node | 🔴 Tidak terpasang | **Perlu install** |
| **MySQL** | 8.x Server (Community) | 🔴 Tidak terpasang | **Perlu install** |
| **Python** | 3.11+ | 🔴 Tidak terpasang (hanya stub Microsoft Store 0 byte) | **Perlu install** |
| **Firebird (staging)** | 2.5.x instance terpisah dari produksi | 🔴 Tidak ada — dokumen asli mengasumsikan copy dari "PC dev" yang tidak dapat kita akses | **Perlu keputusan (lihat 5.1)** |
| **Git** | Untuk clone/update | 🟢 Ada, 2.55.0 — tapi tidak di PATH | Cukup pakai path lengkap |

**Kesimpulan:** Tidak ada konflik versi — ini adalah server **bersih tanpa stack web sebelumnya**, jadi tidak ada risiko downgrade/upgrade yang bentrok dengan software lain. Namun **seluruh runtime backend/frontend/agent harus diinstal dari nol**, yang merupakan perubahan besar pada server dan **wajib dilakukan bertahap dengan persetujuan eksplisit di setiap kelompok instalasi**.

---

## 3. ARSITEKTUR FINAL (Single Server, Terisolasi)

```
ACCURATE 5 (existing, TIDAK DISENTUH)
   └─ FirebirdServer25 (service existing) ── port 3051 ── D:\GUDANGSIG2025.GDB
   └─ Automatic .GBK backup ──> D:\GUDANGSIG2025*.gbk

STOCKWISE (baru, terisolasi)
   └─ Firebird STOCKWISE (proses baru, BUKAN service, BUKAN FirebirdServer25) ── port 3050 (127.0.0.1 saja)
        └─ Staging DB: D:\01.STOCKWISE\Staging\STAGING.GDB
   └─ Sync Agent (Python, dijadwalkan via Task Scheduler 04:00/10:30/20:30, TIDAK loop terus-menerus)
        scan .GBK ber-pola GUDANGSIG2025*.gbk → cek stabil (2x, jeda 120 detik) →
        gbak -r ke staging → baca via Firebird lokal → POST HTTPS ke Laravel
   └─ MySQL 8 (service baru) ── port 3306 (127.0.0.1 saja, TIDAK expose ke LAN)
        └─ Database: stockwise, user: stockwise (bukan root)
   └─ Laravel API (backend/) ── port 8001 (dibuka ke LAN untuk akses user kantor)
   └─ React frontend (build statis, disajikan oleh Laravel)
```

**Prinsip isolasi (tidak berubah dari brief):**
- Firebird STOCKWISE = instance kedua yang berdiri sendiri, port berbeda, security database berbeda, **tidak pernah** menyentuh `FirebirdServer25` atau `GUDANGSIG2025.GDB`.
- MySQL & Firebird staging **hanya** bind `127.0.0.1` — tidak pernah diakses dari LAN.
- Hanya port Laravel (8001) yang dibuka ke jaringan kantor.

---

## 4. URUTAN INSTALASI YANG DIUSULKAN

Setiap kelompok berikut akan **saya minta persetujuan terpisah** sebelum dijalankan, dan diikuti health check produksi (Accurate/Firebird/File Sharing) sesudahnya. Tidak ada kelompok yang dijalankan tanpa konfirmasi Anda.

| # | Kelompok | Tindakan | Menyentuh produksi? |
|---|---|---|---|
| 1 | **Runtime dasar** | Install PHP 8.3+, Composer 2, Node.js 20+, Python 3.11+ | Tidak — instalasi software baru, tidak ada service existing yang disentuh |
| 2 | **MySQL** | Install MySQL 8 Community Server sebagai service baru, bind `127.0.0.1:3306`, buat database `stockwise` + user `stockwise` | Tidak — service baru, port baru, tidak berinteraksi dengan Firebird/Accurate |
| 3 | **Firebird staging STOCKWISE** | Siapkan instance Firebird 2.5 kedua (lihat opsi di 5.1), jalankan sebagai **proses manual, bukan service**, port 3050, buat user `stockwise_agent` khusus | Tidak — proses terpisah, port berbeda, tidak menyentuh `FirebirdServer25`/PID 3552 |
| 4 | **Backend Laravel** | `composer install --no-dev`, konfigurasi `.env` production, `migrate --seed`, generate agent token | Tidak — hanya menulis ke `D:\01.STOCKWISE`, port 8001 belum dibuka ke LAN di tahap ini |
| 5 | **Frontend React** | `npm install`, `npm run build`, sajikan hasil `dist/` lewat Laravel | Tidak — build statis, tidak ada service tambahan |
| 6 | **Firewall** | Buka **hanya** port 8001 inbound untuk Laravel | Berpotensi — perubahan firewall, wajib persetujuan eksplisit meski scope sempit |
| 7 | **Sync Agent (dry-run)** | `python -m agent.run --dry-run` (scan saja, tanpa restore/kirim) | Tidak — read-only terhadap `.GBK`, tidak restore apa pun |
| 8 | **Sync Agent (real test)** | `python -m agent.run --sync-now` dengan `.GBK` asli kantor (`GUDANGSIG2025*.gbk`), lalu verifikasi hasil di UI | Tidak langsung, tapi **wajib health check Accurate sebelum & sesudah** karena ini uji end-to-end pertama |
| 9 | **Task Scheduler untuk Agent** | 3 task terjadwal (04:00, 10:30, 20:30) menjalankan `agent.run --sync-now` | Tidak — task baru, terpisah dari task `auto shutdown` existing yang **tidak diubah** |
| 10 | **Laravel jalan permanen** | Task Scheduler trigger "at startup" untuk `php artisan serve` (atau alternatif lebih robust seperti NSSM, dibahas terpisah bila diperlukan) | Tidak — task baru |

---

## 5. KEPUTUSAN YANG PERLU DIBAHAS DULU

### 5.1 Sumber instalasi Firebird staging

Dokumen asli (`deployment-single-server.md`) mengasumsikan **copy folder `D:\FirebirdLocal\Firebird_25\` dari PC development** yang sudah terbukti jalan. Server produksi ini **tidak punya akses ke PC dev tersebut**. Opsi yang tersedia:

- **Opsi A (direkomendasikan):** Download installer/zip Firebird 2.5.x resmi dari `firebirdsql.org`, ekstrak sebagai instalasi **standalone/zip** (bukan installer penuh) agar **tidak otomatis membuat Windows Service kedua** — dijalankan manual sebagai proses biasa sesuai rencana.
- **Opsi B:** Bila Anda punya cara memindahkan file dari PC dev (USB/network share) ke server ini, copy folder tersebut langsung — menghindari kebutuhan akses internet ke situs eksternal.

Ini butuh akses internet keluar (HTTPS) dari server produksi bila Opsi A dipilih — mohon konfirmasi apakah server ini boleh mengakses internet untuk keperluan ini.

### 5.2 Kredensial baru yang akan dibuat

Sesuai kebijakan brief, saya **tidak akan meminta atau menggunakan** kredensial Accurate/Firebird produksi. Kredensial berikut akan **dibuat baru** khusus STOCKWISE (Anda tentukan passwordnya saat instalasi, tidak saya hardcode):

- User Firebird staging: `stockwise_agent` (bukan SYSDBA — dokumen mencatat collision karena backup `GUDANGSIG2025` sendiri punya SQL ROLE bernama SYSDBA)
- User MySQL: `stockwise`
- `API_TOKEN` untuk Agent: digenerate oleh `php artisan stockwise:agent-token`

### 5.3 Jendela waktu instalasi

Mengingat 20 user aktif dengan 133 koneksi saat audit, saya sarankan seluruh instalasi (langkah 1–6) dilakukan **di luar jam kerja**, dan langkah 8 (uji sync nyata) dilakukan saat beban Accurate rendah. **Mohon konfirmasi jam yang tersedia.**

### 5.4 Cara menjalankan Laravel secara permanen

Dokumen asli menyebut ada dua opsi: Task Scheduler sederhana (trigger at startup) atau NSSM (auto-restart bila crash, lebih robust). **Keputusan ini akan saya tanyakan lagi saat sampai langkah 10** — tidak perlu diputuskan sekarang.

---

## 6. RISIKO YANG SUDAH DIIDENTIFIKASI (dari audit) DAN MITIGASINYA DI RENCANA INI

| Risiko dari audit | Mitigasi di rencana ini |
|---|---|
| Port 3051/3052 rapuh, 133 koneksi aktif | Firebird staging pakai port 3050, tidak pernah menyentuh PID 3552 |
| Filter `*.gbk` akan memproses 405 file lain | `BACKUP_PATTERN=GUDANGSIG2025*.gbk` sudah hardcoded di `.env.example`, bukan sekadar rekomendasi |
| Task `auto shutdown` jam 22:00 | `SYNC_TIMES=04:00,10:30,20:30` sudah dirancang menghindari jam tsb; Agent **tidak** dijadikan proses yang harus selalu hidup |
| CPU 4-core terbatas | Node.js hanya untuk build (tidak jalan terus); Agent jalan 3x/hari via Task Scheduler (tidak loop terus-menerus), bukan resident process |
| Firewall Private nonaktif → risiko expose LAN | MySQL & Firebird staging **hanya** bind 127.0.0.1; firewall rule dibuat eksplisit hanya untuk port 8001 |
| 21 database `.GDB` lain di server yang sama | Tidak ada langkah yang menyentuh path `D:\` selain filter baca `.gbk` bernama Gudang |

---

## 7. LAPORAN FASE

**STATUS:** ⏳ **WAITING APPROVAL**

**YANG DIPERIKSA:** Struktur repository (526 file), `README.md`, `docs/sync-architecture.md`, `docs/setup-accurate-connector.md`, `docs/deployment-single-server.md`, `sync-service/.env.example`, `backend/.env.example`, `backend/composer.json`, `frontend/package.json`, `sync-service/requirements.txt`.

**HASIL:** Tidak ada konflik versi. Server bersih dari stack web sebelumnya. Arsitektur project sudah secara native menerapkan seluruh prinsip keselamatan produksi yang sama dengan brief ini. Ditemukan dokumen deployment yang sudah ditulis spesifik untuk server ini.

**PERUBAHAN:** File baru ditambahkan ke repo (tidak menimpa apa pun): `docs/server-audit-report.md`, `docs/baseline-pre-deployment.json`, `docs/server-migration-plan.md`. Tidak ada perubahan pada sistem produksi.

**YANG TIDAK BERUBAH:** Accurate, Firebird produksi, `GUDANGSIG2025.GDB`, seluruh service/port existing.

**HEALTH CHECK:** Tidak ada perubahan sejak health check pasca-clone sebelumnya (Accurate PID 3516 Running, Firebird PID 3552 Running/port 3051 listening, database 133.881.856 byte).

**RISIKO:** Lihat bagian 6. Risiko tertinggi tetap seputar beban produksi aktif dan port Firebird — keduanya sudah dimitigasi oleh desain isolasi di rencana ini.

**TINDAKAN BERIKUTNYA:** Menunggu persetujuan Anda untuk:
1. Keputusan sumber Firebird staging (5.1 — Opsi A atau B)
2. Jendela waktu instalasi (5.3)
3. Izin memulai **Kelompok 1 (Runtime dasar: PHP, Composer, Node.js, Python)** — instalasi pertama yang akan dieksekusi

Tidak ada instalasi yang akan dimulai sebelum Anda menjawab.
