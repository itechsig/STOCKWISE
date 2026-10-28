# PHASE 2 — Auth + RBAC — Report

Format: brief §BC. Lanjutan dari PHASE 1.

## PHASE
2 — Authentication (Sanctum) + RBAC (roles, permissions, route protection) + seeding master
orang/divisi dari Excel.

## FILES CREATED

**backend/**
- Migrations: `2026_09_08_090001..090008` — `sites`, `departments`, `employees`, kolom STOCKWISE di
  `users`, `roles`, `permissions`, `role_user`, `permission_role`
- Models: `Site`, `Department`, `Employee`, `Role`, `Permission`, trait `Concerns/HasRolesAndPermissions`
- `app/Support/Rbac/Rbac.php` — katalog RBAC tunggal (7 role, 150 permission, matriks role×permission)
- `app/Http/Middleware/CheckPermission.php` — alias route `permission:slug` / `permission:a|b`
- `app/Http/Controllers/Api/{AuthController,RoleController,PermissionController}.php`
- `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Resources/UserResource.php`
- `app/Support/Import/` — `SpreadsheetReader`, `Importer`, `ImportResult`,
  `Importers/{DepartmentImporter,EmployeeImporter}`
- `app/Console/Commands/StockwiseImportCommand.php` — `php artisan stockwise:import`
- `config/stockwise.php` — path file Excel + parameter engine
- Seeders: `SiteSeeder`, `RbacSeeder`, `UserSeeder` (11 akun dev)
- Tests: `tests/Feature/Auth/{AuthTest,RbacTest}.php`, `tests/Unit/RbacCatalogTest.php`

**frontend/**
- `src/lib/api.ts` — interceptor 401 → event `auth-expired`, `apiErrorMessage()`
- `src/types/index.ts` — `AuthUser`, `LoginResponse`
- `src/auth/{AuthContext,AuthProvider,guards}.tsx` — context, provider (restore sesi dari token),
  `RequireAuth` / `RequirePermission`
- `src/components/{AppLayout}.tsx`, `src/components/ui/{input,label}.tsx`
- `src/pages/{LoginPage,DashboardPage}.tsx`
- `src/test/utils.tsx`, tests `LoginPage.test.tsx`
- `e2e/auth.spec.ts`

## FILES MODIFIED
- `backend/app/Models/User.php` — trait RBAC, relasi `site`/`employee`, cast `is_active`
- `backend/bootstrap/app.php` — alias middleware `permission`
- `backend/routes/api.php` — `/login`, `/logout`, `/me`, `/roles`, `/permissions`
- `backend/database/seeders/DatabaseSeeder.php` — panggil Site/Rbac/User seeder + `stockwise:import`
- `backend/database/factories/UserFactory.php` — `username`, `is_active`, state `inactive()`
- `backend/tests/TestCase.php` — helper `actingAsRole()`, flush cache (rate limiter) di setUp
- `backend/phpunit.xml` — `STOCKWISE_IMPORT_PATH` diarahkan ke folder kosong saat test
- `frontend/src/{App,router}.tsx` — bungkus `AuthProvider`, rute auth-gated

## COMMAND TO RUN

```bash
cd backend
php artisan migrate:fresh --seed        # migrasi + RBAC + 11 akun + import Excel (divisi & karyawan)
php artisan stockwise:import --list     # daftar importer
php artisan stockwise:import            # jalankan ulang import (idempoten)
php artisan test
php artisan serve --port=8001

cd frontend
npm run test && npm run build
npm run e2e -- auth.spec.ts             # butuh backend :8001 + sudah di-seed
```

## TEST & EXPECTED & ACTUAL

| Test | Expected | Actual |
|------|----------|--------|
| `php artisan test` (AuthTest 8 + RbacTest 6 + RbacCatalogTest 4 + HealthTest 2 + Unit 1) | semua hijau | ✅ **21 passed, 315 assertions, ~5s** (SQLite in-memory) |
| Frontend `LoginPage.test.tsx` + `HealthPage.test.tsx` | hijau | ✅ **3 passed** (~4s) |
| Frontend `tsc -b` / `oxlint` / `vite build` | bersih / sukses | ✅ (2 warning shadcn/effect, non-blocking) · build 407 kB |
| E2E `auth.spec.ts` | 3 hijau (bad creds, login→dashboard→logout, redirect) | ✅ **3 passed** |
| `migrate:fresh --seed` (MySQL `stockwise`) | 7 role, 150 permission, 2 site, 11 user, 17 divisi, 218 karyawan dari Excel | ✅ terverifikasi |
| `curl` login `admingudang` → `/api/me` | token + 106 permission | ✅ |
| `curl` `/api/roles` as super_admin / admin_gudang | 200 / 403 | ✅ |

## ERROR & FIX
1. `me()` resource ter-wrap `data` → tidak konsisten dgn `login`. **Fix:** `me()` balikan
   `{user: UserResource}` seperti login.
2. Sanctum guard cache antar request dalam 1 test method → logout test lolos padahal harusnya 401.
   **Fix:** `$this->app['auth']->forgetGuards()` di test (perilaku produksi tetap benar: 1 request = 1 proses).
3. Rate limiter (`array` cache) bocor antar test → login test kena 429. **Fix:** `Cache::flush()` di `TestCase::setUp`.
4. `stockwise:import` divisi awalnya ikut ambil `Penempatan` STPP (51 nilai, bukan divisi).
   **Fix:** sumber divisi dibatasi kolom `Divisi` (PPB-RI + NPBG); `Penempatan` → nanti `placement_raw` di modul STPP (NC-18).
5. Test lambat (~3 mnt): RbacSeeder dijalankan per-test. **Fix:** `$seed`/`$seeder` di kelas test (seed sekali per proses).

## STATUS
**PASS** — 21 test backend + 3 test frontend + 3 e2e hijau; `migrate:fresh --seed` di MySQL
menghasilkan RBAC lengkap + 11 akun + 17 divisi + 218 karyawan dari Excel; login/RBAC terverifikasi
via curl & browser.

## NEXT STEP
PHASE 3 — Master Data + Inventory + Calculation Engine: `categories`, `units`, `warehouses`,
`warehouse_locations`, `items`, `item_safety_stocks`, `inventory`, `stock_movements`; importer
`categories`/`units`/`warehouses`/`items` dari `DATA.xlsx`; `StockwiseEngine` (docs/calculation-engine.md)
+ test TC-INV-001..009.
