# POS Cross-Location Stock Distribution — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a permitted cashier open a product detail drawer (via an eye icon) and see, for a chosen variant, that product's on-hand + in-transit-incoming stock across all active shop/warehouse locations — server-first with an offline cache.

**Architecture:** New POS-scoped read endpoint `GET /api/v1/pos/products/{product}/stock-distribution` (mounted in the POS route group so it inherits `EnforceTokenTenantClaim`), backed by a new all-locations grouped-query method on `LocationStockQueryService`. Gated by a company flag (`companies.allow_cross_location_stock_view`, persisted offline) **and** the `pos.view_cross_location_stock` permission. Frontend wires the orphaned `ProductDetailDrawer` to an eye icon, upgrades it to location-aware own-location stock, and adds a gated cross-location section with a variant selector, fetch-on-open + SQLite cache, "as of" timestamp + staleness, and a Refresh button. Read-only; no device-authority interaction.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit, PHPStan L8. React 19 / TypeScript strict / Zustand / TanStack Query / Tauri SQLite, Vitest.

**Spec:** `docs/superpowers/specs/2026-06-13-pos-cross-location-stock-design.md` (rev2). **Codex review:** `docs/superpowers/reviews/2026-06-13-pos-cross-location-stock-codex-review.md`.

---

## File Structure

**Backend (`apps/api`)**
- Create: `database/migrations/tenant/2026_06_14_100000_add_allow_cross_location_stock_view_to_companies.php` — company flag column.
- Modify: `app/Modules/Company/Domain/Company.php` — cast the new boolean.
- Modify: `app/Http/Controllers/Api/CompanyConfigController.php` — expose the flag in `/company/config`.
- Modify: `database/seeders/RolesAndPermissionsSeeder.php` — add `pos.view_cross_location_stock` + grant admin/manager.
- Create: `app/Shared/DTOs/StockDistributionRowDTO.php`, `app/Shared/DTOs/StockDistributionDTO.php` — response DTOs.
- Modify: `app/Modules/Inventory/Application/Services/LocationStockQueryService.php` — add `stockDistributionForProduct(...)`.
- Modify: `app/Shared/Contracts/LocationStockReader.php` — add the method to the contract.
- Create: `app/Modules/POS/Presentation/Controllers/StockDistributionController.php` — the endpoint.
- Modify: `app/Modules/POS/routes.php` — register the route.
- Create: `tests/Feature/POS/StockDistributionEndpointTest.php`, `tests/Feature/Inventory/StockDistributionQueryTest.php`.

**Frontend (`apps/pos`)**
- Create: `src/lib/companyConfigCache.ts` — shared per-company config cache key + persist/hydrate helpers (H2).
- Modify: `src/api/productApi.ts`, `src/stores/authStore.ts`, `src/stores/productStore.ts`, `src/lib/migration/c2BareCartLineDump.ts` — use the shared cache (persist + hydrate config offline).
- Modify: `src/components/pos/ProductDetailDrawer.tsx` — location-aware own-location stock (H4) + host the cross-location section.
- Modify: `src/components/molecules/ProductCard/ProductCard.tsx` — eye icon + `onViewDetails` (H1).
- Modify: `src/components/organisms/ProductGrid/ProductGrid.tsx`, `src/pages/HomePage.tsx` — thread `onViewDetails` + drawer state.
- Modify: `src/lib/db/migrations.ts` — v52 cache table.
- Create: `src/lib/db/repositories/crossLocationStockRepository.ts` — cache repo.
- Create: `src/api/stockDistributionApi.ts`, `src/types/stockDistribution.ts` — API client + types.
- Create: `src/hooks/useCrossLocationStock.ts` — fetch-on-open + cache + refresh + staleness.
- Create: `src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx` (+ test) — the gated UI.
- Modify: `src/locales/en/pos.json`, `src/locales/fr/pos.json` — new keys.
- Create: `src/__tests__/crossLocationStockI18n.test.tsx` — two-locale smoke (L2).

**Verification commands** (scope tests — NEVER run the full PHPUnit suite, per project rule):
- Backend: `cd apps/api && ./vendor/bin/phpstan analyse <files>` ; `./vendor/bin/pint <files>` ; `php artisan test --filter <Name>`
- Frontend: `cd apps/pos && pnpm vitest run <path>` ; `pnpm typecheck` ; `pnpm lint <path>`

---

## BACKEND

### Task B1: Company flag migration + model cast

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_14_100000_add_allow_cross_location_stock_view_to_companies.php`
- Modify: `apps/api/app/Modules/Company/Domain/Company.php` (casts array)

- [ ] **Step 1: Write the migration**

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
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('allow_cross_location_stock_view')->default(false)->after('smart_prompts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('allow_cross_location_stock_view');
        });
    }
};
```

(If `smart_prompts_enabled` is not a column on this branch, drop the `->after(...)`. Verify with `grep -n smart_prompts_enabled apps/api/database/migrations/tenant/*` before running.)

- [ ] **Step 2: Add the cast** in `Company.php` `casts()` (find the existing `protected function casts(): array` and add a line):

```php
'allow_cross_location_stock_view' => 'boolean',
```

- [ ] **Step 3: Verify migration applies** (test DB is SQLite; tenant migrations run there)

Run: `cd apps/api && php artisan migrate --path=database/migrations/tenant --database=testing 2>&1 | tail -5` (or rely on `RefreshDatabase` in the B2 test).
Expected: no error; column exists.

- [ ] **Step 4: Commit**

```bash
git add apps/api/database/migrations/tenant/2026_06_14_100000_add_allow_cross_location_stock_view_to_companies.php apps/api/app/Modules/Company/Domain/Company.php
git commit -m "feat(api): companies.allow_cross_location_stock_view flag"
```

---

### Task B2: Expose flag in `/company/config`

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/CompanyConfigController.php` (show(), ~line 81-92)
- Test: `apps/api/tests/Feature/CompanyConfig/CompanyConfigCrossLocationFlagTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CompanyConfig;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class CompanyConfigCrossLocationFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_config_exposes_cross_location_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'allow_cross_location_stock_view' => true,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        // primary active membership so $company resolves in the controller:
        \App\Modules\Identity\Domain\UserCompanyMembership::create([
            'user_id' => $user->id, 'company_id' => $company->id,
            'role' => 'admin', 'is_primary' => true, 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.allow_cross_location_stock_view', true);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (key missing)

Run: `cd apps/api && php artisan test --filter CompanyConfigCrossLocationFlagTest`
Expected: FAIL — `Failed asserting that null matches expected true`.

- [ ] **Step 3: Add the flag** in `CompanyConfigController::show()` `'data'` array (next to `smart_prompts_enabled`):

```php
'allow_cross_location_stock_view' => (bool) ($company?->allow_cross_location_stock_view ?? false),
```

- [ ] **Step 4: Run — expect PASS.** `php artisan test --filter CompanyConfigCrossLocationFlagTest`

- [ ] **Step 5: PHPStan + Pint + Commit**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Http/Controllers/Api/CompanyConfigController.php && ./vendor/bin/pint app/Http/Controllers/Api/CompanyConfigController.php
git add apps/api/app/Http/Controllers/Api/CompanyConfigController.php apps/api/tests/Feature/CompanyConfig/CompanyConfigCrossLocationFlagTest.php
git commit -m "feat(api): expose allow_cross_location_stock_view in /company/config"
```

---

### Task B3: Permission + role grants

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (POS perms array ~238-257; manager ~437; admin gets all)
- Test: `apps/api/tests/Feature/Permissions/CrossLocationStockPermissionTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class CrossLocationStockPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_seeded_and_granted_to_manager_not_cashier(): void
    {
        $tenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = Role::findByName('manager', 'sanctum');
        $cashier = Role::findByName('cashier', 'sanctum');

        $this->assertTrue($manager->hasPermissionTo('pos.view_cross_location_stock'));
        $this->assertFalse($cashier->hasPermissionTo('pos.view_cross_location_stock'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`There is no permission named pos.view_cross_location_stock`).

Run: `cd apps/api && php artisan test --filter CrossLocationStockPermissionTest`

- [ ] **Step 3: Add the permission string** to the POS permissions array (after `'pos.tolerance.apply',`):

```php
'pos.view_cross_location_stock',
```

And add it to the **manager** `syncPermissions([...])` array and the **admin** grant. (Admin typically gets all permissions; verify how admin is granted — if admin uses `Permission::all()` no change needed. If admin has an explicit list, add the string there too.) Do **not** add it to the cashier array.

- [ ] **Step 4: Run — expect PASS.** `php artisan test --filter CrossLocationStockPermissionTest`

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/seeders/RolesAndPermissionsSeeder.php apps/api/tests/Feature/Permissions/CrossLocationStockPermissionTest.php
git commit -m "feat(api): pos.view_cross_location_stock permission (admin+manager)"
```

---

### Task B4: `stockDistributionForProduct` service method + DTOs

**Files:**
- Create: `apps/api/app/Shared/DTOs/StockDistributionRowDTO.php`, `apps/api/app/Shared/DTOs/StockDistributionDTO.php`
- Modify: `apps/api/app/Shared/Contracts/LocationStockReader.php`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php`
- Test: `apps/api/tests/Feature/Inventory/StockDistributionQueryTest.php` (create)

- [ ] **Step 1: Write the DTOs**

`StockDistributionRowDTO.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class StockDistributionRowDTO
{
    public function __construct(
        public string $locationId,
        public string $locationName,
        public string $locationType,
        public bool $isCurrent,
        public string $onHand,           // scale-4 decimal string
        public string $incomingTransfer, // scale-4 decimal string
    ) {}
}
```

`StockDistributionDTO.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class StockDistributionDTO
{
    /** @param list<StockDistributionRowDTO> $locations */
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public ?string $variantLabel,
        public array $locations,
        public string $totalOnHand,
        public string $totalIncomingTransfer,
    ) {}
}
```

- [ ] **Step 2: Add to the contract** `LocationStockReader.php`:

```php
public function stockDistributionForProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    ?string $variantId,
    string $currentLocationId,
): \App\Shared\DTOs\StockDistributionDTO;
```

- [ ] **Step 3: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Shared\Contracts\LocationStockReader;
use Tests\TestCase;
use Tests\Support\TenantCompanyProductSetup; // see note below
use Illuminate\Foundation\Testing\RefreshDatabase;

final class StockDistributionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_on_hand_and_in_transit_per_shop_and_warehouse(): void
    {
        [$tenant, $company, $product] = $this->makeTenantCompanyProduct(); // helper inline below

        $shop = Location::factory()->create(['company_id' => $company->id, 'type' => 'shop', 'is_active' => true, 'name' => 'Shop A']);
        $warehouse = Location::factory()->create(['company_id' => $company->id, 'type' => 'warehouse', 'is_active' => true, 'name' => 'WH']);
        $office = Location::factory()->create(['company_id' => $company->id, 'type' => 'office', 'is_active' => true, 'name' => 'HQ']);
        $inactive = Location::factory()->create(['company_id' => $company->id, 'type' => 'shop', 'is_active' => false, 'name' => 'Closed']);

        $this->stockAt($tenant->id, $company->id, $product->id, $shop->id, '12.0000', '2.0000'); // qty, reserved
        $this->stockAt($tenant->id, $company->id, $product->id, $warehouse->id, '40.0000', '0.0000');

        // in-transit transfer of 5 to the shop:
        $transfer = StockTransfer::factory()->create([
            'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'status' => TransferStatus::InTransit, 'destination_location_id' => $shop->id,
            'source_location_id' => $warehouse->id,
        ]);
        StockTransferLine::factory()->create([
            'transfer_id' => $transfer->id, 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'product_id' => $product->id, 'variant_id' => null, 'quantity' => '5.0000',
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);
        $reader = app(LocationStockReader::class);

        $dto = $reader->stockDistributionForProduct($tenant->id, $company->id, $product->id, null, $shop->id);

        // office + inactive excluded; shop + warehouse present:
        $this->assertCount(2, $dto->locations);
        $names = array_map(fn ($r) => $r->locationName, $dto->locations);
        $this->assertNotContains('HQ', $names);
        $this->assertNotContains('Closed', $names);

        // current location (shop) first:
        $this->assertSame('Shop A', $dto->locations[0]->locationName);
        $this->assertTrue($dto->locations[0]->isCurrent);
        $this->assertSame('10.0000', $dto->locations[0]->onHand);            // 12 - 2 reserved
        $this->assertSame('5.0000', $dto->locations[0]->incomingTransfer);   // in-transit to shop
        $this->assertSame('40.0000', $dto->locations[1]->onHand);
        $this->assertSame('0.0000', $dto->locations[1]->incomingTransfer);

        $this->assertSame('50.0000', $dto->totalOnHand);                     // 10 + 40
        $this->assertSame('5.0000', $dto->totalIncomingTransfer);
    }
}
```

> Note: reuse the project's existing tenant/company/product test harness. Mirror the setUp seen in `tests/Feature/Inventory/StockTransferVariantTest.php` (creates Tenant, Company, sets `CompanyContext`, `Location::create([... 'type' => 'warehouse' ...])`). Add small private helpers `makeTenantCompanyProduct()` and `stockAt(...)` that wrap `StockLevel::create([...])` with `quantity`/`reserved`. Use real factories/models that exist on this branch; verify field names against `StockLevel`/`StockTransfer` before running.

- [ ] **Step 4: Run — expect FAIL** (method missing).

Run: `cd apps/api && php artisan test --filter StockDistributionQueryTest`

- [ ] **Step 5: Implement `stockDistributionForProduct`** in `LocationStockQueryService` (add `use` imports for `Location`, the two DTOs; reuse `QTY_SCALE`, `QuantityScale`, `DB`, `TransferStatus`, `StockLevel`):

```php
public function stockDistributionForProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    ?string $variantId,
    string $currentLocationId,
): StockDistributionDTO {
    // 1) candidate locations: active shop + warehouse (one query)
    $locations = \App\Modules\Company\Domain\Location::query()
        ->where('company_id', $companyId)
        ->where('is_active', true)
        ->whereIn('type', ['shop', 'warehouse'])
        ->get(['id', 'name', 'type']);

    // 2) on-hand per location (one grouped query), variant-grain-matched
    $stockQuery = StockLevel::query()
        ->where('tenant_id', $tenantId)
        ->where('company_id', $companyId)
        ->where('product_id', $productId);
    $variantId === null
        ? $stockQuery->whereNull('variant_id')
        : $stockQuery->where('variant_id', $variantId);
    /** @var array<string, array{q: string, r: string}> $onHandByLoc */
    $onHandByLoc = [];
    foreach ($stockQuery->get(['location_id', 'quantity', 'reserved']) as $lvl) {
        $onHandByLoc[(string) $lvl->location_id] = ['q' => (string) $lvl->quantity, 'r' => (string) $lvl->reserved];
    }

    // 3) in-transit incoming per destination (one grouped query)
    $transferQuery = DB::table('stock_transfer_lines')
        ->join('stock_transfers', 'stock_transfer_lines.transfer_id', '=', 'stock_transfers.id')
        ->where('stock_transfer_lines.tenant_id', $tenantId)
        ->where('stock_transfer_lines.company_id', $companyId)
        ->where('stock_transfer_lines.product_id', $productId)
        ->where('stock_transfers.status', TransferStatus::InTransit->value);
    $variantId === null
        ? $transferQuery->whereNull('stock_transfer_lines.variant_id')
        : $transferQuery->where('stock_transfer_lines.variant_id', $variantId);
    /** @var array<string, string> $incomingByLoc */
    $incomingByLoc = [];
    foreach (
        $transferQuery->groupBy('stock_transfers.destination_location_id')
            ->selectRaw('stock_transfers.destination_location_id as loc, SUM(stock_transfer_lines.quantity) as incoming')
            ->get() as $row
    ) {
        $incomingByLoc[(string) $row->loc] = QuantityScale::round((string) $row->incoming, self::QTY_SCALE, QuantityScale::FLOOR);
    }

    // 4) assemble rows (current first, then by name), zero-filled
    $rows = [];
    foreach ($locations as $loc) {
        $id = (string) $loc->id;
        $oh = $onHandByLoc[$id] ?? null;
        $onHand = $oh === null
            ? '0.0000'
            : bcsub(
                QuantityScale::round($oh['q'], self::QTY_SCALE, QuantityScale::FLOOR),
                QuantityScale::round($oh['r'], self::QTY_SCALE, QuantityScale::FLOOR),
                self::QTY_SCALE
            );
        $rows[] = new StockDistributionRowDTO(
            locationId: $id,
            locationName: (string) $loc->name,
            locationType: (string) $loc->type,
            isCurrent: $id === $currentLocationId,
            onHand: $onHand,
            incomingTransfer: $incomingByLoc[$id] ?? '0.0000',
        );
    }
    usort($rows, function (StockDistributionRowDTO $a, StockDistributionRowDTO $b): int {
        if ($a->isCurrent !== $b->isCurrent) {
            return $a->isCurrent ? -1 : 1;
        }
        return strcmp($a->locationName, $b->locationName);
    });

    $totalOnHand = '0.0000';
    $totalIncoming = '0.0000';
    foreach ($rows as $r) {
        $totalOnHand = bcadd($totalOnHand, $r->onHand, self::QTY_SCALE);
        $totalIncoming = bcadd($totalIncoming, $r->incomingTransfer, self::QTY_SCALE);
    }

    return new StockDistributionDTO(
        productId: $productId,
        variantId: $variantId,
        variantLabel: null, // resolved in the controller via ProductVariantLookup
        locations: $rows,
        totalOnHand: $totalOnHand,
        totalIncomingTransfer: $totalIncoming,
    );
}
```

> `getAvailableQuantity()` on `StockLevel` already does `quantity − reserved` via bcmath; the grouped query above avoids hydrating models per location (M4: no `read()`-per-location loop). If `bcsub`/`bcadd` are not already imported, they are global PHP functions — no import needed.

- [ ] **Step 6: Run — expect PASS.** `php artisan test --filter StockDistributionQueryTest`

- [ ] **Step 7: PHPStan + Pint + Commit**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/Services/LocationStockQueryService.php app/Shared/DTOs/StockDistributionDTO.php app/Shared/DTOs/StockDistributionRowDTO.php app/Shared/Contracts/LocationStockReader.php && ./vendor/bin/pint app/Modules/Inventory app/Shared/DTOs app/Shared/Contracts
git add apps/api/app/Shared/DTOs/StockDistribution*.php apps/api/app/Shared/Contracts/LocationStockReader.php apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php apps/api/tests/Feature/Inventory/StockDistributionQueryTest.php
git commit -m "feat(api): LocationStockQueryService::stockDistributionForProduct (all-locations, grouped)"
```

---

### Task B5: `StockDistributionController` + route + gating + variant rule

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php`
- Modify: `apps/api/app/Modules/POS/routes.php`
- Test: `apps/api/tests/Feature/POS/StockDistributionEndpointTest.php` (create)

- [ ] **Step 1: Write the failing feature test** (covers shape, 403 perm, 403 flag-off, 422 variant rules, 404 non-UUID, isolation). Mirror the setUp in `tests/Feature/POS/PosStockLevelEndpointTest.php`. Skeleton:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Domain\UserCompanyMembership;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class StockDistributionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenant(bool $flag, bool $grant): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'allow_cross_location_stock_view' => $flag]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'admin', 'is_primary' => true, 'status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('pos.view_cross_location_stock', 'sanctum');
        if ($grant) {
            $user->givePermissionTo('pos.view_cross_location_stock');
        }
        app(CompanyContext::class)->setCompanyId($company->id);
        Sanctum::actingAs($user);
        return [$tenant, $company, $user];
    }

    public function test_returns_distribution_when_permitted_and_flag_on(): void
    {
        [$tenant, $company] = $this->bootTenant(flag: true, grant: true);
        $shop = Location::factory()->create(['company_id' => $company->id, 'type' => 'shop', 'is_active' => true, 'name' => 'Shop A']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        StockLevel::create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $shop->id, 'quantity' => '7.0000', 'reserved' => '0.0000']);

        $this->getJson("/api/v1/pos/products/{$product->id}/stock-distribution?current_location_id={$shop->id}")
            ->assertOk()
            ->assertJsonPath('data.locations.0.on_hand', '7.0000')
            ->assertJsonPath('data.locations.0.incoming_transfer', '0.0000');
    }

    public function test_403_without_permission(): void
    {
        [$tenant, $company] = $this->bootTenant(flag: true, grant: false);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        $this->getJson("/api/v1/pos/products/{$product->id}/stock-distribution")->assertForbidden();
    }

    public function test_403_when_flag_off(): void
    {
        [$tenant, $company] = $this->bootTenant(flag: false, grant: true);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        $this->getJson("/api/v1/pos/products/{$product->id}/stock-distribution")->assertForbidden();
    }

    public function test_422_when_variant_product_missing_variant_id(): void
    {
        [$tenant, $company] = $this->bootTenant(flag: true, grant: true);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        // create an active variant for the product so the variant rule trips:
        \App\Modules\Catalog\Domain\ProductVariant::factory()->create(['product_id' => $product->id, 'company_id' => $company->id, 'is_active' => true]);
        $this->getJson("/api/v1/pos/products/{$product->id}/stock-distribution")->assertStatus(422);
    }

    public function test_404_on_non_uuid(): void
    {
        $this->bootTenant(flag: true, grant: true);
        $this->getJson('/api/v1/pos/products/not-a-uuid/stock-distribution')->assertStatus(404);
    }
}
```

> Verify the variant model/factory name on this branch (`ProductVariant` under `App\Modules\Catalog\Domain` per `ProductVariantLookup`). If no factory exists, create the variant via `::create([...])` with required fields. Confirm `Product` factory namespace.
>
> **Spec §8 #7 (H3) — token-tenant mismatch:** the route inherits `EnforceTokenTenantClaim` from the POS group, so it is structurally covered. If an existing test in `tests/Feature/.../EnforceTokenTenantClaim*` demonstrates how to mint a Sanctum token with a mismatched `tenant:<id>` ability, add one case asserting this route returns `401` with `error.code = TOKEN_TENANT_MISMATCH`. If no such harness exists, document that the protection is inherited (do not hand-roll token abilities).
>
> **Spec §8 #10 (M4) — no N+1 (optional):** if the suite has a query-count helper (`DB::listen` counter), assert the all-locations path issues a bounded, constant number of queries regardless of location count. Otherwise the grouped-query implementation in B4 is the enforced contract.

- [ ] **Step 2: Run — expect FAIL** (route 404 for all).

Run: `cd apps/api && php artisan test --filter StockDistributionEndpointTest`

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class StockDistributionController extends Controller
{
    public function __construct(
        private readonly LocationStockReader $stockReader,
        private readonly ProductVariantLookup $variantLookup,
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(Request $request, string $product): JsonResponse
    {
        // Permission gate (tenant-scoped Spatie permission):
        Gate::authorize('pos.view_cross_location_stock');

        // Company master switch:
        $company = $this->companyContext->requireCompany();
        if (! (bool) $company->allow_cross_location_stock_view) {
            abort(403, 'Cross-location stock view is disabled for this company.');
        }

        // UUID guard (PG would 500 on a bad uuid):
        if (! Str::isUuid($product)) {
            abort(404);
        }

        $validated = $request->validate([
            'variant_id' => ['sometimes', 'uuid'],
            'current_location_id' => ['sometimes', 'uuid'],
        ]);
        $variantId = $validated['variant_id'] ?? null;
        $currentLocationId = $validated['current_location_id'] ?? '';

        // Variant rule (mirror StockTransferService invariant):
        $hasActiveVariants = $this->variantLookup->listForProduct($product, true)->isNotEmpty();
        if ($hasActiveVariants && $variantId === null) {
            abort(422, 'This product has variants; variant_id is required.');
        }
        if (! $hasActiveVariants && $variantId !== null) {
            abort(422, 'This product has no variants; variant_id must be omitted.');
        }

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $dto = $this->stockReader->stockDistributionForProduct(
            tenantId: $tenantId,
            companyId: $companyId,
            productId: $product,
            variantId: $variantId,
            currentLocationId: $currentLocationId,
        );

        $variantLabel = null;
        if ($variantId !== null) {
            $summary = $this->variantLookup->findById($variantId);
            $variantLabel = $summary?->nameSuffix; // verify property name on ProductVariantSummary
        }

        return response()->json([
            'data' => [
                'product_id' => $dto->productId,
                'variant_id' => $dto->variantId,
                'variant_label' => $variantLabel,
                'locations' => array_map(static fn ($r) => [
                    'location_id' => $r->locationId,
                    'location_name' => $r->locationName,
                    'location_type' => $r->locationType,
                    'is_current' => $r->isCurrent,
                    'on_hand' => $r->onHand,
                    'incoming_transfer' => $r->incomingTransfer,
                ], $dto->locations),
                'totals' => [
                    'on_hand' => $dto->totalOnHand,
                    'incoming_transfer' => $dto->totalIncomingTransfer,
                ],
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }
}
```

> Verify `ProductVariantSummary`'s label property name (likely `nameSuffix`) — `grep -n "class ProductVariantSummary" -A20 apps/api/app/Shared/DTOs/ProductVariantSummary.php`. Verify `CompanyContext::requireCompany()` returns the Company model with the flag attribute.

- [ ] **Step 4: Register the route** in `apps/api/app/Modules/POS/routes.php` — add the `use` import and a line inside the existing `api/v1` group (after `/pos/stock-levels`):

```php
use App\Modules\POS\Presentation\Controllers\StockDistributionController;
// ...
Route::get('/pos/products/{product}/stock-distribution', [StockDistributionController::class, 'show']);
```

- [ ] **Step 5: Run — expect PASS** for all cases. `php artisan test --filter StockDistributionEndpointTest`

- [ ] **Step 6: PHPStan + Pint + Commit**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/StockDistributionController.php && ./vendor/bin/pint app/Modules/POS
git add apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php apps/api/app/Modules/POS/routes.php apps/api/tests/Feature/POS/StockDistributionEndpointTest.php
git commit -m "feat(api): GET /pos/products/{product}/stock-distribution endpoint (gated, variant-aware)"
```

---

## FRONTEND

### Task F1: Persist + hydrate company config offline (H2)

**Files:**
- Create: `apps/pos/src/lib/companyConfigCache.ts`
- Modify: `apps/pos/src/api/productApi.ts`, `apps/pos/src/stores/authStore.ts`, `apps/pos/src/lib/migration/c2BareCartLineDump.ts`, `apps/pos/src/stores/productStore.ts`
- Test: `apps/pos/src/lib/__tests__/companyConfigCache.test.ts` (create)

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { companyConfigCacheKey, persistCompanyConfig, loadCachedCompanyConfig } from '@/lib/companyConfigCache';
import { setStoredValue, getStoredValue } from '@/lib/storage';
import type { CompanyConfig } from '@/types/companyConfig';

vi.mock('@/lib/storage', () => {
  const store = new Map<string, unknown>();
  return {
    setStoredValue: vi.fn(async (k: string, v: unknown) => { store.set(k, v); }),
    getStoredValue: vi.fn(async (k: string) => store.get(k) ?? null),
    StorageKeys: {},
  };
});

const cfg: CompanyConfig = { all_enabled_modules: [], allow_cross_location_stock_view: true } as CompanyConfig;

describe('companyConfigCache', () => {
  beforeEach(() => vi.clearAllMocks());

  it('round-trips config per company', async () => {
    await persistCompanyConfig('c-1', cfg);
    expect(setStoredValue).toHaveBeenCalledWith(companyConfigCacheKey('c-1'), cfg);
    const loaded = await loadCachedCompanyConfig('c-1');
    expect(loaded?.allow_cross_location_stock_view).toBe(true);
  });

  it('returns null for unknown company', async () => {
    expect(await loadCachedCompanyConfig('nope')).toBeNull();
  });
});
```

- [ ] **Step 2: Run — expect FAIL** (module missing). `cd apps/pos && pnpm vitest run src/lib/__tests__/companyConfigCache.test.ts`

- [ ] **Step 3: Create `companyConfigCache.ts`**

```ts
import { getStoredValue, setStoredValue } from '@/lib/storage';
import type { CompanyConfig } from '@/types/companyConfig';

const PREFIX = 'company_config';

export function companyConfigCacheKey(companyId: string): string {
  return `${PREFIX}:${companyId}`;
}

export async function persistCompanyConfig(companyId: string, config: CompanyConfig): Promise<void> {
  await setStoredValue(companyConfigCacheKey(companyId), config);
}

export async function loadCachedCompanyConfig(companyId: string): Promise<CompanyConfig | null> {
  return getStoredValue<CompanyConfig>(companyConfigCacheKey(companyId));
}
```

- [ ] **Step 4: Add the flag to the type** — `apps/pos/src/types/companyConfig.ts`, add to `CompanyConfig`:

```ts
  allow_cross_location_stock_view?: boolean;
```

- [ ] **Step 5: Persist on fetch + hydrate on boot.**
  - In `productApi.ts` `fetchCompanyConfig()` — after fetching, persist using the current company id. Pull the company id from the auth store at call time:

```ts
import { useAuthStore } from '@/stores/authStore';
import { persistCompanyConfig } from '@/lib/companyConfigCache';

export async function fetchCompanyConfig(): Promise<CompanyConfig> {
  const config = await apiGet<CompanyConfig>('/company/config');
  const companyId = useAuthStore.getState().companyId;
  if (companyId) {
    await persistCompanyConfig(companyId, config).catch(() => {});
  }
  return config;
}
```

  - In `authStore.refreshCompanyConfig()` — after setting productStore state, persist (it already has `companyId` via `get()`/state). Add:

```ts
const companyId = get().companyId;
if (companyId) {
  const { persistCompanyConfig } = await import('@/lib/companyConfigCache');
  await persistCompanyConfig(companyId, config).catch(() => {});
}
```

  - In `productStore.ts` config-fetch block (~191-199), before fetching, hydrate from cache so an offline boot still has the flag:

```ts
let config = get().companyConfig;
if (!config) {
  const companyId = useAuthStore.getState().companyId;
  if (companyId) {
    const { loadCachedCompanyConfig } = await import('@/lib/companyConfigCache');
    config = await loadCachedCompanyConfig(companyId);
    if (config) set({ companyConfig: config });
  }
}
if (!config) {
  try {
    config = await fetchCompanyConfig();
    set({ companyConfig: config });
  } catch {
    config = null;
  }
}
```

  - In `c2BareCartLineDump.ts` — replace the local `companyConfigCacheKey` definition + inline `setStoredValue`/`getStoredValue` calls with imports from `@/lib/companyConfigCache` (`persistCompanyConfig` / `loadCachedCompanyConfig`). Remove the now-duplicate `COMPANY_CONFIG_CACHE_PREFIX`/`companyConfigCacheKey`.

- [ ] **Step 6: Run the cache test + typecheck — expect PASS.**

```bash
cd apps/pos && pnpm vitest run src/lib/__tests__/companyConfigCache.test.ts && pnpm typecheck
```

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/lib/companyConfigCache.ts apps/pos/src/lib/__tests__/companyConfigCache.test.ts apps/pos/src/types/companyConfig.ts apps/pos/src/api/productApi.ts apps/pos/src/stores/authStore.ts apps/pos/src/stores/productStore.ts apps/pos/src/lib/migration/c2BareCartLineDump.ts
git commit -m "feat(pos): persist+hydrate company config offline (cross-location gate, H2)"
```

---

### Task F2: ProductDetailDrawer → location-aware own-location stock (H4)

**Files:**
- Modify: `apps/pos/src/components/pos/ProductDetailDrawer.tsx`
- Test: `apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx` (create)

- [ ] **Step 1: Write the failing test** (renders available from the slice, not legacy `stock_quantity`):

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProductDetailDrawer } from '@/components/pos/ProductDetailDrawer';
import type { POSProduct } from '@/types/product';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }));
vi.mock('@/lib/currency', () => ({ useCurrency: () => ({ format: (n: string | number) => `${n}` }) }));

const product = { id: 'p1', name: 'Widget', sku: 'W1', sale_price: '9.99', stock_quantity: 999 } as POSProduct;

describe('ProductDetailDrawer own-location stock', () => {
  it('renders available from locationStock slice, not legacy stock_quantity', () => {
    render(<ProductDetailDrawer isOpen product={product} onClose={() => {}}
      locationStock={{ available: '8.0000', incoming_transfer: '2.0000', incoming_po: '0.0000' }} />);
    // shows formatted available "8", never the legacy 999
    expect(screen.queryByText('999')).toBeNull();
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('8');
  });

  it('renders no stock chrome when slice is null (exempt)', () => {
    render(<ProductDetailDrawer isOpen product={product} onClose={() => {}} locationStock={null} />);
    expect(screen.queryByTestId('drawer-stock-row')).toBeNull();
  });
});
```

- [ ] **Step 2: Run — expect FAIL** (prop unsupported / legacy rendering). `cd apps/pos && pnpm vitest run src/components/pos/__tests__/ProductDetailDrawer.test.tsx`

- [ ] **Step 3: Upgrade the drawer.** Change the props interface + stock rendering:

```tsx
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { X, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { bccomp } from '@/lib/decimal';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

interface ProductDetailDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct | null;
  /** Same slice semantics as ProductCard: object → location-aware; null → exempt (no chrome); undefined → legacy fallback. */
  locationStock?: LocationStockDisplay | null;
}
```

Replace the `stockColor`/stock-row block. Compute from the slice when present; fall back to legacy only when `undefined`; render nothing when `null`:

```tsx
  const hasSlice = locationStock !== undefined && locationStock !== null;
  const exempt = locationStock === null;

  const available = hasSlice ? locationStock!.available : null;
  const isOut = available !== null ? bccomp(available, '0') <= 0 : product.stock_quantity <= 0;
  const isLow = !isOut && (available !== null ? bccomp(available, '10') <= 0 : product.stock_quantity <= 10);
  const stockTone = isOut ? 'text-red-600 bg-red-50' : isLow ? 'text-amber-600 bg-amber-50' : 'text-green-600 bg-green-50';
  const stockText = available !== null ? formatAvailableQty(available) : String(product.stock_quantity);
```

And the JSX (replace the existing stock row; render only when not exempt):

```tsx
{!exempt && (
  <div className="flex items-center justify-between text-sm">
    <span className="text-gray-500">{t('productDetail.stock')}</span>
    <span data-testid="drawer-stock-row" className={cn('rounded-full px-2.5 py-0.5 text-xs font-semibold', stockTone)}>
      {stockText}
    </span>
  </div>
)}
```

- [ ] **Step 4: Run — expect PASS.** `pnpm vitest run src/components/pos/__tests__/ProductDetailDrawer.test.tsx`

- [ ] **Step 5: typecheck + lint + Commit**

```bash
cd apps/pos && pnpm typecheck && pnpm lint src/components/pos/ProductDetailDrawer.tsx
git add apps/pos/src/components/pos/ProductDetailDrawer.tsx apps/pos/src/components/pos/__tests__/ProductDetailDrawer.test.tsx
git commit -m "feat(pos): ProductDetailDrawer reads location-aware own-location stock (H4)"
```

---

### Task F3: Eye icon + `onViewDetails` on ProductCard (H1)

**Files:**
- Modify: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx`
- Test: `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx` (add cases)

- [ ] **Step 1: Add failing tests** to the existing test file:

```tsx
it('renders an eye button when onViewDetails is provided and opens details on click without adding to cart', () => {
  const onViewDetails = vi.fn();
  const onAddToCart = vi.fn();
  const product = makeProduct({ id: 'p1', name: 'Widget', sale_price: '5.00', stock_quantity: 10 });
  renderCard({ product, onAddToCart, onViewDetails });
  fireEvent.click(screen.getByTestId('view-details-button'));
  expect(onViewDetails).toHaveBeenCalledWith(product);
  expect(onAddToCart).not.toHaveBeenCalled();
});

it('keyboard-activating the eye button does NOT add to cart (H1)', () => {
  const onViewDetails = vi.fn();
  const onAddToCart = vi.fn();
  const product = makeProduct({ id: 'p1', name: 'Widget', sale_price: '5.00', stock_quantity: 10 });
  renderCard({ product, onAddToCart, onViewDetails });
  const eye = screen.getByTestId('view-details-button');
  fireEvent.keyDown(eye, { key: 'Enter' });
  expect(onAddToCart).not.toHaveBeenCalled();
});
```

- [ ] **Step 2: Run — expect FAIL** (no button). `cd apps/pos && pnpm vitest run src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`

- [ ] **Step 3: Implement.** Add `Eye` to the lucide import, add the prop, render the button.

In the import line: `import { ArrowUpRight, Eye, Package, SlidersHorizontal } from 'lucide-react';`

Add to `ProductCardProps`:
```tsx
  onViewDetails?: (product: POSProduct) => void;
```

Destructure `onViewDetails` in the component params. Render the eye button inside the root div (top-left, mirroring the customize button), stopping BOTH click and keydown so it never reaches the parent `activate()`:

```tsx
{onViewDetails && (
  <button
    type="button"
    data-testid="view-details-button"
    aria-label={t('products.viewDetails')}
    onClick={(e) => {
      e.stopPropagation();
      onViewDetails(product);
    }}
    onKeyDown={(e) => {
      // H1: the card root is a role=button with onKeyDown=activate; Enter/Space
      // on this inner button must NOT bubble up and add the product to cart.
      if (e.key === 'Enter' || e.key === ' ') {
        e.stopPropagation();
        e.preventDefault();
        onViewDetails(product);
      }
    }}
    className="absolute top-1.5 left-1.5 flex h-9 w-9 items-center justify-center rounded-full bg-gray-100/90 text-gray-600 shadow-sm transition-colors hover:bg-gray-200 active:bg-gray-300"
    title={t('products.viewDetails')}
  >
    <Eye className="h-4 w-4" />
  </button>
)}
```

> Add the `products.viewDetails` key in Task F9 (en+fr). The test mocks `t` to echo the key, so it passes before the locale entry exists.

- [ ] **Step 4: Run — expect PASS.** `pnpm vitest run src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx`

- [ ] **Step 5: typecheck + lint + Commit**

```bash
cd apps/pos && pnpm typecheck && pnpm lint src/components/molecules/ProductCard/ProductCard.tsx
git add apps/pos/src/components/molecules/ProductCard/ProductCard.tsx apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.test.tsx
git commit -m "feat(pos): eye/view-details button on ProductCard, keyboard-safe (H1)"
```

---

### Task F4: Thread `onViewDetails` → HomePage drawer state

**Files:**
- Modify: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`
- Modify: `apps/pos/src/pages/HomePage.tsx`

- [ ] **Step 1: Add the prop to ProductGrid.** In `ProductGridProps`:
```tsx
  onViewDetails?: (product: POSProduct) => void;
```
Destructure it in the function signature and pass it to each `<ProductCard ... onViewDetails={onViewDetails} />` in the map.

- [ ] **Step 2: HomePage — add drawer state + handler + render.**
  - Import the drawer: `import { ProductDetailDrawer } from '@/components/organisms/ProductDetailDrawer';`
  - Add state near the other modal state: `const [detailProduct, setDetailProduct] = useState<POSProduct | null>(null);`
  - Add handler near `handleCustomize`:
```tsx
const handleViewDetails = useCallback((product: POSProduct) => {
  setDetailProduct(product);
}, []);
```
  - Pass `onViewDetails={handleViewDetails}` on the `<ProductGrid ... />`.
  - Render the drawer near the other modals, passing the per-product location-stock slice already available in `locationStock`:
```tsx
<ProductDetailDrawer
  isOpen={detailProduct !== null}
  product={detailProduct}
  onClose={() => setDetailProduct(null)}
  locationStock={detailProduct ? locationStock[detailProduct.id] : undefined}
/>
```

- [ ] **Step 3: typecheck — expect PASS.** `cd apps/pos && pnpm typecheck`

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx apps/pos/src/pages/HomePage.tsx
git commit -m "feat(pos): wire eye -> ProductDetailDrawer from HomePage"
```

---

### Task F5: SQLite cache table (v52) + repository

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts` (append v52)
- Create: `apps/pos/src/lib/db/repositories/crossLocationStockRepository.ts`
- Test: `apps/pos/src/lib/db/repositories/__tests__/crossLocationStockRepository.test.ts` (create — mirror existing repo test harness)

- [ ] **Step 1: Append migration v52** to the `migrations` array (after v51):

```ts
  {
    // Cross-location stock distribution cache (server-first, fetch-on-open).
    // payload = JSON of the /pos/products/{id}/stock-distribution `data` object;
    // quantities inside stay scale-4 decimal STRINGS. fetched_at = server time.
    // variant_id '' = product-grain (SQLite PKs reject NULL).
    version: 52,
    name: 'create_product_stock_distribution_cache',
    sql: `
      CREATE TABLE IF NOT EXISTS product_stock_distribution_cache (
        product_id TEXT NOT NULL,
        variant_id TEXT NOT NULL DEFAULT '',
        variant_label TEXT,
        payload TEXT NOT NULL,
        fetched_at TEXT NOT NULL,
        PRIMARY KEY (product_id, variant_id)
      );
      CREATE INDEX IF NOT EXISTS idx_xloc_cache_product ON product_stock_distribution_cache(product_id);
    `,
  },
```

- [ ] **Step 2: Write the failing repo test** (mirror `locationStockRepository` test setup — an in-memory/Tauri-mock DB harness; reuse whatever helper the existing repo tests use to get a `db`):

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { upsertDistribution, getDistribution, getAllForProduct, deleteDistributionForProducts } from '@/lib/db/repositories/crossLocationStockRepository';
// import the same test-db helper the existing repo tests use:
import { makeTestDb } from '@/lib/db/__tests__/testDb'; // adjust to the real helper path

describe('crossLocationStockRepository', () => {
  let db: Awaited<ReturnType<typeof makeTestDb>>;
  beforeEach(async () => { db = await makeTestDb(); /* runs migrations incl. v52 */ });

  it('upserts and reads by product+variant', async () => {
    await upsertDistribution(db, 'p1', 'v1', 'M / Blue', '{"x":1}', '2026-06-14T10:00:00Z');
    const row = await getDistribution(db, 'p1', 'v1');
    expect(row?.payload).toBe('{"x":1}');
    expect(row?.variant_label).toBe('M / Blue');
  });

  it('product-grain uses empty variant key', async () => {
    await upsertDistribution(db, 'p2', null, null, '{"y":2}', '2026-06-14T10:00:00Z');
    expect((await getDistribution(db, 'p2', null))?.payload).toBe('{"y":2}');
  });

  it('lists all cached variants for a product', async () => {
    await upsertDistribution(db, 'p3', 'a', 'A', '{}', 't');
    await upsertDistribution(db, 'p3', 'b', 'B', '{}', 't');
    expect((await getAllForProduct(db, 'p3')).length).toBe(2);
  });

  it('deletes by product ids', async () => {
    await upsertDistribution(db, 'p4', '', null, '{}', 't');
    await deleteDistributionForProducts(db, ['p4']);
    expect(await getDistribution(db, 'p4', null)).toBeNull();
  });
});
```

- [ ] **Step 3: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/crossLocationStockRepository.test.ts`

- [ ] **Step 4: Implement the repository** (mirror `locationStockRepository.ts` imports + the `vid()` empty-string convention):

```ts
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

export interface DistributionCacheRow {
  product_id: string;
  variant_id: string;
  variant_label: string | null;
  payload: string;
  fetched_at: string;
}

const vid = (v: string | null): string => v ?? '';

export async function upsertDistribution(
  db: Database, productId: string, variantId: string | null,
  variantLabel: string | null, payload: string, fetchedAt: string,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO product_stock_distribution_cache
       (product_id, variant_id, variant_label, payload, fetched_at)
     VALUES ($1, $2, $3, $4, $5)
     ON CONFLICT(product_id, variant_id) DO UPDATE SET
       variant_label = excluded.variant_label,
       payload       = excluded.payload,
       fetched_at    = excluded.fetched_at`,
    [productId, vid(variantId), variantLabel, payload, fetchedAt],
  );
}

export async function getDistribution(
  db: Database, productId: string, variantId: string | null,
): Promise<DistributionCacheRow | null> {
  const row = await queryOne<DistributionCacheRow>(
    db,
    `SELECT product_id, variant_id, variant_label, payload, fetched_at
       FROM product_stock_distribution_cache
      WHERE product_id = $1 AND variant_id = $2`,
    [productId, vid(variantId)],
  );
  return row ?? null;
}

export async function getAllForProduct(db: Database, productId: string): Promise<DistributionCacheRow[]> {
  return queryAll<DistributionCacheRow>(
    db,
    `SELECT product_id, variant_id, variant_label, payload, fetched_at
       FROM product_stock_distribution_cache WHERE product_id = $1`,
    [productId],
  );
}

export async function deleteDistributionForProducts(db: Database, productIds: string[]): Promise<void> {
  if (productIds.length === 0) return;
  const placeholders = productIds.map((_, i) => `$${i + 1}`).join(', ');
  await execute(db, `DELETE FROM product_stock_distribution_cache WHERE product_id IN (${placeholders})`, productIds);
}
```

- [ ] **Step 5: Cascade eviction on tombstone.** In `syncService.ts`, where `deleteLocationStockForProducts(db, deletedIds)` is called after a product tombstone pull, add a sibling call `await deleteDistributionForProducts(db, deletedIds);` (import it). Verify the exact callsite via `grep -n deleteLocationStockForProducts apps/pos/src/lib/sync/syncService.ts`.

- [ ] **Step 6: Run — expect PASS + typecheck.**

```bash
cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/crossLocationStockRepository.test.ts && pnpm typecheck
```

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/db/repositories/crossLocationStockRepository.ts apps/pos/src/lib/db/repositories/__tests__/crossLocationStockRepository.test.ts apps/pos/src/lib/sync/syncService.ts
git commit -m "feat(pos): product_stock_distribution_cache table + repository (v52)"
```

---

### Task F6: API client + types

**Files:**
- Create: `apps/pos/src/types/stockDistribution.ts`, `apps/pos/src/api/stockDistributionApi.ts`

- [ ] **Step 1: Types** (`stockDistribution.ts`):

```ts
export interface StockDistributionRow {
  location_id: string;
  location_name: string;
  location_type: string;
  is_current: boolean;
  on_hand: string;          // decimal string
  incoming_transfer: string; // decimal string
}

export interface StockDistribution {
  product_id: string;
  variant_id: string | null;
  variant_label: string | null;
  locations: StockDistributionRow[];
  totals: { on_hand: string; incoming_transfer: string };
  as_of: string;
}
```

- [ ] **Step 2: API client** (`stockDistributionApi.ts`) — non-paginated, use `apiGet` (unwraps `data`):

```ts
import { apiGet } from '@/lib/api';
import type { StockDistribution } from '@/types/stockDistribution';

export async function fetchStockDistribution(
  productId: string,
  variantId: string | null,
  currentLocationId: string | null,
): Promise<StockDistribution> {
  const params: Record<string, unknown> = {};
  if (variantId) params.variant_id = variantId;
  if (currentLocationId) params.current_location_id = currentLocationId;
  return apiGet<StockDistribution>(`/pos/products/${productId}/stock-distribution`, params);
}
```

- [ ] **Step 3: typecheck + Commit**

```bash
cd apps/pos && pnpm typecheck
git add apps/pos/src/types/stockDistribution.ts apps/pos/src/api/stockDistributionApi.ts
git commit -m "feat(pos): stock-distribution API client + types"
```

---

### Task F7: `useCrossLocationStock` hook (fetch-on-open + cache + staleness)

**Files:**
- Create: `apps/pos/src/hooks/useCrossLocationStock.ts`
- Test: `apps/pos/src/hooks/__tests__/useCrossLocationStock.test.ts` (create)

**Behavior contract:** On `(productId, variantId)` change while the section is mounted: if online → `fetchStockDistribution`, render, `upsertDistribution` with `fetched_at = data.as_of`; if offline or fetch throws → read cache via `getDistribution`. Expose `{ data, source: 'live'|'cache'|null, fetchedAt, isStale, isLoading, error, refresh() }`. `isStale` = `fetchedAt` older than 15 min (reuse `isOlderThan` from `@/lib/relativeTime`).

- [ ] **Step 1: Write the failing test** (mock the api + repo + connectivity + db):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';

vi.mock('@/stores/connectivityStore', () => ({ useConnectivityStore: (sel: any) => sel({ isOnline: true }) }));
vi.mock('@/lib/db', () => ({ getDatabase: vi.fn(async () => ({})) }));
const fetchMock = vi.fn();
vi.mock('@/api/stockDistributionApi', () => ({ fetchStockDistribution: (...a: unknown[]) => fetchMock(...a) }));
const upsertMock = vi.fn(); const getMock = vi.fn();
vi.mock('@/lib/db/repositories/crossLocationStockRepository', () => ({
  upsertDistribution: (...a: unknown[]) => upsertMock(...a),
  getDistribution: (...a: unknown[]) => getMock(...a),
  getAllForProduct: vi.fn(async () => []),
}));

import { useCrossLocationStock } from '@/hooks/useCrossLocationStock';

const payload = { product_id: 'p1', variant_id: null, variant_label: null, locations: [], totals: { on_hand: '0.0000', incoming_transfer: '0.0000' }, as_of: '2026-06-14T10:00:00Z' };

describe('useCrossLocationStock', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetches live when online and caches', async () => {
    fetchMock.mockResolvedValue(payload);
    const { result } = renderHook(() => useCrossLocationStock('p1', null, 'loc1', true));
    await waitFor(() => expect(result.current.data).not.toBeNull());
    expect(result.current.source).toBe('live');
    expect(upsertMock).toHaveBeenCalled();
  });
});
```

> Adjust the connectivity mock to however the hook reads `isOnline` (selector vs whole store). The hook takes `enabled` (section gated-in) as the 4th arg so it does nothing until shown.

- [ ] **Step 2: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/hooks/__tests__/useCrossLocationStock.test.ts`

- [ ] **Step 3: Implement the hook**

```ts
import { useCallback, useEffect, useState } from 'react';
import { getDatabase } from '@/lib/db';
import { fetchStockDistribution } from '@/api/stockDistributionApi';
import { getDistribution, upsertDistribution } from '@/lib/db/repositories/crossLocationStockRepository';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { isOlderThan } from '@/lib/relativeTime';
import type { StockDistribution } from '@/types/stockDistribution';

const STALE_MS = 15 * 60_000;

interface Result {
  data: StockDistribution | null;
  source: 'live' | 'cache' | null;
  fetchedAt: string | null;
  isStale: boolean;
  isLoading: boolean;
  error: 'offline-no-cache' | 'fetch-failed' | null;
  refresh: () => void;
}

export function useCrossLocationStock(
  productId: string | null,
  variantId: string | null,
  currentLocationId: string | null,
  enabled: boolean,
): Result {
  const isOnline = useConnectivityStore((s) => s.isOnline);
  const [data, setData] = useState<StockDistribution | null>(null);
  const [source, setSource] = useState<'live' | 'cache' | null>(null);
  const [fetchedAt, setFetchedAt] = useState<string | null>(null);
  const [isLoading, setLoading] = useState(false);
  const [error, setError] = useState<Result['error']>(null);
  const [nonce, setNonce] = useState(0);

  const refresh = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    if (!enabled || !productId) return;
    let cancelled = false;

    async function load(): Promise<void> {
      setLoading(true);
      setError(null);
      const db = await getDatabase();

      if (isOnline) {
        try {
          const live = await fetchStockDistribution(productId as string, variantId, currentLocationId);
          if (cancelled) return;
          setData(live); setSource('live'); setFetchedAt(live.as_of);
          await upsertDistribution(db, live.product_id, live.variant_id, live.variant_label, JSON.stringify(live), live.as_of);
          return;
        } catch {
          // fall through to cache
        }
      }

      const cached = await getDistribution(db, productId as string, variantId);
      if (cancelled) return;
      if (cached) {
        setData(JSON.parse(cached.payload) as StockDistribution);
        setSource('cache'); setFetchedAt(cached.fetched_at);
        setError(isOnline ? 'fetch-failed' : null);
      } else {
        setData(null); setSource(null); setFetchedAt(null);
        setError(isOnline ? 'fetch-failed' : 'offline-no-cache');
      }
    }

    void load().finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [enabled, productId, variantId, currentLocationId, isOnline, nonce]);

  return {
    data, source, fetchedAt,
    isStale: fetchedAt !== null && isOlderThan(fetchedAt, STALE_MS),
    isLoading, error, refresh,
  };
}
```

> Verify `isOlderThan` accepts an ISO string (it does in `StockFreshness`); if it expects ms, convert. Verify `getDatabase` import path (`@/lib/db`).

- [ ] **Step 4: Run — expect PASS + typecheck.**

```bash
cd apps/pos && pnpm vitest run src/hooks/__tests__/useCrossLocationStock.test.ts && pnpm typecheck
```

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/hooks/useCrossLocationStock.ts apps/pos/src/hooks/__tests__/useCrossLocationStock.test.ts
git commit -m "feat(pos): useCrossLocationStock hook (fetch-on-open + cache + staleness)"
```

---

### Task F8: `CrossLocationStockSection` component + drawer integration

**Files:**
- Create: `apps/pos/src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx`
- Create: `apps/pos/src/components/organisms/CrossLocationStockSection/__tests__/CrossLocationStockSection.test.tsx`
- Modify: `apps/pos/src/components/pos/ProductDetailDrawer.tsx` (render the section, gated)

**Gating:** the section renders only when `companyConfig?.allow_cross_location_stock_view === true` AND the operator has `pos.view_cross_location_stock`. Read the flag from `productStore.companyConfig`; read permissions from the operator/auth store (`permissions: string[]`). Variant options: online → `useProductVariants(productId)`; offline → derive from `getAllForProduct` cache (label + id). Default selected variant: the default/first.

- [ ] **Step 1: Write failing tests** (gate hidden when flag off / perm missing; table renders rows; offline shows cached "as of" + refresh):

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string, o?: any) => (o?.time ? `${k}:${o.time}` : k) }) }));
const hookMock = vi.fn();
vi.mock('@/hooks/useCrossLocationStock', () => ({ useCrossLocationStock: (...a: unknown[]) => hookMock(...a) }));
vi.mock('@/hooks/useProductVariants', () => ({ useProductVariants: () => ({ data: [], isLoading: false }) }));

import { CrossLocationStockSection } from '@/components/organisms/CrossLocationStockSection/CrossLocationStockSection';

const product = { id: 'p1', name: 'Widget', has_variants: false } as any;

describe('CrossLocationStockSection gating', () => {
  it('renders nothing when not allowed', () => {
    hookMock.mockReturnValue({ data: null, source: null, fetchedAt: null, isStale: false, isLoading: false, error: null, refresh: vi.fn() });
    const { container } = render(<CrossLocationStockSection product={product} canView={false} currentLocationId="l1" />);
    expect(container).toBeEmptyDOMElement();
  });

  it('renders location rows when data present', () => {
    hookMock.mockReturnValue({
      data: { product_id: 'p1', variant_id: null, variant_label: null,
        locations: [{ location_id: 'l1', location_name: 'Shop A', location_type: 'shop', is_current: true, on_hand: '12.0000', incoming_transfer: '5.0000' }],
        totals: { on_hand: '12.0000', incoming_transfer: '5.0000' }, as_of: '2026-06-14T10:00:00Z' },
      source: 'live', fetchedAt: '2026-06-14T10:00:00Z', isStale: false, isLoading: false, error: null, refresh: vi.fn(),
    });
    render(<CrossLocationStockSection product={product} canView currentLocationId="l1" />);
    expect(screen.getByText('Shop A')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/components/organisms/CrossLocationStockSection`

- [ ] **Step 3: Implement the component** (uses `formatAvailableQty` for quantities, `formatRelativeTime` for the "as of", design tokens — no hardcoded color classes beyond the established stock tones; all text via `t()`):

```tsx
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { RefreshCw } from 'lucide-react';
import { formatAvailableQty } from '@/lib/stock/stockGate';
import { formatRelativeTime } from '@/lib/relativeTime';
import { useCrossLocationStock } from '@/hooks/useCrossLocationStock';
import { useProductVariants } from '@/hooks/useProductVariants';
import type { POSProduct } from '@/types/product';

interface Props {
  product: POSProduct;
  canView: boolean;
  currentLocationId: string | null;
}

export function CrossLocationStockSection({ product, canView, currentLocationId }: Props) {
  const { t } = useTranslation('pos');
  const hasVariants = product.has_variants === true;
  const { data: variants } = useProductVariants(hasVariants && canView ? product.id : null);
  const [selectedVariant, setSelectedVariant] = useState<string | null>(null);

  const variantId = useMemo(() => {
    if (!hasVariants) return null;
    if (selectedVariant) return selectedVariant;
    const def = variants?.find((v) => v.is_default) ?? variants?.[0];
    return def?.id ?? null;
  }, [hasVariants, selectedVariant, variants]);

  const enabled = canView && (!hasVariants || variantId !== null);
  const { data, fetchedAt, isStale, isLoading, error, refresh } =
    useCrossLocationStock(product.id, variantId, currentLocationId, enabled);

  if (!canView) return null;

  return (
    <section className="mt-4 border-t border-gray-200 pt-3" data-testid="xloc-section">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">{t('crossLocationStock.title')}</h3>
        <button type="button" onClick={refresh} className="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700" title={t('crossLocationStock.refresh')}>
          <RefreshCw className="h-3.5 w-3.5" /> {t('crossLocationStock.refresh')}
        </button>
      </div>

      {hasVariants && (
        <select
          aria-label={t('crossLocationStock.variant')}
          className="mb-2 w-full rounded-md border border-gray-200 px-2 py-1 text-sm"
          value={variantId ?? ''}
          onChange={(e) => setSelectedVariant(e.target.value)}
        >
          {(variants ?? []).map((v) => (
            <option key={v.id} value={v.id}>{product.name}{v.name_suffix}</option>
          ))}
        </select>
      )}

      {fetchedAt && (
        <p className={`mb-1 text-xs ${isStale ? 'text-amber-600' : 'text-gray-500'}`}>
          {t('crossLocationStock.asOf', { time: formatRelativeTime(fetchedAt) ?? '' })}
        </p>
      )}

      {isLoading && <p className="text-xs text-gray-500">{t('crossLocationStock.loading')}</p>}
      {!isLoading && error === 'offline-no-cache' && (
        <p className="text-xs text-gray-500">{t('crossLocationStock.offlineNoCache')}</p>
      )}

      {data && (
        <table className="w-full text-sm" data-testid="xloc-table">
          <thead>
            <tr className="text-left text-xs text-gray-500">
              <th className="py-1">{t('crossLocationStock.location')}</th>
              <th className="py-1 text-right">{t('crossLocationStock.onHand')}</th>
              <th className="py-1 text-right">{t('crossLocationStock.incoming')}</th>
            </tr>
          </thead>
          <tbody>
            {data.locations.map((row) => (
              <tr key={row.location_id} className={row.is_current ? 'font-semibold' : ''}>
                <td className="py-0.5">{row.is_current ? `→ ${row.location_name}` : row.location_name}</td>
                <td className="py-0.5 text-right">{formatAvailableQty(row.on_hand)}</td>
                <td className="py-0.5 text-right">{formatAvailableQty(row.incoming_transfer)}</td>
              </tr>
            ))}
            <tr className="border-t border-gray-200 font-semibold">
              <td className="py-1">{t('crossLocationStock.total')}</td>
              <td className="py-1 text-right">{formatAvailableQty(data.totals.on_hand)}</td>
              <td className="py-1 text-right">{formatAvailableQty(data.totals.incoming_transfer)}</td>
            </tr>
          </tbody>
        </table>
      )}
    </section>
  );
}
```

- [ ] **Step 4: Integrate into the drawer.** In `ProductDetailDrawer.tsx`, compute `canView` and render the section near the bottom of the drawer body. The drawer needs the flag + permission; read them from stores (keeps the drawer self-contained):

```tsx
import { useProductStore } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { CrossLocationStockSection } from '@/components/organisms/CrossLocationStockSection/CrossLocationStockSection';
import { useTerminalStore } from '@/stores/terminalStore';
// ...
const allow = useProductStore((s) => s.companyConfig?.allow_cross_location_stock_view === true);
const canPermit = useOperatorStore((s) => s.permissions?.includes('pos.view_cross_location_stock') ?? false);
const currentLocationId = useTerminalStore((s) => s.terminal?.location_id ?? null);
// ... inside the drawer body, after the existing detail rows:
{product && (
  <CrossLocationStockSection product={product} canView={allow && canPermit} currentLocationId={currentLocationId} />
)}
```

> Verify the operator/auth store that holds `permissions` (recon shows both `authStore.user.permissions` and `operatorStore.permissions`). Use whichever is populated for the active cashier — `grep -n "permissions" apps/pos/src/stores/operatorStore.ts`. Verify `terminalStore` exposes `terminal.location_id`.

- [ ] **Step 5: Run — expect PASS + typecheck + lint.**

```bash
cd apps/pos && pnpm vitest run src/components/organisms/CrossLocationStockSection && pnpm typecheck && pnpm lint src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx
```

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/components/organisms/CrossLocationStockSection apps/pos/src/components/pos/ProductDetailDrawer.tsx
git commit -m "feat(pos): CrossLocationStockSection (gated, variant drill-down) in drawer"
```

---

### Task F9: i18n keys + two-locale smoke test (L2)

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json`
- Create: `apps/pos/src/__tests__/crossLocationStockI18n.test.tsx`

- [ ] **Step 1: Add keys to BOTH locale files.** Under a new `crossLocationStock` object and the `products.viewDetails` key. English (`en/pos.json`):

```json
"crossLocationStock": {
  "title": "Stock across locations",
  "variant": "Variant",
  "location": "Location",
  "onHand": "On-hand",
  "incoming": "In-transit",
  "total": "Total",
  "asOf": "As of {{time}}",
  "refresh": "Refresh",
  "loading": "Loading…",
  "offlineNoCache": "Unavailable offline — connect to refresh"
}
```

French (`fr/pos.json`) — same keys, translated (`"title": "Stock par emplacement"`, `"onHand": "En stock"`, `"incoming": "En transit"`, `"total": "Total"`, `"asOf": "Au {{time}}"`, `"refresh": "Actualiser"`, `"loading": "Chargement…"`, `"offlineNoCache": "Indisponible hors ligne — connectez-vous pour actualiser"`, `"variant": "Variante"`, `"location": "Emplacement"`). Also add `"viewDetails": "View details"` / `"viewDetails": "Voir les détails"` under the existing `products` object in each file.

- [ ] **Step 2: Write the smoke test** (mirror `syncIndicatorI18n.test.tsx` — direct `i18n.t()` in both locales):

```tsx
import { describe, it, expect } from 'vitest';
import i18n from '@/i18n';

const KEYS = ['title', 'variant', 'location', 'onHand', 'incoming', 'total', 'asOf', 'refresh', 'loading', 'offlineNoCache'];

describe('crossLocationStock i18n', () => {
  for (const lng of ['en', 'fr']) {
    for (const k of KEYS) {
      it(`${lng}: crossLocationStock.${k} resolves`, async () => {
        await i18n.changeLanguage(lng);
        const val = i18n.t(`pos:crossLocationStock.${k}`);
        expect(val).not.toBe(`crossLocationStock.${k}`);
        expect(val).not.toContain('crossLocationStock.');
      });
    }
  }
});
```

- [ ] **Step 3: Run — expect PASS.** `cd apps/pos && pnpm vitest run src/__tests__/crossLocationStockI18n.test.tsx`

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json apps/pos/src/__tests__/crossLocationStockI18n.test.tsx
git commit -m "feat(pos): cross-location stock i18n (en+fr) + smoke test (L2)"
```

---

## Final verification (scoped — do NOT run the full suites)

- [ ] Backend touched files: `cd apps/api && ./vendor/bin/phpstan analyse <changed files> && ./vendor/bin/pint --test app/Modules/POS app/Modules/Inventory app/Shared && php artisan test --filter 'StockDistribution|CrossLocationStock|CompanyConfigCrossLocationFlag'`
- [ ] Frontend: `cd apps/pos && pnpm typecheck && pnpm lint src/components/organisms/CrossLocationStockSection src/components/molecules/ProductCard src/hooks/useCrossLocationStock.ts && pnpm vitest run src/components/organisms/CrossLocationStockSection src/components/molecules/ProductCard/__tests__ src/components/pos/__tests__ src/hooks/__tests__/useCrossLocationStock.test.ts src/lib/db/repositories/__tests__/crossLocationStockRepository.test.ts src/lib/__tests__/companyConfigCache.test.ts src/__tests__/crossLocationStockI18n.test.tsx`
- [ ] Manual Tauri smoke (post-merge): enable the company flag + grant a manager the permission, open a product's eye → drawer → cross-location section; verify online fetch, offline cached "as of" + amber stale, Refresh, and that a base cashier (no permission) sees no section.

## Notes / decisions baked in
- **Variant offline edge:** the variant **selector** needs the server variant list (POS doesn't persist variants); offline it falls back to cached variants (`getAllForProduct`) so previously-viewed variants still render. The endpoint returns `variant_label` so cached rows stay human-readable. (Spec §9 open item — resolved here.)
- **Incoming = transfers only** (`incoming_transfer`), per spec M2; PO incoming deferred.
- **Device authority:** read-only; no shift/fiscal writes; cache is per-device, advisory, overwritten on each fetch.
- **No N+1** (M4): the service uses one locations query + one grouped stock query + one grouped transfer query.
