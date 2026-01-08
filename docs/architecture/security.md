# Module Access Control & Security

**Last Updated:** December 30, 2025
**Status:** Production Ready
**Related:** [Module Loading System](./module-loading.md)

---

## Table of Contents

1. [Overview](#overview)
2. [RequireModule Middleware](#requiremodule-middleware)
3. [Module Classification](#module-classification)
4. [Route Protection Patterns](#route-protection-patterns)
5. [Testing Strategy](#testing-strategy)
6. [Implementation Guide](#implementation-guide)
7. [Troubleshooting](#troubleshooting)

---

## Overview

The Multi-App Vertical System implements a **two-tier security model** for API routes:

1. **Authentication & Authorization** (`auth:sanctum` + `SetPermissionsTeam`)
   - Verifies user identity
   - Loads user permissions and company context
   - Standard for ALL routes

2. **Module Access Control** (`module:ModuleName`)
   - Verifies tenant's vertical includes the required module
   - Blocks access to vertical-specific features
   - Only required for vertical-specific modules

### Security Flow

```
Request → auth:sanctum → SetPermissionsTeam → RequireModule → Permission Gates → Controller
          ↓              ↓                     ↓               ↓
          401            Sets team context     403             403
          (no auth)      (tenant_id)           (no module)     (no permission)
```

---

## RequireModule Middleware

### Location
`/Users/houssamr/Projects/mecanospex/apps/api/app/Http/Middleware/RequireModule.php`

### Registration
```php
// bootstrap/app.php
$middleware->alias([
    'module' => RequireModule::class,
]);
```

### How It Works

```php
public function handle(Request $request, Closure $next, string $module): Response
{
    // 1. Get authenticated user (already verified by auth:sanctum)
    $user = $request->user();

    // 2. Get tenant from user
    $tenant = $user->tenant;

    // 3. Get effective module configuration for tenant
    $config = $this->configService->getConfigForTenant($tenant);

    // 4. Check if module is enabled
    if (!$config->hasModule($module)) {
        abort(403, "Module '{$module}' is not enabled for this business type");
    }

    return $next($request);
}
```

### Resolution Chain

```
User Request
    ↓
User → tenant_id → Tenant
    ↓
Tenant → vertical (enum: 'mechanic', 'pharmacy', etc.)
    ↓
Vertical Config → default_modules + enabled_extras
    ↓
CompanyConfig DTO → allEnabledModules (merged list)
    ↓
hasModule($module) → true/false
```

---

## Module Classification

### Core Modules (No Module Middleware)

Available to **ALL verticals** (both IziPOS and Otospex):

| Module | Description | Routes |
|--------|-------------|--------|
| **Identity** | Users, roles, permissions | `/api/v1/users`, `/api/v1/roles` |
| **Tenant** | Multi-tenancy, subscriptions | `/api/v1/tenants`, `/api/v1/companies` |
| **Company** | Company settings | `/api/v1/company`, `/api/v1/company/settings` |
| **Partner** | Customers & suppliers | `/api/v1/partners` |
| **Product** | Catalog, categories, pricing | `/api/v1/products`, `/api/v1/categories` |
| **Inventory** | Stock levels, locations | `/api/v1/stock`, `/api/v1/locations` |
| **Document** | Quotes, orders, invoices, DNs | `/api/v1/documents` |
| **Treasury** | Payments, instruments, repos | `/api/v1/payments` |
| **Accounting** | Journal entries, GL, chart of accounts | `/api/v1/journal-entries` |
| **Compliance** | Audit logs, fiscal events | `/api/v1/audit-events` |
| **Pricing** | Price lists, margins | `/api/v1/price-lists` |
| **Media** | File uploads, images | `/api/v1/media` |
| **Dashboard** | Widgets, KPIs | `/api/v1/dashboard` |
| **Taxation** | Tax rules, stamp duty | `/api/v1/tax` |
| **Expense** | Expense tracking | `/api/v1/expenses` |
| **Service** | Service catalog | `/api/v1/services` |
| **Import** | Data import wizard | `/api/v1/imports` |

**Middleware Pattern:**
```php
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
```

### Vertical-Specific Modules (Require Module Middleware)

#### Otospex Only (Product: 'otospex')

| Module | Verticals | Middleware | Routes |
|--------|-----------|------------|--------|
| **Vehicle** | mechanic, body_shop, parts_retailer, car_glass, tire_shop, service_station | `'module:Vehicle'` | `/api/v1/vehicles` |
| **Workshop** | mechanic, body_shop, car_glass, tire_shop, service_station | `'module:Workshop'` | `/api/v1/work-orders` |

#### IziPOS Only (Product: 'izipos')

| Module | Verticals | Middleware | Routes |
|--------|-----------|------------|--------|
| **Menu** | restaurant, coffee_shop | `'module:Menu'` | `/api/v1/menu-items` |
| **Tables** | restaurant | `'module:Tables'` | `/api/v1/tables` |
| **BatchExpiry** | pharmacy, parapharmacy | `'module:BatchExpiry'` | `/api/v1/batches` |

#### Optional Extras (Enabled via `enabled_extras`)

| Module | Available For | Middleware | Routes |
|--------|---------------|------------|--------|
| **Fleet** | All Otospex verticals | `'module:Fleet'` | `/api/v1/fleet` |
| **Appointments** | All verticals (both products) | `'module:Appointments'` | `/api/v1/appointments` |
| **Recipe** | pharmacy, parapharmacy | `'module:Recipe'` | `/api/v1/recipes` |
| **Prescription** | pharmacy | `'module:Prescription'` | `/api/v1/prescriptions` |

**Middleware Pattern:**
```php
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:ModuleName'])
```

---

## Route Protection Patterns

### Standard Pattern (Core Modules)

```php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
    ->group(function (): void {
        Route::get('/products', [ProductController::class, 'index'])
            ->middleware('can:products.view')
            ->name('products.index');

        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('can:products.create')
            ->name('products.store');
    });
```

### Vertical-Specific Pattern

```php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Vehicle\Presentation\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle'])
    ->group(function (): void {
        Route::get('/vehicles', [VehicleController::class, 'index'])
            ->middleware('can:vehicles.view')
            ->name('vehicles.index');

        Route::post('/vehicles', [VehicleController::class, 'store'])
            ->middleware('can:vehicles.create')
            ->name('vehicles.store');
    });
```

### Common Mistakes to Avoid

❌ **WRONG - Missing 'api' middleware:**
```php
Route::prefix('api/v1')->middleware(['auth:sanctum', SetPermissionsTeam::class])
// This will cause 401 Unauthorized errors
```

❌ **WRONG - Missing SetPermissionsTeam:**
```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])
// This will cause permission/team context issues
```

❌ **WRONG - Adding module middleware to core modules:**
```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Product'])
// Product is a CORE module - this will block retail tenants!
```

❌ **WRONG - Case-sensitive module names:**
```php
->middleware(['module:vehicle'])  // Wrong - should be 'Vehicle'
```

✅ **CORRECT - Reference Pattern:**
```php
// Compare your routes.php with Vehicle module routes for exact pattern
// File: app/Modules/Vehicle/Presentation/routes.php
```

---

## Testing Strategy

### Unit Tests (`RequireModuleTest.php`)

Tests the middleware in isolation with mocked dependencies:

```php
public function test_allows_request_when_module_is_enabled(): void
{
    $tenant = Mockery::mock(Tenant::class);
    $request = $this->createAuthenticatedRequest($tenant, '/api/v1/vehicles');

    $config = new CompanyConfig(
        vertical: Vertical::Mechanic,
        defaultModules: ['Identity', 'Vehicle', 'Workshop'],
        enabledExtras: ['Appointments'],
        allEnabledModules: ['Identity', 'Vehicle', 'Workshop', 'Appointments']
    );

    $this->configService
        ->shouldReceive('getConfigForTenant')
        ->once()
        ->with($tenant)
        ->andReturn($config);

    $response = $this->middleware->handle($request, $next, 'Vehicle');

    $this->assertEquals(200, $response->getStatusCode());
}
```

**8 test cases:**
1. Allows request when module is enabled
2. Blocks request when module is not enabled
3. Allows request when module is in default modules
4. Allows request when module is in enabled extras
5. Blocks request for disabled extra
6. Throws exception when user not authenticated
7. Module check is case sensitive
8. Core modules are always accessible

### Feature Tests (`ModuleAccessControlTest.php`)

End-to-end tests with real database:

```php
public function test_retail_user_cannot_access_vehicle_routes(): void
{
    Sanctum::actingAs($this->retailUser);

    $response = $this->getJson('/api/v1/vehicles', [
        'X-Company-Id' => $this->retailCompany->id,
    ]);

    // Should get 403 Forbidden from module middleware
    $response->assertStatus(403);
    $response->assertJson([
        'message' => "Module 'Vehicle' is not enabled for this business type",
    ]);
}
```

**10 test scenarios:**
1. Mechanic user can access vehicle routes
2. Retail user cannot access vehicle routes
3. Retail user cannot access specific vehicle
4. Retail user cannot create vehicle
5. Retail user cannot update vehicle
6. Retail user cannot delete vehicle
7. Unauthenticated user cannot access vehicle routes
8. Mechanic with fleet extra can access vehicle routes
9. Pharmacy user cannot access vehicle routes
10. Core modules accessible to all verticals

### Test Results

```
Tests:    18 passed (32 assertions)
- 8 unit tests (15 assertions)
- 10 feature tests (17 assertions)
```

---

## Implementation Guide

### When Adding a New Vertical-Specific Module

Follow this TDD approach:

#### Step 1: Write Unit Tests

```php
// tests/Unit/Middleware/RequireModuleTest.php
public function test_allows_request_when_workshop_module_is_enabled(): void
{
    $config = new CompanyConfig(
        vertical: Vertical::Mechanic,
        defaultModules: ['Identity', 'Vehicle', 'Workshop'],
        enabledExtras: [],
        allEnabledModules: ['Identity', 'Vehicle', 'Workshop']
    );

    $response = $this->middleware->handle($request, $next, 'Workshop');

    $this->assertEquals(200, $response->getStatusCode());
}

public function test_blocks_request_when_workshop_module_not_enabled(): void
{
    $config = new CompanyConfig(
        vertical: Vertical::Retail,
        defaultModules: ['Identity', 'Catalog'],
        enabledExtras: [],
        allEnabledModules: ['Identity', 'Catalog']
    );

    $this->expectException(HttpException::class);
    $this->expectExceptionMessage("Module 'Workshop' is not enabled for this business type");

    $this->middleware->handle($request, $next, 'Workshop');
}
```

#### Step 2: Create Module Routes

```php
// app/Modules/Workshop/Presentation/routes.php
<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Workshop\Presentation\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Workshop'])
    ->group(function (): void {
        Route::apiResource('work-orders', WorkOrderController::class);
    });
```

#### Step 3: Write Feature Tests

```php
// tests/Feature/Security/WorkshopModuleAccessTest.php
public function test_mechanic_can_access_workshop_routes(): void
{
    $mechanicTenant = Tenant::factory()->create(['vertical' => 'mechanic']);
    $mechanicUser = User::factory()->create(['tenant_id' => $mechanicTenant->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($mechanicTenant->id);
    $mechanicUser->givePermissionTo('work-orders.view');

    Sanctum::actingAs($mechanicUser);

    $response = $this->getJson('/api/v1/work-orders');

    $response->assertStatus(200);
}

public function test_retail_cannot_access_workshop_routes(): void
{
    $retailTenant = Tenant::factory()->create(['vertical' => 'retail']);
    $retailUser = User::factory()->create(['tenant_id' => $retailTenant->id]);

    Sanctum::actingAs($retailUser);

    $response = $this->getJson('/api/v1/work-orders');

    $response->assertStatus(403);
    $response->assertJson([
        'message' => "Module 'Workshop' is not enabled for this business type",
    ]);
}
```

#### Step 4: Update Vertical Configuration

```php
// config/verticals.php
'mechanic' => [
    'product' => 'otospex',
    'default_modules' => [
        'Identity',
        'Vehicle',
        'Workshop',  // ← Add here
        // ...
    ],
    // ...
],
```

#### Step 5: Run Tests

```bash
php artisan test --filter=RequireModuleTest
php artisan test --filter=WorkshopModuleAccessTest
```

---

## Troubleshooting

### Issue: 403 Forbidden on Core Module Routes

**Symptom:**
```json
{"message": "Module 'Product' is not enabled for this business type"}
```

**Cause:** Core module incorrectly configured with module middleware.

**Fix:** Remove `'module:Product'` from middleware array:

```php
// WRONG
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Product'])

// CORRECT
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
```

---

### Issue: 401 Unauthorized on Module Routes

**Symptom:**
```json
{"error": {"code": "UNAUTHENTICATED"}}
```

**Possible Causes:**
1. Missing `'api'` middleware
2. Missing `'auth:sanctum'` middleware
3. User not authenticated

**Fix:** Ensure complete middleware chain:

```php
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle'])
```

---

### Issue: Permission Errors on Module Routes

**Symptom:**
```json
{"message": "This action is unauthorized."}
```

**Cause:** User authenticated and has module access, but lacks specific permission.

**Fix:** Assign permission to user or role:

```php
// In tests
app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
$user->givePermissionTo('vehicles.view');

// In seeders
$user->assignRole('manager');  // Role has vehicles.view permission
```

---

### Issue: RuntimeException - User Must Be Authenticated

**Symptom:**
```
RuntimeException: User must be authenticated to check module access
```

**Cause:** RequireModule middleware running before auth:sanctum middleware.

**Fix:** Ensure correct middleware order:

```php
// CORRECT ORDER
->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle'])

// WRONG ORDER (will fail)
->middleware(['module:Vehicle', 'api', 'auth:sanctum'])
```

---

### Issue: Spatie Permission Team Context Errors

**Symptom:**
```
SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: model_has_permissions.tenant_id
```

**Cause:** Not setting permissions team ID before assigning permissions in tests.

**Fix:**

```php
// In tests - ALWAYS set team ID before givePermissionTo()
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
$user->givePermissionTo('vehicles.view');
```

---

## Future Enhancements

### Planned Module Middleware Usage

As new vertical-specific modules are implemented, they should use this middleware:

- [ ] `'module:Workshop'` - Work order management
- [ ] `'module:Menu'` - Restaurant menu items
- [ ] `'module:Tables'` - Restaurant table management
- [ ] `'module:BatchExpiry'` - Pharmacy batch/expiry tracking
- [ ] `'module:Fleet'` - Fleet management (extra)
- [ ] `'module:Appointments'` - Appointment scheduling (extra)
- [ ] `'module:Recipe'` - Recipe/formula management (extra)
- [ ] `'module:Prescription'` - Prescription management (extra)

### Module Dependency Resolution (Future)

```php
// config/modules.php (planned)
'dependencies' => [
    'Recipe' => ['Product', 'Inventory'],
    'Workshop' => ['Vehicle', 'Inventory'],
    'Tables' => ['Menu'],
    'Fleet' => ['Vehicle', 'Customer'],
]
```

The CompanyConfigService will auto-resolve dependencies:
- If `Recipe` is enabled, automatically include `Product` and `Inventory`
- Prevents broken module access when dependencies are missing

---

## Related Documentation

- [Module Loading System](./module-loading.md) - CompanyConfig, VerticalConfig, module resolution
- [Multi-Tenancy](./multi-tenancy.md) - Tenant isolation, schema-based architecture
- [Testing Guide](../testing/vertical-testing-strategy.md) - Priority matrix, E2E scenarios

---

## Changelog

### 2025-12-30
- **Milestone 4 Complete**: RequireModule middleware production-ready
- Fixed Import module missing SetPermissionsTeam middleware
- All 18 tests passing (8 unit + 10 feature)
- Comprehensive security audit completed (Opus 4.5)

---

**Maintained by:** AutoERP Architecture Team
**Contact:** See CLAUDE.md for development guidelines
