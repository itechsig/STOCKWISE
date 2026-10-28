# LAPORAN AUDIT SERVER PRODUKSI — STOCKWISE

**Tanggal audit:** 22 September 2026
**Server:** DESKTOP-CMEG5CR (192.168.2.41)
**Fase:** 1 — AUDIT READ-ONLY
**Status:** ✅ SAFE — tidak ada perubahan apa pun yang dilakukan pada server

---

## 1. RINGKASAN EKSEKUTIF

Audit read-only telah selesai. Seluruh sistem produksi (Accurate, Firebird, File Sharing, jaringan) dalam kondisi **NORMAL dan SEDANG DIPAKAI AKTIF** oleh ±20 workstation kantor.

Tidak ada satu pun perintah yang mengubah, menulis, me-restart, atau menyentuh sistem produksi. Database `D:\GUDANGSIG2025.GDB` **tidak disentuh** — hanya metadata file (ukuran, tanggal) yang dibaca dari filesystem.

**Kesimpulan utama:** Server ini secara teknis **mampu** menjalankan STOCKWISE, namun **belum memiliki satu pun runtime yang dibutuhkan** (PHP, Composer, Node.js, npm, MySQL). Instalasi tersebut membutuhkan persetujuan eksplisit dan harus dilakukan bertahap dengan health check di setiap langkah.

---

## 2. SPESIFIKASI SERVER

| Item | Nilai |
|---|---|
| OS | Microsoft Windows 10 Pro |
| Versi / Build | 10.0.19045 (Build 19045) |
| Arsitektur | 64-bit |
| Hostname | DESKTOP-CMEG5CR |
| Domain | WORKGROUP |
| Hardware | Hewlett-Packard 400-511x |
| CPU | Intel Core i5-4460 @ 3.20GHz — 4 core / 4 thread |
| RAM Total | 15.94 GB |
| RAM Terpakai | 46% (7.34 GB terpakai, 8.60 GB bebas) |
| CPU Load saat audit | 15% |
| Tanggal instalasi OS | 23 Desember 2023 |
| Terakhir boot | 22 September 2026, 06:07:50 |
| Pending reboot | Tidak ada |
| Antivirus | Windows Defender (aktif) |

### Disk

| Drive | Total | Bebas | % Bebas | Filesystem | Label |
|---|---|---|---|---|---|
| C: | 442.61 GB | 334.72 GB | 75.6% | NTFS | — |
| D: | 488.28 GB | 305.92 GB | 62.7% | NTFS | Data |

**Penilaian:** Kapasitas disk sangat memadai. RAM 16 GB dengan sisa 8.6 GB cukup, namun perlu diperhatikan bahwa Firebird sendiri sudah memakai ~760 MB dan akan bertambah seiring beban kerja Accurate.

**Catatan CPU:** i5-4460 (4 core, tahun 2014) tergolong terbatas. Menjalankan MySQL + PHP-FPM + Node build di atas beban Accurate yang sudah ada perlu dibatasi resource-nya (lihat bagian Risiko).

---

## 3. SISTEM PRODUKSI — STATUS

### 3.1 Accurate 5

| Item | Nilai |
|---|---|
| Executable | `C:\Program Files (x86)\CPSSoft\ACCURATE5 Deluxe\accurate.exe` |
| Versi file | 5.0.20.1868 |
| Ukuran | 30.55 MB |
| Tanggal file | 23 Mei 2018 |
| Process aktif | `AccurateLicenseManager` (PID 3516), RAM 162.3 MB |
| Port License Manager | 6605 (127.0.0.1), 6688 (IPv6) |
| Process pendukung | `aclicfivsvc` (PID 3532), port 34615 |

**Status: 🟢 NORMAL.** License Manager berjalan. Aplikasi `accurate.exe` sendiri tidak berjalan di server ini — server berperan sebagai **database & license server**, sedangkan client menjalankan Accurate dari workstation masing-masing.

### 3.2 Firebird 2.5

| Item | Nilai |
|---|---|
| Service | `FirebirdServer25` — **Running**, StartType **Automatic** |
| Process | `fb_inet_server` (PID 3552), RAM 754–761 MB |
| Install dir | `C:\Program Files\Firebird\Firebird_25` |
| Port konfigurasi | `RemoteServicePort = 3051` (dari `firebird.conf`) |
| Port listening | 3051 pada `0.0.0.0` |
| `aliases.conf` | Kosong (tidak ada alias database terdaftar) |
| Instance lain | Tidak ada — hanya satu instance Firebird |

**Tools Firebird yang tersedia di `bin\`:** `gbak.exe`, `isql.exe`, `fbserver.exe`, `fb_inet_server.exe`, `instsvc.exe`, `instreg.exe` — semuanya ada.

**Status: 🟢 NORMAL.**

### 3.3 ⚠️ TEMUAN PENTING — PORT 3052

Ditemukan kondisi yang perlu dicatat khusus:

- **Port 3052 TIDAK memiliki listener aktif** saat ini.
- Namun terdapat **47 koneksi TCP dalam status ESTABLISHED** pada port lokal 3052, dimiliki oleh process `fb_inet_server` (PID 3552).
- Koneksi berasal dari 20 workstation berbeda di subnet `10.10.20.x` dan `10.10.10.x`.

**Artinya:** Sebelumnya ada listener Firebird pada port 3052 yang kini sudah tidak menerima koneksi baru, tetapi **sesi client lama masih hidup dan masih dipakai**. Ini persis seperti yang diperingatkan dalam instruksi.

**Konsekuensi: Port 3052 adalah RESERVED MUTLAK.** Menggunakan port ini akan memutus 47 sesi Accurate yang sedang aktif. **Tidak boleh disentuh dalam kondisi apa pun.**

### 3.4 Beban Client Aktif

| Port | Koneksi Established | Jumlah workstation |
|---|---|---|
| 3051 | **86** | 20 |
| 3052 | **47** | 20 |
| **Total** | **133** | **20** |

Workstation teridentifikasi: `10.10.10.28`, `10.10.20.30`, `.31`, `.33`, `.34`, `.42`, `.43`, `.44`, `.45`, `.46`, `.47`, `.51`, `.52`, `.53`, `.66`, `.67`, `.68`, `.70`, `.74`, `.76`

**Penilaian:** Server sedang dalam **beban produksi penuh dengan 20 user aktif**. Ini adalah faktor risiko utama — setiap gangguan akan langsung dirasakan seluruh kantor.

### 3.5 Database Accurate Produksi

| Item | Nilai |
|---|---|
| Path | `D:\GUDANGSIG2025.GDB` |
| Ukuran | 127.68 MB (133.881.856 byte) |
| Dibuat | 11 September 2025, 07:58:00 |
| Terakhir diubah | 22 September 2026, 14:00:22 |
| Read-only flag | False |

**Status: 🟢 AKTIF dan sedang ditulis.** Timestamp modified menunjukkan database sedang aktif digunakan saat audit berlangsung.

**Ukuran dan timestamp ini menjadi bagian dari BASELINE.** File ini tidak boleh berubah akibat aktivitas STOCKWISE.

### 3.6 Database Lain di D:\ (jangan tertukar)

Ditemukan **21 file `.GDB`** di root `D:\`, termasuk database non-Gudang:

`ADM GUDANG.GDB`, `CV.SIG PAJAK1.GDB`, `DATA TABUNG SORONG.GDB`, `GUDANG SIG BPP.GDB`, `GUDANG SIG SDA.GDB`, `GUDANG2021.GDB`, **`GUDANGSIG2025.GDB`**, `INTEREN 1833.GDB`, `SIG BPP 1833.GDB`, `SIG NEW 1833.GDB`, `SIG SDA 1833.GDB`, `SORONG.GDB`, `TABUNG BARU 1833 NEW.GDB`, `TABUNG BPP 1835.GDB`, dan lain-lain.

Beberapa di antaranya **juga sedang aktif ditulis hari ini** (`SIG SDA 1833.GDB`, `SIG NEW 1833.GDB`, `TABUNG BARU 1833 NEW.GDB`, `CV.SIG PAJAK1.GDB`, `TABUNG BPP 1835.GDB`, `SIG BPP 1833.GDB`).

**Implikasi:** Server ini melayani **lebih dari satu database Accurate**, bukan hanya Gudang. Ini memperbesar dampak bila terjadi gangguan.

### 3.7 Backup .GBK

| Item | Nilai |
|---|---|
| Total file `.gbk` di `D:\` | **424 file** |
| Total ukuran semua `.gbk` | **98.25 GB** |
| Match pola `GUDANGSIG2025*.gbk` | **19 file** |

**Backup Gudang terbaru:**

| File | Ukuran | Terakhir diubah |
|---|---|---|
| `GUDANGSIG2025 092026.gbk` | 36.71 MB | 21 Sep 2026 16:41:01 |
| `GUDANGSIG2025 Monday.gbk` | 36.71 MB | 21 Sep 2026 16:41:01 |
| `GUDANGSIG2025 Saturday.gbk` | 36.28 MB | 19 Sep 2026 15:54:43 |
| `GUDANGSIG2025 Friday.gbk` | 36.25 MB | 18 Sep 2026 18:25:13 |
| `GUDANGSIG2025 Thursday.gbk` | 36.23 MB | 17 Sep 2026 17:19:26 |
| `GUDANGSIG2025 Wednesday.gbk` | 36.19 MB | 16 Sep 2026 16:06:00 |
| `GUDANGSIG2025 Tuesday.gbk` | 35.42 MB | 15 Sep 2026 16:40:23 |
| `GUDANGSIG2025 082026.gbk` | 34.54 MB | 31 Agu 2026 16:30:16 |

**Konfirmasi kritis:** Dari 424 file `.gbk`, hanya **19 file (4.5%)** yang merupakan backup Gudang. Menggunakan pola `*.gbk` akan memproses **405 file backup milik divisi lain** (Tabung, BPP, SDA, Pajak, Interen, Sorong). Filter `GUDANGSIG2025*.gbk` adalah **WAJIB MUTLAK**.

**Pola rotasi terdeteksi:** backup harian per nama hari (`Monday`–`Saturday`) + backup bulanan (`MMYYYY`). Agent harus menangani file yang **di-overwrite berkala** — deteksi perubahan tidak boleh mengandalkan nama file saja, harus kombinasi `nama + ukuran + modified time` atau hash.

### 3.8 File Sharing

| Service | Status | StartType |
|---|---|---|
| `LanmanServer` (Server) | Running | Automatic |
| `LanmanWorkstation` (Workstation) | Running | Automatic |

**SMB Share yang terdaftar:**

| Nama | Path | Keterangan |
|---|---|---|
| `ADMIN$` | `C:\Windows` | Admin share bawaan |
| `C$` | `C:\` | Admin share bawaan |
| `D$` | `D:\` | Admin share bawaan |
| `IPC$` | — | Remote IPC bawaan |

**Status: 🟢 NORMAL.** Tidak ada share kustom terdaftar — akses file kantor kemungkinan melalui admin share `D$` atau melalui aplikasi. Port 139 (192.168.2.41) dan 445 aktif.

---

## 4. PORT — KONDISI SAAT INI

### Port yang sedang listening

| Port | Alamat | PID | Process | Keterangan |
|---|---|---|---|---|
| 135 | :: | 820 | svchost | RPC Windows |
| 139 | 192.168.2.41 | 4 | System | NetBIOS / File Sharing |
| 445 | :: | 4 | System | SMB / File Sharing |
| **3051** | **0.0.0.0** | **3552** | **fb_inet_server** | **🔴 FIREBIRD PRODUKSI — RESERVED** |
| 4709 | 127.0.0.1 | 9496 | wpscloudsvr | WPS Office |
| 5040 | 0.0.0.0 | 1580 | svchost | CDPSvc |
| 5357 | :: | 4 | System | WSDAPI |
| 5939 | 127.0.0.1 | 3748 | TeamViewer_Service | Remote access |
| **6605** | 127.0.0.1 | 3516 | AccurateLicenseManager | **🔴 ACCURATE — RESERVED** |
| **6688** | :: | 3516 | AccurateLicenseManager | **🔴 ACCURATE — RESERVED** |
| 7070 | 0.0.0.0 | 3492 | AnyDesk | Remote access |
| 7680 | :: | 2436 | svchost | Delivery Optimization |
| 10906 | 127.0.0.1 | 7372 | Code | VS Code |
| **34615** | :: | 3532 | aclicfivsvc | **🔴 ACCURATE — RESERVED** |
| 42050 | ::1 | 11560 | OneDrive.Sync.Service | OneDrive |
| 49664–49683 | — | — | lsass, wininit, spoolsv, services, svchost, AnyDesk | RPC dinamis Windows |

### Port kandidat STOCKWISE

| Port | Status | Rekomendasi |
|---|---|---|
| 80 | 🟢 BEBAS | Dapat dipakai (web frontend) |
| 443 | 🟢 BEBAS | Dapat dipakai (HTTPS, bila diperlukan) |
| 3306 | 🟢 BEBAS | Dapat dipakai MySQL — **bind ke `127.0.0.1` saja** |
| 8001 | 🟢 BEBAS | Dapat dipakai Laravel API |
| **3051** | 🔴 TERPAKAI | **DILARANG** |
| **3052** | 🔴 47 sesi aktif | **DILARANG** |

**Catatan:** Untuk staging Firebird STOCKWISE nanti, port 3051 dan 3052 tidak boleh dipakai. Kandidat aman perlu dibahas terpisah (misalnya 3053+ pada `127.0.0.1` saja) dan **wajib melalui persetujuan** karena menyangkut instance Firebird kedua.

---

## 5. FIREWALL & JARINGAN

| Item | Nilai |
|---|---|
| IP Server | 192.168.2.41 / 24 (interface: Ethernet) |
| Gateway | 192.168.2.1 |
| Subnet client Accurate | 10.10.20.x dan 10.10.10.x (routed) |

### Profil Firewall

| Profil | Enabled | Inbound Default | Outbound Default |
|---|---|---|---|
| Domain | True | NotConfigured | NotConfigured |
| **Private** | **False** | NotConfigured | NotConfigured |
| Public | True | NotConfigured | NotConfigured |

**Catatan:** Profil Private dalam keadaan **nonaktif**. Ini kemungkinan besar alasan Firebird dan File Sharing dapat diakses client tanpa rule eksplisit.

**Implikasi untuk STOCKWISE:** Bila server menggunakan profil Private, port STOCKWISE kemungkinan **otomatis dapat diakses LAN tanpa perlu menambah rule firewall**. Ini **menguntungkan** (tidak perlu mengubah firewall) tapi juga berarti MySQL **harus di-bind ke `127.0.0.1`**, karena kalau tidak, MySQL akan terekspos ke seluruh jaringan kantor.

---

## 6. RUNTIME & TOOLING — HASIL PEMERIKSAAN

| Software | Status | Versi | Lokasi |
|---|---|---|---|
| **Git** | 🟡 ADA, tidak di PATH | 2.55.0.windows.5 | `C:\Program Files\Git\cmd\git.exe` |
| **PHP** | 🔴 TIDAK ADA | — | — |
| **Composer** | 🔴 TIDAK ADA | — | — |
| **Node.js** | 🔴 TIDAK ADA | — | — |
| **npm** | 🔴 TIDAK ADA | — | — |
| **MySQL / MariaDB** | 🔴 TIDAK ADA | — | — |
| **Python** | 🔴 TIDAK ADA (stub) | — | Hanya stub Microsoft Store (0 byte) |
| **Docker** | 🔴 TIDAK ADA | — | — |
| **Apache** | 🔴 TIDAK ADA | — | — |
| **Nginx** | 🔴 TIDAK ADA | — | — |
| **IIS** | 🔴 TIDAK ADA | — | `C:\inetpub` ada tapi service W3SVC tidak terpasang |

### Folder stack web yang diperiksa

| Path | Ada |
|---|---|
| `C:\xampp` | Tidak |
| `C:\laragon` | Tidak |
| `C:\wamp64` / `C:\wamp` | Tidak |
| `C:\php` | Tidak |
| `C:\nginx` | Tidak |
| `C:\Program Files\MySQL` | Tidak |
| `C:\Program Files\nodejs` | Tidak |
| `C:\ProgramData\ComposerSetup` | Tidak |
| `C:\Program Files\Git` | **Ya** |

**Kesimpulan:** Server ini **bersih dari stack web** — tidak ada konflik dengan Apache/Nginx/IIS/MySQL existing. Ini **kabar baik**: instalasi STOCKWISE tidak akan bentrok dengan web server yang sudah ada, karena memang belum ada.

**Konsekuensi:** Seluruh runtime (PHP, Composer, Node.js, npm, MySQL) **harus diinstal dari nol**. Ini adalah perubahan besar pada server dan **wajib melalui persetujuan bertahap**.

---

## 7. SCHEDULED TASK NON-MICROSOFT

| Task | Path | State |
|---|---|---|
| **`auto shutdown`** | `\` | **Ready** |
| `OneDrive Reporting Task` | `\` | Ready |
| `OneDrive Standalone Update Task` | `\` | Ready |
| `OneDrive Startup Task` | `\` | Ready |
| `WpsExternal_server-sig_interval` | `\` | Ready |
| `WpsExternal_server-sig_startup` | `\` | **Running** |
| `WpsUpdateTask_server-sig` | `\` | Ready |
| `RunPlatformExperienceHelper_Daily` | `\GoogleUserPEH\` | Ready |
| `RunPlatformExperienceHelper_Metrics` | `\GoogleUserPEH\` | Ready |

### ⚠️ Perhatian khusus: task `auto shutdown`

Terdapat scheduled task bernama **`auto shutdown`**. Ini berarti server kemungkinan **dimatikan otomatis pada jadwal tertentu**.

**Implikasi serius untuk STOCKWISE:** Jadwal sync yang direncanakan adalah `04:00, 10:30, 20:30`. Bila server mati otomatis pada malam hari, **sync 04:00 dan 20:30 berpotensi tidak pernah berjalan**.

**Tindakan yang diperlukan:** Perlu dikonfirmasi ke user jadwal `auto shutdown` ini sebelum menentukan `SYNC_TIMES`. Saya **tidak membaca detail trigger task** karena termasuk konfigurasi sistem existing — akan diperiksa setelah ada konfirmasi.

---

## 8. STATUS FOLDER TARGET STOCKWISE

| Item | Hasil |
|---|---|
| `D:\01.STOCKWISE` | **ADA** |
| Jumlah item (termasuk hidden) | **0 — KOSONG** |
| Ada `.git`? | **Tidak** |
| `D:\STOCKWISE` (folder terlarang) | Tidak ada ✅ |
| Folder `*STOCKWISE*` lain di C: dan D: | Tidak ada, hanya `D:\01.STOCKWISE` |

**Status: 🟢 AMAN UNTUK CLONE.** Folder target sudah ada, benar-benar kosong, bukan repository Git, tidak berisi data/backup/aplikasi apa pun. **Tidak perlu menghapus apa pun.**

---

## 9. BASELINE PRODUKSI (PRE-DEPLOYMENT)

Baseline telah direkam dalam format JSON untuk pembanding health check berikutnya.

| Komponen | Nilai Baseline |
|---|---|
| **Accurate License Manager** | PID 3516, Running |
| **Firebird service** | `FirebirdServer25` = Running / Automatic |
| **Firebird process** | `fb_inet_server` PID 3552 |
| **Firebird port** | 3051 listening pada 0.0.0.0 |
| **Koneksi 3051** | 86 Established |
| **Koneksi 3052** | 47 Established (tanpa listener) |
| **`D:\GUDANGSIG2025.GDB`** | 133.881.856 byte, modified 22 Sep 2026 14:00:22 |
| **Service Running** | 99 service |
| **SMB Share** | ADMIN$, C$, D$, IPC$ |
| **Listening port** | 23 port unik (daftar lengkap di bagian 4) |
| **Disk C: bebas** | 334.72 GB |
| **Disk D: bebas** | 305.92 GB |
| **File `.gbk` total** | 424 |
| **File `GUDANGSIG2025*.gbk`** | 19 |

> **Catatan:** Nilai `modified time` database dan jumlah koneksi bersifat **dinamis** — akan terus berubah karena Accurate sedang aktif dipakai. Yang harus dipantau adalah: (a) service tetap Running, (b) PID tidak berubah tanpa sebab, (c) port 3051 tetap listening, (d) jumlah koneksi tidak turun drastis ke nol, (e) ukuran file database berubah secara wajar (bertambah), bukan mengecil drastis atau hilang.

---

## 10. ANALISIS RISIKO

### 🔴 RISIKO TINGGI

**R-1. Server sedang melayani 20 workstation dengan 133 koneksi aktif**
Setiap gangguan berdampak langsung ke seluruh operasional kantor. Instalasi apa pun sebaiknya dilakukan **di luar jam kerja**.
*Mitigasi:* Jadwalkan instalasi di luar jam operasional; lakukan health check sebelum dan sesudah setiap langkah.

**R-2. Port 3052 memiliki 47 sesi Accurate aktif tanpa listener**
Sesi ini rapuh — tidak bisa dipulihkan otomatis bila terputus.
*Mitigasi:* Port 3051 dan 3052 dinyatakan terlarang mutlak. Tidak ada perintah yang menyentuh `fb_inet_server` PID 3552.

**R-3. Filter backup `*.gbk` akan memproses 405 file milik divisi lain (98 GB)**
Berpotensi mengisi database STOCKWISE dengan data Tabung/BPP/SDA/Pajak, dan membebani disk & CPU secara masif.
*Mitigasi:* Filter `GUDANGSIG2025*.gbk` wajib, ditambah validasi isi database setelah restore staging (bukan hanya validasi nama file).

**R-4. Instance Firebird kedua untuk staging**
Restore `.gbk` ke staging memerlukan Firebird. Menggunakan instance produksi PID 3552 berarti beban restore ditanggung server yang sedang melayani 20 user, dan berisiko bila terjadi error.
*Mitigasi:* Perlu pembahasan khusus — opsi: instance Firebird terpisah (Embedded/portable, tanpa service, bind `127.0.0.1`) agar tidak menyentuh instance produksi sama sekali. **Ini adalah keputusan arsitektur yang wajib disetujui user.**

### 🟡 RISIKO SEDANG

**R-5. Task `auto shutdown` berpotensi membatalkan jadwal sync 04:00 dan 20:30**
*Mitigasi:* Konfirmasi jadwal shutdown sebelum menetapkan `SYNC_TIMES`.

**R-6. CPU i5-4460 (4 core) dengan RAM sisa 8.6 GB**
MySQL + PHP + build Node.js di atas beban Firebird 760 MB berpotensi memperlambat Accurate.
*Mitigasi:* Batasi `innodb_buffer_pool_size` MySQL (misalnya 512 MB–1 GB); lakukan `npm run build` di luar jam kerja; pertimbangkan build frontend di mesin lain lalu deploy hasil build saja.

**R-7. Firewall profil Private nonaktif**
Service STOCKWISE bisa otomatis terekspos ke LAN tanpa disadari.
*Mitigasi:* MySQL wajib bind `127.0.0.1:3306`. Laravel API bind sesuai kebutuhan akses.

**R-8. Git tidak ada di PATH**
Bukan risiko produksi, hanya kendala operasional.
*Mitigasi:* Gunakan path lengkap `C:\Program Files\Git\cmd\git.exe`. **Tidak perlu mengubah PATH sistem.**

### 🟢 RISIKO RENDAH

**R-9. Disk D: menyimpan 98 GB backup + 21 database produktif**
Sisa 305 GB masih sangat cukup. Staging STOCKWISE hanya butuh ±150 MB per siklus.
*Mitigasi:* Rotasi/cleanup file staging STOCKWISE; jangan pernah menyentuh file di root `D:\`.

**R-10. Tidak ada web server existing**
Justru menguntungkan — tidak ada konflik port 80/443 atau konfigurasi Apache/IIS yang bisa rusak.

---

## 11. REKOMENDASI

### Yang AMAN untuk dilanjutkan

1. **Clone repository PT ke `D:\01.STOCKWISE`** — folder kosong, Git tersedia, risiko sangat rendah. Tidak menyentuh sistem produksi sama sekali.
2. **Inspect project** setelah clone (baca `composer.json`, `package.json`, `.env.example`, dokumentasi) — read-only.
3. **Compatibility check** — bandingkan kebutuhan project dengan kondisi server.

### Yang WAJIB DIBAHAS SEBELUM DILANJUTKAN

4. **Arsitektur staging Firebird** — keputusan penting: instance terpisah vs. instance produksi. Rekomendasi saya: **instance terpisah yang tidak menyentuh service produksi sama sekali**.
5. **Jadwal `auto shutdown`** — menentukan `SYNC_TIMES` yang realistis.
6. **Jendela waktu instalasi** — sangat disarankan di luar jam kerja mengingat 20 user aktif.
7. **Versi runtime** — PHP, Node.js, MySQL mana yang akan dipasang (ditentukan setelah inspect project).

### Yang TIDAK DIREKOMENDASIKAN

- ❌ Menggunakan instance Firebird produksi (PID 3552) untuk restore staging
- ❌ Menggunakan port 3051 / 3052 dalam bentuk apa pun
- ❌ Mengubah PATH sistem, firewall, atau service existing
- ❌ Instalasi pada jam kerja
- ❌ Membaca `D:\GUDANGSIG2025.GDB` secara langsung
- ❌ Menggunakan filter `*.gbk`

---

## 12. LAPORAN FASE

**STATUS:** ✅ **SAFE — AUDIT SELESAI, MENUNGGU PERSETUJUAN**

**YANG DIPERIKSA:**
OS, CPU, RAM, disk, Accurate, Firebird (service/process/port/config), database produksi, database lain, backup `.gbk`, File Sharing, SMB share, service Windows, running process, listening port, koneksi client aktif, firewall, jaringan, scheduled task, runtime (PHP/Composer/Node/npm/MySQL/Python/Docker/Apache/Nginx/IIS), folder target STOCKWISE, antivirus, pending reboot.

**HASIL:**
Seluruh sistem produksi normal dan aktif. Server belum memiliki runtime STOCKWISE. Folder target kosong dan aman.

**PERUBAHAN:**
**TIDAK ADA.** Seluruh perintah bersifat read-only (`Get-*`, `Test-Path`, `Get-Content`). Tidak ada file yang ditulis ke `D:\` atau `C:\`. Baseline dan laporan ini disimpan di direktori scratchpad sementara, di luar folder produksi.

**YANG TIDAK BERUBAH:**
Accurate, Firebird, `D:\GUDANGSIG2025.GDB`, semua database `.GDB` lain, semua file `.gbk`, File Sharing, seluruh service Windows, seluruh port, firewall, jaringan, scheduled task, PATH sistem.

**HEALTH CHECK:**

| Komponen | Status |
|---|---|
| Accurate | 🟢 NORMAL — License Manager PID 3516 Running |
| Firebird | 🟢 NORMAL — Service Running, PID 3552, port 3051 listening |
| Database Accurate | 🟢 NORMAL — 127.68 MB, aktif ditulis, tidak disentuh |
| File Sharing | 🟢 NORMAL — LanmanServer & LanmanWorkstation Running |
| Service existing | 🟢 NORMAL — 99 service Running |
| Port existing | 🟢 NORMAL — 23 port listening, tidak berubah |
| Network | 🟢 NORMAL — 192.168.2.41, gateway 192.168.2.1, 133 koneksi client aktif |
| STOCKWISE | ⚪ BELUM ADA — folder kosong, belum di-clone |
| MySQL | ⚪ BELUM ADA |
| Laravel | ⚪ BELUM ADA |
| Agent | ⚪ BELUM ADA |

**RISIKO:**
Lihat bagian 10. Risiko tertinggi: beban produksi aktif 20 user, port 3052 dengan 47 sesi rapuh, dan filter backup yang salah.

**TINDAKAN BERIKUTNYA:**
Menunggu persetujuan user untuk clone repository PT ke `D:\01.STOCKWISE`. Tidak ada tindakan lain yang akan dilakukan sebelum persetujuan.

---

*Laporan ini dibuat pada fase audit read-only. Tidak ada perubahan yang dilakukan pada server produksi.*
