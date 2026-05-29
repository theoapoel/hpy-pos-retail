# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Mitra POS HPY** — Laravel 11 Point-of-Sale system with ERPNext/HPY integration. PHP 8.2+, MySQL, Blade + Vite.

## Commands

```bash
# Dependencies
composer install
npm install

# Dev server (XAMPP already handles Apache; use artisan serve for standalone)
php artisan serve

# Frontend assets
npm run dev      # watch mode
npm run build    # production build

# Database
php artisan migrate
php artisan db:seed                 # creates 3 demo users, 26 products, ~300 transactions
php artisan migrate:fresh --seed    # full reset

# Cache (clear after .env or config changes)
php artisan cache:clear && php artisan config:clear && php artisan route:clear

# Linting (PSR-12 via Laravel Pint)
./vendor/bin/pint --test   # check only
./vendor/bin/pint           # auto-fix

# Tests
./vendor/bin/phpunit
./vendor/bin/phpunit tests/Feature/SomeTest.php   # single file
```

## Architecture

### Middleware Layers

Routes use two stacking middlewares defined in `app/Http/Middleware/`:

- `role:admin` / `role:admin,manager` — checks `users.role` column directly
- `permission:module_name` — checks `role_permissions` table via `RolePermission::can()`

The configurable modules are: `dashboard`, `pos`, `transactions`, `products`, `customers`, `stock_transfer`, `sync`. Admin-only routes (users, roles, settings, warehouses, backup) bypass the permission system entirely — they only use `role:admin`.

Permissions are cached per-role for 300 seconds under the key `role_permissions_{role}`. Call `RolePermission::clearCache($role)` after any permission change.

### Configuration Store

`app/Models/Setting.php` is a key-value store used for runtime configuration (ERPNext credentials, store name, default warehouse, etc.). These override `.env` at runtime. Pattern: `Setting::get('key', $default)` / `Setting::set('key', $value)`.

### ERPNext Integration

All ERPNext API calls go through `app/Services/ErpNextService.php` (Guzzle HTTP). Credentials are read from the `Setting` model at runtime — never hardcoded. Sync state is tracked on the `transactions` table (`erp_sync_status`: `pending` / `synced` / `failed`) and audited in `erp_sync_logs`.

The `ErpSyncController` exposes on-demand sync endpoints; there is no background queue — syncs run synchronously per request.

### Transaction Flow

Checkout in `PosController::checkout()` runs inside a DB transaction: validates cart, deducts stock (if `products.track_stock = true`), writes `Transaction` + `TransactionItems`, and marks `erp_sync_status = pending`. Invoice numbers follow the format `INV-YYYYMMDD-XXXX`. Cancellation (admin/manager only) restores stock and soft-deletes the transaction.

### Multi-Warehouse

`Warehouse` models map 1:1 to ERPNext warehouses. One warehouse carries `is_default = true` for POS operations; one may carry `is_transit = true` for in-transit stock. Stock transfers link to ERPNext Warehouse Transfer documents and are soft-deleted for audit.

### Demo Credentials (after seeding)

| Role    | Email                  | Password  | PIN    |
|---------|------------------------|-----------|--------|
| Admin   | admin@larapos.com      | password  | 123456 |
| Manager | manager@larapos.com    | password  | 111222 |
| Cashier | kasir@larapos.com      | password  | 654321 |

Cashiers land directly on the POS page after login; other roles land on the Dashboard.

## Adding a New Permission Module

1. Add entry to `RolePermission::modules()` in [app/Models/RolePermission.php](app/Models/RolePermission.php)
2. Add `->middleware('permission:new_module')` to the relevant route group in [routes/web.php](routes/web.php)
3. Update the permission matrix UI in [resources/views/permissions/index.blade.php](resources/views/permissions/index.blade.php)
