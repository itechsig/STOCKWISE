# LAPORAN DEPLOYMENT — STOCKWISE di Server Produksi

**Server:** DESKTOP-CMEG5CR (192.168.2.41)
**Update terakhir:** 22 September 2026, 15:10
**Status keseluruhan:** 🟢 IN PROGRESS — 2 dari 10 kelompok selesai, produksi tidak terganggu

---

## Progres per Kelompok (lihat `docs/server-migration-plan.md` untuk definisi lengkap)

| # | Kelompok | Status | Catatan |
|---|---|---|---|
| 1 | Runtime Dasar (PHP/Composer/Node/Python) | ✅ **SELESAI** | Lihat detail di bawah |
| 2 | MySQL 8 | ✅ **SELESAI** | Lihat detail di bawah |
| 3 | Firebird staging STOCKWISE | ✅ **SELESAI** | Lihat detail di bawah |
| 4 | Backend Laravel | ✅ **SELESAI** | Lihat detail — import Excel item TERTUNDA, butuh file sumber |
| 5 | Frontend React | ✅ **SELESAI** | `npm install` + `npm run build` sukses |
| 6 | Firewall (port 8001) | ✅ **SELESAI** | Diverifikasi HTTP 200 dari LAN |
| 7 | Sync Agent (dry-run) | ✅ **SELESAI** | Lihat detail — filter backup terkonfirmasi benar |
| 8 | Sync Agent (real test) | ✅ **SELESAI (PARTIAL)** | 28.150/30.697 baris berhasil (92%) — lihat detail |
| 8 | Sync Agent (real test) | ⏳ Belum dimulai | |
| 9 | Task Scheduler Agent | ⏳ Belum dimulai | |
| 10 | Laravel jalan permanen | ⏳ Belum dimulai | |

---

## Kelompok 1 — Runtime Dasar ✅

| Tool | Versi | Lokasi |
|---|---|---|
| PHP | 8.3.33 | `C:\Users\server-sig\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_...\php.exe` (via winget, portable) |
| Composer | 2.10.3 | `C:\DevTools\composer.phar` + wrapper `C:\DevTools\composer.bat` |
| Node.js | v24.19.0 (LTS) | `C:\Program Files\nodejs\` |
| npm | 11.17.0 | bundled Node.js |
| Python | 3.11.9 | `C:\Users\server-sig\AppData\Local\Programs\Python\Python311\` |

**Kendala & solusi:**
- Installer MSI (Node.js, Python) macet karena sesi awal tidak Administrator dan UAC tidak bisa tampil di sesi non-interaktif → diselesaikan setelah sesi di-restart sebagai Administrator.
- PHP baru tidak punya `php.ini` aktif → dibuat dari template `php.ini-production`, ekstensi diaktifkan: `openssl, curl, mbstring, fileinfo, zip, gd, pdo_mysql, mysqli`.
- PHP tidak punya CA certificate bundle (HTTPS gagal) → `cacert.pem` diunduh dari curl.se, di-set di `curl.cainfo`/`openssl.cafile`.
- `composer-setup.php` resmi macet (bukan masalah jaringan, script installernya sendiri) → diganti unduh `composer.phar` langsung.
- PATH sistem tidak diubah (diblokir kebijakan otomatis) — `composer` dipanggil via path lengkap `C:\DevTools\composer.bat`.
- Bandwidth kantor sangat lambat & fluktuatif (~0.5–8 KB/s) saat mengunduh dari `getcomposer.org`/`downloads.php.net` — total ±20 menit untuk file berukuran beberapa MB.

## Kelompok 2 — MySQL 8 ✅

| Item | Detail |
|---|---|
| Versi | MySQL 8.4.9 Community Server (winget `Oracle.MySQL`) |
| Service | `MySQL84`, Running, Automatic |
| Bind | `127.0.0.1:3306` saja — **tidak ada firewall rule**, tidak reachable dari LAN (diverifikasi) |
| Config | `C:\Program Files\MySQL\MySQL Server 8.4\my.ini` — `innodb_buffer_pool_size=512M`, `max_connections=100` |
| Data dir | `C:\ProgramData\MySQL\MySQL Server 8.4\Data` |
| Database | `stockwise`, `stockwise_test` (utf8mb4_unicode_ci) |
| User app | `stockwise@localhost` — akses terbatas hanya ke 2 database miliknya (diverifikasi isolasi) |
| Root | Diamankan dengan password acak (awalnya `--initialize-insecure`, langsung di-`ALTER USER`) |
| Kredensial | `C:\01.STOCKWISE-Secrets\mysql-credentials.txt` — **di luar folder git repo**, tidak akan ter-commit |

**Kendala & solusi:**
- Instalasi silent MSI via winget **tidak otomatis** membuat data dir/service/password (berbeda dari installer wizard biasa) → dikonfigurasi manual: `mysqld --initialize-insecure` → `mysqld --install` → `Start-Service` → set password root → buat database & user.
- Download installer MySQL dari CDN `cdn.mysql.com` ternyata jauh lebih cepat dari CDN lain yang dicoba sebelumnya — selesai dalam hitungan menit, bukan berjam-jam seperti yang dikhawatirkan.

## Kelompok 3 — Firebird Staging STOCKWISE ✅

| Item | Detail |
|---|---|
| Versi | Firebird 2.5.9.27139-0 (x64 zip resmi dari `github.com/FirebirdSQL/firebird`, bukan installer) |
| Lokasi | `D:\FirebirdLocal\Firebird_25\` |
| Mode | Proses manual (`fb_inet_server.exe -a`), **bukan Windows Service** — sesuai rencana |
| Port | **3050**, bind `127.0.0.1` saja (di-set eksplisit di `firebird.conf`: `RemoteServicePort`, `RemoteBindAddress`) |
| Instance produksi | `FirebirdServer25` (PID 3552, port 3051) — **tidak disentuh sama sekali**, berjalan berdampingan sebagai proses terpisah |
| User staging | `stockwise_agent` (dibuat via `gsec`, bukan SYSDBA) |
| Database staging | `D:\01.STOCKWISE\Staging\STAGING.GDB` — dibuat & dimiliki oleh `stockwise_agent` |
| Struktur folder | `D:\01.STOCKWISE\{Staging,Config,Logs,Backup}\` dibuat sesuai `docs/deployment-single-server.md` |
| Kredensial | `C:\01.STOCKWISE-Secrets\credentials.txt` (di luar repo git) |

**Kendala & solusi:**
- `gsec`/`isql` gagal dengan "unable to open database" saat memakai path relatif (`security2.fdb`) — harus pakai **path absolut lengkap** (`127.0.0.1/3050:D:\FirebirdLocal\Firebird_25\security2.fdb`) karena proses dijalankan manual (bukan service dengan `RootDirectory` terdaftar).
- Firebird 2.5 legacy auth **hanya memakai 8 byte pertama password** — dicatat eksplisit di file kredensial agar tidak membingungkan saat konfigurasi `sync-service/.env` nanti.
- Firebird membatasi akses SQL langsung ke `security2.fdb` (harus lewat `gsec`) — ini perilaku normal Firebird, bukan bug.

**Catatan penting:** Proses `fb_inet_server.exe -a` untuk staging ini **berjalan di sesi PowerShell saat ini** — akan berhenti jika sesi ditutup atau server reboot. Ini sesuai rencana untuk tahap testing (`docs/deployment-single-server.md` §6: baru dijadikan Task Scheduler/service permanen setelah pipeline tervalidasi end-to-end).

---

## Kelompok 4 — Backend Laravel ✅ (dengan 1 catatan tertunda)

| Item | Detail |
|---|---|
| PHP produksi Laravel | **8.4.25** (bukan 8.3) — lihat "Insiden Compatibility" di bawah |
| `composer install --no-dev` | 82 paket terpasang, persis sesuai `composer.lock` (tidak ada drift versi) |
| `.env` | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=http://192.168.2.41:8001`, DB ke MySQL `stockwise`/`stockwise` |
| `APP_KEY` | Digenerate |
| Migration | **Seluruh migration sukses** (puluhan tabel, dari `0001_01_01` s.d. `2026_09_22`) |
| Seeding | SiteSeeder, **RbacSeeder** (163 permission, 7 role, 453 mapping — 212 detik), **UserSeeder** (12 akun sesuai README), TrackingSeeder — semua DONE |
| Routes | **177 route API terdaftar**, tidak ada error |
| Agent token | Digenerate via `stockwise:agent-token`, disimpan di `C:\01.STOCKWISE-Secrets\credentials.txt` |

### ⚠️ Insiden Compatibility — PHP 8.3 vs 8.4

`composer.lock` (dibuat developer di PHP 8.4.23) mengunci Symfony 8.x yang butuh **PHP ≥8.4.1**, sedangkan server baru punya PHP 8.3.33. Sesuai prosedur (jangan asal upgrade/downgrade), saya **STOP dan lapor** ke user sebelum bertindak. User memilih **install PHP 8.4 tambahan** (bukan `composer update`) agar kombinasi dependency persis sama dengan yang sudah diuji developer. PHP 8.4.25 terpasang berdampingan dengan 8.3 (tidak saling mengganggu), dikonfigurasi ulang (ekstensi + CA cert) — backend Laravel memakai 8.4, sisanya (Composer CLI, dll) tetap fleksibel pakai versi manapun.

### ⚠️ TERTUNDA — Import Data Excel (8.957 item)

Job `stockwise:import` (dipanggil otomatis oleh `migrate --seed`) **tidak menemukan file sumber Excel** (`DATA.xlsx`, `1. PPB - RI.xlsx`, `2. NPBG.xlsx`, dll) di `STOCKWISE_IMPORT_PATH` (default: root repo). Hasil:

| Target | Read | Created |
|---|---|---|
| departments | 14 | 13 (dari kode kanonik, bukan Excel) |
| units | 27 | 27 (kode kanonik) |
| categories, warehouses, **items**, item_safety_stocks | 0 | 0 |

**Master barang (8.957 item) belum ter-import.** `DemoSeeder` otomatis dilewati karena bergantung pada data ini.

**Keputusan user (22 Sep 2026):** Excel **bukan** sumber permanen untuk master Item — file tersebut memang belum tersedia di server ini. Master Item akan mengalir lewat **pipeline resmi STOCKWISE**: Accurate 5 → `.GBK` → Firebird staging → extraction → Laravel API → MySQL (Kelompok 7-8, sesuai `docs/sync-architecture.md`). Sampai pipeline itu aktif:

- ❌ **JANGAN** membuat/mengarang/mengganti data item, kategori, atau gudang secara manual.
- ❌ **JANGAN** menyentuh data `departments`/`units` yang sudah berhasil di-seed.
- ✅ Item/kategori/gudang **dibiarkan kosong** — ini kondisi yang disengaja, bukan bug.
- ✅ Lanjutkan hanya ke tahap yang tidak bergantung pada file Excel (Frontend, lalu tahap-tahap lain yang tidak butuh master Item).

---

## Kelompok 5 — Frontend React ✅

| Item | Detail |
|---|---|
| `npm install` | 345 paket, 0 vulnerabilities, ~1 menit |
| `.env` | `VITE_API_URL=http://192.168.2.41:8001/api` |
| `npm run build` | 3441 modul, 32.44 detik, output ke `dist/` |
| Catatan build | Warning ukuran chunk JS >500KB (1.1MB) — kosmetik, tidak menghambat, bisa dioptimasi nanti dengan code-splitting |

**Kendala & solusi:** `node` belum ter-refresh di PATH shell setelah instalasi winget sebelumnya (`npm run build` gagal "node is not recognized") → ditambahkan ke PATH sesi ini saja, **tidak mengubah PATH sistem**.

**Verifikasi data master tidak tersentuh (per instruksi user 22 Sep 2026):** `departments`=16 (kanonik, tidak berubah), `units`=27 (tidak berubah), `items`=0 (**sengaja kosong** — menunggu pipeline Accurate resmi, bukan diisi manual/Excel).

---

## Kelompok 6 — Firewall ✅

| Item | Detail |
|---|---|
| Rule dibuat | `STOCKWISE Web` — Inbound, TCP, port **8001**, Allow |
| Total rule firewall | 528 (bertambah tepat 1) |
| Verifikasi akses | ✅ Diuji langsung: `php artisan serve` sementara, HTTP 200 dari `127.0.0.1:8001` **dan** `192.168.2.41:8001` (LAN) |
| Port lain | **Tidak ada rule baru** untuk 3306 (MySQL) atau 3050 (Firebird staging) — keduanya tetap `127.0.0.1` saja |
| Cleanup | Server test dihentikan setelah verifikasi (permanent run adalah Kelompok 10) |

**Catatan:** `php artisan serve` ternyata membuat 2 proses (CLI wrapper + listener child) — keduanya perlu dihentikan terpisah saat cleanup. Dicatat untuk referensi Kelompok 10 nanti (NSSM/Task Scheduler perlu menangani proses child dengan benar).

---

## Kelompok 7 — Sync Agent (Dry-Run) ✅

| Item | Detail |
|---|---|
| `pip install -r requirements.txt` | `fdb`, `pymysql`, `python-dotenv`, `requests` — semua terpasang lancar |
| `sync-service/.env` | `FIREBIRD_HOST=127.0.0.1` (bukan `localhost`, sesuai catatan gotcha DNS Windows di `docs/sync-architecture.md`), `FIREBIRD_USER=stockwise_agent`, `API_URL=http://127.0.0.1:8001/api`, `API_TOKEN` dari Kelompok 4 |
| **Filter backup** | `BACKUP_PATTERN=GUDANGSIG2025*.gbk` — **terverifikasi via eksekusi nyata**, bukan cuma baca config |
| Hasil `--dry-run` | Mendeteksi **1 kandidat**: `GUDANGSIG2025 092026.gbk` (36.7 MB, terbaru) — dari 424 file `.gbk` di `D:\`, tidak ada satu pun file lain yang tersentuh |
| Verifikasi tidak ada perubahan | `LastWriteTime`/`LastAccessTime` file backup **identik** sebelum-sesudah; jumlah file `.gbk` tetap 424; `error.log` kosong |
| Log | `D:\01.STOCKWISE\Logs\agent.log` — tidak mencatat password/token (sesuai kebijakan) |

**Kesimpulan:** Komponen Agent bekerja **persis sesuai desain** — filter backup yang benar, tidak menyentuh file lain, berhenti sebelum restore/kirim data pada mode dry-run.

---

## Health Check Kumulatif (setelah Kelompok 1-7)

| Komponen | Baseline Awal | Sekarang | Status |
|---|---|---|---|
| Accurate LicenseManager | PID 3516, Running | PID 3516, Running | 🟢 Identik |
| Firebird service | Running/Automatic | Running/Automatic | 🟢 Normal |
| Firebird process | PID 3552 | PID 3552 | 🟢 **Identik — tidak pernah di-restart** |
| Firebird port 3051 | Listening | Listening | 🟢 Normal |
| `GUDANGSIG2025.GDB` | 133.881.856 byte | 133.881.856 byte | 🟢 **Tidak berubah** |
| File Sharing (Lanman) | Running | Running | 🟢 Normal |
| Firewall rule baru | — | 0 rule ditambahkan | 🟢 Belum ada perubahan firewall |

**Kesimpulan: Tidak ada dampak apa pun ke sistem produksi selama Kelompok 1 & 2.**

---

## Keputusan User — Master Item via Pipeline Resmi (22 Sep 2026)

File Excel master data **belum tersedia** di server dan **tidak akan dijadikan sumber permanen**. Master Item akan mengalir dari pipeline resmi: **Accurate 5 → `.GBK` → Firebird staging → extraction → Laravel API → MySQL** (Kelompok 7-8). Sampai pipeline itu aktif: item/kategori/gudang **sengaja dibiarkan kosong**, tidak boleh diisi manual/dikarang, dan data `departments`/`units` yang sudah ter-seed tidak boleh diubah. Lanjutan hanya mencakup tahap yang **tidak bergantung pada file Excel**.

## Kelompok 8 — Sync Agent Real Test ✅ (PARTIAL, sesuai keterbatasan yang sudah diketahui)

Setelah 5 percobaan (4 gagal karena `set_time_limit` terlalu rendah untuk hardware ini, dinaikkan bertahap 300→1800→3600→7200 detik), **percobaan ke-5 (batch id=6) berhasil menyelesaikan seluruh 8 tahap** `finishFromStaging()`: item → npbg → stpp → ppb → po → ri → used_returns → stock_opname.

| Metrik | Jumlah |
|---|---|
| Total baris diproses | 30.697 |
| Berhasil insert | 11.939 |
| Sudah sesuai (skip, idempoten) | 16.211 |
| **Error** | 2.547 — **100% di `stock_opname`**, root cause: `warehouse_id` NULL |

**Root cause error (bukan bug baru):** Sesuai `docs/sync-architecture.md` — Accurate's `WAREHS` cuma 1 baris generik ("CENTRE"), tidak bisa dipetakan ke 7 gudang fisik STOCKWISE. Modul `stock_opname` gagal insert karena butuh `warehouse_id` valid. **Item, NPBG, STPP, PPB, PO, RI, UsedReturn semuanya berhasil** (28.150/30.697 baris, 92%).

**Data akhir tersimpan:** 9.204 item, 8.642 NPBG, 196 STPP, 1.022 UsedReturn.

**Bug lain yang ditemukan & diperbaiki selama proses ini:**
- `sync-service/agent/restore.py`: target `gbak` harus pakai `FirebirdSettings.dsn()` (format `host/port:path`), bukan path lokal — path lokal gagal dengan "user/password not defined" pada instance yang berjalan sebagai proses manual (bukan service).
- Server sempat **reboot otomatis** (24 Sep 2026, 06:06) di luar kendali — PID Accurate/Firebird berubah (baseline baru: Firebird 3596, Accurate 3612), **tidak ada dampak** ke data/service produksi, MySQL84 (service Windows) auto-start normal.

## Tindakan Berikutnya

Menunggu keputusan: (a) terima kondisi `stock_opname` PARTIAL sebagai batasan arsitektur yang sudah didokumentasikan, atau (b) nonaktifkan sync `stock_opname` khusus untuk data Accurate (karena `items.accurate_qty_onhand` sudah jadi sumber kebenaran alternatif sesuai keputusan desain awal). Selanjutnya: setup akses web LAN + serving frontend (diminta user), lalu Kelompok 9-10 (Task Scheduler permanen) bila diperlukan.
