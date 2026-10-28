# Deployment — Satu Server (Accurate + STOCKWISE)

Panduan ini untuk saat migrasi ke server kantor dijalankan — **oleh Anda, langsung di server itu**
(RDP/akses fisik). Saya tidak bisa mengeksekusi ini dari sini. Ikuti urutan; setiap bagian punya langkah
verifikasi sebelum lanjut ke bagian berikutnya.

Konteks: server kantor (192.168.2.41) sekarang menjalankan Accurate 5 + Firebird 2.5 (`FirebirdServer25`,
port 3051, database aktif `D:\GUDANGSIG2025.GDB`). Karena cuma ada satu mesin, Laravel + MySQL + React +
Sync Agent semuanya akan jalan di mesin yang sama — **tapi tetap terisolasi**: Firebird untuk STOCKWISE
adalah instalasi terpisah (port beda, security database beda, tidak pernah menyentuh `FirebirdServer25`
atau `GUDANGSIG2025.GDB`), dan MySQL/PHP tidak pernah expose ke LAN, hanya port web Laravel yang dibuka.

**Sebelum mulai**: lakukan di luar jam sibuk, dan pastikan tidak ada yang sedang aktif memakai Accurate.
Semua langkah di bawah ini read/install-only terhadap komponen baru — tidak ada satu pun yang menyentuh,
restart, atau mengubah konfigurasi Accurate/Firebird production yang sudah jalan.

## 0. Cek prasyarat

```powershell
Get-Service | Where-Object { $_.Name -match 'Firebird' }   # pastikan FirebirdServer25 tetap Running, jangan disentuh
```

Install (kalau belum ada): PHP 8.3+ (dengan ekstensi `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `zip`,
`gd`), Composer 2, Node.js 20+ (cuma untuk *build* React, tidak perlu jalan terus), MySQL 8 (Server
edition biasa — bukan Laragon, ini bukan mesin dev), Python 3.11+ (untuk Sync Agent), Git.

## 1. Firebird terpisah untuk STOCKWISE (staging)

**Jangan pakai `FirebirdServer25` yang sudah ada.** Copy folder instalasi Firebird 2.5 yang sudah
terbukti jalan di PC dev (`D:\FirebirdLocal\Firebird_25\`) ke server ini, di path yang sama atau serupa.

```powershell
# Di server, dari folder yang baru di-copy:
cd D:\FirebirdLocal\Firebird_25\bin
.\fbserver.exe -a          # jalan sebagai proses biasa dulu, BUKAN service — port default 3050,
                            # tidak bentrok dengan FirebirdServer25 (3051)
```

Buat user Firebird khusus STOCKWISE (bukan SYSDBA — backup GUDANGSIG2025 asli punya SQL ROLE bernama
"SYSDBA" di datanya sendiri, collision kalau dipakai login, sudah terbukti di PC dev):

```powershell
.\gsec.exe -user SYSDBA -password masterkey -add stockwise_agent -pw "<password baru, buat sendiri>"
```

**Verifikasi**: `netstat -an | findstr 3050` harus `LISTENING`. `netstat -an | findstr 3051` juga masih
`LISTENING` (Accurate tidak terganggu).

## 2. MySQL

```powershell
mysql -uroot -p -e "CREATE DATABASE stockwise CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p -e "CREATE USER 'stockwise'@'localhost' IDENTIFIED BY '<password, buat sendiri>'; GRANT ALL ON stockwise.* TO 'stockwise'@'localhost'; FLUSH PRIVILEGES;"
```

User `stockwise` (bukan `root`) khusus untuk aplikasi, hanya bisa login dari `localhost` — MySQL tidak
pernah perlu diakses dari LAN sama sekali (brief §"Jangan expose MySQL ke LAN").

## 3. Deploy backend (Laravel)

```powershell
cd D:\
git clone <url repo STOCKWISE> STOCKWISE-PROD   # atau copy folder backend/ + sync-service/ manual
cd STOCKWISE-PROD\backend
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
```

Edit `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.2.41:8001          # atau IP/port final yang Anda pilih

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stockwise
DB_USERNAME=stockwise
DB_PASSWORD=<sama seperti langkah 2>

FRONTEND_URL=http://192.168.2.41:8001     # sesuaikan kalau React disajikan port lain
SANCTUM_STATEFUL_DOMAINS=192.168.2.41:8001

STOCKWISE_IMPORT_PATH=<folder Excel di server ini, kalau perlu import awal>
```

```powershell
php artisan migrate --seed      # RBAC + akun awal + import Excel master (kalau file-nya sudah ada di server)
php artisan stockwise:agent-token   # catat token yang muncul — untuk sync-service/.env langkah 5
```

**Verifikasi**: `php artisan test` (harus hijau), `php artisan route:list` (tidak error).

## 4. Deploy frontend (React)

```powershell
cd ..\frontend
npm install
copy .env.example .env
```

Edit `frontend/.env`: `VITE_API_URL=http://192.168.2.41:8001/api`

```powershell
npm run build      # -> dist/
```

`dist/` adalah file statis — bisa disajikan Laravel langsung (copy isi `dist/` ke `backend/public/`, atau
route catch-all) atau server statis ringan terpisah. Detail cara paling sesuai tergantung pilihan Anda di
langkah 6 (cara menjalankan Laravel) — tanyakan saya nanti kalau sudah sampai sini, supaya tidak salah
pilih pendekatan.

## 5. Sync Agent

```powershell
cd ..\sync-service
pip install -r requirements-dev.txt
copy .env.example .env
```

Edit `sync-service/.env`:

```
BACKUP_PATH=D:\
BACKUP_PATTERN=GUDANGSIG2025*.gbk
STABILITY_CHECK_SECONDS=120

STAGING_PATH=D:\01.STOCKWISE\Staging
GBAK_BIN=D:\FirebirdLocal\Firebird_25\bin\gbak.exe

FIREBIRD_HOST=127.0.0.1
FIREBIRD_PORT=3050
FIREBIRD_USER=stockwise_agent
FIREBIRD_PASSWORD=<dari langkah 1>

API_URL=http://127.0.0.1:8001/api        # satu mesin — localhost, bukan LAN IP
API_TOKEN=<dari langkah 3>

SYNC_TIMES=04:00,10:30,20:30
AGENT_STATE_PATH=D:\01.STOCKWISE\Config\agent_state.sqlite
AGENT_LOG_PATH=D:\01.STOCKWISE\Logs
```

**Test dulu, jangan langsung jadwal** (brief §41):

```powershell
python -m agent.run --dry-run     # cuma scan, tidak restore/kirim apa pun
python -m agent.run --sync-now    # jalan penuh sekali, tunggu sampai selesai
```

Cek hasilnya: buka STOCKWISE di browser → menu **Sync Accurate** → status harus `SUCCESS`, jumlah record
masuk akal (~9.000+ item kalau backup lengkap).

## 6. Menjalankan terus-menerus + jadwal

**Penting**: server ini auto-shutdown jam 22:00 (Task Scheduler yang sudah ada — **jangan diubah**).
Karena itu, Agent **tidak boleh** didesain sebagai proses yang harus selalu hidup — dia harus jalan
lewat **Windows Task Scheduler**, dipicu 3x sehari, bukan loop yang jalan terus:

- Task 1: trigger jam **04:00**, jalankan `python -m agent.run --sync-now`
- Task 2: trigger jam **10:30**, sama
- Task 3: trigger jam **20:30**, sama

(Bukan `python -m agent.run` tanpa `--sync-now` — mode loop itu untuk development/testing manual saja,
lihat §42 brief: belum saatnya jadi service.)

Laravel (`php artisan serve` atau setara) perlu tetap jalan selama jam kerja — paling sederhana: Task
Scheduler juga, trigger **at startup** (server otomatis nyala pagi, sesuaikan dengan kebiasaan kantor).
Kalau mau lebih robust (auto-restart kalau crash), saya bisa bantu setup lewat NSSM nanti — beri tahu
saya kalau sudah sampai tahap ini.

## 7. Firewall

```powershell
New-NetFirewallRule -DisplayName "STOCKWISE Web" -Direction Inbound -LocalPort 8001 -Protocol TCP -Action Allow
```

Hanya port Laravel yang dibuka ke LAN. **Jangan** buka 3306 (MySQL) atau 3050 (Firebird staging STOCKWISE)
ke LAN — keduanya cukup diakses `localhost` saja.

## 8. Verifikasi akhir

- [ ] `FirebirdServer25` (Accurate) tetap `Running`, port 3051 tetap seperti semula
- [ ] Accurate 5 bisa dipakai normal oleh user seperti biasa
- [ ] STOCKWISE bisa dibuka dari PC lain di kantor (`http://192.168.2.41:8001`)
- [ ] Login semua role berhasil
- [ ] Sync Accurate manual (`--sync-now`) sukses, data masuk benar
- [ ] 3 Task Scheduler (04:00/10:30/20:30) sudah dibuat dan tervalidasi jalan sekali
- [ ] Task auto-shutdown 22:00 **tidak diubah**
- [ ] MySQL & Firebird staging tidak bisa diakses dari PC lain di LAN (test dari PC lain: harus gagal connect)

## Kalau ada yang salah

Semua yang di atas cuma menambah komponen baru (Firebird terpisah, MySQL baru, Laravel, React, Agent) —
tidak ada satu langkah pun yang mengubah Accurate/Firebird production. Kalau ada masalah, cukup
stop/uninstall komponen baru yang error; Accurate tidak terpengaruh. Kalau ragu di tengah jalan, berhenti
dan hubungi saya dengan pesan error persis yang muncul — jangan coba tebak-tebak fix-nya sendiri untuk
bagian yang menyentuh Firebird/database.
