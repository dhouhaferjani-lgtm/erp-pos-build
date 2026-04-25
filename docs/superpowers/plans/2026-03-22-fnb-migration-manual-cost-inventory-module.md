# F&B Migration: Manual Cost, Inventory Module & Import Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable F&B client migration by fixing import column mapping, adding `manual_cost` to composite items, gating Inventory as a paid module, and providing admin module management.

**Architecture:** Hexagonal (Domain/Application/Infrastructure/Presentation per module). Changes span Import, Catalog, Product, Inventory, and Admin modules. TDD required — write failing test first, then implement. All frontend text via `t()` i18n keys.

**Tech Stack:** Laravel 12 / PHP 8.2+ (strict types), PostgreSQL 16, React 19 / TypeScript strict / TanStack Query 5, Vitest, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-03-21-fnb-migration-manual-cost-inventory-module.md`

**Conventions:** Read `docs/conventions/README.md` before starting. Key rules: constructor injection only, no `mixed`/`any`, enums for all status columns, `apiGet`/`apiPost` already unwrap `response.data.data`.

---

## Stream 1: Import Fixes

### Task 1: Fix Column Mapping — Frontend Sends Mapping to Backend

**Files:**
- Modify: `apps/web/src/features/import/api/importApi.ts:30-45`
- Modify: `apps/web/src/features/import/pages/ImportWizardPage.tsx:250-266` (the `handleMappingComplete` callback)

- [ ] **Step 1: Fix `importApi.ts` — send column_mapping in FormData**

In `apps/web/src/features/import/api/importApi.ts`, update `createJob()`:

```typescript
createJob: async (
  type: ImportType,
  file: File,
  columnMapping?: Record<string, string>
): Promise<CreateImportResponse> => {
  const formData = new FormData()
  formData.append('type', type)
  formData.append('file', file)
  if (columnMapping && Object.keys(columnMapping).length > 0) {
    formData.append('column_mapping', JSON.stringify(columnMapping))
  }

  const response = await api.post<CreateImportResponse>(IMPORT_URL, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120000,
  })
  return response.data
},
```

Key changes: remove underscore prefix from `_columnMapping`, append as JSON string.

- [ ] **Step 2: Fix `ImportWizardPage.tsx` — pass columnMapping to createJob**

In the `handleMappingComplete` callback, the `createImport.mutate` call already passes `columnMapping`. Verify the `useCreateImport` hook passes it through to `importApi.createJob()`. Check `apps/web/src/features/import/api/queries.ts` — the `useCreateImport` mutation should pass `columnMapping` to `importApi.createJob(data.type, data.file, data.columnMapping)`.

- [ ] **Step 3: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

---

### Task 2: Fix Column Mapping — Backend Accepts and Applies Mapping

**Files:**
- Modify: `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:71-169`
- Modify: `apps/api/app/Modules/Import/Services/ImportService.php` (add `applyColumnMapping` method)
- Test: `apps/api/tests/Feature/Import/ColumnMappingTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Feature/Import/ColumnMappingTest.php`. Follow the test setup pattern from `ImportTypesTest.php` (tenant, company, user with admin role, company membership).

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ColumnMappingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'column-mapping-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'mapping@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_apply_column_mapping_renames_row_keys(): void
    {
        $importService = app(ImportService::class);

        $rows = [
            ['Code' => 'P001', 'Price' => '10.00', 'Product Name' => 'Widget'],
        ];
        $mapping = [
            'Code' => 'sku',
            'Price' => 'sale_price',
            'Product Name' => 'name',
        ];

        $result = $importService->applyColumnMapping($rows, $mapping);

        $this->assertCount(1, $result);
        $this->assertEquals('P001', $result[0]['sku']);
        $this->assertEquals('10.00', $result[0]['sale_price']);
        $this->assertEquals('Widget', $result[0]['name']);
        $this->assertArrayNotHasKey('Code', $result[0]);
        $this->assertArrayNotHasKey('Price', $result[0]);
    }

    public function test_apply_column_mapping_drops_unmapped_columns(): void
    {
        $importService = app(ImportService::class);

        $rows = [
            ['sku' => 'P001', 'extra_field' => 'ignore', 'name' => 'Widget'],
        ];
        $mapping = [
            'sku' => 'sku',
            'name' => 'name',
        ];

        $result = $importService->applyColumnMapping($rows, $mapping);

        $this->assertArrayNotHasKey('extra_field', $result[0]);
        $this->assertCount(2, $result[0]); // only sku and name
    }

    public function test_null_mapping_returns_rows_unchanged(): void
    {
        $importService = app(ImportService::class);

        $rows = [
            ['sku' => 'P001', 'name' => 'Widget'],
        ];

        $result = $importService->applyColumnMapping($rows, null);

        $this->assertEquals($rows, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=ColumnMappingTest`
Expected: FAIL — `applyColumnMapping` method does not exist.

- [ ] **Step 3: Implement `applyColumnMapping` in ImportService**

In `apps/api/app/Modules/Import/Services/ImportService.php`, add:

```php
/**
 * Apply column mapping to rows — rename source column names to target names.
 * Unmapped columns are dropped.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @param  array<string, string>|null  $mapping  Source → target column name map
 * @return array<int, array<string, mixed>>
 */
public function applyColumnMapping(array $rows, ?array $mapping): array
{
    if ($mapping === null || $mapping === []) {
        return $rows;
    }

    return array_map(static function (array $row) use ($mapping): array {
        $mapped = [];
        foreach ($mapping as $source => $target) {
            if (array_key_exists($source, $row)) {
                $mapped[$target] = $row[$source];
            }
        }

        return $mapped;
    }, $rows);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && php artisan test --filter=ColumnMappingTest`
Expected: All 3 tests PASS.

- [ ] **Step 5: Wire mapping into ImportController**

In `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php` `store()` method:

1. After `$type = ImportType::from(...)` (line 95), add:
```php
$columnMapping = $request->has('column_mapping')
    ? json_decode($request->input('column_mapping'), true)
    : null;
```

2. After `$parseResult = $this->spreadsheetParser->parse($fullPath)` (line 138), add:
```php
// Apply column mapping: rename source headers to target names
$mappedRows = $this->importService->applyColumnMapping($parseResult['rows'], $columnMapping);
$mappedHeaders = $columnMapping !== null
    ? array_values($columnMapping)
    : $parseResult['headers'];
```

3. Update `addRowsBatch` call (line 141) to use `$mappedRows` instead of `$parseResult['rows']`.

4. Update `validateHeaders` call (line 144) to use `$mappedHeaders` instead of `$parseResult['headers']`.

5. Pass `$columnMapping` to `createJob` (line 127). **Note:** `ImportService::createJob()` already accepts `?array $columnMapping = null` as its last parameter — just add the named argument:
```php
$job = $this->importService->createJob(
    tenantId: $tenantId,
    userId: $user->id,
    type: $type,
    filename: $file->getClientOriginalName(),
    filePath: $path,
    totalRows: 0,
    columnMapping: $columnMapping
);
```

- [ ] **Step 6: Run PHPStan on modified files**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Import/Presentation/Controllers/ImportController.php app/Modules/Import/Services/ImportService.php --level=8`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/tests/Feature/Import/ColumnMappingTest.php apps/api/app/Modules/Import/Services/ImportService.php apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php apps/web/src/features/import/api/importApi.ts
git commit -m "fix(import): send and apply column mapping in import pipeline"
```

---

### Task 3: Remove stock_levels Card from Dashboard & Update Templates

**Files:**
- Modify: `apps/web/src/features/import/pages/ImportDashboardPage.tsx`
- Modify: `apps/web/src/features/import/components/ImportTypeCard.tsx`
- Modify: `apps/api/app/Modules/Import/Services/MigrationWizardService.php`

- [ ] **Step 1: Remove stock_levels from ImportDashboardPage IMPORT_TYPES**

In `apps/web/src/features/import/pages/ImportDashboardPage.tsx`, remove the stock_levels entry from the `IMPORT_TYPES` array. Keep the other 5 types: partners, products, product_images, composite_items, opening_balances.

Also remove the `Warehouse` import from lucide-react since it's no longer used in this file.

In `apps/web/src/features/import/components/ImportTypeCard.tsx`, remove `stock_levels: Warehouse` from the `typeIcons` map and the `Warehouse` import from lucide-react.

- [ ] **Step 2: Update product template example rows**

In `apps/api/app/Modules/Import/Services/MigrationWizardService.php`, find `generateExampleRows()` Products case (around line 236). Update the example rows to include values for the new fields:

```php
self::Products => [
    [
        'name' => 'Brake Pad Set',
        'sku' => 'BRK-001',
        'type' => 'part',
        'description' => 'Front brake pad set',
        'sale_price' => '45.99',
        'purchase_price' => '22.50',
        'barcode' => '3760012345678',
        'category_name' => 'Brake Parts',
        'tax_rate' => '19',
        'unit' => 'piece',
        'is_active' => 'true',
    ],
    [
        'name' => 'Oil Change Service',
        'sku' => 'SVC-001',
        'type' => 'service',
        'description' => 'Standard oil change',
        'sale_price' => '35.00',
        'purchase_price' => '',
        'barcode' => '',
        'category_name' => 'Services',
        'tax_rate' => '19',
        'unit' => '',
        'is_active' => 'true',
    ],
],
```

- [ ] **Step 3: Verify frontend compiles and backend passes PHPStan**

Run: `cd apps/web && pnpm typecheck`
Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Import/Services/MigrationWizardService.php --level=8`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/features/import/pages/ImportDashboardPage.tsx apps/api/app/Modules/Import/Services/MigrationWizardService.php
git commit -m "fix(import): remove stock_levels card, update product template examples"
```

---

## Stream 2: `manual_cost` on Composite Items

### Task 4: Database Migration for `manual_cost`

**Files:**
- Create: `apps/api/database/migrations/YYYY_MM_DD_HHMMSS_add_manual_cost_to_composite_items_table.php`

- [ ] **Step 1: Create migration**

Run: `cd apps/api && php artisan make:migration add_manual_cost_to_composite_items_table`

Edit the generated file:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composite_items', function (Blueprint $table): void {
            $table->decimal('manual_cost', 15, 4)->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('composite_items', function (Blueprint $table): void {
            $table->dropColumn('manual_cost');
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `cd apps/api && php artisan migrate`
Expected: Migration runs successfully.

- [ ] **Step 3: Commit**

```bash
git add apps/api/database/migrations/*_add_manual_cost_to_composite_items_table.php
git commit -m "feat(catalog): add manual_cost column to composite_items table"
```

---

### Task 5: Domain Model — `manual_cost`, `getEffectiveCost()`, `getMarginPercentage()`

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Domain/Entities/CompositeItem.php`
- Test: `apps/api/tests/Unit/Catalog/CompositeItemCostTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `apps/api/tests/Unit/Catalog/CompositeItemCostTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use Tests\TestCase;

class CompositeItemCostTest extends TestCase
{
    public function test_effective_cost_returns_null_when_no_cost_data(): void
    {
        $item = new CompositeItem();
        $item->manual_cost = null;

        $this->assertNull($item->getEffectiveCost());
    }

    public function test_effective_cost_returns_manual_cost_when_no_recipe(): void
    {
        $item = new CompositeItem();
        $item->manual_cost = '1.5000';

        $this->assertEquals('1.5000', $item->getEffectiveCost());
    }

    public function test_effective_cost_returns_recipe_cost_when_recipe_exists(): void
    {
        $recipe = new Recipe();
        $recipe->calculated_cost = '2.3000';

        $item = new CompositeItem();
        $item->manual_cost = '1.5000';
        $item->setRelation('activeRecipe', $recipe);

        $this->assertEquals('2.3000', $item->getEffectiveCost());
    }

    public function test_effective_cost_returns_zero_recipe_cost_without_fallback(): void
    {
        $recipe = new Recipe();
        $recipe->calculated_cost = '0.0000';

        $item = new CompositeItem();
        $item->manual_cost = '1.5000';
        $item->setRelation('activeRecipe', $recipe);

        $this->assertEquals('0.0000', $item->getEffectiveCost());
    }

    public function test_effective_cost_falls_back_when_recipe_cost_is_null(): void
    {
        $recipe = new Recipe();
        $recipe->calculated_cost = null;

        $item = new CompositeItem();
        $item->manual_cost = '1.5000';
        $item->setRelation('activeRecipe', $recipe);

        $this->assertEquals('1.5000', $item->getEffectiveCost());
    }

    public function test_margin_percentage_calculated_correctly(): void
    {
        $item = new CompositeItem();
        $item->base_price = '4.5000';
        $item->manual_cost = '1.2000';

        $this->assertEquals(73.33, $item->getMarginPercentage());
    }

    public function test_margin_percentage_returns_null_when_no_cost(): void
    {
        $item = new CompositeItem();
        $item->base_price = '4.5000';
        $item->manual_cost = null;

        $this->assertNull($item->getMarginPercentage());
    }

    public function test_margin_percentage_returns_null_when_base_price_is_zero(): void
    {
        $item = new CompositeItem();
        $item->base_price = '0.0000';
        $item->manual_cost = '1.2000';

        $this->assertNull($item->getMarginPercentage());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && php artisan test --filter=CompositeItemCostTest`
Expected: FAIL — methods do not exist.

- [ ] **Step 3: Implement on CompositeItem model**

In `apps/api/app/Modules/Catalog/Domain/Entities/CompositeItem.php`:

1. Add `'manual_cost'` to `$fillable` array (after `'base_price'`)

2. Add to `casts()` method: `'manual_cost' => 'decimal:4'`

3. Add the `@property` docblock: `@property string|null $manual_cost`

4. Add methods:

```php
/**
 * Get the effective cost for this composite item.
 * Recipe calculated cost takes precedence over manual cost.
 * A recipe with null calculated_cost means "never calculated" — falls back to manual.
 * A recipe with zero calculated_cost is a valid result (ingredients have no cost yet).
 */
public function getEffectiveCost(): ?string
{
    $recipeCost = $this->activeRecipe?->calculated_cost;

    if ($recipeCost !== null) {
        return $recipeCost;
    }

    return $this->manual_cost;
}

/**
 * Get the profit margin percentage based on effective cost and base_price.
 * Returns null if no cost data is available or base_price is zero.
 */
public function getMarginPercentage(): ?float
{
    $cost = $this->getEffectiveCost();

    if ($cost === null || $this->base_price === null || (float) $this->base_price === 0.0) {
        return null;
    }

    return round(((float) $this->base_price - (float) $cost) / (float) $this->base_price * 100, 2);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=CompositeItemCostTest`
Expected: All 8 tests PASS.

- [ ] **Step 5: PHPStan check**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog/Domain/Entities/CompositeItem.php --level=8`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add apps/api/tests/Unit/Catalog/CompositeItemCostTest.php apps/api/app/Modules/Catalog/Domain/Entities/CompositeItem.php
git commit -m "feat(catalog): add manual_cost field with effective cost resolution to CompositeItem"
```

---

### Task 6: Application & Presentation Layer — DTO, Resource, Request Validation

**Files:**
- Modify: Composite item resource/transformer (find via `CompositeItemResource` or the controller that returns composite items)
- Modify: Composite item store/update request
- Modify: `apps/api/app/Modules/Catalog/Application/Services/CompositeItemImportService.php`
- Modify: `apps/api/app/Modules/Import/Domain/Enums/ImportType.php`

Before starting, find the exact files:
- Run: `grep -r "CompositeItemResource\|CompositeItemData" apps/api/app/Modules/Catalog --include="*.php" -l` to find the resource/DTO
- Run: `grep -r "StoreCompositeItem\|UpdateCompositeItem\|CompositeItemRequest" apps/api/app/Modules/Catalog --include="*.php" -l` to find the request validation

- [ ] **Step 1: Update the composite item resource/DTO**

Add these fields to the API response:
- `manual_cost` — from `$compositeItem->manual_cost`
- `effective_cost` — from `$compositeItem->getEffectiveCost()`
- `recipe_cost` — from `$compositeItem->activeRecipe?->calculated_cost`
- `margin_percentage` — from `$compositeItem->getMarginPercentage()`

- [ ] **Step 2: Update store/update request validation**

Add `'manual_cost' => ['nullable', 'numeric', 'min:0']` to the validation rules.

- [ ] **Step 3: Update CompositeItemImportService**

In `apps/api/app/Modules/Catalog/Application/Services/CompositeItemImportService.php`, in the `upsert()` method, add `manual_cost` handling (same pattern as `tax_rate`):

```php
if (isset($data['manual_cost']) && $data['manual_cost'] !== '') {
    $attributes['manual_cost'] = $data['manual_cost'];
}
```

- [ ] **Step 4: Update ImportType enum**

In `apps/api/app/Modules/Import/Domain/Enums/ImportType.php`:

Add `'manual_cost'` to CompositeItems `getOptionalColumns()` array.

Add to CompositeItems `getValidationRules()`:
```php
'manual_cost' => ['nullable', 'numeric', 'min:0'],
```

- [ ] **Step 5: Update composite items template example rows**

In `apps/api/app/Modules/Import/Services/MigrationWizardService.php`, find the CompositeItems example rows (around line 286) and add `'manual_cost' => '1.20'` to the first example and `'manual_cost' => '3.50'` to the second.

- [ ] **Step 6: PHPStan check on all modified files**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog/ app/Modules/Import/Domain/Enums/ImportType.php --level=8`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Catalog/ apps/api/app/Modules/Import/Domain/Enums/ImportType.php apps/api/app/Modules/Import/Services/MigrationWizardService.php
git commit -m "feat(catalog): wire manual_cost through DTO, resource, import, and validation"
```

---

### Task 7: Frontend — Composite Item Form `manual_cost` Field

**Files:**
- Modify: `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx`
- Modify: `apps/web/src/features/import/pages/ImportWizardPage.tsx`
- Modify: `apps/web/src/locales/en/catalog.json` (or equivalent i18n file for composite items)
- Modify: `apps/web/src/locales/fr/catalog.json`

- [ ] **Step 1: Add `manual_cost` to composite item form state and UI**

In `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx`:

1. Add `manual_cost: ''` to initial form state
2. Add input field after `base_price`:

```tsx
{/* Cost */}
<div>
  <label className="block text-sm font-medium text-gray-700">
    {t('compositeItems.fields.manualCost')}
  </label>
  <input
    type="number"
    step="0.01"
    min="0"
    value={form.manual_cost}
    onChange={(e) => setForm({ ...form, manual_cost: e.target.value })}
    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
    placeholder={t('compositeItems.fields.manualCostPlaceholder')}
  />
</div>
```

3. When the API response includes `recipe_cost` and it's not null, show both side-by-side:

```tsx
{data?.recipe_cost && (
  <div className="rounded-md bg-blue-50 p-3 text-sm">
    <div className="flex justify-between">
      <span className="text-blue-700">{t('compositeItems.fields.recipeCost')}</span>
      <span className="font-medium text-blue-900">{data.recipe_cost}</span>
    </div>
    <div className="flex justify-between mt-1">
      <span className="text-blue-700">{t('compositeItems.fields.margin')}</span>
      <span className="font-medium text-blue-900">{data.margin_percentage}%</span>
    </div>
  </div>
)}
```

4. When recipe_cost is null but manual_cost has a value, show margin from manual_cost:

```tsx
{!data?.recipe_cost && form.manual_cost && parseFloat(form.base_price) > 0 && (
  <p className="text-sm text-gray-500">
    {t('compositeItems.fields.margin')}: {(((parseFloat(form.base_price) - parseFloat(form.manual_cost)) / parseFloat(form.base_price)) * 100).toFixed(1)}%
  </p>
)}
```

- [ ] **Step 2: Add `manual_cost` to import wizard TARGET_COLUMNS**

In `apps/web/src/features/import/pages/ImportWizardPage.tsx`, add to `composite_items` TARGET_COLUMNS:

```typescript
{ name: 'manual_cost', required: false, description: 'Estimated cost per unit' },
```

- [ ] **Step 3: Add i18n keys**

Add to English and French catalog translation files:
```json
"manualCost": "Cost",
"manualCostPlaceholder": "e.g., 1.20",
"recipeCost": "Recipe Cost",
"margin": "Margin"
```

- [ ] **Step 4: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/catalog/ apps/web/src/features/import/pages/ImportWizardPage.tsx apps/web/src/locales/
git commit -m "feat(catalog): add manual_cost field to composite item form and import wizard"
```

---

## Stream 3: Inventory as Paid Module

### Task 8: Vertical Config Change + Existing Tenant Data Migration

**Files:**
- Modify: `apps/api/config/verticals.php`
- Create: `apps/api/database/migrations/YYYY_MM_DD_HHMMSS_backfill_inventory_in_fnb_tenant_extras.php`
- Test: `apps/api/tests/Feature/Catalog/InventoryModuleGatingTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Feature/Catalog/InventoryModuleGatingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\Vertical;
use App\Services\CompanyConfigService;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryModuleGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_coffee_shop_without_extras_does_not_have_inventory(): void
    {
        $tenant = Tenant::create([
            'name' => 'Coffee Shop',
            'slug' => 'coffee-no-inv',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => [],
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Coffee',
            'legal_name' => 'Test Coffee LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        $configService = app(CompanyConfigService::class);
        $config = $configService->getConfigForTenant($tenant);

        $this->assertNotContains('Inventory', $config->default_modules);
        $this->assertContains('Inventory', $config->compatible_extras);
        $this->assertNotContains('Inventory', $config->all_enabled_modules);
    }

    public function test_coffee_shop_with_inventory_extra_has_inventory(): void
    {
        $tenant = Tenant::create([
            'name' => 'Coffee Shop Pro',
            'slug' => 'coffee-with-inv',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pro Coffee',
            'legal_name' => 'Pro Coffee LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        $configService = app(CompanyConfigService::class);
        $config = $configService->getConfigForTenant($tenant);

        $this->assertContains('Inventory', $config->all_enabled_modules);
    }

    public function test_restaurant_without_extras_does_not_have_inventory(): void
    {
        $tenant = Tenant::create([
            'name' => 'Restaurant',
            'slug' => 'restaurant-no-inv',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Restaurant,
            'enabled_extras' => [],
        ]);

        $configService = app(CompanyConfigService::class);
        $config = $configService->getConfigForTenant($tenant);

        $this->assertNotContains('Inventory', $config->default_modules);
        $this->assertContains('Inventory', $config->compatible_extras);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && php artisan test --filter=InventoryModuleGatingTest`
Expected: FAIL — Inventory is still in default_modules.

- [ ] **Step 3: Update vertical config**

In `apps/api/config/verticals.php`:

For both `coffee_shop` and `restaurant`:
- Remove `'Inventory'` from the `'default_modules'` array
- Add `'Inventory'` to the `'compatible_extras'` array

- [ ] **Step 3b: Add `compatibleExtras` to CompanyConfig DTO**

The `CompanyConfig` DTO at `apps/api/app/DTOs/CompanyConfig.php` does not have `compatibleExtras`. Add it:

1. Add constructor parameter: `public readonly array $compatibleExtras,` (after `$enabledExtras`)
2. Update `fromArray()`: add `compatibleExtras: $data['compatible_extras'] ?? [],`
3. Update `toArray()`: add `'compatible_extras' => $this->compatibleExtras,`

Then update `CompanyConfigService::getConfigForTenant()` in `apps/api/app/Services/CompanyConfigService.php` to include `compatible_extras` in the cached data. It should fetch compatible extras from `VerticalConfigService::getCompatibleExtras($vertical)`.

Also update `CompanyConfigController` response to include `compatible_extras`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=InventoryModuleGatingTest`
Expected: All 3 tests PASS.

- [ ] **Step 5: Create data migration for existing tenants**

Run: `cd apps/api && php artisan make:migration backfill_inventory_in_fnb_tenant_extras`

```php
<?php

declare(strict_types=1);

use App\Enums\Vertical;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $fnbVerticals = [Vertical::CoffeeShop->value, Vertical::Restaurant->value];

        Tenant::whereIn('vertical', $fnbVerticals)->each(function (Tenant $tenant): void {
            $extras = $tenant->enabled_extras ?? [];
            if (! in_array('Inventory', $extras, true)) {
                $extras[] = 'Inventory';
                $tenant->update(['enabled_extras' => $extras]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally blank — cannot safely remove Inventory
        // as we don't know which tenants had it before
    }
};
```

- [ ] **Step 6: Run migration**

Run: `cd apps/api && php artisan migrate`
Expected: Migration runs successfully.

- [ ] **Step 7: Commit**

```bash
git add apps/api/config/verticals.php apps/api/database/migrations/*_backfill_inventory_in_fnb_tenant_extras.php apps/api/tests/Feature/Catalog/InventoryModuleGatingTest.php
git commit -m "feat(config): move Inventory to paid extra for F&B verticals, backfill existing tenants"
```

---

### Task 9: Backend Route Gating — Split Routes by Module

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Presentation/routes.php`
- Modify: `apps/api/app/Modules/Product/routes.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/routes.php`

- [ ] **Step 1: Split Catalog routes**

In `apps/api/app/Modules/Catalog/Presentation/routes.php`:

Split the single route group into TWO groups with the same base middleware:

**Group 1 (ungated):** Composite item CRUD routes only
```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Composite Items - accessible without Inventory module
    Route::apiResource('composite-items', CompositeItemController::class);
    // ... any other composite item routes that don't involve recipes
});
```

**Group 2 (Inventory-gated):** Recipe, recipe line, variant, modifier group routes
```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Inventory'])->group(function () {
    // Recipes - require Inventory module
    Route::apiResource('composite-items.recipes', RecipeController::class)->shallow();
    Route::apiResource('recipes.lines', RecipeLineController::class)->shallow();
    // ... variant and modifier routes
});
```

- [ ] **Step 2: Split Product routes**

In `apps/api/app/Modules/Product/routes.php`:

**Group 1 (ungated):** Category CRUD only
```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    Route::apiResource('categories', CategoryController::class);
});
```

**Group 2 (Inventory-gated):** Product CRUD, product search, product export
```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Inventory'])->group(function () {
    Route::apiResource('products', ProductController::class);
    // ... other product routes
});
```

- [ ] **Step 3: Gate Inventory routes**

In `apps/api/app/Modules/Inventory/Presentation/routes.php`:

Add `'module:Inventory'` to the existing middleware array for the entire route group. Change from:
```php
->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
```
to:
```php
->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Inventory'])
```

- [ ] **Step 4: PHPStan check on route files**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog/Presentation/routes.php app/Modules/Product/routes.php app/Modules/Inventory/Presentation/routes.php --level=8`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Catalog/Presentation/routes.php apps/api/app/Modules/Product/routes.php apps/api/app/Modules/Inventory/Presentation/routes.php
git commit -m "feat(modules): gate recipe, product, and inventory routes behind Inventory module"
```

---

### Task 10: Frontend Module Gating — Sidebar, Import Dashboard, Composite Item Form

**Files:**
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- Modify: `apps/web/src/features/import/pages/ImportDashboardPage.tsx`
- Modify: `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx`

- [ ] **Step 1: Gate sidebar nav items**

In `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`:

The sidebar already uses `MODULE_NAME_MAP` and module checks. Verify that:
- Products nav item checks for `'Inventory'` module (it should map to `'Inventory'` not `'Product'`)
- Stock nav section checks for `'Inventory'` module
- Purchase Orders checks for `'Inventory'` module

If `MODULE_NAME_MAP` doesn't include these mappings, add them. If the sidebar uses `ModuleGuard`, wrap the relevant items. Read the file carefully and follow its existing pattern.

- [ ] **Step 2: Gate import types on dashboard**

In `apps/web/src/features/import/pages/ImportDashboardPage.tsx`:

Import `useCompanyConfig` and filter `IMPORT_TYPES` based on module state:

```tsx
const { hasModule } = useCompanyConfig()

// Filter import types based on enabled modules
const INVENTORY_IMPORT_TYPES: ImportType[] = ['products', 'product_images']
const visibleImportTypes = IMPORT_TYPES.filter(
  (config) => !INVENTORY_IMPORT_TYPES.includes(config.type) || hasModule('Inventory')
)
```

Then render `visibleImportTypes` instead of `IMPORT_TYPES` in the grid.

- [ ] **Step 3: Conditionally show recipe section in composite item form**

In `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx`:

Import `useCompanyConfig` and conditionally render recipe-related UI:

```tsx
const { hasModule } = useCompanyConfig()
const hasInventory = hasModule('Inventory')
```

- Hide recipe tab/section when `!hasInventory`
- Show `manual_cost` as the primary cost field when `!hasInventory`
- Show side-by-side view when `hasInventory` AND recipe cost exists

- [ ] **Step 4: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/organisms/Sidebar/ apps/web/src/features/import/pages/ImportDashboardPage.tsx apps/web/src/features/catalog/
git commit -m "feat(modules): gate sidebar, import types, and recipe UI behind Inventory module"
```

---

### Task 11: Verify Cache Invalidation (No Code Changes)

**Files:**
- Verify: `apps/api/app/Observers/TenantObserver.php` (already exists)

- [ ] **Step 1: Verify existing TenantObserver handles cache invalidation**

The `TenantObserver` at `apps/api/app/Observers/TenantObserver.php` already watches for `wasChanged(['vertical', 'enabled_extras'])` and calls `Cache::forget("tenant_config:{$tenant->id}")`. This means when the admin update-extras endpoint (Task 12) calls `$tenant->update(['enabled_extras' => ...])`, the observer fires automatically and busts the cache. **No additional code is needed.**

Verify this by reading the file and confirming the observer is registered on the Tenant model (check `Tenant::boot()` or `AppServiceProvider`).

- [ ] **Step 2: No commit needed — this is a verification step only**

---

## Stream 4: Super Admin Module Management

### Task 12: Backend — Admin Update Extras Endpoint

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php`
- Modify: `apps/api/routes/api.php`
- Test: `apps/api/tests/Feature/Admin/UpdateTenantExtrasTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Feature/Admin/UpdateTenantExtrasTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Vertical;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTenantExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Coffee Shop',
            'slug' => 'admin-extras-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => [],
        ]);
    }

    public function test_update_extras_with_valid_modules(): void
    {
        $response = $this->postJson("/api/v1/admin/tenants/{$this->tenant->id}/update-extras", [
            'enabled_extras' => ['Inventory'],
        ]);

        $response->assertOk();

        $this->tenant->refresh();
        $this->assertEquals(['Inventory'], $this->tenant->enabled_extras);
    }

    public function test_update_extras_rejects_invalid_module(): void
    {
        $response = $this->postJson("/api/v1/admin/tenants/{$this->tenant->id}/update-extras", [
            'enabled_extras' => ['NonExistentModule'],
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_extras_rejects_incompatible_module(): void
    {
        // Workshop is not a compatible extra for CoffeeShop
        $response = $this->postJson("/api/v1/admin/tenants/{$this->tenant->id}/update-extras", [
            'enabled_extras' => ['Workshop'],
        ]);

        $response->assertUnprocessable();
    }
}
```

**Note:** This test will need super admin auth setup. Check existing admin tests for the auth pattern (likely a `SuperAdmin` model or a guard). Adapt the test setUp accordingly — the test should authenticate as a super admin before making requests.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && php artisan test --filter=UpdateTenantExtrasTest`
Expected: FAIL — route/method does not exist.

- [ ] **Step 3: Implement the endpoint**

In `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php`, add:

```php
/**
 * Update a tenant's enabled extras (optional modules).
 */
public function updateExtras(Request $request, string $id): JsonResponse
{
    $tenant = Tenant::findOrFail($id);

    $request->validate([
        'enabled_extras' => ['required', 'array'],
        'enabled_extras.*' => ['required', 'string'],
    ]);

    /** @var array<string> $requestedExtras */
    $requestedExtras = $request->input('enabled_extras');

    // Validate each extra is compatible with the tenant's vertical
    $vertical = $tenant->vertical;
    if ($vertical === null) {
        return response()->json([
            'error' => 'Tenant has no vertical configured',
        ], 422);
    }

    $compatibleExtras = $this->verticalConfigService->getCompatibleExtras($vertical);

    $invalidExtras = array_diff($requestedExtras, $compatibleExtras);
    if ($invalidExtras !== []) {
        return response()->json([
            'error' => 'Invalid extras for vertical ' . $vertical->value . ': ' . implode(', ', $invalidExtras),
            'valid_extras' => $compatibleExtras,
        ], 422);
    }

    $previousExtras = $tenant->enabled_extras ?? [];
    $tenant->update(['enabled_extras' => $requestedExtras]);

    // Audit log (cache invalidation happens automatically via TenantObserver)
    $this->auditService->log(
        action: 'tenant.extras_updated',
        description: 'Updated tenant enabled extras',
        metadata: [
            'tenant_id' => $tenant->id,
            'previous_extras' => $previousExtras,
            'new_extras' => $requestedExtras,
        ]
    );

    return response()->json([
        'data' => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'vertical' => $tenant->vertical?->value,
            'enabled_extras' => $tenant->enabled_extras,
        ],
    ]);
}
```

**Important:** Add `VerticalConfigService` to the controller's constructor via constructor injection (the controller already has `AdminAuditService` injected — follow the same pattern). Do NOT use `app()` helper.

- [ ] **Step 4: Register the route**

In `apps/api/routes/api.php`, inside the super admin route group (around line 55-65), add:

```php
Route::post('/tenants/{id}/update-extras', [SuperAdminController::class, 'updateExtras']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=UpdateTenantExtrasTest`
Expected: All 3 tests PASS.

- [ ] **Step 6: PHPStan check**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Http/Controllers/Api/Admin/SuperAdminController.php --level=8`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php apps/api/routes/api.php apps/api/tests/Feature/Admin/UpdateTenantExtrasTest.php
git commit -m "feat(admin): add update-extras endpoint for tenant module management"
```

---

### Task 13: Frontend — Admin Module Toggles in TenantDetailModal

**Files:**
- Modify: `apps/web/src/features/admin/components/TenantDetailModal.tsx`
- Modify: `apps/web/src/features/admin/api/` (find admin API file for the new endpoint)
- Modify: `apps/web/src/locales/en/admin.json` (add i18n keys)
- Modify: `apps/web/src/locales/fr/admin.json`

- [ ] **Step 1: Add admin API function**

Find the admin API file (search for existing admin API calls like `extendTrial`, `changePlan`). Add:

```typescript
updateTenantExtras: async (tenantId: string, enabledExtras: string[]): Promise<void> => {
  await apiPost(`/admin/tenants/${tenantId}/update-extras`, {
    enabled_extras: enabledExtras,
  })
},
```

- [ ] **Step 2: Add module toggle UI to TenantDetailModal**

In `apps/web/src/features/admin/components/TenantDetailModal.tsx`:

After the existing modules section, add a "Manage Modules" section:

```tsx
{/* Module Management */}
{tenant.compatible_extras && tenant.compatible_extras.length > 0 && (
  <div className="space-y-3">
    <h4 className="text-sm font-medium text-gray-900">{t('admin:tenants.manageModules')}</h4>
    <div className="space-y-2">
      {tenant.compatible_extras.map((extra: string) => {
        const isEnabled = tenant.enabled_extras?.includes(extra) ?? false
        return (
          <div key={extra} className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3">
            <span className="text-sm font-medium text-gray-700">
              {moduleLabels[extra.toLowerCase()] ?? extra}
            </span>
            <button
              type="button"
              onClick={() => handleToggleModule(extra, !isEnabled)}
              className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors ${
                isEnabled ? 'bg-blue-600' : 'bg-gray-200'
              }`}
            >
              <span className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transform transition-transform ${
                isEnabled ? 'translate-x-5' : 'translate-x-0'
              }`} />
            </button>
          </div>
        )
      })}
    </div>
  </div>
)}
```

The `handleToggleModule` function:
```tsx
const handleToggleModule = async (module: string, enable: boolean) => {
  const currentExtras = tenant.enabled_extras ?? []
  const newExtras = enable
    ? [...currentExtras, module]
    : currentExtras.filter((e: string) => e !== module)

  await adminApi.updateTenantExtras(tenant.id, newExtras)
  // Refetch tenant data
  onRefresh?.()
}
```

**Note:** The `TenantDetailModal` needs access to `compatible_extras` in the tenant API response. Check if the `showTenant` endpoint in `SuperAdminController` already returns this. If not, add it to the response by computing it from the vertical config.

- [ ] **Step 3: Add i18n keys**

Add to admin translations:
```json
"manageModules": "Manage Modules",
"moduleEnabled": "Module enabled",
"moduleDisabled": "Module disabled"
```

- [ ] **Step 4: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/admin/ apps/web/src/locales/
git commit -m "feat(admin): add module toggle UI to tenant detail modal"
```

---

## Final Verification

### Task 14: End-to-End Verification

- [ ] **Step 1: Run full backend test suite**

Run: `cd apps/api && php artisan test`
Expected: All tests pass.

- [ ] **Step 2: Run PHPStan on all modified modules**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Catalog/ app/Modules/Import/ app/Modules/Product/ app/Modules/Inventory/ app/Http/Controllers/Api/Admin/ app/Services/ --level=8`
Expected: No errors.

- [ ] **Step 3: Run Pint for code style**

Run: `cd apps/api && ./vendor/bin/pint`
Expected: Code formatted correctly.

- [ ] **Step 4: Run frontend checks**

Run: `cd apps/web && pnpm typecheck && pnpm lint`
Expected: No errors.

- [ ] **Step 5: Run preflight script if available**

Run: `./scripts/preflight.sh`
Expected: All checks pass.
