# STOCKWISE — Backend (Laravel API)

REST API for the STOCKWISE Inventory Intelligence System (PT Surya Inti Gas).

## Context

- Design & requirements live in [`../docs/`](../docs/): `erd.md`, `status-flow.md`,
  `roles-permissions.md`, `api-spec.md`, `calculation-engine.md`, `business-process.md`,
  `testing-plan.md`, `assumptions.md`. Read those before changing domain logic.
- Built phase by phase — see `../README.md` for the phase table. Do not skip ahead or skip tests.

## Stack

Laravel 13 · PHP 8.4 · MySQL 8 · Sanctum (token auth) · PHPUnit.
Local DB: Laragon MySQL, `root` / no password, db `stockwise`. Tests run on SQLite in-memory
(fast); `composer test:mysql` runs the suite against MySQL `stockwise_test` for parity.
Migrations must stay portable — avoid raw MySQL-only SQL.

## Commands

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8001     # http://127.0.0.1:8001 (:8000 often taken on this machine)
php artisan test                  # PHPUnit (MySQL stockwise_test)
vendor/bin/pint                   # format
```

## Rules (from the project brief)

- Backend is the source of truth for permissions — check in Policies/Gates, never trust the client.
- All stock changes go through the ledger service in a DB transaction; never mutate `inventory` directly.
- Stock decreases on PICKUP, increases on RECEIVING CONFIRMED, adjusts only after opname APPROVE.
- Don't invent Excel structure; unknowns are tracked as `[NEEDS CONFIRMATION]` in `../docs/assumptions.md`.
