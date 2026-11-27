# Jeevan API — Backend Context & Architecture Rules

Repository ini adalah backend REST API Modular Monolith untuk Jeevan Multi-Merchant SaaS POS, dibangun dengan Laravel 13, PHP 8.4, PostgreSQL, dan `nwidart/laravel-modules`.

## 1. Directory Map & Module Structure

- `app/`: Hanya untuk bootstrap dan global exceptions/middleware (`bootstrap/app.php`). Seluruh logika domain berada di dalam `Modules/`.
- `Modules/Core/`: Fondasi shared domain:
  - `BaseModel`, `BaseService`, `BaseController`, `BaseFormRequest`.
  - `MerchantContext`, `BranchContext`.
  - Traits: `BelongsToMerchant`, `BelongsToMerchantOrGlobal`, `HasAudit`, `ApiResponse`, `MerchantAwareJob`.
  - Scopes: `MerchantScope`, `MerchantOrGlobalScope`.
  - Support: `ServerSideTable`, `DataTableConfig`, `Quantity`.
- `Modules/<ModuleName>/`: Modul fungsional vertikal (misal: `Auth`, `Merchant`, `Operational`, `Product`, `Partner`, `Inventory`, `Purchase`, `User`, `Permission`):
  - `app/Http/Controllers/`: Thin controller, memanggil Service/Action.
  - `app/Http/Requests/`: Validasi request (`declare(strict_types=1);`, scoped unique ke `merchant_id`).
  - `app/Services/`: Business logic, kalkulasi, `DB::transaction()`, dan `lockForUpdate()`.
  - `app/Models/`: Model domain yang menggunakan `BelongsToMerchant` dan `MerchantScope`.
  - `app/Transformers/`: Eloquent API Resources untuk memformat respons data.
  - `routes/api.php`: Registrasi route endpoint modul.
  - `tests/Feature/`: Feature tests spesifik modul.

## 2. Multi-Tenancy, Auth & Authorization Guardrails

1. **Merchant Context & Scoping:**
   - Semua route merchant menggunakan middleware `resolve.merchant` dan `merchant.active`.
   - Context merchant aktif diakses via `MerchantContext` singleton.
   - Semua tabel/model milik merchant WAJIB menggunakan trait `BelongsToMerchant` dan `MerchantScope`.
   - **IDOR & Cross-Tenant Data Leakage Dilarang Keras:** Selalu batasi query dengan merchant boundary yang terverifikasi di level service/query.
2. **Guards & Principals:**
   - `users` (Merchant Users): Menggunakan guard `merchant` (`auth:merchant`) via Sanctum stateful cookie session.
   - `employees` (Platform Operators): Menggunakan guard `employee` (`auth:employee`) yang terpisah sepenuhnya.
   - Dilarang menggabungkan kedua guard tanpa pemisahan rute yang eksplisit.
3. **Spatie Permissions with Teams:**
   - Multi-tenancy permission menggunakan fitur Teams Spatie dengan `team_foreign_key = 'merchant_id'`.
   - Pengujian permission pada route menggunakan middleware `role_or_permission:<role>|<permission>` atau method `$user->can(...)`.
4. **Module Entitlement:**
   - Endpoint fitur vertikal opsional wajib diproteksi middleware `module:<module_slug>` (alias `EnsureModuleEntitled`).
   - Jika modul tidak aktif/entitled untuk merchant terkait, API wajib mengembalikan respons HTTP 403 `MODULE_NOT_ENTITLED`.

## 3. REST API Contract & Response Envelope

Semua controller turunan `BaseController` menggunakan trait `ApiResponse`:
- **Sukses (Single/List):** `$this->success($data, $message, 200)` -> `{ "success": true, "message": "...", "data": ... }`
- **Created:** `$this->created($data, $message)` -> `{ "success": true, "message": "...", "data": ... }` (HTTP 201)
- **Error:** `$this->error($message, $code, $details, $errorCode)` -> `{ "success": false, "error": { "code": "...", "message": "...", "details": ... } }`
- **Server-Side DataTables:** Menggunakan `ServerSideTable::paginate($query, $config, $request)` yang kompatibel dengan TanStack Table di frontend `jeevan-app`.

## 4. Testing Mandate (4 Pilar Wajib)

Setiap pembuatan endpoint baru atau perubahan logika bisnis di `Modules/<ModuleName>/tests/Feature/` wajib menguji:
1. **Happy Path & Validation:** Skenario sukses (200/201) dan penolakan validasi input (422).
2. **Authentication & Authorization:** Request tanpa login (401) dan user tanpa permission Spatie yang sesuai (403).
3. **Cross-Merchant Isolation:** Merchant A tidak boleh membaca, mengubah, atau menghapus resource milik Merchant B (wajib menghasilkan 404 atau 403).
4. **Module Entitlement Access:** Request dari merchant yang modulnya nonaktif wajib menghasilkan 403 `MODULE_NOT_ENTITLED`.

## 5. Exact Verification Commands (Run from `jeevan-api/` directory)

- **Run all tests (compact):** `php artisan test --compact`
- **Run specific module tests:** `php artisan test Modules/<ModuleName>/tests`
- **Run single test file:** `php artisan test --compact tests/Feature/<TestName>.php` (atau path modul terkait)
- **Run single test method:** `php artisan test --compact --filter=<method_name>`
- **Code style fixer (dirty files):** `php vendor/bin/pint --dirty`
- **Code style fixer (specific module):** `php vendor/bin/pint Modules/<ModuleName>`
- **Check routes:** `php artisan route:list --path=api/v1`
