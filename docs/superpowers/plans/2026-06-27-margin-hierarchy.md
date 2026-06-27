# Editable, hierarchy-aware product margin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A company → category → product margin hierarchy with an editable, bidirectional margin field on the product editor, consistent with the server everywhere margin is used.

**Architecture:** A new `MarginResolver` (Product module) owns 3-level resolution (product override → nearest category ancestor → company default → fallback) with per-field provenance and a `minimum ≤ target` clamp. A per-product `pricing_mode` (`auto`|`manual`) makes auto-repricing safe. The editor computes margin on the canonical cost basis (WAC `cost_price`, else `purchase_price`) so it matches the server.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PostgreSQL (db-per-tenant), Spatie LaravelData DTOs, PHPUnit; React 19 / TypeScript strict / Vitest; `lib/decimal.ts` (big.js) for float-free FE math.

**Spec:** `docs/superpowers/specs/2026-06-27-margin-hierarchy-design.md` (read it). Reviews: `docs/superpowers/audits/2026-06-27-margin-hierarchy-approach-review.md`, `…-spec-review-claude.md`.

## Global Constraints

- **Precision (rule 19):** percent = **2dp**, NOT currency-scaled. Validation regex `/^\d+(\.\d{1,2})?$/`. Never `parseFloat`/`Number`/native arithmetic/`Math.round` on money/percent in FE — use `lib/decimal.ts`. Money/qty via bcmath server-side.
- **Scale resolution (rule 20):** never bare no-arg `getScale()` on queue/console paths — pass currency from `Company::$currency` via `getScaleSafe($currency, 3)`. `Product` has **no** currency attribute.
- **Constructor injection only (rule 13):** `private readonly`, never `app()`.
- **Enums for status/type (rule 9):** no magic strings.
- **Module boundaries (rule 6):** hierarchy logic stays in Product module; Inventory keeps calling the public `MarginService`.
- **Margin model:** markup on cost — `price = cost × (1 + m/100)`, `m% = ((sell − cost)/cost) × 100`. `MARGIN_SCALE = 2`.
- **Canonical cost basis:** `cost_price` (WAC) when `> 0`, else `purchase_price`. Used identically by editor + server.
- **Branch (backend slice):** `feat/margin-category-override` off `origin/dev`, in a `git worktree`. Tests **by path only** (never full suite). Validation envelope `{error:{errors}}` → `Tests\Traits\AssertsApiValidation`. `typescript:transform` needs `CACHE_STORE=array`. Preflight before commit. Reconcile with `origin/dev` before finishing.
- **Branch (frontend slice):** on `feat/izipos-theme-product-editor`, rebased onto the backend slice. Verify the worktree `vendor` is a real copy, not a symlink.
- **Fiscal non-goal:** changes affect product-master/future pricing only — never receipt/document lines or hash-chain rows.

---

# BACKEND SLICE — branch `feat/margin-category-override`

> Setup (do once before Task 1): create the worktree off `origin/dev`.
> ```bash
> cd /Users/houssamr/Projects/syneriva/apps/erp
> git fetch origin dev
> git worktree add ../erp.margin-hier -b feat/margin-category-override origin/dev
> cd ../erp.margin-hier && ls -la apps/api/vendor   # confirm vendor is a real dir, not a symlink
> ```
> All backend paths below are relative to the worktree `apps/api/`.

## Task 1: Enums — `PricingMode` and `MarginSource`

**Files:**
- Create: `app/Modules/Product/Domain/Enums/PricingMode.php`
- Create: `app/Modules/Product/Domain/Enums/MarginSource.php`
- Test: `tests/Unit/Product/Enums/PricingModeTest.php`

**Interfaces:**
- Produces: `PricingMode::Auto` (`'auto'`), `PricingMode::Manual` (`'manual'`); `MarginSource::Product`/`Category`/`Company`/`DefaultFallback` with values `'product'|'category'|'company'|'default'`.

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Product\Enums;
use App\Modules\Product\Domain\Enums\MarginSource;
use App\Modules\Product\Domain\Enums\PricingMode;
use Tests\TestCase;

class PricingModeTest extends TestCase
{
    public function test_pricing_mode_values(): void
    {
        $this->assertSame('auto', PricingMode::Auto->value);
        $this->assertSame('manual', PricingMode::Manual->value);
    }

    public function test_margin_source_values(): void
    {
        $this->assertSame('product', MarginSource::Product->value);
        $this->assertSame('category', MarginSource::Category->value);
        $this->assertSame('company', MarginSource::Company->value);
        $this->assertSame('default', MarginSource::DefaultFallback->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `php artisan test tests/Unit/Product/Enums/PricingModeTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the enums**
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Domain\Enums;

enum PricingMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
```
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Domain\Enums;

enum MarginSource: string
{
    case Product = 'product';
    case Category = 'category';
    case Company = 'company';
    case DefaultFallback = 'default';
}
```

- [ ] **Step 4: Run test to verify it passes**
Run: `php artisan test tests/Unit/Product/Enums/PricingModeTest.php` → PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Domain/Enums/PricingMode.php app/Modules/Product/Domain/Enums/MarginSource.php tests/Unit/Product/Enums/PricingModeTest.php
git commit -m "feat(product): add PricingMode and MarginSource enums"
```

## Task 2: Migration — category margin overrides + product `pricing_mode`

**Files:**
- Create: `database/migrations/tenant/2026_06_27_100000_add_margin_hierarchy_columns.php`
- Modify: `app/Modules/Product/Domain/Category.php` (`$fillable`, `casts()`)
- Modify: `app/Modules/Product/Domain/Product.php` (`$fillable`, `casts()`, `@property`)
- Test: `tests/Feature/Product/MarginHierarchyMigrationTest.php`

**Interfaces:**
- Produces: `categories.target_margin_override` / `categories.minimum_margin_override` (`decimal(5,2)` nullable, cast `decimal:2`); `products.pricing_mode` (string, DB default `'manual'`, cast `PricingMode::class`).

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Product;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarginHierarchyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_margin_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('categories', 'target_margin_override'));
        $this->assertTrue(Schema::hasColumn('categories', 'minimum_margin_override'));
    }

    public function test_product_pricing_mode_defaults_to_manual(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'pricing_mode'));
        $product = Product::factory()->create();
        $this->assertSame(PricingMode::Manual, $product->fresh()->pricing_mode);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `php artisan test tests/Feature/Product/MarginHierarchyMigrationTest.php` → FAIL (no column / cast).

- [ ] **Step 3: Write the migration**
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
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('target_margin_override', 5, 2)->nullable()->after('default_tax_rate');
            $table->decimal('minimum_margin_override', 5, 2)->nullable()->after('target_margin_override');
        });

        Schema::table('products', function (Blueprint $table) {
            // Safe default 'manual': no existing price can be auto-clobbered (Task 14 backfill flips proven-auto rows).
            $table->string('pricing_mode', 16)->default('manual')->after('minimum_margin_override');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['target_margin_override', 'minimum_margin_override']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
```

- [ ] **Step 4: Update models**
In `Category.php` add to `$fillable`: `'target_margin_override'`, `'minimum_margin_override'`; in `casts()` add `'target_margin_override' => 'decimal:2'`, `'minimum_margin_override' => 'decimal:2'`. Add matching `@property string|null` docblocks.
In `Product.php` add `'pricing_mode'` to `$fillable`; in `casts()` add `'pricing_mode' => PricingMode::class`; add `use App\Modules\Product\Domain\Enums\PricingMode;` and `@property PricingMode $pricing_mode`.

- [ ] **Step 5: Run test to verify it passes**
Run: `php artisan test tests/Feature/Product/MarginHierarchyMigrationTest.php` → PASS.

- [ ] **Step 6: Commit**
```bash
git add database/migrations/tenant/2026_06_27_100000_add_margin_hierarchy_columns.php app/Modules/Product/Domain/Category.php app/Modules/Product/Domain/Product.php tests/Feature/Product/MarginHierarchyMigrationTest.php
git commit -m "feat(product): add category margin overrides + product pricing_mode column"
```

## Task 3: `EffectiveMargins` DTO

**Files:**
- Create: `app/Modules/Product/Application/DTOs/EffectiveMargins.php`
- Test: `tests/Unit/Product/EffectiveMarginsTest.php`

**Interfaces:**
- Produces: `EffectiveMargins` with readonly props `target_margin` (numeric-string 2dp), `minimum_margin` (numeric-string 2dp, post-clamp), `target_source: MarginSource`, `target_source_category_id: ?int`, `minimum_source: MarginSource`, `minimum_source_category_id: ?int`, `minimum_clamped: bool`.

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Product;
use App\Modules\Product\Application\DTOs\EffectiveMargins;
use App\Modules\Product\Domain\Enums\MarginSource;
use Tests\TestCase;

class EffectiveMarginsTest extends TestCase
{
    public function test_holds_resolved_values_and_provenance(): void
    {
        $m = new EffectiveMargins('30.00', '15.00', MarginSource::Product, null, MarginSource::Company, null, false);
        $this->assertSame('30.00', $m->target_margin);
        $this->assertSame(MarginSource::Company, $m->minimum_source);
        $this->assertFalse($m->minimum_clamped);
    }
}
```

- [ ] **Step 2: Run** `php artisan test tests/Unit/Product/EffectiveMarginsTest.php` → FAIL.

- [ ] **Step 3: Write the DTO**
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Application\DTOs;
use App\Modules\Product\Domain\Enums\MarginSource;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EffectiveMargins extends Data
{
    public function __construct(
        public string $target_margin,
        public string $minimum_margin,
        public MarginSource $target_source,
        public ?int $target_source_category_id,
        public MarginSource $minimum_source,
        public ?int $minimum_source_category_id,
        public bool $minimum_clamped,
    ) {}
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Application/DTOs/EffectiveMargins.php tests/Unit/Product/EffectiveMarginsTest.php
git commit -m "feat(product): add EffectiveMargins DTO with per-field provenance"
```

## Task 4: `MarginResolver` — 3-level resolution + clamp + per-field source

**Files:**
- Create: `app/Modules/Product/Application/Services/MarginResolver.php`
- Test: `tests/Feature/Product/MarginResolverTest.php`

**Interfaces:**
- Consumes: `EffectiveMargins` (Task 3), `MarginSource`/`PricingMode` (Task 1), `Category::getAncestors()` (returns root→…→parent, excludes self), `Company::$default_target_margin`/`$default_minimum_margin`.
- Produces:
  - `resolve(Product $product): EffectiveMargins`
  - `resolveMany(Collection $products): array<string,EffectiveMargins>` (keyed by product id)

**Resolution rules (per field, independently):** product override → product's OWN category then `getAncestors()` reversed (nearest first) → company default → fallback `30`/`15`. Then clamp `minimum = min(minimum, target)`, set `minimum_clamped` when it changed.

- [ ] **Step 1: Write the failing tests**
```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Product;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\MarginResolver;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\MarginSource;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarginResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): MarginResolver
    {
        return app(MarginResolver::class); // resolver itself is constructor-injected; app() only in tests
    }

    public function test_product_override_wins(): void
    {
        $company = Company::factory()->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $product = Product::factory()->for($company)->create(['target_margin_override' => '40.00']);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('40.00', $m->target_margin);
        $this->assertSame(MarginSource::Product, $m->target_source);
    }

    public function test_inherits_from_grandparent_category(): void
    {
        $company = Company::factory()->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $grand = Category::factory()->for($company)->create(['target_margin_override' => '50.00']);
        $parent = Category::factory()->for($company)->create(['parent_id' => $grand->id]);
        $leaf = Category::factory()->for($company)->create(['parent_id' => $parent->id]);
        $product = Product::factory()->for($company)->create(['category_id' => $leaf->id]);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('50.00', $m->target_margin);
        $this->assertSame(MarginSource::Category, $m->target_source);
        $this->assertSame($grand->id, $m->target_source_category_id);
    }

    public function test_company_default_when_no_overrides(): void
    {
        $company = Company::factory()->create(['default_target_margin' => '25', 'default_minimum_margin' => '12']);
        $product = Product::factory()->for($company)->create();
        $m = $this->resolver()->resolve($product);
        $this->assertSame('25.00', $m->target_margin);
        $this->assertSame(MarginSource::Company, $m->target_source);
    }

    public function test_independent_target_and_minimum_resolution_with_clamp(): void
    {
        // target from product (25), minimum from category (35) -> would invert -> clamp to 25
        $company = Company::factory()->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $cat = Category::factory()->for($company)->create(['minimum_margin_override' => '35.00']);
        $product = Product::factory()->for($company)->create(['category_id' => $cat->id, 'target_margin_override' => '25.00']);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('25.00', $m->target_margin);
        $this->assertSame('25.00', $m->minimum_margin);
        $this->assertTrue($m->minimum_clamped);
        $this->assertSame(MarginSource::Category, $m->minimum_source);
    }

    public function test_resolve_many_is_bounded_query(): void
    {
        $company = Company::factory()->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $cat = Category::factory()->for($company)->create(['target_margin_override' => '40.00']);
        $products = Product::factory()->count(20)->for($company)->create(['category_id' => $cat->id]);
        \DB::enableQueryLog();
        $result = $this->resolver()->resolveMany($products);
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        $this->assertCount(20, $result);
        $this->assertLessThanOrEqual(3, $queryCount, 'resolveMany must not be N+1');
    }
}
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Product/MarginResolverTest.php` → FAIL.

- [ ] **Step 3: Implement `MarginResolver`**
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Application\Services;
use App\Modules\Product\Application\DTOs\EffectiveMargins;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\MarginSource;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;

final class MarginResolver
{
    private const FALLBACK_TARGET = '30';
    private const FALLBACK_MINIMUM = '15';
    private const MARGIN_SCALE = 2;

    public function resolve(Product $product): EffectiveMargins
    {
        $chain = $this->categoryChain($product);   // [own, parent, ..., root] nearest-first
        $company = $product->company;

        [$target, $tSource, $tCatId] = $this->resolveField(
            $product->target_margin_override, $chain, 'target_margin_override',
            $company?->default_target_margin, self::FALLBACK_TARGET,
        );
        [$minimum, $mSource, $mCatId] = $this->resolveField(
            $product->minimum_margin_override, $chain, 'minimum_margin_override',
            $company?->default_minimum_margin, self::FALLBACK_MINIMUM,
        );

        $clamped = false;
        if (bccomp($minimum, $target, self::MARGIN_SCALE) > 0) {
            $minimum = $target;
            $clamped = true;
        }

        return new EffectiveMargins(
            CurrencyScale::bcformat($target, self::MARGIN_SCALE),
            CurrencyScale::bcformat($minimum, self::MARGIN_SCALE),
            $tSource, $tCatId, $mSource, $mCatId, $clamped,
        );
    }

    /**
     * @param  Collection<int,Product>  $products
     * @return array<string,EffectiveMargins>
     */
    public function resolveMany(Collection $products): array
    {
        // Eager-load company + category so resolve() never lazy-loads per row.
        $products->loadMissing(['company', 'category']);
        // Preload every ancestor category referenced on the page in ONE query.
        $ancestorIds = $products
            ->map(fn (Product $p) => $p->category)
            ->filter()
            ->flatMap(fn (Category $c) => explode('/', $c->path))
            ->filter()
            ->unique()
            ->all();
        $byId = Category::query()->whereIn('id', $ancestorIds)->get()->keyBy('id');

        $out = [];
        foreach ($products as $product) {
            $out[$product->id] = $this->resolveWithCache($product, $byId);
        }

        return $out;
    }

    /** @return array{0:string,1:MarginSource,2:?int} */
    private function resolveField(?string $override, array $chain, string $column, ?string $companyDefault, string $fallback): array
    {
        if ($override !== null) {
            return [(string) $override, MarginSource::Product, null];
        }
        foreach ($chain as $category) {
            if ($category->{$column} !== null) {
                return [(string) $category->{$column}, MarginSource::Category, $category->id];
            }
        }
        if ($companyDefault !== null) {
            return [(string) $companyDefault, MarginSource::Company, null];
        }

        return [$fallback, MarginSource::DefaultFallback, null];
    }

    /** @return list<Category> nearest-first: own category, then parent..root */
    private function categoryChain(Product $product): array
    {
        $category = $product->category;
        if ($category === null) {
            return [];
        }
        $ancestorsNearestFirst = $category->getAncestors()->reverse()->values()->all();

        return array_merge([$category], $ancestorsNearestFirst);
    }

    /** Variant of resolve() using a preloaded category map (batch path). */
    private function resolveWithCache(Product $product, Collection $byId): EffectiveMargins
    {
        $chain = [];
        if ($product->category !== null) {
            $ids = explode('/', $product->category->path);
            // path is root/.../self -> reverse for nearest-first (self first)
            foreach (array_reverse($ids) as $id) {
                if ($id !== '' && $byId->has((int) $id)) {
                    $chain[] = $byId->get((int) $id);
                }
            }
        }
        // Reuse the same field/clamp logic as resolve() by temporarily swapping the chain.
        return $this->buildFrom($product, $chain);
    }

    private function buildFrom(Product $product, array $chain): EffectiveMargins
    {
        $company = $product->company;
        [$target, $tSource, $tCatId] = $this->resolveField($product->target_margin_override, $chain, 'target_margin_override', $company?->default_target_margin, self::FALLBACK_TARGET);
        [$minimum, $mSource, $mCatId] = $this->resolveField($product->minimum_margin_override, $chain, 'minimum_margin_override', $company?->default_minimum_margin, self::FALLBACK_MINIMUM);
        $clamped = false;
        if (bccomp($minimum, $target, self::MARGIN_SCALE) > 0) { $minimum = $target; $clamped = true; }

        return new EffectiveMargins(
            CurrencyScale::bcformat($target, self::MARGIN_SCALE),
            CurrencyScale::bcformat($minimum, self::MARGIN_SCALE),
            $tSource, $tCatId, $mSource, $mCatId, $clamped,
        );
    }
}
```
> Note: refactor `resolve()` to also call `buildFrom($product, $this->categoryChain($product))` to keep one code path (DRY). The two methods above are shown expanded for clarity; collapse into `buildFrom`.

- [ ] **Step 4: Collapse `resolve()` to use `buildFrom`** (DRY), re-run, confirm still green.

- [ ] **Step 5: Run** `php artisan test tests/Feature/Product/MarginResolverTest.php` → PASS (all 5 tests).

- [ ] **Step 6: Commit**
```bash
git add app/Modules/Product/Application/Services/MarginResolver.php tests/Feature/Product/MarginResolverTest.php
git commit -m "feat(product): MarginResolver — 3-level resolution, clamp, per-field provenance, batch resolveMany"
```

## Task 5: `MarginService` delegates to resolver + queue-safe scale + `manual` guard

**Files:**
- Modify: `app/Modules/Product/Application/Services/MarginService.php`
- Test: `tests/Feature/Product/MarginServiceDelegationTest.php`

**Interfaces:**
- Consumes: `MarginResolver` (Task 4) injected via constructor.
- Produces: `getEffectiveMargins(Product): array{target_margin,minimum_margin,source,minimum_clamped}` (back-compat shape, now 3-level); `updateSalePrice` returns `false` for `manual` products; scale resolved from `$product->company->currency`.

- [ ] **Step 1: Write the failing tests**
```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Product;
use App\Modules\Company\Domain\CompanyContext;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarginServiceDelegationTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_margins_reflect_three_level_resolution(): void
    {
        $company = Company::factory()->create(['default_target_margin' => '20', 'default_minimum_margin' => '10']);
        $product = Product::factory()->for($company)->create(['target_margin_override' => '45.00']);
        $margins = app(MarginService::class)->getEffectiveMargins($product);
        $this->assertSame('45.00', $margins['target_margin']);
        $this->assertSame('product', $margins['source']);
    }

    public function test_update_sale_price_skips_manual_products(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Manual,
            'cost_price' => '10.000000',
            'sale_price' => '99.000',
            'target_margin_override' => '50.00',
        ]);
        $changed = app(MarginService::class)->updateSalePrice($product);
        $this->assertFalse($changed);
        $this->assertSame('99.000', (string) $product->fresh()->sale_price);
    }

    public function test_update_sale_price_reprices_auto_products(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Auto,
            'cost_price' => '10.000000',
            'sale_price' => '0.000',
            'target_margin_override' => '50.00',
        ]);
        $changed = app(MarginService::class)->updateSalePrice($product);
        $this->assertTrue($changed);
        $this->assertSame('15.000', (string) $product->fresh()->sale_price); // 10 * 1.5
    }

    public function test_reprice_does_not_throw_without_company_context(): void
    {
        app(CompanyContext::class)->clear(); // simulate queue worker
        $company = Company::factory()->create(['currency' => 'TND']);
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Auto, 'cost_price' => '10.000000', 'target_margin_override' => '50.00',
        ]);
        app(MarginService::class)->updateSalePrice($product); // must not throw
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Modify `MarginService`**
- Add to constructor: `private readonly MarginResolver $resolver`.
- Replace `getEffectiveMargins()` body to call `$this->resolver->resolve($product)` and map the DTO to the array shape, adding `'minimum_clamped'` and `'source' => $effective->target_source->value`.
- Delete `getMarginSource()` (now redundant).
- Replace `scale()` (line 44) with a currency-aware version used on the repricing path:
```php
private function scale(?Product $product = null): int
{
    $currency = $product?->company?->currency;
    return $this->scaleResolver->getScaleSafe($currency, 3);
}
```
  Update every internal caller of `scale()` on the pricing path to pass `$product`. (`priceFromMargin`/`updateSalePrice`/`getSuggestedPrice`/`getMarginLevel` all have the product in scope — thread it through.)
- In `updateSalePrice()`, add at the top after loading `$product`:
```php
if ($product->pricing_mode === PricingMode::Manual) {
    return false;
}
```
  and `$product->loadMissing('company');` before scale resolution.
- Add `use App\Modules\Product\Domain\Enums\PricingMode;` and `use App\Modules\Product\Application\Services\MarginResolver;`.

- [ ] **Step 4: Run** `php artisan test tests/Feature/Product/MarginServiceDelegationTest.php` → PASS. Also re-run any existing `MarginServiceTest` by path to confirm no regression.

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Application/Services/MarginService.php tests/Feature/Product/MarginServiceDelegationTest.php
git commit -m "feat(product): MarginService delegates to resolver; manual-mode reprice guard; queue-safe scale"
```

## Task 6: `minimum ≤ target` write-time validation (shared rule)

**Files:**
- Create: `app/Modules/Product/Presentation/Requests/Concerns/ValidatesMarginBand.php` (trait)
- Test: `tests/Unit/Product/ValidatesMarginBandTest.php`

**Interfaces:**
- Produces: a trait method `marginBandRule(string $minField, string $maxField): array` and an `after`-validator hook usable by product/category/company requests: when both fields present and `min > max` → error on `$minField`.

- [ ] **Step 1: Write the failing test** (unit-test the comparison helper)
```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Product;
use App\Modules\Product\Presentation\Requests\Concerns\ValidatesMarginBand;
use Tests\TestCase;

class ValidatesMarginBandTest extends TestCase
{
    use ValidatesMarginBand;

    public function test_detects_inversion(): void
    {
        $this->assertTrue($this->marginBandInverts('30.00', '20.00'));   // min>max
        $this->assertFalse($this->marginBandInverts('10.00', '20.00'));
        $this->assertFalse($this->marginBandInverts(null, '20.00'));     // partial -> no inversion
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement the trait**
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Presentation\Requests\Concerns;

trait ValidatesMarginBand
{
    protected function marginBandInverts(?string $min, ?string $max): bool
    {
        if ($min === null || $max === null || $min === '' || $max === '') {
            return false;
        }
        return bccomp($min, $max, 2) > 0;
    }

    /** Call from a FormRequest withValidator() to attach the cross-field rule. */
    protected function attachMarginBandRule(\Illuminate\Validation\Validator $validator, string $minField, string $maxField): void
    {
        $validator->after(function ($v) use ($minField, $maxField) {
            $data = $v->getData();
            if ($this->marginBandInverts($data[$minField] ?? null, $data[$maxField] ?? null)) {
                $v->errors()->add($minField, "The {$minField} must be less than or equal to {$maxField}.");
            }
        });
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Presentation/Requests/Concerns/ValidatesMarginBand.php tests/Unit/Product/ValidatesMarginBandTest.php
git commit -m "feat(product): shared minimum<=target margin-band validation trait"
```

## Task 7: Category margin persistence + cross-field validation

**Files:**
- Modify: `app/Modules/Product/Presentation/Requests/{Store,Update}CategoryRequest.php` (paths may differ — locate the category request classes used by the category controller)
- Modify: the category controller's create/update to persist the two override fields
- Test: `tests/Feature/Product/CategoryMarginPersistenceTest.php`

**Interfaces:**
- Consumes: `ValidatesMarginBand` (Task 6).
- Produces: category create/update accepts `target_margin_override`, `minimum_margin_override` (2dp, nullable) and rejects `min > target` with 422.

- [ ] **Step 1: Write the failing tests** (use `Tests\Traits\AssertsApiValidation`)
```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Product;
// ... imports, RefreshDatabase, RolesAndPermissionsSeeder, authenticated request helper
class CategoryMarginPersistenceTest extends TestCase
{
    use RefreshDatabase, AssertsApiValidation;

    public function test_persists_category_overrides(): void
    {
        // authenticate as a user with category permissions, then:
        $res = $this->postJson('/api/categories', ['name' => 'Oils', 'target_margin_override' => '40.00', 'minimum_margin_override' => '20.00']);
        $res->assertCreated();
        $this->assertDatabaseHas('categories', ['name' => 'Oils', 'target_margin_override' => '40.00']);
    }

    public function test_rejects_inverted_band(): void
    {
        $res = $this->postJson('/api/categories', ['name' => 'Bad', 'target_margin_override' => '20.00', 'minimum_margin_override' => '40.00']);
        $this->assertApiValidationError($res, 'minimum_margin_override');
    }

    public function test_rejects_three_decimals(): void
    {
        $res = $this->postJson('/api/categories', ['name' => 'Prec', 'target_margin_override' => '40.123']);
        $this->assertApiValidationError($res, 'target_margin_override');
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**
- Add rules to both category requests:
```php
'target_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
'minimum_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
```
- `use ValidatesMarginBand;` and add `withValidator(Validator $v): void { $this->attachMarginBandRule($v, 'minimum_margin_override', 'target_margin_override'); }`.
- Ensure the controller passes these into the create/update (they are already `$fillable` from Task 2).

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Presentation/Requests tests/Feature/Product/CategoryMarginPersistenceTest.php <category controller>
git commit -m "feat(product): persist category margin overrides with 2dp + band validation"
```

## Task 8: Company defaults exposure + band validation

**Files:**
- Modify: `app/Modules/Company/Presentation/Controllers/CompanyController.php` (`formatCompany`, ~line 586)
- Modify: the company-settings update request (locate it) — add 2dp rules + band rule for `default_target_margin`/`default_minimum_margin`
- Test: `tests/Feature/Company/CompanyMarginExposureTest.php`

**Interfaces:**
- Produces: `GET /company` response includes `default_target_margin` and `default_minimum_margin` (2dp strings).

- [ ] **Step 1: Write the failing test**
```php
public function test_company_payload_exposes_default_margins(): void
{
    // authenticate; company has default_target_margin=30, default_minimum_margin=15
    $res = $this->getJson('/api/company');
    $res->assertOk()
        ->assertJsonPath('data.default_target_margin', '30.00')
        ->assertJsonPath('data.default_minimum_margin', '15.00');
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**
In `formatCompany()` add before `created_at`:
```php
'default_target_margin' => $company->default_target_margin !== null
    ? \App\Shared\Domain\CurrencyScale::bcformat((string) $company->default_target_margin, 2) : null,
'default_minimum_margin' => $company->default_minimum_margin !== null
    ? \App\Shared\Domain\CurrencyScale::bcformat((string) $company->default_minimum_margin, 2) : null,
```
Add 2dp + band validation to the settings update request (`ValidatesMarginBand` trait, `attachMarginBandRule($v, 'default_minimum_margin', 'default_target_margin')`).

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Company/Presentation tests/Feature/Company/CompanyMarginExposureTest.php
git commit -m "feat(company): expose default margins in /company; band validation on settings"
```

## Task 9: `ProductPricingIntentService` — the pricing_mode/override enforcement seam

**Files:**
- Create: `app/Modules/Product/Application/Services/ProductPricingIntentService.php`
- Test: `tests/Feature/Product/ProductPricingIntentServiceTest.php`

**Interfaces:**
- Consumes: `MarginResolver` (Task 4), `PricingMode` (Task 1).
- Produces: `applyIntent(Product $product, array $validated): void` — mutates (does not save) `$product` setting `pricing_mode`, `target_margin_override`, `sale_price` per §4.5:
  1. payload has `pricing_mode` → trust it.
  2. else `sale_price` present without `pricing_mode` → `Manual`.
  3. else unchanged.
  And: when an explicit `target_margin_override` equals the inherited effective target → store `null` (keep inheriting), else store the value.

- [ ] **Step 1: Write the failing tests**
```php
public function test_explicit_manual_intent_on_price_edit(): void
{
    $product = Product::factory()->for(Company::factory())->create(['pricing_mode' => PricingMode::Auto]);
    app(ProductPricingIntentService::class)->applyIntent($product, ['sale_price' => '50.000', 'pricing_mode' => 'manual']);
    $this->assertSame(PricingMode::Manual, $product->pricing_mode);
    $this->assertSame('50.000', $product->sale_price);
}

public function test_margin_edit_keeps_auto_and_persists_override_when_different(): void
{
    $company = Company::factory()->create(['default_target_margin' => '30']);
    $product = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Manual]);
    app(ProductPricingIntentService::class)->applyIntent($product, ['pricing_mode' => 'auto', 'target_margin_override' => '45.00']);
    $this->assertSame(PricingMode::Auto, $product->pricing_mode);
    $this->assertSame('45.00', $product->target_margin_override);
}

public function test_margin_equal_to_inherited_stores_null(): void
{
    $company = Company::factory()->create(['default_target_margin' => '30']);
    $product = Product::factory()->for($company)->create();
    app(ProductPricingIntentService::class)->applyIntent($product, ['pricing_mode' => 'auto', 'target_margin_override' => '30.00']);
    $this->assertNull($product->target_margin_override); // equals inherited -> keep inheriting
}

public function test_import_path_without_mode_becomes_manual(): void
{
    $product = Product::factory()->for(Company::factory())->create(['pricing_mode' => PricingMode::Auto]);
    app(ProductPricingIntentService::class)->applyIntent($product, ['sale_price' => '12.000']); // no pricing_mode
    $this->assertSame(PricingMode::Manual, $product->pricing_mode);
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Application\Services;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;

final class ProductPricingIntentService
{
    public function __construct(private readonly MarginResolver $resolver) {}

    /** @param array<string,mixed> $validated */
    public function applyIntent(Product $product, array $validated): void
    {
        if (array_key_exists('pricing_mode', $validated) && $validated['pricing_mode'] !== null && $validated['pricing_mode'] !== '') {
            $product->pricing_mode = PricingMode::from((string) $validated['pricing_mode']);
        } elseif (array_key_exists('sale_price', $validated) && $validated['sale_price'] !== null && $validated['sale_price'] !== '') {
            $product->pricing_mode = PricingMode::Manual;
        }

        if (array_key_exists('sale_price', $validated)) {
            $product->sale_price = $validated['sale_price'];
        }

        if (array_key_exists('target_margin_override', $validated)) {
            $value = $validated['target_margin_override'];
            if ($value === null || $value === '') {
                $product->target_margin_override = null;
            } else {
                // store null if equal to inherited effective target (keep inheriting)
                $inherited = $this->resolver->resolve($product)->target_margin;
                $product->target_margin_override = bccomp((string) $value, $inherited, 2) === 0 ? null : (string) $value;
            }
        }
        if (array_key_exists('minimum_margin_override', $validated)) {
            $min = $validated['minimum_margin_override'];
            $product->minimum_margin_override = ($min === null || $min === '') ? null : (string) $min;
        }
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Application/Services/ProductPricingIntentService.php tests/Feature/Product/ProductPricingIntentServiceTest.php
git commit -m "feat(product): ProductPricingIntentService — pricing_mode/override enforcement seam"
```

## Task 10: Wire intent seam into product create/update + requests; stop blind mass-assign

**Files:**
- Modify: `app/Modules/Product/Presentation/Requests/StoreProductRequest.php`, `UpdateProductRequest.php`
- Modify: `app/Modules/Product/Presentation/Controllers/ProductController.php` (the update/store mass-assign at ~:689)
- Modify: `app/Modules/Product/Application/Services/ProductService.php` (`upsert`)
- Test: `tests/Feature/Product/ProductMarginIntentEndToEndTest.php`

**Interfaces:**
- Consumes: `ProductPricingIntentService` (Task 9).
- Produces: product create/update route the pricing fields through the intent seam (not blanket `$validated` mass-assign for `pricing_mode`/`target_margin_override`/`minimum_margin_override`/`sale_price`).

- [ ] **Step 1: Write the failing test**
```php
public function test_editing_margin_via_api_keeps_auto_and_recomputes_price(): void
{
    // authenticate; product with cost_price 10, company default 30
    $res = $this->putJson("/api/products/{$product->id}", ['pricing_mode' => 'auto', 'target_margin_override' => '50.00']);
    $res->assertOk();
    $fresh = $product->fresh();
    $this->assertSame(PricingMode::Auto, $fresh->pricing_mode);
    $this->assertSame('50.00', $fresh->target_margin_override);
    $this->assertSame('15.000', (string) $fresh->sale_price); // 10 * 1.5 via auto reprice
}

public function test_editing_price_via_api_sets_manual(): void
{
    $res = $this->putJson("/api/products/{$product->id}", ['pricing_mode' => 'manual', 'sale_price' => '99.000']);
    $res->assertOk();
    $this->assertSame(PricingMode::Manual, $product->fresh()->pricing_mode);
    $this->assertSame('99.000', (string) $product->fresh()->sale_price);
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**
- Add to both product requests:
```php
'pricing_mode' => ['sometimes', new \Illuminate\Validation\Rules\Enum(\App\Modules\Product\Domain\Enums\PricingMode::class)],
'target_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
'minimum_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
```
  and the band rule via `ValidatesMarginBand` (`attachMarginBandRule($v,'minimum_margin_override','target_margin_override')`).
- In `ProductController`: remove `pricing_mode`/`target_margin_override`/`minimum_margin_override`/`sale_price` from the blanket `$validated` mass-assign; after filling the other fields, call `$this->pricingIntent->applyIntent($product, $validated);` (constructor-inject `ProductPricingIntentService`), then save, then for `auto` products call `MarginService::updateSalePrice` to recompute. (Order: applyIntent sets override/mode → save → reprice if auto.)
- In `ProductService::upsert`: do not set `pricing_mode` explicitly; the `updateOrCreate` keeps the DB default on create. (Import path = manual via DB default; existing rows untouched.)

- [ ] **Step 4: Run** → PASS. Re-run existing product controller tests by path.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Presentation tests/Feature/Product/ProductMarginIntentEndToEndTest.php
git commit -m "feat(product): route pricing fields through intent seam on create/update"
```

## Task 11: Serialize effective margins + pricing_mode to FE (ProductData) + transform

**Files:**
- Modify: `app/Modules/Product/Application/DTOs/ProductData.php`
- Modify: the product show controller to pass resolved margins (single-product path → `MarginResolver::resolve`)
- Test: `tests/Feature/Product/ProductDataMarginSerializationTest.php`

**Interfaces:**
- Produces: `ProductData` gains `pricing_mode: PricingMode`, `effective_margins: EffectiveMargins`. Generated TS types updated.

- [ ] **Step 1: Write the failing test**
```php
public function test_product_show_includes_pricing_mode_and_effective_margins(): void
{
    // company default 30/15, product no overrides
    $res = $this->getJson("/api/products/{$product->id}");
    $res->assertOk()
        ->assertJsonPath('data.pricing_mode', 'manual')
        ->assertJsonPath('data.effective_margins.target_margin', '30.00')
        ->assertJsonPath('data.effective_margins.target_source', 'company');
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**
- Add constructor params `public PricingMode $pricing_mode` and `public ?EffectiveMargins $effective_margins = null` to `ProductData`.
- In `fromModel`, set `pricing_mode: $product->pricing_mode`. Add an optional `?EffectiveMargins $effective = null` param to `fromModel` and assign it (the show controller computes it via `MarginResolver::resolve` and passes it). Keep `effective_margins` null in list contexts unless `resolveMany` is wired.
- Add `use App\Modules\Product\Domain\Enums\PricingMode;` import.

- [ ] **Step 4: Regenerate types**
Run: `CACHE_STORE=array php artisan typescript:transform`
Verify `packages/shared/types/` now contains `EffectiveMargins` and `pricing_mode` on the product type.

- [ ] **Step 5: Run** `php artisan test tests/Feature/Product/ProductDataMarginSerializationTest.php` → PASS.

- [ ] **Step 6: Commit**
```bash
git add app/Modules/Product/Application/DTOs/ProductData.php app/Modules/Product/Presentation packages/shared/types tests/Feature/Product/ProductDataMarginSerializationTest.php
git commit -m "feat(product): serialize pricing_mode + effective_margins to FE"
```

## Task 12: Explicit recalc command (the only sanctioned manual→auto reprice)

**Files:**
- Create: `app/Modules/Product/Application/Services/RecalculateSalePriceAction.php`
- Test: `tests/Feature/Product/RecalculateSalePriceActionTest.php`

**Interfaces:**
- Consumes: `MarginService` (Task 5).
- Produces: `execute(Product $product): bool` — sets `pricing_mode = Auto`, recomputes `sale_price` from the effective target (even if it was `manual`), saves.

- [ ] **Step 1: Write the failing test**
```php
public function test_recalc_reprices_manual_product_and_sets_auto(): void
{
    $company = Company::factory()->create();
    $product = Product::factory()->for($company)->create([
        'pricing_mode' => PricingMode::Manual, 'cost_price' => '10.000000',
        'sale_price' => '99.000', 'target_margin_override' => '50.00',
    ]);
    app(RecalculateSalePriceAction::class)->execute($product);
    $fresh = $product->fresh();
    $this->assertSame(PricingMode::Auto, $fresh->pricing_mode);
    $this->assertSame('15.000', (string) $fresh->sale_price);
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace App\Modules\Product\Application\Services;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;

final class RecalculateSalePriceAction
{
    public function __construct(private readonly MarginService $marginService) {}

    public function execute(Product $product): bool
    {
        $product->pricing_mode = PricingMode::Auto;
        $product->save();
        return $this->marginService->updateSalePrice($product); // now reprices since auto
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Application/Services/RecalculateSalePriceAction.php tests/Feature/Product/RecalculateSalePriceActionTest.php
git commit -m "feat(product): explicit recalc action (manual->auto reprice)"
```

## Task 13: Backfill command `products:backfill-pricing-mode`

**Files:**
- Create: `app/Modules/Product/Presentation/Console/BackfillPricingModeCommand.php`
- Test: `tests/Feature/Product/BackfillPricingModeCommandTest.php`

**Interfaces:**
- Consumes: `MarginResolver` (Task 4). Flips a product to `auto` only when `cost_price > 0` AND `sale_price == priceFromMargin(cost, effective_target)` at money scale; else leaves `manual`. Idempotent. Eager-loads company+category (batch).

- [ ] **Step 1: Write the failing tests**
```php
public function test_auto_priced_row_becomes_auto(): void
{
    $company = Company::factory()->create(['currency' => 'TND', 'default_target_margin' => '50']);
    // 10 * 1.5 = 15.000 -> matches effective target -> auto
    $p = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Manual, 'cost_price' => '10.000000', 'sale_price' => '15.000']);
    $this->artisan('products:backfill-pricing-mode')->assertExitCode(0);
    $this->assertSame(PricingMode::Auto, $p->fresh()->pricing_mode);
}

public function test_hand_set_row_stays_manual(): void
{
    $company = Company::factory()->create(['default_target_margin' => '50']);
    $p = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Manual, 'cost_price' => '10.000000', 'sale_price' => '99.000']);
    $this->artisan('products:backfill-pricing-mode');
    $this->assertSame(PricingMode::Manual, $p->fresh()->pricing_mode);
}

public function test_zero_cost_row_stays_manual(): void
{
    $p = Product::factory()->for(Company::factory())->create(['pricing_mode' => PricingMode::Manual, 'cost_price' => '0.000000', 'sale_price' => '5.000']);
    $this->artisan('products:backfill-pricing-mode');
    $this->assertSame(PricingMode::Manual, $p->fresh()->pricing_mode);
}

public function test_idempotent_on_rerun(): void
{
    $company = Company::factory()->create(['default_target_margin' => '50']);
    $p = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Manual, 'cost_price' => '10.000000', 'sale_price' => '15.000']);
    $this->artisan('products:backfill-pricing-mode');
    $this->artisan('products:backfill-pricing-mode'); // second run
    $this->assertSame(PricingMode::Auto, $p->fresh()->pricing_mode);
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** the command. Chunk products with `with(['company','category'])`; for each chunk call `resolveMany`; for each product compute `priceFromMargin(cost, effective_target)` via a small public helper on `MarginService` (expose `priceFromMargin` as `public function computeAutoPrice(Product $product, string $targetMargin): string` if not already) using `getScaleSafe($product->company->currency, 3)`; compare to `sale_price` at money scale; if `cost>0` and equal → set `pricing_mode = Auto` and save. Log totals + sample ids. Only consider rows currently `manual` (idempotency).

- [ ] **Step 4: Run** `php artisan test tests/Feature/Product/BackfillPricingModeCommandTest.php` → PASS (all 4).

- [ ] **Step 5: Commit**
```bash
git add app/Modules/Product/Presentation/Console/BackfillPricingModeCommand.php app/Modules/Product/Application/Services/MarginService.php tests/Feature/Product/BackfillPricingModeCommandTest.php
git commit -m "feat(product): backfill command flips proven-auto products to auto (idempotent)"
```

## Task 14: Backend slice — preflight + reconcile

- [ ] **Step 1: Run targeted backend checks (by path — never full suite)**
```bash
cd apps/api
./vendor/bin/phpstan analyse app/Modules/Product app/Modules/Company
./vendor/bin/pint app/Modules/Product app/Modules/Company
php artisan test tests/Unit/Product tests/Feature/Product tests/Feature/Company
```
Expected: PHPStan 0 errors on touched files; Pint clean; all listed tests PASS.

- [ ] **Step 2: Reconcile with origin/dev (rule 21)**
```bash
git fetch origin dev
git merge origin/dev   # resolve if needed
```

- [ ] **Step 3: Open PR `feat/margin-category-override` → dev.** Log the API/DB shape change in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` if the cross-team contract changed (new product/company fields).

---

# FRONTEND SLICE — branch `feat/izipos-theme-product-editor` (rebased onto backend slice)

> Setup: from the izipos editor worktree, rebase onto the merged backend slice (or `origin/dev` once it lands) so generated types include `effective_margins`/`pricing_mode`. Confirm worktree `vendor` is a real copy, not a symlink. FE paths below are relative to `apps/web/`.

## Task 15: Float-free, correct-formula `PriceInputWithMargin`

**Files:**
- Modify: `src/components/molecules/PriceInputWithMargin/PriceInputWithMargin.tsx` (current floats at lines 7,9,10,11,22,27,90,111,142,167)
- Test: `src/components/molecules/PriceInputWithMargin/__tests__/PriceInputWithMargin.test.tsx`

**Interfaces:**
- Produces: string props `cost`, `value` (sale price), `margin` (string), callbacks emit strings; markup-on-cost math via `lib/decimal.ts`.

- [ ] **Step 1: Write the failing tests**
```tsx
import { render, screen, fireEvent } from '@testing-library/react'
import { PriceInputWithMargin } from '../PriceInputWithMargin'

it('computes markup-on-cost margin from price', () => {
  const onChange = vi.fn()
  render(<PriceInputWithMargin cost="10.000" value="15.000" onChange={onChange} />)
  // displayed margin should be 50.00 ((15-10)/10*100)
  expect(screen.getByTestId('margin-display')).toHaveTextContent('50')
})

it('recomputes price from margin (cost*(1+m/100))', () => {
  const onChange = vi.fn()
  render(<PriceInputWithMargin cost="10.000" value="" onChange={onChange} />)
  fireEvent.change(screen.getByTestId('margin-input'), { target: { value: '50' } })
  expect(onChange).toHaveBeenCalledWith('15.000') // string, no float
})

it('shows blank margin when cost is zero (no divide-by-zero)', () => {
  render(<PriceInputWithMargin cost="0.000" value="15.000" onChange={vi.fn()} />)
  expect(screen.getByTestId('margin-display')).toHaveTextContent('—')
})
```

- [ ] **Step 2: Run** `pnpm test PriceInputWithMargin` → FAIL.

- [ ] **Step 3: Rewrite** the component: string props; compute via `bcsub/bcdiv/bcmul/bcadd/bccomp` from `@/lib/decimal`; `margin = bcmul(bcdiv(bcsub(sale,cost),cost,6),'100',6)` formatted to 2dp; `sale = bcmul(cost, bcadd('1', bcdiv(margin,'100',6),6),3)`; guard `bccomp(cost,'0',6) <= 0` → render `—`, no recompute; feedback-loop guard (skip recompute when value set programmatically). No `parseFloat`/`Number`/`Math.round`/native arithmetic anywhere.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Lint** `pnpm lint src/components/molecules/PriceInputWithMargin` (no-parsefloat-on-money / no-hardcoded-step must pass).
- [ ] **Step 6: Commit**
```bash
git add src/components/molecules/PriceInputWithMargin
git commit -m "fix(web): PriceInputWithMargin markup-on-cost + float-free"
```

## Task 16: Float-free, formula-fixed `ProductPricingCard`

**Files:**
- Modify: `src/features/inventory/components/pricing/ProductPricingCard.tsx` (own `parseFloat` at 34-37; formula inconsistency markup-on-cost@42 vs gross-inverse@57)
- Test: `src/features/inventory/components/pricing/__tests__/ProductPricingCard.test.tsx`

**Interfaces:**
- Consumes: the same `lib/decimal.ts`. This is a SEPARATE rewrite (not just a prop update of Task 15).

- [ ] **Step 1: Write the failing test** asserting the suggested price uses `cost×(1+m/100)` (markup-on-cost) consistently and no `parseFloat`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Rewrite** the card to use string math via `lib/decimal.ts`, the canonical cost basis (`cost_price>0 ? cost_price : purchase_price`), and the markup-on-cost formula throughout; remove `parseFloat`/native math.
- [ ] **Step 4: Run** → PASS. **Step 5: Lint. Step 6: Commit**
```bash
git add src/features/inventory/components/pricing/ProductPricingCard.tsx src/features/inventory/components/pricing/__tests__
git commit -m "fix(web): ProductPricingCard float-free + consistent markup-on-cost"
```

## Task 17: Wire the editable margin field into the product editor

**Files:**
- Modify: the product editor pricing card on the izipos editor branch (the component rendering `PriceInputWithMargin`)
- Test: editor pricing card test

**Interfaces:**
- Consumes: `effective_margins`, `pricing_mode` from the product payload (Task 11 generated types); `PriceInputWithMargin` (Task 15).

- [ ] **Step 1: Write the failing tests**
  - seeds margin from `effective_margins.target_margin` (not company default) on load.
  - editing margin → submit payload contains `pricing_mode: 'auto'` + `target_margin_override`.
  - editing sale price → submit payload contains `pricing_mode: 'manual'` + `sale_price`, no override.
  - untouched save → payload contains neither override nor a flipped mode.
  - basis = `cost_price>0 ? cost_price : purchase_price`.
  - existing override `"30.000"` is normalized to `"30.00"` on load (no 422 on resave).
  - color band + provenance label render from per-field source; `minimum_clamped` hint shows when true.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the wiring per §5.2/§4.12/§4.13. Normalize loaded override values with a 2dp `formatPercent` helper. Emit string payloads.
- [ ] **Step 4: Run** → PASS. **Step 5: Lint. Step 6: Commit**
```bash
git commit -am "feat(web): editable hierarchy-aware margin field on product editor"
```

## Task 18: Category margin-defaults section in `CategoryForm`

**Files:**
- Modify: `src/features/categories/components/CategoryForm.tsx`
- Modify: `src/features/categories/api/categoriesApi.ts` + types (override fields)
- Test: `src/features/categories/components/__tests__/CategoryForm.test.tsx`

**Interfaces:**
- Produces: optional target/minimum override inputs (2dp, float-free), inline `minimum ≤ target` feedback; empty = inherit.

- [ ] **Step 1: Write the failing test** (renders inputs; submitting target<minimum shows inline error; submitting valid values calls onSubmit with the two fields).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the "Margin defaults" section; add fields to `CreateCategoryInput`/`UpdateCategoryInput`; 2dp input handling; inline band validation.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit**
```bash
git commit -am "feat(web): category margin-defaults section with band validation"
```

## Task 19: i18n + frontend preflight

**Files:**
- Modify: `src/locales/{en,fr}/inventory.json`, `categories.json`, `settings.json` (new keys for margin labels, provenance, clamp hint, validation messages)

- [ ] **Step 1:** Add all new keys (en + fr). No hardcoded user-facing strings (rule 11). Onboarding-safe namespaces.
- [ ] **Step 2: Run FE preflight (scoped)**
```bash
cd apps/web
pnpm typecheck
pnpm lint src/components/molecules/PriceInputWithMargin src/features/inventory/components/pricing src/features/categories
pnpm test PriceInputWithMargin ProductPricingCard CategoryForm
```
Expected: typecheck clean; lint clean (no float/step violations); tests PASS.
- [ ] **Step 3: Commit**
```bash
git commit -am "feat(web): i18n keys for margin hierarchy; FE preflight green"
```

---

## Final verification (both slices)

- [ ] Backend: `php artisan test tests/Unit/Product tests/Feature/Product tests/Feature/Company` (by path) → all PASS.
- [ ] Frontend: scoped `pnpm typecheck && pnpm lint && pnpm test` on touched dirs → PASS.
- [ ] Manual E2E (db-per-tenant local recipe → `reference_db_per_tenant_local_visual_test.md`): open a product, edit margin → price updates + stays auto; edit price → margin updates + flips manual; a category with an override flows to an un-overridden product; company default change reflects on inheriting products after recalc; manual product survives a simulated cost change.
- [ ] Reconcile each branch with `origin/dev` (rule 21); preflight green; open PRs.
