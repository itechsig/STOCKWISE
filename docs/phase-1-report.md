# PHASE 1 — Project Setup — Report

Format: brief §BC. Branch: `feature/project-setup` (dari `develop`).

## PHASE
1 — Project Setup: Laravel API + React/Vite SPA + MySQL + Sanctum, jalan lokal.

## FILES CREATED

**Root**
- `.gitignore`, `README.md`

**backend/** (Laravel 13.30.1, PHP 8.4)
- Scaffold `composer create-project laravel/laravel` + `php artisan install:api` (Sanctum)
- `app/Http/Controllers/Api/HealthController.php` — probe `/api/ping`
- `routes/api.php` — `GET /api/ping`, `GET /api/login` (stub 401), `GET /api/user` (auth:sanctum)
- `config/cors.php` — origin dari `FRONTEND_URL` / `CORS_ALLOWED_ORIGINS`
- `tests/Feature/HealthTest.php`, `tests/Unit/ExampleTest.php`
- `.env.example` (MySQL, `stockwise`), `AGENTS.md` + `CLAUDE.md` (pointer ke `docs/`)

**frontend/** (React 19 + Vite 8 + TS 6 + Tailwind 3 + shadcn base)
- `vite.config.ts` — alias `@`, proxy `/api`+`/sanctum` → `:8001`, konfig Vitest
- `tailwind.config.js`, `postcss.config.js`, `src/index.css` (token shadcn light/dark)
- `src/lib/{api,queryClient,utils}.ts` — Axios + bearer token + TanStack Query
- `src/components/ui/{button,card}.tsx` — komponen dasar shadcn
- `src/App.tsx`, `src/router.tsx`, `src/pages/HealthPage.tsx` (+ `.test.tsx`)
- `src/test/setup.ts`, `playwright.config.ts`, `e2e/health.spec.ts`
- `.env.example`, `.gitignore`

## FILES MODIFIED
- `backend/app/Models/User.php` — tambah trait `Laravel\Sanctum\HasApiTokens`
- `backend/bootstrap/app.php` — render `AuthenticationException` → JSON 401 untuk `api/*`
- `backend/phpunit.xml` — test DB = MySQL `stockwise_test` (bukan sqlite :memory:)
- `backend/composer.json` — script `format`, `lint` (Pint)
- `backend/.env` — MySQL, `APP_NAME=STOCKWISE`, `APP_TIMEZONE=Asia/Jakarta`, locale `id`, port 8001
- `frontend/package.json` — script `typecheck`, `test`, `test:watch`, `e2e`
- `frontend/tsconfig.app.json` / `tsconfig.node.json` — paths `@/*`, types vitest, include playwright
- `README.md` — setup & port

## COMMAND TO RUN

```bash
# DB (Laragon MySQL sudah jalan di mesin ini; root tanpa password)
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS stockwise;  CREATE DATABASE IF NOT EXISTS stockwise_test;"

# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve --port=8001        # http://127.0.0.1:8001
php artisan test                     # PHPUnit

# Frontend (terminal lain)
cd frontend
npm install
cp .env.example .env
npm run dev                          # http://127.0.0.1:5173
npm run test                         # Vitest
npm run build                        # tsc -b && vite build
npx playwright install chromium && npm run e2e
```

## TEST & EXPECTED & ACTUAL

| Test | Command | Expected | Actual |
|------|---------|----------|--------|
| Backend unit+feature | `php artisan test` | semua hijau | **3 passed, 9 assertions** ✅ |
| `GET /api/ping` | curl :8001 | 200 `{app:STOCKWISE, database:connected}` | ✅ `{"app":"STOCKWISE","status":"ok","database":"connected","version":"13.30.1"}` |
| `GET /api/user` tanpa token | curl | 401 JSON | ✅ 401 `{"message":"Unauthenticated."}` (dengan & tanpa header Accept) |
| CORS preflight | curl OPTIONS | 204 | ✅ 204 |
| Frontend typecheck | `tsc -b` | tanpa error | ✅ |
| Frontend lint | `oxlint` | tanpa error | ✅ (1 warning fast-refresh di `button.tsx`, konvensi shadcn) |
| Frontend unit | `vitest run` | HealthPage render data backend | ✅ **1 passed** |
| Frontend build | `vite build` | `dist/` terbentuk | ✅ 136 modul, `dist/assets/*` |
| Proxy SPA→API | curl `:5173/api/ping` | JSON backend | ✅ diteruskan ke :8001 |
| E2E | `playwright test` | health page → "connected" | ✅ **1 passed** (chromium, 1.5s) — SPA boot + proxy + backend + DB |

## ERROR & FIX (yang muncul saat setup)

1. **Pest gagal di-install** — `pestphp/pest` v4 butuh phpunit ≤12.5.29, skeleton Laravel 13 pin
   phpunit `^12.5.12` (resolve ke 12.5.33); Pest v5 butuh phpunit 13. → **Fix:** pakai **PHPUnit**
   (diizinkan brief §AL). `docs/testing-plan.md` menyebut "Pest / PHPUnit" — dokumen di-note.
2. **`/api/ping` 404 di server** — port 8000 sudah dipakai project lain (`D:\PT-Surya-Inti-Gas\Backend`
   `php artisan serve` sejak pagi). → **Fix:** STOCKWISE backend pakai **port 8001**; proxy Vite,
   `.env`, README disesuaikan. (Tidak mematikan server project lain.)
3. **`/api/user` tanpa header Accept → 500** (`Route [login] not defined`). → **Fix:** route bernama
   `login` (stub 401) + render `AuthenticationException` sebagai JSON untuk `api/*`.
4. **`tsc` error `baseUrl` deprecated (TS 6)** → **Fix:** hapus `baseUrl`, cukup `paths` (bundler resolution).
5. **Vite bind `localhost` saja** → **Fix:** `server.host = '127.0.0.1'`.

## STATUS
**PASS** — backend & frontend jalan lokal, saling terhubung, semua test hijau.

## NEXT STEP
PHASE 2 — Auth + RBAC: migrasi `users/roles/permissions` (dari `docs/erd.md` Grup A), endpoint
`login/logout/me`, middleware `permission`, seeder akun per role, test TC-SEC-001..010.
Tunggu approval sebelum mulai (sesuai pola brief).
