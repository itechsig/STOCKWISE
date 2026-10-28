@echo off
REM ============================================================
REM  STOCKWISE - jalankan semua service untuk development
REM  Klik-ganda file ini, atau jalankan: start-dev.bat
REM ============================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"

REM --- 1. MySQL (Laragon) -------------------------------------
netstat -ano | findstr ":3306 " >nul 2>&1
if %errorlevel%==0 (
  echo [MySQL]    sudah jalan di port 3306
) else (
  set "MYSQLD="
  for /d %%D in ("C:\laragon\bin\mysql\*") do (
    if exist "%%~fD\bin\mysqld.exe" set "MYSQLD=%%~fD\bin\mysqld.exe"
  )
  if defined MYSQLD (
    echo [MySQL]    starting...
    start "STOCKWISE MySQL" /min "!MYSQLD!" --defaults-file="!MYSQLD:\bin\mysqld.exe=\my.ini!"
    timeout /t 6 >nul
  ) else (
    echo [MySQL]    tidak ketemu di C:\laragon - buka Laragon lalu Start All secara manual
  )
)

REM --- 2. Backend Laravel :8001 ------------------------------
REM NOTE: php artisan serve is single-threaded on Windows (its multi-worker
REM mode needs pcntl_fork(), which Windows doesn't have at all) - one long
REM request, e.g. Sync Accurate (1-3 min), freezes the WHOLE app for every
REM user until it finishes. Fine for solo local dev; not for anything else.
echo [Backend]  http://127.0.0.1:8001
start "STOCKWISE Backend" cmd /k "cd /d %~dp0backend && php artisan serve --port=8001"

REM --- 3. Frontend Vite :5173 -------------------------------
echo [Frontend] http://127.0.0.1:5173
start "STOCKWISE Frontend" cmd /k "cd /d %~dp0frontend && npm run dev"

echo.
echo STOCKWISE dev berjalan. Tutup jendela Backend/Frontend untuk berhenti.
echo Login: superadmin@gmail.com / Password@26
timeout /t 3 >nul
