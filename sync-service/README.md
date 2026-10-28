# sync-service — Sync Agent Accurate

Menyalin database Accurate 5 (`D:\GUDANGSIG2025.GDB`) ke **staging** STOCKWISE, memvalidasinya, lalu
mengirim 20 tabel ke API Laravel STOCKWISE. Berjalan di server yang sama dengan Accurate
(`192.168.2.41`) lewat Windows Task Scheduler. **File Accurate hanya dibaca** — Agent tidak pernah
mengubah Accurate, Firebird produksi (port 3051), konfigurasinya, maupun akunnya.

## Alur

```text
① Preflight      service Accurate/Firebird jalan, port 3051 & 3050 aktif, disk cukup — kalau tidak, batal
② Salin file     D:\GUDANGSIG2025.GDB → Staging\tmp\STAGING_NEW.GDB
                  (mode berbagi baca-tulis: Accurate & pengguna tidak pernah terhalang)
③ Checksum       sumber dibaca ulang; SHA-256 harus sama dengan salinan. Beda = Accurate menulis
                  saat disalin → salinan dibuang, dicoba ulang (SOURCE_COPY_ATTEMPTS). Tetap beda → batal
④ Validasi       isi benar database Gudang Accurate (tabel ITEM + kode barang)
⑤ Ganti staging  STAGING.GDB → STAGING_PREV.GDB (cadangan), STAGING_NEW.GDB → STAGING.GDB
⑥ Push           baca 20 tabel (sebagai user GUEST, baca saja) → POST /api/agent/sync/* (per 1000 baris)
⑦ Proses         Laravel memproses di proses terpisah (`accurate:finish-batch`); Agent memantau
                  status sampai selesai. Web tetap bisa dipakai selama proses.
```

Kegagalan di langkah mana pun **tidak pernah** mengganti staging aktif dan tidak mengubah data STOCKWISE;
tercatat di **Log Agen** dan dicoba lagi pada jadwal berikutnya. Hanya **satu job** berjalan dalam satu waktu
(lock `AGENT_LOCK_PATH`); job kedua keluar dengan exit code 2.

## Jadwal (Task Scheduler, akun SYSTEM)

| Jam | Task | Perintah | Isi |
|---|---|---|---|
| 06:30 | `STOCKWISE-Sync-0630-SyncOnly` | `--sync-only` | sync staging hasil salinan 21:00 (tanpa salinan baru) |
| 09:00 | `STOCKWISE-Sync-0900-Full` | `--full` | salin → validasi → staging → sync |
| 12:20 | `STOCKWISE-Sync-1220-Full` | `--full` | salin → validasi → staging → sync |
| 21:00 | `STOCKWISE-Sync-2100-Snapshot` | `--snapshot` | salin → validasi → staging (tanpa sync) |
| — | `STOCKWISE-Sync-Accurate` (lama, **Disabled**) | `--sync-now` | cadangan; 04:00/10:30/20:30 |

Semua task: `MultipleInstances=IgnoreNew`, batas waktu 3 jam, working dir `D:\01.STOCKWISE\sync-service`.
Kembali ke jadwal lama:

```powershell
Get-ScheduledTask -TaskName 'STOCKWISE-Sync-0*','STOCKWISE-Sync-1*','STOCKWISE-Sync-2*' | Disable-ScheduledTask
Enable-ScheduledTask -TaskName 'STOCKWISE-Sync-Accurate'
```

Selain jadwal, job bisa dijalankan dari web: **Integrasi Accurate → Log Agen → Salin GDB ke Staging /
Salin + Sync** (permission `sync.accurate.trigger`) — kode yang dijalankan sama persis.

## Perintah

```bash
python -m agent.run --full        # salin GDB → validasi → staging → sync ke STOCKWISE
python -m agent.run --snapshot    # salin GDB → validasi → staging (tanpa sync)
python -m agent.run --sync-only   # sync staging yang ada sekarang (tanpa salinan baru)
python -m agent.run --sync-now    # run default: = --full bila mode salin-file aktif, selain itu jalur .gbk lama
python -m agent.run --preflight   # cek kesehatan read-only (service, port, disk); exit 0 = OK
python -m agent.run --dry-run     # (jalur .gbk lama) scan saja
```

Exit code: `0` sukses, `1` gagal, `2` job lain sedang berjalan.

## Konfigurasi (`sync-service/.env`, tidak di-commit)

| Variabel | Fungsi |
|---|---|
| `SYNC_ENV=production` | `dummy` = mode uji: menolak port 3051, `D:\GUDANGSIG2025.GDB`, staging asli, API asli |
| `SOURCE_COPY_ENABLED=true` | izinkan salinan dari database Accurate (default `false`) |
| `SOURCE_METHOD=filecopy` | `filecopy` = salin file GDB (tanpa akun Firebird produksi); `gbak` = backup online (butuh `SOURCE_FIREBIRD_USER/PASSWORD` produksi) |
| `SOURCE_DATABASE` | default `D:\GUDANGSIG2025.GDB` |
| `SOURCE_COPY_ATTEMPTS` | percobaan salin bila checksum beda (default 3) |
| `SOURCE_READER_USER/PASSWORD` | user **GUEST** di Firebird **staging** — pembaca salinan (Accurate memberi GUEST hak SELECT, baca saja) |
| `FIREBIRD_HOST/PORT/USER/PASSWORD` | Firebird **staging** `127.0.0.1:3050`, user `stockwise_agent` (restore) |
| `STAGING_PATH`, `STAGING_MIN_FREE_MB` | folder staging (`D:\01.STOCKWISE\Staging`), ruang disk minimum |
| `API_URL`, `API_TOKEN` | API STOCKWISE; token dari `php artisan stockwise:agent-token` (hanya berlaku untuk endpoint agent) |
| `API_COMPLETE_TIMEOUT_SECONDS` | batas tunggu pemrosesan batch di Laravel |
| `AGENT_LOG_PATH`, `AGENT_LOCK_PATH`, `AGENT_STATE_PATH` | log, lock, state (default di `D:\01.STOCKWISE\Logs` / `Config`) |
| `AGENT_ENV_FILE` | pakai file env lain (mis. `.env.dummy`, template `.env.dummy.example`) |

**Rollback ke backup `.gbk` Accurate:** `SOURCE_COPY_ENABLED=false` lalu aktifkan task lama.

## Log & status

| File | Isi | Tampil di web |
|---|---|---|
| `Logs\sync.log` | 1 baris per run (sumber, status, error) | Log Agen → Riwayat Run |
| `Logs\agent.log`, `Logs\error.log` | detail langkah per run | Log Agen → Event Detail |
| `Logs\agent-status.json` | job yang sedang berjalan / hasil job terakhir | Log Agen (banner status) |
| tabel `sync_batches`, `sync_logs` (MySQL) | hasil pemrosesan per batch (hanya data yang berubah) | Sync History |

Password dan token tidak pernah ditulis ke log.

## Test

```bash
pip install -r requirements-dev.txt
python -m pytest tests/
```

Unit test tanpa Firebird/MySQL/jaringan: salin + checksum (`test_file_snapshot.py`), ganti staging, lock,
guard, polling API, status batch, pemilihan backup, scheduler. Uji end-to-end memakai database **dummy** di
Firebird staging port 3050 dan mock API (lihat `docs/sync-agent-security-report.md`) — tidak pernah
menyentuh database Accurate asli.

## Struktur

```
agent/
  run.py            entrypoint & mode (--full/--snapshot/--sync-only/--sync-now)
  file_snapshot.py  salin file GDB + verifikasi checksum
  live_copy.py      sumber salinan (filecopy / gbak) → Staging\tmp
  validate.py       validasi isi database Gudang
  staging.py        ganti staging + rollback (STAGING_PREV.GDB)
  api_client.py     push ke API + polling status batch
  guards.py         pengaman (mode dummy, izin, target, ruang disk)
  preflight.py      cek kesehatan server (read-only)
  lock.py           satu job dalam satu waktu
  procjob.py        proses gbak ikut berhenti bila Agent berhenti
  status.py         Logs\agent-status.json untuk web
  restore.py        restore .gbk / cek integritas (jalur lama)
  scanner.py, state.py, scheduler.py   jalur .gbk lama (backup Accurate)
firebird/           akses baca metadata & baris Firebird
mapping/            daftar 20 tabel + pemetaan tipe Firebird → MySQL
sync/, database/    jalur lama direct-MySQL (tidak dipakai)
```
