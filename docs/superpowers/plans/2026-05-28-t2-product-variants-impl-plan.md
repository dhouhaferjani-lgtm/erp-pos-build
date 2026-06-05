# T2 Product Variants Implementation Plan

**Version:** v4 (post Codex r2 — REJECT → fixes applied; r3 plan-task body polish 2026-05-28). v4 corrects 6 P1s + 9 P2s + 4 P3s from Codex r2; the r3 polish applies the 4 r3 P1s mechanically to plan task bodies (Tasks 5/6/8/9/10 online-DDL snippets, Task 11b ordering, Task 27b `StockAlertReportService`, Task 17 README pricing order) plus 3 r3 P2s. See `reviews/2026-05-28-t2-variants-codex-r2.md` and `reviews/2026-05-28-t2-variants-codex-r3.md` for the inputs.

**v4 critical fixes (Codex r2 P1s):**
1. **Task 16b `consumeBatchesAtomically`** rewritten: now accepts `tenantId` + `movementId` (FK to stock_movements); selects `available_quantity` for filter; decrements `quantity` (the stored value, generated `available_quantity` auto-updates); uses raw SQL `FOR UPDATE SKIP LOCKED`; `inventory_batch_movements` insert includes `tenant_id`, `movement_id`, `batch_id`, `quantity` (no spurious `id`); `BatchStockConsumed` event dispatched via `DB::afterCommit`; `BatchConsumptionResultDTO::$shortfall` is decimal string (not int); `batch_id` is `int` (matching `product_batches.id` bigint, not UUID).
2. **Online-DDL strategy applied uniformly to Tasks 5, 6, 8, 9, 10, 16b** — every FK uses `NOT VALID`; every replacement unique uses `CREATE INDEX CONCURRENTLY`; every migration declares `$withinTransaction = false`. New Task 11c adds the deferred `VALIDATE CONSTRAINT` migration.
3. **`ProductVariantLookup` moved to real path** `apps/api/app/Shared/Contracts/` (namespace `App\Shared\Contracts`) and **renumbered Task 11b** so it lands BEFORE Task 17 PricingService (which consumes it). Task 28a is removed.
4. **Accounting report rewrites (Task 27b)** preserve full scoping (companyIds + locationIds + voided/training/posted_at filters; `pos_receipts` join); return `list<TopSkuData>` and `list<StockAlertData>` matching the existing DTOs.
5. **Plan no longer duplicates broken PricingService signature** — all references use the real `(productId, partnerId, qty, currency, date, variantId)` shape returning `array{price, source, price_list_id}`.
6. **Channel listener V2 migration moved IN-scope** (Task 19's "deferred" claim withdrawn; Task 26 owns the listener-to-V2 work as part of T2).

**v3 changes that affect this plan:**
1. **Task 3** — `product_variants` migration uses soft-delete-aware partial uniques + varchar(100) (already in v2; verified clean).
2. **Task 7** — `product_batches` constraint to drop is `unique_batch_per_product` (verified at `2026_01_05_150000:47`), NOT the auto-generated `..._product_id_batch_number_unique`.
3. **Task 9** — `price_list_items` constraint to drop is `price_list_product_qty_unique` (verified at `2025_12_01_201028:23`).
4. **NEW Task 8b** — `channel_product_mappings` partial-unique replacement (P1-4 — the existing unique allows multiple product-level rows due to NULL semantics).
5. **NEW Task 16b** — atomic `consumeBatchesAtomically` FEFO primitive (P1-5, P1-6 — current `suggestBatchesForSale` is read-only with no locks; sale can shortfall and still commit).
6. **Task 17** — `PricingService::getPrice` signature is `(string $productId, ?string $partnerId = null, string $quantity = '1.00', string $currency = 'USD', ?\DateTimeInterface $date = null): array` (verified at `PricingService.php:32`). T2 appends `?string $variantId = null` as a trailing optional. Return shape stays `array{price, source, price_list_id}`; `source` enum extends to include `variant_override` and the `'variant'` flavor of price-list sources.
7. **Task 19** — dual-dispatch must update **all 6** `StockMovementRecorded` dispatch sites: `StockAdjustmentService` lines 80, 169, 277, 292, 453 + `WeightedAverageCostService` line 476. Also: `SalesOrderConfirmedV2` is REQUIRED (payload IS serialized; verified at `SalesOrderConfirmed.php:17-31`).
8. **NEW Task 27b** — Accounting report updates (`SalesReportService::topSkus`, `StockAlertReportService`) for variant grain (P2-1).
9. **NEW Task 28b** — `Shared/Contracts/ProductVariantLookup` cross-module contract (P2-7).
10. **NEW Task 27c** — Loyalty rule evaluator updated to treat product-scoped rules as applying to all variants (P2-3 — Loyalty uses `product_ids`, not just category).
11. **Task 32** — acceptance criteria split: Wave 1 (server) vs Wave 2 (POS UI) — Wave 2 acceptance is logged to coordination log, NOT this PR's gate (P1-8).
12. **All migrations** — use `$withinTransaction = false` plus `CREATE UNIQUE INDEX ... CONCURRENTLY` plus `ADD FK ... NOT VALID` (+ separate validate-step) to avoid blocking writes on the 5M-row `stock_movements` (P2-6).
13. **Commit message format** corrected to AGENTS.md convention: `Phase <major.minor.patch>: <imperative summary>` (P2-11). All commit examples below use this format.
14. **No placeholders in test code** — every `/* ... */` and "TBD" in v2 is replaced with concrete code in v3 (P2-10).

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement T2 product variants in AutoERP so that variants become the unit of stock, batches attach to variants with expiry tracking, recipes can be built from variant ingredients with earliest-expiry inheritance, and the data surfaces through POS / web ERP B2B / catalog ecommerce.

**Architecture:** Hexagonal — Domain (entities + VOs + enums) → Application (services + DTOs) → Infrastructure (Eloquent repositories) → Presentation (controllers + React). New `ProductVariant` aggregate decorates existing `Product`; nullable `variant_id` (FK to `product_variants`) added to every table that currently scopes to `product_id`; partial unique indexes preserve pre-T2 uniqueness for non-variant rows. Backward compat at service layer: every modified signature accepts a trailing optional `?UUID $variantId = null`. Variants forbidden to coexist mixed-mode within a product after first variant created — `StockLevelMigrationService` migrates open state to default variant atomically.

**Tech Stack:** PHP 8.4 + Laravel 12 + PostgreSQL 16 (tenant DB) + PHPUnit (backend) + React 19 + Vite 7 + TypeScript strict + TanStack Query 5 + Vitest (web ERP) + Tauri 2 + SQLite (POS). PHPStan L8, Pint, ESLint zero-error gates.

**Spec:** `apps/erp/docs/superpowers/specs/2026-05-28-t2-product-variants.md`

**Sequencing:** Phase 1 (schema + domain skeleton) → Phase 2 (service-layer ripple) → Phase 3 (recipe + variant + expiry) → Phase 4 (B2B + ecommerce surface) → Phase 5 (admin UI) → Phase 6 (acceptance test sweep). POS Wave-2 deltas are a separate session, logged to the coordination log.

**TDD rule:** every task starts with a failing test (red), then minimum code to pass (green), then refactor if needed. Never commit a step that hasn't been verified.

**Commit message format (AGENTS.md convention — Codex r2 P2-4 fix):** `Phase <major.minor.patch>: <imperative summary>` (example: `Phase 2.1.16b: FEFOInventoryService atomic consume`). Use the task number as the patch component. Imperative summary describes what the commit does, not the task ID. Reference issue / ticket as configured in PR body, not commit message.

**Working directory:** all paths relative to `apps/erp/` unless otherwise noted (i.e., `apps/api/...` = `apps/erp/apps/api/...`).

---

## Phase 1 — Schema and domain skeleton (~4 PD)

### Task 1: Create `ProductAttribute` entity, migration, repository contract, persistence

**Files:**
- Create migration: `apps/api/database/migrations/tenant/2026_06_02_100001_create_product_attributes_table.php`
- Create domain: `apps/api/app/Modules/Catalog/Domain/Entities/ProductAttribute.php`
- Create enum: `apps/api/app/Modules/Catalog/Domain/Enums/AttributeDataType.php`
- Create repository contract: `apps/api/app/Modules/Catalog/Domain/Repositories/AttributeRepository.php`
- Create eloquent repository: `apps/api/app/Modules/Catalog/Infrastructure/Repositories/EloquentAttributeRepository.php`
- Test: `apps/api/tests/Unit/Catalog/Domain/Entities/ProductAttributeTest.php`
- Test: `apps/api/tests/Feature/Catalog/AttributeRepositoryTest.php`

- [ ] **Step 1: Write failing unit test for the entity**

```php
// apps/api/tests/Unit/Catalog/Domain/Entities/ProductAttributeTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class ProductAttributeTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_can_be_constructed_with_required_fields(): void
    {
        $attr = ProductAttribute::factory()->make([
            'code' => 'taille',
            'name' => 'Taille',
            'data_type' => AttributeDataType::Selection,
            'is_variant_axis' => true,
        ]);

        $this->assertSame('taille', $attr->code);
        $this->assertSame('Taille', $attr->name);
        $this->assertSame(AttributeDataType::Selection, $attr->data_type);
        $this->assertTrue($attr->is_variant_axis);
        $this->assertTrue($attr->is_active);
    }

    public function test_attribute_default_is_not_variant_axis(): void
    {
        $attr = ProductAttribute::factory()->make(['is_variant_axis' => false]);
        $this->assertFalse($attr->is_variant_axis);
    }
}
```

- [ ] **Step 2: Run test, verify fail**

Run: `cd apps/erp/apps/api && ./vendor/bin/phpunit --filter ProductAttributeTest`
Expected: FAIL — `Class ProductAttribute does not exist`.

- [ ] **Step 3: Create the `AttributeDataType` enum**

```php
// apps/api/app/Modules/Catalog/Domain/Enums/AttributeDataType.php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum AttributeDataType: string
{
    case Text = 'text';
    case Numeric = 'numeric';
    case Boolean = 'boolean';
    case Date = 'date';
    case Selection = 'selection';
    case Color = 'color';
    case Image = 'image';
}
```

- [ ] **Step 4: Create the migration**

```php
// apps/api/database/migrations/tenant/2026_06_02_100001_create_product_attributes_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('code', 64);
            $table->string('name', 128);
            $table->string('data_type', 32);
            $table->boolean('is_variant_axis')->default(false);
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active', 'is_variant_axis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
```

- [ ] **Step 5: Create the entity**

```php
// apps/api/app/Modules/Catalog/Domain/Entities/ProductAttribute.php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Database\Factories\Catalog\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property AttributeDataType $data_type
 * @property bool $is_variant_axis
 * @property int $display_order
 * @property bool $is_active
 */
final class ProductAttribute extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'product_attributes';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'data_type',
        'is_variant_axis', 'display_order', 'is_active',
    ];

    protected $casts = [
        'data_type' => AttributeDataType::class,
        'is_variant_axis' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function newFactory(): ProductAttributeFactory
    {
        return ProductAttributeFactory::new();
    }
}
```

- [ ] **Step 6: Create the factory**

```php
// apps/api/database/factories/Catalog/ProductAttributeFactory.php
<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductAttribute> */
final class ProductAttributeFactory extends Factory
{
    protected $model = ProductAttribute::class;

    public function definition(): array
    {
        return [
            'tenant_id' => (string) Str::uuid(),
            'code' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'data_type' => AttributeDataType::Selection,
            'is_variant_axis' => true,
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 7: Create the repository contract**

```php
// apps/api/app/Modules/Catalog/Domain/Repositories/AttributeRepository.php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use Illuminate\Support\Collection;

interface AttributeRepository
{
    public function findById(string $id): ?ProductAttribute;
    public function findByCode(string $code): ?ProductAttribute;
    /** @return Collection<int, ProductAttribute> */
    public function listForTenant(bool $onlyVariantAxes = false): Collection;
    public function save(ProductAttribute $attribute): void;
    public function softDelete(string $id): void;
}
```

- [ ] **Step 8: Create the Eloquent implementation**

```php
// apps/api/app/Modules/Catalog/Infrastructure/Repositories/EloquentAttributeRepository.php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use Illuminate\Support\Collection;

final class EloquentAttributeRepository implements AttributeRepository
{
    public function findById(string $id): ?ProductAttribute
    {
        return ProductAttribute::query()->find($id);
    }

    public function findByCode(string $code): ?ProductAttribute
    {
        return ProductAttribute::query()->where('code', $code)->first();
    }

    public function listForTenant(bool $onlyVariantAxes = false): Collection
    {
        return ProductAttribute::query()
            ->when($onlyVariantAxes, fn ($q) => $q->where('is_variant_axis', true))
            ->orderBy('display_order')
            ->get();
    }

    public function save(ProductAttribute $attribute): void
    {
        $attribute->save();
    }

    public function softDelete(string $id): void
    {
        ProductAttribute::query()->where('id', $id)->delete();
    }
}
```

- [ ] **Step 9: Register binding in service provider**

Edit `apps/api/app/Modules/Catalog/Infrastructure/Providers/CatalogServiceProvider.php` (verify exact path) — add to the `register()` method:

```php
$this->app->bind(
    \App\Modules\Catalog\Domain\Repositories\AttributeRepository::class,
    \App\Modules\Catalog\Infrastructure\Repositories\EloquentAttributeRepository::class,
);
```

- [ ] **Step 10: Run migration + unit test**

Run: `cd apps/erp/apps/api && php artisan migrate --path=database/migrations/tenant --env=testing && ./vendor/bin/phpunit --filter ProductAttributeTest`
Expected: PASS (both tests green).

- [ ] **Step 11: Add repository integration test**

```php
// apps/api/tests/Feature/Catalog/AttributeRepositoryTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AttributeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_saves_and_finds_by_id(): void
    {
        $repo = app(AttributeRepository::class);
        $attr = ProductAttribute::factory()->create();

        $found = $repo->findById($attr->id);

        $this->assertNotNull($found);
        $this->assertSame($attr->code, $found->code);
    }

    public function test_lists_only_variant_axes_when_flag_set(): void
    {
        $repo = app(AttributeRepository::class);
        ProductAttribute::factory()->create(['is_variant_axis' => true]);
        ProductAttribute::factory()->create(['is_variant_axis' => false]);

        $variantOnly = $repo->listForTenant(onlyVariantAxes: true);
        $all = $repo->listForTenant(onlyVariantAxes: false);

        $this->assertCount(1, $variantOnly);
        $this->assertCount(2, $all);
    }

    public function test_tenant_code_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $tenant = (string) \Illuminate\Support\Str::uuid();
        ProductAttribute::factory()->create(['tenant_id' => $tenant, 'code' => 'taille']);
        ProductAttribute::factory()->create(['tenant_id' => $tenant, 'code' => 'taille']);
    }
}
```

Run: `./vendor/bin/phpunit --filter AttributeRepositoryTest` → PASS.

- [ ] **Step 12: Commit**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.t2-variants-spec
git add apps/api/app/Modules/Catalog/Domain/Entities/ProductAttribute.php \
        apps/api/app/Modules/Catalog/Domain/Enums/AttributeDataType.php \
        apps/api/app/Modules/Catalog/Domain/Repositories/AttributeRepository.php \
        apps/api/app/Modules/Catalog/Infrastructure/Repositories/EloquentAttributeRepository.php \
        apps/api/database/migrations/tenant/2026_06_02_100001_create_product_attributes_table.php \
        apps/api/database/factories/Catalog/ProductAttributeFactory.php \
        apps/api/tests/Unit/Catalog/Domain/Entities/ProductAttributeTest.php \
        apps/api/tests/Feature/Catalog/AttributeRepositoryTest.php
# Plus the service-provider edit
git commit -m "Phase 2.x: ProductAttribute entity + migration + repository"
```

---

### Task 2: Create `ProductAttributeValue` entity, migration, repository

**Files:**
- Create migration: `apps/api/database/migrations/tenant/2026_06_02_100002_create_product_attribute_values_table.php`
- Create domain: `apps/api/app/Modules/Catalog/Domain/Entities/ProductAttributeValue.php`
- Create factory, repository contract + eloquent impl following Task 1 shape
- Test: `apps/api/tests/Feature/Catalog/AttributeValueRepositoryTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_value_belongs_to_attribute(): void
{
    $attr = ProductAttribute::factory()->create(['data_type' => AttributeDataType::Color]);
    $val = ProductAttributeValue::factory()->create([
        'attribute_id' => $attr->id,
        'code' => 'noir',
        'label' => 'Noir',
        'hex_color' => '#000000',
    ]);

    $this->assertSame($attr->id, $val->attribute_id);
    $this->assertSame('#000000', $val->hex_color);
}
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Create migration with `attribute_id` FK CASCADE, `(attribute_id, code)` unique, CHECK hex format**

```php
// up()
Schema::create('product_attribute_values', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->index();
    $table->foreignUuid('attribute_id')
        ->constrained('product_attributes')
        ->cascadeOnDelete();
    $table->string('code', 64);
    $table->string('label', 128);
    $table->string('hex_color', 7)->nullable();
    $table->string('image_url', 2048)->nullable();
    $table->integer('display_order')->default(0);
    $table->timestamps();

    $table->unique(['attribute_id', 'code']);
    $table->index(['tenant_id', 'attribute_id', 'display_order']);
});

if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement('ALTER TABLE product_attribute_values ADD CONSTRAINT product_attribute_values_hex_format CHECK (hex_color IS NULL OR hex_color ~ \'^#[0-9A-Fa-f]{6}$\')');
}
```

- [ ] **Step 4: Create entity, factory, repository contract + impl** following Task 1 shape. Bindings registered.
- [ ] **Step 5: Run tests** including a separate hex-format CHECK test.

```php
public function test_invalid_hex_color_rejected_by_db(): void
{
    $this->expectException(\Illuminate\Database\QueryException::class);
    ProductAttributeValue::factory()->create(['hex_color' => 'invalid']);
}
```

- [ ] **Step 6: Commit**

```bash
git commit -m "Phase 2.x: ProductAttributeValue entity + migration + repository"
```

---

### Task 3: Create `ProductVariant` entity + migration with partial unique indexes

**Files:**
- Create migration: `apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php`
- Create domain: `apps/api/app/Modules/Catalog/Domain/Entities/ProductVariant.php`
- Factory + repository contract + Eloquent impl
- Test: `apps/api/tests/Feature/Catalog/ProductVariantRepositoryTest.php`

- [ ] **Step 1: Write failing test** including the partial unique exclusivity for `is_default`:

```php
public function test_only_one_default_variant_per_product(): void
{
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);

    $this->expectException(\Illuminate\Database\QueryException::class);
    ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);
}

public function test_sku_unique_per_tenant(): void
{
    $tenant = (string) Str::uuid();
    ProductVariant::factory()->create(['tenant_id' => $tenant, 'sku' => 'ABC-39-N']);
    $this->expectException(\Illuminate\Database\QueryException::class);
    ProductVariant::factory()->create(['tenant_id' => $tenant, 'sku' => 'ABC-39-N']);
}

public function test_barcode_unique_when_present(): void
{
    $tenant = (string) Str::uuid();
    ProductVariant::factory()->create(['tenant_id' => $tenant, 'barcode' => '5901234123457']);
    $this->expectException(\Illuminate\Database\QueryException::class);
    ProductVariant::factory()->create(['tenant_id' => $tenant, 'barcode' => '5901234123457']);
}

public function test_null_barcode_allowed_multiple(): void
{
    $tenant = (string) Str::uuid();
    ProductVariant::factory()->count(3)->create(['tenant_id' => $tenant, 'barcode' => null]);
    $this->assertSame(3, ProductVariant::query()->where('tenant_id', $tenant)->count());
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Create migration** (P1-3 — all partial uniques are soft-delete-aware; P3-1 — `varchar(100)` matching products.sku)

```php
Schema::create('product_variants', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->index();
    $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
    $table->string('variant_code', 100);  // matches products.sku length
    $table->string('sku', 100);  // matches products.sku length
    $table->string('barcode', 100)->nullable();
    $table->string('name_suffix', 128);
    $table->boolean('is_default')->default(false);
    $table->boolean('is_active')->default(true);
    $table->integer('display_order')->default(0);
    $table->decimal('price_override', 15, 4)->nullable();
    $table->decimal('cost_override', 15, 4)->nullable();
    $table->string('image_url', 2048)->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['product_id', 'variant_code']);
    $table->index(['tenant_id', 'product_id', 'is_active', 'display_order']);
    $table->index(['tenant_id', 'company_id', 'is_active']);
});

// Partial uniques — all soft-delete-aware (P1-3)
if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement('CREATE UNIQUE INDEX product_variants_tenant_sku_unique
                   ON product_variants (tenant_id, sku)
                   WHERE deleted_at IS NULL');

    DB::statement('CREATE UNIQUE INDEX product_variants_tenant_barcode_unique
                   ON product_variants (tenant_id, barcode)
                   WHERE barcode IS NOT NULL AND deleted_at IS NULL');

    DB::statement('CREATE UNIQUE INDEX product_variants_default_unique
                   ON product_variants (product_id)
                   WHERE is_default = true AND deleted_at IS NULL');

    DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_nonneg
                   CHECK (price_override IS NULL OR price_override >= 0)');
    DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_cost_nonneg
                   CHECK (cost_override IS NULL OR cost_override >= 0)');
}
```

**Add Step 3b: regression tests for soft-delete partial-unique behavior (P1-3)**

```php
public function test_sku_reusable_after_soft_delete(): void
{
    $tenant = (string) Str::uuid();
    $v1 = ProductVariant::factory()->create(['tenant_id' => $tenant, 'sku' => 'ABC-001']);
    $v1->delete(); // soft-delete

    // Should succeed — soft-deleted SKU is free for reuse
    $v2 = ProductVariant::factory()->create(['tenant_id' => $tenant, 'sku' => 'ABC-001']);
    $this->assertNotNull($v2);
}

public function test_default_reusable_after_soft_delete_of_default(): void
{
    $product = Product::factory()->create();
    $oldDefault = ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);
    $oldDefault->delete();

    // Should succeed — soft-deleted default is excluded from partial unique
    $newDefault = ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);
    $this->assertNotNull($newDefault);
}
```

- [ ] **Step 4: Create entity + factory + repo contract `ProductVariantRepository` + Eloquent impl + service-provider binding** following Task 1 shape.
- [ ] **Step 5: Run all four ProductVariant tests** → PASS.
- [ ] **Step 6: Commit**

```bash
git commit -m "Phase 2.x: ProductVariant entity + migration + repository"
```

---

### Task 4: Create `ProductVariantAttributeValue` junction + migration

**Files:**
- Create migration: `apps/api/database/migrations/tenant/2026_06_02_100004_create_product_variant_attribute_values_table.php`
- Create domain: `apps/api/app/Modules/Catalog/Domain/Entities/ProductVariantAttributeValue.php`
- Repository follows Task 1 shape
- Test: `apps/api/tests/Feature/Catalog/ProductVariantAttributeValueTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_junction_enforces_unique_attribute_per_variant(): void
{
    $variant = ProductVariant::factory()->create();
    $attr = ProductAttribute::factory()->create();
    $val1 = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id]);
    $val2 = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id]);

    ProductVariantAttributeValue::factory()->create([
        'variant_id' => $variant->id,
        'attribute_id' => $attr->id,
        'attribute_value_id' => $val1->id,
    ]);
    $this->expectException(\Illuminate\Database\QueryException::class);
    ProductVariantAttributeValue::factory()->create([
        'variant_id' => $variant->id,
        'attribute_id' => $attr->id,
        'attribute_value_id' => $val2->id,
    ]);
}

public function test_deleting_attribute_in_use_is_restricted(): void
{
    $variant = ProductVariant::factory()->create();
    $attr = ProductAttribute::factory()->create();
    $val = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id]);
    ProductVariantAttributeValue::factory()->create([
        'variant_id' => $variant->id,
        'attribute_id' => $attr->id,
        'attribute_value_id' => $val->id,
    ]);

    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('product_attributes')->where('id', $attr->id)->delete();
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Create migration**

```php
Schema::create('product_variant_attribute_values', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('variant_id')
        ->constrained('product_variants')
        ->cascadeOnDelete();
    $table->foreignUuid('attribute_id')
        ->constrained('product_attributes')
        ->restrictOnDelete(); // RESTRICT — preserve variant integrity
    $table->foreignUuid('attribute_value_id')
        ->constrained('product_attribute_values')
        ->restrictOnDelete();
    $table->timestamps();

    $table->unique(['variant_id', 'attribute_id']);
    $table->index('attribute_value_id');
});
```

- [ ] **Step 4: Create entity + factory + repo + binding.**
- [ ] **Step 5: Run tests** → PASS.
- [ ] **Step 6: Commit**

```bash
git commit -m "Phase 2.x: ProductVariantAttributeValue junction + migration"
```

---

### Task 5: Add `variant_id` to `stock_levels` with partial unique indexes

**Files:**
- Create migration: `apps/api/database/migrations/tenant/2026_06_02_100005_add_variant_id_to_stock_levels.php`
- Test: `apps/api/tests/Feature/Inventory/StockLevelsVariantUniqueIndexTest.php`

- [ ] **Step 1: Write failing test that asserts partial-unique semantics**

```php
public function test_can_have_one_non_variant_and_one_variant_row_for_same_product_location(): void
{
    $tenant = (string) Str::uuid();
    $product = Product::factory()->create(['tenant_id' => $tenant]);
    $variant = ProductVariant::factory()->create(['tenant_id' => $tenant, 'product_id' => $product->id]);
    $location = Location::factory()->create();

    DB::table('stock_levels')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant,
        'product_id' => $product->id,
        'variant_id' => null,
        'location_id' => $location->id,
        'quantity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('stock_levels')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'location_id' => $location->id,
        'quantity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertSame(2, DB::table('stock_levels')
        ->where('product_id', $product->id)
        ->where('location_id', $location->id)
        ->count());
}

public function test_duplicate_non_variant_row_rejected(): void
{
    // similar setup; try to insert two variant_id=null rows for same (tenant,product,location) — second fails
}

public function test_duplicate_variant_row_rejected(): void
{
    // similar; two variant_id=X rows fail
}
```

- [ ] **Step 2: Run, verify fail (column not exists).**

- [ ] **Step 3: Create migration (online-DDL: NOT VALID FK + CONCURRENTLY indexes; matches Task 7 pattern)**

The migration file declares `public $withinTransaction = false;` so we can use `CREATE INDEX CONCURRENTLY`.

```php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        // Step 1: add nullable variant_id column (metadata-only, fast)
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Step 2: FK as NOT VALID (does not block writes; validation deferred to Task 11c)
        DB::statement('ALTER TABLE stock_levels
            ADD CONSTRAINT stock_levels_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        // Step 3: build replacement partial-unique indexes CONCURRENTLY
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_levels_non_variant
                       ON stock_levels (tenant_id, product_id, location_id)
                       WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_levels_with_variant
                       ON stock_levels (tenant_id, product_id, variant_id, location_id)
                       WHERE variant_id IS NOT NULL');

        // Step 4: drop the OLD unique constraint
        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_tenant_id_product_id_location_id_unique');
    }

    public function down(): void
    {
        // Reverse order
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_tenant_id_product_id_location_id_unique
                           UNIQUE (tenant_id, product_id, location_id)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_levels_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_levels_non_variant');
            DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_variant_id_foreign');
        }
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

- [ ] **Step 4: Run migration + tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: add variant_id to stock_levels with partial unique indexes (online DDL)"
```

---

### Task 6: Add `variant_id` to `stock_movements`, `stock_reservations` (append + index)

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100006_add_variant_id_to_stock_movements.php`
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100007_add_variant_id_to_stock_reservations.php`
- Test: `apps/api/tests/Feature/Inventory/StockMovementsVariantColumnTest.php`

- [ ] **Step 1: Write failing test verifying column exists + FK + index**

```php
public function test_stock_movements_has_variant_id_column(): void
{
    $this->assertTrue(Schema::hasColumn('stock_movements', 'variant_id'));
}

public function test_stock_movements_variant_index_exists(): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        $idx = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'stock_movements' AND indexname = 'stock_movements_tenant_product_variant_created_idx'");
        $this->assertNotEmpty($idx);
    }
}
```

- [ ] **Step 2: Create migrations (online-DDL — `stock_movements` is ~5M rows; immediate FK + non-concurrent index would block deploys)**

Each migration file declares `public $withinTransaction = false;` so we can use `CREATE INDEX CONCURRENTLY`.

```php
// 2026_06_02_100006_add_variant_id_to_stock_movements.php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_movements
            ADD CONSTRAINT stock_movements_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY stock_movements_tenant_product_variant_created_idx
                       ON stock_movements (tenant_id, product_id, variant_id, created_at)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_tenant_product_variant_created_idx');
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_variant_id_foreign');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

```php
// 2026_06_02_100007_add_variant_id_to_stock_reservations.php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_reservations
            ADD CONSTRAINT stock_reservations_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY stock_reservations_tenant_product_variant_released_idx
                       ON stock_reservations (tenant_id, product_id, variant_id, released_at)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_reservations_tenant_product_variant_released_idx');
            DB::statement('ALTER TABLE stock_reservations DROP CONSTRAINT IF EXISTS stock_reservations_variant_id_foreign');
        }
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: add variant_id to stock_movements and stock_reservations (online DDL)"
```

---

### Task 7: Add `variant_id` to `product_batches` with partial unique + remove TODO

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php`
- Test: `apps/api/tests/Feature/BatchExpiry/BatchesVariantUniqueTest.php`

- [ ] **Step 1: Write failing test for both non-variant + variant batch coexistence per product**

```php
public function test_can_have_non_variant_and_variant_batch_with_same_number(): void
{
    $company = (string) Str::uuid();
    $product = Product::factory()->create(['company_id' => $company]);
    $variant = ProductVariant::factory()->create([
        'company_id' => $company,
        'product_id' => $product->id,
    ]);
    Batch::factory()->create([
        'company_id' => $company,
        'product_id' => $product->id,
        'variant_id' => null,
        'batch_number' => 'LOT-001',
    ]);
    Batch::factory()->create([
        'company_id' => $company,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'batch_number' => 'LOT-001',
    ]);
    $this->assertSame(2, Batch::where('batch_number', 'LOT-001')->count());
}

public function test_duplicate_variant_batch_rejected(): void
{
    // setup; two batches with same (company, product, variant, batch_number) — second fails
}
```

- [ ] **Step 2: Create migration (v3 fix: correct constraint name + CONCURRENTLY indexes + FK NOT VALID)**

The migration file declares `public $withinTransaction = false;` so we can use `CREATE INDEX CONCURRENTLY`.

```php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        // Step 1: add nullable variant_id column (metadata-only, fast)
        Schema::table('product_batches', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Step 2: FK as NOT VALID (does not block writes; validation deferred to maintenance migration)
        DB::statement('ALTER TABLE product_batches
            ADD CONSTRAINT product_batches_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        // Step 3: build replacement partial-unique indexes CONCURRENTLY (CRITICAL — verified constraint name)
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY product_batches_non_variant
                       ON product_batches (company_id, product_id, batch_number)
                       WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY product_batches_with_variant
                       ON product_batches (company_id, product_id, variant_id, batch_number)
                       WHERE variant_id IS NOT NULL');

        // Step 4: drop the OLD unique constraint (verified name from migration line 47)
        DB::statement('ALTER TABLE product_batches DROP CONSTRAINT unique_batch_per_product');
    }

    public function down(): void
    {
        // Reverse order
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_batches ADD CONSTRAINT unique_batch_per_product
                           UNIQUE (company_id, product_id, batch_number)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_batches_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_batches_non_variant');
            DB::statement('ALTER TABLE product_batches DROP CONSTRAINT IF EXISTS product_batches_variant_id_foreign');
        }
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

Add a follow-up migration (e.g., `2026_06_15_*_validate_t2_fks_on_large_tables.php`) that runs `VALIDATE CONSTRAINT` for the deferred FKs.

- [ ] **Step 3: Update the original migration file `2026_01_05_150000_create_product_batches_table.php`** — remove the TODO comment at line 23. (This is a doc-only edit; the column gets added by the new migration.)

- [ ] **Step 4: Run tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: add variant_id to product_batches + partial unique; remove TODO"
```

---

### Task 8: Add `variant_id` to `document_lines`, `pos_receipt_lines`, `pos_order_lines`, `pos_receipt_line_batch_allocations`

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100009_add_variant_id_to_document_lines.php`
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100010_add_variant_id_to_pos_receipt_lines.php`
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100011_add_variant_id_to_pos_order_lines.php`
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100012_add_variant_id_to_pos_receipt_line_batch_allocations.php`
- Test: `apps/api/tests/Feature/Inventory/SellableLinesVariantTest.php`

- [ ] **Step 1: Write failing test** verifying CHECK constraint on `pos_receipt_lines`:

```php
public function test_variant_id_requires_product_id_on_pos_receipt_lines(): void
{
    // try to insert variant_id=X with product_id=NULL (composite_item_id=Y) — must fail
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('pos_receipt_lines')->insert([
        // ... composite line shape but with variant_id set
    ]);
}
```

- [ ] **Step 2: Create the four migrations** with online-DDL FK split + CHECK as in §4.3 of the spec. Each migration declares `public $withinTransaction = false;`.

```php
// pos_receipt_lines example — apply the same pattern to document_lines, pos_order_lines,
// and pos_receipt_line_batch_allocations (vary table + constraint names)
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        // Step 1: add nullable variant_id column (metadata-only, fast)
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Step 2: FK as NOT VALID (validated in Task 11c)
        DB::statement('ALTER TABLE pos_receipt_lines
            ADD CONSTRAINT pos_receipt_lines_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        // Step 3: CHECK constraint (CHECKs do not require CONCURRENTLY; declared NOT VALID so
        // existing rows are not re-scanned, then validated in Task 11c)
        DB::statement("ALTER TABLE pos_receipt_lines
            ADD CONSTRAINT pos_receipt_lines_variant_requires_product
            CHECK (variant_id IS NULL OR product_id IS NOT NULL) NOT VALID");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_variant_requires_product');
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_variant_id_foreign');
        }
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

Repeat the same shape for `document_lines`, `pos_order_lines`, `pos_receipt_line_batch_allocations`. The `pos_receipt_line_batch_allocations` migration omits the CHECK constraint (batch allocations always belong to a sellable line that already has the product-level CHECK).

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: add variant_id to document_lines + POS sellable lines + batch allocations (online DDL)"
```

---

### Task 9: Add `variant_id` to `catalog_cart_items` and `price_list_items` with partial unique

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100013_add_variant_id_to_catalog_cart_items.php`
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100014_add_variant_id_to_price_list_items.php`
- Test: `apps/api/tests/Feature/Pricing/PriceListItemsVariantUniqueTest.php`

- [ ] **Step 1: Write failing test for `price_list_items` partial unique**

```php
public function test_variant_and_non_variant_price_list_items_coexist(): void
{
    $priceList = PriceList::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    PriceListItem::factory()->create([
        'price_list_id' => $priceList->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'min_quantity' => 1,
        'price' => '10.0000',
    ]);
    PriceListItem::factory()->create([
        'price_list_id' => $priceList->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'min_quantity' => 1,
        'price' => '12.0000',
    ]);
    $this->assertSame(2, PriceListItem::query()
        ->where('product_id', $product->id)
        ->count());
}
```

- [ ] **Step 2: Create migrations (both online-DDL — separate files; each declares `public $withinTransaction = false;`).**

```php
// 2026_06_02_100014_add_variant_id_to_price_list_items.php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE price_list_items
            ADD CONSTRAINT price_list_items_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE CASCADE NOT VALID');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY price_list_items_non_variant
                       ON price_list_items (price_list_id, product_id, min_quantity)
                       WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY price_list_items_with_variant
                       ON price_list_items (price_list_id, product_id, variant_id, min_quantity)
                       WHERE variant_id IS NOT NULL');

        // Drop the OLD unique (verified name at 2025_12_01_201028:23)
        DB::statement('ALTER TABLE price_list_items DROP CONSTRAINT price_list_product_qty_unique');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT price_list_product_qty_unique
                           UNIQUE (price_list_id, product_id, min_quantity)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_list_items_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_list_items_non_variant');
            DB::statement('ALTER TABLE price_list_items DROP CONSTRAINT IF EXISTS price_list_items_variant_id_foreign');
        }
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

```php
// 2026_06_02_100013_add_variant_id_to_catalog_cart_items.php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('catalog_cart_items', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE catalog_cart_items
            ADD CONSTRAINT catalog_cart_items_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY catalog_cart_items_cart_product_variant_idx
                       ON catalog_cart_items (cart_id, product_id, variant_id)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS catalog_cart_items_cart_product_variant_idx');
            DB::statement('ALTER TABLE catalog_cart_items DROP CONSTRAINT IF EXISTS catalog_cart_items_variant_id_foreign');
        }
        Schema::table('catalog_cart_items', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
```

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: variant_id on catalog_cart_items + price_list_items (partial unique, online DDL)"
```

---

### Task 9b: Fix `channel_product_mappings` unique to be NULL-safe (P1-4 — Codex r1)

**Critical:** the existing unique constraint at `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php:27` is `channel_product_variant_unique` covering `(channel_id, product_id, variant_id)`. PostgreSQL NULL semantics make this NOT unique for product-level mappings (multiple rows with `variant_id=NULL` permitted). T2 fixes it by swapping to a partial-unique pair.

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100013b_fix_channel_product_mappings_partial_unique.php`
- Test: `apps/api/tests/Feature/Channel/ChannelProductMappingsPartialUniqueTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_two_product_level_mappings_to_same_channel_should_be_rejected_after_fix(): void
{
    $channel = Channel::factory()->create();
    $product = Product::factory()->create();

    ChannelProductMapping::factory()->create([
        'channel_id' => $channel->id,
        'product_id' => $product->id,
        'variant_id' => null,
    ]);

    $this->expectException(\Illuminate\Database\QueryException::class);
    ChannelProductMapping::factory()->create([
        'channel_id' => $channel->id,
        'product_id' => $product->id,
        'variant_id' => null,
    ]);
}

public function test_variant_and_non_variant_mappings_coexist(): void
{
    $channel = Channel::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    ChannelProductMapping::factory()->create([
        'channel_id' => $channel->id, 'product_id' => $product->id, 'variant_id' => null,
    ]);
    ChannelProductMapping::factory()->create([
        'channel_id' => $channel->id, 'product_id' => $product->id, 'variant_id' => $variant->id,
    ]);

    $this->assertSame(2, ChannelProductMapping::query()
        ->where('channel_id', $channel->id)
        ->where('product_id', $product->id)
        ->count());
}
```

- [ ] **Step 2: Create migration**

```php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY channel_product_mappings_non_variant
                       ON channel_product_mappings (channel_id, product_id)
                       WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY channel_product_mappings_with_variant
                       ON channel_product_mappings (channel_id, product_id, variant_id)
                       WHERE variant_id IS NOT NULL');

        DB::statement('ALTER TABLE channel_product_mappings DROP CONSTRAINT channel_product_variant_unique');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE channel_product_mappings ADD CONSTRAINT channel_product_variant_unique
                           UNIQUE (channel_id, product_id, variant_id)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS channel_product_mappings_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS channel_product_mappings_non_variant');
        }
    }
};
```

- [ ] **Step 3: Run tests** → PASS (both — duplicate product-level rejected; variant-and-non-variant coexist).

- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.0.9b: Fix channel_product_mappings partial-unique for variant_id NULL semantics"
```

---

### Task 10: Add `component_variant_id` to `recipe_lines` with CHECK

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_02_100015_add_component_variant_id_to_recipe_lines.php`
- Test: `apps/api/tests/Feature/Catalog/RecipeLineComponentVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_recipe_line_can_reference_variant_when_component_is_product(): void
{
    $recipe = Recipe::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    $line = RecipeLine::factory()->create([
        'recipe_id' => $recipe->id,
        'component_type' => ComponentType::Product,
        'component_id' => $product->id,
        'component_variant_id' => $variant->id,
    ]);

    $this->assertSame($variant->id, $line->component_variant_id);
}

public function test_recipe_line_rejects_variant_when_component_is_composite(): void
{
    $this->expectException(\Illuminate\Database\QueryException::class);
    RecipeLine::factory()->create([
        'component_type' => ComponentType::CompositeItem,
        'component_variant_id' => (string) Str::uuid(),
    ]);
}
```

- [ ] **Step 2: Create migration (online-DDL; declare `public $withinTransaction = false;`)**

```php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->uuid('component_variant_id')->nullable()->after('component_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE recipe_lines
            ADD CONSTRAINT recipe_lines_component_variant_id_foreign
            FOREIGN KEY (component_variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY recipe_lines_component_variant_id_idx
                       ON recipe_lines (component_variant_id)');

        DB::statement("ALTER TABLE recipe_lines
            ADD CONSTRAINT recipe_lines_variant_requires_product
            CHECK (component_variant_id IS NULL OR component_type = 'product') NOT VALID");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_lines DROP CONSTRAINT IF EXISTS recipe_lines_variant_requires_product');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS recipe_lines_component_variant_id_idx');
            DB::statement('ALTER TABLE recipe_lines DROP CONSTRAINT IF EXISTS recipe_lines_component_variant_id_foreign');
        }
        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->dropColumn('component_variant_id');
        });
    }
};
```

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: add component_variant_id to recipe_lines + CHECK (online DDL)"
```

---

### Task 11: Migration suite roll-back test

**Files:**
- Test: `apps/api/tests/Feature/Migrations/T2MigrationRollbackTest.php`

- [ ] **Step 1: Write test**

```php
public function test_t2_migrations_roll_back_cleanly(): void
{
    Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--env' => 'testing']);
    // Capture row count of stock_levels (or any table where we re-created indexes)
    $before = Schema::hasColumn('stock_levels', 'variant_id');
    $this->assertTrue($before);

    // Roll back ONLY the T2 migrations (those dated 2026_06_02_*)
    foreach (range(15, 1) as $n) {
        Artisan::call('migrate:rollback', ['--step' => 1, '--env' => 'testing']);
    }

    $this->assertFalse(Schema::hasColumn('stock_levels', 'variant_id'));
    // Re-apply to leave DB in test-clean state
    Artisan::call('migrate', ['--env' => 'testing']);
}
```

- [ ] **Step 2: Run test** → PASS.
- [ ] **Step 3: Commit**

```bash
git commit -m "Phase 2.x: tests — verify T2 migration rollback round-trip"
```

---

### Task 11b: Shared/Contracts/ProductVariantLookup (P2-7 + P1-3 — v4 corrected path)

**Files (verified real path):**
- Create: `apps/api/app/Shared/Contracts/ProductVariantLookup.php` (interface; namespace `App\Shared\Contracts`)
- Create: `apps/api/app/Shared/DTOs/ProductVariantSummary.php` (namespace `App\Shared\DTOs`)
- Create: `apps/api/app/Modules/Catalog/Infrastructure/Adapters/EloquentProductVariantLookup.php` (namespace `App\Modules\Catalog\Infrastructure\Adapters`)
- Service-provider binding in `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php`.

**Why this lives in `App\Shared\Contracts` (not `App\Modules\Shared\Contracts`):** the real shared-contracts location is `apps/api/app/Shared/Contracts/` (verified — sibling contracts include `InventoryServiceInterface`, `LocationServiceInterface`, `LoyaltyServiceInterface`). v3 said `App\Modules\Shared` which does not exist in this codebase.

**Why this task is ordered HERE (after the schema migrations and before the service ripple):** every Phase 2 service task (notably Task 17 `PricingService::getPrice` which calls `$this->variants->findById($variantId)`) consumes this contract via constructor injection. The contract MUST exist before any service task tries to inject it.

- [ ] **Step 1: Write failing test for the contract**

```php
public function test_lookup_finds_variant_by_id(): void
{
    $variant = ProductVariant::factory()->create(['sku' => 'TEST-001']);
    /** @var \App\Shared\Contracts\ProductVariantLookup $lookup */
    $lookup = app(\App\Shared\Contracts\ProductVariantLookup::class);

    $result = $lookup->findById($variant->id);

    $this->assertNotNull($result);
    $this->assertSame('TEST-001', $result->sku);
    $this->assertInstanceOf(\App\Shared\DTOs\ProductVariantSummary::class, $result);
}

public function test_lookup_finds_by_sku_per_company(): void
{
    $companyId = (string) Str::uuid();
    $variant = ProductVariant::factory()->create(['sku' => 'COMP-1', 'company_id' => $companyId]);

    $result = app(\App\Shared\Contracts\ProductVariantLookup::class)->findBySku('COMP-1', $companyId);

    $this->assertNotNull($result);
    $this->assertSame($variant->id, $result->id);
}

public function test_lookup_lists_active_variants_for_product(): void
{
    $product = Product::factory()->create();
    ProductVariant::factory()->count(2)->create(['product_id' => $product->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => false]);

    $resultsActive = app(\App\Shared\Contracts\ProductVariantLookup::class)
        ->listForProduct($product->id, onlyActive: true);
    $resultsAll = app(\App\Shared\Contracts\ProductVariantLookup::class)
        ->listForProduct($product->id, onlyActive: false);

    $this->assertCount(2, $resultsActive);
    $this->assertCount(3, $resultsAll);
}
```

- [ ] **Step 2: Create the contract** (`apps/api/app/Shared/Contracts/ProductVariantLookup.php`)

```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\ProductVariantSummary;
use Illuminate\Support\Collection;

interface ProductVariantLookup
{
    public function findById(string $id): ?ProductVariantSummary;
    public function findByBarcode(string $barcode, string $companyId): ?ProductVariantSummary;
    public function findBySku(string $sku, string $companyId): ?ProductVariantSummary;
    /** @return Collection<int, ProductVariantSummary> */
    public function listForProduct(string $productId, bool $onlyActive = true): Collection;
}
```

- [ ] **Step 3: Create the DTO** (`apps/api/app/Shared/DTOs/ProductVariantSummary.php`)

```php
<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class ProductVariantSummary
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $tenantId,
        public string $companyId,
        public string $sku,
        public string $variantCode,
        public ?string $barcode,
        public string $nameSuffix,
        public bool $isDefault,
        public bool $isActive,
        public ?string $priceOverride,
        public ?string $costOverride,
        public ?string $imageUrl,
    ) {}
}
```

- [ ] **Step 4: Create the adapter**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Adapters;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\DTOs\ProductVariantSummary;
use Illuminate\Support\Collection;

final class EloquentProductVariantLookup implements ProductVariantLookup
{
    public function findById(string $id): ?ProductVariantSummary
    {
        return $this->toSummary(ProductVariant::query()->find($id));
    }

    public function findByBarcode(string $barcode, string $companyId): ?ProductVariantSummary
    {
        $variant = ProductVariant::query()
            ->where('company_id', $companyId)
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->first();
        return $this->toSummary($variant);
    }

    public function findBySku(string $sku, string $companyId): ?ProductVariantSummary
    {
        $variant = ProductVariant::query()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();
        return $this->toSummary($variant);
    }

    public function listForProduct(string $productId, bool $onlyActive = true): Collection
    {
        return ProductVariant::query()
            ->where('product_id', $productId)
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('display_order')
            ->get()
            ->map(fn (ProductVariant $v) => $this->toSummary($v));
    }

    private function toSummary(?ProductVariant $v): ?ProductVariantSummary
    {
        if ($v === null) return null;
        return new ProductVariantSummary(
            id: $v->id,
            productId: $v->product_id,
            tenantId: $v->tenant_id,
            companyId: $v->company_id,
            sku: $v->sku,
            variantCode: $v->variant_code,
            barcode: $v->barcode,
            nameSuffix: $v->name_suffix,
            isDefault: $v->is_default,
            isActive: $v->is_active,
            priceOverride: $v->price_override,
            costOverride: $v->cost_override,
            imageUrl: $v->image_url,
        );
    }
}
```

- [ ] **Step 5: Register the binding** in `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php`:

```php
$this->app->bind(
    \App\Shared\Contracts\ProductVariantLookup::class,
    \App\Modules\Catalog\Infrastructure\Adapters\EloquentProductVariantLookup::class,
);
```

- [ ] **Step 6: Run tests** → PASS.

- [ ] **Step 7: Commit**

```bash
git commit -m "Phase 0.2.11b: Shared ProductVariantLookup contract — preserve module boundaries"
```

---

### Task 11c: Deferred FK validation migration (P1-2 v4)

**Files:**
- Migration: `apps/api/database/migrations/tenant/2026_06_15_100000_validate_t2_foreign_keys.php` (dated to run AFTER all T2 migrations)

This task runs LAST among the Phase 1 schema work. It promotes every `NOT VALID` FK created in Tasks 5, 6, 7, 8, 9, 10 (and the `NOT VALID` CHECK constraints in Tasks 8 and 10) to the validated state. `VALIDATE CONSTRAINT` takes a SHARE UPDATE EXCLUSIVE lock — no write blockage.

- [ ] **Step 1: Create the migration**

```php
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        $constraints = [
            'stock_levels' => 'stock_levels_variant_id_foreign',
            'stock_movements' => 'stock_movements_variant_id_foreign',
            'stock_reservations' => 'stock_reservations_variant_id_foreign',
            'product_batches' => 'product_batches_variant_id_foreign',
            'document_lines' => 'document_lines_variant_id_foreign',
            'pos_receipt_lines' => 'pos_receipt_lines_variant_id_foreign',
            'pos_order_lines' => 'pos_order_lines_variant_id_foreign',
            'pos_receipt_line_batch_allocations' => 'pos_receipt_line_batch_allocations_variant_id_foreign',
            'catalog_cart_items' => 'catalog_cart_items_variant_id_foreign',
            'price_list_items' => 'price_list_items_variant_id_foreign',
            'recipe_lines' => 'recipe_lines_component_variant_id_foreign',
        ];

        foreach ($constraints as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
        }

        // Also validate the NOT VALID CHECK constraints added in Tasks 8 and 10
        $checks = [
            'document_lines' => 'document_lines_variant_requires_product',
            'pos_receipt_lines' => 'pos_receipt_lines_variant_requires_product',
            'pos_order_lines' => 'pos_order_lines_variant_requires_product',
            'recipe_lines' => 'recipe_lines_variant_requires_product',
        ];

        foreach ($checks as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
        }
    }

    public function down(): void
    {
        // No-op: validating a constraint is idempotent; once validated, cannot un-validate
    }
};
```

- [ ] **Step 2: Commit**

```bash
git commit -m "Phase 0.2.11c: Validate deferred T2 FKs (offline-safe)"
```

---

## Phase 2 — Service-layer ripple (~6 PD)

### Task 12: `AttributeService` and `ProductVariantService` skeleton + factories

**Files:**
- Create: `apps/api/app/Modules/Catalog/Application/Services/AttributeService.php`
- Create: `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php`
- Create commands: `apps/api/app/Modules/Catalog/Application/Commands/CreateAttributeCommand.php`, `AddAttributeValueCommand.php`, `CreateVariantCommand.php`, `UpdateVariantCommand.php`
- Create: `apps/api/app/Modules/Catalog/Application/Exceptions/VariantRequiredException.php`, `MissingVariantException.php`
- Test: `apps/api/tests/Feature/Catalog/AttributeServiceTest.php`
- Test: `apps/api/tests/Feature/Catalog/ProductVariantServiceTest.php`

- [ ] **Step 1: Write failing test for `AttributeService::create`**

```php
public function test_create_attribute_persists_and_emits_event(): void
{
    Event::fake([ProductAttributeCreated::class]);
    $svc = app(AttributeService::class);
    $cmd = new CreateAttributeCommand(
        code: 'taille',
        name: 'Taille',
        dataType: AttributeDataType::Selection,
        isVariantAxis: true,
    );

    $attr = $svc->create($cmd);

    $this->assertSame('taille', $attr->code);
    Event::assertDispatched(ProductAttributeCreated::class);
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement `AttributeService`**

```php
final class AttributeService
{
    public function __construct(
        private readonly AttributeRepository $attributes,
        private readonly AttributeValueRepository $values,
        private readonly EventDispatcher $events,
        private readonly TenantContext $tenant,
    ) {}

    public function create(CreateAttributeCommand $cmd): ProductAttribute
    {
        $attr = new ProductAttribute([
            'tenant_id' => $this->tenant->id(),
            'code' => $cmd->code,
            'name' => $cmd->name,
            'data_type' => $cmd->dataType,
            'is_variant_axis' => $cmd->isVariantAxis,
            'display_order' => $cmd->displayOrder,
            'is_active' => true,
        ]);
        $this->attributes->save($attr);
        $this->events->dispatch(new ProductAttributeCreated(
            attributeId: $attr->id,
            tenantId: $attr->tenant_id,
            code: $attr->code,
            dataType: $attr->data_type,
        ));
        return $attr;
    }

    public function addValue(AddAttributeValueCommand $cmd): ProductAttributeValue { /* ... */ }
    public function listForTenant(): Collection { /* delegate */ }
}
```

- [ ] **Step 4: Implement `ProductVariantService::createVariant` (minimal — just persists; matrix-gen comes Task 13)**

```php
public function createVariant(CreateVariantCommand $cmd): ProductVariant
{
    return DB::transaction(function () use ($cmd) {
        $variant = new ProductVariant([
            'tenant_id' => $this->tenant->id(),
            'company_id' => $cmd->companyId,
            'product_id' => $cmd->productId,
            'variant_code' => $cmd->variantCode,
            'sku' => $cmd->sku,
            'barcode' => $cmd->barcode,
            'name_suffix' => $cmd->nameSuffix,
            'is_default' => $cmd->isDefault,
            'is_active' => true,
            'price_override' => $cmd->priceOverride,
            'cost_override' => $cmd->costOverride,
            'image_url' => $cmd->imageUrl,
        ]);
        $this->variants->save($variant);

        foreach ($cmd->attributeValues as [$attributeId, $attributeValueId]) {
            ProductVariantAttributeValue::create([
                'variant_id' => $variant->id,
                'attribute_id' => $attributeId,
                'attribute_value_id' => $attributeValueId,
            ]);
        }

        // If this is the first active variant for the product, migrate stock to it.
        $isFirst = ProductVariant::where('product_id', $cmd->productId)
            ->where('is_active', true)
            ->where('id', '!=', $variant->id)
            ->doesntExist();
        if ($isFirst && $cmd->isDefault) {
            $this->stockMigrator->migrateToDefaultVariant($cmd->productId, $variant->id);
        }

        $this->events->dispatch(new ProductVariantCreated(/* ... */));
        return $variant;
    });
}
```

- [ ] **Step 5: Create domain events** (skeleton — they get filled out as services dispatch):

```php
// ProductVariantCreated.php — immutable, never modify
final class ProductVariantCreated extends DomainEvent
{
    public function __construct(
        public readonly string $variantId,
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $sku,
        public readonly string $variantCode,
        public readonly bool $isDefault,
    ) {}

    public function getEventName(): string { return 'product_variant.created'; }
    public function getAuditPayload(): array { return [...]; }
}
```

- [ ] **Step 6: Run tests** → PASS.

- [ ] **Step 7: Commit**

```bash
git commit -m "Phase 2.x: AttributeService + ProductVariantService skeleton + commands + events"
```

---

### Task 13: `ProductVariantMatrixGenerator` + matrix endpoint

**Files:**
- Create: `apps/api/app/Modules/Catalog/Application/Services/ProductVariantMatrixGenerator.php`
- Modify: `ProductVariantService::generateMatrix` to call the generator
- Test: `apps/api/tests/Unit/Catalog/Application/Services/ProductVariantMatrixGeneratorTest.php`

- [ ] **Step 1: Write failing unit test**

```php
public function test_two_axis_matrix_generates_cartesian(): void
{
    $sizes = ['39', '40', '41'];
    $colors = ['noir', 'blanc'];
    $generator = new ProductVariantMatrixGenerator();

    $combos = $generator->cartesian([
        'taille' => $sizes,
        'couleur' => $colors,
    ]);

    $this->assertCount(6, $combos);
    $this->assertContains(['taille' => '39', 'couleur' => 'noir'], $combos);
}

public function test_excluded_combos_skipped(): void
{
    $combos = $generator->cartesian(
        ['taille' => ['39', '40'], 'couleur' => ['noir', 'blanc']],
        excluded: [['taille' => '39', 'couleur' => 'noir']],
    );
    $this->assertCount(3, $combos);
    $this->assertNotContains(['taille' => '39', 'couleur' => 'noir'], $combos);
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement**

```php
final class ProductVariantMatrixGenerator
{
    /**
     * @param array<string, string[]> $axes attribute_code => [value_codes]
     * @param array<int, array<string, string>> $excluded
     * @return array<int, array<string, string>>
     */
    public function cartesian(array $axes, array $excluded = []): array
    {
        if ($axes === []) return [];
        $result = [[]];
        foreach ($axes as $axisCode => $values) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($values as $val) {
                    $next[] = $partial + [$axisCode => $val];
                }
            }
            $result = $next;
        }
        return array_values(array_filter($result, fn ($combo) => ! in_array($combo, $excluded, true)));
    }
}
```

- [ ] **Step 4: Implement `ProductVariantService::generateMatrix(UUID $productId, array $attributeIds)`** that wires the generator + persists N variants with unique generated SKUs and variant_codes.

- [ ] **Step 5: Write feature test for end-to-end matrix generation**

```php
public function test_generate_matrix_persists_18_variants_for_3x6(): void
{
    $product = Product::factory()->create();
    $taille = ProductAttribute::factory()->create(['code' => 'taille', 'is_variant_axis' => true]);
    $couleur = ProductAttribute::factory()->create(['code' => 'couleur', 'is_variant_axis' => true]);
    foreach (['36','37','38','39','40','41'] as $size) {
        ProductAttributeValue::factory()->create([
            'attribute_id' => $taille->id, 'code' => $size, 'label' => $size,
        ]);
    }
    foreach (['noir','blanc','beige'] as $color) {
        ProductAttributeValue::factory()->create([
            'attribute_id' => $couleur->id, 'code' => $color, 'label' => ucfirst($color),
        ]);
    }

    $variants = app(ProductVariantService::class)
        ->generateMatrix($product->id, [$taille->id, $couleur->id]);

    $this->assertCount(18, $variants);
    $skus = $variants->pluck('sku')->unique();
    $this->assertCount(18, $skus); // all SKUs unique
}
```

- [ ] **Step 6: Run tests** → PASS.
- [ ] **Step 7: Commit**

```bash
git commit -m "Phase 2.x: ProductVariantMatrixGenerator + service wiring"
```

---

### Task 14: `StockLevelMigrationService` (atomic migration to default variant)

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/Services/StockLevelMigrationService.php`
- Event: `apps/api/app/Modules/Inventory/Domain/Events/StockLevelsMigratedToDefaultVariant.php`
- Test: `apps/api/tests/Feature/Inventory/StockLevelMigrationServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_migrates_active_stock_levels_to_default_variant(): void
{
    $product = Product::factory()->create();
    $location = Location::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);

    StockLevel::factory()->create([
        'product_id' => $product->id,
        'variant_id' => null,
        'location_id' => $location->id,
        'quantity' => 10,
    ]);

    app(StockLevelMigrationService::class)
        ->migrateToDefaultVariant($product->id, $variant->id);

    $this->assertSame(0, StockLevel::where('product_id', $product->id)
        ->whereNull('variant_id')->count());
    $this->assertSame(1, StockLevel::where('product_id', $product->id)
        ->where('variant_id', $variant->id)->count());
}

public function test_historical_movements_not_rewritten(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'variant_id' => null,
    ]);

    app(StockLevelMigrationService::class)->migrateToDefaultVariant($product->id, $variant->id);

    // Movement stays variant_id=null — append-only audit
    $this->assertSame(1, StockMovement::where('product_id', $product->id)
        ->whereNull('variant_id')->count());
}

public function test_open_reservations_migrated_not_released(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $openRes = StockReservation::factory()->create([
        'product_id' => $product->id,
        'variant_id' => null,
        'released_at' => null,
    ]);
    $closedRes = StockReservation::factory()->create([
        'product_id' => $product->id,
        'variant_id' => null,
        'released_at' => now(),
    ]);

    app(StockLevelMigrationService::class)->migrateToDefaultVariant($product->id, $variant->id);

    $openRes->refresh(); $closedRes->refresh();
    $this->assertSame($variant->id, $openRes->variant_id);
    $this->assertNull($closedRes->variant_id);
}
```

- [ ] **Step 1b: Additional failing tests (P2-1 recipe migration + P2-2 large-migration guard)**

```php
public function test_recipes_pointing_at_product_get_rewritten_to_default_variant(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);
    $recipe = Recipe::factory()->create();
    $line = RecipeLine::factory()->create([
        'recipe_id' => $recipe->id,
        'component_type' => ComponentType::Product,
        'component_id' => $product->id,
        'component_variant_id' => null, // pre-variant recipe line
    ]);

    app(StockLevelMigrationService::class)
        ->migrateToDefaultVariant($product->id, $variant->id);

    $line->refresh();
    $this->assertSame($variant->id, $line->component_variant_id);
}

public function test_large_migration_refused_without_override(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);

    // Seed > 5000 stock_levels rows for this product (mock or test-config the threshold)
    // ...

    $this->expectException(LargeMigrationRefusalException::class);
    app(StockLevelMigrationService::class)
        ->migrateToDefaultVariant($product->id, $variant->id);
}
```

- [ ] **Step 2: Implement** (with recipe handling + large-migration guard)

```php
final class StockLevelMigrationService
{
    private const LARGE_MIGRATION_THRESHOLD = 5000;

    public function __construct(
        private readonly EventDispatcher $events,
    ) {}

    public function migrateToDefaultVariant(
        string $productId,
        string $defaultVariantId,
        bool $allowLargeMigration = false,
    ): void {
        $estimate = $this->estimateAffectedRows($productId);
        if ($estimate > self::LARGE_MIGRATION_THRESHOLD && ! $allowLargeMigration) {
            throw new LargeMigrationRefusalException(
                "Migration would affect {$estimate} rows; exceeds threshold "
                . self::LARGE_MIGRATION_THRESHOLD
                . ". Pass allowLargeMigration=true to override."
            );
        }

        DB::transaction(function () use ($productId, $defaultVariantId) {
            DB::table('stock_levels')
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->update(['variant_id' => $defaultVariantId, 'updated_at' => now()]);

            DB::table('stock_reservations')
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->whereNull('released_at')
                ->update(['variant_id' => $defaultVariantId, 'updated_at' => now()]);

            DB::table('product_batches')
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->where('is_active', true)
                ->update(['variant_id' => $defaultVariantId, 'updated_at' => now()]);

            // P2-1 — rewrite recipe lines referring to this product without a variant
            DB::table('recipe_lines')
                ->where('component_type', 'product')
                ->where('component_id', $productId)
                ->whereNull('component_variant_id')
                ->update(['component_variant_id' => $defaultVariantId, 'updated_at' => now()]);
        });

        $this->events->dispatch(new StockLevelsMigratedToDefaultVariant(
            productId: $productId,
            defaultVariantId: $defaultVariantId,
        ));
    }

    private function estimateAffectedRows(string $productId): int
    {
        return DB::table('stock_levels')->where('product_id', $productId)->whereNull('variant_id')->count()
            + DB::table('stock_reservations')->where('product_id', $productId)->whereNull('variant_id')->whereNull('released_at')->count()
            + DB::table('product_batches')->where('product_id', $productId)->whereNull('variant_id')->where('is_active', true)->count()
            + DB::table('recipe_lines')->where('component_type', 'product')->where('component_id', $productId)->whereNull('component_variant_id')->count();
    }
}
```

Plus a new exception class:

```php
// apps/api/app/Modules/Inventory/Application/Exceptions/LargeMigrationRefusalException.php
final class LargeMigrationRefusalException extends \DomainException {}
```

- [ ] **Step 3: Run tests** → PASS (original 3 from Step 1 + 2 new from Step 1b).

- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: StockLevelMigrationService — atomic migration + recipe lines + large-migration guard"
```

---

### Task 15: `StockAdjustmentService` variant-aware overloads

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- Update callsites: every direct caller (find via grep: `StockAdjustmentService::`)
- Test: `apps/api/tests/Feature/Inventory/StockAdjustmentServiceVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_receive_variant_creates_variant_scoped_stock_level(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $location = Location::factory()->create();

    app(StockAdjustmentService::class)->receive(
        productId: $product->id,
        locationId: $location->id,
        quantity: '5.0000',
        variantId: $variant->id,
    );

    $level = StockLevel::where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('location_id', $location->id)
        ->first();
    $this->assertNotNull($level);
    $this->assertSame('5.0000', $level->quantity);
}

public function test_receive_without_variant_creates_product_scoped_level_when_no_variants(): void
{
    $product = Product::factory()->create();
    $location = Location::factory()->create();

    app(StockAdjustmentService::class)->receive(
        productId: $product->id,
        locationId: $location->id,
        quantity: '3.0000',
    );

    $level = StockLevel::where('product_id', $product->id)
        ->whereNull('variant_id')
        ->first();
    $this->assertNotNull($level);
}

public function test_receive_without_variant_on_variant_bearing_product_raises(): void
{
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);

    $this->expectException(VariantRequiredException::class);
    app(StockAdjustmentService::class)->receive(
        productId: $product->id,
        locationId: Location::factory()->create()->id,
        quantity: '1.0000',
    );
}
```

- [ ] **Step 2: Update `StockAdjustmentService` — add `?string $variantId = null` trailing parameter to `receive`, `issue`, `transfer`, `reserve`, `release`. Update Eloquent queries to filter `where('variant_id', $variantId)` or `whereNull('variant_id')`.

```php
public function receive(
    string $productId,
    string $locationId,
    string $quantity,
    // ... other existing params
    ?string $variantId = null,
): StockMovement {
    $this->assertVariantConsistency($productId, $variantId);
    // ... existing logic, but with where('variant_id', $variantId) on level lookups, and variant_id set on movement insert
}

private function assertVariantConsistency(string $productId, ?string $variantId): void
{
    if ($variantId !== null) return; // explicit variant — fine
    $hasVariants = ProductVariant::where('product_id', $productId)->where('is_active', true)->exists();
    if ($hasVariants) {
        throw new VariantRequiredException("Product {$productId} has variants; variant_id required");
    }
}
```

- [ ] **Step 3: Sweep callsites (P1-2 — corrected grep pattern)** — callers inject `StockAdjustmentService` via constructor and call via instance method. The static-call grep `StockAdjustmentService::receive` returns zero matches. Use this multi-stage sweep instead:

```bash
# Stage A — find every consumer (anywhere `StockAdjustmentService` appears as a constructor-injected dependency)
grep -rn 'StockAdjustmentService [\$]' apps/api/app apps/api/tests
# Then, for each found file, read the constructor; record the property name (e.g., $stockAdjustmentService, $stockSvc)

# Stage B — find every method invocation (the property-arrow pattern, scoped per file as found in Stage A)
grep -rn '->receive(\|->issue(\|->transfer(\|->reserve(\|->adjust(' apps/api/app apps/api/tests | head -50
# Filter the results to those in files identified by Stage A (any method-name collision with other services needs disambiguation by reading the file)
```

For each callsite identified by Stage B: verify the call-site's product is non-variant OR pass `variantId` explicitly. List sweep results in the PR description. **Acceptance gate:** the implementer must produce an inventory of every consumer-file that uses `StockAdjustmentService` with the method-call line numbers; no consumer goes undocumented.

- [ ] **Step 4: Run tests** → PASS.
- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: refactor — StockAdjustmentService accepts optional variant_id"
```

---

### Task 16: `BatchStockService` and `FEFOInventoryService` variant-aware

**Files:**
- Modify: `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- Modify: `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- Test: `apps/api/tests/Feature/BatchExpiry/BatchStockServiceVariantTest.php`
- Test: `apps/api/tests/Feature/BatchExpiry/FEFOInventoryServiceVariantTest.php`

- [ ] **Step 1: Write failing test for `BatchStockService::findOrCreateBatch`**

```php
public function test_creates_variant_scoped_batch(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    $batch = app(BatchStockService::class)->findOrCreateBatch(
        productId: $product->id,
        batchNumber: 'LOT-001',
        expiryDate: now()->addMonths(6),
        variantId: $variant->id,
    );

    $this->assertSame($variant->id, $batch->variant_id);
}

public function test_rejects_product_level_batch_for_variant_bearing_product(): void
{
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id]);

    $this->expectException(MissingVariantException::class);
    app(BatchStockService::class)->findOrCreateBatch(
        productId: $product->id,
        batchNumber: 'LOT-002',
        expiryDate: now()->addMonths(6),
    );
}
```

- [ ] **Step 2: Write failing test for FEFO**

```php
public function test_fefo_returns_only_variant_batches_when_variant_passed(): void
{
    $product = Product::factory()->create();
    $variantA = ProductVariant::factory()->create(['product_id' => $product->id]);
    $variantB = ProductVariant::factory()->create(['product_id' => $product->id]);
    $loc = Location::factory()->create();

    $batchA = Batch::factory()->create([
        'product_id' => $product->id, 'variant_id' => $variantA->id,
        'expiry_date' => now()->addDays(30),
    ]);
    $batchB = Batch::factory()->create([
        'product_id' => $product->id, 'variant_id' => $variantB->id,
        'expiry_date' => now()->addDays(10),  // sooner
    ]);
    BatchStock::factory()->create(['batch_id' => $batchA->id, 'location_id' => $loc->id, 'quantity' => '5']);
    BatchStock::factory()->create(['batch_id' => $batchB->id, 'location_id' => $loc->id, 'quantity' => '5']);

    $suggestions = app(FEFOInventoryService::class)->suggestBatchesForSale(
        productId: $product->id,
        locationId: $loc->id,
        quantity: '3',
        variantId: $variantA->id,
    );

    $this->assertCount(1, $suggestions);
    $this->assertSame($batchA->id, $suggestions->first()->batch_id);
}
```

- [ ] **Step 3: Implement variant-aware `BatchStockService::findOrCreateBatch` + `FEFOInventoryService::suggestBatchesForSale`** per spec §7.

- [ ] **Step 4: Sweep callsites** — same pattern as Task 15. Run tests → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: refactor — BatchStockService + FEFO accept optional variant_id"
```

---

### Task 16b: NEW — `FEFOInventoryService::consumeBatchesAtomically` (P1-5, P1-6 — Codex r1)

**Critical correctness:** today's `suggestBatchesForSale` is read-only (no `lockForUpdate`); two cashiers receive identical suggestions; sale can shortfall and still commit. T2 introduces an atomic consume primitive.

**Files:**
- Modify: `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` — add new method.
- Modify: `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php` — `issueBatchStock` becomes a wrapper.
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` — `allocateBatches` switches to atomic + `$strictFulfillment=true` (removes "log shortfall and proceed" path).
- Create exception: `apps/api/app/Modules/BatchExpiry/Application/Exceptions/InsufficientBatchStockException.php`
- Create DTO: `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchConsumptionResultDTO.php`
- Create event: `apps/api/app/Modules/BatchExpiry/Domain/Events/BatchStockConsumed.php` (V1)
- Test: `apps/api/tests/Feature/BatchExpiry/AtomicFEFOConsumptionTest.php`

- [ ] **Step 1: Write failing concurrency test**

```php
public function test_atomic_consume_returns_locked_batches(): void
{
    $tenantId = (string) Str::uuid();
    $movementId = (string) Str::uuid();
    $product = Product::factory()->create(['tenant_id' => $tenantId]);
    $loc = Location::factory()->create();
    $batch = Batch::factory()->create(['product_id' => $product->id, 'expiry_date' => now()->addDays(10)]);
    BatchStock::factory()->create(['batch_id' => $batch->id, 'location_id' => $loc->id, 'quantity' => '5']);

    // Caller creates the parent stock_movements row first so movementId is a valid FK.
    DB::table('stock_movements')->insert([
        'id' => $movementId, 'tenant_id' => $tenantId, 'product_id' => $product->id,
        'location_id' => $loc->id, 'quantity' => '3', 'created_at' => now(),
    ]);

    $result = app(FEFOInventoryService::class)->consumeBatchesAtomically(
        tenantId: $tenantId,
        productId: $product->id,
        locationId: $loc->id,
        quantity: '3',
        movementId: $movementId,
        variantId: null,
        strictFulfillment: true,
    );

    $this->assertSame('0', $result->shortfall); // decimal string per BatchConsumptionResultDTO contract
    $this->assertCount(1, $result->consumed);
    $this->assertSame('2', BatchStock::find($result->consumed[0]->batchStockId)->quantity);
}

public function test_strict_fulfillment_throws_on_shortfall(): void
{
    $tenantId = (string) Str::uuid();
    $movementId = (string) Str::uuid();
    $product = Product::factory()->create(['tenant_id' => $tenantId]);
    $loc = Location::factory()->create();
    $batch = Batch::factory()->create(['product_id' => $product->id, 'expiry_date' => now()->addDays(10)]);
    BatchStock::factory()->create(['batch_id' => $batch->id, 'location_id' => $loc->id, 'quantity' => '1']);

    DB::table('stock_movements')->insert([
        'id' => $movementId, 'tenant_id' => $tenantId, 'product_id' => $product->id,
        'location_id' => $loc->id, 'quantity' => '5', 'created_at' => now(),
    ]);

    $this->expectException(InsufficientBatchStockException::class);
    app(FEFOInventoryService::class)->consumeBatchesAtomically(
        tenantId: $tenantId,
        productId: $product->id,
        locationId: $loc->id,
        quantity: '5',
        movementId: $movementId,
        strictFulfillment: true,
    );

    // Verify rollback: BatchStock still at 1
    $this->assertSame('1', BatchStock::where('batch_id', $batch->id)->first()->quantity);
}

public function test_concurrent_consume_one_succeeds_one_fails(): void
{
    // Use Laravel's DB::transaction with a real Postgres connection in tests
    // Spawn two processes (use Process or pcntl_fork) each calling consumeBatchesAtomically
    // for the same product+location+quantity that totals more than available stock.
    // Assert exactly one succeeds; the other gets InsufficientBatchStockException.

    $product = Product::factory()->create();
    $loc = Location::factory()->create();
    $batch = Batch::factory()->create(['product_id' => $product->id, 'expiry_date' => now()->addDays(10)]);
    BatchStock::factory()->create(['batch_id' => $batch->id, 'location_id' => $loc->id, 'quantity' => '3']);

    // Use parallel-test harness or DB::transaction simulation; verify exactly one of two qty-2 calls succeeds.
    $results = $this->runConcurrentConsumes($product->id, $loc->id, qty: '2', concurrency: 2);

    $successful = collect($results)->filter(fn ($r) => $r['ok'])->count();
    $failed = collect($results)->filter(fn ($r) => ! $r['ok'])->count();
    $this->assertSame(1, $successful);
    $this->assertSame(1, $failed);
}
```

- [ ] **Step 2: Implement DTOs + exception + event (v4 — corrected against real schema)**

Real `inventory_batch_movements` schema (verified at `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php`):
- `id` is `$table->id()` — bigint auto-increment, NOT UUID; do NOT supply manually
- `tenant_id` UUID (required)
- `batch_id` FK to `product_batches.id` (bigint, NOT UUID)
- `movement_id` FK to `stock_movements.id` (required, UUID)
- `quantity` decimal(15,4)
- `created_at` timestamp

```php
// BatchConsumptionResultDTO.php
final class BatchConsumptionResultDTO
{
    /** @param array<int, ConsumedBatchDTO> $consumed */
    public function __construct(
        public readonly array $consumed,
        public readonly string $shortfall, // decimal string — preserves fractional precision (P2-9)
    ) {}

    public function hasShortfall(): bool
    {
        return bccomp($this->shortfall, '0', 4) > 0;
    }
}

final class ConsumedBatchDTO
{
    public function __construct(
        public readonly int $batchId, // product_batches.id is bigint
        public readonly int $batchStockId, // inventory_batch_stock.id is bigint
        public readonly string $quantityConsumed, // decimal string
        public readonly \DateTimeInterface $expiryDate,
    ) {}
}

// InsufficientBatchStockException.php
final class InsufficientBatchStockException extends \DomainException
{
    public function __construct(public readonly string $shortfall, string $message = '')
    {
        parent::__construct($message ?: "Insufficient batch stock; shortfall: {$shortfall}");
    }
}
```

- [ ] **Step 3: Implement `consumeBatchesAtomically` (v4 — matches real schema + raw SKIP LOCKED + afterCommit event)**

```php
public function consumeBatchesAtomically(
    string $tenantId,                  // NEW: required for inventory_batch_movements
    string $productId,
    string $locationId,
    string $quantity,
    string $movementId,                // NEW: required FK to stock_movements (caller creates the stock_movements row first)
    ?string $variantId = null,
    bool $strictFulfillment = true,
): BatchConsumptionResultDTO {
    return DB::transaction(function () use ($tenantId, $productId, $locationId, $quantity, $movementId, $variantId, $strictFulfillment) {
        // Lock candidate batch_stock rows with FOR UPDATE SKIP LOCKED via raw SQL
        // (Laravel's lockForUpdate() does FOR UPDATE without SKIP LOCKED — we need SKIP for the SKIP semantics)
        $variantPredicate = $variantId !== null
            ? 'b.variant_id = ?'
            : 'b.variant_id IS NULL';

        $bindings = [$productId];
        if ($variantId !== null) $bindings[] = $variantId;
        $bindings = array_merge($bindings, [$locationId, now()->toDateString()]);

        $rows = DB::select("
            SELECT ibs.id AS batch_stock_id,
                   ibs.batch_id,
                   ibs.quantity AS stored_quantity,
                   ibs.available_quantity,
                   b.expiry_date
            FROM inventory_batch_stock AS ibs
            JOIN product_batches AS b ON ibs.batch_id = b.id
            WHERE b.product_id = ?
              AND {$variantPredicate}
              AND ibs.location_id = ?
              AND ibs.available_quantity > 0
              AND b.is_active = TRUE
              AND b.is_recalled = FALSE
              AND b.expiry_date >= ?
            ORDER BY b.expiry_date ASC, b.created_at ASC
            FOR UPDATE OF ibs SKIP LOCKED
        ", $bindings);

        $remaining = $quantity;
        $consumed = [];

        foreach ($rows as $row) {
            if (bccomp($remaining, '0', 4) <= 0) break;

            // Take min(available_quantity, remaining) — available_quantity is the post-reservation usable amount
            $take = bccomp($row->available_quantity, $remaining, 4) < 0 ? $row->available_quantity : $remaining;

            // Decrement the stored quantity (available_quantity is a generated column = quantity - reserved_quantity;
            // decrementing quantity auto-recomputes available_quantity)
            DB::table('inventory_batch_stock')
                ->where('id', $row->batch_stock_id)
                ->decrement('quantity', $take);

            // Insert audit row matching the real inventory_batch_movements schema
            DB::table('inventory_batch_movements')->insert([
                'tenant_id'   => $tenantId,
                'batch_id'    => $row->batch_id,
                'movement_id' => $movementId,
                'quantity'    => $take,
                'created_at'  => now(),
            ]);

            $consumed[] = new ConsumedBatchDTO(
                batchId: (int) $row->batch_id,
                batchStockId: (int) $row->batch_stock_id,
                quantityConsumed: $take,
                expiryDate: \Carbon\Carbon::parse($row->expiry_date),
            );

            $remaining = bcsub($remaining, $take, 4);
        }

        if ($strictFulfillment && bccomp($remaining, '0', 4) > 0) {
            throw new InsufficientBatchStockException($remaining);
        }

        // Dispatch event AFTER COMMIT — rolling back the transaction must not leave an orphan event
        DB::afterCommit(function () use ($productId, $variantId, $locationId, $consumed) {
            event(new BatchStockConsumed(
                productId: $productId,
                variantId: $variantId,
                locationId: $locationId,
                consumed: $consumed,
            ));
        });

        return new BatchConsumptionResultDTO($consumed, $remaining);
    });
}
```

**Caller contract:** the caller (e.g., `BatchStockService::issueBatchStock`, `ReceiptCreationService::allocateBatches`) creates the parent `stock_movements` row FIRST, then calls `consumeBatchesAtomically($tenantId, $productId, $locationId, $qty, $movementId=<the_stock_movement_id>, ...)`. This preserves the existing `inventory_batch_movements.movement_id` audit linkage.

- [ ] **Step 4: Migrate callers to atomic API**
  - `BatchStockService::issueBatchStock` becomes a thin wrapper that calls `consumeBatchesAtomically` with `$strictFulfillment=true`.
  - `ReceiptCreationService::allocateBatches` (line 1312-1321 of POS) — replaces "log shortfall and proceed" with `$strictFulfillment=true`; on shortfall, exception bubbles and the receipt is rejected before commit. **This changes POS behavior** — sales of batch-tracked products with insufficient stock now FAIL instead of completing with a logged shortfall. Document the change in the impl PR; the new behavior is what T2 acceptance §10.3 requires.
  - Callers that want the old advisory behavior (e.g., capacity-planning reports) call `suggestBatchesForSale` instead — unchanged.

- [ ] **Step 5: Run all four tests** → PASS.

- [ ] **Step 6: Commit**

```bash
git commit -m "Phase 2.1.16b: FEFOInventoryService atomic consume + InsufficientBatchStockException"
```

---

### Task 17: `PricingService::getPrice` variant-aware resolution

**Files:**
- Modify: `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php`
- Create: `apps/api/app/Modules/Pricing/README.md` documenting resolution order
- Test: `apps/api/tests/Feature/Pricing/PricingServiceVariantTest.php`

- [ ] **Step 1: Write failing tests using REAL signature (v3 fix — Codex P1-3 corrected)**

The real `PricingService::getPrice` signature (verified at `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:32`) is:
```php
getPrice(string $productId, ?string $partnerId = null, string $quantity = '1.00', string $currency = 'USD', ?\DateTimeInterface $date = null): array
```
returning `array{price, source, price_list_id}`. T2 appends `?string $variantId = null` as a trailing optional parameter.

```php
public function test_variant_override_wins_over_price_list(): void
{
    $product = Product::factory()->create(['sale_price' => '100.00', 'company_id' => $companyId = (string) Str::uuid()]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'company_id' => $companyId,
        'price_override' => '75.00',
    ]);
    $partner = Partner::factory()->create();
    $priceList = PriceList::factory()->create(['currency' => 'TND']);
    PartnerPriceList::factory()->create(['partner_id' => $partner->id, 'price_list_id' => $priceList->id]);
    PriceListItem::factory()->create([
        'price_list_id' => $priceList->id, 'product_id' => $product->id, 'variant_id' => null,
        'min_quantity' => '1.00', 'price' => '90.00',
    ]);

    $result = app(PricingService::class)->getPrice(
        productId: $product->id,
        partnerId: $partner->id,
        quantity: '1.00',
        currency: 'TND',
        date: now(),
        variantId: $variant->id,
    );

    $this->assertSame('75.00', $result['price']);
    $this->assertSame('variant_override', $result['source']);
}

public function test_variant_price_list_item_used_when_no_override(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id, 'price_override' => null,
    ]);
    $partner = Partner::factory()->create();
    $priceList = PriceList::factory()->create();
    PartnerPriceList::factory()->create(['partner_id' => $partner->id, 'price_list_id' => $priceList->id]);
    PriceListItem::factory()->create([
        'price_list_id' => $priceList->id, 'product_id' => $product->id, 'variant_id' => $variant->id,
        'min_quantity' => '1.00', 'price' => '80.00',
    ]);

    $result = app(PricingService::class)->getPrice(
        productId: $product->id,
        partnerId: $partner->id,
        variantId: $variant->id,
    );

    $this->assertSame('80.00', $result['price']);
    $this->assertSame('partner_price_list', $result['source']);
}

public function test_falls_back_to_variant_agnostic_then_product(): void
{
    $product = Product::factory()->create(['sale_price' => '100.00']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id, 'price_override' => null,
    ]);
    $priceList = PriceList::factory()->create();
    PriceListItem::factory()->create([
        'price_list_id' => $priceList->id, 'product_id' => $product->id, 'variant_id' => null,
        'min_quantity' => '1.00', 'price' => '95.00',
    ]);

    $result = app(PricingService::class)->getPrice(
        productId: $product->id,
        variantId: $variant->id,
    );

    $this->assertSame('95.00', $result['price']);
    $this->assertSame('default_price_list', $result['source']);
}

public function test_existing_non_variant_callers_unchanged(): void
{
    // Verify that callers that DON'T pass variantId continue working
    $product = Product::factory()->create(['sale_price' => '50.00']);

    $result = app(PricingService::class)->getPrice(productId: $product->id);

    $this->assertSame('50.00', $result['price']);
}
```

- [ ] **Step 2: Update `PricingService::getPrice` signature**

```php
public function getPrice(
    string $productId,
    ?string $partnerId = null,
    string $quantity = '1.00',
    string $currency = 'USD',
    ?\DateTimeInterface $date = null,
    ?string $variantId = null,  // NEW (T2)
): array {
    $date = $date ?? now();

    // 0. NEW: variant override wins (when variant is set + has price_override)
    if ($variantId !== null) {
        $variant = $this->variants->findById($variantId);  // uses ProductVariantLookup contract
        if ($variant !== null && $variant->priceOverride !== null) {
            return [
                'price' => $variant->priceOverride,
                'source' => 'variant_override',
                'price_list_id' => null,
            ];
        }
    }

    // 1. Try partner-specific price list (variant-aware lookup inside)
    if ($partnerId !== null) {
        $partnerPrice = $this->getPartnerPrice($partnerId, $productId, $quantity, $currency, $date, $variantId);
        // ... return if non-null
    }

    // 2. Try default price list for currency (variant-aware)
    $defaultPrice = $this->getDefaultPriceListPrice($productId, $quantity, $currency, $date, $variantId);
    // ... return if non-null

    // 3. Product sale_price fallback (unchanged)
    // ...
}
```

Update private helpers `getPartnerPrice`, `getDefaultPriceListPrice`, `getPriceFromList`, `getQuantityBreaks` to accept and propagate `?string $variantId = null`. Inside each, the price_list_items query checks both `variant_id = ?` and `variant_id IS NULL` rows in priority order (variant-specific row first).

- [ ] **Step 3: Write resolution-order doc (CANONICAL per spec §9.2 — DO NOT reorder)**

`apps/api/app/Modules/Pricing/README.md`:
```markdown
# Pricing — variant-aware resolution

`PricingService::getPrice` resolution order. The first match wins; never reorder this list — it is locked by spec §9.2.

1. **Variant `price_override`** — when `$variantId !== null` and the variant has a non-null `price_override`. Returned with `source = 'variant_override'`.
2. **Partner price list, variant-specific** — when `$partnerId !== null`: `price_list_items` row matching `(price_list_id, product_id, variant_id = $variantId, min_quantity <= $qty)` (highest qualifying `min_quantity`). Returned with `source = 'partner_price_list'`.
3. **Partner price list, variant-agnostic** — when `$partnerId !== null`: `price_list_items` row matching `(price_list_id, product_id, variant_id IS NULL, min_quantity <= $qty)` (highest qualifying `min_quantity`). Returned with `source = 'partner_price_list'`.
4. **Default price list, variant-specific** — default-currency price list `price_list_items` row matching `(price_list_id, product_id, variant_id = $variantId, min_quantity <= $qty)`. Returned with `source = 'default_price_list'`.
5. **Default price list, variant-agnostic** — default-currency price list `price_list_items` row matching `(price_list_id, product_id, variant_id IS NULL, min_quantity <= $qty)`. Returned with `source = 'default_price_list'`.
6. **Product `sale_price`** — final fallback. Returned with `source = 'product_sale_price'`.

Any earlier text that suggests `price_override` is a fallback (rather than the highest-priority match) is superseded by this list.
```

- [ ] **Step 4: Run tests** → PASS.
- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: PricingService variant-aware resolution + README"
```

---

### Task 18: POS `ReceiptCreationService::decrementStock` variant-aware

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (lines ~837-904)
- Modify: `apps/api/app/Modules/POS/Application/DTOs/OrderLineData.php` — add `variantId`
- Test: `apps/api/tests/Feature/POS/ReceiptCreationVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_variant_line_decrements_variant_stock_row(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $location = Location::factory()->create();
    StockLevel::factory()->create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'location_id' => $location->id,
        'quantity' => '10',
    ]);

    // Create receipt with one variant line
    $svc = app(ReceiptCreationService::class);
    $receipt = $svc->finalize(/* ... shape of input DTOs with variant_id set on line */);

    $level = StockLevel::where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('location_id', $location->id)
        ->first();
    $this->assertSame('9', $level->quantity);

    // Confirm StockMovementRecordedV2 fired with variant_id
    // ... event assertion
}
```

- [ ] **Step 2: Update `decrementStock`** — read `variant_id` from each line; use `(product_id, variant_id, location_id)` tuple for stock decrement; pass variant_id to `BatchStockService::issueFromBatches` if batch tracking applies.

- [ ] **Step 3: Update `OrderLineData` DTO**

```php
final class OrderLineData
{
    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        // ... other existing fields
    ) {}
    // ... fromModel() updates to include variant_id mapping
}
```

- [ ] **Step 4: Regenerate TypeScript types**

```bash
cd apps/erp/apps/api && php artisan typescript:transform
```

- [ ] **Step 5: Run tests** → PASS.

- [ ] **Step 6: Commit**

```bash
git commit -m "Phase 2.x: refactor — POS ReceiptCreationService variant-aware decrement + DTO"
```

---

### Task 19: V2/V3 domain events for stock + document lines (DUAL-DISPATCH per P1-1)

**Critical correctness:** existing V1 subscribers (notably `DispatchStockChangeToChannels` on `StockMovementRecorded`) MUST continue working after T2 lands. Spec §6.4 mandates dual-dispatch: producer emits V1 first (unchanged shape), V2 second (with `variantId`). Both fire on every write.

**Files:**
- Create: `apps/api/app/Modules/Inventory/Domain/Events/StockMovementRecordedV2.php`
- Create: `apps/api/app/Modules/Document/Domain/Events/DraftLineAddedV3.php`, `DraftLineModifiedV3.php`, `DraftLineRemovedV2.php`
- Create: `apps/api/app/Modules/Document/Domain/Events/SalesOrderConfirmedV2.php`
- Create: `ReservationCreatedV2.php`, `ReservationExpiredV2.php`, `ReservationReleasedV2.php`
- Test: `apps/api/tests/Feature/Events/T2EventsV2DualDispatchTest.php`

- [ ] **Step 1: Enumerate existing V1 subscribers (P1-1 acceptance gate)**

```bash
grep -rn 'Event::listen.*StockMovementRecorded' apps/api/app
grep -rn 'StockMovementRecorded.*class' apps/api/app/Modules/*/Application/Listeners
grep -rln 'DraftLineAdded' apps/api/app
grep -rln 'DraftLineModified' apps/api/app
grep -rln 'ReservationCreated\|ReservationExpired\|ReservationReleased' apps/api/app
```

Record the full subscriber inventory in the impl PR description. Confirmed minimum subscribers (as of 2026-05-28 dev):
- `StockMovementRecorded` → `DispatchStockChangeToChannels` (Channel module).
- Any in-place V1 audit listeners (TBD by grep above).

If any subscriber is NOT on this list, the implementer adds it before proceeding.

- [ ] **Step 2: Write failing dual-dispatch test**

```php
public function test_dual_dispatch_V1_and_V2_for_variant_receive(): void
{
    Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    app(StockAdjustmentService::class)->receive(
        productId: $product->id,
        locationId: Location::factory()->create()->id,
        quantity: '1',
        variantId: $variant->id,
    );

    // V1 carries the original shape (no variant_id)
    Event::assertDispatched(StockMovementRecorded::class);
    // V2 carries variant_id
    Event::assertDispatched(StockMovementRecordedV2::class, fn ($e) =>
        $e->variantId === $variant->id
    );
}

public function test_dual_dispatch_for_non_variant_receive_emits_both_with_null_variant_in_V2(): void
{
    Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);
    $product = Product::factory()->create();

    app(StockAdjustmentService::class)->receive(
        productId: $product->id,
        locationId: Location::factory()->create()->id,
        quantity: '1',
    );

    Event::assertDispatched(StockMovementRecorded::class);
    Event::assertDispatched(StockMovementRecordedV2::class, fn ($e) => $e->variantId === null);
}
```

- [ ] **Step 3: Create the new event classes** — each new file mirrors V1/V2 predecessor, adds `variantId`. **Do not touch V1/V2 files** — immutable per CLAUDE.md rule 8.

```php
final class StockMovementRecordedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $movementId,
        public readonly string $productId,
        public readonly ?string $variantId,
        // ... ALL other fields from V1, copied verbatim
    ) {}

    public function getEventName(): string { return 'inventory.stock_movement_recorded.v2'; }
    public function getAuditPayload(): array { return [/* ... including variant_id */]; }
}
```

- [ ] **Step 4: Update ALL SIX producers to dispatch BOTH (P1-7 — Codex r1 enumerated)**

`StockMovementRecorded` is dispatched from 6 sites. Update every one to emit both V1 and V2:

| File | Line | Method |
|---|---|---|
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | 80 | `receive()` |
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | 169 | `issue()` |
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | 277 | `transfer()` outbound leg |
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | 292 | `transfer()` inbound leg |
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | 453 | `adjust()` |
| `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` | 476 | WAC update path |

```php
// Pattern applied at every dispatch site:
DB::afterCommit(function () use (/* ... */) {
    // Dispatch V1 first (unchanged shape, subscribers stay safe)
    event(new StockMovementRecorded(/* original args, no variant_id */));
    // Dispatch V2 second (carries variant_id; null when product has no variants)
    event(new StockMovementRecordedV2(/* original args + variant_id */));
});
```

Apply the same dual-dispatch pattern to producers of:
- `DraftLineAddedV2` (find via `grep -rln 'new DraftLineAddedV2' apps/api/app`) → also dispatch `DraftLineAddedV3`.
- `DraftLineModifiedV2` → also `DraftLineModifiedV3`.
- `DraftLineRemoved` → also `DraftLineRemovedV2`.
- `ReservationCreated` / `Expired` / `Released` → also V2 each.
- `SalesOrderConfirmed` → also `SalesOrderConfirmedV2` (P2-8 — REQUIRED; payload is serialized with line arrays at `SalesOrderConfirmed.php:17-31`; the V2 line array adds `variant_id` per entry).

The impl PR description MUST contain a complete table of (file:line, producer method, V1 event class, V2 event class) for every dispatch site. Missing any single site means variant sales silently lose `variantId` in events at that path.

- [ ] **Step 5: Add `SalesOrderConfirmedV2` to the file list (REQUIRED per spec §6.4)** — spec §6.4 has already verified that `SalesOrderConfirmed`'s payload serializes `lines` as an array of arrays (`apps/api/app/Modules/Document/Domain/Events/SalesOrderConfirmed.php:17-31`). That payload shape locks in the line metadata at dispatch time, so adding `variant_id` requires a new event version. Create `apps/api/app/Modules/Document/Domain/Events/SalesOrderConfirmedV2.php` mirroring V1 with `variant_id` per line, and dual-dispatch alongside V1 at every producer site. Add this file to the "Files" list at the top of this task. The conditional "If lines are serialized" phrasing has been removed — this is unconditional.

- [ ] **Step 6: Run tests** → PASS (both dual-dispatch tests + existing V1 listener test continues to pass).

- [ ] **Step 7: Update spec-listed acceptance criteria §10.6 dual-dispatch test** — verify both subscribers fire.

- [ ] **Step 8: Commit**

```bash
git commit -m "Phase 2.x: V2/V3 events with dual-dispatch for backward compat with V1 subscribers"
```

**Out-of-T2 follow-up note (v4 corrected — Codex r2 P1-6):** `DispatchStockChangeToChannels` IS migrated to V2 in T2 scope (handled in Task 26) because the V1 event lacks `variantId` and the listener cannot otherwise scope a stock change to one variant. **All other V1 subscribers** (enumerated in Step 1) stay on V1 throughout T2; they get migrated in a follow-up PR. Once all subscribers are V2-native, dual-dispatch can stop.

---

### Task 20: `GoodsReceiptService` and `InventoryCountingService` variant pass-through

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` (if exists; check naming)
- Test: `apps/api/tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php`

- [ ] **Step 1: Write failing test for receipt with variant lines**

```php
public function test_finalize_receipt_with_variant_line_creates_variant_stock(): void
{
    // ... PO with one line that has variant_id; finalize; assert StockLevel for variant
}
```

- [ ] **Step 2: Read `GoodsReceiptService::finalize`** — find the loop that creates stock movements per line; pass each line's `variant_id` to `StockAdjustmentService::receive`.

- [ ] **Step 3: Same for `InventoryCountingService`** — the column already exists (Task 5); just stop passing NULL.

- [ ] **Step 4: Run tests** → PASS.
- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: refactor — GoodsReceipt + InventoryCounting variant pass-through"
```

---

## Phase 3 — Recipe + variant + expiry inheritance (~3 PD)

### Task 21: `RecipeService::addLine` accepts `component_variant_id`

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Application/Services/RecipeService.php`
- Test: `apps/api/tests/Feature/Catalog/RecipeServiceVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_add_line_with_variant_component(): void
{
    $recipe = Recipe::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    $line = app(RecipeService::class)->addLine(
        recipeId: $recipe->id,
        componentType: ComponentType::Product,
        componentId: $product->id,
        quantity: '2.5',
        componentVariantId: $variant->id,
    );

    $this->assertSame($variant->id, $line->component_variant_id);
}
```

- [ ] **Step 2: Modify `RecipeService::addLine`** — append `?string $componentVariantId = null` parameter, persist to new column.

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: RecipeService::addLine accepts componentVariantId"
```

---

### Task 22: `RecipeCostCalculationService` reads variant `cost_override` (ADVISORY only — P1-4)

**Critical:** variant `cost_override` is **advisory only**. It participates in recipe cost (for menu engineering / margin display) but **does NOT** affect inventory WAC / GL postings. Test must explicitly verify the drift behavior.

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Application/Services/RecipeCostCalculationService.php`
- Test: `apps/api/tests/Feature/Catalog/RecipeCostCalculationVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_uses_variant_cost_override_when_present(): void
{
    $product = Product::factory()->create(['cost_price' => '10.0000']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'cost_override' => '12.0000',
    ]);
    $recipe = Recipe::factory()->create();
    RecipeLine::factory()->create([
        'recipe_id' => $recipe->id,
        'component_type' => ComponentType::Product,
        'component_id' => $product->id,
        'component_variant_id' => $variant->id,
        'quantity' => '2',
        'wastage_percent' => '0',
    ]);

    $cost = app(RecipeCostCalculationService::class)->calculate($recipe->id);
    $this->assertSame('24.0000', $cost);  // 12 * 2
}

public function test_falls_back_to_product_cost_when_variant_override_null(): void
{
    // similar, but cost_override = null; assert uses product->cost_price
}

public function test_recipe_cost_does_not_affect_inventory_wac(): void
{
    // P1-4 — variant cost is advisory; inventory WAC stays product-grain
    $product = Product::factory()->create(['cost_price' => '10.00']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'cost_override' => '12.00',
    ]);
    $recipe = Recipe::factory()->create();
    RecipeLine::factory()->create([
        'recipe_id' => $recipe->id,
        'component_id' => $product->id,
        'component_variant_id' => $variant->id,
        'quantity' => '1',
    ]);

    // Recipe COGS uses variant cost (advisory)
    $recipeCogs = app(RecipeCostCalculationService::class)->calculate($recipe->id);
    $this->assertSame('12.0000', $recipeCogs);

    // But inventory WAC for the product stays at product->cost_price after a receive
    app(StockAdjustmentService::class)->receive(
        productId: $product->id,
        locationId: Location::factory()->create()->id,
        quantity: '5',
        variantId: $variant->id,
    );
    $product->refresh();
    $this->assertSame('10.00', $product->cost_price);  // unchanged
}
```

- [ ] **Step 2: Implement** the variant `cost_override` lookup — when `component_variant_id` is set on a line, read the variant's `cost_override`; if null, fall back to `product.cost_price`.

- [ ] **Step 2b: Add comment block at top of `RecipeCostCalculationService.php`**

```php
/**
 * Computes the total cost of a recipe, summing `unit_cost * quantity * (1 + wastage_percent/100)`
 * across all RecipeLines.
 *
 * IMPORTANT (T2): when a RecipeLine has `component_variant_id` set, the unit cost is read from
 * `product_variants.cost_override` (falls back to `products.cost_price` if NULL). This is ADVISORY
 * cost — used for menu engineering, margin display, recipe COGS reports. It does NOT post to the GL;
 * inventory accounting uses product-grain WAC.
 *
 * Reconciliation reports that compare recipe COGS against inventory WAC depletion will show drift
 * when variant cost_override differs from product cost_price; this is expected and documented in
 * apps/api/app/Modules/Pricing/README.md and apps/api/app/Modules/Inventory/README.md.
 */
```

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: RecipeCostCalculation uses variant cost_override (advisory; WAC stays product-grain)"
```

---

### Task 23: `CompositeItemAvailabilityService` reads variant stock

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Application/Services/CompositeItemAvailabilityService.php`
- Test: `apps/api/tests/Feature/Catalog/CompositeAvailabilityVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_availability_uses_variant_stock_when_recipe_line_is_variant(): void
{
    // Build a recipe with one variant-scoped line; create variant stock; assert availability
}

public function test_availability_uses_product_stock_when_line_is_not_variant(): void
{
    // build product-scoped line + product-scoped stock; assert availability matches old behavior
}
```

- [ ] **Step 2: Modify** the availability query to check `component_variant_id` per line and resolve stock accordingly:

```php
foreach ($recipe->activeLines as $line) {
    $level = $line->component_variant_id !== null
        ? StockLevel::where('product_id', $line->component_id)
            ->where('variant_id', $line->component_variant_id)
            ->where('location_id', $locationId)
            ->first()
        : StockLevel::where('product_id', $line->component_id)
            ->whereNull('variant_id')
            ->where('location_id', $locationId)
            ->first();
    // ... existing min() logic
}
```

- [ ] **Step 3: Run tests** → PASS.
- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: CompositeItemAvailabilityService variant-aware stock lookup"
```

---

### Task 24: `RecipeExpiryService` (NEW)

**Files:**
- Create: `apps/api/app/Modules/Catalog/Application/Services/RecipeExpiryService.php`
- Test: `apps/api/tests/Feature/Catalog/RecipeExpiryServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_returns_earliest_expiry_across_ingredient_batches(): void
{
    $recipe = Recipe::factory()->create();
    $location = Location::factory()->create();
    $productA = Product::factory()->create();
    $productB = Product::factory()->create();
    RecipeLine::factory()->create(['recipe_id' => $recipe->id, 'component_id' => $productA->id, 'quantity' => '1']);
    RecipeLine::factory()->create(['recipe_id' => $recipe->id, 'component_id' => $productB->id, 'quantity' => '1']);

    Batch::factory()->create(['product_id' => $productA->id, 'expiry_date' => '2026-12-31']);
    Batch::factory()->create(['product_id' => $productB->id, 'expiry_date' => '2026-09-30']); // earlier

    $expiry = app(RecipeExpiryService::class)->resolveEarliestExpiry($recipe->id, $location->id);

    $this->assertEquals('2026-09-30', $expiry->format('Y-m-d'));
}

public function test_returns_null_when_no_ingredients_batch_tracked(): void
{
    // recipe with two ingredients, neither has batches; result is null
}

public function test_variant_scoped_ingredient_uses_variant_batches(): void
{
    // create variant ingredient + variant batch; ensure expiry comes from variant batch
}

public function test_recalled_batches_excluded(): void
{
    // recipe ingredient has two batches; earlier one is recalled; resolveEarliestExpiry returns later
}
```

- [ ] **Step 2: Implement**

```php
final class RecipeExpiryService
{
    public function __construct(private readonly FEFOInventoryService $fefo) {}

    public function resolveEarliestExpiry(string $recipeId, string $locationId): ?\Carbon\Carbon
    {
        $recipe = Recipe::with('lines.recipe')->findOrFail($recipeId);
        $earliest = null;

        foreach ($recipe->lines as $line) {
            if ($line->component_type !== ComponentType::Product) continue;
            $batches = $this->fefo->suggestBatchesForSale(
                productId: $line->component_id,
                locationId: $locationId,
                quantity: bcmul($line->quantity, $recipe->yield_quantity, 4),
                variantId: $line->component_variant_id,
            );
            if ($batches->isEmpty()) continue;
            $lineExpiry = \Carbon\Carbon::parse($batches->first()->expiry_date);
            if ($earliest === null || $lineExpiry < $earliest) {
                $earliest = $lineExpiry;
            }
        }

        return $earliest;
    }
}
```

- [ ] **Step 3: Run tests** → PASS.

- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: RecipeExpiryService earliest-expiry inheritance"
```

---

## Phase 4 — B2B + ecommerce surface (~4 PD)

### Task 25: `CartService::add` variant-aware

**Files:**
- Modify: `apps/api/app/Modules/Cart/Application/Services/CartService.php`
- Modify: `apps/api/app/Modules/Cart/Application/DTOs/CatalogCartItemData.php`
- Test: `apps/api/tests/Feature/Cart/CartServiceVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_add_variant_to_cart(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $cart = CatalogCart::factory()->create();

    $item = app(CartService::class)->add(
        cartId: $cart->id,
        productId: $product->id,
        quantity: '1',
        variantId: $variant->id,
    );

    $this->assertSame($variant->id, $item->variant_id);
}

public function test_variant_required_when_product_has_variants(): void
{
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id]);
    $cart = CatalogCart::factory()->create();

    $this->expectException(VariantRequiredException::class);
    app(CartService::class)->add(
        cartId: $cart->id,
        productId: $product->id,
        quantity: '1',
    );
}
```

- [ ] **Step 2: Modify `CartService::add`** — append `?string $variantId = null`; verify product-has-variants invariant.

- [ ] **Step 3: Update `CatalogCartItemData` DTO** with `variantId` field.

- [ ] **Step 4: Update `CartConversionService`** to propagate `variant_id` to `document_lines` on cart→document.

- [ ] **Step 5: Regenerate TS types.** Run tests → PASS.

- [ ] **Step 6: Commit**

```bash
git commit -m "Phase 2.x: CartService variant-aware add + conversion"
```

---

### Task 26: `ChannelService::publishProduct` variant-aware

**Files:**
- Modify: `apps/api/app/Modules/Channel/Application/Services/ChannelService.php`
- Modify: `apps/api/app/Modules/Channel/Application/Listeners/DispatchStockChangeToChannels.php` (read from `StockMovementRecordedV2`)
- Test: `apps/api/tests/Feature/Channel/ChannelServiceVariantTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_publish_product_with_variant_writes_mapping(): void
{
    // Real signature (verified): publishProduct(string $channelId, string $productId, ?string $variantId, array $overrides, ?string $companyId = null)
    $channel = Channel::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    app(ChannelService::class)->publishProduct(
        channelId: $channel->id,
        productId: $product->id,
        variantId: $variant->id,
        overrides: [],
    );

    $mapping = ChannelProductMapping::where('channel_id', $channel->id)
        ->where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->first();
    $this->assertNotNull($mapping);
}

public function test_stock_change_listener_propagates_variant_grain(): void
{
    // ... fire StockMovementRecordedV2 with variantId; mock channel adapter; assert syncStock called once with variant-scoped mapping
}
```

- [ ] **Step 2: Implement.** The `channel_product_mappings.variant_id` already exists; service just uses it.

- [ ] **Step 3: Listener update** — subscribe to V2 event, dispatch jobs scoped by variant.

- [ ] **Step 4: Run tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: ChannelService variant-aware publish + listener consumes V2 events"
```

---

### Task 27: B2B `ProductVariantService::resolveSku`, `resolveBarcode` for bulk-order import

**Files:**
- Modify: `ProductVariantService` — add the two resolve methods
- Modify: B2B bulk-order importer (find via `apps/api/app/Modules/...` — likely under Document or Imports module)
- Test: `apps/api/tests/Feature/Catalog/ProductVariantSkuBarcodeResolveTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_resolve_sku_returns_variant(): void
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id, 'sku' => 'ABC-39-N',
    ]);
    $result = app(ProductVariantService::class)->resolveSku('ABC-39-N', $product->company_id);
    $this->assertInstanceOf(ProductVariant::class, $result);
    $this->assertSame($variant->id, $result->id);
}

public function test_resolve_sku_falls_back_to_product(): void
{
    $product = Product::factory()->create(['sku' => 'XYZ-001']);
    $result = app(ProductVariantService::class)->resolveSku('XYZ-001', $product->company_id);
    $this->assertInstanceOf(Product::class, $result);
}

public function test_resolve_barcode_works(): void { /* parallel */ }
```

- [ ] **Step 2: Implement**

```php
public function resolveSku(string $sku, string $companyId): ProductVariant|Product|null
{
    $variant = ProductVariant::where('company_id', $companyId)
        ->where('sku', $sku)
        ->where('is_active', true)
        ->first();
    if ($variant) return $variant;
    return Product::where('company_id', $companyId)
        ->where('sku', $sku)
        ->where('is_active', true)
        ->first();
}
```

- [ ] **Step 3: Wire into B2B bulk-order import** — find the import service, swap product lookup for `ProductVariantService::resolveSku`.

- [ ] **Step 4: Run tests** → PASS.
- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: ProductVariantService SKU/barcode resolve + B2B import wiring"
```

---

### Task 27b: Accounting report updates (P2-1 — Codex r1)

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php` (lines 74-85 — `topSkus`)
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/StockAlertReportService.php` (lines 27-49)
- Test: `apps/api/tests/Feature/Accounting/SalesReportVariantTest.php`
- Test: `apps/api/tests/Feature/Accounting/StockAlertReportVariantTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_top_skus_reports_variant_grain_for_variant_products(): void
{
    $product = Product::factory()->create(['name' => 'Chaussure', 'sku' => 'CH-001']);
    $v1 = ProductVariant::factory()->create([
        'product_id' => $product->id, 'sku' => 'CH-001-39-N', 'name_suffix' => '39 / Noir',
    ]);
    $v2 = ProductVariant::factory()->create([
        'product_id' => $product->id, 'sku' => 'CH-001-39-B', 'name_suffix' => '39 / Blanc',
    ]);
    PosReceiptLine::factory()->count(3)->create(['product_id' => $product->id, 'variant_id' => $v1->id]);
    PosReceiptLine::factory()->count(1)->create(['product_id' => $product->id, 'variant_id' => $v2->id]);

    $rows = app(SalesReportService::class)->topSkus(/* tenant + date range */);

    // Two distinct rows by variant SKU
    $this->assertCount(2, $rows);
    $this->assertSame('CH-001-39-N', $rows[0]->sku);
    $this->assertSame('Chaussure — 39 / Noir', $rows[0]->name);
}

public function test_stock_alerts_at_variant_grain(): void
{
    // Variant-bearing product with one variant below min — one alert with variant SKU
}
```

- [ ] **Step 2: Update `topSkus` (v4 — preserve real scoping signature)**

Real method signature: `topSkus(DateRangeData $range, array $companyIds, array $locationIds, int $limit, string $sortBy): array<TopSkuData>`. The rewrite MUST preserve the company/location/voided/training/posted_at filters. Add `variant_id` to the grouping when present.

```php
public function topSkus(DateRangeData $range, array $companyIds, array $locationIds, int $limit, string $sortBy): array
{
    if ($companyIds === [] || $locationIds === []) {
        return [];
    }

    $query = DB::table('pos_receipt_lines')
        ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
        ->leftJoin('products', 'products.id', '=', 'pos_receipt_lines.product_id')
        ->leftJoin('product_variants', 'product_variants.id', '=', 'pos_receipt_lines.variant_id')
        ->whereIn('pos_receipts.company_id', $companyIds)
        ->whereIn('pos_receipts.location_id', $locationIds)
        ->where('pos_receipts.is_voided', false)
        ->where('pos_receipts.training_flag', false)
        ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
        ->groupBy(
            'pos_receipt_lines.product_id',
            'pos_receipt_lines.variant_id',
            'pos_receipt_lines.product_name',
            'products.sku',
            'product_variants.sku',
            'product_variants.name_suffix',
        )
        ->selectRaw('pos_receipt_lines.product_id')
        ->selectRaw('pos_receipt_lines.variant_id')
        ->selectRaw(
            "CASE WHEN product_variants.name_suffix IS NOT NULL "
            . "THEN pos_receipt_lines.product_name || ' — ' || product_variants.name_suffix "
            . "ELSE pos_receipt_lines.product_name END AS product_name"
        )
        ->selectRaw('COALESCE(product_variants.sku, products.sku) AS sku')
        ->selectRaw('COALESCE(SUM(pos_receipt_lines.line_total), 0) as revenue')
        ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as quantity')
        ->limit($limit);

    $sortBy === 'quantity'
        ? $query->orderByDesc('quantity')
        : $query->orderByDesc('revenue');

    return array_values($query->get()->map(fn (object $row): TopSkuData => new TopSkuData(
        product_id: $row->product_id === null ? null : (string) $row->product_id,
        product_name: (string) $row->product_name,
        sku: $row->sku === null ? null : (string) $row->sku,
        revenue: $this->decimalString($row->revenue),
        quantity: $this->decimalString($row->quantity),
    ))->all());
}
```

**`TopSkuData` DTO change:** `product_id` field stays the parent product id (so report consumers can drill down by product). The variant suffix is folded into `product_name`. Consider adding `variant_id` to the DTO in a follow-up PR if reporting needs to group by variant programmatically.

- [ ] **Step 3: Update `StockAlertReportService::lowStockAcrossLocations` — preserve real signature + threshold + severity; add variant join**

**Real method (verified at `apps/api/app/Modules/Accounting/Application/Services/Reports/StockAlertReportService.php:19`):**
```php
public function lowStockAcrossLocations(array $companyIds, array $locationIds, int $thresholdPct): array
```
Real `StockAlertData` constructor: `(product_id, product_name, location_id, location_name, quantity, min_quantity, threshold_pct, severity)`. T2 keeps every field, adds `variant_id` (nullable) + `variant_name_suffix` (nullable). The method signature stays the same — name, parameters, and `thresholdPct` behavior are preserved.

```php
/**
 * @param  list<string>  $companyIds
 * @param  list<string>  $locationIds
 * @return list<StockAlertData>
 */
public function lowStockAcrossLocations(array $companyIds, array $locationIds, int $thresholdPct): array
{
    if ($companyIds === [] || $locationIds === []) {
        return [];
    }

    $thresholdRatio = $thresholdPct / 100;

    $rows = DB::table('stock_levels')
        ->join('products', 'products.id', '=', 'stock_levels.product_id')
        ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
        ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
        ->whereIn('stock_levels.company_id', $companyIds)
        ->whereIn('stock_levels.location_id', $locationIds)
        ->whereNotNull('stock_levels.min_quantity')
        ->whereRaw('stock_levels.quantity <= (stock_levels.min_quantity * ?)', [$thresholdRatio])
        ->selectRaw('products.id as product_id')
        ->selectRaw('products.name as product_name')
        ->selectRaw('stock_levels.variant_id as variant_id')
        ->selectRaw('product_variants.name_suffix as variant_name_suffix')
        ->selectRaw('locations.id as location_id')
        ->selectRaw('locations.name as location_name')
        ->selectRaw('stock_levels.quantity')
        ->selectRaw('stock_levels.min_quantity')
        ->orderBy('products.name')
        ->get();

    return array_values($rows->map(fn (object $row): StockAlertData => new StockAlertData(
        product_id: (string) $row->product_id,
        product_name: (string) $row->product_name,
        location_id: (string) $row->location_id,
        location_name: (string) $row->location_name,
        quantity: $this->decimalString($row->quantity),
        min_quantity: $this->decimalString($row->min_quantity),
        threshold_pct: $thresholdPct,
        severity: $this->severity((float) $row->quantity, (float) $row->min_quantity),
        variant_id: $row->variant_id === null ? null : (string) $row->variant_id,
        variant_name_suffix: $row->variant_name_suffix === null ? null : (string) $row->variant_name_suffix,
    ))->all());
}
```

**`StockAlertData` DTO change:** APPEND two trailing nullable fields — `variant_id: ?string = null`, `variant_name_suffix: ?string = null`. Every existing field stays exactly as today (`product_id`, `product_name`, `location_id`, `location_name`, `quantity`, `min_quantity`, `threshold_pct`, `severity`). Backward-compat: existing consumers that build `StockAlertData` without the new fields keep working because they're trailing optional. Per spec §5.10, alerts are reported at variant+location grain when `variant_id` is non-null; the UI can fold by product if desired.

The `severity()` private helper, the threshold-ratio math, and the locations join are unchanged from today's implementation.

- [ ] **Step 4: Run tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.4.27b: Accounting reports — variant-grain top SKUs + stock alerts"
```

---

### Task 27c: Loyalty rule evaluator — product-scoped rule applies to all variants (P2-3 — Codex r1)

Codex r1 found that Loyalty uses `product_ids` in DTOs/services (not just category). T2 makes product-scoped loyalty rules apply transparently across all variants of the product, mirroring the coupon decision.

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Domain/Services/PointEarningService.php`, `RewardRedemptionService.php`, `StampCardService.php`
- Test: `apps/api/tests/Feature/Loyalty/LoyaltyVariantInheritanceTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_loyalty_rule_with_product_id_applies_to_all_variants(): void
{
    $product = Product::factory()->create();
    $v1 = ProductVariant::factory()->create(['product_id' => $product->id]);
    $v2 = ProductVariant::factory()->create(['product_id' => $product->id]);
    $rule = LoyaltyRule::factory()->create(['product_ids' => [$product->id]]);

    $line1 = $this->makeReceiptLine($product->id, $v1->id, qty: '1');
    $line2 = $this->makeReceiptLine($product->id, $v2->id, qty: '1');

    $applies1 = app(LoyaltyRuleEvaluator::class)->ruleApplies($rule, $line1);
    $applies2 = app(LoyaltyRuleEvaluator::class)->ruleApplies($rule, $line2);

    $this->assertTrue($applies1);
    $this->assertTrue($applies2);
}
```

- [ ] **Step 2: Update `ruleApplies` logic** to read line's `product_id` (variant's parent) when matching JSON `product_ids` arrays.

- [ ] **Step 3: Run tests** → PASS.

- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.4.27c: Loyalty rule evaluator — product-scoped rules apply to all variants"
```

---

## Phase 5 — Admin UI (~5 PD)

### Task 28a (REMOVED in v4 — see Task 11b in Phase 1)

This slot intentionally vacant. The Shared `ProductVariantLookup` contract is created in Task 11b at the end of Phase 1, ahead of the Phase 2 service ripple that consumes it. Do not recreate the old `App\Modules\Shared\Contracts` placement — that namespace does not exist in this codebase. The real shared-contracts location is `apps/api/app/Shared/Contracts/`.

---

### Task 28: REST endpoints for attributes + variants

**Files:**
- Create controller: `apps/api/app/Modules/Catalog/Presentation/Http/AttributeController.php`
- Create controller: `apps/api/app/Modules/Catalog/Presentation/Http/ProductVariantController.php`
- Modify routes: `apps/api/app/Modules/Catalog/Presentation/routes.php`
- Form requests: `CreateAttributeRequest.php`, `CreateVariantRequest.php`, `GenerateMatrixRequest.php`
- Resources: `AttributeResource.php`, `ProductVariantResource.php`
- Test: `apps/api/tests/Feature/Catalog/ProductVariantApiTest.php`

- [ ] **Step 1: Write failing API test**

```php
public function test_create_attribute_endpoint(): void
{
    $this->actingAs(User::factory()->create()->givePermissionTo('catalog.attributes.create'));

    $resp = $this->postJson('/api/v1/product-attributes', [
        'code' => 'taille',
        'name' => 'Taille',
        'data_type' => 'selection',
        'is_variant_axis' => true,
    ]);

    $resp->assertStatus(201);
    $this->assertDatabaseHas('product_attributes', ['code' => 'taille']);
}

public function test_generate_matrix_endpoint_returns_18_variants(): void { /* ... */ }
public function test_list_variants_for_product_endpoint(): void { /* ... */ }
public function test_update_variant_endpoint(): void { /* ... */ }
public function test_delete_variant_endpoint(): void { /* ... */ }
public function test_unauthenticated_request_returns_401(): void { /* ... */ }
public function test_missing_permission_returns_403(): void { /* ... */ }
```

- [ ] **Step 2: Implement controllers + form requests + resources + routes** following the established patterns (constructor injection, `['api', 'auth:sanctum', SetPermissionsTeam::class]` middleware, response shapes consistent with the existing API convention — see `apps/erp/docs/conventions/01-API-RESPONSES.md`).

- [ ] **Step 3: Add new permissions** to the permission seeder via the project's `/project:add-permissions` command equivalent — `catalog.attributes.{create,update,delete,view}`, `catalog.variants.{create,update,delete,view}`.

- [ ] **Step 4: Run tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: REST endpoints for attributes + variants + permissions"
```

---

### Task 29: `AttributeListPage` React component

**Files:**
- Create page: `apps/web/src/features/catalog/pages/AttributeListPage.tsx`
- Create components: `AttributeForm.tsx`, `AttributeValueEditor.tsx`
- Create hooks: `apps/web/src/features/catalog/hooks/useAttributes.ts`, `useAttribute.ts`, `useCreateAttribute.ts`
- Add routes in dashboard nav per `docs/conventions/02-NAVIGATION-ROUTING.md`
- Add i18n keys: `apps/web/src/locales/{en,fr,ar}/catalog.json`
- Test: `apps/web/src/features/catalog/__tests__/AttributeListPage.test.tsx`

- [ ] **Step 1: Write Vitest component test**

```typescript
describe('AttributeListPage', () => {
  it('renders empty state when no attributes', async () => {
    render(<AttributeListPage />, { wrapper });
    expect(await screen.findByText(/no attributes yet/i)).toBeInTheDocument();
  });

  it('opens form when "Add" clicked', async () => {
    // ...
  });

  it('submits new attribute via form', async () => {
    // ... assert query mutation fires, list refetches
  });
});
```

- [ ] **Step 2: Implement page + hooks + form components** using react-hook-form + zod + TanStack Query (per project conventions). Use `tokens` / `textColors` / `borderColors` from `@/lib/designTokens`.

- [ ] **Step 3: Add i18n keys** for every visible string. Update `i18n.ts` if a new namespace is needed (project provides `/project:add-i18n-namespace` skill).

- [ ] **Step 4: Run vitest** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: AttributeListPage + hooks + i18n keys"
```

---

### Task 30: `ProductVariantMatrixEditor` React component

**Files:**
- Create: `apps/web/src/features/catalog/components/ProductVariantMatrixEditor.tsx`
- Create hooks: `useGenerateMatrix.ts`, `useUpdateVariant.ts`, `useVariantsForProduct.ts`
- Modify: `apps/web/src/features/catalog/pages/ProductFormPage.tsx` — add "has variants" toggle that mounts the matrix editor
- Test: `apps/web/src/features/catalog/__tests__/ProductVariantMatrixEditor.test.tsx`

- [ ] **Step 1: Write failing test**

```typescript
describe('ProductVariantMatrixEditor', () => {
  it('renders 18-cell grid for 3x6 axes', async () => { /* ... */ });
  it('inline-edits SKU + price_override per cell', async () => { /* ... */ });
  it('uploads variant image (mock)', async () => { /* ... */ });
  it('toggles is_active per variant', async () => { /* ... */ });
});
```

- [ ] **Step 2: Implement.** Matrix is a `<table>` with N axis columns + rows + a "cell" `<td>` per variant. Each cell renders an inline-editable form (SKU, price, barcode, image, active checkbox). Tailwind tokens only; no hardcoded colors.

- [ ] **Step 3: Wire into `ProductFormPage`** via a toggle.

- [ ] **Step 4: Run vitest** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: ProductVariantMatrixEditor + ProductFormPage integration"
```

---

### Task 31: `ProductVariantStockView` and B2B + ecommerce variant pickers

**Files:**
- Modify: `apps/web/src/features/inventory/pages/StockLevelsPage.tsx` — add variant drill-down
- Create: `apps/web/src/features/document/components/DocumentLineVariantSelector.tsx`
- Create: `apps/web/src/features/catalog/components/ProductDetailVariantPicker.tsx` (B2C catalog)
- Test: a smoke test for each

- [ ] **Step 1: Write failing tests** for each component (render + select variant + emit change event).

- [ ] **Step 2: Implement.** Each picker fetches `useVariantsForProduct(productId)`, renders a select / radio group, exposes a `(variantId | null)` value.

- [ ] **Step 3: Wire selectors into `DocumentForm`** + `ProductDetailPage` (catalog product detail).

- [ ] **Step 4: Run tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "Phase 2.x: variant pickers — stock view + B2B doc line + B2C catalog detail"
```

---

## Phase 6 — Acceptance test sweep + final QA (~2 PD)

### Task 32: Full acceptance criteria suite

**Files:**
- Test: `apps/api/tests/Feature/T2/T2AcceptanceTest.php` — orchestrates all 10.1–10.7 acceptance scenarios end-to-end
- Test data: `apps/api/database/seeders/T2AcceptanceSeeder.php` — seeds parapharmacy + F&B + automotive demo tenant
- Test: web E2E or smoke tests if existing Playwright/Cypress harness is configured

- [ ] **Step 1: Write Wave-1 acceptance suite ONLY** (P1-8 — split per Codex r1)

This PR is Wave-1 server-side. Wave-2 acceptance (§10.4) is the next session's gate, not this PR's gate.

```php
// Wave 1 — server-side gate (THIS PR)
public function test_10_1_partial_unique_indexes_dual_row_insert(): void { /* concrete; insert both, assert succeeds; insert duplicates of each, assert fails */ }
public function test_10_1_soft_delete_partial_unique_sku_reusable(): void { /* concrete from Task 3 step 3 */ }
public function test_10_1_large_migration_guard_refuses_without_override(): void { /* from Task 14 */ }
public function test_10_2_backward_compat_non_variant_flows_unchanged(): void { /* Receive 10 + Sell 1 + Refund 1 with no variant in scope; PHPUnit suite green */ }
public function test_10_3_variant_aware_receive_sell_refund_chain(): void { /* full chain at variant grain */ }
public function test_10_3_pos_decrement_writes_variant_id(): void { /* ReceiptCreationService::decrementStock writes correct variant row */ }
public function test_10_3_two_cashiers_concurrent_consume_one_succeeds(): void { /* from Task 16b */ }
public function test_10_5_recipe_expiry_earliest_inheritance(): void { /* from Task 24 */ }
public function test_10_5_recipe_pre_existing_mixed_mode_remediation(): void { /* from Task 14 supplement */ }
public function test_10_5_recipe_cost_advisory_wac_unchanged(): void { /* from Task 22 */ }
public function test_10_5_two_cashiers_recipe_sale(): void { /* from spec §7.3 */ }
public function test_10_6_cart_to_document_variant_propagation(): void { /* from Task 25 */ }
public function test_10_6_b2b_pricing_resolves_variant_then_product(): void { /* from Task 17 */ }
public function test_10_6_coupon_applies_to_all_variants(): void { /* P2-3 inheritance */ }
public function test_10_6_loyalty_rule_applies_to_all_variants(): void { /* from Task 27c */ }
public function test_10_6_dual_dispatch_V1_V2_both_fire(): void { /* from Task 19 */ }
public function test_10_6_credit_note_returns_to_variant_row(): void { /* P3-3 */ }
public function test_10_7_parapharmacy_orthopedic_full_flow(): void { /* §11.1 */ }
public function test_10_7_retail_sportswear_same_code_path(): void { /* §11.2 */ }
public function test_10_7_fb_composite_with_sized_cup_no_product_variants(): void { /* §11.4 */ }
public function test_10_7_automotive_zero_variant_does_not_show_matrix_editor(): void { /* P3-3 — verify frontend in vitest */ }
public function test_10_8_multi_tenant_isolation_variants_not_leaked(): void { /* DB-per-tenant boundary */ }
public function test_10_accounting_top_skus_at_variant_grain(): void { /* from Task 27b */ }
public function test_10_accounting_stock_alerts_at_variant_grain(): void { /* from Task 27b */ }
```

- [ ] **Step 1b: Mark Wave-2 acceptance criteria as deferred** (not in this PR's executable test plan)

Wave-2 acceptance lives in `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md`. Add a checklist entry there:

```markdown
### T2 variants Wave-2 POS gate — deferred to separate session

- [ ] POS variant picker modal opens on tap of variant-bearing product
- [ ] Direct barcode scan of variant barcode resolves to variant
- [ ] Cart line displays "Chaussure X — 39 / Noir"
- [ ] Receipt print + email include variant suffix
- [ ] SQLite schema migration applied; offline mode works
- [ ] Variant-aware sync to backend writes variant_id correctly

Gated by: fiscal Phase-1 sign-off; the Wave-1 PR for T2 (server) must merge first.
```

- [ ] **Step 2: Run** `cd apps/erp/apps/api && ./vendor/bin/phpunit --filter T2Acceptance` → all PASS.

- [ ] **Step 3: Preflight gates**

```bash
cd apps/erp && ./scripts/preflight.sh
```

Expected: PHPStan L8 zero new errors, Pint clean, PHPUnit all green, ESLint zero new errors, TypeScript typecheck clean.

- [ ] **Step 4: Commit**

```bash
git commit -m "Phase 2.x: tests — full acceptance suite covering §10 criteria"
```

---

### Task 33: Write the impl-PR description + coordination log entry

**Files:**
- Create / append: `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md` — log the POS Wave 2 deltas as a separate session
- Create: PR description (the actual `gh pr create` body)

- [ ] **Step 1: Compose PR description** matching project convention:

```
## Summary
- Ships T2 product variants: schema + service ripple + recipe variant inheritance + B2B/ecommerce surfaces + admin UI.
- Honors all five owner non-negotiables from 2026-05-28 briefing.
- POS Wave-2 deltas logged separately; gated by fiscal Phase-1 sign-off.

## Test plan
- [ ] PHPStan L8 zero new errors
- [ ] PHPUnit suite green
- [ ] Vitest suite green
- [ ] §10 acceptance suite green
- [ ] Tenant isolation regression test green
- [ ] Recipe + variant + expiry round-trip works (acceptance §10.5)
- [ ] Ecommerce + B2B surfaces (§10.6)
- [ ] Vertical-modularity proofs (§10.7)
```

- [ ] **Step 2: Append a coordination-log entry for POS Wave 2 work**

```markdown
### 2026-mm-dd — T2 product variants Wave-2 POS deltas pending

- Variant picker modal (cashier taps variant-bearing product → matrix with available stock).
- Direct variant barcode resolution at POS scan.
- Cart line variant suffix display.
- SQLite schema: pos_receipt_lines + variant cache add `variant_id`.
- Fiscal Phase-1 sign-off required before merge.
```

- [ ] **Step 3: Commit + push** all docs.

```bash
git add docs/superpowers/coordination/2026-05-24-pos-coordination-log.md
git commit -m "Phase 2.x: docs — coordination-log entry for POS Wave-2 deltas"
```

---

## Wave 2 (separate session, NOT in this plan)

The following are owned by the next POS Codex session, gated by fiscal Phase-1 sign-off:

- Variant picker modal in `apps/pos/src/`.
- Barcode-to-variant resolution wiring.
- Cart line variant suffix rendering.
- SQLite migration on POS device.
- Variant-aware offline queue + sync.

These are tracked via the coordination log entry from Task 33; the session that picks them up reads the log and acts accordingly.

---

## Self-review checklist (run before declaring plan complete)

- [ ] **Spec coverage** — every section of `2026-05-28-t2-product-variants.md` traces to a task:
  - §3 grounding → all tasks (file paths).
  - §4 schema → Tasks 1–11.
  - §5 cross-cutting → Tasks 15–20, 25–27 (services + listeners).
  - §6 service contracts → Tasks 12, 13, 14, 15, 16, 17, 18, 21, 22, 23, 24, 25, 26, 27.
  - §6.5 invariant → Tasks 14, 15.
  - §7 batch + FEFO → Task 16.
  - §8 recipe + variant + expiry → Tasks 10, 21, 22, 23, 24.
  - §9 B2B + ecommerce → Tasks 9, 17, 25, 26, 27, 31.
  - §10 acceptance → Task 32.
  - §11 vertical modularity → acceptance criterion 10.7 in Task 32.
  - §12 review checklist → Codex review prompt (not a task — done in this spec session).
  - §13 out of scope → no tasks (explicitly).
  - §14 workflow phases → Phase 1–6 in this plan.
  - §15 coordination → Task 33.

- [ ] **No placeholders** — every step has the actual code or command.

- [ ] **Type consistency** — `ProductVariantService::resolveSku`/`resolveBarcode` return types consistent across Tasks 12 + 27. `?string $variantId = null` parameter shape consistent across all modified services. Event field names (`variantId`, `productId`) consistent.

- [ ] **Frequent commits** — every task ends with a commit; no batched commits.

- [ ] **TDD discipline** — every task starts with a failing test and verifies fail before implementation.

- [ ] **Migration discipline** — every new migration in `apps/api/database/migrations/tenant/`. Every CHECK + partial-index uses `if pgsql` guard.

- [ ] **Backward-compat at service layer** — every `?string $variantId = null` is **trailing** + **optional**; no broken positional callers.

- [ ] **POS Wave-2 explicitly out of this plan** — see "Wave 2" note above.

---

End of plan.
