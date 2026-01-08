# Multi-App Vertical System - Module Loading Architecture

**Document Version:** 1.0
**Last Updated:** December 30, 2025
**Status:** Production Ready (Milestone 3 Complete)

---

## Table of Contents

1. [Overview](#overview)
2. [Core Concepts](#core-concepts)
3. [Products: IziPOS vs Otospex](#products-izipos-vs-otospex)
4. [Business Verticals](#business-verticals)
5. [Service Architecture](#service-architecture)
6. [Configuration Structure](#configuration-structure)
7. [Module Resolution](#module-resolution)
8. [Database Schema](#database-schema)
9. [Usage Examples](#usage-examples)
10. [Testing Guidelines](#testing-guidelines)
11. [API Reference](#api-reference)

---

## Overview

The AutoERP multi-app vertical system enables a single codebase to serve **two distinct products** (IziPOS and Otospex) across **12 different business verticals**. Each vertical has its own set of default modules and optional extras, creating a customized experience for each business type.

**Key Benefits:**
- Single codebase maintains both IziPOS (POS-focused) and Otospex (automotive-focused) products
- Each tenant has a vertical (e.g., pharmacy, mechanic) that determines their default modules
- Verticals can enable optional extras (e.g., Appointments, Fleet) for additional functionality
- Frontend navigation and available features adapt automatically to the tenant's configuration
- Security enforced via `RequireModule` middleware (prevents unauthorized access to vertical-specific modules)

---

## Core Concepts

### 1. Product
A **product** is the top-level SKU/brand that a customer subscribes to:
- **IziPOS**: Point-of-sale focused product for retail, hospitality, pharmacy
- **Otospex**: Automotive-focused product for mechanics, body shops, parts retailers

**Detection:** Product is determined by the `APP_PRODUCT` environment variable (default: `izipos`)

### 2. Vertical
A **vertical** is the business type/industry of a tenant:
- Examples: `mechanic`, `pharmacy`, `restaurant`, `retail`
- Each vertical belongs to one product
- Each vertical has default modules (e.g., mechanics get Vehicle + Workshop)
- Each vertical has compatible extras (optional modules)

**Storage:** Vertical is stored on the `tenants.vertical` column

### 3. Module
A **module** is a self-contained domain/feature:
- Core modules: `Identity`, `Tenant`, `Catalog`, `Inventory`, `Accounting`
- Vertical-specific modules: `Vehicle`, `Workshop`, `Menu`, `Tables`, `BatchExpiry`
- Optional extras: `Appointments`, `Fleet`, `Prescription`, `Recipe`

**Resolution:** Modules available to a tenant = vertical defaults + enabled extras

### 4. Enabled Extras
**Enabled extras** are optional modules that a tenant activates beyond their vertical defaults:
- Stored as JSONB array in `tenants.enabled_extras`
- Must be compatible with the tenant's vertical
- Examples: A `mechanic` tenant might enable `Appointments` and `Fleet` extras

---

## Products: IziPOS vs Otospex

### IziPOS (Point-of-Sale Product)

**Target Markets:** Retail, hospitality, pharmacy, healthcare
**Tagline:** "Modern POS for modern businesses"

**Supported Verticals:**
1. `pharmacy` - Pharmacy/drugstore with batch tracking
2. `restaurant` - Full-service restaurant with table management
3. `coffee_shop` - Quick-service coffee shop/café
4. `retail` - General retail store
5. `fashion` - Fashion/apparel retailer
6. `parapharmacy` - Health/wellness retail without prescriptions

**Default Modules (All IziPOS Verticals):**
- Identity, Tenant, Catalog, Inventory, Sales, Treasury, Accounting, Communication, Media

**Vertical-Specific Defaults:**
| Vertical | Additional Defaults |
|----------|---------------------|
| pharmacy | BatchExpiry |
| restaurant | Menu, Tables |
| coffee_shop | Menu |
| retail | - |
| fashion | - |
| parapharmacy | BatchExpiry |

**Compatible Extras:**
- `Recipe` - Recipe/formula management (pharmacy, parapharmacy)
- `Prescription` - Prescription tracking (pharmacy)
- `Appointments` - Appointment scheduling (all verticals)

---

### Otospex (Automotive Product)

**Target Markets:** Automotive service & parts businesses
**Tagline:** "Built for auto professionals"

**Supported Verticals:**
1. `mechanic` - General automotive repair shop
2. `body_shop` - Body shop/collision repair
3. `parts_retailer` - Auto parts retail store
4. `car_glass` - Auto glass replacement/repair
5. `tire_shop` - Tire sales and service
6. `service_station` - Quick service/oil change

**Default Modules (All Otospex Verticals):**
- Identity, Tenant, Catalog, Vehicle, Inventory, Sales, Treasury, Accounting, Communication, Media

**Vertical-Specific Defaults:**
| Vertical | Additional Defaults |
|----------|---------------------|
| mechanic | Workshop |
| body_shop | Workshop |
| parts_retailer | - |
| car_glass | Workshop |
| tire_shop | Workshop |
| service_station | Workshop |

**Compatible Extras:**
- `Workshop` - Work order management (parts_retailer only - others have it by default)
- `Fleet` - Fleet management (all verticals)
- `Appointments` - Appointment scheduling (all verticals)

---

## Business Verticals

### Complete Vertical Matrix

| Vertical | Product | Default Modules | Compatible Extras |
|----------|---------|-----------------|-------------------|
| **mechanic** | Otospex | Identity, Tenant, Catalog, Vehicle, Workshop, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Fleet |
| **body_shop** | Otospex | Identity, Tenant, Catalog, Vehicle, Workshop, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Fleet |
| **parts_retailer** | Otospex | Identity, Tenant, Catalog, Vehicle, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Fleet, Workshop |
| **car_glass** | Otospex | Identity, Tenant, Catalog, Vehicle, Workshop, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Fleet |
| **tire_shop** | Otospex | Identity, Tenant, Catalog, Vehicle, Workshop, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Fleet |
| **service_station** | Otospex | Identity, Tenant, Catalog, Vehicle, Workshop, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Fleet |
| **pharmacy** | IziPOS | Identity, Tenant, Catalog, BatchExpiry, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Recipe, Prescription |
| **restaurant** | IziPOS | Identity, Tenant, Catalog, Menu, Tables, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments |
| **coffee_shop** | IziPOS | Identity, Tenant, Catalog, Menu, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments |
| **retail** | IziPOS | Identity, Tenant, Catalog, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments |
| **fashion** | IziPOS | Identity, Tenant, Catalog, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments |
| **parapharmacy** | IziPOS | Identity, Tenant, Catalog, BatchExpiry, Inventory, Sales, Treasury, Accounting, Communication, Media | Appointments, Recipe |

### Vertical Attributes

Each vertical configuration includes:

```php
[
    'label' => 'Auto Repair Shop',           // Display name
    'description' => 'Full-service...',       // Description
    'product' => 'otospex',                   // Product assignment
    'default_modules' => [...],               // Always enabled modules
    'compatible_extras' => [...],             // Optional modules
    'databases' => [...],                     // Specialized databases (future)
    'features' => [...],                      // Feature flags (future)
]
```

---

## Service Architecture

The multi-app vertical system is implemented via three singleton services and one DTO:

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Frontend / Controllers                        │
└────────────────────────────────┬────────────────────────────────────┘
                                 │
                                 ▼
                    ┌────────────────────────┐
                    │ CompanyConfigService   │ ◄── Main Entry Point
                    │ (Singleton)            │
                    └────────┬───────────────┘
                             │
                ┌────────────┴─────────────┐
                ▼                          ▼
    ┌──────────────────────┐   ┌─────────────────────────┐
    │ VerticalConfigService│   │ Tenant Model            │
    │ (Singleton)          │   │ (vertical, enabled_extras)│
    └──────────┬───────────┘   └─────────────────────────┘
               │
               ▼
    ┌──────────────────────┐   ┌─────────────────────────┐
    │ config/verticals.php │   │ CompanyConfig DTO       │
    │ (12 verticals)       │   │ (Immutable, readonly)   │
    └──────────────────────┘   └─────────────────────────┘
               │
               ▼
    ┌──────────────────────┐
    │ ProductService       │
    │ (Singleton)          │
    └──────────┬───────────┘
               │
               ▼
    ┌──────────────────────┐
    │ APP_PRODUCT env var  │
    │ (izipos | otospex)   │
    └──────────────────────┘
```

### 1. ProductService

**File:** `app/Services/ProductService.php`
**Purpose:** Detect the current product (IziPOS or Otospex)
**Registered:** Singleton in `AppServiceProvider`

**Key Methods:**
```php
public function current(): Product                  // Get current product enum
public function isIziPOS(): bool                    // Check if IziPOS
public function isOtospex(): bool                   // Check if Otospex
public function name(): string                      // Get product name
public function label(): string                     // Get product label
public function description(): string               // Get product description
public function domains(): array                    // Get product domains
```

**Configuration:**
```php
// config/app.php (add this):
'product' => env('APP_PRODUCT', 'izipos'),

// .env
APP_PRODUCT=otospex
```

**Null Handling:**
If `config('app.product')` returns null or empty string, defaults to `'izipos'`.

---

### 2. VerticalConfigService

**File:** `app/Services/VerticalConfigService.php`
**Purpose:** Access vertical-specific configurations from `config/verticals.php`
**Registered:** Singleton in `AppServiceProvider`

**Key Methods:**
```php
public function getVerticalConfig(Vertical $vertical): array
public function getLabel(Vertical $vertical): string
public function getDescription(Vertical $vertical): string
public function getProduct(Vertical $vertical): string
public function getCompatibleExtras(Vertical $vertical): array
public function getDefaultModules(Vertical $vertical): array
public function getVerticalsForProduct(string $product): array
```

**Example:**
```php
$service = app(VerticalConfigService::class);

$config = $service->getVerticalConfig(Vertical::Mechanic);
// Returns entire config array for 'mechanic' vertical

$label = $service->getLabel(Vertical::Pharmacy);
// Returns: 'Pharmacy/Drugstore'

$iziposVerticals = $service->getVerticalsForProduct('izipos');
// Returns: [Vertical::Pharmacy, Vertical::Restaurant, ...]
```

---

### 3. CompanyConfigService

**File:** `app/Services/CompanyConfigService.php`
**Purpose:** Integrate tenant's vertical data with vertical configuration
**Registered:** Singleton in `AppServiceProvider`

**Key Method:**
```php
public function getConfigForTenant(Tenant $tenant): CompanyConfig
```

**Responsibilities:**
1. Read `tenant.vertical` and convert to `Vertical` enum
2. Get vertical's default modules from `VerticalConfigService`
3. Decode `tenant.enabled_extras` (handles null, empty, array, JSON string)
4. Merge default modules + enabled extras into `allEnabledModules`
5. Return immutable `CompanyConfig` DTO

**Example:**
```php
$service = app(CompanyConfigService::class);
$tenant = Tenant::find($tenantId);

$config = $service->getConfigForTenant($tenant);

// Access properties
$config->vertical;              // Vertical enum
$config->defaultModules;        // Array of default module names
$config->enabledExtras;         // Array of enabled extra module names
$config->allEnabledModules;     // Combined array (defaults + extras)

// Check if module is enabled
$config->hasModule('Vehicle');  // true/false

// Serialize to JSON
json_encode($config);           // Uses JsonSerializable
```

---

### 4. CompanyConfig DTO

**File:** `app/DTOs/CompanyConfig.php`
**Purpose:** Immutable data transfer object for company configuration

**Properties (readonly):**
```php
public readonly Vertical $vertical;
public readonly array $defaultModules;
public readonly array $enabledExtras;
public readonly array $allEnabledModules;
```

**Methods:**
```php
public static function fromArray(array $data): self
public function hasModule(string $module): bool
public function toArray(): array
public function jsonSerialize(): mixed  // JsonSerializable interface
```

**Features:**
- **Immutable:** All properties are `readonly` (PHP 8.1+)
- **Type-Safe:** Uses `Vertical` enum, not string
- **Serializable:** Implements `JsonSerializable` for JSON API responses
- **Testable:** Factory method `fromArray()` for easy test data creation

---

## Configuration Structure

### config/products.php

```php
return [
    'izipos' => [
        'name' => 'IziPOS',
        'label' => 'IziPOS - Point of Sale',
        'description' => 'Modern point-of-sale system for retail and hospitality',
        'domains' => ['app.izipos.com'],
        'features' => [],
    ],
    'otospex' => [
        'name' => 'Otospex',
        'label' => 'Otospex - Automotive ERP',
        'description' => 'Complete management solution for automotive businesses',
        'domains' => ['app.otospex.com'],
        'features' => [],
    ],
];
```

### config/verticals.php

**Structure for Each Vertical:**
```php
'mechanic' => [
    'label' => 'Auto Repair Shop',
    'description' => 'Full-service automotive repair and maintenance',
    'product' => 'otospex',
    'default_modules' => [
        'Identity',
        'Tenant',
        'Catalog',
        'Vehicle',
        'Workshop',
        'Inventory',
        'Sales',
        'Treasury',
        'Accounting',
        'Communication',
        'Media',
    ],
    'compatible_extras' => [
        'Appointments',
        'Fleet',
    ],
    'databases' => [],      // Future: specialized databases
    'features' => [],       // Future: feature flags
],
```

**All 12 Verticals Configured:**
- mechanic, body_shop, parts_retailer, car_glass, tire_shop, service_station
- pharmacy, restaurant, coffee_shop, retail, fashion, parapharmacy

---

## Module Resolution

### Resolution Logic

Given a `Tenant` with:
- `vertical = 'mechanic'`
- `enabled_extras = ['Appointments', 'Fleet']`

**Step-by-step resolution:**

1. **Vertical Detection**
   ```php
   $vertical = Vertical::from($tenant->vertical);  // Vertical::Mechanic
   ```

2. **Get Default Modules**
   ```php
   $defaultModules = $verticalConfigService->getDefaultModules($vertical);
   // ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Workshop', 'Inventory', ...]
   ```

3. **Decode Enabled Extras**
   ```php
   $enabledExtras = $this->decodeExtras($tenant->enabled_extras);
   // ['Appointments', 'Fleet']
   ```

4. **Merge & Deduplicate**
   ```php
   $allEnabledModules = array_unique(array_merge($defaultModules, $enabledExtras));
   // ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Workshop', 'Inventory', ..., 'Appointments', 'Fleet']
   ```

5. **Return DTO**
   ```php
   return CompanyConfig::fromArray([
       'vertical' => $vertical,
       'default_modules' => $defaultModules,
       'enabled_extras' => $enabledExtras,
       'all_enabled_modules' => $allEnabledModules,
   ]);
   ```

### Extras Decoding Logic

The `CompanyConfigService::decodeExtras()` method handles multiple formats:

| Input | Output |
|-------|--------|
| `null` | `[]` |
| `''` (empty string) | `[]` |
| `['Appointments', 'Fleet']` (array) | `['Appointments', 'Fleet']` |
| `'["Appointments","Fleet"]'` (JSON string) | `['Appointments', 'Fleet']` |

**Implementation:**
```php
private function decodeExtras(string|array|null $extras): array
{
    if ($extras === null || $extras === '') {
        return [];
    }

    if (is_array($extras)) {
        return $extras;
    }

    $decoded = json_decode($extras, true);
    return is_array($decoded) ? $decoded : [];
}
```

---

## Database Schema

### Tenants Table

**Migration:** `2025_12_30_115627_add_vertical_to_tenants_table.php`

**Columns:**
```sql
vertical            VARCHAR(50)  NOT NULL DEFAULT 'retail'
enabled_extras      JSONB        NULLABLE DEFAULT '[]'
signup_source       VARCHAR(100) NULLABLE
signup_tracking     JSONB        NULLABLE

INDEX idx_tenants_vertical ON tenants(vertical)
```

**Key Design Decisions:**
1. `vertical` is NOT NULL with default `'retail'` (safest generic vertical)
2. `enabled_extras` is NULLABLE with default `'[]'` (handles both null and empty array)
3. Index on `vertical` for fast filtering by business type
4. JSONB for flexible array storage (PostgreSQL)

### Tenant Model Updates

**File:** `app/Modules/Tenant/Domain/Tenant.php`

**Added to `$fillable`:**
```php
'vertical',
'enabled_extras',
'signup_source',
'signup_tracking',
```

**Added to `casts()`:**
```php
'enabled_extras' => 'array',
'signup_tracking' => 'array',
```

**Added to `getCustomColumns()`:**
```php
'vertical',
'enabled_extras',
'signup_source',
'signup_tracking',
```

**Added to PHPDoc:**
```php
/**
 * @property string $vertical Business vertical (mechanic, pharmacy, etc.)
 * @property array<int, string>|null $enabled_extras Enabled optional modules
 * @property string|null $signup_source Signup attribution source
 * @property array<string, mixed>|null $signup_tracking Signup tracking metadata
 */
```

---

## Usage Examples

### Backend Example: Controller

```php
use App\Services\CompanyConfigService;
use App\Modules\Tenant\Domain\Tenant;

class CompanyConfigController extends Controller
{
    public function __construct(
        private readonly CompanyConfigService $configService
    ) {}

    public function show(Request $request)
    {
        $tenant = $request->user()->tenant;
        $config = $this->configService->getConfigForTenant($tenant);

        return response()->json([
            'data' => $config,  // Automatically serialized via JsonSerializable
        ]);
    }
}
```

### Backend Example: Middleware Check

```php
use App\Services\CompanyConfigService;

class RequireModule
{
    public function __construct(
        private readonly CompanyConfigService $configService
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = $request->user()->tenant;
        $config = $this->configService->getConfigForTenant($tenant);

        if (!$config->hasModule($module)) {
            abort(403, "Module '{$module}' is not enabled for your business type");
        }

        return $next($request);
    }
}
```

### Frontend Example: React Context

```typescript
import { CompanyConfig } from '@/types/company'

interface CompanyConfigContextValue {
  config: CompanyConfig | null
  hasModule: (module: string) => boolean
  isLoading: boolean
}

export function useCompanyConfig(): CompanyConfigContextValue {
  const { data: config, isLoading } = useQuery({
    queryKey: ['company-config'],
    queryFn: () => apiGet<CompanyConfig>('/company/config'),
  })

  const hasModule = (module: string) => {
    return config?.all_enabled_modules.includes(module) ?? false
  }

  return { config, hasModule, isLoading }
}
```

### Frontend Example: Dynamic Navigation

```typescript
function Sidebar() {
  const { hasModule } = useCompanyConfig()

  return (
    <nav>
      {hasModule('Vehicle') && (
        <NavLink to="/vehicles">Vehicles</NavLink>
      )}

      {hasModule('Workshop') && (
        <NavLink to="/work-orders">Work Orders</NavLink>
      )}

      {hasModule('BatchExpiry') && (
        <NavLink to="/batch-expiry">Batch Tracking</NavLink>
      )}
    </nav>
  )
}
```

### Frontend Example: Route Guard

```typescript
interface ModuleGuardProps {
  module: string
  fallback?: string
  children: React.ReactNode
}

function ModuleGuard({ module, fallback = '/', children }: ModuleGuardProps) {
  const { hasModule, isLoading } = useCompanyConfig()

  if (isLoading) {
    return <LoadingSpinner />
  }

  if (!hasModule(module)) {
    return <Navigate to={fallback} replace />
  }

  return <>{children}</>
}

// Usage in routes
<Route
  path="/work-orders"
  element={
    <ModuleGuard module="Workshop">
      <WorkOrderListPage />
    </ModuleGuard>
  }
/>
```

---

## Testing Guidelines

### Unit Testing Services

**Location:** `tests/Unit/Services/`

**Test Coverage Requirements:**
- ProductService: Test current product detection, null handling, helper methods
- VerticalConfigService: Test config access, filtering by product
- CompanyConfigService: Test tenant integration, extras decoding (null, empty, array, JSON)

**Example Test:**
```php
use App\Services\CompanyConfigService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CompanyConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompanyConfigService::class);
    }

    public function test_get_config_merges_default_modules_and_extras(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode(['Appointments', 'Fleet']),
        ]);

        $config = $this->service->getConfigForTenant($tenant);

        // Check defaults
        $this->assertContains('Vehicle', $config->defaultModules);
        $this->assertContains('Workshop', $config->defaultModules);

        // Check extras
        $this->assertEquals(['Appointments', 'Fleet'], $config->enabledExtras);

        // Check merged
        $this->assertContains('Vehicle', $config->allEnabledModules);
        $this->assertContains('Appointments', $config->allEnabledModules);
    }
}
```

### Integration Testing

**Location:** `tests/Feature/`

**Test Scenarios:**
- API endpoint returns correct config for tenant
- Middleware blocks access when module not enabled
- Frontend receives proper config structure
- Config updates invalidate cache

### Running Milestone 3 Tests

```bash
# Run all Milestone 3 tests
php artisan test --filter="ProductServiceTest|VerticalConfigServiceTest|CompanyConfigTest|CompanyConfigServiceTest"

# Expected output:
# Tests:  36 passed (101 assertions)
```

### PHPStan Verification

```bash
# Check type safety for Milestone 3 files
./vendor/bin/phpstan analyse --memory-limit=2G \
  app/Services/ProductService.php \
  app/Services/VerticalConfigService.php \
  app/Services/CompanyConfigService.php \
  app/DTOs/CompanyConfig.php \
  tests/Unit/Services/ \
  tests/Unit/DTOs/

# Expected output:
# [OK] No errors
```

---

## API Reference

### ProductService API

| Method | Return Type | Description |
|--------|-------------|-------------|
| `current()` | `Product` | Get current product enum (IziPOS or Otospex) |
| `isIziPOS()` | `bool` | Check if current product is IziPOS |
| `isOtospex()` | `bool` | Check if current product is Otospex |
| `name()` | `string` | Get product name (e.g., 'IziPOS') |
| `label()` | `string` | Get product label (e.g., 'IziPOS - Point of Sale') |
| `description()` | `string` | Get product description |
| `domains()` | `array` | Get product domains (e.g., ['app.izipos.com']) |

### VerticalConfigService API

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getVerticalConfig(Vertical $vertical)` | `array` | Get entire config array for vertical |
| `getLabel(Vertical $vertical)` | `string` | Get vertical label (e.g., 'Auto Repair Shop') |
| `getDescription(Vertical $vertical)` | `string` | Get vertical description |
| `getProduct(Vertical $vertical)` | `string` | Get product name for vertical |
| `getCompatibleExtras(Vertical $vertical)` | `array` | Get compatible extras for vertical |
| `getDefaultModules(Vertical $vertical)` | `array` | Get default modules for vertical |
| `getVerticalsForProduct(string $product)` | `array` | Get all verticals for a product |

### CompanyConfigService API

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getConfigForTenant(Tenant $tenant)` | `CompanyConfig` | Get complete config for tenant |

### CompanyConfig DTO API

| Method | Return Type | Description |
|--------|-------------|-------------|
| `fromArray(array $data)` | `CompanyConfig` | Factory method to create from array |
| `hasModule(string $module)` | `bool` | Check if module is enabled |
| `toArray()` | `array` | Convert to array representation |
| `jsonSerialize()` | `mixed` | Serialize to JSON (automatic in JSON responses) |

---

## Future Enhancements

### Phase 2: Specialized Databases

Some verticals may require specialized databases:

```php
'pharmacy' => [
    // ... existing config
    'databases' => [
        'drug_interactions' => true,  // Enable drug interaction checking
        'prescription_tracking' => true,
    ],
],
```

### Phase 3: Feature Flags

Per-vertical feature flags:

```php
'restaurant' => [
    // ... existing config
    'features' => [
        'kitchen_display_system' => true,
        'online_ordering' => true,
        'table_reservations' => true,
    ],
],
```

### Phase 4: POS Variants

Specialized POS interfaces per vertical:

```php
'restaurant' => [
    // ... existing config
    'pos_variant' => 'RestaurantPOS',  // Uses table layout, kitchen tickets
],
'pharmacy' => [
    // ... existing config
    'pos_variant' => 'PharmacyPOS',    // Uses prescription lookup, dosage warnings
],
```

### Phase 5: Module Dependencies

Automatic dependency resolution:

```php
// config/modules.php
'dependencies' => [
    'Recipe' => ['Product', 'Inventory'],
    'Workshop' => ['Vehicle', 'Inventory'],
    'Fleet' => ['Vehicle', 'Customer'],
],
```

---

## Troubleshooting

### Common Issues

**Issue:** `RuntimeException: Configuration not found for vertical: xyz`
**Cause:** Invalid vertical enum value
**Fix:** Ensure `tenant.vertical` contains a valid vertical from `config/verticals.php`

**Issue:** Module shows in navigation but API returns 403
**Cause:** Frontend has stale config
**Fix:** Invalidate `company-config` query cache after changing `enabled_extras`

**Issue:** Tests fail with "no such table: tenants"
**Cause:** Missing `RefreshDatabase` trait
**Fix:** Add `use RefreshDatabase;` to test class

**Issue:** PHPStan error "has parameter with no value type specified"
**Cause:** Missing `@param` type for array parameters
**Fix:** Add PHPDoc with `@param array<int, string>` type

---

## Migration Guide

### For Existing Tenants

All existing tenants will be assigned `vertical = 'retail'` by default (safest generic vertical).

**Post-Migration Steps:**
1. Update existing tenants to correct vertical:
   ```php
   Tenant::where('slug', 'some-garage')->update(['vertical' => 'mechanic']);
   ```

2. Enable extras for tenants that need them:
   ```php
   $tenant = Tenant::find($id);
   $tenant->enabled_extras = ['Appointments', 'Fleet'];
   $tenant->save();
   ```

3. Verify config is correct:
   ```php
   $config = app(CompanyConfigService::class)->getConfigForTenant($tenant);
   dd($config->allEnabledModules);
   ```

---

## Related Documentation

- [CLAUDE.md](../../CLAUDE.md) - Master architecture document
- [config/products.php](../../apps/api/config/products.php) - Product configurations
- [config/verticals.php](../../apps/api/config/verticals.php) - Vertical configurations
- [app/Enums/Product.php](../../apps/api/app/Enums/Product.php) - Product enum
- [app/Enums/Vertical.php](../../apps/api/app/Enums/Vertical.php) - Vertical enum

---

**Document Status:** Production Ready (Milestone 3 Complete)
**Last Verified:** December 30, 2025 (Opus 4.5 Verification Passed)
**Next Milestone:** Milestone 4 - Security Middleware (RequireModule)
