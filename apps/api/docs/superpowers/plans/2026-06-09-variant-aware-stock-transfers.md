# Variant-Aware Stock Transfers (G2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retrofit the stock-transfer feature so a transfer line can target a specific product variant (SKU): it moves variant-level stock, preserves variant-scoped batches, and capitalizes freight into the company-wide product WAC exactly as today.

**Architecture:** Mirror the T2 variant-scoping convention already in `StockAdjustmentService` (a nullable `?string $variantId` threaded through, stock rows resolved via `when($variantId, where('variant_id',$v), whereNull('variant_id'))`, no-mixed-mode enforced by `assertVariantConsistency`). WAC stays **product-grain** (locked §6.7): `recordCostAdjustment`, the `ProductCostLock` advisory key, and `companyOwnedQuantity` are unchanged — variants share one company-wide cost. The transfer line gains a nullable `variant_id`; the service passes it to `issue()`/`receive()`; batch queries gain variant scoping. Cross-DB-FK and partial-unique idioms copy the existing T2 migrations verbatim.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PostgreSQL 16 (partial-unique + advisory locks), PHPUnit (RefreshDatabase + real Eloquent + seeded permissions), bcmath for money/qty, Vitest + React 19 for the web admin UI.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.variant-transfer` — branch `feat/variant-aware-transfers`. All paths below are relative to this worktree; backend lives under `apps/api/`, web admin under `apps/web/`.

---

## Context the implementer needs

- **The gap:** `StockTransferService::moveSourceToInTransit()`/`completeLocked()`/`cancel()` call `StockAdjustmentService::issue()`/`receive()` **without** a `variantId`. Those methods call `assertVariantConsistency($productId, null)`, which throws `VariantRequiredException` when the product has ≥1 active variant. So **transferring any variant-bearing product currently fails** (and 500s, because the controller does not catch `\DomainException`).
- **WAC is product-grain.** Do **NOT** add a `variantId` to `WeightedAverageCostService::recordCostAdjustment`, to `ProductCostLock::acquire` (keyed by `[$productId]`), or to `companyOwnedQuantity` (sums every stock_level row for the product across all variants + in-transit). `capitalizeTransferCost()` needs **no change**.
- **Stock-row idiom** (copy exactly): `->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))`. Never `where('variant_id', null)`.
- **Variant lookup contract:** `App\Shared\Contracts\ProductVariantLookup::findById(string $id): ?ProductVariantSummary`. `ProductVariantSummary` exposes `->productId`, `->isActive`, `->companyId`, `->sku`, `->nameSuffix`. Bound to `EloquentProductVariantLookup` in `CatalogServiceProvider`. Inject the **interface**, never the Catalog Eloquent model (Rule 6).
- **No-mixed-mode predicate:** "product requires a variant" ≡ `ProductVariantLookup::listForProduct($productId, true)->isNotEmpty()` (has ≥1 active variant). The service must reject a `null` variant on such a product with an `InvalidArgumentException` (→ 422) **before** the seam is reached, so we never surface the uncaught `VariantRequiredException`.
- **Cost snapshot stays product-grain.** `unit_cost_snapshot` keeps reading `product->cost_price` (NOT `variant.cost_override`). Rationale: WAC and the pro-rata-value weighting are product-grain; the variant cost_override is advisory only and must not shard the weighting.
- **Batches are variant-scoped** (`product_batches.variant_id` exists, partial-unique `(company_id, product_id, variant_id, batch_number)`). When a line has a variant, its batch allocations must reference batches with that `variant_id`, and the FEFO query must filter on it.
- **Transfers are web-admin only** — there is no POS transfer surface, so no POS changes.

---

## File Structure

**Backend — modify:**
- `apps/api/database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php` *(create)* — nullable `variant_id` + variant-aware unique.
- `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php` — `variant_id` fillable + property + `variant()` relation comment.
- `apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferLineData.php` — add `?string $variantId = null`.
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` — thread `variantId` through initiate/complete/cancel + batch queries + variant validation + duplicate-key by `(product,variant)`.
- `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php` — `lines.*.variant_id` validation.
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` — parse `variant_id` into the DTO; emit variant fields in `formatTransfer`.

**Backend — create (tests):**
- `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php` — the variant-aware behaviour suite.

**Frontend — modify:**
- `apps/web/src/features/stock-transfers/types.ts` — `variant_id` on the line input + detail line.
- `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` — variant selector per line + payload plumbing.
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx` — show variant name/SKU per line.
- `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.variants.test.tsx` *(create)*.
- `apps/web/src/locales/{en,fr}/stock-transfers.json` — variant labels.

---

## Phase 1 — Schema, model, DTO

### Task 1: Migration — add `variant_id` to `stock_transfer_lines`

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php`

- [ ] **Step 1: Write the migration** (mirrors `2026_06_02_100008_add_variant_id_to_product_batches.php`: online DDL, FK `NOT VALID`, partial-unique replacing the plain unique).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2 — make stock transfer lines variant-aware.
 *
 * A line may now target a specific product variant. variant_id is a nullable
 * uuid (NULL = product-level line, matching the legacy behaviour and the
 * non-variant stock_levels row). The old (transfer_id, product_id) unique is
 * replaced by two partial uniques so the SAME product can appear on multiple
 * lines under different variants while still forbidding a duplicate
 * (transfer, product, variant) line.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table): void {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite (tests): drop the old composite unique, add partial uniques.
            DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_transfer_product_unique');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_non_variant
                ON stock_transfer_lines (transfer_id, product_id) WHERE variant_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_with_variant
                ON stock_transfer_lines (transfer_id, product_id, variant_id) WHERE variant_id IS NOT NULL');

            return;
        }

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_transfer_product_unique');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_transfer_lines_non_variant
            ON stock_transfer_lines (transfer_id, product_id) WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_transfer_lines_with_variant
            ON stock_transfer_lines (transfer_id, product_id, variant_id) WHERE variant_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_transfer_lines_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_transfer_lines_non_variant');
            DB::statement('ALTER TABLE stock_transfer_lines DROP CONSTRAINT IF EXISTS stock_transfer_lines_variant_id_foreign');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_transfer_product_unique
                ON stock_transfer_lines (transfer_id, product_id)');
        } else {
            DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_with_variant');
            DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_non_variant');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_transfer_product_unique
                ON stock_transfer_lines (transfer_id, product_id)');
        }

        Schema::table('stock_transfer_lines', function (Blueprint $table): void {
            $table->dropColumn('variant_id');
        });
    }
};
```

- [ ] **Step 2: Run the migration against the SQLite test DB to confirm it applies**

Run: `cd apps/api && php artisan migrate --env=testing --path=database/migrations/tenant 2>&1 | tail -20`
Expected: the new migration runs without error (or, under `RefreshDatabase`, defer verification to Task 2's test run).

- [ ] **Step 3: Commit**

```bash
git add apps/api/database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php
git commit -m "feat(inventory): add variant_id to stock_transfer_lines (G2 schema)"
```

### Task 2: `StockTransferLine` model — expose `variant_id`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php`

- [ ] **Step 1: Add the property docblock line** after `* @property string $product_id` (line ~22):

```php
 * @property string|null $variant_id
```

- [ ] **Step 2: Add `variant_id` to `$fillable`** (after `'product_id',`):

```php
        'product_id',
        'variant_id',
        'quantity',
```

- [ ] **Step 3: Commit**

```bash
git add apps/api/app/Modules/Inventory/Domain/StockTransferLine.php
git commit -m "feat(inventory): StockTransferLine exposes variant_id"
```

### Task 3: DTO — `InitiateTransferLineData.variantId`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferLineData.php`

- [ ] **Step 1: Add the optional `variantId` constructor property** (keep `$batchAllocations` last so existing positional callers in tests still compile; add `variantId` before it with a default):

```php
    public function __construct(
        public readonly string $productId,
        public readonly string $quantity,
        public readonly ?string $variantId = null,
        public readonly array $batchAllocations = [],
    ) {}
```

> Note: the controller (Task 11) and tests construct this with **named arguments**, so the parameter order is safe to extend.

- [ ] **Step 2: Commit**

```bash
git add apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferLineData.php
git commit -m "feat(inventory): InitiateTransferLineData carries variantId"
```

---

## Phase 2 — Test scaffold + variant-aware `initiate`

### Task 4: Test scaffold with a variant-bearing product

**Files:**
- Create: `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

This suite reuses the `InventoryTransferServiceTest` setup idiom (real Tenant/Company/Location/User, `RolesAndPermissionsSeeder`, `CompanyContext`) and adds a product with two active variants plus a helper to seed variant-scoped stock.

- [ ] **Step 1: Write the test-class skeleton + setUp + helpers (no test methods yet)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockTransferVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Location $warehouse;
    private Location $shop;
    private Product $product;
    private ProductVariant $variantA;
    private ProductVariant $variantB;
    private StockTransferService $service;
    private StockAdjustmentService $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Variant Tenant', 'slug' => 'variant-tenant',
            'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Acme', 'legal_name' => 'Acme LLC',
            'tax_id' => 'TAX-ACME', 'country_code' => 'TN', 'currency' => 'TND',
            'locale' => 'fr_TN', 'timezone' => 'Africa/Tunis', 'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@e.com',
            'password' => bcrypt('password'), 'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id, 'code' => 'WH-01', 'name' => 'WH',
            'type' => 'warehouse', 'is_active' => true, 'is_default' => true,
        ]);
        $this->shop = Location::create([
            'company_id' => $this->company->id, 'code' => 'SH-01', 'name' => 'Shop',
            'type' => 'shop', 'is_active' => true, 'is_default' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'sku' => 'TSHIRT', 'name' => 'T-Shirt', 'type' => ProductType::Part,
            'is_active' => true, 'cost_price' => '5.0000', 'sale_price' => '10.0000',
        ]);
        $this->variantA = $this->makeVariant('RED-L');
        $this->variantB = $this->makeVariant('BLU-M');

        $this->service = app(StockTransferService::class);
        $this->stockService = app(StockAdjustmentService::class);
    }

    private function makeVariant(string $code): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $this->product->id, 'variant_code' => $code,
            'sku' => $this->product->sku.'-'.$code, 'barcode' => null,
            'name_suffix' => $code, 'is_default' => false, 'is_active' => true,
            'display_order' => 0, 'price_override' => null, 'cost_override' => null,
            'image_url' => null,
        ]);
    }

    /** Seed variant-scoped on-hand stock at a location via the variant-aware receive(). */
    private function seedVariantStock(ProductVariant $variant, Location $loc, string $qty): void
    {
        $this->stockService->receive(
            productId: $this->product->id,
            locationId: $loc->id,
            quantity: $qty,
            reference: 'SEED',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );
    }

    private function variantStockQty(ProductVariant $variant, Location $loc): string
    {
        $level = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $loc->id)
            ->first();

        return $level === null ? '0.0000' : (string) $level->quantity;
    }
}
```

- [ ] **Step 2: Run the empty class to confirm setUp boots**

Run: `cd apps/api && php artisan test --filter StockTransferVariantTest 2>&1 | tail -15`
Expected: "No tests found" or a green run with 0 assertions — confirms imports/setUp compile. (If `RefreshDatabase` errors on the new migration, fix Task 1 first.)

- [ ] **Step 3: Commit**

```bash
git add apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "test(inventory): scaffold variant-aware transfer suite"
```

### Task 5: RED — variant initiate decrements the variant row

**Files:**
- Modify (test): `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

- [ ] **Step 1: Add the failing test**

```php
    public function test_initiate_moves_only_the_targeted_variant_stock(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '10');
        $this->seedVariantStock($this->variantB, $this->warehouse, '7');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(
                    productId: $this->product->id,
                    quantity: '4',
                    variantId: $this->variantA->id,
                ),
            ],
        ));

        $this->assertSame(TransferStatus::InTransit, $transfer->status);
        // Variant A decremented at source; variant B untouched.
        $this->assertSame('6.0000', $this->variantStockQty($this->variantA, $this->warehouse));
        $this->assertSame('7.0000', $this->variantStockQty($this->variantB, $this->warehouse));
        // The line persisted the variant_id.
        $this->assertSame($this->variantA->id, $transfer->lines->first()->variant_id);
        // The out-movement carries the variant_id.
        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $this->variantA->id)
            ->where('location_id', $this->warehouse->id)
            ->where('movement_type', 'transfer_out')
            ->first();
        $this->assertNotNull($movement);
    }
```

- [ ] **Step 2: Run it — expect RED**

Run: `cd apps/api && php artisan test --filter test_initiate_moves_only_the_targeted_variant_stock 2>&1 | tail -25`
Expected: FAIL — `VariantRequiredException` (the seam rejects the null variant) OR the assertion on `variant_id` being null.

- [ ] **Step 3: Make `initiate` variant-aware** — edit `StockTransferService.php`:

(a) In `collectProductIds()` (around line 795) dedupe by `(productId, variantId)` so the same product may appear under different variants, and only reject a true duplicate line:

```php
    private function collectProductIds(array $lines): array
    {
        $seen = [];
        $productIds = [];
        foreach ($lines as $line) {
            $key = $line->productId.'|'.($line->variantId ?? '');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate product/variant on transfer lines: '.$line->productId);
            }
            $seen[$key] = true;
            $productIds[$line->productId] = true;
        }

        return array_keys($productIds);
    }
```

(b) In the `initiate()` line loop (around line 149) persist `variant_id`, validate the variant, and reject a missing variant on a variant-bearing product:

```php
            foreach ($data->lines as $line) {
                if (bccomp($line->quantity, '0', self::QTY_SCALE) <= 0) {
                    throw new InvalidArgumentException('Each transfer line must have quantity greater than zero.');
                }

                $this->assertVariantValidForProduct($line->productId, $line->variantId);

                $transferLine = StockTransferLine::create([
                    'id' => Str::uuid()->toString(),
                    'transfer_id' => $transfer->id,
                    'tenant_id' => $data->tenantId,
                    'company_id' => $data->companyId,
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'quantity' => $line->quantity,
                ]);
                // ... batch allocations loop unchanged ...
```

(c) Add the new validation helper + inject the lookup. At the top, add the import and constructor dependency:

```php
use App\Shared\Contracts\ProductVariantLookup;
```

```php
    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly WeightedAverageCostService $wacService,
        private readonly ProductCostLock $costLock,
        private readonly ProductVariantLookup $variantLookup,
    ) {}
```

```php
    /**
     * Validate the line's variant addressing:
     *  - if a variantId is supplied, it must exist, be active, and belong to
     *    the product;
     *  - if it is null but the product has active variants, reject (mirrors
     *    StockAdjustmentService::assertVariantConsistency, but surfaced as an
     *    InvalidArgumentException so the controller maps it to 422 instead of
     *    letting the seam's uncaught VariantRequiredException 500).
     */
    private function assertVariantValidForProduct(string $productId, ?string $variantId): void
    {
        if ($variantId === null) {
            if ($this->variantLookup->listForProduct($productId, true)->isNotEmpty()) {
                throw new InvalidArgumentException(
                    "Product {$productId} has active variants; a variant_id is required for the transfer line."
                );
            }

            return;
        }

        $summary = $this->variantLookup->findById($variantId);
        if ($summary === null || ! $summary->isActive || $summary->productId !== $productId) {
            throw new InvalidArgumentException(
                "Variant {$variantId} is invalid for product {$productId}."
            );
        }
    }
```

(d) In `moveSourceToInTransit()` make the availability read variant-scoped and pass `variantId` to `issue()`. Change the stock_level lookup (around line 395):

```php
            $stockLevel = StockLevel::query()
                ->where('product_id', $product->id)
                ->where('location_id', $transfer->source_location_id)
                ->where('company_id', $transfer->company_id)
                ->when(
                    $line->variant_id !== null,
                    fn ($q) => $q->where('variant_id', $line->variant_id),
                    fn ($q) => $q->whereNull('variant_id'),
                )
                ->lockForUpdate()
                ->first();
```

and pass `variantId: $line->variant_id` to BOTH `issue()` calls (batch and non-batch branches, around lines 426 and 439):

```php
                    $movement = $this->stockAdjustmentService->issue(
                        productId: $product->id,
                        locationId: $transfer->source_location_id,
                        quantity: (string) $allocation->quantity,
                        reference: $reference,
                        userId: $userId,
                        batchId: $allocation->batch_id,
                        expectedCompanyId: $transfer->company_id,
                        variantId: $line->variant_id,
                    );
```

```php
                $movement = $this->stockAdjustmentService->issue(
                    productId: $product->id,
                    locationId: $transfer->source_location_id,
                    quantity: (string) $line->quantity,
                    reference: $reference,
                    userId: $userId,
                    expectedCompanyId: $transfer->company_id,
                    variantId: $line->variant_id,
                );
```

- [ ] **Step 4: Run the test — expect GREEN**

Run: `cd apps/api && php artisan test --filter test_initiate_moves_only_the_targeted_variant_stock 2>&1 | tail -20`
Expected: PASS.

- [ ] **Step 5: Run the existing transfer suite to confirm no product-level regression**

Run: `cd apps/api && php artisan test --filter InventoryTransferServiceTest 2>&1 | tail -20`
Expected: PASS (product-level transfers still work — `variant_id` defaults to null end-to-end).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "feat(inventory): variant-aware transfer initiate (issue + validation)"
```

### Task 6: RED — missing variant on a variant product is a 422-style error, not a 500

**Files:**
- Modify (test): `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

- [ ] **Step 1: Add the tests**

```php
    public function test_initiate_without_variant_on_variant_product_throws_invalid_argument(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '5');

        $this->expectException(InvalidArgumentException::class);

        $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: '1'),
            ],
        ));
    }

    public function test_initiate_with_foreign_variant_throws_invalid_argument(): void
    {
        $otherProduct = Product::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'sku' => 'OTHER', 'name' => 'Other', 'type' => ProductType::Part,
            'is_active' => true, 'cost_price' => '1.0000', 'sale_price' => '2.0000',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                // variantA belongs to $this->product, not $otherProduct.
                new InitiateTransferLineData(
                    productId: $otherProduct->id, quantity: '1', variantId: $this->variantA->id,
                ),
            ],
        ));
    }

    public function test_same_product_two_variants_on_one_transfer_is_allowed(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '5');
        $this->seedVariantStock($this->variantB, $this->warehouse, '5');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: '2', variantId: $this->variantA->id),
                new InitiateTransferLineData(productId: $this->product->id, quantity: '3', variantId: $this->variantB->id),
            ],
        ));

        $this->assertCount(2, $transfer->lines);
        $this->assertSame('3.0000', $this->variantStockQty($this->variantA, $this->warehouse));
        $this->assertSame('2.0000', $this->variantStockQty($this->variantB, $this->warehouse));
    }
```

- [ ] **Step 2: Run — expect GREEN** (Task 5's implementation already covers these)

Run: `cd apps/api && php artisan test --filter StockTransferVariantTest 2>&1 | tail -25`
Expected: all PASS. If `test_same_product_two_variants_on_one_transfer_is_allowed` fails on a unique-constraint violation, re-check the Task 1 partial-unique indexes and the Task 5(a) `collectProductIds` change.

- [ ] **Step 3: Commit**

```bash
git add apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "test(inventory): variant addressing guards on transfer initiate"
```

---

## Phase 3 — Variant-aware `complete` and `cancel`

### Task 7: RED — complete increments the destination variant row + WAC stays product-grain

**Files:**
- Modify (test): `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

- [ ] **Step 1: Add the test**

```php
    public function test_complete_increments_destination_variant_and_capitalizes_product_wac(): void
    {
        // Company-wide on-hand at cost 5: 10 of A + 10 of B = 20 units, avg 5.
        $this->seedVariantStock($this->variantA, $this->warehouse, '10');
        $this->seedVariantStock($this->variantB, $this->warehouse, '10');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: '4', variantId: $this->variantA->id),
            ],
            transferCost: '40',
        ));

        $completed = $this->service->complete($transfer->id, $this->user->id);

        $this->assertSame(TransferStatus::Completed, $completed->status);
        // Destination variant-A row got the 4 units; variant B never appears at the shop.
        $this->assertSame('4.0000', $this->variantStockQty($this->variantA, $this->shop));
        $this->assertSame('0.0000', $this->variantStockQty($this->variantB, $this->shop));

        // WAC is PRODUCT-grain: freight 40 / company on-hand 20 = +2.00 → 5 + 2 = 7.
        $this->product->refresh();
        $this->assertSame('7.0000', (string) $this->product->cost_price);
    }
```

> The `7.0000` figure encodes the product-grain invariant: the freight is spread over the **whole product's** 20 on-hand units (10 A + 10 B), not just variant A. If complete erroneously scoped the WAC denominator to variant A's 10 units you would see `9.0000` — the test would catch it.

- [ ] **Step 2: Run — expect RED** (`receive()` throws `VariantRequiredException` because `completeLocked` passes no variant)

Run: `cd apps/api && php artisan test --filter test_complete_increments_destination_variant 2>&1 | tail -25`
Expected: FAIL.

- [ ] **Step 3: Thread `variantId` through `completeLocked()`** — in `StockTransferService.php`, pass `variantId: $line->variant_id` to BOTH `receive()` calls (batch and non-batch branches, around lines 222 and 235):

```php
                    $movement = $this->stockAdjustmentService->receive(
                        productId: $product->id,
                        locationId: $transfer->destination_location_id,
                        quantity: (string) $allocation->quantity,
                        reference: $reference,
                        userId: $userId,
                        batchId: $allocation->batch_id,
                        expectedCompanyId: $transfer->company_id,
                        variantId: $line->variant_id,
                    );
```

```php
                $movement = $this->stockAdjustmentService->receive(
                    productId: $product->id,
                    locationId: $transfer->destination_location_id,
                    quantity: (string) $line->quantity,
                    reference: $reference,
                    userId: $userId,
                    expectedCompanyId: $transfer->company_id,
                    variantId: $line->variant_id,
                );
```

> Leave `capitalizeTransferCost()` and the `recordCostAdjustment` call **unchanged** — WAC is product-grain.

- [ ] **Step 4: Run — expect GREEN**

Run: `cd apps/api && php artisan test --filter test_complete_increments_destination_variant 2>&1 | tail -20`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "feat(inventory): variant-aware transfer complete (receive); WAC stays product-grain"
```

### Task 8: RED — cancel-in-transit returns stock to the source variant row

**Files:**
- Modify (test): `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

- [ ] **Step 1: Add the test**

```php
    public function test_cancel_in_transit_restocks_the_source_variant(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '10');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: '4', variantId: $this->variantA->id),
            ],
        ));
        $this->assertSame('6.0000', $this->variantStockQty($this->variantA, $this->warehouse));

        $cancelled = $this->service->cancel($transfer->id, $this->user->id, 'changed mind');

        $this->assertSame(TransferStatus::Cancelled, $cancelled->status);
        // The 4 in-flight units returned to the source variant row.
        $this->assertSame('10.0000', $this->variantStockQty($this->variantA, $this->warehouse));
        // Nothing leaked to the destination.
        $this->assertSame('0.0000', $this->variantStockQty($this->variantA, $this->shop));
    }
```

- [ ] **Step 2: Run — expect RED**

Run: `cd apps/api && php artisan test --filter test_cancel_in_transit_restocks_the_source_variant 2>&1 | tail -25`
Expected: FAIL (`VariantRequiredException` from `receive()` in the cancel restock loop).

- [ ] **Step 3: Thread `variantId` through `cancel()`** — pass `variantId: $line->variant_id` to BOTH `receive()` calls inside the `InTransit` restock loop (around lines 316 and 329):

```php
                                $movement = $this->stockAdjustmentService->receive(
                                    productId: $line->product_id,
                                    locationId: $transfer->source_location_id,
                                    quantity: (string) $allocation->quantity,
                                    reference: $cancelReference,
                                    userId: $userId,
                                    batchId: $allocation->batch_id,
                                    expectedCompanyId: $transfer->company_id,
                                    variantId: $line->variant_id,
                                );
```

```php
                            $movement = $this->stockAdjustmentService->receive(
                                productId: $line->product_id,
                                locationId: $transfer->source_location_id,
                                quantity: (string) $line->quantity,
                                reference: $cancelReference,
                                userId: $userId,
                                expectedCompanyId: $transfer->company_id,
                                variantId: $line->variant_id,
                            );
```

- [ ] **Step 4: Run — expect GREEN, then run the whole variant suite**

Run: `cd apps/api && php artisan test --filter StockTransferVariantTest 2>&1 | tail -20`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "feat(inventory): variant-aware transfer cancel restock"
```

---

## Phase 4 — Batch + variant scoping

### Task 9: RED — batch-tracked variant transfer scopes batches to the variant

**Files:**
- Modify (test): `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

Add a batch-tracked variant product and a helper to seed a variant-scoped batch + batch stock, then assert FEFO/issue only considers batches of the targeted variant.

- [ ] **Step 1: Add imports + a batch helper to the test class**

Add to the `use` block:

```php
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Inventory\Application\DTOs\InitiateTransferBatchAllocationData;
```

Add helper methods:

```php
    private function makeBatchProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'sku' => 'CREAM', 'name' => 'Face Cream', 'type' => ProductType::Part,
            'is_active' => true, 'cost_price' => '3.0000', 'sale_price' => '6.0000',
            'requires_batch_tracking' => true,
        ]);
    }

    private function makeVariantFor(Product $product, string $code): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $product->id, 'variant_code' => $code,
            'sku' => $product->sku.'-'.$code, 'barcode' => null, 'name_suffix' => $code,
            'is_default' => false, 'is_active' => true, 'display_order' => 0,
            'price_override' => null, 'cost_override' => null, 'image_url' => null,
        ]);
    }

    /** Seed a variant-scoped batch with on-hand stock at a location. Returns the Batch. */
    private function seedVariantBatch(Product $product, ProductVariant $variant, Location $loc, string $number, string $expiry, string $qty): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $product->id, 'variant_id' => $variant->id,
            'batch_number' => $number, 'expiry_date' => $expiry, 'status' => 'active',
        ]);
        BatchStock::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'batch_id' => $batch->id, 'location_id' => $loc->id,
            'quantity' => $qty, 'reserved_quantity' => '0',
        ]);
        // Mirror the on-hand into a variant-scoped stock_level so the source
        // availability pre-check passes.
        $this->stockService->receive(
            productId: $product->id, locationId: $loc->id, quantity: $qty,
            reference: 'SEED-BATCH', userId: $this->user->id,
            batchId: (int) $batch->id, expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        return $batch;
    }
```

> Verify the exact `Batch`/`BatchStock` fillable column names against the entities in `apps/api/app/Modules/BatchExpiry/Domain/Entities/` before running; adjust keys (e.g. `status` vs `batch_status`) if the constructor rejects them.

- [ ] **Step 2: Add the failing test**

```php
    public function test_batch_tracked_variant_transfer_uses_variant_scoped_batches(): void
    {
        $product = $this->makeBatchProduct();
        $vA = $this->makeVariantFor($product, 'A');
        $vB = $this->makeVariantFor($product, 'B');

        // Variant A has an earlier-expiry batch; variant B a later one. FEFO must
        // not pull B's batch into A's transfer line.
        $batchA = $this->seedVariantBatch($product, $vA, $this->warehouse, 'LOT-A', '2026-09-01', '5');
        $this->seedVariantBatch($product, $vB, $this->warehouse, 'LOT-B', '2026-08-01', '5');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(
                    productId: $product->id,
                    quantity: '3',
                    variantId: $vA->id,
                    batchAllocations: [
                        new InitiateTransferBatchAllocationData(batchId: (int) $batchA->id, quantity: '3'),
                    ],
                ),
            ],
        ));

        $this->assertSame(TransferStatus::InTransit, $transfer->status);
        $allocation = $transfer->lines->first()->batchAllocations->first();
        $this->assertSame((int) $batchA->id, (int) $allocation->batch_id);
    }
```

- [ ] **Step 3: Run — expect RED** (FEFO sees variant B's earlier-expiry batch and rejects A's allocation as non-FEFO, OR `VariantRequiredException` if Step-5/7 not yet merged — they are; so the failure is the FEFO mismatch)

Run: `cd apps/api && php artisan test --filter test_batch_tracked_variant_transfer_uses_variant_scoped_batches 2>&1 | tail -25`
Expected: FAIL with the FEFO `InvalidArgumentException` ("Batch allocations must follow FEFO ...").

- [ ] **Step 4: Scope the batch queries by variant** — in `StockTransferService.php`, add a `->when(...)` variant filter to the three `Batch`/`BatchStock` product-scoped queries:

In `assertAllocationsFollowFefo()` (the `Batch::query()` around line 655) add after `->where('product_id', $product->id)`:

```php
            ->when(
                $line->variant_id !== null,
                fn ($q) => $q->where('variant_id', $line->variant_id),
                fn ($q) => $q->whereNull('variant_id'),
            )
```

In `assertBatchCanIssue()` (the `Batch::query()` around line 729) add the same `->when(...)` after `->where('product_id', $product->id)`. (`$allocation` has no `variant_id`; the line does — but `assertBatchCanIssue` only receives `$allocation`, `$product`, `$transfer`. Pass the line's `variant_id` down: change the signature to accept `?string $variantId` and thread it from `assertBatchAllocationsCanIssue`.)

Concretely, change `assertBatchAllocationsCanIssue` to pass `$line->variant_id`:

```php
            $this->assertBatchCanIssue($allocation, $product, $transfer, $line->variant_id);
```

and update `assertBatchCanIssue`:

```php
    private function assertBatchCanIssue(
        StockTransferLineBatchAllocation $allocation,
        Product $product,
        StockTransfer $transfer,
        ?string $variantId,
    ): void {
        $batch = Batch::query()
            ->where('tenant_id', $transfer->tenant_id)
            ->where('company_id', $transfer->company_id)
            ->where('product_id', $product->id)
            ->when(
                $variantId !== null,
                fn ($q) => $q->where('variant_id', $variantId),
                fn ($q) => $q->whereNull('variant_id'),
            )
            ->find($allocation->batch_id);
        // ... rest unchanged ...
```

- [ ] **Step 5: Run — expect GREEN**

Run: `cd apps/api && php artisan test --filter test_batch_tracked_variant_transfer_uses_variant_scoped_batches 2>&1 | tail -20`
Expected: PASS.

- [ ] **Step 6: Run the full transfer + variant suites (regression)**

Run: `cd apps/api && php artisan test --filter "InventoryTransferServiceTest|StockTransferVariantTest" 2>&1 | tail -20`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "feat(inventory): variant-scope batch FEFO + issue checks on transfers"
```

---

## Phase 5 — HTTP surface (request, controller)

### Task 10: Request validation for `lines.*.variant_id`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php`

- [ ] **Step 1: Add the rule** after the `lines.*.product_id` rule:

```php
            'lines.*.variant_id' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('product_variants', 'id')
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->where('is_active', true),
            ],
```

> Product↔variant ownership ("variant belongs to *this line's* product") and the "required when product has variants" rule are enforced in the service (Task 5c) where the per-line product is in scope; the request rule only checks existence + tenant/company + active.

- [ ] **Step 2: Commit**

```bash
git add apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php
git commit -m "feat(inventory): validate transfer line variant_id"
```

### Task 11: Controller — parse `variant_id` in; emit variant fields out

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- Modify (test): `apps/api/tests/Feature/Inventory/StockTransferVariantTest.php`

- [ ] **Step 1: Write a failing HTTP test** (asserts a variant transfer round-trips through the endpoint and the response carries `variant_id`)

```php
    public function test_store_endpoint_accepts_variant_line_and_returns_variant_fields(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '10');

        $response = $this->actingAs($this->user)->postJson('/api/inventory/stock-transfers', [
            'source_location_id' => $this->warehouse->id,
            'destination_location_id' => $this->shop->id,
            'lines' => [
                ['product_id' => $this->product->id, 'variant_id' => $this->variantA->id, 'quantity' => '4'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.lines.0.variant_id', $this->variantA->id);
        $response->assertJsonPath('data.lines.0.variant_sku', $this->variantA->sku);
    }

    public function test_store_endpoint_rejects_missing_variant_on_variant_product_with_422(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '10');

        $response = $this->actingAs($this->user)->postJson('/api/inventory/stock-transfers', [
            'source_location_id' => $this->warehouse->id,
            'destination_location_id' => $this->shop->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '4'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_TRANSFER');
    }
```

> Confirm the route prefix: check `apps/api/app/Modules/Inventory/Presentation/routes.php` for the transfer routes; adjust `/api/inventory/stock-transfers` to the actual URI if it differs. If the route needs a header/team middleware, the `actingAs($this->user)` plus the seeded membership/`CompanyContext` from setUp should satisfy it (mirror any header the existing controller feature tests send).

- [ ] **Step 2: Run — expect RED** (`variant_id` not parsed → `VariantRequiredException` 500 on the first test; second may already pass once parsing is wired)

Run: `cd apps/api && php artisan test --filter "test_store_endpoint_accepts_variant_line|test_store_endpoint_rejects_missing_variant" 2>&1 | tail -25`
Expected: FAIL.

- [ ] **Step 3: Parse `variant_id` into the DTO** — in `store()`, update the `$rawLines` PHPDoc and the line-building loop:

```php
        /** @var array<int, array{product_id: string, variant_id?: string|null, quantity: string|int|float, batch_allocations?: array<int, array{batch_id: int|string, quantity: string|int|float}>}> $rawLines */
        $rawLines = $request->input('lines', []);

        $lines = [];
        foreach ($rawLines as $line) {
            /** @var numeric-string $qty */
            $qty = (string) $line['quantity'];
            $batchAllocations = [];
            foreach ($line['batch_allocations'] ?? [] as $allocation) {
                /** @var numeric-string $allocationQty */
                $allocationQty = (string) $allocation['quantity'];
                $batchAllocations[] = new InitiateTransferBatchAllocationData(
                    batchId: (int) $allocation['batch_id'],
                    quantity: $allocationQty,
                );
            }
            $variantId = isset($line['variant_id']) && $line['variant_id'] !== null
                ? (string) $line['variant_id']
                : null;
            $lines[] = new InitiateTransferLineData(
                productId: (string) $line['product_id'],
                quantity: $qty,
                variantId: $variantId,
                batchAllocations: $batchAllocations,
            );
        }
```

- [ ] **Step 4: Emit variant fields in `formatTransfer`** — in the `$includeLines` block, add `variant_id`/`variant_sku`/`variant_name` to each line. Eager-load the variant so we don't N+1: add `'lines.variant'` to the `with([...])` calls in `index`/`show`/`store`/`complete`/`cancel`, then:

```php
            $payload['lines'] = $transfer->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product->name ?? null,
                'product_sku' => $line->product->sku ?? null,
                'variant_id' => $line->variant_id,
                'variant_sku' => $line->variant->sku ?? null,
                'variant_name' => $line->variant->name_suffix ?? null,
                'quantity' => $line->quantity,
                // ... rest unchanged ...
```

Add the `variant()` relation to `StockTransferLine` (Task 2 file) — append:

```php
    /**
     * @return BelongsTo<\App\Modules\Catalog\Domain\Entities\ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Catalog\Domain\Entities\ProductVariant::class, 'variant_id');
    }
```

> This is an Eloquent relation for read-side projection only (not a cross-module service call), consistent with how `product()`/`company()` relations already live on this model. Acceptable under Rule 6 since it is a presentation-layer eager-load, not domain logic crossing the module boundary. If review prefers stricter isolation, project variant via `ProductVariantLookup` in the controller instead — note this as a follow-up, do not block.

- [ ] **Step 5: Run — expect GREEN**

Run: `cd apps/api && php artisan test --filter StockTransferVariantTest 2>&1 | tail -20`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php apps/api/app/Modules/Inventory/Domain/StockTransferLine.php apps/api/tests/Feature/Inventory/StockTransferVariantTest.php
git commit -m "feat(inventory): transfer endpoint parses + projects variant_id"
```

### Task 12: Backend quality gates

- [ ] **Step 1: PHPStan L8 on the touched module**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory --memory-limit=3G 2>&1 | tail -20`
Expected: `[OK] No errors`. Fix any `precision.floatCastOnDecimalProperty` or generics issues introduced.

- [ ] **Step 2: Pint**

Run: `cd apps/api && ./vendor/bin/pint app/Modules/Inventory tests/Feature/Inventory/StockTransferVariantTest.php 2>&1 | tail -10`
Expected: clean / auto-fixed.

- [ ] **Step 3: Deptrac (no new boundary violations)**

Run: `cd apps/api && ./vendor/bin/deptrac analyse 2>&1 | tail -15`
Expected: baseline unchanged (the only new cross-module reference is the `ProductVariantLookup` contract — a `Shared\Contracts` interface, which is allowed).

- [ ] **Step 4: Commit any Pint fixes**

```bash
git add -A && git commit -m "chore(inventory): pint + phpstan clean for variant transfers" || echo "nothing to commit"
```

---

## Phase 6 — Web admin UI (variant selection)

> Goal: make the feature usable end-to-end. A line whose chosen product has active variants must require picking a variant before submit, and the payload must carry `variant_id`. Keep it minimal and inside the existing `CreateStockTransferPage` line table.

### Task 13: Types + API payload carry `variant_id`

**Files:**
- Modify: `apps/web/src/features/stock-transfers/types.ts`

- [ ] **Step 1: Inspect the current types**

Run: `sed -n '1,80p' apps/web/src/features/stock-transfers/types.ts`
Expected: find the `CreateStockTransferInput` line shape and the detail-line shape.

- [ ] **Step 2: Add `variant_id` to the line input type** (the object inside `lines: [...]` of `CreateStockTransferInput`) and `variant_id`/`variant_sku`/`variant_name` to the detail-line response type. Example (adapt names to the file):

```ts
export interface CreateStockTransferLineInput {
  product_id: string
  variant_id?: string | null
  quantity: string
  batch_allocations?: { batch_id: number; quantity: string }[]
}
```

```ts
// on the detail/response line type:
  variant_id: string | null
  variant_sku: string | null
  variant_name: string | null
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/stock-transfers/types.ts
git commit -m "feat(web): transfer line types carry variant_id"
```

### Task 14: RED — Vitest: line with a variant product requires + sends a variant

**Files:**
- Create: `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.variants.test.tsx`

- [ ] **Step 1: Write the failing component test**

Model it on the existing `CreateStockTransferPage.batchAllocations.test.tsx` (same provider/mocks setup). Assert that:
1. When a product with `has_variants: true` (or a non-empty `variants` array — match the `ProductPickerValue` shape) is selected, a variant `<select>`/picker renders for that line.
2. Submitting without choosing a variant shows the `create.validation.variantRequired` toast and does **not** call the create mutation.
3. After choosing a variant, the mutation payload's `lines[0].variant_id` equals the chosen variant id.

```tsx
// Pseudocode skeleton — fill in using the batchAllocations test's render+mock harness.
it('requires a variant for a variant-bearing product and sends variant_id', async () => {
  // render <CreateStockTransferPage/> with mocked locations, product picker
  // returning a product that has active variants, and a mocked useCreateStockTransfer.
  // 1. select source + destination
  // 2. pick the variant product
  // 3. submit -> expect toast.error(variantRequired) and mutate NOT called
  // 4. pick variant A from the variant select
  // 5. submit -> expect mutate called with lines[0].variant_id === variantA.id
})
```

- [ ] **Step 2: Run — expect RED**

Run: `cd apps/web && pnpm test --run CreateStockTransferPage.variants 2>&1 | tail -25`
Expected: FAIL (no variant select rendered / variant_id absent from payload).

- [ ] **Step 3: Implement the variant selector in `CreateStockTransferPage.tsx`**

- Extend `DraftLine` with `variantId: string | null`.
- In the `product` column `onChange`, reset `variantId: null` when the product changes (like `batchAllocations: []`).
- Add a `variant` column (or render a variant `<select>` beneath the product cell) that appears only when the selected product has active variants. Source the variants from the product picker value if it already carries them; otherwise fetch via the existing variants hook/endpoint (check `apps/web/src/features/` for a `useProductVariants`/variants API; if none, fetch `/products/${id}/variants` with `apiGet` + `tenantScopedKey`). Populate `name_suffix`/`sku` as the option label.
- In `submitTransfer`, before building the payload, validate: if the line's product has variants and `variantId` is null, `toast.error(t('create.validation.variantRequired'))` and return.
- Add `variant_id: l.variantId` to each payload line (omit or send `null` when absent — match the request rule, which is `nullable`).

> Determine the "product has active variants" signal from `ProductPickerValue`. Inspect `apps/web/src/components/molecules/pickers/ProductPicker.tsx` for a `has_variants`/`variants` field. If the picker doesn't expose it, add a lightweight per-line variants query keyed on the product id and treat "variants array non-empty" as the gate.

- [ ] **Step 4: Run — expect GREEN**

Run: `cd apps/web && pnpm test --run CreateStockTransferPage.variants 2>&1 | tail -20`
Expected: PASS.

- [ ] **Step 5: Run the full stock-transfers web suite (regression)**

Run: `cd apps/web && pnpm test --run stock-transfers 2>&1 | tail -25`
Expected: all PASS (existing product-level + batch tests unaffected — `variant_id` is optional).

- [ ] **Step 6: Add i18n keys** to `apps/web/src/locales/en/stock-transfers.json` and `.../fr/stock-transfers.json`:

```jsonc
// en
"create": {
  "field": { "variant": "Variant", "selectVariant": "Select a variant" },
  "validation": { "variantRequired": "Select a variant for this product." }
}
```

```jsonc
// fr
"create": {
  "field": { "variant": "Variante", "selectVariant": "Sélectionner une variante" },
  "validation": { "variantRequired": "Sélectionnez une variante pour ce produit." }
}
```

> Merge into the existing `create.field` / `create.validation` objects — do not create duplicate keys.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/stock-transfers apps/web/src/locales/en/stock-transfers.json apps/web/src/locales/fr/stock-transfers.json
git commit -m "feat(web): variant selection on the create-transfer line"
```

### Task 15: Detail page shows the variant

**Files:**
- Modify: `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`

- [ ] **Step 1: Render `variant_name` / `variant_sku`** next to the product name in the lines table (only when `variant_id` is set). Inspect the existing product-name cell and append a muted `· {variant_name} ({variant_sku})` suffix.

- [ ] **Step 2: Run the detail page test if one exists; otherwise typecheck**

Run: `cd apps/web && pnpm test --run StockTransfer 2>&1 | tail -15 && pnpm typecheck 2>&1 | tail -10`
Expected: PASS / no type errors.

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx
git commit -m "feat(web): show variant on transfer detail lines"
```

---

## Phase 7 — Full verification + review

### Task 16: Preflight + adversarial review

- [ ] **Step 1: Run preflight** (PHPStan, Pint, PHPUnit, tsc, ESLint)

Run: `./scripts/preflight.sh 2>&1 | tail -40`
Expected: all gates green. If the script is scoped per-app, run the api + web variants it defines. Investigate every failure — do not mark complete on red (CLAUDE.md Rule 5/10).

- [ ] **Step 2: Targeted regression — the inventory + WAC suites**

Run: `cd apps/api && php artisan test --filter "Inventory|Wac|Transfer|StockAdjustment" 2>&1 | tail -25`
Expected: green except any pre-existing failures already red on `dev` (verify by `git stash` + run on base if unsure — these are documented in `project_t2_variants_impl` as the `partners.account_status` / fiscal-hash / PG-only suites).

- [ ] **Step 3: Codex adversarial review** (this is critical inventory+costing code). Run a Codex review and instruct it to WRITE the review to a file, not return inline (see `feedback_codex_review_to_file`):

> "Adversarially review the variant-aware stock-transfer retrofit on branch `feat/variant-aware-transfers` (diff vs `origin/dev`). Focus on: (1) the WAC product-grain invariant — confirm `recordCostAdjustment`, `ProductCostLock` keying, and `companyOwnedQuantity` were NOT variant-sharded; (2) the canonical lock order (advisory → stock_level → product) is preserved on the variant path; (3) no mixed-mode hole — a variant product can never be transferred at product grain, and a variant can't be addressed on a non-variant product; (4) batch FEFO correctly scoped to the variant; (5) the partial-unique migration is online-DDL safe and the down() restores the old unique; (6) the same product appearing twice under different variants is handled by both the DB constraint and `collectProductIds`. Write the review to `apps/api/docs/superpowers/reviews/2026-06-09-variant-aware-transfers-codex-review.md`."

- [ ] **Step 4: Triage findings**, fix BLOCKER/P1s with follow-up TDD tasks, re-run preflight, re-review until APPROVE.

- [ ] **Step 5: Finalize** — use `superpowers:finishing-a-development-branch` to decide PR vs merge. Update memory note `project_inventory_transfer_wac_remediation` / `project_t2_variants_impl` with the G2-closed state.

---

## Self-Review notes (author)

- **Spec coverage:** variant-level stock motion (Tasks 5,7,8) ✓; correct product-grain WAC (Task 7 asserts `7.0000` not `9.0000`) ✓; batch+variant FEFO (Task 9) ✓; HTTP surface (Tasks 10–11) ✓; no-mixed-mode + foreign-variant guards (Task 6) ✓; same-product-different-variant (Task 6) ✓; UI (Tasks 13–15) ✓; gates/review (Tasks 12,16) ✓.
- **Invariants preserved:** WAC product-grain (no change to `capitalizeTransferCost`/`recordCostAdjustment`/`ProductCostLock`); canonical lock order untouched (we only added `variant_id` filters to existing `lockForUpdate` reads, never reordered locks); deadlock defense (`lineProductIds` stays product-grain for the advisory acquire) ✓.
- **Type consistency:** `variantId` (camel, DTO/PHP params) vs `variant_id` (snake, DB/JSON) used consistently; `assertVariantValidForProduct` is the single new service guard; `ProductVariantLookup::findById`/`listForProduct` signatures match the contract.
- **Risk to verify during exec:** exact `Batch`/`BatchStock` fillable column names (Task 9 Step-1 note); the transfer route URI (Task 11 note); whether `ProductPickerValue` exposes variants (Task 14 note). Each task flags the check inline.
