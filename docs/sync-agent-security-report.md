# Sync Agent — Security Audit Report (PHASE 1, read-only)

Tanggal audit: 2026-09-25 ~13:40 WIB · Server: `DESKTOP-CMEG5CR` / `192.168.2.41`
Status: **PHASE 1 & PHASE 3 (dummy-only) selesai** — lihat bagian PHASE 3 di bawah. Tidak ada perubahan pada server/production. PHASE 4 menunggu approval.

Metode audit: membaca kode `sync-service/`, `.env` (secret disamarkan), log, serta query read-only
(`Get-Service`/`Get-NetTCPConnection`/`Get-ScheduledTask`/`Get-NetFirewallRule`, membaca `firebird.conf`).
Tidak ada koneksi ke database production, tidak ada gbak/isql yang dijalankan.

---

## 1. Kondisi awal server

| Komponen | Kondisi |
|---|---|
| Timezone | `SE Asia Standard Time` (UTC+7 = WIB) — sesuai, tidak perlu diubah |
| `FirebirdServer25` (production) | Running, Auto, LocalSystem, `C:\Program Files\Firebird\Firebird_25`, **v2.5.4.26856** |
| Port production | `0.0.0.0:3051` (+ aux 3052) — **79 koneksi aktif** saat audit |
| `D:\GUDANGSIG2025.GDB` | 127.7 MB, sedang aktif ditulis (LastWrite 13:39) |
| Accurate license | `Acc5LicSvc`, `AccurateLicenseManager` — Running |
| `STOCKWISE-FirebirdStaging` (NSSM) | **Sudah terpasang & running.** Binary terpisah `D:\FirebirdLocal\Firebird_25` v2.5.9, `127.0.0.1:3050` saja, `security2.fdb` terpisah dari production |
| `STOCKWISE-Laravel` (NSSM) | `php artisan serve --host=0.0.0.0 --port=8001` (HTTP, bukan HTTPS) |
| MySQL84 | `127.0.0.1:3306` saja |
| Firewall | Rule `STOCKWISE Web` Allow TCP 8001, profile Any. Tidak ada rule untuk 3050 (bind 127.0.0.1) |
| Disk | C: 312 GB free · D: 305 GB free |
| Staging | `D:\01.STOCKWISE\Staging\STAGING.GDB` 69.7 MB (restore 25/09 08:49) |
| Backup Accurate | `D:\GUDANGSIG2025 <Hari>.gbk` + `<MMYYYY>.gbk`, dibuat Accurate sendiri ±1x/hari, jam tidak tetap (13:00–19:40) |

### ⚠️ Temuan kritis: scheduler production SUDAH AKTIF
Task Scheduler `\STOCKWISE-Sync-Accurate`: **Ready/Enabled**, user `SYSTEM` (Highest),
trigger harian **04:00, 10:30, 20:30**, action `python -m agent.run --sync-now`,
`MultipleInstances=IgnoreNew`. Last run 25/09 10:30 (result 0). **Next run: 25/09 20:30.**

Perilaku saat ini: hanya membaca `.gbk` buatan Accurate (tidak menyentuh GDB/port 3051), restore ke
staging, push ke API lokal. Dampak ke production: I/O baca file `.gbk` saja. Namun jadwal ini
**tidak sesuai** jadwal final dan melanggar aturan §4 (lihat §5 di bawah). Tidak saya ubah — perlu keputusan Anda.

---

## 2. Metode salinan GDB yang diusulkan

| Metode | Keputusan | Alasan |
|---|---|---|
| `Copy-Item` GDB aktif | ❌ Ditolak | Tidak konsisten, file bisa korup di tengah transaksi |
| `nbackup -L` + copy + `-N` | ❌ Ditolak | Memaksa DB production masuk mode delta; jika unlock gagal, delta terus tumbuh — terlalu berisiko |
| `.gbk` buatan Accurate (flow saat ini) | ⚠️ Fallback | Nol kontak ke production, tapi hanya ±1x/hari & jam tidak tetap → tidak bisa memenuhi 09:00/12:20/21:00 |
| **`gbak -b -g` online backup via Services API** | ✅ **Usulan** | Metode backup online resmi Firebird 2.5: satu snapshot transaction (MVCC), konsisten, **tanpa lock**, user Accurate tetap bekerja. `.gbk` Accurate di D:\ adalah format gbak yang sama. |

Detail usulan:
- Tool: `gbak.exe` **milik instalasi production** (2.5.4, versi cocok dengan server), mode read-only terhadap GDB.
- `-g` (no garbage collection) → gbak tidak menulis cleanup ke GDB production, hanya membaca.
- ~~`-se` service manager~~ → **tidak dipakai** (revisi Phase 3): tanpa `-se`, backup berjalan di proses gbak
  STOCKWISE, sehingga menghentikan proses gbak itu saja sudah membatalkan snapshot transaction di server.
- Output ke folder STOCKWISE saja: `D:\01.STOCKWISE\Staging\tmp\GUDANG_<timestamp>.fbk`.
- Estimasi durasi: beberapa menit untuk GDB 128 MB (perlu diukur pada run pertama yang di-approve).
- **Butuh kredensial production** (SYSDBA atau owner DB) — belum diketahui. Saya **tidak** akan membuat user baru di
  `security2.fdb` production. Kredensial diberikan oleh Anda, disimpan di `sync-service/.env` (git-ignored),
  dikirim ke gbak via env `ISC_USER/ISC_PASSWORD` (bukan `-pass` di command line, agar tidak terlihat di process list).

---

## 3. Command yang akan dijalankan (setelah approval)

**A. Online backup (satu-satunya kontak ke production, read-only):**
```
"C:\Program Files\Firebird\Firebird_25\bin\gbak.exe" -b -g \
    localhost/3051:D:\GUDANGSIG2025.GDB D:\01.STOCKWISE\Staging\tmp\GUDANG_<ts>.fbk
    (kredensial via env ISC_USER/ISC_PASSWORD, tidak di argv)
```
**B. Restore ke DB temporary di staging (3050 saja), bukan menimpa staging aktif:**
```
"D:\FirebirdLocal\Firebird_25\bin\gbak.exe" -c \
    D:\01.STOCKWISE\Staging\tmp\GUDANG_<ts>.fbk 127.0.0.1/3050:D:\01.STOCKWISE\Staging\tmp\STAGING_NEW.GDB
```
**C. Validasi** `STAGING_NEW.GDB` (validate.py yang ada + cek jumlah baris tabel inti vs run sebelumnya).
**D. Swap atomik** (hanya jika C lolos & tidak ada attachment ke staging): `STAGING.GDB → STAGING_PREV.GDB`, `STAGING_NEW.GDB → STAGING.GDB`.
**E. Sync** (mode yang ada: baca staging → API → MySQL), hanya untuk run 09:00, 12:20, 06:30.

Perubahan kode STOCKWISE yang dibutuhkan (hanya di `sync-service/`):
1. Mode `--snapshot` (A–D), `--sync-only` (E dari staging yang ada), `--full` (A–E).
2. Restore memakai `-c` ke file temp; **hapus `-rep` ke staging aktif**.
3. File lock global (`D:\01.STOCKWISE\Config\agent.lock`) → job kedua keluar jika job pertama masih jalan.
4. Retensi: simpan `STAGING_PREV.GDB` + N file `.fbk` terakhir di folder STOCKWISE saja. Tidak pernah menghapus file di `D:\` root.
5. Password via env `ISC_USER/ISC_PASSWORD`, tidak di argv, tidak di log.

Jadwal target (Task Scheduler, **aktivasi = PHASE 4, approval terpisah**):

| WIB | Mode |
|---|---|
| 09:00 | `--full` |
| 12:20 | `--full` |
| 21:00 | `--snapshot` (tanpa sync) |
| 06:30 | `--sync-only` (staging hasil 21:00, tanpa ambil GDB baru) |

---

## 4. Service, file, port & konfigurasi yang terdampak

| Objek | Dampak |
|---|---|
| `FirebirdServer25` / port 3051 | **Tidak diubah.** Hanya menerima 1 koneksi gbak read-only (`-b -g`) selama backup |
| `D:\GUDANGSIG2025.GDB` | **Tidak ditulis/dipindah/dikunci.** Dibaca oleh proses server lewat gbak |
| `security2.fdb` production, `firebird.conf`, Accurate, registry, PATH, firewall | Tidak disentuh |
| `STOCKWISE-FirebirdStaging` (3050) | Dipakai untuk restore temp (tidak diubah konfigurasinya) |
| `D:\01.STOCKWISE\Staging\`, `Config\`, `Logs\` | Ditulis (file temp, swap, lock, log) |
| `sync-service/*.py`, `.env` | Diubah (kode agent + kredensial) |
| Task `STOCKWISE-Sync-Accurate` | Perlu keputusan Anda (disable / ganti) — lihat §7 |

---

## 5. Risiko terhadap Accurate & file sharing

| Risiko | Level | Mitigasi |
|---|---|---|
| Beban I/O/CPU saat gbak -b di jam kerja (09:00, 12:20; 79 koneksi aktif) | Rendah–sedang | DB kecil (128 MB), `-g`, satu proses, timeout; ukur durasi pada run pertama; bisa digeser jika terasa lambat |
| Snapshot transaction panjang menahan OAT → garbage menumpuk sementara | Rendah | Hanya selama backup (menit); tidak ada lock |
| Kredensial SYSDBA production tersimpan di server | Sedang | `.env` git-ignored, ACL folder, env `ISC_PASSWORD`, tidak di log |
| gbak versi salah terhadap server | Rendah | Pakai gbak 2.5.4 dari instalasi production untuk backup; 2.5.9 staging hanya untuk restore (ODS 11.2 kompatibel) |
| Disk penuh | Sangat rendah | 305 GB free; retensi terbatas |
| File sharing | Tidak ada | Tidak ada perubahan share/permission |

**Temuan tambahan (kondisi existing, tidak saya ubah):**
1. Scheduler aktif dengan jadwal lama (§1) — `gbak -rep` langsung **menimpa staging aktif sebelum validasi** (melanggar §4 brief). Jika restore gagal di tengah, staging lama hilang.
2. Tidak ada lock antar-job selain `IgnoreNew` di satu task; jika dibuat beberapa task, job bisa paralel.
3. Password Firebird dikirim sebagai `-pass` di argv gbak (terlihat di process list).
4. Laravel berjalan via `artisan serve` (dev server) di `0.0.0.0:8001` **HTTP**, firewall Allow profile Any → API agent terbuka di LAN tanpa TLS. Brief meminta HTTPS.
5. `sync-service/.env` memakai MySQL `root` (untuk jalur legacy yang tidak dipakai agent) — sebaiknya dihapus/diganti user terbatas.
6. `API /complete` terlihat sangat lama (push selesai 08:49, complete 10:35 — ±1j45m, timeout 7260 s). Perlu dipastikan agar run 09:00 tidak bertabrakan dengan 12:20.
7. NSSM & PHP dijalankan dari folder profil user (`AppData\...\WinGet`) — rentan rusak jika profil/WinGet diubah.
8. `Config/agent_state.sqlite` tidak di-`.gitignore` (untracked) — jangan ikut ter-commit.

---

## 6. Rencana rollback (tanpa menyentuh production)

1. Disable task STOCKWISE baru (`Disable-ScheduledTask`) — tidak mempengaruhi Accurate.
2. Kembalikan staging: rename `STAGING_PREV.GDB → STAGING.GDB`.
3. Revert kode `sync-service/` via git ke commit sebelumnya; agent kembali ke mode `.gbk` Accurate.
4. File di `Staging\tmp\` bisa dihapus; tidak ada file production yang terlibat.
5. Jika ada anomali saat gbak -b berjalan: hentikan **proses gbak STOCKWISE saja** (`Stop-Process` PID gbak); server Firebird production membatalkan snapshot transaction otomatis. **Tidak** menghentikan FirebirdServer25/Accurate.

---

## 7. Keputusan yang dibutuhkan dari Anda

1. **Task `STOCKWISE-Sync-Accurate` (next run 20:30 hari ini):** biarkan, atau disable sekarang sampai flow baru siap?
2. **Metode salinan:** setuju `gbak -b -g` online via port 3051?
3. **Kredensial production** untuk gbak: SYSDBA atau user lain yang sudah ada (tanpa membuat user baru)?
4. **Tujuan API:** tetap Laravel lokal (`127.0.0.1:8001`) atau Hostinger via HTTPS?
5. Approval PHASE 3: implementasi kode + test **hanya dengan DB dummy di staging 3050** (tanpa menyentuh 3051).

Run gbak pertama ke production, real sync, dan aktivasi scheduler = PHASE 4, approval terpisah.

---

# PHASE 3 — Implementasi mekanisme (dummy-only) · 2026-09-25

> ⚠️ **Digantikan oleh PHASE 3b di bawah** (keputusan: direct GDB copy-replace, tanpa `.fbk` rutin).
> `agent/snapshot.py` dan variabel `PROD_*` sudah dihapus/diganti. Bagian ini disimpan sebagai riwayat.

Scope: **hanya mekanisme Sync Agent STOCKWISE.** Tidak ada koneksi ke port 3051, tidak menyentuh
`D:\GUDANGSIG2025.GDB`, tidak memakai kredensial production, tidak mengubah service/scheduler/firewall/registry,
tidak commit/push/deploy. Staging aktif `D:\01.STOCKWISE\Staging\STAGING.GDB` tidak disentuh selama test.

## Audit kode sebelum perubahan (ringkas)
- `restore.py`: `gbak -rep` langsung ke `STAGING.GDB` → staging aktif tertimpa **sebelum** validasi; password di argv (`-pass`).
- `run.py`: validasi dilakukan setelah restore ke staging aktif; tidak ada lock antar-job; hanya mode `.gbk` Accurate.
- `scanner.py`, `validate.py`, `state.py`, `api_client.py`, `mapping/`: sudah baik, dipakai ulang tanpa perubahan.

## Perubahan
| File | Perubahan |
|---|---|
| `sync-service/agent/restore.py` | Restore `gbak -c` ke `Staging\tmp\STAGING_NEW.GDB` (bukan `-rep` ke staging aktif); guard path hanya di `tmp\`; kredensial via env `ISC_USER/ISC_PASSWORD` |
| `sync-service/agent/staging.py` (baru) | `swap_in()` — rename atomik `STAGING.GDB→STAGING_PREV.GDB`, `NEW→STAGING.GDB`; gagal jika file masih dipakai (staging lama tetap); `rollback()` |
| `sync-service/agent/snapshot.py` (baru) | `gbak -b -g` online backup ke `Staging\tmp\GUDANG_<ts>.fbk`; **terkunci `PROD_SNAPSHOT_ENABLED=false` (default)**; hapus output parsial saat gagal; retensi N `.fbk` |
| `sync-service/agent/lock.py` (baru) | Lock global OS-level (`Config\agent.lock`), otomatis lepas jika proses mati |
| `sync-service/agent/run.py` | `prepare_staging()` (restore temp → validasi → swap) dipakai semua mode; mode baru `--full`, `--snapshot`, `--sync-only`; semua mode di bawah lock; exit code 2 jika job lain berjalan |
| `sync-service/config/settings.py` | `ProductionSourceSettings` (disabled default), path temp/prev staging, `AGENT_LOCK_PATH` |
| `sync-service/firebird/introspect.py` | `connect(database_path=None)` untuk validasi DB temp |
| `sync-service/tests/test_staging_mechanism.py` (baru) | 12 unit test mekanisme |
| `sync-service/.env.example`, `README.md`, `.gitignore` | Dokumentasi variabel baru (disabled), jadwal target, ignore `*.fbk` & `/Config/` |

`--sync-now` (dipakai task terjadwal yang ada) **tetap membaca `.gbk` Accurate saja** — sumber tidak berubah,
hanya kini melalui temp → validasi → swap.

## Hasil test
- Unit test: **39/39 passed** (27 lama + 12 baru). pytest dipasang hanya di folder scratchpad (`--target`), bukan ke Python sistem.
- End-to-end dummy (Firebird staging `127.0.0.1:3050`, DB dummy di `Staging\dummy-test`, API **mock** lokal): **20/20 passed**

| # | Skenario | Hasil |
|---|---|---|
| 1 | Snapshot menolak jalan jika `PROD_SNAPSHOT_ENABLED≠true` | ✅ |
| 2 | `--snapshot`: backup → temp → validasi → swap, tanpa push | ✅ |
| 3 | Snapshot kedua menyimpan `STAGING_PREV.GDB` | ✅ |
| 4 | Copy tidak valid ditolak, staging aktif tetap (identitas file sama) | ✅ |
| 5 | `.gbk` korup: restore gagal, tercatat FAILED, staging aktif tetap | ✅ |
| 6 | Job paralel ditolak (exit 2), tanpa panggilan API | ✅ |
| 7 | `--sync-only` push staging aktif (50/50 baris), tanpa copy baru | ✅ |
| 8 | `--full` end-to-end | ✅ |
| 9 | `--sync-now` (jalur task yang ada) dari `.gbk` | ✅ |
| 10 | Rollback `STAGING_PREV.GDB` → aktif | ✅ |
| 11 | Retensi `.fbk` | ✅ |
| 12 | Log bebas password/token; gbak tidak lagi menerima `-pass` di argv | ✅ |

Catatan: data dummy **sengaja tidak** di-push ke API asli `127.0.0.1:8001` — itu akan menimpa tabel `accurate_*`
di MySQL STOCKWISE dengan data palsu. Push diuji terhadap mock API yang meniru kontrak endpoint. Folder dummy sudah dihapus.
Keberhasilan test dummy **bukan** persetujuan untuk backup production.

## Risiko tersisa
1. **Task `STOCKWISE-Sync-Accurate` (20:30 malam ini) akan menjalankan kode baru** karena memanggil `agent.run --sync-now`
   dari folder ini. Sumber tetap `.gbk` Accurate (tanpa 3051); perbedaannya: restore ke temp + swap, file baru
   `Staging\tmp\`, `Staging\STAGING_PREV.GDB` (~70 MB), `Config\agent.lock`. Jika swap gagal → FAILED, staging lama tetap, dicoba lagi tick berikutnya.
   Jika ingin kode lama untuk malam ini: `git stash` pada `sync-service/` (atau disable task — perlu approval Anda).
2. Validasi masih berbasis struktur + pola kode barang; belum membandingkan jumlah baris dengan run sebelumnya.
3. Durasi `gbak -b -g` pada GDB production (128 MB, jam kerja) belum terukur — diukur saat run pertama yang disetujui.
4. `API /complete` ±1j45m pada data nyata — run 09:00 dan 12:20 berpotensi berdekatan; lock mencegah overlap (job kedua keluar, exit 2).
5. Temuan existing lain (Laravel HTTP `0.0.0.0:8001`, MySQL `root` di `.env`, NSSM/PHP di profil user) belum ditangani — di luar scope mekanisme.

## Langkah deployment yang memerlukan approval terpisah
1. Kredensial production (user yang sudah ada) → `PROD_FIREBIRD_USER/PASSWORD` di `sync-service/.env`.
2. `PROD_SNAPSHOT_ENABLED=true` + **satu** run `--snapshot` manual di luar jam sibuk, dipantau (durasi, Accurate tetap normal).
3. Real sync pertama `--full` ke API lokal.
4. Ganti task `STOCKWISE-Sync-Accurate` dengan 4 task: 09:00 `--full`, 12:20 `--full`, 21:00 `--snapshot`, 06:30 `--sync-only`.
5. Sebelum aktivasi: verifikasi Accurate bisa dibuka, FirebirdServer25 running, port 3051, file sharing normal.
6. Commit perubahan.

---

# PHASE 3b — Direct GDB copy-replace (dummy-only) · 2026-09-25

**Status: diimplementasikan & diuji hanya pada dummy (Firebird staging `127.0.0.1:3050`). BUKAN bukti production-safe.**
Tidak ada koneksi ke 3051, tidak ada akses ke `D:\GUDANGSIG2025.GDB`, tidak ada kredensial production,
scheduler/service/konfigurasi tidak diubah, tidak ada commit/push/deploy.

## 1. Metode "direct GDB copy" yang diteliti

| Metode | Hasil `.GDB`? | Konsisten saat DB aktif? | Dampak ke DB sumber | Keputusan |
|---|---|---|---|---|
| `Copy-Item` / robocopy | Ya (byte-copy) | **Tidak.** Firebird menulis halaman dari cache kapan saja; salinan bisa berisi halaman dari waktu berbeda | Tidak ada | ❌ Ditolak. Di dummy, copy seperti ini *tetap bisa dibuka* (200.000 baris) meski sumber sedang ditulis → "berhasil dibuka" ≠ konsisten |
| Snapshot filesystem (VSS) | Ya | Hanya *crash-consistent* (setara mati listrik). Firebird 2.5 tidak punya VSS writer | Operasi VSS pada volume D: (admin; memengaruhi I/O volume & file sharing) | ❌ Ditolak. Tidak diuji: butuh perubahan level sistem pada volume production |
| `nbackup -L` → copy → `-N` (+ `-F` pada salinan) | Ya (byte-copy) | Ya, metode resmi Firebird 2.5 untuk salinan fisik | **Menulis ke DB production:** header masuk state "stalled", penulisan dialihkan ke file `.delta` di sebelah GDB lalu di-merge saat `-N`. Jika terputus, DB tetap stalled sampai `-N` manual. Butuh akses **lokal** ke file | ❌ Ditolak. Di dummy, nbackup menolak koneksi TCP ("needs local access to database file"); akses lokal di mesin ini lewat instance Firebird *production* → eksperimen dihentikan |
| `gbak -b` → `.fbk` → `gbak -c` | Ya (via file perantara) | Ya (satu snapshot transaction MVCC) | Hanya baca (`-g`) | ⚠️ Aman, tapi memakai `.fbk` rutin → tidak sesuai keputusan Anda |
| **`gbak -b -g <src> stdout` │ `gbak -c stdin <STAGING_NEW.GDB>`** | **Ya, langsung GDB → GDB** | **Ya** (satu snapshot transaction MVCC) | **Hanya baca**, tanpa lock, tanpa `.delta`, tanpa perubahan header | ✅ **Dipilih** |

Referensi teknis (dokumentasi resmi, firebirdsql.org):
- *gbak — Firebird Backup & Restore Utility* (manual) dan *Firebird 2.5 Release Notes*: backup online dalam satu snapshot transaction; `-g` (no garbage collection); `stdout`/`stdin` sebagai nama file backup untuk piping.
- *Firebird's nbackup tool* (manual): *Lock and unlock* (`-L`/`-N`), file delta, `-F` (fixup) untuk salinan hasil copy.
- Dokumentasi operasional Firebird: jangan menyalin file database yang sedang dipakai dengan tool file biasa.

Keterbatasan metode terpilih:
- **Isi sama, file tidak identik:** hasilnya adalah salinan logis. `.GDB` baru berisi data yang sama, tetapi **tidak byte-identik** dengan sumber karena index dibangun ulang dan halaman dipadatkan. Untuk kebutuhan baca STOCKWISE ini setara.
- **Transaksi terbuka selama copy:** satu snapshot transaction tetap terbuka di production sampai copy selesai. Ini menahan OAT, sehingga garbage collection tertunda.
- **Beban belum terukur:** beban I/O dan CPU di production **belum pernah diukur**.
- **Butuh approval:** mekanisme ini tetap sebuah `gbak -b` terhadap production, jadi **perlu approval Anda.**

## 2. Aman untuk DB aktif Firebird 2.5.4?
- **Secara desain: ya.** Hanya membaca, tanpa lock, tanpa perubahan header atau file production. Mekanismenya sama dengan yang dipakai Accurate untuk membuat `.gbk`-nya sendiri.
- **Terverifikasi hanya di dummy:**
  - gbak **2.5.4 milik instalasi production** mendukung `stdout` (hanya diarahkan ke dummy di port 3050).
  - Copy 200.000–300.000 baris tetap konsisten walau sumber terus di-update.
  - `gfix -v -full` bersih.
- **Belum terverifikasi di production:**
  - durasi copy
  - beban saat jam kerja (±79 koneksi)
  - interaksi dengan backup Accurate yang berjalan bersamaan

  **Belum boleh dianggap production-safe.**

## 3. File dibuat / diubah
| File | Isi |
|---|---|
| `sync-service/agent/live_copy.py` (baru) | Pipe `gbak -b -g … stdout │ gbak -c stdin …`; kredensial per sisi lewat env; timeout mematikan kedua proses; hapus file parsial (dengan retry) |
| `sync-service/agent/guards.py` (baru) | `SYNC_ENV=dummy` menolak port 3051, `D:\GUDANGSIG2025.GDB`, folder staging asli, API asli `:8001`; production menolak kecuali `SOURCE_COPY_ENABLED=true`; target hanya `Staging\tmp\`; guard free space |
| `sync-service/agent/restore.py` | `check_integrity()` (`gfix -v -full -n`, read-only); guard target + free space; hapus file parsial saat gagal |
| `sync-service/agent/run.py` | `finalize_staging()` = gfix → validasi identitas → swap, dipakai semua mode; `--full/--snapshot` memakai live copy |
| `sync-service/config/settings.py` | `AGENT_ENV_FILE` (env dummy/production terpisah), `SourceSettings` (`SYNC_ENV`, `SOURCE_*`), `GFIX_BIN`, `STAGING_MIN_FREE_MB`; konstanta production hard-coded untuk guard |
| `sync-service/agent/snapshot.py` | **Dihapus** (alur `.fbk` rutin) |
| `agent/staging.py`, `agent/lock.py`, `firebird/introspect.py` | Dari Phase 3 (swap + rollback, lock, connect ke DB temp) |
| `sync-service/tests/test_staging_mechanism.py` | 25 unit test (swap, rollback, lock, guards, live copy, integrity) |
| `.env.example`, `.env.dummy.example` (baru), `README.md`, `.gitignore` | Konfigurasi `SOURCE_*` (disabled), template dummy terpisah tanpa secret, dokumentasi |

Backend Laravel, mapping, API, dan workflow **tidak diubah**. `--sync-now` (task yang ada) tetap membaca `.gbk` Accurate.

## 4. Hasil test
- Unit test: **52/52 passed** (pytest dipasang hanya di scratchpad).
- End-to-end dummy: **31/31 passed**. Dijalankan lewat `agent.run` asli dengan `AGENT_ENV_FILE=.env.dummy` dan mock API.

| Skenario | Hasil |
|---|---|
| Guard dummy: port 3051, `D:\GUDANGSIG2025.GDB`, staging asli dan API asli ditolak. Mode production tanpa `SOURCE_COPY_ENABLED` ditolak. Tidak ada copy yang dimulai | ✅ |
| GDB → GDB temp → gfix → validasi → swap; **tidak ada `.fbk/.gbk` perantara** | ✅ |
| `STAGING_PREV.GDB` dipertahankan; rollback | ✅ |
| Validasi gagal → staging aktif tetap | ✅ |
| **Koneksi aktif** ke `STAGING.GDB` saat swap → swap ditolak, staging tetap | ✅ |
| **File terkunci** (handle terbuka) → swap ditolak, staging tetap | ✅ |
| **Disk penuh** (free-space guard) → ditolak sebelum copy | ✅ |
| **Timeout / proses terputus** → kedua gbak dimatikan, file parsial dihapus, tidak ada gbak tersisa | ✅ (awalnya gagal: file parsial masih dipegang server → diperbaiki dengan retry) |
| **Agent di-kill** di tengah copy → staging tetap, lock lepas, run berikutnya sukses | ✅ (gbak anak tetap jalan sampai selesai — lihat risiko) |
| `--full` saat sumber terus ditulis → 300.000 baris, gfix bersih, push lengkap | ✅ |
| Job bersamaan ditolak (exit 2) | ✅ |
| `--sync-only`; `--sync-now` legacy `.gbk` tetap jalan | ✅ |
| Log dan file env dummy tanpa password/token | ✅ |

## 5. Bukti port 3051 & GDB production tidak disentuh
- **Monitor koneksi:** selama seluruh test e2e, `netstat` disampling tiap 0,3 detik. Hasilnya **0 koneksi ke `:3051`** dari gbak/gfix/nbackup/python.
- **Guard berlapis:**
  - `SYNC_ENV=dummy` menolak port 3051 dan path production sebelum proses apa pun dimulai (unit test + e2e A1–A2).
  - Dalam mode apa pun, target restore harus berada di `Staging\tmp\` dan server staging tidak boleh 3051.
- **DSN test:** semua test memakai `127.0.0.1/3050:D:\01.STOCKWISE\Staging\{dummy-test,method-probe}\…`. Kedua folder sudah dihapus.
- **Staging asli utuh:** `STAGING.GDB` masih bertanggal 08:49, dan `Config\` tidak berubah.
- **Catatan:** `C:\Program Files\Firebird\Firebird_25\bin\gbak.exe` (2.5.4) dijalankan sekali sebagai *client* ke dummy di port 3050 untuk membuktikan dukungan `stdout`. Proses ini tidak terhubung ke server production.

## 6. Risiko tersisa
1. **Task terjadwal 20:30 malam ini** (`--sync-now`, tidak diubah) akan memakai kode baru.
   - Sumbernya tetap `.gbk` Accurate, **ditambah pemeriksaan `gfix`**.
   - Jika gfix melaporkan sesuatu pada data nyata, run FAILED dan staging lama dipertahankan. Ini fail-safe, tetapi berarti tidak ada sync malam itu.
   - Perilaku gfix pada data nyata belum diuji, karena test dibatasi ke dummy.
2. **Proses gbak anak bisa tertinggal:** jika agent dimatikan paksa, gbak anak **tetap jalan sampai selesai**. Sifatnya read-only dan durasinya terbatas. Untuk menghentikan copy, matikan juga `gbak.exe` milik STOCKWISE. Opsi perbaikan: Windows Job Object.
3. **Slot 12:20:** durasi dan beban copy di production belum terukur, jadi run di tengah jam kerja perlu diamati.
4. **`API /complete` lambat (±1j45m):** lock mencegah overlap, tetapi run berikutnya bisa terlewat (exit 2).
5. **Di luar scope:** temuan Phase 1 lain (Laravel HTTP `0.0.0.0:8001`, MySQL `root`, NSSM/PHP di profil user).

## 7. Langkah yang butuh approval terpisah (urut)
1. Run 20:30 malam ini: biarkan (kode baru), `git stash` sementara, atau disable task.
2. Kredensial production (akun yang sudah ada, disiapkan oleh Anda) → `SOURCE_FIREBIRD_USER/PASSWORD` di `.env`.
3. **Uji terkontrol pertama** `--snapshot` (tanpa sync) dengan `SOURCE_COPY_ENABLED=true`:
   - dijalankan di luar jam kerja dan dipantau langsung
   - catat durasi copy
   - pastikan Accurate bisa dibuka, `FirebirdServer25` running, port 3051 normal, dan file sharing normal
4. Real sync pertama `--full`.
5. Ganti task lama dengan jadwal target: 09:00 `--full`, 12:20 `--full`, 21:00 `--snapshot`, 06:30 `--sync-only`.
6. Commit.

## Rekomendasi tahap berikutnya
Lakukan langkah 3: satu `--snapshot` di luar jam kerja, diawasi langsung, dengan durasi dan beban dicatat. Setelah itu baru putuskan apakah slot 12:20 aman dijalankan di tengah jam kerja.

---

# PHASE 3c — Hardening keamanan (dummy-only) · 2026-09-25

| Perubahan | File | Tujuan |
|---|---|---|
| Windows Job Object (`KILL_ON_JOB_CLOSE`) untuk kedua gbak | `agent/procjob.py` (baru), `agent/live_copy.py` | Jika agent mati/di-kill, gbak ikut mati → snapshot transaction di sumber tidak pernah tertinggal. Jika binding gagal, copy dihentikan. Menutup risiko §6.2 PHASE 3b |
| Preflight read-only (`sc query` + `netstat`, **tanpa koneksi ke 3051**) | `agent/preflight.py` (baru), `agent/run.py` | Live copy **tidak dimulai** kecuali `FirebirdServer25`, `Acc5LicSvc`, `AccurateLicenseManager`, `STOCKWISE-FirebirdStaging` RUNNING, port 3051 & 3050 LISTENING, dan disk cukup. Dicek ulang setelah copy (masalah → ERROR di log). Manual: `python -m agent.run --preflight` |

Hasil test:
- Unit test: **55/55**.
- End-to-end dummy: **34/34**, termasuk:
  - H0: agent di-kill → 2 gbak berjalan sebelum kill, 0 sesudahnya
  - P1: preflight gagal → copy tidak dimulai
  - Z: 0 koneksi ke `:3051`

Verifikasi read-only dengan `.env` asli (tanpa copy, tanpa tulis log):
- **Konfigurasi aktif:** `SYNC_ENV=production`, `SOURCE_COPY_ENABLED=False`, staging port 3050.
- **Live copy:** DITOLAK (sesuai harapan).
- **Import dari folder task:** berhasil dengan interpreter yang sama, jadi task 20:30 bisa memuat kode baru.
- **Preflight:** `OK`.
- **Staging/Config:** tidak berubah.

Status: semua pekerjaan yang bisa dilakukan tanpa menyentuh production sudah selesai. Langkah berikutnya (§7 PHASE 3b, mulai dari langkah 2) membutuhkan kredensial dari Anda dan approval eksplisit.

---

# Web STOCKWISE: Log Agent, akses LAN, auto-start · 2026-09-25

**Log Agent di dashboard (Sync History).** Endpoint baru `GET /api/sync/agent-log`:
- Permission sama dengan riwayat sync (`sync.accurate.view`). Hanya membaca `Logs\sync.log` dan `agent.log`, dengan secret disamarkan di server.
- Menampilkan run yang tidak masuk `sync_batches`: gagal sebelum sync dimulai, atau salinan staging saja (`STAGED`).
- File: `AgentLogController.php`, `routes/api.php`, `config/stockwise.php` (`STOCKWISE_AGENT_LOG_PATH`), `AgentLogTest.php`, `features/sync/api.ts`, `SyncHistoryPage.tsx`.

**Bug login di UI yang ter-deploy (sudah diperbaiki).**
- **Penyebab:** build lama menanam `VITE_API_URL=http://192.168.2.41:8001/api`, sedangkan kode memanggil `/api/...`. Hasilnya request ke `/api/api/login` (404), sehingga login gagal dari semua PC. Dibuka lewat hostname atau `localhost` juga diblokir CORS.
- **Perbaikan:** `VITE_API_URL` dikosongkan (same-origin). UI bisa dibuka lewat IP, hostname, maupun localhost.
- **Deploy** (disetujui "deploy ui") pukul 16:01:
  - backup: `D:\01.STOCKWISE\Backup\frontend-public-20260925_160120\`
  - rollback: salin `app.html` dari backup ke `backend/public/`
- **Verifikasi dengan Edge headless:** login akun palsu → `POST /api/login` 422 (bukan 404), tanpa error JS, via IP (3/3), hostname, dan localhost.

**Test.** Backend 229/229 (salinan terisolasi, SQLite in-memory; MySQL live tidak tersentuh). Frontend 5/5, typecheck bersih.

**Auto-start service.**
- **Kondisi awal:** `STOCKWISE-Laravel` dan `STOCKWISE-FirebirdStaging` baru di-install 25/09 08:42 dan belum pernah melewati reboot. Keduanya sudah `Automatic`, Laravel menunggu MySQL84 dan FirebirdStaging, dan SYSTEM punya akses penuh ke binary NSSM/PHP.
- **Ditambahkan** (hanya service STOCKWISE: `STOCKWISE-Laravel`, `STOCKWISE-FirebirdStaging`, `MySQL84`):
  - recovery Windows: restart setelah 5 / 10 / 30 detik, reset 1 hari (`sc failure` + `failureflag 1`)
  - tidak ada service yang di-restart
- **Tidak diubah:** `FirebirdServer25`, `Acc5LicSvc`, `AccurateLicenseManager`.
- **Rollback:** `sc failure <nama> reset= 0 actions= ""`.

**Risiko tersisa (perlu keputusan/approval):**
1. **Server web satu antrian:** `php artisan serve` adalah server development dan hanya melayani satu request dalam satu waktu (di Windows tidak mendukung multi-worker). Request panjang, seperti `/complete` sync ±1j45m, bisa membuat web lambat atau macet untuk pengguna lain. Solusi permanen: web server sungguhan (mis. Apache + PHP sebagai service). Ini instalasi komponen baru dan butuh approval serta jadwal pemeliharaan.
2. **Binary di folder profil user:** NSSM dan PHP berada di folder profil user (`AppData\...\WinGet`). Jika paket di-upgrade atau dihapus lewat WinGet, service bisa gagal start. Sebaiknya dipindah ke folder tetap (mis. `C:\STOCKWISE\tools`); ini perlu approval karena service harus di-restart sebentar.
3. **Batas waktu task:** task `STOCKWISE-Sync-Accurate` punya `ExecutionTimeLimit=2 jam`, sedangkan sync nyata bisa mendekati 2 jam (restore + `/complete`), sehingga berisiko dihentikan Windows.
4. **Auto-start belum terbukti:** auto-start saat boot akan terbukti pada reboot berikutnya. Setelah server menyala, cek dengan `python -m agent.run --preflight` dan buka web.

---

# Audit keamanan web STOCKWISE · 2026-09-25

## Sudah aman (dicek)
- **Tidak ada file sensitif yang bisa diakses lewat web.** Path `.env`, `.git`, `storage/logs`, `vendor`, `artisan`, `composer.json`, dan path traversal `../` semuanya hanya mengembalikan halaman SPA.
- **Tidak ada fitur debug yang terbuka.** `APP_DEBUG=false`, tidak ada Telescope/Horizon/Ignition. Semua endpoint data memakai `auth:sanctum` + permission.
- **Port tidak terbuka keluar.** MySQL hanya `127.0.0.1`, Firebird staging hanya `127.0.0.1`.

## Diperbaiki & sudah live
**Celah KRITIS: token login user bisa memanggil endpoint Sync Agent.**
- **Penyebab:** `createToken()` tanpa ability menghasilkan `['*']`, dan `abilities:agent:sync` menerima `*`. Terbukti dengan test: token login **karyawan** berhasil membuat sesi sync (201). Artinya user mana pun bisa mengirim data palsu ke mirror Accurate lalu memicu `complete`.
- **Perbaikan:**
  - middleware `EnsureAgentToken` (alias `agent.token`) hanya menerima token yang eksplisit ber-ability `agent:sync` dan menolak `*`, termasuk token lama
  - token login user kini ber-ability `['app']` dan **berlaku 12 jam** (`STOCKWISE_LOGIN_TOKEN_TTL_HOURS`); setelah itu user login ulang
- **File:** `app/Http/Middleware/EnsureAgentToken.php` (baru), `bootstrap/app.php`, `routes/api.php`, `AuthController.php`, `config/stockwise.php`, `tests/Feature/Auth/TokenScopeTest.php` (baru, memakai token asli, bukan `actingAs`).
- **Test:** 234/234 lulus (salinan terisolasi, SQLite). Setiap file dicek `php -l` sebelum live, dan app live dicek setelahnya (/, /api/me, login, agent).
- **Token Sync Agent asli** (`["agent:sync"]`) diverifikasi read-only tetap lolos, jadi task 20:30 aman.

## BELUM diperbaiki — perlu keputusan Anda
1. **KRITIS: password default bersama.**
   - 12 dari 13 akun aktif (termasuk `superadmin`, `admingudang`, `purchasing`, `bos`) masih memakai password default dari `UserSeeder::PASSWORD`, yang tertulis di source code.
   - Aplikasi **tidak punya fitur ganti password**.
   - Opsi:
     - (a) buat fitur "Ganti Password" + wajib ganti saat login pertama
     - (b) reset password acak per akun, hasilnya diberikan ke Anda untuk dibagikan
     - (c) keduanya
2. **Login throttle** 20 percobaan/menit per IP. Bisa diperketat per username.
3. **Header keamanan** belum ada (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`). `X-Powered-By: PHP/8.4.25` terekspos; mematikannya lewat `expose_php=Off` di `php.ini` (perubahan konfigurasi sistem).
4. **`LOG_LEVEL=debug`** di production → sebaiknya `warning`.
5. Risiko web server satu antrian, lokasi binary NSSM/PHP, dan batas waktu task 2 jam (lihat bagian sebelumnya).

---

# Kelola Pengguna & Role + Ganti Password · 2026-09-26

**Verifikasi pagi:**
- Server reboot 06:04 dan semua service naik sendiri, jadi **auto-start terbukti**.
- Task Sync 20:30 (25/09) memakai alur baru temp → gfix → validasi → swap. `STAGING_PREV.GDB` tersimpan, sync PARTIAL (sama seperti sebelumnya), selesai 21:08.

**Fitur baru (live), menu "Pengaturan":**

| Fitur | Endpoint | Permission |
|---|---|---|
| Daftar/cari pengguna (filter role & status) | `GET /api/users` | `user.view` |
| Tambah pengguna (+ password otomatis) | `POST /api/users` | `user.create` |
| Ubah data / role / aktif-nonaktif | `PUT /api/users/{id}` | `user.update` (+ `user.assign_role` untuk role) |
| Reset password (semua sesi user diakhiri) | `POST /api/users/{id}/password` | `user.update` |
| Hapus pengguna | `DELETE /api/users/{id}` | `user.delete` |
| Tambah/ubah/hapus role + atur hak akses | `POST/PUT/DELETE /api/roles` | `role.manage` |
| Ganti password sendiri (semua user; sesi lain diakhiri) | `POST /api/me/password` | login |

**Aturan keamanan (semua diuji):**
- **Akses Super Admin:** hanya Super Admin yang bisa memberi/mencabut role Super Admin atau mengubah, reset, dan menghapus akun Super Admin.
- **Diri sendiri & Super Admin terakhir:** admin tidak bisa menonaktifkan atau menghapus dirinya sendiri, dan Super Admin aktif terakhir tidak bisa dinonaktifkan, diturunkan, atau dihapus.
- **User dengan riwayat:** user yang sudah punya riwayat (`created_by`, `approved_by`, `requester_id`, …) **tidak bisa dihapus permanen**, supaya jejak transaksi tidak hilang; cukup dinonaktifkan. Pengecekannya otomatis ke semua foreign key yang menunjuk ke `users`.
- **Mencabut sesi:** menonaktifkan user atau reset password langsung mencabut semua token login user itu.
- **Aturan password:** minimal 8 karakter, berisi huruf dan angka, dan **password bawaan seeder ditolak**.
- **Role `super_admin`** terkunci.
- **Role bawaan** tidak bisa dihapus. Hak aksesnya bisa diubah, tetapi kembali ke bawaan jika `RbacSeeder` dijalankan ulang.
- **Role custom** hanya bisa dihapus jika tidak dipakai user.
- **Audit:** setiap aksi admin tercatat di log Laravel (`[admin] user.create`, `user.reset_password`, …) tanpa password.

**File:**
- **Backend:** `UserController.php` (baru), `RoleController.php`, `AuthController.php` (`changePassword`), `routes/api.php`, `tests/Feature/Admin/*` (14 test).
- **Frontend:** `features/admin/api.ts`, `pages/UsersPage.tsx`, `pages/RolesPage.tsx`, `components/ChangePasswordModal.tsx`, `AppLayout.tsx` (menu Pengaturan + tombol Ganti Password), `router.tsx`, `pages/UsersPage.test.tsx`.

**Bug diperbaiki:** dropdown "Kategori Anak 1" di Master Barang memuat nilai kosong ganda (peringatan React key).

**Test:**
- **Backend:** 248/248 (salinan terisolasi, SQLite).
- **Frontend:** 9/9 + typecheck.
- **E2E Edge:** 18/18 terhadap salinan terisolasi di `127.0.0.1:8099` dengan SQLite; tidak memakai akun production. Alurnya: buat role → buat user → login user baru (menu sesuai hak akses, `/settings/users` ditolak) → ganti password sendiri (password lama ditolak) → admin reset password & nonaktifkan (login ditolak) → hapus user & role; tanpa error JS.

**Deploy UI:**
- 08:21 dan 08:36, dengan backup `Backup\frontend-public-20260926_082141` dan `…_083649`.
- Rollback: salin `app.html` dari backup ke `backend/public/`.

**Tindak lanjut yang disarankan (dilakukan oleh admin, bukan otomatis):**
1. Login sebagai `superadmin` → **Ganti Password**.
2. **Pengaturan → Pengguna** → **Reset password** untuk 11 akun lain yang masih memakai password bawaan, lalu bagikan password baru ke masing-masing pengguna.

---

# Perbaikan Sync: cepat, valid, tidak membekukan web · 2026-09-26

## Masalah yang ditemukan (diukur di production, read-only)
| # | Masalah | Bukti | Dampak |
|---|---|---|---|
| 1 | **Web membeku selama sync.** `complete` / tombol "Sync Accurate" diproses DI DALAM `php artisan serve` (satu antrian) | 26/09 12:21–13:30: semua request web timeout selama ±70 menit | Tidak ada yang bisa memakai web 3× sehari |
| 2 | **Sangat lambat: ±10 baris/detik**, bahkan untuk baris tanpa perubahan | Batch 9: tahap barang 9.634 baris (100% SKIP) = 963 detik. Setiap baris = 1 insert `sync_logs` autocommit (MySQL `flush_log_at_trx_commit=1`, `sync_binlog=1` → 2× fsync) | ±57 menit per sync; `sync_logs` membengkak ±30.000 baris/sync (172.338 baris) |
| 3 | **Status tidak pernah SUCCESS.** 2547 `stock_opname` selalu gagal `warehouse_id cannot be null` | Tabel `warehouses` STOCKWISE kosong; Accurate punya 1 gudang (`CENTRE`) | Setiap batch PARTIAL/FAILED; stock opname Accurate tidak pernah masuk |
| 4 | **Data mundur.** Agent memilih "backup terbaru yang belum diproses", bukan "lebih baru dari yang terakhir sukses" | 26/09 10:30 memproses `Thursday.gbk` setelah `Friday.gbk` sukses → 45 RI & 21 NPBG dihapus oleh data lama | STOCKWISE bisa kembali ke data kemarin |
| 5 | **Dua sync bisa jalan bersamaan.** Batch >10 menit dianggap macet (`STALE_RUNNING_MINUTES=10`), padahal sync normal ±57 menit | Kode | Sync saling menimpa |
| 6 | **Batch FAILED dicatat SUCCESS oleh agent** | `run.py` tidak memeriksa status akhir | Backup tidak pernah dicoba ulang, kegagalan tersembunyi |

## Perbaikan (live)
1. **Proses di latar belakang.** `complete` (agent) dan tombol web hanya *memicu*; pemrosesan berjalan di proses terpisah `php artisan accurate:finish-batch {id}` (`App\Support\BackgroundArtisan`, PowerShell `Start-Process` tanpa pewarisan handle — terbukti port 8001 tidak ikut terpegang). Agent memantau `GET /api/agent/sync/sessions/{id}`. Klaim atomik: retry `complete` tidak pernah memproses dua kali. Kalau proses gagal diluncurkan → fallback ke cara lama (tetap selesai).
2. **Tanpa log SKIP per baris** (tetap dihitung di ringkasan batch) dan log lain ditulis **sekaligus per 500 baris** → hilang ±95% penulisan ke disk. Isi data yang diproses TIDAK berubah.
3. **Gudang Accurate dipetakan** (`WAREHOUSEID` → gudang STOCKWISE `ACC-CENTRE`, dibuat sekali di site pertama). Jika prasyarat tidak ada: **1** error yang jelas, bukan 2547.
4. **Agent tidak pernah memproses backup yang lebih tua/sama** dengan backup terakhir yang sukses (termasuk duplikat harian/bulanan Accurate pada detik yang sama).
5. **Heartbeat**: batch diperbarui minimal tiap 30 detik; dianggap mati hanya jika diam 20 menit (bukan karena lama). Endpoint status langsung menandai FAILED worker yang mati, sehingga agent tidak menunggu 2 jam.
6. **Agent**: batch FAILED → backup ditandai FAILED dan dicoba ulang jadwal berikutnya.
7. **UI**: modal "Sinkronisasi berjalan" kini menampilkan tahap nyata (web tetap bisa dipakai, modal boleh ditutup); hasil diumumkan saat selesai. Sync terjadwal agent tidak memunculkan modal ke pengguna. Polling status 5 detik saat RUNNING.

**Sengaja TIDAK diaktifkan (perlu keputusan Anda):** pengisian **saldo awal stok** dari qty Accurate untuk barang yang belum punya inventory (`STOCKWISE_SYNC_SEED_OPENING_BALANCE=false`). Fitur ini dulu dirancang atas instruksi Anda, tetapi tidak pernah berjalan karena gudang kosong. Setelah gudang ada, fitur ini akan membuat saldo awal untuk ±9.600 barang sekaligus → perubahan massal angka stok STOCKWISE.

## File
- **Backend:** `AccurateSyncService.php`, `Agent/AccurateIngestController.php` (+ `GET sessions/{id}`), `SyncController.php`, `app/Support/BackgroundArtisan.php` (baru), `Console/Commands/Accurate/FinishSyncBatch.php` (baru), `routes/api.php`, `config/stockwise.php` (`STOCKWISE_SYNC_RUN_INLINE`, `STOCKWISE_SYNC_SEED_OPENING_BALANCE`); test `AccurateSyncReliabilityTest.php` (baru, 7) + `AccurateSyncTest.php` (2 disesuaikan).
- **Agent:** `scanner.py`, `state.py`, `run.py`, `api_client.py`; test `test_sync_reliability.py`, `test_run_batch_status.py` (baru).
- **Frontend:** `features/sync/api.ts`, `components/SyncProgressModal.tsx` (+ test).
- **Backup:** `Backup\backend-sync-20260926_133348\` (backend), `Backup\frontend-public-20260926_133418\` (UI).

## Test
- **Backend:** 256/256 (SQLite terisolasi).
- **Agent:** 67/67.
- **Frontend:** 11/11 + typecheck.
- **Integrasi (salinan terisolasi, agent Python asli):** `complete` dijawab langsung (202), proses `finish-batch` terpisah berjalan dengan heartbeat, web tetap merespons ±0,3–0,5 detik selama sync.

**Belum terbukti di production:** kecepatan akhir di MySQL live dan peluncuran proses latar belakang di bawah akun service (SYSTEM). Keduanya akan terlihat pada sync terjadwal **20:30** (halaman **Log Agen** dan **Sync History**).

**Rollback:** salin file dari `Backup\backend-sync-20260926_133348\` ke `backend\`; untuk mode lama tanpa rollback kode: `STOCKWISE_SYNC_RUN_INLINE=true` di `backend\.env`.

---

# Mode "copy langsung" file GDB (tanpa akun production) · 2026-09-26

**Keputusan user:** salin langsung `D:\GUDANGSIG2025.GDB` ke staging (bukan gbak dengan akun production).

**Cara kerja (`SOURCE_METHOD=filecopy`, `agent/file_snapshot.py`):**
1. **Salin file:** file disalin dengan mode berbagi baca-tulis, sehingga Accurate dan pengguna tidak pernah terhalang.
2. **Verifikasi checksum:** sumber dibaca ulang, dan SHA-256-nya harus sama dengan salinan. Kalau beda, berarti Firebird menulis di tengah penyalinan; salinan dibuang lalu dicoba lagi (`SOURCE_COPY_ATTEMPTS`). Kalau tetap gagal, staging lama tetap dipakai.
3. **Validasi isi Gudang, lalu ganti staging:** gfix dilewati karena hanya boleh dijalankan pemilik database; integritas dijamin oleh checksum + pembacaan penuh 20 tabel saat push.
4. **Membaca salinan:** memakai user **`GUEST`** di server **staging**. GUEST adalah penerima hak `SELECT` dari Accurate (baca saja), dibuat 26/09 dengan persetujuan user; kredensialnya ada di `sync-service/.env` (`SOURCE_READER_*`). User ini juga bisa membaca staging hasil restore `.gbk`, karena hak aksesnya ikut terbawa.

**Temuan teknis:**
- **Pemilik tabel:** tabel Accurate dimiliki `CPS#1`. Hak SELECT diberikan ke `CPS#1`, `GUEST`, dan `POS`, sehingga `stockwise_agent` tidak bisa membaca salinan mentah.
- **Batasan gbak:** gbak backup hanya untuk SYSDBA atau pemilik database, sehingga salinan tidak di-rebuild dan dipakai langsung.

**Test:**
- **Unit:** agent 75/75.
- **E2E dummy (10/10):** dummy dimiliki SYSDBA dengan GRANT ke GUEST, meniru Accurate. Skenario yang lulus:
  - sumber sedang dibuka → sukses 1,6 detik
  - sumber ditulis terus-menerus → **ditolak aman**, staging tidak tersentuh
  - sumber ditulis sesekali → sukses dengan retry
  - log tanpa password
- **Uji nyata validasi saja (19:52, koneksi Accurate 0):**
  - salinan 133,9 MB identik pada percobaan pertama dalam **2,8 detik**
  - validasi OK; GUEST membaca 20/20 tabel (64.740 baris) dalam 2,0 detik
  - salinan dihapus; staging tidak diganti, tanpa sync

**Belum aktif** — perlu persetujuan:
1. Set di `sync-service/.env`: `SYNC_ENV=production`, `SOURCE_COPY_ENABLED=true`, `SOURCE_METHOD=filecopy`.
2. Ganti task `STOCKWISE-Sync-Accurate` (04:00/10:30/20:30 `--sync-now`) dengan jadwal target: 09:00 `--full`, 12:20 `--full`, 21:00 `--snapshot`, 06:30 `--sync-only`.

**Keterbatasan:** saat jam sibuk, Accurate bisa menulis ke file berkali-kali per detik. Salinan bisa gagal lolos checksum pada semua percobaan, dan jadwal itu dilewati tanpa merusak apa pun. Seberapa sering hal ini terjadi baru terlihat setelah dipakai di jam kerja (dicatat di Log Agen).

---

# Sumber data sync: file GDB, bukan .gbk lagi · 2026-09-26 20:18

- Atas permintaan user, run default Sync Agent (`--sync-now`, yang dijalankan task `STOCKWISE-Sync-Accurate`, dan mode loop) kini memakai **salinan langsung file GDB + sync** selama `SOURCE_COPY_ENABLED=true` dan `SOURCE_METHOD=filecopy` (keduanya aktif di `sync-service/.env`). Backup `.gbk` Accurate tidak dipakai lagi.
- Jadwal task **tidak diubah** (04:00 / 10:30 / 20:30; 04:00 praktis tidak jalan karena server mati malam). Jadwal target 06:30 / 09:00 / 12:20 / 21:00 menunggu skrip PowerShell yang dijalankan user.
- **Rollback ke `.gbk`:** `SOURCE_COPY_ENABLED=false` di `sync-service/.env` (tanpa ubah kode/jadwal).
- Tambahan fitur hari ini: halaman **Accurate Online** (PC yang terhubung ke Accurate, putus koneksi manual — permission `accurate.session.view` / `accurate.session.terminate`, migration `2026_09_26_130000_add_accurate_session_permissions`) dan tombol **Salin GDB ke Staging** / **Salin + Sync** di Log Agen (`POST /api/agent-runs`, status dari `Logs\agent-status.json`).
- Run manual pertama `--snapshot` (20:13): salin + checksum + validasi + ganti staging dalam **3 detik**, hasil OK.

---

# Dashboard: logika STOCKWISE diterapkan dari data Accurate (tanpa Excel) · 2026-09-26 21:02

**Masalah:** semua barang BEP dan dashboard kosong. Penyebabnya bukan rumus, tetapi input kosong di produksi:
- `inventory` = 0 baris (stok = 0)
- `item_safety_stocks` = 0 baris (SS = 0)
- lead time 0 barang
- `inventory_snapshots` = 0 baris (analisis tidak pernah dijalankan)

Semua input ini dulu berasal dari Excel (`DATA.xlsx`), yang tidak dipakai lagi.

**Penerapan** (`App\Services\Inventory\AccurateDerivedAnalysis`, command `stockwise:refresh-from-accurate`, otomatis dijalankan setelah setiap sync):

| Input | Sumber | Hasil (26/09 21:02) |
|---|---|---|
| Stok awal | `OPENING_BALANCE` = qty Accurate, hanya untuk barang tanpa stok STOCKWISE | 9.211 barang (2.110 berisi), batch `b8b11ce2-…` |
| Pemakaian | NPBG Accurate 12 bulan, `avg_usage_3m` untuk rumus | 1.921 barang |
| Lead time | median hari PO → RI (link ID, atau nomor PO), dibulatkan ke atas | 1.193 barang |
| Safety Stock / MIN PR | `SafetyStockService` (2,33 × avg_3m × LT/30), baris `source_category=ACCURATE`; baris manual tidak ditimpa | 1.921 baris, 480 dengan SS > 0 |
| Analisis | `InventoryAnalyzer::run()` | AMAN 2.096 · TIDAK AMAN 301 (semua HIGH) · BEP 6.814 |

- **Validasi:** rumus dicek manual pada data nyata (mis. OFN.0076 SS 196, skor 418).
- **Test:** 268/268 lulus (3 test baru + 1 test lama disesuaikan).
- **Backup DB sebelum perubahan:** `Backup\stockwise-db-20260926_135920.sql` (61,7 MB).
- **Rollback data:** restore backup tersebut, atau hapus `stock_movements`/`inventory` dari `batch_uuid` di atas dan hapus `item_safety_stocks` dengan `source_category='ACCURATE'`.

**Catatan / keputusan terbuka:**
1. **208 barang** dipakai tetapi tidak punya riwayat pembelian, sehingga lead time kosong (tidak ditebak) dan SS = 0.
2. **Median defisit = 1,** sehingga semua item TIDAK AMAN menjadi HIGH. Ini sesuai spesifikasi (`deficit ≥ median`), tetapi tidak membedakan prioritas.
3. **Stok setelah saldo awal mengikuti transaksi STOCKWISE,** bukan qty Accurate berikutnya (aturan desain: stok yang sudah dilacak STOCKWISE tidak ditimpa Accurate). Selama transaksi gudang masih dicatat di Accurate, stok STOCKWISE tidak akan ikut berubah.

## Update 28/09/2026 — STPP tampil nomor NPBG, Usulan Pembelian & Penerimaan dinonaktifkan

**STPP (nomor NPBG)**
- `StppController@index` sekarang mengirim `display_number` = nomor NPBG asal (relasi `outNpbg`), fallback ke nomor STPP internal. Pencarian juga bisa pakai nomor NPBG.
- Halaman STPP: kolom "No. NPBG"; di detail ada "No. NPBG" dan "No. STPP (internal)".
- Data NPBG yang berkriteria STPP **tetap ada** di fitur NPBG (tidak difilter). Baris yang berkriteria STPP juga tampil di fitur STPP.

**Usulan Pembelian & Penerimaan belum diaktifkan**
- Backend: 11 route `/api/purchase-proposals*` dan 5 route `/api/receivings*` dibungkus middleware `feature:purchase_proposal` / `feature:receiving`, yang menjawab 403 "Fitur ini belum diaktifkan...".
- Flag `config/stockwise.php`: `purchase_proposal_enabled` dan `receiving_enabled`, default `false`. Env: `FEATURE_PURCHASE_PROPOSAL_ENABLED`, `FEATURE_RECEIVING_ENABLED`.
- Frontend: `src/config/features.ts` (`PURCHASE_PROPOSAL_ENABLED`, `RECEIVING_ENABLED` = false). Halaman `/purchase-proposals*` dan `/receivings*` menampilkan `FeatureDisabledNotice`. Tombol "Buat Penerimaan" di detail PO disembunyikan.
- PPB, PO, dan RI (data Accurate) tetap aktif.
- Mengaktifkan kembali (khusus developer): set kedua env backend = true, ubah konstanta frontend = true, lalu build ulang UI.

**Verifikasi**
- PHPUnit 274/274 (salinan terisolasi, SQLite), termasuk test baru `tests/Feature/Purchasing/PurchasingFeatureFlagTest.php`.
- vitest 15/15; `tsc` OK.
- `route:list`: 16/16 route tergate.
- Backup:
  - `Backup/backend-flags-20260928_082942`
  - `Backup/frontend-public-20260928_083147`
- Bundle UI aktif: `index-COMdqkrF.js`.

## Update 28/09/2026 — Accurate Online: Nama User, Divisi, Keterangan per IP

**Tampilan**
- Tabel Accurate Online sekarang punya kolom **Nama User**, **Divisi**, dan **Keterangan**.
- Setiap baris punya tombol **Edit**.
- Data disimpan per IP. Saat PC dengan IP itu online lagi, datanya langsung tampil.

**Penyimpanan**
- Tabel baru `accurate_ip_labels` di MySQL STOCKWISE: `ip` (unik), `user_name`, `division`, `note`, `updated_by`, timestamps.
- Migrasi dijalankan hanya untuk file itu: `migrate --path=database/migrations/2026_09_28_090000_create_accurate_ip_labels_table.php`. Tidak ada migrasi lain yang tertunda. Tabel Accurate/Firebird tidak disentuh.

**API dan hak akses**
- `PUT /api/accurate/ip-labels` dengan isi `{ip, user_name, division, note}`. Jika semua field kosong, data IP tersebut dihapus.
- Endpoint ini memerlukan permission `accurate.session.terminate` dan setiap perubahan dicatat di log.
- `GET /api/accurate/sessions` menyertakan `user_name`, `division`, dan `note` untuk tiap PC.

**Verifikasi**
- PHPUnit 276/276; vitest 15/15; `tsc` OK.
- Smoke test live: endpoint mengembalikan 200 dengan 20 PC dan field baru ikut terkirim.
- Backup:
  - `Backup/backend-iplabels-20260928_083747`
  - `Backup/frontend-public-20260928_084624`
- Bundle UI aktif: `index-CbeeXQU6.js`.

## Update 28/09/2026 — Stock Opname lebih jelas & mudah

**Backend**
- `GET /api/stock-opnames`: filter baru `source=internal|accurate` dan `search` (nomor opname).
- Pesan error submit sekarang lebih jelas:
  - menyebut berapa barang yang belum diisi
  - menyebut kode barang yang selisih tapi belum diberi catatan
  - tidak pernah menampilkan stok sistem, sehingga blind count tetap terjaga
- Tes: 2 tes baru ditambah dan satu tes lama diperluas, 22 tes Stock Opname lulus.

**Frontend**
- Status dalam Bahasa Indonesia: Terjadwal, Sedang Dihitung, Menunggu Review, Perlu Hitung Ulang, Selesai.
- Opname dari Accurate: `PENDING_REVIEW` tampil "Belum dicek di Accurate", `COMPLETED` tampil "Final di Accurate". Sebelumnya status mentah tanpa tombol aksi.
- Daftar:
  - tab "Opname STOCKWISE" / "Dari Accurate"
  - panduan alur 4 langkah
  - progres berupa bar
  - pencarian nomor
  - seluruh baris bisa diklik
  - tombol "Jadwalkan Opname"; setelah menjadwalkan, halaman opname baru langsung terbuka
- Detail:
  - stepper alur
  - kotak "langkah berikutnya" sesuai status dan hak akses
  - progres hitung
  - filter chip: Belum diisi, Sudah diisi, Selisih, Hitung ulang
  - status "Tersimpan" per baris, dan Enter pindah ke barang berikutnya
  - tampilan kartu di HP
  - bar aksi menempel di bawah
  - konfirmasi sebelum kirim dan review, dengan ringkasan dampak ke stok
  - aksi massal "Setujui semua" dan "Hitung ulang semua yang selisih"
- Menyimpan hitungan tidak lagi memunculkan toast di setiap kolom dan tidak memuat ulang semua baris; cache diperbarui dari respons server.
- vitest 18/18 (3 tes baru `OpnameDetailPage.test.tsx`); `tsc` OK.

**Backup**
- `Backup/backend-opname-20260928_125328`
- `Backup/frontend-public-20260928_125836`
- Bundle UI aktif: `index-CYrFelDi.js`.

## Update 28/09/2026 — Stock Opname: tabel per barang

- Endpoint baru `GET /api/stock-opnames/lines` untuk tabel per barang lintas dokumen, diurutkan dari yang terbaru.
  - Filter: `source`, `status`, `search` (kode/nama barang/no. dokumen), `date_from`/`date_to`, dan `only_diff` (khusus reviewer).
  - Aturan blind count sama dengan detail: qty sebelum/stok sistem dan selisih hanya dikirim untuk `opname.review`.
- `GET /api/stock-opnames`: filter tanggal baru, dan cuplikan `first_item` per dokumen.
- Daftar Stock Opname:
  - default tab "Dari Accurate" dengan tampilan **Per barang**: Tanggal, No. Dokumen, Barang, Satuan, Qty sebelum, Qty sesudah, Selisih (hijau +/merah −), Status
  - tampilan "Per dokumen" menampilkan nama barang dan "+N barang lainnya"
  - filter tanggal, pencarian, dan tombol reset filter
  - header tabel menempel saat di-scroll
  - kartu di HP
- Detail dokumen Accurate: kolom Qty sebelum, Qty sesudah, dan Selisih. Kolom Catatan dan Review yang selalu kosong untuk data Accurate disembunyikan.
- Performa di data live (2.799 baris Accurate): 9–73 ms per halaman.
- Tes:
  - PHPUnit 280/280, termasuk 2 tes baru
  - vitest 21/21, termasuk 3 tes baru di `OpnameListPage.test.tsx`
- Backup:
  - `Backup/backend-opnamelines-20260928_131126`
  - `Backup/frontend-public-20260928_131509`

## Update 28/09/2026 — Dashboard dirapikan, halaman Sync & Histori Accurate, divisi & peminta PPB

**Dashboard**
- Dihapus:
  - kartu yang bertentangan dengan analisa inventory ("Status AMAN" yang ikut menghitung BEP)
  - kartu dan grafik fitur nonaktif (Request, Usulan Pembelian, Penerimaan)
  - Total Stok dan Total Batas Aman (menjumlahkan satuan berbeda)
  - grafik per gudang (hanya 1 gudang)
  - PPB per Divisi
  - Pergerakan Stok 14 Hari
- Filter dipisah: filter utama selalu tampil, sisanya di "Filter lanjutan".

**Sync & Histori Accurate**
- Halaman Sync Accurate digabung ke Sync History dengan nama menu "Sync & Histori Accurate".
- `/sync/accurate` diarahkan ke `/sync/history`.
- Jadwal di halaman diperbaiki menjadi 06:30, 09:00, 12:20, dan 21:00 (salinan).

**Divisi & peminta PPB**
- Penyebab: divisi diambil dari segmen nomor dokumen (`PPB/NA/..`, `PPB/ATK/..`), padahal itu kode penomoran. PPB baru di Accurate memakai Departemen (`REQUISITIONDET.DEPTID`). Peminta (`ITEMRESERVED3`) hanya diisi di baris pertama.
  - Dicek dari backup DB 26/09 tanpa akses production.
- Perbaikan sync PPB:
  - divisi = `DEPARTMENT.DEPTNAME` untuk semua baris PPB
  - PPB lama tanpa departemen tetap memakai segmen nomor
  - peminta dari baris pertama diterapkan ke semua baris PPB
- Agen: `DEPARTMENT` ditambahkan sebagai `OPTIONAL_MIRROR_TABLES`. Kalau gagal dibaca, hanya dicatat peringatan dan sync tetap berjalan.
- Tes & deploy (dijalankan user lewat `Backup/lanjut-20260928.sh`, karena terminal Claude tidak tersedia):
  - `php -l` OK
  - PHPUnit 283/283, termasuk `AccuratePpbDivisiTest` (3 tes)
  - `py_compile` OK
  - vitest 23/23
  - UI terdeploy dengan backup `Backup/frontend-public-20260928_200352`, bundle `index-BuzoDlHP.js`
- Belum terverifikasi: apakah user GUEST staging bisa membaca `DEPARTMENT`. Ini terlihat di Log Agen pada sync berikutnya: "Pushed DEPARTMENT" berarti berhasil, "Optional table DEPARTMENT skipped" berarti tidak ada izin baca.
- Catatan: `config/app.php` mengunci `timezone` = UTC dan mengabaikan `APP_TIMEZONE`. Sengaja tidak diubah, karena akan menggeser data waktu yang sudah tersimpan.

## Update 29/09/2026 — Divisi PPB aktif & tampilan lebih ramah pengguna

**Divisi PPB**
- Sync 06:30 dan 09:00 menolak tabel `DEPARTMENT` dengan pesan *"not a whitelisted Accurate mirror table"*. Penyebabnya: `config/accurate.php` punya whitelist sendiri. Agen berhasil membacanya, jadi izin GUEST sudah OK.
- Perbaikan: `DEPARTMENT` ditambahkan ke `accurate.mirror_tables`. Tes kesetaraan whitelist kini mencakup `MIRROR_TABLES` dan `OPTIONAL_MIRROR_TABLES`. PHPUnit 283/283.
- Satu kali Salin + Sync manual (`agent.run --full`, sama dengan tombol Log Agen): *Pushed DEPARTMENT: 88 row(s)*, batch `SYNC-20260929-002` SUCCESS.
- Hasil: 47 baris PPB berdivisi dari departemen (GUDANG 40, MAINTENANCE KENDARAAN 5, GENERAL SERVICES 1, IT DEVELOPER 1). Peminta kosong tinggal 81 baris, karena memang tidak diisi di Accurate.

**Tampilan**
- Tombol "Sync Accurate" di NPBG/PPB/PO/RI/Stock Opname diganti komponen `AccurateDataNote`, yang berisi jadwal update otomatis, waktu sync terakhir beserta status, dan tautan ke halaman sync. Sync hanya dari halaman Sync & Histori Accurate.
- Keterangan halaman memakai bahasa sehari-hari. Contohnya "Mirror ARINV / ARINVDET" menjadi "Nota pengeluaran barang dari gudang".
- Label status dalam Bahasa Indonesia:
  - PPB: Terbuka/Selesai
  - filter status PO
  - sync: Berhasil/Sebagian/Gagal
  - riwayat sync: Baru/Diubah/Dihapus/Tidak berubah
- Divisi PPB "NA"/"ATK" (kode penomoran) tampil "—". Hanya nama departemen Accurate yang ditampilkan.
- Tes: vitest 23/23.
- Backup:
  - `Backup/frontend-src-20260929_100537`
  - `Backup/frontend-public-20260929_101255`
- Bundle UI aktif: `index-Cr5B0teg.js`.

## Audit 29/09/2026 — pembacaan data Accurate & logika perhitungan

Metode: setiap pemetaan kolom Accurate → STOCKWISE dicocokkan dengan data staging asli (read-only), termasuk `ITEMHIST` sebagai sumber kebenaran pergerakan stok.

**Sudah benar (terverifikasi)**
- Stok STOCKWISE = qty Accurate untuk semua barang (0 selisih). Barang `SUSPENDED` = nonaktif.
- Stock Opname: `CURRENTQTY` = sebelum, `NEWQTY` = sesudah (2.794/2.800 cocok). 6 baris janggal berasal dari barang satuan CMS dengan rasio 1.000–10.000 (data Accurate).
- PO: qty diterima (`QTYRECV`) = total RI per baris PO, cocok 2.379/2.379.
- Lead time = median PO → RI. RI non-vendor (NV) dan RI tanpa PO tidak ikut. RI yang tanggalnya sebelum PO dibuang.
- STPP dari NPBG: 196 = 196.
- Health Score dan KPI: BEP dihitung sebagai AMAN (aturan bisnis, dikonfirmasi user 29/09).

**Diperbaiki**
1. **Rasio satuan NPBG.** Accurate memindahkan stok sebesar `QUANTITY × UNITRATIO` (terbukti 8.781/8.781 baris NPBG dan 4.938/4.938 baris RI terhadap `ITEMHIST`). STOCKWISE sebelumnya menjumlah `QUANTITY` saja, sehingga pemakaian rata-rata dan safety stock terlalu kecil untuk barang yang keluar dalam SET/PCK/BOX (23 baris).
   - Perbaikan: kolom baru `npbg.unit_ratio` (migrasi aditif `2026_09_29_100000`).
   - `SafetyStockService::usageAveragesFromNpbg` menghitung `kuantitas × unit_ratio`.
2. **"Dikirim ke" NPBG terpotong.** Accurate memecah teks panjang ke `SHIPTO1` + `SHIPTO2` (203 dokumen). Sekarang digabung.
3. **Status PO.** PO yang ditutup di Accurate setelah diterima sebagian tetap berstatus "Diterima sebagian" (13 PO) dan terhitung sebagai "PO berjalan". Sekarang `CLOSED` didahulukan.
4. **Dashboard "Opname aktif"** menghitung status `SUBMITTED` yang tidak pernah ada. Sekarang: opname internal `SCHEDULED`/`IN_PROGRESS`/`PENDING_REVIEW`/`RECOUNT_REQUIRED`.
5. **Dashboard "RI bulan ini"** memakai jam UTC. Sekarang memakai kalender WIB.
6. **Label Stock Opname Accurate.** `ADJCHECK` = 1 hanya ada pada 2.111 dokumen saldo awal (31/12/2024). Itu bukan tanda "sudah dicek". Label sekarang "Saldo awal" / "Penyesuaian stok".
7. **RI "Divisi" → "Jenis RI".** Segmen NA/ATK/NV adalah jenis penerimaan (NA/ATK selalu ber-PO; NV = non-vendor/pengembalian), bukan divisi. Logika pengembalian bekas yang memakai kode NV tidak diubah.
8. **KPI "Aman"** di dashboard inventory = AMAN + BEP, dengan keterangan jumlah BEP.

**Catatan (tidak diubah)**
- Nama divisi NPBG di Accurate tidak seragam, misalnya "MAINTENANCE" dan "MAINTENANCE (SDA)". Datanya diambil apa adanya.
- `config/app.php` timezone = UTC. Tidak diubah karena akan menggeser data waktu yang sudah tersimpan.

**Verifikasi**
- PHPUnit 287/287, termasuk `AccurateDataAuditFixesTest` (4 tes).
- vitest 23/23.
- Backup: `Backup/frontend-public-20260929_110622`.

**Hasil proses ulang (batch 24, SUCCESS)**
- 23 baris NPBG kini memiliki rasio satuan ≠ 1.
- "Dikirim ke" utuh, contoh: "W 8348 QG (MITSUBISHI RAGASA)".
- Analisis inventory dihitung ulang.

## Update 29/09/2026 — Perbaikan Peminjaman (Lend) & Pinjam Luar (Borrow)

**Bug yang diperbaiki**
1. **Qty dikirim sebagai teks** ("1.00") karena model tidak meng-cast desimal. Akibatnya pengecekan UI `qty_returned === 0` selalu gagal, tombol **Ubah/Hapus tidak pernah muncul**, dan tabel menampilkan "0.00/1.00".
   - Perbaikan: cast `qty`/`qty_returned` → float di `LendTransaction` dan `BorrowTransaction`.
2. **Barang dipilih dari Master Barang** tapi keterangan tersimpan "-" dan satuan kosong. Sekarang keterangan dan satuan diambil dari data barang.
3. **Status "Lewat jatuh tempo" tidak pernah terjadi**, karena `markOverdue()` tidak pernah dipanggil dan tidak ada scheduler di server ini. Sekarang dijalankan setiap kali daftar atau detail Lend dibuka. Tidak ada scheduler baru.
4. **Status tampil mentah** (ON_LOAN/BORROWED/...). Sekarang: Dipinjamkan, Kembali sebagian, Sudah kembali, Lewat jatuh tempo, Dipinjam. Filter status dan label tujuan (Relasi/Proyek/Internal) juga ikut diterjemahkan.

**Verifikasi**
- PHPUnit 290/290, termasuk `LendBorrowFixesTest` (3 tes).
- vitest 23/23.
- Backup: `Backup/frontend-public-20260929_111637`.

## Update 29/09/2026 — Lend & Borrow otomatis dari Accurate

**Latar belakang**
- Tabel Lend dan Borrow kosong, karena dulu diisi dari Excel yang sudah tidak dipakai.
- Keputusan user: diisi otomatis dari Accurate, tanpa aksi admin.

**Aturan** (`App\Services\Tracking\LendBorrowFromAccurate`, dijalankan tiap sync pada tahap `lend_borrow`):

| Dokumen | Keterangan mengandung | Hasil | Contoh keterangan asli |
|---|---|---|---|
| NPBG | "PINJAM", tanpa kata pengembalian | Lend keluar | "UNTUK DIPINJAMKAN KE CDO", "CDO PINJAM UNTUK PEMAKAIAN" |
| RI | "PINJAM" + kata pengembalian | Lend kembali | "PENGEMBALIAN … REF NPBG", "KEMBALI SETELAH DIPINJAMKAN", "TIDAK JADI DIPINJAMKAN" |
| RI | "PINJAMAN DARI" / "PINJAM … DARI" / "PINJAM KE" | Borrow masuk | — |
| NPBG | "MENGEMBALIKAN/KEMBALIKAN/PENGEMBALIAN PINJAMAN" | Borrow kembali | — |

- Kata pengembalian: MENGEMBALIKAN, KEMBALIKAN PINJAMAN, PENGEMBALIAN, KEMBALI SETELAH, TIDAK JADI, BATAL.
- Pencocokan pengembalian, berurutan: nomor dokumen yang dikutip (REF NPBG/REF RI), lalu pihak yang sama, lalu pinjaman tertua. Barang harus sama, dan pinjaman tidak boleh bertanggal setelah pengembaliannya.
- "ESTIMASI n HARI" menjadi jatuh tempo.
- Dihitung ulang penuh setiap sync (idempoten). Baris MANUAL tidak disentuh. Pinjaman yang dokumen asalnya hilang dari Accurate ikut terhapus.
- Baris ACCURATE bersifat hanya-baca: ubah, hapus, dan catat kembali ditolak dengan pesan untuk mengubahnya di Accurate.

**Migrasi `2026_09_29_120000`** (kedua tabel masih kosong)
- FK `lend.out_npbg_id` dan `borrow.return_npbg_id` dipindah dari `goods_issues` ke `npbg`, pola yang sama dengan STPP.
- Kolom baru: `source`, `return_refs`, dan `borrow.in_ri_id`.

**Hasil pengisian di data live**
- Lend: 164 baris (145 masih dipinjam, 19 sudah kembali).
- Borrow: 7 baris (4 masih dipinjam, 3 sudah kembali).
- Contoh: NPBG NA/26/IV/016 "minus mur & per" → handwheel 10/10 kembali, mur dan per 0/10.
- Run ulang: 171 dilewati, 0 berubah.

**Verifikasi**
- PHPUnit 293/293, termasuk `LendBorrowFromAccurateTest` (3 tes, memakai keterangan asli).
- vitest 23/23.
- Backup UI: `Backup/frontend-public-20260929_113010`.

**Keterbatasan:** pengenalan berbasis kata pada teks bebas. Keterangan yang tidak memakai kata "pinjam" tidak akan terbaca.

## Update 29/09/2026 — Modul tracking disesuaikan dengan Excel referensi

Excel (3. Borrow & Lend, 5. Ban Luar, 6. Maintenance Assets, 7. Manufaktur & Assembly) hanya dipakai sebagai **acuan format kolom**, tidak diimpor. Data diambil dari database Accurate bila tersedia. Selebihnya diisi manual.

| Modul | Dari Accurate (otomatis tiap sync) | Input manual |
|---|---|---|
| Lend | Tgl pinjam, tanda keluar (NPBG), barang, qty, satuan, **peminta** (NPBG.peminta), dipinjamkan ke, ket. keluar, tanda/tgl kembali (RI), **ket. kembali** | Keperluan, Est. pinjam (hari). Tidak tertimpa sync. |
| Borrow | Tgl pinjam, tanda terima (RI), barang, qty, vendor, keterangan, tanda keluar kembali (NPBG), **ket. barang kembali** | — |
| Ban Luar (baru, `TyreFromAccurate`) | NPBG "BAN LUAR" ke kendaraan: nopol, tgl & no. NPBG, ban baru, posisi, no. seri (dibaca dari keterangan), ket. keluar. RI NV ban bekas ("REF NPBG"): tgl & no. RI, ban lama, no. seri bekas, ket. kembali, status CLEAR. | No. seri, posisi, deskripsi (melengkapi; tidak tertimpa sync). Penambahan manual untuk data di luar Accurate. |
| Maintenance | No. NPBG per Sub SPK (dicek dan ditampilkan isinya) | No. SPK (otomatis **MK/yy/bulan/urut**), nopol, tgl laporan, bengkel, keterangan awal, permintaan, status, tgl selesai, keterangan akhir |
| Manufaktur & Assembly / Jasa | No. NPBG dan No. RI per tahapan (dicek dan ditampilkan isinya) | No. **MA/…** atau **MJ/…** (otomatis), tanggal, hasil produk, lokasi, item no., no. seri, proses, keterangan awal/akhir, tgl selesai |

**Teknis**
- Migrasi `2026_09_29_130000_tracking_excel_alignment` (semua tabel yang disentuh masih kosong):
  - FK NPBG/RI di tyre, maintenance, dan manufacturing dipindah dari `goods_issues`/`receivings` ke `npbg`/`ri`
  - `asset_id` dibuat opsional, ditambah `nopol_raw`
  - kolom baru: `requester_name`, `return_note`, `location`, `request_note`, `npbg_no`/`ri_no`, `source`, `out_note`/`in_note`
- Endpoint pencarian `/api/tracking/vehicles` (nopol dari NPBG/RI) dan `/api/tracking/document?type=npbg|ri&no=`.
- Langkah sync baru `tyres`. Turunan Lend/Borrow/Ban dibungkus `deriveSafely()`: bila gagal, tercatat 1 error dan sync tetap selesai.
- No. seri placeholder ("MENYUSUL", "-", "TIDAK ADA") diperlakukan kosong.

**Hasil data live**
- Ban Luar: 145 ban (132 CLEAR, 13 menunggu RI). No. seri baru terbaca di 138 ban, no. seri lama di 127, posisi di 100.
- Lend: peminta terisi di 99 dari 164 baris.

**Verifikasi**
- PHPUnit 298/298, termasuk `TrackingExcelAlignmentTest` (5 tes).
- vitest 23/23.
- Backup UI: `Backup/frontend-public-20260929_115225`.

## Update 29/09/2026 — Karakter khusus Accurate (Ø, °, ², ³, NBSP)

**Pemeriksaan**
- Byte asli dibaca dari salinan staging (read-only, GUEST, `CAST … AS OCTETS`). Ø = 0xD8 (419 barang); karakter lain: ° 0xB0, ² 0xB2, ³ 0xB3, NBSP 0xA0.
- Agen membaca dengan charset WIN1252, sehingga semua byte terdekode benar.
- STOCKWISE identik dengan Accurate: 0 selisih deskripsi `items` vs `accurate_item`, tidak ada mojibake.
- "?" (0x3F) di 108 deskripsi memang tanda tanya asli di Accurate (mis. "MWP ?", "/ ?"), bukan karakter hilang.

**Masalah nyata dan perbaikannya**
- Masalah: Ø tidak bisa diketik dengan keyboard, dan NBSP tidak cocok dengan spasi biasa, sehingga pencarian gagal.
- Perbaikan: macro `whereLoose`/`orWhereLoose` (`App\Support\LooseSearch`) membandingkan Ø/ø/⌀ sebagai O dan NBSP sebagai spasi. Dipakai di 15 pencarian deskripsi: barang, inventory, safety stock, NPBG (deskripsi & keterangan), PPB, RI, stock opname, Lend, Ban Luar, dan Pengembalian Bekas. Data tersimpan tidak diubah.

**Verifikasi**
- Live (MySQL): "O.T = (35/15)" → 1 barang; "1200 x 6mm" → 1 barang (NBSP); NPBG "O.P.T" → 9 baris. Waktu 22–49 ms.
- PHPUnit 300/300, termasuk `LooseSearchTest` (2 tes).

## Update 29/09/2026 — Tabel scroll internal & Bukti Keluar Barang dinonaktifkan

**Tabel scroll internal**
- Semua tabel sekarang di-scroll di dalam tabel: tinggi 70vh, header menempel, dan data halaman berikutnya dimuat otomatis di dasar tabel (`useInfiniteRows`/`useInfiniteSlice`, `LoadMoreSentinel`).
- Tombol Sebelumnya/Berikutnya dihapus dari 17 tabel.
- Server tetap memakai paging per halaman.
- vitest 26/26, termasuk `InfiniteRows.test.tsx`.
- Catatan: saat pengujian, satu tes sempat berulang tanpa henti. Enam proses vitest liar (~1,8 GB) dihentikan (hanya proses vitest), lalu tes dan hook diperbaiki supaya tidak re-render bila data sama.

**Bukti Keluar Barang belum diaktifkan**
- 9 route `/api/goods-issues*` dibungkus `feature:goods_issue`, sehingga menjawab 403 "belum diaktifkan".
- Flag `goods_issue_enabled` default false (env `FEATURE_GOODS_ISSUE_ENABLED`).
- Frontend: `GOODS_ISSUE_ENABLED = false`, halaman menampilkan `FeatureDisabledNotice`, dan kartu dashboard "Siap diambil"/"Sedang disiapkan" disembunyikan.
- PHPUnit 301/301, termasuk tes flag baru.
- Backup:
  - `Backup/backend-goodsissue-20260929_151513`
  - `Backup/frontend-public-20260929_151617`

## Update 29/09/2026 — Dashboard visualisasi untuk semua role

- `GET /api/dashboard/inventory` tidak lagi memerlukan `inventory.view_analysis`, cukup login (auth:sanctum).
- Endpoint baru `GET /api/dashboard/inventory/filters` berisi pilihan kategori, satuan, dan gudang, sehingga filter dashboard berfungsi tanpa izin Master Barang atau master data.
- Grafik Status PO di `/api/dashboard` kini untuk semua role (sebelumnya hanya `po.view`).
- Frontend: bagian Kondisi Stok, Pemakaian NPBG, dan Pengadaan selalu tampil. Kartu Ringkasan Operasional tetap mengikuti izin masing-masing.
- Halaman Analisis Inventory (detail per barang) tetap memerlukan `inventory.view_analysis`.
- Verifikasi:
  - live sebagai `karyawan`: 9.215 barang, filter 11 kategori, 27 satuan, 1 gudang
  - PHPUnit 301/301, vitest 26/26
  - backup UI: `Backup/frontend-public-20260929_153413`

## 2026-09-30 — Status PPB per baris (Amend / Close / Completed / Requested / Shortage)

- Aturan mengikuti rumus kolom Status di Excel "1. PPB - RI". Excel hanya dipakai sebagai referensi; datanya tetap dari Accurate REQUISITION/REQUISITIONDET.
- Pemetaan status ke data Accurate:

  | Status | Kondisi di Accurate |
  |---|---|
  | **Requested** | PPB terbuka, `QTYRECEIVED` = 0 |
  | **Shortage** | PPB terbuka, 0 < diterima < diminta (misalnya datang 10 dari 15, 5 menyusul) |
  | **Completed** | diterima ≥ diminta |
  | **Close** | PPB ditutup setelah diterima sebagian |
  | **Amend** | kode barang tercantum di keterangan header "PPB AMEND (…)", **atau** PPB ditutup tanpa ada barang yang diterima (tidak jadi dibeli) |
  | **Perlu dicek** (Error di Excel) | baris ditandai AMEND tetapi sudah ada RI |

- Perubahan:
  - kolom baru `ppb.line_status` (migrasi additive `2026_09_30_090000`)
  - logika di `App\Support\PpbLineStatus`, dihitung ulang di setiap sync
  - filter API `?line_status=`
  - grafik dashboard dan kartu "PPB menunggu barang" (Requested + Shortage)
  - UI: kolom Status, filter status, dan kolom "Diterima (RI)" di daftar PPB; status beserta penjelasan di halaman detail
- Hasil live (2.550 baris):

  | Status | Jumlah baris |
  |---|---|
  | Completed | 2.157 |
  | Requested | 181 |
  | Amend | 149 |
  | Close | 47 |
  | Shortage | 16 |
  | Error | 0 |

- Verifikasi:
  - PHPUnit 303/303, termasuk `PpbLineStatusTest` yang baru
  - vitest 26/26, tsc bersih
  - backup UI: `Backup/frontend-public-20260930_084319`
- Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler.

## 2026-09-30 — Manufaktur & Assembly jadi satu fitur, mengikuti Excel "7. Tracking Manufaktur & Assembly"

- Satu menu "Manufaktur & Assembly" dengan dua tab sesuai sheet Excel:
  - "Manufaktur & Assembly (MA)" (kind ASSEMBLY)
  - "Manufaktur & Jasa Lain-Lain (MJ)" (kind JASA)
- Tabel: satu baris per unit, urutan kolom sama dengan tiap sheet (MJ: Item No sebelum Lokasi).
- Rumus Excel yang diterapkan (`App\Support\ManufacturingExcel`):

  | Rumus | Aturan |
  |---|---|
  | Status Hasil Pengerjaan | COMPLETED bila foto hasil + tgl selesai + No. RI lengkap → ON-GOING bila No. NPBG terisi → REQUESTED bila hasil produk + no. seri + proses terisi → "Belum lengkap" (di Excel kosong) |
  | Nomor | MA/YY/bulan-romawi/NNN dan MJ/…, urut per bulan |
  | Item No | berurutan per sheet |
  | Sub | SUB-01; SUB-02 = batch NPBG berikutnya untuk unit yang sama |
  | Helper Nama File | MA `HASIL [SERI]_ItemNo`; MJ `MJ.25.VIII.021_SUB-01_ItemNo` — dipakai sebagai nama foto |
  | Status nomor MA/MJ | gabungan status semua barisnya |

- Pilihan isian (hasil produk, proses, lokasi) diambil dari sheet "Dropdown List".
- No. NPBG/RI divalidasi terhadap data Accurate.
- Foto hasil: upload diperkecil di browser, disimpan di storage lokal aplikasi (`manufacturing/<kind>/<id>/<Nama File>.jpg`), dibuka lewat API ber-token.
- Export Excel: dua sheet dengan kolom sesuai file referensi, termasuk Nama File dan Foto Hasil.
- Migrasi `2026_09_30_110000_manufacturing_excel_rows` (dijalankan live, tabel dalam keadaan kosong: 0 order/0 baris):
  - tambah kolom `product_name` dan `photo_path` di `manufacturing_order_subs`
  - unique (order, sub_no) diganti index biasa, karena satu SUB berisi banyak unit
- Verifikasi:
  - PHPUnit 306/306, termasuk `ManufacturingExcelTest` yang baru
  - vitest 26/26, tsc bersih
  - backup UI: `Backup/frontend-public-20260930_085913`
- Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler.

## 2026-09-30 — Manufaktur disamakan dengan Excel + pilih NPBG/RI dari daftar + kolom Catatan

- **Pilih NPBG / RI dari daftar**, berlaku di semua form tracking:
  - input menampilkan daftar dokumen Accurate (terbaru dulu), dicari per kata di nomor, keterangan, tujuan/vendor, dan nama barang, lalu klik untuk memilih
  - endpoint baru `GET /api/tracking/documents` (read-only ke mirror NPBG/RI)
- **Manufaktur & Assembly** — kolom yang diisi otomatis dari dokumen yang dipilih (tetap bisa diedit):

  | Dokumen | Kolom yang terisi | Kecocokan diukur dari 760 baris Excel |
  |---|---|---|
  | NPBG | Tanggal | ±90% |
  | NPBG | Keterangan Awal | ±80% |
  | NPBG | Lokasi (MJ; tujuan NPBG bila bukan MAINTENANCE/GUDANG) | — |
  | NPBG | No. Seri: satu baris per SIG-xxx, termasuk rentang "SIG-033 s.d SIG-042" | — |
  | NPBG | Hasil Produk, bila disebut di keterangan | — |
  | RI | Tgl Selesai | MJ 89%, MA 50% |
  | RI | Keterangan Akhir | ±71% |

  - Kolom "No" (urutan baris di sheet, tidak berubah saat difilter) ditambahkan.
  - "Hari ini" dan "No. MA/MJ berikutnya" ditampilkan seperti baris 4 Excel.
- **Kolom "Catatan"** di setiap tabel data:
  - dipasang di semua modul tracking dan di daftar PPB, NPBG, RI, PO, Barang, Analisa, Safety Stock, Opname, Request, Bukti Keluar, Penerimaan, dan Usulan
  - tabel baru `record_notes` (entity + key baris + catatan + penulis); data sumber, termasuk mirror Accurate, tidak diubah
  - endpoint `GET/PUT /api/record-notes`, bisa dipakai semua user yang login
- **Migrasi live:** `2026_09_30_130000_create_record_notes_table` (tabel baru).
- **Verifikasi:**
  - PHPUnit 308/308, vitest 26/26, tsc bersih
  - backup UI: `Backup/frontend-public-20260930_091604`
- Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler.

## 2026-09-30 — Data Manufaktur & Assembly diisi dari Excel terbaru + kolom/isian sama persis + link ke database

- **Sumber:** file terbaru `7. Tracking Manufaktur & Assembly.xlsx` (diganti user 30-09 09:22). Excel dijadikan sumber data atas permintaan eksplisit user ("tolong isi data").
- **Import:** `php artisan stockwise:import-manufacturing [path] [--dry-run]`
  - bisa dijalankan ulang; baris dicocokkan per Item No, karena kolom "No" di Excel berisi angka 456 ganda (×6)
  - hasil import:

    | Sheet | Baris | Nomor | Status | NPBG ter-link | RI ter-link | Hasil Produk = barang Accurate |
    |---|---|---|---|---|---|---|
    | MA | 470 | 113 | 466 COMPLETED, 4 ON-GOING | 316 | 317 | 152 |
    | MJ | 295 | 117 | 282 COMPLETED, 11 ON-GOING, 2 REQUESTED | 227 | 217 | 191 |

  - status = nilai rumus yang tersimpan di Excel (identik); setelah baris diedit, status dihitung ulang dengan rumus yang sama
  - "-" yang diketik di Excel dihitung sebagai terisi
  - penomoran MA/MJ berikutnya melanjutkan Excel: MA/26/IX/001, MJ/26/IX/002
  - backup sebelum import: `Backup/manufacturing-<ts>/` (tabel kosong, `document_sequences` 29 baris)
- **Kolom baru** (migrasi additive `2026_09_30_150000`): `sheet_no` (No), `row_date` (Tanggal), `row_location` (Lokasi), `file_name` (Nama File), `item_id`, `source`.
- **Aturan COMPLETED mengikuti Excel:** Nama File (Foto Hasil) + Tgl Selesai + No. RI. Upload foto di aplikasi otomatis mengisi Nama File. Foto asli Excel ada di folder "DATA BUKTI" yang tidak ada di server, jadi ditampilkan "Open File (folder Excel)".
- **Link ke data yang ada:**

  | Kolom | Tujuan link |
  |---|---|
  | No. NPBG / No. RI | dokumen Accurate; dokumen sebelum 20-09-2025 tetap teks |
  | Hasil Produk | halaman Barang (kode Accurate) |
  | No. Seri | Tracking Ban Luar / STPP |

- **Isian baru:**
  - daftar pilihan per kolom seperti validasi dropdown Excel: nilai yang pernah dipakai, ditambah pencarian barang Accurate (Hasil Produk), vendor (Lokasi), dan nomor seri ban/STPP
  - Nama File berupa dropdown berisi nama helper
  - endpoint `GET /api/manufacturing-lines/options`
- **Verifikasi:**
  - PHPUnit 309/309, vitest 26/26
  - backup UI: `Backup/frontend-public-20260930_094806`
- Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler. File GDB lain di D:\ tidak disentuh.

## 2026-09-30 — Folder "1. Dashboard Input Data": foto Manufaktur dihubungkan

- User menaruh folder kerja `1. Dashboard Input Data/` (±801 MB) berisi:
  - 8 Excel tracking
  - DATA BUKTI (foto per modul)
  - TRACKING PROJECT
- Folder dimasukkan ke `.gitignore` supaya tidak ter-commit.
- Excel Manufaktur di folder itu identik (md5) dengan yang sudah diimport.
- **Foto Hasil Manufaktur:** `php artisan stockwise:import-manufacturing --photos-only`
  - cara kerja: foto dicari berdasarkan Nama File, lalu disalin ke storage aplikasi (`storage/app/private/manufacturing`, 89 MB); subfolder sheet sendiri diutamakan, foto yang diunggah di aplikasi tidak ditimpa
  - hasil: 752 dari 753 baris ber-Nama File kini punya foto; yang tidak ditemukan hanya `MJ.26.II.010_SUB-01_178` (tidak ada di folder)
  - folder sumber hanya dibaca
- **Modul lain belum dikerjakan** (menunggu keputusan user):

  | Modul | Foto | Data di aplikasi |
  |---|---|---|
  | Maintenance | 707 | 0 |
  | Ban Luar | 515 | 145 |
  | STPP | 829 | 196 |
  | Pengembalian Bekas | 777 | 1.047 |

- **Verifikasi:** PHPUnit 309/309. Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler.

## 2026-09-30 — Semua tracking dipindah dari folder "1. Dashboard Input Data" ke database STOCKWISE

Atas permintaan user: "all pindah datanya ke database … full by database dan stockwise", lalu dikoreksi menjadi "untuk all tracking saja dahulu". PPB, NPBG, dan TRACKING PROJECT tidak disentuh.

- **Command:** `php artisan stockwise:import-tracking {maintenance|stpp|tyre|used-return|lend-borrow} [--dry-run]` (Manufaktur: `stockwise:import-manufacturing`).
  - Bisa dijalankan ulang: data diperbarui, tidak dobel.
  - Setiap modul di-dry-run dulu. Jumlah status di setiap sheet sama dengan nilai rumus yang tersimpan di Excel.
  - Folder sumber hanya dibaca.
- **Infrastruktur bersama:**
  - `tracking_photos`: foto per baris dan slot, di storage aplikasi (`storage/app/private/tracking-photos` ±513 MB; Manufaktur ±89 MB). Video .mp4 bukti Maintenance ikut dipindah.
  - `WpsCellImages`: membaca gambar yang ditempel di sel (WPS `=DISPIMG`).
  - Endpoint `GET/POST/DELETE /api/tracking-photos` dengan izin mengikuti modul.
  - Komponen UI `PhotoSlot` / `PhotoOpen` (Open File, unggah dari kamera).

**Data yang dipindah**

| Modul (sheet) | Baris | Status (sama dengan Excel) | Foto / gambar | Digabung dengan data Accurate |
|---|---|---|---|---|
| Manufaktur & Assembly (MA, MJ) | 470 + 295 | lihat bagian sebelumnya | 752 | – |
| Maintenance Assets | 396 Sub SPK / 344 SPK | 327 COMPLETED, 61 ON-GOING | Permintaan 356 (gambar di sel), Sebelum 373, Sesudah 327 | – |
| STPP (STPP + Maintenance) | 786 + 16 log | 738 ACTIVE, 47 PASSIVE | Bukti Keluar 778, Bukti Terima 43 | 121 baris |
| Ban Luar (3 sheet) | 624 (318 ORIGIN) + 40 BPN + 20 kirim/terima | 619 CLEAR, 5 PENDING RI | Out 309, In 191, BPN 70 | 128 baris |
| Pengembalian Bekas | Spare Part 210, Spare Part Lain 407 | 474 CLEAR, 143 PENDING | Keluar 287, Terima 263 | – |
| Borrow & Lend | Lend 373, Borrow 27 | Lend 326 KEMBALI / 12 DEADLINE / 35 SEDANG DIPINJAM; Borrow 19 LUNAS / 8 SEDANG DIPINJAM | 106 gambar di sel | 75 + 3 |

- **Pengembalian Bekas:** kedua sheet disimpan di tabel sendiri (`used_return_matrix_rows`, `used_return_other_rows`), terpisah dari `used_returns` (RI/NV dari Accurate). Dengan begitu data Excel tidak pernah menggerakkan stok.
- **Sync Accurate (derivasi ban, lend/borrow, STPP) dibuat aman:**
  - baris yang sudah diambil alih dari Excel (source EXCEL) dilewati;
  - dokumen RI/NPBG yang sudah dipakai baris Excel tidak dialokasikan ulang;
  - baris baru melanjutkan nomor "No";
  - FK `cascadeOnDelete` ke baris NPBG/RI diganti `nullOnDelete` (ban, lend, borrow), supaya data Excel tidak ikut terhapus kalau dokumen Accurate hilang.
- **Aturan yang disamakan dengan Excel:**
  - Lend DEADLINE mulai `hari ini ≥ Tgl Pinjam + Est.` (sebelumnya sehari setelahnya);
  - "-" dihitung terisi;
  - nama foto `…-1` jatuh ke foto dokumennya kalau file per item tidak ada;
  - baris dari Excel bisa diedit di semua kolom, status dihitung ulang dengan rumusnya.
- **UI:** setiap modul punya tab sesuai sheet dan kolom urut persis Excel. NPBG/RI bisa diklik bila ada di data Accurate. Foto terbuka dari aplikasi. Kolom Catatan tersedia.
- **Export Excel** tiap modul: sheet dan kolom mengikuti file referensi.
- **Migrasi live (tambahan saja):**
  - `2026_09_30_170000_create_tracking_photos_table`
  - `_180000_stpp_excel_columns`
  - `_190000_tyre_excel_columns`
  - `_200000_used_return_excel_sheets`
  - `_210000_lend_borrow_excel_columns`
- **Backup sebelum import:** `Backup/stpp-*`, `Backup/tyre-*`, `Backup/lendborrow-*` (JSON), dan `Backup/frontend-public-*`.
- **Verifikasi:**
  - PHPUnit 314/314 (importer per modul dengan xlsx buatan, termasuk gambar WPS), vitest 26/26
  - smoke test semua endpoint di MySQL live
  - total 3.103 foto
- **Belum dikerjakan:** PPB, NPBG, dan TRACKING PROJECT (sesuai koreksi user). Folder `1. Dashboard Input Data` tidak dihapus.
- Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler.

## 2026-09-30 — "cek all logic": audit data tracking + perbaikan

**Audit 4 lapis (read-only; lapis 4 di dalam transaksi yang di-rollback):**

1. **Integritas:** 3.103 foto, semua file ada, tidak ada file rusak atau foto yatim. Nomor MK/MA/MJ tidak akan terulang. Semua link NPBG/RI mengarah ke dokumen yang benar.
2. **Double count dengan Accurate:** ditemukan dan diperbaiki (lihat di bawah). Sekarang 0.
3. **Sel demi sel Excel ↔ database:** 12 sheet (±3.700 baris) cocok, 0 sel berbeda, termasuk status.
4. **Simulasi sync Accurate** (derivasi ban, lend/borrow, STPP) di data live: baris Excel tidak berubah, tidak ada duplikat baru, dan data identik setelah rollback.

**Bug yang ditemukan dan diperbaiki:**

- **Pemetaan baris Excel ke baris NPBG/RI**
  - Masalah: kalau deskripsi Excel berbeda dari Accurate, importer memakai baris pertama dokumen tanpa memeriksa sisa kuantitas. Contoh: NA/25/X/108, 2 ban di 2 baris, keduanya masuk ke baris 1, sehingga baris 2 tetap tercatat dari Accurate (dobel).
  - Perbaikan: `assignLine()` memilih baris yang masih punya sisa kuantitas dengan deskripsi paling mirip.
- **Dokumen yang dilacak Excel masih punya baris turunan Accurate**
  - Masalah: komponen dan barang habis pakai tercatat ACTIVE / SEDANG DIPINJAM. Contoh: NA/25/XI/188 SEAL TAPE, dan komponen caster NA/26/IV/187.
  - Perbaikan: Excel dijadikan acuan untuk dokumen yang dilacaknya. Baris turunan Accurate di dokumen itu dihapus (STPP 9, Ban Luar 11, Lend 11), dan sync berikutnya melewati dokumen tersebut.
- **Tanggal berbentuk teks**
  - Masalah: Maintenance baris 385–401 Tgl Laporan dan Tgl Selesai tersimpan kosong. STPP "01/10/025" terbaca 10 Jan 2025 (format bulan/hari).
  - Perbaikan: teks dibaca hari/bulan/tahun; tahun 3 digit diambil dari nomor dokumen ("21/11/205" + NPBG …/25/… menjadi 21-11-2025).
- **Kata di kolom tanggal ikut disimpan**
  - Lend Tgl Kembali "LOST" (19) dan "TIDAK JADI" (1) disimpan di `condition_in`, tampil di kolom Tgl Kembali, dan tetap dihitung KEMBALI sesuai rumus Excel.
  - Spare Part Lain Tgl NPBG "NGUTANG" (2) disimpan di Keterangan.

**Catatan untuk user** (dibiarkan sesuai tulisan Excel):

- Maintenance C398 Tgl Laporan "14/09/2029" (Tgl Selesai 14/09/2026).
- Nomor "No" 456 ganda di sheet MA.
- 5 kombinasi SPK+Sub ganda di Maintenance.

**Verifikasi dan backup:**

- PHPUnit 315/315 (termasuk `ExcelLineMatchingTest`), vitest 26/26.
- Backup sebelum perbaikan: `Backup/tracking-fix-*`, UI `Backup/frontend-public-20260930_153824`.
- Tidak ada perubahan pada Accurate/Firebird, GDB, maupun scheduler.

## 2026-10-01 — PPB: "Amend" jadi status kedua (Amend + Requested, dst.)

- Permintaan user: baris PPB yang amend tetap menampilkan status Accurate-nya, sehingga ada 2 status.
- **Perubahan data:**
  - `ppb.line_status` sekarang hanya berisi status menurut Accurate: REQUESTED / SHORTAGE / COMPLETED / CLOSE.
  - Kolom baru `is_amend` dan `amend_source` (migrasi tambahan `2026_10_01_090000`, langsung menghitung ulang semua baris):
    - `NOTE` = tercantum di "PPB AMEND (…)"
    - `CLOSED` = PPB ditutup tanpa ada barang diterima
  - Status "Perlu dicek" (Error) dihapus; kasusnya sekarang tampil sebagai Amend + Completed / Shortage.
- **API:** `?line_status=AMEND` (atau `?amend=1`) menyaring baris Amend dengan status apa pun. Resource mengirim `is_amend` dan `amend_source`.
- **UI:** badge **Amend** tampil di samping status di daftar dan detail PPB; ada pilihan filter "Amend (status apa pun)".
- **Dashboard:** kartu "PPB menunggu barang" tidak lagi menghitung baris Amend.
- **Hasil live:**

  | Status | Jumlah baris |
  |---|---|
  | Completed | 2.166 |
  | Requested | 188 |
  | Shortage | 16 |
  | Close | 47 |
  | Amend + Close | 137 (133 ditutup tanpa penerimaan, 4 dari catatan "PPB AMEND") |

- Saat ini belum ada baris yang berstatus Amend + Requested.
- **Verifikasi dan backup:**
  - PHPUnit 315/315, vitest 26/26
  - backup status lama: `Backup/ppb-amend-20261001_104417`; UI: `Backup/frontend-public-20261001_104417`

## 2026-10-01 — Fix: status PPB "Amend + Close" padahal PO masih berjalan

- **Laporan user:** PPB/NA/26/IX/032 (MAI.0828) tampil Amend + Close, padahal di Accurate masih menunggu dari PO.
- **Penyebab:** Accurate menutup PPB (ISCLOSED = 1) begitu seluruh kuantitasnya sudah dibuatkan PO. Aturan lama menganggap "PPB closed tanpa penerimaan" berarti tidak jadi dibeli.
  - Dari 137 baris "Amend": 59 masih punya PO yang berjalan (SENT / sebagian diterima), 72 memang tidak pernah dibuat PO, 5 PO-nya ditutup tanpa penerimaan.
- **Aturan baru** (`App\Support\PpbLineStatus`) — status ikut melihat PO baris itu (`purchase_order_items.accurate_ppb_id`):

  | Kondisi | Status |
  |---|---|
  | Masih ditunggu (PPB terbuka, atau ada PO yang belum selesai dengan sisa belum diterima), belum ada yang diterima | Requested |
  | Masih ditunggu, sebagian sudah diterima | Shortage |
  | Diterima ≥ diminta | Completed |
  | Tidak ada lagi yang datang, sebagian sudah diterima | Close |
  | Tidak ada lagi yang datang, tidak ada yang diterima | Amend + Close |
  | Tercantum di "PPB AMEND (…)" | Amend (+ status di atas) |

- **Sync:** status dihitung ulang setelah langkah PO (langkah `ppb_status`, tidak fatal), karena langkah PPB berjalan sebelum PO masuk.
- **Hasil live** (68 baris dibetulkan; backup `Backup/ppb-po-status-20261001_035540`):

  | Status | Jumlah baris |
  |---|---|
  | Completed | 2.166 |
  | Requested | 247 |
  | Amend + Close | 78 |
  | Close | 38 |
  | Shortage | 25 |

  PPB/NA/26/IX/032 sekarang Requested.
- **Audit seluruh PPB terhadap PO:** 0 Amend dengan PO masih berjalan, 0 Close dengan PO masih berjalan, 0 Requested/Shortage pada PPB tertutup tanpa PO berjalan.
  - Satu ketidaksesuaian ada di data Accurate sendiri, dibiarkan dan dilaporkan ke user: PPB/NA/26/IX/009 (BSP.2864) tercatat dipesan 0 / diterima 0, tetapi PO PT/D/26/IX/030 menautkannya dengan diterima 2, dan tidak ada RI.
- **Verifikasi:** PHPUnit 316/316, termasuk kasus PPB closed + PO SENT menjadi Requested.

## 2026-10-01 — Dashboard: bagian "Tracking & Pembelian"

- Endpoint baru `GET /api/dashboard/operations` (`OperationsDashboardController`), **read-only**, tanpa migrasi, tanpa perubahan data, tidak menyentuh Accurate/Firebird.
  - Grafik agregat tampil untuk semua peran (sama seperti visualisasi inventory). Daftar dokumen (PPB tertua, pinjaman lewat tempo) hanya untuk user dengan `ppb.view` / `lend.view`.
- Isi:
  - status semua tracking (selesai / berjalan / perlu tindakan, memakai kata status Excel tiap modul)
  - umur PPB menunggu barang (Requested/Shortage tanpa Amend; 0–7 / 8–30 / 31–60 / 61–90 / >90 hari)
  - alur PPB → PO → RI per bulan (12 bulan penuh terakhir)
  - top 10 kendaraan maintenance
  - penggantian ban per bulan
- Pinjaman "lewat tempo" mengikuti rumus Excel: tanggal kembali ≤ hari ini, walaupun status tersimpan masih ON_LOAN.
- Tes: `tests/Feature/Dashboard/OperationsDashboardTest.php`. Backend 318/318, frontend 26/26, tsc dan build bersih.
- Deploy frontend dengan backup `Backup/frontend-public-20261001_110618` dan `_111004`.

## 2026-10-01 — Nomor dokumen berubah di Accurate & detail riwayat sync

**Laporan user:** NPBG ATK/26/IX/046 berubah menjadi 001 di Accurate, tetapi STOCKWISE tidak ikut berubah.

**Hasil cek (read-only).** Sumber yang dibaca: salinan staging (Firebird staging port 3050, `Staging\STAGING.GDB`). GDB production tidak disentuh.
- ARINVOICEID 4353 di salinan 09:32 WIB = **ATK/26/X/001**. Di STOCKWISE masih ATK/26/IX/046, karena sync terakhir yang di-push memakai salinan 08:45. Salinan 09:32 baru berstatus STAGED.
- Sync mengikuti dokumen lewat ID Accurate, jadi nomor akan ikut berubah pada sync berikutnya.
- Perbandingan semua dokumen (staging terbaru vs STOCKWISE):
  - NPBG ATK/26/IX/046 → ATK/26/X/001
  - PO PT/D/26/IX/038 → "PT/D/26/IX/038 REV 1"
  - RI dan PPB: tidak ada perubahan nomor maupun tanggal.
- Salinan nomor di modul tracking (±2.700 baris tertaut FK) saat ini 0 selisih.

**Bug ditemukan:** setiap sync mencatat ±8.800 baris NPBG sebagai "diubah" padahal tidak berubah. Penyebabnya `unit_ratio` "1.0000" dibandingkan sebagai teks dengan 1. Akibatnya log penuh perubahan palsu dan perubahan nyata tenggelam.

**Perubahan:**
- `App\Support\SyncDiff`:
  - Satu aturan pembanding untuk sync dan log: angka sebagai angka, tanggal sebagai Y-m-d, kode berawalan nol tetap teks.
  - Label kolom dalam bahasa Indonesia.
  - Dipakai di semua langkah sync: NPBG, PPB, RI, PO + barisnya, Stock Opname + barisnya.
- Pesan log memuat perubahan nomor, mis. "NPBG ATK/26/IX/046 → ATK/26/X/001 (nomor berubah) baris 1 diperbarui."
- PO / Stock Opname: perubahan per baris barang ikut dicatat (`new_data._lines`).
- Langkah baru `npbg_renumber` / `ri_renumber` (`DocumentRenumber`), dijalankan sebelum turunan STPP/Lend/Ban. Nomor baru dibawa ke salinan teks di Maintenance, Manufaktur, STPP, Ban, Lend/Borrow dan Barang Bekas:
  - baris tertaut FK selalu ikut diubah;
  - baris yang hanya teks diubah hanya jika nomor lama sudah tidak dipakai dokumen lain;
  - hanya token nomor itu yang diganti;
  - setiap perubahan dicatat di log (entity `renumber`).
- `GET /api/sync/history/{id}`:
  - tiap log membawa dokumen (lama/baru), barang, dan `changes` [label, sebelum, sesudah];
  - "update palsu" log lama disembunyikan dan hanya dihitung;
  - ada ringkasan per jenis data dan filter `entity` / `action` / `search`.
- Halaman Riwayat Sync: kolom Data / Dokumen / Barang / Status / "Yang berubah (sebelum → sesudah)", chip per jenis data, dan pencarian.

**Tidak dilakukan:**
- Sync baru tidak dijalankan. Perubahan nomor akan masuk pada sync terjadwal berikutnya, atau lewat tombol Sync Accurate.
- Log lama tidak dihapus.
- Tanpa migrasi.

**Tes dan deploy:**
- `tests/Feature/Accurate/SyncChangeHistoryTest.php` (4 tes). Backend 322/322, frontend 26/26.
- Deploy frontend dengan backup `Backup/frontend-public-20261001_113023`.
- Salinan `AccurateSyncService.php` sebelum perubahan: `Backup/AccurateSyncService.before-renumber-20261001.php`.

## 2026-10-02 — Stock Opname: kolom NPBG dan PPB

- Kolom baru **NPBG** dan **PPB** = jumlah baris NPBG / PPB untuk barang itu (dicocokkan lewat kode barang, semua periode).
  - Tampil di tabel detail opname (desktop + kartu ponsel) dan di tampilan "Per barang".
  - Angkanya bisa diklik untuk membuka daftar NPBG/PPB yang difilter ke kode barang itu.
- Backend: `StockOpnameController::lineRow` menambah `npbg_count` / `ppb_count`, dihitung dengan satu query per tabel untuk semua barang di layar (index `kode_barang` sudah ada, ±11 ms).
  - Read-only, tanpa migrasi.
  - Tidak membuka qty sistem, jadi aturan blind count tetap berlaku.
- Tes: `StockOpnameTest::test_npbg_and_ppb_columns_count_the_lines_of_each_item`. Backend 323/323, frontend 26/26.
- Deploy dengan backup `Backup/frontend-public-20261002_104047`.
- **Lanjutan (permintaan user: "bisa filter dari tanggal berapa ke berapa"):**
  - Filter **Periode NPBG / PPB** (dari – sampai tanggal) dengan tombol cepat Bulan ini / 3 bulan / 12 bulan / Semua.
    - Di detail opname, lewat endpoint baru `GET /api/stock-opnames/{id}/usage?date_from=&date_to=`. Endpoint ini terpisah dari `show()`, supaya cache aksi hitung/review tidak terganggu.
    - Di tampilan "Per barang" lewat `usage_from` / `usage_to`. `date_from`/`date_to` di sana tetap untuk tanggal opname.
  - Filter dihitung pada `tgl_npbg` / `tgl_ppb`. Angka yang diklik membuka daftar NPBG/PPB dengan kode barang dan periode yang sama; halaman NPBG/PPB kini membaca `?date_from` / `?date_to` dari URL.
  - Tes periode ditambahkan. Backend 323/323, frontend 26/26.
  - Deploy dengan backup `Backup/frontend-public-20261002_141337`.
  - Server STOCKWISE port 8001 ditemukan mati sejak 01-10-2026 15:44 dan **tidak** dinyalakan tanpa persetujuan user.

## 2026-10-04 — Qty Master Barang tidak sama dengan Accurate

**Laporan user:** ada qty Master Barang yang tidak sesuai Accurate. Database harus sama persis dengan Accurate, tanpa logika "mirip-mirip".

**Hasil cek (read-only).** Sumber yang dibaca: mirror MySQL dan salinan staging (Firebird staging 3050). GDB production tidak disentuh.
1. `items.accurate_qty_onhand` = `accurate_item.QUANTITY` untuk semua 9.646 baris. Langkah sync ke Master Barang tidak mengubah angka.
2. **Sumbernya yang salah:** `ITEM.QUANTITY` tidak diperbarui oleh Accurate.
   - Accurate menghitung qty dengan prosedurnya sendiri `GETITEMQUANTITY` → `GETITEMBAL` (`ITEMBALANCE` + `ITEMHIST`).
   - Dijalankan untuk 9.222 produk di salinan staging: **1.826 berbeda** dari `ITEM.QUANTITY` (mis. OFN.0009: 0 vs 66; OFN.0018: 10 vs 4). Master Barang saat cek: 1.844 barang tidak sama dengan qty Accurate.
   - `ITEM.ONORDER` juga tidak diperbarui: 123 berbeda dari `GETITEMONORDER` (mis. OFN.0028: 0 vs 150).
3. **Bug mirror:** semua kolom NUMERIC(18,4) Accurate (228 kolom: qty, harga, rasio satuan, saldo) dimirror sebagai BIGINT, sehingga desimal terbulatkan (0,75 → 1; 4,05 → 4). Penyebabnya: pemetaan tipe mengabaikan RDB$FIELD_SCALE, dan nilai Decimal dikirim sebagai float.

**Perbaikan:**
- `App\Services\Accurate\AccurateItemQuantity` — port baris-per-baris prosedur Accurate:
  - `GETITEMBAL` (ITEMID null), termasuk aturan "tanpa saldo tahun ini → saldo 31-12 tahun terakhir";
  - `GETITEMONORDER` (AsOf = hari ini).
  - Dipakai sync untuk `accurate_qty_onhand` / `accurate_qty_onorder`. `ITEM.QUANTITY`/`ONORDER` hanya dipakai bila tabel ITEMBALANCE/ITEMHIST (PO/PODET) tidak ada di staging.
- **Verifikasi:**
  - rumus yang sama di atas data desimal utuh salinan staging cocok **9.222/9.222** dengan `GETITEMQUANTITY`;
  - `onOrder()` cocok **9.222/9.222** dengan `GETITEMONORDER`;
  - di atas mirror lama (masih BIGINT) cocok 9.175; 47 sisanya semuanya barang berdesimal.
- **Agen** (`sync-service/mapping/type_mapping.py`, `agent/run.py`):
  - NUMERIC/DECIMAL dimirror sebagai `DECIMAL(presisi,scale)` persis definisi Accurate;
  - Decimal dikirim sebagai teks (tanpa float).
  - Laravel (`config/accurate.php`, `AccurateIngestController`, `StagingTableWriter`) menerima DECIMAL dengan batas presisi/scale tervalidasi. Config tidak di-cache.
- **Efek pada sync full berikutnya (Task Scheduler, proses baru tiap jadwal; tidak ada yang di-restart):**
  - tabel mirror dibuat ulang dengan DECIMAL;
  - QTY dan qty dipesan Master Barang = Accurate;
  - `stock_follows_accurate` (aktif) lalu menyamakan stok STOCKWISE (kolom "Tersedia") ke qty itu, tercatat di ledger sebagai `ACCURATE_SYNC` (±1.844 barang). Reservasi tetap dilindungi seperti sebelumnya.
- **Tes:** `tests/Feature/Accurate/AccurateItemQuantityTest.php` (4 tes). Backend 327/327. Tes Python agen tidak dijalankan (pytest tidak terpasang dan tidak dipasang); pemetaan diverifikasi dengan skrip terhadap kolom asli staging.
- **Backup kode lama:**
  - `Backup/AccurateSyncService.before-itemqty-20261004.php`
  - `Backup/sync-agent-decimal-20261004/` (`type_mapping.py`, `run.py`)

### 2026-10-04 (lanjutan) — Cek ulang semua data vs Accurate

**Status saat cek (10:44 WIB):** perbaikan belum berlaku, karena sync full berikutnya (12:20) belum jalan. Mirror masih BIGINT.

**Kesiapan sync berikutnya:** ke-21 tabel / 764 kolom yang akan dikirim agen (228 DECIMAL) lolos validasi ingest Laravel dan pembuat DDL. 0 kegagalan.

**Perbandingan baris per baris (salinan Accurate staging 09:00 vs STOCKWISE):**

| Data | Jumlah | Nomor/kode/tanggal | Baris hilang/lebih | Selisih tersisa |
|---|---|---|---|---|
| NPBG | 8.889 | sama | 0 | 94 qty desimal |
| PPB | 2.563 | sama | 0 | 5 qty + 8 qty diterima (desimal) |
| RI | 4.997 | sama | 0 | 42 qty + 94 harga (desimal) |
| PO | 2.415 | sama | **4 baris lebih** | 5 qty + 6 qty diterima + 91 harga (desimal) |
| Stock Opname | 2.550 dok / 2.805 baris | sama | 0 | 48 qty (desimal) |
| Master Barang | 9.222 | sama | 0 | 1.844 QTY + 123 qty dipesan |

**Temuan tambahan dan perbaikannya:**
- **Presisi kolom STOCKWISE 2 desimal, Accurate 4.** Ada nilai 3–4 desimal di Accurate:
  - harga RI/PO, mis. 119.369,3694;
  - qty diterima PPB, mis. 1,1111;
  - qty opname dan stok MAI.0306 = 46,656.
- Migrasi `2026_10_04_090000_accurate_decimal_precision` melebarkan 14 kolom ke DECIMAL(18,4): npbg/ppb/ri qty & harga, purchase_order_items qty/qty_received/unit_price, items qty Accurate, stock_opname_items qty + kolom generated `difference` (ekspresi dipertahankan).
  - Hanya pelebaran. Backup struktur + isi: `Backup/decimal-precision-20261004_034717`.
  - Checksum count/sum sebelum = sesudah untuk 14/14 kolom.
  - `Item` diberi cast float untuk qty Accurate (tampil 66 / 46.656, bukan "66.0000").
- **Baris PO yang dihapus di Accurate tetap tersimpan** (PO revisi, mis. BL/D/26/IX/071 "ELBOW CU 3/4"", BL/D/26/IX/042 BATAL).
  - Baris "hantu" berstatus SENT membuat 3 baris PPB terkait tetap dianggap menunggu PO.
  - `syncPoLines` sekarang menghapus baris PO yang tidak ada lagi di Accurate. FK RI/receiving = SET NULL, 0 referensi saat cek. Penghapusan dicatat di riwayat sync ("dihapus di Accurate").
- **Tes:** `AccurateDataAuditFixesTest` diperluas (revisi PO + desimal 0,75 / 422,433). Backend 327/327.
- **Catatan sisa:** stok STOCKWISE (`inventory`, kolom "Tersedia") tetap 2 desimal. Untuk 2 barang ber-3 desimal (MAI.0306 46,656; MAI.0329 1,496) tampil 46,66 / 1,50. QTY Accurate di Master Barang tetap persis.

## 2026-10-05 — Format input NPBG & RI di Accurate berubah

**Temuan (read-only, mirror + salinan staging):**
- **Kolom per baris yang artinya berubah:**
  - NPBG `ITEMRESERVED1` dulu peminta, kini lokasi gudang ("GUDANG 1"). `ITEMRESERVED2` dulu catatan, kini rak ("RAK B.30").
  - RI `ITEMRESERVED1/2` dulu tanggal, kini lokasi. `ITEMRESERVED3` (pemeriksa) kini kosong.
- **Peminta/pemeriksa kini dipilih sebagai Departemen (`DEPTID`):** sub-departemen `DEPTNO` = nama orang (NINING, REZA, BUDI), `DEPTNAME` = divisi.
  - NPBG sejak NA/26/IX/106 (12-09-2026); RI sejak RI/NV/26/IX/031 (24-09-2026).
  - Sering hanya diisi di baris pertama.
- **NPBG header `SHIPVIA`** (sejak 23-09-2026) = jenis pengeluaran, dari tabel Accurate `SHIPMENT`: 8 UMUM, 9 MAINTENANCE TABUNG, 10 MAINTENANCE KENDARAAN, 11 STPP, 12 MAINTENANCE GEDUNG, 13 MAINTENANCE STPP, 15 PROYEK.
- **Dampak di STOCKWISE sebelum perbaikan:** peminta NPBG Oktober = "GUDANG 1" (22 baris) / kosong (45); pemeriksa RI Oktober kosong (23).
- **Masa transisi:** NPBG sejak 12-09: 96 dokumen format baru vs 212 lama. RI: 12 baru vs 169 lama.

**Keputusan user (2026-10-05):**
- Departemen dulu.
- Kolom baru Lokasi Gudang & Rak.
- Jenis Pengeluaran ditampilkan + filter.

**Perubahan:**
- **`App\Services\Accurate\AccurateLineFields`** — aturan pembacaan:
  - dokumen format baru = ada `DEPTID` atau `SHIPVIA`;
  - peminta/pemeriksa = departemen baris, atau departemen pertama di dokumen. Format lama tetap `ITEMRESERVED1` (NPBG) / `ITEMRESERVED3` (RI);
  - lokasi = `ITEMRESERVED1/2` pada dokumen format baru (RI juga bila `ITEMRESERVED1` bukan tanggal);
  - jenis = `SHIPMENT.NAME`.
- **Migrasi aditif `2026_10_05_090000_npbg_ri_new_accurate_fields`:** `npbg.jenis_pengeluaran` (index), `lokasi_gudang`, `lokasi_rak`; `ri.lokasi_gudang`, `lokasi_rak`. Dijalankan live 08:46, sebelum sync 09:00.
- **Agen + Laravel:** `SHIPMENT` ditambahkan sebagai tabel mirror opsional (`tables.py` OPTIONAL_MIRROR_TABLES, `config/accurate.php`).
- **API dan layar:**
  - `GET /api/npbg/jenis-pengeluaran`, filter `?jenis_pengeluaran=`;
  - pencarian rak; resource NPBG/RI; ekspor NPBG/RI (kolom Jenis Pengeluaran, Lokasi Gudang, Lokasi Rak); label riwayat sync;
  - layar daftar + detail NPBG (kolom Jenis Pengeluaran, Lokasi; filter jenis) dan RI (kolom Lokasi).
- **Tes:** `AccurateNewInputFormatTest`. Backend 328/328, frontend 26/26. Deploy frontend backup `Backup/frontend-public-20261005_085114`.
- **Backup kode sync lama:** `Backup/AccurateSyncService.before-newformat-20261005.php`.

**Catatan untuk user:**
- (1) RI/NV (pengembalian): departemen berisi orang Maintenance (VANDIKA, BUDI). Kemungkinan besar pengembali barang, bukan pemeriksa. Kolom pemeriksa RI/NV lama selalu kosong.
- (2) NA/26/IX/106: departemen AYU, tapi ITEMRESERVED1 = KEVIN (dokumen percobaan); mengikuti aturan "departemen dulu" → AYU.
- (3) Kode barang Accurate "ETA. 0252" (ada spasi) tidak masuk Master Barang karena bukan pola kode produk. Sebaiknya diperbaiki di Accurate menjadi "ETA.0252".

## 2026-10-05 — NPBG/RI mengikuti template Accurate terbaru

Detail lengkap ada di `docs/npbg-ri-mapping-audit.md` (audit + bagian "Update 2026-10-05").

**Ringkasan:**
- **Agen:** mirror opsional TERMOPMT/SNHISTORY/SERIALNUMBERS + tabel turunan SNLINE_BYITEMHIST (prosedur Accurate dijalankan di salinan staging; production tidak disentuh).
- **Migrasi aditif/rename** `2026_10_05_120000`: kolom data lama tidak hilang; jenis_pengeluaran disalin ke klasifikasi sebelum di-drop.
- **Verifikasi:** No. PPB dan No. Seri cocok 100% dengan prosedur template Accurate.
- **No. RI belum diubah**, menunggu verifikasi user.
- **Backup:**
  - kode: `Backup/AccurateSyncService.before-template-20261005.php`, `Backup/AccurateLineFields.before-template-20261005.php`;
  - agen sebelum perubahan: `Backup/sync-agent-template-20261005/`;
  - skema: `Backup/npbg-ri-template-20261005_025143`.

## 2026-10-05 — Audit menyeluruh logika & database

Read-only. Acuan = salinan staging yang dipakai sync #42 (agen, SUCCESS 10:50 WIB).

**Logika / fitur:**
- **Tes:** backend 329/329 (sebelum fitur Kartu Stock), frontend 26/26, tsc bersih.
- **Endpoint GET tanpa parameter:**
  - 58 OK;
  - 8 bukan error: 4 fitur sengaja dinonaktifkan, 3 wajib parameter, GET /login;
  - lambat: dashboard inventory 3,4 dtk.
- **Endpoint detail (ID nyata):** 18 OK, 0 error.

**Database vs Accurate:**

| Data | Jumlah | Hasil |
|---|---|---|
| NPBG | 8.903 baris | nomor, tanggal, kode, qty, rasio, divisi, klasifikasi, keterangan, no_seri semua sama |
| RI | 4.996 | nomor, no surat jalan, tanggal, kode, qty, harga, no_po, pengirim, FOB, no_ppb, no_seri sama |
| PPB | 2.570 | sama |
| PO | 2.417 baris, 1.360 header | sama |
| Opname | 2.806 | sama |
| Master Barang | 9.224 | QTY dan qty dipesan sama dengan GETITEMQUANTITY / GETITEMONORDER; stok STOCKWISE = qty Accurate (0 selisih) |

**Konsistensi:**
- mirror BIGINT tersisa 0;
- tabel mirror baru terisi (SHIPMENT 7, TERMOPMT 6, SNHISTORY 4.246, SERIALNUMBERS 2.977, SNLINE_BYITEMHIST 842);
- salinan nomor NPBG/RI di tracking: 2.702, 0 selisih;
- status PPB sesuai aturan: 0 selisih;
- RI NV → Barang Bekas: 1.053 dok, 0 terlewat;
- baris PO yatim 0; duplikat 0; FK yatim 0;
- UPDATE palsu batch #42: 0.

**Sisa terbuka:**
- 4 RI ber-no_ri "SRT-…" (menunggu verifikasi Form No; tidak ikut ke Barang Bekas karena Jenis RI kosong);
- kode Accurate "ETA. 0252" (spasi) tidak masuk Master Barang.

## 2026-10-05 — Fitur Kartu Stock

- **Halaman `/stock-card/{item}`** (menu Operasional Gudang + link "Kartu" di Master Barang):
  - judul KARTU STOCK;
  - header: Nama Barang, Nomor Kartu, Satuan, Kode Barang, Letak Gudang / Rak, Penanggung Jawab;
  - tabel: Tgl, No. Bukti (link ke NPBG/RI/Opname), Keterangan, Masuk, Keluar, Sisa, Upload Dokumen (gambar/PDF) / Foto;
  - TTD Penanggung Jawab & Direktur;
  - filter periode; cetak A4.
- **Data:** pergerakan dari Accurate ITEMHIST (`StockCardService`). Saldo awal tahun pertama + ITEMHIST = GETITEMQUANTITY untuk 9.224/9.224 barang (sisa akhir = stok Accurate, dicek untuk semua barang).
- **Header kartu** (nomor kartu, letak, penanggung jawab, nama direktur): tabel baru `stock_cards` (migrasi aditif `2026_10_05_140000`). Letak default dari lokasi terakhir NPBG/RI Accurate.
- **Upload:** `tracking_photos` entity `stock_card` (per ITEMHISTID, slot doc/foto). PDF hanya diizinkan untuk entity ini.
- **Hak akses:**
  - lihat: `item.view` atau `inventory.view`;
  - ubah header/upload: `item.update`.
- **Tes:** `tests/Feature/Inventory/StockCardTest.php`. Backend 331/331, frontend 26/26.
- **Deploy frontend:** backup `Backup/frontend-public-20261005_105816` dan `_105946`.
- **Kartu Stock — pemilih barang (lanjutan):** `GET /api/stock-card/items?search=` mencari di Master Barang berdasarkan kode atau nama.
  - Urutan: kode persis → awalan kode → nama (semua kata, toleran aksen/spasi).
  - Termasuk barang nonaktif; menampilkan satuan & stok Accurate.
  - Akses: `item.view` atau `inventory.view`.
  - Komponen `StockCardItemPicker` (pilih dengan klik / ↑↓ Enter, menampilkan barang terpilih).
  - Tes ditambah; backend 332/332, frontend 26/26.
  - Deploy backup `Backup/frontend-public-20261005_111701`.

## 2026-10-05 — Kartu Stock diubah menjadi kartu manual (contoh foto "KARTU STOCK BARANG", proyek instalasi CCTV)

- **Alasan:** menurut contoh user (`kartu stock.jpeg`), kartu stock diisi tangan, mis. "Beli ke P. Anam" +15, "Pasang No. 6 & 7" −2. Kartu No. = kode barang (EAE.0291). Isinya bukan riwayat Accurate. Versi berbasis ITEMHIST diganti; belum pernah ada data.
- **Alur:**
  - daftar Kartu Stock → **Buat Kartu Stock** (barang dari Master Barang lewat kode/nama; Nama/Kode/Satuan otomatis; Nomor Kartu default = kode barang, "-2", "-3" bila sudah dipakai; letak gudang/rak, penanggung jawab, direktur);
  - halaman kartu: baris manual Tgl / No. Bukti / Keterangan / Masuk / Keluar (tambah, ubah, hapus), Sisa dihitung menurut tanggal, upload Dokumen (gambar/PDF) & Foto per baris;
  - TTD Penanggung Jawab & Direktur.
- **Cetak / Download PDF:**
  - PDF dibuat server dengan Google Chrome yang sudah terpasang (`--headless --print-to-pdf`, `config/stockwise.php` chrome_path) dari template Blade `resources/views/stock-card/print.blade.php`;
  - tanpa paket baru; ±6 detik per PDF;
  - folder sementara `storage/app/tmp-pdf` dibersihkan otomatis.
- **Database (migrasi aditif `2026_10_05_160000_stock_card_entries`; tabel stock_cards masih kosong):**
  - card_number unik;
  - satu barang boleh banyak kartu;
  - tabel `stock_card_entries`;
  - upload memakai `tracking_photos` entity `stock_card_entry`.
- **Hak akses:**
  - lihat / cetak: `item.view` atau `inventory.view`;
  - buat / ubah / upload: `item.update`.
- **Tes:** `tests/Feature/Inventory/StockCardTest.php` (3 tes). Ditemukan & diperbaiki saat tes: total di daftar kosong (urutan select vs withSum). Backend 332/332, frontend 26/26.
- **Deploy frontend:** backup `Backup/frontend-public-20261005_132659`.

## 2026-10-05 — Kartu Stock per proyek

- **Logika (user: "satu proyek bisa banyak kartu stock, kartu per barang"):**
  - Proyek (master `projects` yang sudah ada) → banyak Kartu Stock, satu kartu per barang;
  - dalam satu proyek: barang dan nomor kartu unik (nomor = kode barang);
  - barang yang sama boleh punya kartu di proyek lain;
  - proyek yang masih punya kartu tidak bisa dihapus.
- **Migrasi aditif `2026_10_05_180000_stock_cards_per_project`:** `stock_cards.project_id` (FK restrict), unique (project_id, item_id) & (project_id, card_number). Kartu lama (1: EAE.0291, ODEV) tetap ada, project_id null → tampil "Kartu belum masuk proyek", bisa dipindah lewat "Ubah data kartu".
- **API:** `/api/stock-card-projects` (daftar, buat, ubah, hapus bila kosong, `/pdf` semua kartu proyek satu-per-halaman); kartu wajib `project_id`.
- **Layar:**
  - Kartu Stock → daftar Proyek (Buat Proyek);
  - halaman Proyek: kartu-kartunya + Buat Kartu Stock + Cetak semua / Download PDF semua;
  - halaman Kartu: baris Proyek di header + pindah proyek;
  - pemilih barang menandai barang yang sudah punya kartu di proyek itu;
  - kolom "Kartu" di Master Barang dihapus (kartu dibuat dari proyek).
- **Tes:** `StockCardTest` 3 tes / 58 pengecekan. Backend 332/332, frontend 26/26.
- **Deploy:** backup `Backup/frontend-public-20261005_141206`.

## 2026-10-05 — Kartu Stock ramah HP, edit langsung + simpan otomatis

- **Halaman kartu** ditulis ulang. Hanya frontend; backend/API tidak berubah.
- **Edit langsung:**
  - semua isian transaksi (Tgl, No. Bukti, Keterangan, Masuk, Keluar) dan data kartu (proyek, nomor kartu, letak gudang/rak, penanggung jawab, direktur) bisa diketik di tempat, tanpa tombol pulpen;
  - **simpan otomatis** ±0,9 dtk setelah berhenti mengetik / saat pindah kolom; status "Menyimpan… / Tersimpan" per baris;
  - ketikan selama proses simpan tidak hilang (disimpan ulang).
- **Transaksi baru:**
  - desktop: baris biru di bawah, tersimpan saat Enter / pindah dari baris;
  - HP: tombol tetap "+ Transaksi baru" → kartu isian besar.
  - Disimpan hanya bila tanggal + Masuk/Keluar terisi (petunjuk ditampilkan).
- **Upload:** tombol **Kamera** (, langsung kamera belakang di HP) dan **File** (foto/PDF), per Dokumen dan Foto.
- **Diuji di browser** (desktop 1280px & HP 390px, request dicegat): ubah baris → PUT otomatis tanpa tombol; baris baru + Enter → POST. Frontend 26/26, tsc bersih.
- **Deploy:** backup `Backup/frontend-public-20261005_142147`.

## Kartu Stock — perapian tampilan HP (2026-10-05)

- **Daftar Proyek / halaman Proyek (HP):** kartu yang mudah diketuk menggantikan tabel. Tombol utama ("Proyek baru", "Kartu stock baru") tetap di bawah layar. Cetak, PDF, Ubah, dan Hapus masuk ke menu ⋯.
- **Halaman Kartu (HP):**
  - ringkasan barang plus Masuk/Keluar/Sisa;
  - "Info kartu" bisa dibuka-tutup, berisi field bertumpuk yang tersimpan otomatis;
  - transaksi tampil sebagai daftar satu baris (tanggal, keterangan, no. bukti, lampiran, +/−qty, sisa).
- **Edit transaksi (HP):** ketuk transaksi untuk membuka panel dari bawah. Perubahan tersimpan otomatis. Di panel yang sama ada upload Kamera/File dan Hapus. Setelah transaksi baru disimpan, panel tetap terbuka supaya dokumen/foto bisa langsung ditambahkan.
- **Perbaikan tanggal dan No. Bukti yang bertumpuk:**
  - di HP keduanya kini bertumpuk vertikal, karena input tanggal di HP lebar;
  - di desktop, kolom Tgl/No. Bukti diberi lebar minimum, dan tabel bisa digeser bila layar sempit.
- **Desktop:** tampilan kartu (seperti kertas) dan edit langsung di tabel tidak berubah.
- **Uji:**
  - screenshot HP 390px dan desktop 1280px dengan data tiruan;
  - tsc bersih; frontend 26/26.
  - Preview sementara sudah dihapus.
- **Deploy:** backup `Backup/frontend-public-20261005_143431`.
- Backend dan database tidak diubah. Tidak ada commit/push.
- **Revisi (2026-10-05):**
  - Baris "Direktur (TTD) :" di atas tabel kartu (desktop) dihapus.
  - Nama direktur kini diketik langsung di bawah garis TTD Direktur dan tersimpan otomatis. Di HP tetap ada di "Info kartu".
  - Cetak/PDF tidak berubah.
  - Frontend 26/26; backup `Backup/frontend-public-20261005_143748`.
- **Revisi: tabel langsung di HP (2026-10-05):**
  - Alur: Proyek → ketuk barang → langsung tabel kartu stock (sama seperti desktop).
  - Semua sel diketik langsung (tambah/ubah/hapus) dan tersimpan otomatis. Tidak ada panel/form terpisah. Baris biru di bawah = transaksi baru.
  - Kolom diberi lebar tetap (table-fixed), sehingga Tgl dan No. Bukti tidak bisa bertumpuk.
  - Di HP tabel bisa digeser ke kanan untuk Masuk/Keluar/Sisa/Lampiran/hapus. Tombol lampiran berupa ikon (Kamera/File).
  - Ringkasan dan "Info kartu" tetap di atas tabel. TTD hanya tampil di desktop dan di PDF.
  - Diuji dengan screenshot HP 360/390px dan desktop.
  - Frontend 26/26; backup `Backup/frontend-public-20261005_144647`.

## Kartu Stock — lampiran banyak file + logo di cetakan (2026-10-06)

- **Lampiran per baris:**
  - Satu ikon upload kecil. Di HP ikon ini membuka pilihan kamera/galeri/file dan bisa memilih beberapa file sekaligus, berulang kali.
  - Angka 📎N membuka daftar lampiran (Lihat / Hapus / Tambah foto / file).
  - Slot Dokumen/Foto tetap tidak lagi dipakai.
- **Backend** (tanpa migrasi, tanpa perubahan tabel):
  - `TrackingPhotoService::MULTI_ENTITIES = ['stock_card_entry']`: upload dengan slot `auto` diberi slot unik `f<acak>`, jadi file ditambahkan, bukan menimpa.
  - Modul tracking lain tetap memakai slot tetap; `auto` ditolak untuk modul lain.
  - `listForRows()` mengembalikan daftar file per baris.
  - Lampiran lama (doc/foto) tetap tampil di daftar.
- **Cetak/PDF:**
  - Logo PT (`public/logo.png`, disisipkan sebagai data URI) ada di kiri atas, sejajar judul "KARTU STOCK" yang tetap di tengah.
  - Kolom "LAMPIRAN" berisi jumlah file.
- **Uji:**
  - Backend 332/332, termasuk tes baru untuk banyak lampiran dan penolakan slot `auto` di modul lain.
  - Frontend 26/26.
  - Screenshot HP/desktop dan render template cetak dengan data contoh.
- **Backup:** `Backup/backend-stockcard-files-20261006_090821`, `Backup/frontend-public-20261006_091941`.
- Tidak ada commit/push. Database production tidak disentuh.

## Kartu Stock — Penanggung Jawab di TTD + visualisasi proyek di dashboard (2026-10-06)

- **Penanggung Jawab:**
  - Dihapus dari header di atas tabel, baik di halaman desktop maupun di cetakan/PDF.
  - Kini hanya di bagian TTD. Di desktop, nama diketik langsung di bawah garis TTD dan tersimpan otomatis, sama seperti nama Direktur. Di HP tetap di "Info kartu", karena TTD tidak tampil di HP.
- **Dashboard "Proyek Kartu Stock":**
  - Endpoint: `GET /api/dashboard/operations` → `stock_card_projects`. Hanya untuk user dengan item.view / inventory.view; selain itu `null`.
  - Hanya proyek yang punya kartu yang dihitung, karena tabel projects juga dipakai modul lain. Daftar dibatasi 10 proyek: yang berjalan dulu, lalu yang aktivitasnya terbaru.
  - Ukuran yang dipakai adalah jumlah baris transaksi, bukan qty, karena satuan barang berbeda-beda.
  - Ringkasan: proyek berjalan, jumlah kartu, transaksi 30 hari, dan kartu dengan sisa minus.
  - Per proyek: bar baris masuk/keluar dengan angkanya, status, jumlah kartu, tanggal terakhir, kartu sisa minus, dan kartu belum diisi. Klik membuka proyeknya.
  - Warna masuk/keluar: ungu #4a3aa7 dan kuning #eda100, lolos validasi palet. Kontras kuning di bawah 3:1, sehingga angka selalu ditulis sebagai teks.
- **Uji:**
  - Backend 333/333, termasuk tes baru dashboard proyek kartu stock dan hak akses.
  - Query dicek read-only di MySQL live.
  - Frontend 26/26; screenshot HP/desktop.
- **Backup:** `Backup/backend-dash-stockcard-20261006_154633`, `Backup/frontend-public-20261006_155109`.
- Tanpa migrasi. Tidak ada commit/push.

## Dashboard Kartu Stock — grafik barang masuk & keluar harian/mingguan/bulanan (2026-10-06)

- **Posisi:** bagian "Kartu Stock — Barang Masuk & Keluar" ada di paling bawah dashboard. Panel proyek sebelumnya di bagian Tracking & Pembelian dihapus, beserta field `stock_card_projects` di `/api/dashboard/operations`.
- **Endpoint baru:** `GET /api/dashboard/stock-cards?period=daily|weekly|monthly&project_id=&item_id=` (`StockCardDashboardController`).
  - Akses: item.view / inventory.view.
  - Rentang: Harian 30 hari, Mingguan 12 minggu (Senin–Minggu), Bulanan 12 bulan. Periode berjalan ikut dihitung karena ini laporan progres.
  - Semua barang → jumlah baris transaksi, karena satuan berbeda. Satu barang → qty dalam satuannya.
  - Rekap per barang: qty masuk/keluar pada periode, satuan, dan sisa saldo kartu saat ini. Barang dengan sisa minus ditandai.
- **Frontend:**
  - Pilihan Harian/Mingguan/Bulanan, filter Proyek dan Barang, dan 4 kotak ringkasan.
  - Grafik batang Masuk (ungu) vs Keluar (kuning), dengan tooltip rentang tanggal.
  - Tabel rekap (daftar ringkas di HP). Klik barang untuk melihat grafik qty barang itu.
- **Uji:**
  - Backend 334/334, termasuk 2 tes baru (bucket harian/mingguan/bulanan, qty vs baris, sisa, hak akses, validasi).
  - Dicek read-only di MySQL live.
  - Frontend 26/26; screenshot HP/desktop.
- **Backup:** `Backup/backend-dash-stockcard2-20261006_161155`, `Backup/frontend-public-20261006_161946`.
- Tanpa migrasi. Tidak ada commit/push.

## Kartu Stock — share link per proyek (akses tanpa login) (2026-10-06)

- **Keputusan user:**
  - Link dibuat per proyek.
  - Akses dipilih saat membuat link: **Lihat / Edit / Full**.
  - Tanpa PIN, tanpa masa berlaku, tanpa wajib nama. Akses dicabut dengan menghapus link.
- **Database:** migrasi aditif `2026_10_06_120000_create_stock_card_share_links_table`, dijalankan live setelah backup `Backup/stockwise-db-20261006_163245.sql`.
  - Tabel baru `stock_card_share_links` (project_id, token 40 karakter acak unik, access, label, created_by, last_used_at).
  - Kolom opsional baru `stock_card_entries.share_link_id`, untuk mencatat baris yang diisi lewat link.
  - Tidak ada data lama yang diubah.
- **API publik:** `/api/public/kartu-stock/{token}/…`, tanpa login, dibatasi 240 request/menit per IP. Semua akses dibatasi ke proyek link itu (kartu/baris/lampiran proyek lain → 404).
  - **Lihat:** proyek, kartu, lampiran, cetak/PDF.
  - **Edit:** juga tambah/ubah transaksi, header kartu, dan upload lampiran. Tidak bisa menghapus atau membuat kartu (403).
  - **Full:** juga hapus transaksi/lampiran/kartu dan buat kartu baru. Pencarian barang hanya menampilkan kartu proyek ini.
  - Info stok Accurate disembunyikan dari akses lewat link.
- **Kelola link** (user dengan item.update): `GET/POST /api/stock-card-projects/{id}/share-links`, `PUT/DELETE /api/stock-card-share-links/{id}`.
- **Frontend:**
  - Tombol "Bagikan link" di halaman proyek: buat link (pilih akses, nama opsional, otomatis tersalin), ubah akses, salin, hapus. Ada peringatan bila STOCKWISE dibuka lewat localhost.
  - Halaman publik `/share/kartu-stock/{token}` dan `/share/kartu-stock/{token}/kartu/{id}`: header logo + label akses, tanpa menu.
  - Halaman publik memakai komponen yang sama dengan halaman login, termasuk tabel edit langsung, lampiran, dan cetak.
- **Uji:**
  - Backend 338/338, termasuk `StockCardShareTest`: lihat, edit, full, kelola/cabut link, dan isolasi antar proyek.
  - Frontend 26/26.
  - E2E di server live sebagai tamu: link Lihat sementara dibuka di HP 390px lalu dihapus; link salah → "Link tidak ditemukan atau sudah dihapus".
- **Catatan jaringan:** link hanya bisa dibuka dari perangkat yang bisa menjangkau server STOCKWISE (LAN). Tidak ada perubahan port, firewall, atau jaringan.
- **Backup kode:** `Backup/backend-stockcard-share-20261006_162953`, `Backup/frontend-public-20261006_163830`, `Backup/frontend-public-20261006_164116`.
- Tidak ada commit/push.

## Kartu Stock — Sisa bisa diketik (2026-10-07)

- **Perilaku:**
  - Kolom Sisa di tabel kartu kini bisa diketik. Masuk/Keluar terisi otomatis dari selisih terhadap saldo sebelum baris itu: naik = Masuk, turun = Keluar, tidak pernah keduanya.
  - Sebaliknya, mengetik Masuk/Keluar langsung menampilkan Sisa.
  - Baris baru menampilkan saldo sebelumnya sebagai petunjuk.
- **Validitas:**
  - Yang disimpan tetap hanya Masuk/Keluar; backend tidak berubah. Sisa semua baris selalu dihitung ulang, sehingga saldo tidak bisa ditulis paksa sampai tidak cocok.
  - Saldo sebelumnya mengikuti urutan kartu di backend (tanggal, lalu urutan tulis), termasuk baris bertanggal mundur.
  - Pembulatan 4 desimal, sama dengan database.
  - Sisa sama dengan sebelumnya → tidak disimpan (petunjuk "Isi Masuk atau Keluar").
- **Berlaku juga** untuk akses lewat share link (Edit/Full) dan untuk tampilan HP (tabel yang sama).
- **Uji:**
  - Unit test baru `balance.test.ts` (3 tes); frontend 29/29.
  - Uji browser dengan request dicegat: Sisa 13→12 → PUT qty_out 3; baris baru Sisa 20 (saldo 13) → POST qty_in 7; Keluar 4 → Sisa 9.
- **Deploy:** backup `Backup/frontend-public-20261007_085707`. Tanpa perubahan backend/database. Tidak ada commit/push.

## PO — filter fleksibel (2026-10-07)

- **Pencarian:**
  - Sebelumnya hanya nomor PO.
  - Kini per kata (semua kata harus cocok, urutan bebas) di: no. PO, vendor (nama/kode), barang (deskripsi baris, kode/nama Master Barang, pencarian longgar Ø/O), no. PPB (Accurate & internal), no. RI yang menerima, dan catatan.
- **Filter baru:** tanggal dari–sampai dan sumber (Accurate/Internal), di samping status. Ada tombol Reset dan jumlah hasil.
- **Kode:** `PurchaseOrderController::index` (read-only, tanpa migrasi).
- **Uji:**
  - Backend 339/339, termasuk `PurchaseOrderSearchTest` baru; frontend 29/29.
  - Data live (read-only): 1.365 PO, pencarian 50–150 ms.
- **Backup:** `Backup/po-filter-20261007_095222`, `Backup/frontend-public-20261007_095851`. Tidak ada commit/push.

## Dashboard Kartu Stock — line chart alur masuk/keluar + keluar per barang (2026-10-07)

- **Grafik utama:** batang diganti line chart "Alur Barang Masuk & Keluar per Hari/Minggu/Bulan".
  - Gaya sama dengan "Alur Pembelian per Bulan": garis Masuk ungu dan Keluar kuning, total di legenda.
  - Menurut tanggal pengerjaan (tanggal baris kartu).
  - Semua barang = jumlah baris; satu barang = qty dalam satuannya.
- **Grafik baru "Barang Keluar per Barang":**
  - Batang mendatar, 10 barang dengan qty keluar terbanyak pada periode/proyek terpilih.
  - Label qty + satuan di ujung batang, karena satuan berbeda.
  - Klik batang untuk memilih barang itu, sehingga grafik alur menampilkan qty barang tersebut.
- **Data:** dari endpoint yang sudah ada (`/api/dashboard/stock-cards`); tanpa perubahan backend/database.
- **Uji:** frontend 29/29; screenshot desktop/HP; klik batang → request `item_id` benar.
- **Backup:** `Backup/frontend-public-20261007_102629`. Tidak ada commit/push.
