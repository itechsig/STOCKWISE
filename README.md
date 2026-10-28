# STOCKWISE

Inventory Intelligence System untuk PT Surya Inti Gas — versi web produksi.

Monorepo:

| Folder | Isi |
|--------|-----|
| [`backend/`](backend/) | REST API — Laravel + PHP + MySQL + Sanctum |
| [`frontend/`](frontend/) | SPA — React + Vite + TypeScript + Tailwind + shadcn/ui |
| [`sync-service/`](sync-service/) | Sync Agent Accurate (Python) — salin database Accurate → staging → API STOCKWISE |
| [`docs/`](docs/) | Analisis Excel, ERD, status flow, RBAC, API spec, calculation engine, test plan, laporan server |

## Status pembangunan

| Phase | Status |
|-------|--------|
| 0 — Analysis (Excel mapping, ERD, desain) | ✅ `docs/` |
| 1 — Project Setup (Laravel + React + MySQL + Sanctum, jalan lokal) | ✅ `docs/phase-1-report.md` |
| 2 — Auth + RBAC (+ import divisi & karyawan dari Excel) | ✅ `docs/phase-2-report.md` |
| 3 — Master Data + Inventory + Calculation Engine (+ import 8.957 barang dari Excel) | ✅ `docs/phase-3-report.md` |
| 4 — Request (buat → review + cek fisik → reserve) | ✅ `docs/phase-4-report.md` |
| 5 — NPBG + Pickup (stok turun saat pickup) | ✅ `docs/phase-5-report.md` |
| 6 — Stock Opname (selisih → adjustment saat approve) | ✅ `docs/phase-6-report.md` |
| 7 — PPB + Purchasing (PPB → PO → Receiving → stok naik saat confirm) | ✅ `docs/phase-7-report.md` |
| 8 — Tracking (Lend/Borrow/STPP/Ban Luar/Maintenance/Manufaktur/Bekas) | ✅ `docs/phase-8-report.md` |
| 9 — Dashboard per peran (KPI + chart) | ✅ `docs/phase-9-report.md` |
| 10 — Laporan & Export (Excel / CSV / PDF) | ✅ `docs/phase-10-report.md` |
| 11 — Excel Klasik (replika 9 file Excel lama, isi data live) | ✅ `docs/legacy-excel-export.md` |
| 12 — Accurate Sync Foundation (Firebird → MySQL) | ✅ `docs/sync-architecture.md` |
| 13 — Deploy single-server produksi (192.168.2.41) | ✅ `docs/server-deployment-report.md` |
| 14 — Sync Agent produksi: salin file GDB → staging → sync, proses latar belakang | ✅ `docs/sync-agent-security-report.md` |
| 15 — Kelola Pengguna & Role, Ganti Password, Log Agen, Accurate Online | ✅ `docs/sync-agent-security-report.md` |

> `migrate:fresh --seed` menjalankan import Excel penuh (~2–3 menit). Untuk reset cepat tanpa import:
> `php artisan migrate:fresh && php artisan db:seed --class=SiteSeeder && php artisan db:seed --class=RbacSeeder && php artisan db:seed --class=UserSeeder`
>
> ⚠️ **Jangan pernah** menjalankan `migrate:fresh` / `db:seed` di server produksi — itu menghapus data.
> `RbacSeeder` juga mengembalikan hak akses semua role bawaan ke default.

## Produksi (server 192.168.2.41)

Satu server Windows menjalankan Accurate 5 (produksi), STOCKWISE, dan Sync Agent.

| Komponen | Keterangan |
|---|---|
| **Web STOCKWISE** | `http://192.168.2.41:8001` — bisa dibuka dari PC mana pun di jaringan kantor (IP, nama komputer, atau `localhost` di server) |
| Service `STOCKWISE-Laravel` | `php artisan serve` port 8001 (NSSM, otomatis jalan saat server nyala, restart otomatis bila mati) |
| Service `STOCKWISE-FirebirdStaging` | Firebird 2.5 staging, **hanya** `127.0.0.1:3050` (terpisah dari Firebird produksi) |
| Service `MySQL84` | Database STOCKWISE, hanya `127.0.0.1:3306` |
| Firebird produksi Accurate | `FirebirdServer25`, port **3051**, `D:\GUDANGSIG2025.GDB` — **tidak pernah diubah oleh STOCKWISE** |
| Sync Agent | Task Scheduler (akun SYSTEM) — lihat [`sync-service/README.md`](sync-service/README.md) |
| Log | `D:\01.STOCKWISE\Logs\` (agent, sync, web) dan `backend\storage\logs\laravel.log` |
| Backup file saat deploy | `D:\01.STOCKWISE\Backup\` |

### Deploy perubahan UI

Frontend produksi disajikan Laravel dari `backend/public` (index di-rename `app.html`):

```bash
cd frontend
npm run build                         # VITE_API_URL harus KOSONG (same-origin) di produksi
# backup dulu backend/public/app.html ke D:\01.STOCKWISE\Backup\, lalu:
cp -n dist/assets/* ../backend/public/assets/
cp dist/index.html ../backend/public/app.html
```

`VITE_API_URL` dikosongkan supaya web bisa dibuka lewat alamat apa pun. Kalau diisi, isi **origin saja**
(mis. `http://host:8001`), jangan diakhiri `/api` — semua path di kode sudah diawali `/api/`.

### Deploy perubahan backend

Web berjalan langsung dari folder `backend/`, jadi file PHP yang disimpan **langsung berlaku**. Selalu:
uji dulu (test suite), cek sintaks (`php -l <file>`), dan cek `migrate:status` sebelum `php artisan migrate --force`
(pastikan hanya migration baru yang tertunda).

### Keamanan

- Semua akun hasil seeder memakai **password bawaan yang tertulis di source code** (`UserSeeder`).
  **Wajib diganti** di produksi: login `superadmin` → **Ganti Password**, lalu **Pengaturan → Pengguna →
  Reset password** untuk akun lain. Password bawaan ditolak oleh sistem untuk password baru.
- Token login berlaku 12 jam (`STOCKWISE_LOGIN_TOKEN_TTL_HOURS`), hanya bisa dipakai untuk aplikasi —
  endpoint Sync Agent hanya menerima token agent (`php artisan stockwise:agent-token`).
- Kredensial (`backend/.env`, `sync-service/.env`) tidak pernah di-commit.

## Fitur administrasi

| Menu | Isi | Permission |
|---|---|---|
| **Pengaturan → Pengguna** | Tambah/ubah/nonaktifkan/hapus pengguna, atur role, reset password (sesi pengguna diakhiri) | `user.*` |
| **Pengaturan → Role & Hak Akses** | Tambah/ubah/hapus role dan centang hak aksesnya (Super Admin terkunci) | `role.view`, `role.manage` |
| Tombol **Ganti Password** (header) | Setiap pengguna mengganti password sendiri | login |
| **Integrasi Accurate → Sync History** | Riwayat setiap sync + detail per data | `sync.accurate.view` |
| **Integrasi Accurate → Log Agen** | Catatan langsung Sync Agent + tombol **Salin GDB ke Staging** / **Salin + Sync** | `sync.accurate.view`, `sync.accurate.trigger` |
| **Integrasi Accurate → Accurate Online** | PC yang sedang terhubung ke Accurate (nama komputer, IP, MAC, sejak kapan) + putus koneksi manual | `accurate.session.view`, `accurate.session.terminate` |

Aturan pengaman: hanya Super Admin yang bisa memberi/mengubah akses Super Admin; admin tidak bisa
menonaktifkan/menghapus dirinya sendiri; pengguna yang sudah punya riwayat transaksi tidak bisa dihapus
permanen (nonaktifkan saja). Accurate Online hanya membaca tabel koneksi jaringan Windows — tidak
menyentuh Accurate/Firebird; memutus koneksi membuat pekerjaan yang *belum disimpan* di PC itu hilang,
data yang sudah tersimpan tetap aman.

## Accurate Sync

```text
D:\GUDANGSIG2025.GDB ──salin file (checksum)──► Staging\STAGING.GDB (Firebird 3050)
        ──baca 20 tabel──► API /api/agent/sync ──► tabel accurate_* (MySQL)
        ──proses latar belakang──► Barang, NPBG, STPP, PPB, PO, RI, Pengembalian Bekas, Stock Opname
```

- File database Accurate hanya **dibaca**; salinan yang tidak identik dengan sumber (Accurate sedang
  menulis) otomatis ditolak dan dicoba ulang. Staging lama selalu disimpan (`STAGING_PREV.GDB`).
- Pemrosesan berjalan di proses terpisah (`php artisan accurate:finish-batch`) — web tetap bisa dipakai
  selama sync. Hanya satu sync berjalan dalam satu waktu.
- Hasil: **Sync History** (per batch) dan **Log Agen** (per run, termasuk yang gagal sebelum sampai ke API).

Detail operasional & jadwal: [`sync-service/README.md`](sync-service/README.md). Latar belakang keputusan
dan hasil uji: `docs/sync-agent-security-report.md`, `docs/sync-architecture.md`,
`docs/accurate-database-analysis.md`, `docs/accurate-stockwise-mapping.md`.

## Setup lokal (development)

Prasyarat: PHP 8.3+, Composer 2, Node 20+, MySQL 8.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# sesuaikan DB_* di .env (default: MySQL, db `stockwise`), lalu:
php artisan migrate --seed       # RBAC + akun dev + import master dari Excel (~2-3 menit)
php artisan serve --port=8001    # http://127.0.0.1:8001
```

Import data Excel (folder default: root repo, atur `STOCKWISE_IMPORT_PATH` di `.env`):

```bash
php artisan stockwise:import --list      # importer yang tersedia
php artisan stockwise:import             # jalankan semua (idempoten, aman diulang)
php artisan stockwise:import departments # target tertentu
```

### Frontend

```bash
cd frontend
npm install
cp .env.example .env         # biarkan VITE_API_URL kosong → dev memakai proxy Vite
npm run dev                   # http://127.0.0.1:5173
```

### Akun uji (development)

Dibuat oleh `backend/database/seeders/UserSeeder.php` (login pakai **username atau email**; password
bawaan ada di seeder). Akun: `superadmin`, `admingudang`, `adminlapangan`, `purchasing`, `bos`,
`kariawan`, `anakgudang1`, `anakgudang2`, `karyawanbpn`. **Di produksi password ini wajib diganti** (lihat
bagian Keamanan).

### Testing

**Backend** — SQLite in-memory, tidak butuh MySQL:

```bash
cd backend
php artisan test                     # butuh dependency dev (composer install tanpa --no-dev)
php artisan test --filter=Auth       # satu grup
vendor/bin/pint --test               # cek format
```

> Server produksi hanya memasang dependency produksi (tanpa PHPUnit). Untuk menguji di server, salin
> `backend/` ke folder sementara, `composer install` di sana, dan jalankan PHPUnit dengan
> `DB_CONNECTION=sqlite DB_DATABASE=:memory:` (+ `-d extension=pdo_sqlite`) — jangan install dev
> dependency di folder produksi.

**Frontend:**

```bash
cd frontend
npm run test        # Vitest
npm run typecheck   # tsc -b
npm run lint        # oxlint
npm run build       # tsc + vite build -> dist/
```

**Sync Agent:** lihat [`sync-service/README.md`](sync-service/README.md#test).

**E2E (Playwright)** — butuh MySQL + backend ter-seed (development saja):

```bash
cd frontend && npx playwright install chromium
cd backend && php artisan migrate:fresh --seed && php artisan serve --port=8001   # terminal 1
cd frontend && npm run e2e                                                          # terminal 2
```

## Git

Branch: `main` (rilis), `develop` (integrasi), `feature/*` (pengembangan).
Jangan `git push --force` tanpa diminta.
