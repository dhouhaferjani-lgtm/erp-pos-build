# POS Return Disposition — Phase 0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-return-line **disposition** (`RESTOCK | SCRAP | NOT_RECEIVED`) to the live (legacy-chain) POS return path so damaged/kept goods are no longer silently restocked, plus the `restock_policy` resolution hierarchy, the regulated-goods `never ⇒ no RESTOCK` guard, and a fast-follow guard stopping the fiscal projection from decrementing stock for REFUND/VOID.

**Architecture:** Disposition is a per-return-receipt-line operational fact (outside the signed fiscal perimeter in Phase 0). It threads through `ReceiptReturnService::processReturn` → validation → the stock step. `RESTOCK` keeps today's behavior; `SCRAP` is two **quantity-only** movements (receive `+qty` then write-off `−qty`, batch restitution skipped); `NOT_RECEIVED` writes no movement. A `RestockPolicyResolver` (product → category tree → tenant default, mirroring `MarginResolver`) drives the default and a hard guard. Everything is additive (default `RESTOCK`).

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PostgreSQL (tenant DB), PHPUnit, `spatie/laravel-data` DTOs, backed enums, bcmath at quantity scale 4.

## Global Constraints

- **Branch/worktree:** `feat/pos-return-disposition`, worktree `/Users/houssamr/Projects/syneriva/apps/erp.pos-refund` (rebased onto `origin/dev`). All paths below are under `apps/api/`.
- **PHP strict typing:** `declare(strict_types=1);`; no `mixed`; constructor injection with `private readonly` only — **never `app()`** in production code.
- **Enums** for every status/type/policy column. No magic strings.
- **Quantity precision:** quantities are `decimal(N,4)` numeric-strings; all arithmetic via `bcmath` at scale **4**. Never cast money/quantity to float. Phase 0 is **quantity-only** — do NOT write `unit_cost`/`avg_cost_*` on return movements and do NOT call `WeightedAverageCostService::recordReturn` (would corrupt WAC on net-zero scrap; valuation is a later GL-phase concern).
- **Disposition stored per return-receipt line**, NOT per original line (sequential partial returns may carry mixed dispositions). The cumulative already-returned cap stays disposition-independent.
- **Tests:** `RefreshDatabase` + real factories + real models; never fake payloads. **Run tests BY PATH, never the full suite** (`php artisan test --filter` or path). Service tests seed `CompanyContext` (see Task scaffolds); the projection test must `app(CompanyContext::class)->clear()` immediately before `apply()`.
- **Shared types:** after adding/altering a PHP DTO consumed by the frontend, run `php artisan typescript:transform` (needs `CACHE_STORE=array` in this worktree env). `ReturnLineDisposition`/`RestockPolicy` are emitted to `packages/shared/types`.
- **Per-task verification:** the touched PHPUnit test (by path), `./vendor/bin/phpstan analyse --level=8` (touched paths, `--memory-limit=2G`), `./vendor/bin/pint` (touched paths). Commit only when green.
- **Default fallback:** `ReturnLineDisposition::Restock` when a caller omits disposition — every existing return test must stay green unchanged.

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Modules/POS/Domain/Enums/ReturnLineDisposition.php` | `RESTOCK / SCRAP / NOT_RECEIVED` | Create |
| `app/Modules/Product/Domain/Enums/RestockPolicy.php` | `never / if_sealed / default_allow` | Create |
| `app/Modules/Product/Domain/Enums/RestockPolicySource.php` | provenance (`product/category/company/default`) | Create |
| `app/Modules/Product/Application/DTOs/EffectiveRestockPolicy.php` | resolved policy + provenance | Create |
| `app/Modules/Product/Application/Services/RestockPolicyResolver.php` | hierarchy resolver (mirrors `MarginResolver`) | Create |
| `app/Modules/Product/Providers/*ServiceProvider.php` | bind resolver (if not auto-wired) | Modify |
| `database/migrations/tenant/<ts>_add_disposition_to_pos_receipt_lines.php` | `physical_receipt / resalable / disposition` cols | Create |
| `database/migrations/tenant/<ts>_add_restock_policy_to_products_and_categories.php` | nullable `restock_policy` cols | Create |
| `app/Modules/POS/Domain/ReceiptLine.php` | casts for new cols | Modify |
| `app/Modules/Product/Domain/{Product,Category}.php` | casts for `restock_policy` | Modify |
| `app/Modules/Company/Domain/ValueObjects/ReservationSettings.php` | `default_restock_policy` field | Modify |
| `app/Modules/POS/Presentation/Requests/StoreReturnRequest.php` | per-line disposition + facts rules | Modify |
| `app/Modules/POS/Application/Services/ReceiptReturnService.php` | thread disposition; branch stock step; never-guard; persist on lines | Modify |
| `app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` | guard: no decrement for `ReceiptType::Return` | Modify |

---

### Task 1: Domain enums

**Files:**
- Create: `app/Modules/POS/Domain/Enums/ReturnLineDisposition.php`
- Create: `app/Modules/Product/Domain/Enums/RestockPolicy.php`
- Create: `app/Modules/Product/Domain/Enums/RestockPolicySource.php`
- Test: `tests/Unit/POS/Enums/ReturnLineDispositionTest.php`

**Interfaces:**
- Produces: `ReturnLineDisposition::{Restock,Scrap,NotReceived}` (string values `restock|scrap|not_received`) + `::values(): list<string>`; `RestockPolicy::{Never,IfSealed,DefaultAllow}` (`never|if_sealed|default_allow`) + `::values()`; `RestockPolicySource::{Product,Category,Company,DefaultFallback}` (`product|category|company|default`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Enums;

use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use Tests\TestCase;

final class ReturnLineDispositionTest extends TestCase
{
    public function test_cases_and_values(): void
    {
        $this->assertSame('restock', ReturnLineDisposition::Restock->value);
        $this->assertSame('scrap', ReturnLineDisposition::Scrap->value);
        $this->assertSame('not_received', ReturnLineDisposition::NotReceived->value);
        $this->assertSame(['restock', 'scrap', 'not_received'], ReturnLineDisposition::values());
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — `php artisan test --filter=ReturnLineDispositionTest` → FAIL (class not found).

- [ ] **Step 3: Write the enums**

```php
<?php // app/Modules/POS/Domain/Enums/ReturnLineDisposition.php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ReturnLineDisposition: string
{
    case Restock = 'restock';
    case Scrap = 'scrap';
    case NotReceived = 'not_received';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

```php
<?php // app/Modules/Product/Domain/Enums/RestockPolicy.php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum RestockPolicy: string
{
    case Never = 'never';
    case IfSealed = 'if_sealed';
    case DefaultAllow = 'default_allow';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

```php
<?php // app/Modules/Product/Domain/Enums/RestockPolicySource.php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum RestockPolicySource: string
{
    case Product = 'product';
    case Category = 'category';
    case Company = 'company';
    case DefaultFallback = 'default';
}
```

- [ ] **Step 4: Run test to verify it passes** — `php artisan test --filter=ReturnLineDispositionTest` → PASS.
- [ ] **Step 5: PHPStan + Pint on the three files; commit** — `git commit -m "feat(pos): ReturnLineDisposition + RestockPolicy domain enums"`.

---

### Task 2: Migrations + model casts (disposition cols + restock_policy cols)

**Files:**
- Create: `database/migrations/tenant/<ts>_add_disposition_to_pos_receipt_lines.php`
- Create: `database/migrations/tenant/<ts>_add_restock_policy_to_products_and_categories.php`
- Modify: `app/Modules/POS/Domain/ReceiptLine.php` (casts)
- Modify: `app/Modules/Product/Domain/Product.php`, `app/Modules/Product/Domain/Category.php` (casts)
- Test: `tests/Feature/POS/ReturnDispositionColumnsTest.php`

**Interfaces:**
- Produces: `pos_receipt_lines.{physical_receipt:bool nullable, resalable:bool nullable, disposition:string nullable}`; `products.restock_policy` / `categories.restock_policy` (string nullable). Casts: `ReceiptLine.disposition => ReturnLineDisposition::class`; `Product.restock_policy`/`Category.restock_policy => RestockPolicy::class`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReturnDispositionColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('pos_receipt_lines', ['physical_receipt', 'resalable', 'disposition']));
        $this->assertTrue(Schema::hasColumn('products', 'restock_policy'));
        $this->assertTrue(Schema::hasColumn('categories', 'restock_policy'));
    }

    public function test_product_restock_policy_casts_to_enum(): void
    {
        $product = Product::factory()->create(['restock_policy' => RestockPolicy::Never->value]);
        $this->assertSame(RestockPolicy::Never, $product->fresh()->restock_policy);
        $this->assertNull(Product::factory()->create()->restock_policy);
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL (columns missing).

- [ ] **Step 3: Write the migrations** (mirror the verified `string()->nullable()->after()` idiom)

```php
<?php // <ts>_add_disposition_to_pos_receipt_lines.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            $table->boolean('physical_receipt')->nullable()->after('quantity');
            $table->boolean('resalable')->nullable()->after('physical_receipt');
            $table->string('disposition', 32)->nullable()->after('resalable');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn(['physical_receipt', 'resalable', 'disposition']);
        });
    }
};
```

```php
<?php // <ts>_add_restock_policy_to_products_and_categories.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('restock_policy', 32)->nullable()->after('product_type'));
        Schema::table('categories', fn (Blueprint $t) => $t->string('restock_policy', 32)->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('restock_policy'));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('restock_policy'));
    }
};
```

> Implementer note: confirm the `after()` anchor columns exist on each table (`product_type` on products; if absent, drop the `after()`).

- [ ] **Step 4: Add casts.** In `ReceiptLine::casts()` add `'disposition' => ReturnLineDisposition::class`, `'physical_receipt' => 'boolean'`, `'resalable' => 'boolean'`. In `Product::casts()` and `Category::casts()` add `'restock_policy' => RestockPolicy::class`. Add the columns to each model's `$fillable` if it uses an explicit guarded list.

- [ ] **Step 5: Run test to verify it passes** — PASS.
- [ ] **Step 6: PHPStan + Pint; commit** — `git commit -m "feat(pos): disposition + restock_policy columns and casts"`.

---

### Task 3: `default_restock_policy` in ReservationSettings DTO + backfill

**Files:**
- Modify: `app/Modules/Company/Domain/ValueObjects/ReservationSettings.php`
- Create: `database/migrations/tenant/<ts>_backfill_default_restock_policy.php`
- Test: `tests/Unit/Company/ReservationSettingsRestockPolicyTest.php`

**Interfaces:**
- Produces: `ReservationSettings::$default_restock_policy` (string, default `'default_allow'`), round-tripped via `fromArray`/`toArray` key `default_restock_policy`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Company;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use Tests\TestCase;

final class ReservationSettingsRestockPolicyTest extends TestCase
{
    public function test_default_and_round_trip(): void
    {
        $this->assertSame('default_allow', (new ReservationSettings)->default_restock_policy);

        $rebuilt = ReservationSettings::fromArray(
            (new ReservationSettings(default_restock_policy: 'if_sealed'))->toArray()
        );
        $this->assertSame('if_sealed', $rebuilt->default_restock_policy);
    }

    public function test_from_array_falls_back_when_key_absent(): void
    {
        $this->assertSame('default_allow', ReservationSettings::fromArray([])->default_restock_policy);
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL (unknown named arg / property).

- [ ] **Step 3: Add the field.** Add `public readonly string $default_restock_policy = 'default_allow'` to the constructor; in `fromArray` map `default_restock_policy: $data['default_restock_policy'] ?? 'default_allow'`; in `toArray` add `'default_restock_policy' => $this->default_restock_policy`. (Follow the exact existing field pattern in the DTO.)

- [ ] **Step 4: Backfill migration** — for existing companies merge the key in if missing (vertical-aware default deferred; `default_allow` is the safe global default — regulated tenants are protected by the per-product `never` guard, Task 8):

```php
public function up(): void
{
    \App\Modules\Company\Domain\Company::query()->each(function ($company): void {
        $settings = $company->reservation_settings ?? [];
        if (! array_key_exists('default_restock_policy', $settings)) {
            $settings['default_restock_policy'] = 'default_allow';
            $company->reservation_settings = $settings;
            $company->save();
        }
    });
}

public function down(): void { /* additive key; no-op */ }
```

- [ ] **Step 5: Run test to verify it passes** — PASS.
- [ ] **Step 6: PHPStan + Pint; `php artisan typescript:transform` (CACHE_STORE=array) if ReservationSettings is type-exported; commit** — `git commit -m "feat(company): default_restock_policy in ReservationSettings"`.

---

### Task 4: `RestockPolicyResolver` + provenance DTO

**Files:**
- Create: `app/Modules/Product/Application/DTOs/EffectiveRestockPolicy.php`
- Create: `app/Modules/Product/Application/Services/RestockPolicyResolver.php`
- Modify: a Product-module service provider (only if the resolver is not constructor-auto-wireable)
- Test: `tests/Unit/Product/RestockPolicyResolverTest.php`

**Interfaces:**
- Consumes: `RestockPolicy`, `RestockPolicySource` (Task 1); `Company.reservation_settings['default_restock_policy']` (Task 3); `Category::parent()` / `Product::category()`.
- Produces: `RestockPolicyResolver::resolve(string $productId): EffectiveRestockPolicy`; `EffectiveRestockPolicy { RestockPolicy $policy; RestockPolicySource $source; ?int $sourceCategoryId; }`.

> Design (mirrors `MarginResolver`): product.restock_policy wins → nearest-first category-chain walk (`$category` then its ancestors) → `Company.reservation_settings['default_restock_policy']` → fallback `default_allow`. The resolver loads `Product` internally (Product module owns the model) so POS callers pass only a `productId` string (no cross-module model import).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\RestockPolicyResolver;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Enums\RestockPolicySource;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RestockPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): RestockPolicyResolver
    {
        return $this->app->make(RestockPolicyResolver::class);
    }

    public function test_product_override_wins(): void
    {
        $product = Product::factory()->create(['restock_policy' => RestockPolicy::Never->value]);
        $result = $this->resolver()->resolve($product->id);
        $this->assertSame(RestockPolicy::Never, $result->policy);
        $this->assertSame(RestockPolicySource::Product, $result->source);
    }

    public function test_parent_category_supplies_when_product_and_leaf_null(): void
    {
        $parent = Category::factory()->create(['restock_policy' => RestockPolicy::IfSealed->value]);
        $leaf = Category::factory()->create(['parent_id' => $parent->id, 'restock_policy' => null]);
        $product = Product::factory()->create(['category_id' => $leaf->id, 'restock_policy' => null]);
        $result = $this->resolver()->resolve($product->id);
        $this->assertSame(RestockPolicy::IfSealed, $result->policy);
        $this->assertSame(RestockPolicySource::Category, $result->source);
        $this->assertSame($parent->id, $result->sourceCategoryId);
    }

    public function test_falls_back_to_company_then_default(): void
    {
        $company = Company::factory()->create([
            'reservation_settings' => ['default_restock_policy' => RestockPolicy::IfSealed->value],
        ]);
        $product = Product::factory()->create(['company_id' => $company->id, 'restock_policy' => null, 'category_id' => null]);
        $result = $this->resolver()->resolve($product->id);
        $this->assertSame(RestockPolicy::IfSealed, $result->policy);
        $this->assertSame(RestockPolicySource::Company, $result->source);
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement the DTO + resolver**

```php
<?php // app/Modules/Product/Application/DTOs/EffectiveRestockPolicy.php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Enums\RestockPolicySource;

final class EffectiveRestockPolicy
{
    public function __construct(
        public readonly RestockPolicy $policy,
        public readonly RestockPolicySource $source,
        public readonly ?int $sourceCategoryId,
    ) {}
}
```

```php
<?php // app/Modules/Product/Application/Services/RestockPolicyResolver.php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\DTOs\EffectiveRestockPolicy;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Enums\RestockPolicySource;
use App\Modules\Product\Domain\Product;

final class RestockPolicyResolver
{
    private const FALLBACK = RestockPolicy::DefaultAllow;

    public function resolve(string $productId): EffectiveRestockPolicy
    {
        /** @var Product|null $product */
        $product = Product::query()->with('category')->find($productId);

        if ($product?->restock_policy instanceof RestockPolicy) {
            return new EffectiveRestockPolicy($product->restock_policy, RestockPolicySource::Product, null);
        }

        foreach ($this->categoryChain($product) as $category) {
            if ($category->restock_policy instanceof RestockPolicy) {
                return new EffectiveRestockPolicy($category->restock_policy, RestockPolicySource::Category, $category->id);
            }
        }

        $companyDefault = $product?->company?->reservation_settings['default_restock_policy'] ?? null;
        if (is_string($companyDefault) && ($policy = RestockPolicy::tryFrom($companyDefault)) !== null) {
            return new EffectiveRestockPolicy($policy, RestockPolicySource::Company, null);
        }

        return new EffectiveRestockPolicy(self::FALLBACK, RestockPolicySource::DefaultFallback, null);
    }

    /** @return list<Category> nearest-first (leaf → root) */
    private function categoryChain(?Product $product): array
    {
        $category = $product?->category;
        if ($category === null) {
            return [];
        }

        $chain = [$category];
        $cursor = $category;
        // Walk parent_id to the root (self-referencing tree).
        while (($cursor = $cursor->parent) !== null) {
            $chain[] = $cursor;
        }

        return $chain;
    }
}
```

> Implementer note: confirm `Product::company()` relation + `reservation_settings` cast (`'array'`). If `MarginResolver` uses `$category->getAncestors()`, you MAY reuse it instead of the `parent` walk — both are acceptable; the `parent` walk avoids a dependency on that method.

- [ ] **Step 4: Run test to verify it passes** — `php artisan test --filter=RestockPolicyResolverTest` → PASS. If DI fails to resolve, add an explicit `bind` in the Product service provider.
- [ ] **Step 5: PHPStan + Pint; commit** — `git commit -m "feat(product): RestockPolicyResolver with provenance"`.

---

### Task 5: `StoreReturnRequest` — per-line disposition + facts validation

**Files:**
- Modify: `app/Modules/POS/Presentation/Requests/StoreReturnRequest.php`
- Test: `tests/Feature/POS/StoreReturnRequestDispositionTest.php`

**Interfaces:**
- Produces: validated `lines.*.physical_receipt` (bool, optional), `lines.*.resalable` (nullable bool), `lines.*.disposition` (nullable, ∈ `ReturnLineDisposition::values()`). Structural illegal-combo rejection at the request boundary.

- [ ] **Step 1: Write the failing test** — assert a payload with `disposition=restock` + `physical_receipt=false` fails validation; a `disposition=not_received` + `physical_receipt=false` passes; an unknown disposition string fails. (Use the project's `AssertsApiValidation` trait / `postJson` to the return route, mirroring existing request tests.)

- [ ] **Step 2: Run test to verify it fails.**

- [ ] **Step 3: Extend `rules()`** (append to the returned array):

```php
'lines.*.physical_receipt' => ['sometimes', 'boolean'],
'lines.*.resalable' => ['nullable', 'boolean'],
'lines.*.disposition' => ['nullable', 'string', Rule::in(ReturnLineDisposition::values())],
```

Add a `withValidator()` closure rejecting structural illegal combos per line: `disposition === 'restock'` ⇒ `physical_receipt === true && resalable === true`; `physical_receipt === false` ⇒ `resalable` null/absent && `disposition` ∈ `{null, not_received}`. (Business `never` guard is enforced in the service, Task 8 — it needs DB resolution.)

- [ ] **Step 4: Run test to verify it passes.**
- [ ] **Step 5: PHPStan + Pint; commit** — `git commit -m "feat(pos): validate per-line disposition + receipt facts"`.

---

### Task 6: Thread disposition through `processReturn` → validated lines (default RESTOCK)

**Files:**
- Modify: `app/Modules/POS/Application/Services/ReceiptReturnService.php` (`processReturn` docblock for `$returnLines`; `validateReturnQuantities`)
- Test: `tests/Unit/POS/ReceiptReturnServiceDispositionTest.php`

**Interfaces:**
- Consumes: `$returnLines` element keys now `{line_id, quantity, physical_receipt?, resalable?, disposition?}`.
- Produces: each validated line gains `disposition: ReturnLineDisposition` (default `Restock`), `physical_receipt: ?bool`, `resalable: ?bool`. Validated array shape: `{original_line, quantity, already_returned, disposition, physical_receipt, resalable}`.

- [ ] **Step 1: Write the failing test** — call `processReturn` (mirror the `ReceiptReturnServiceTest` scaffold: factories, manual `new ReceiptReturnService(...)` with the 8 deps, `createReceipt`/`createProductLine`/`createStockLevel`) with NO disposition key, assert the existing restock behavior is unchanged (a `pos_receipt_return` movement of `+qty`); then a second test with `disposition => 'restock'` explicit yields the same. (This pins the default.)

- [ ] **Step 2: Run test to verify it fails** (until the validated array carries disposition; initially it will pass for the default case — write the failing assertion against a NEW behavior: assert `processReturn` reads disposition by having Task 7 branch on it; if isolating Task 6, assert via a small reflection/log that the validated array contains a `disposition` key. Prefer to merge the observable behavior assertion into Task 7 and keep Task 6's test focused on "unknown disposition string → InvalidArgumentException").

- [ ] **Step 3: Extend `validateReturnQuantities`.** Inside the loop, after computing `$requestedQuantity`, parse the per-line disposition with a default and validate:

```php
$dispositionRaw = $returnLine['disposition'] ?? null;
$disposition = $dispositionRaw === null
    ? ReturnLineDisposition::Restock
    : (ReturnLineDisposition::tryFrom((string) $dispositionRaw)
        ?? throw new \InvalidArgumentException("Invalid disposition '{$dispositionRaw}' for line '{$lineId}'"));

$physicalReceipt = array_key_exists('physical_receipt', $returnLine) ? (bool) $returnLine['physical_receipt'] : null;
$resalable = array_key_exists('resalable', $returnLine) ? ($returnLine['resalable'] === null ? null : (bool) $returnLine['resalable']) : null;

// Service-side fail-closed illegal-combo guard (defense in depth vs the request layer).
if ($disposition === ReturnLineDisposition::Restock && ($physicalReceipt === false || $resalable === false)) {
    throw new \InvalidArgumentException("RESTOCK requires the item received and resalable for line '{$lineId}'");
}
if ($disposition === ReturnLineDisposition::NotReceived && $physicalReceipt === true) {
    throw new \InvalidArgumentException("NOT_RECEIVED cannot have physical_receipt=true for line '{$lineId}'");
}
```

Then add the three keys to the `$validated[]` element:

```php
$validated[] = [
    'original_line' => $originalLine,
    'quantity' => $requestedQuantity,
    'already_returned' => $alreadyReturnedQty,
    'disposition' => $disposition,
    'physical_receipt' => $physicalReceipt,
    'resalable' => $resalable,
];
```

Update the method docblock return shape. Import `ReturnLineDisposition`.

- [ ] **Step 4: Run test to verify it passes.**
- [ ] **Step 5: PHPStan + Pint; commit** — `git commit -m "feat(pos): thread per-line disposition through return validation (default RESTOCK)"`.

---

### Task 7: Disposition-branch the stock step (two-movement SCRAP, NOT_RECEIVED no-op)

**Files:**
- Modify: `app/Modules/POS/Application/Services/ReceiptReturnService.php` (the Step-11 loop in `runReturnTransaction`; add `writeOffReturnedStock`)
- Test: `tests/Unit/POS/ReceiptReturnServiceDispositionTest.php` (extend)

**Interfaces:**
- Consumes: validated line `disposition` (Task 6); existing `restoreStock(...)`, `restoreBatchAllocations(...)`.
- Produces: `RESTOCK` → 1 `Receipt/POSReturn` movement + batch restitution (unchanged). `SCRAP` → `Receipt/POSReturn +qty` **then** `Adjustment/WriteOff −qty`; batch restitution **skipped**. `NOT_RECEIVED` → zero movements; both stock paths skipped.

- [ ] **Step 1: Write the failing tests** (disposition matrix; mirror `test_return_restores_variant_scoped_stock_row` scaffold):

```php
public function test_scrap_writes_two_movements_and_skips_batch(): void
{
    $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id]);
    $stock = $this->createStockLevel($product->id, null, '10.0000');
    $sale = $this->createReceipt();
    $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

    $this->service->processReturn(
        originalReceiptId: $sale->id,
        returnLines: [['line_id' => $line->id, 'quantity' => '2.000',
            'physical_receipt' => true, 'resalable' => false, 'disposition' => 'scrap']],
        returnReason: ReturnReason::Defective,
        cashier: $this->cashier,
        terminalId: $this->terminal->id,
    );

    // Net sellable unchanged vs before-return baseline of 10 (receive +2, write-off -2).
    $this->assertSame('10.0000', (string) $stock->refresh()->quantity);
    $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'pos_return')->count());
    $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'write_off')->count());
    $writeOff = StockMovement::where('reason', 'write_off')->firstOrFail();
    $this->assertSame('adjustment', $writeOff->movement_type->value);
}

public function test_not_received_writes_zero_movements(): void
{
    $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id]);
    $stock = $this->createStockLevel($product->id, null, '10.0000');
    $sale = $this->createReceipt();
    $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

    $this->service->processReturn(
        originalReceiptId: $sale->id,
        returnLines: [['line_id' => $line->id, 'quantity' => '1.000',
            'physical_receipt' => false, 'resalable' => null, 'disposition' => 'not_received']],
        returnReason: ReturnReason::CustomerChangedMind,
        cashier: $this->cashier,
        terminalId: $this->terminal->id,
    );

    $this->assertSame('10.0000', (string) $stock->refresh()->quantity);
    $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
}
```

- [ ] **Step 2: Run tests to verify they fail.**

- [ ] **Step 3: Branch the Step-11 loop** (replace the unconditional `restoreStock` + `restoreBatchAllocations` calls):

```php
/** @var ReturnLineDisposition $disposition */
$disposition = $returnLine['disposition'];

if ($disposition === ReturnLineDisposition::NotReceived) {
    continue; // nothing came back — no aggregate, no batch movement
}

// RESTOCK and SCRAP both physically receive the goods back (+qty).
$this->restoreStock(
    tenantId: $terminal->tenant_id,
    companyId: $companyId,
    locationId: $originalReceipt->location_id,
    productId: $originalLine->product_id,
    quantity: $qty,
    returnReceiptId: $draft->id,
    cashierId: $cashier->id,
    variantId: $originalLine->variant_id,
);

if ($disposition === ReturnLineDisposition::Scrap) {
    // Net the received qty back out as a write-off; batch restitution skipped
    // (scrapped goods never re-enter a sellable batch).
    $this->writeOffReturnedStock(
        tenantId: $terminal->tenant_id,
        companyId: $companyId,
        locationId: $originalReceipt->location_id,
        productId: $originalLine->product_id,
        quantity: $qty,
        returnReceiptId: $draft->id,
        cashierId: $cashier->id,
        variantId: $originalLine->variant_id,
    );
} else { // RESTOCK
    $this->restoreBatchAllocations(
        originalLine: $originalLine,
        returnQuantity: $qty,
        alreadyReturnedQuantity: $alreadyReturnedQty,
        locationId: $originalReceipt->location_id,
    );
}
```

- [ ] **Step 4: Add `writeOffReturnedStock`** (mirror `restoreStock` but subtract; `Adjustment/WriteOff`; quantity-only):

```php
private function writeOffReturnedStock(
    string $tenantId, string $companyId, string $locationId, string $productId,
    string $quantity, string $returnReceiptId, string $cashierId, ?string $variantId = null,
): void {
    /** @var StockLevel|null $stockLevel */
    $stockLevel = StockLevel::where('product_id', $productId)
        ->where('location_id', $locationId)
        ->where('company_id', $companyId)
        ->when($variantId !== null,
            fn ($q) => $q->where('variant_id', $variantId),
            fn ($q) => $q->whereNull('variant_id'))
        ->lockForUpdate()->first();

    if ($stockLevel === null) {
        Log::warning('No stock level for scrap write-off during return', ['product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $locationId]);
        return;
    }

    $quantityBefore = (string) $stockLevel->quantity;
    $quantityAfter = bcsub((string) $stockLevel->quantity, $quantity, 4); // scale 4 = canonical quantity storage
    $stockLevel->quantity = $quantityAfter;
    $stockLevel->save();

    StockMovement::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'location_id' => $locationId,
        'movement_type' => MovementType::Adjustment,
        'reason' => MovementReason::WriteOff,
        'quantity' => $quantity,
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
        'reference' => 'POS Return Scrap',
        'reference_type' => 'pos_receipt_return_scrap',
        'reference_id' => $returnReceiptId,
        'notes' => "Scrapped on return (return receipt: {$returnReceiptId})",
        'user_id' => $cashierId,
        'is_historical' => false,
    ]);
}
```

- [ ] **Step 5: Run tests to verify they pass; run the full existing `ReceiptReturnServiceTest` by path to confirm RESTOCK default unchanged.**
- [ ] **Step 6: PHPStan + Pint; commit** — `git commit -m "feat(pos): two-movement SCRAP + NOT_RECEIVED no-op return stock branch"`.

---

### Task 8: Persist disposition + facts on the return-receipt lines

**Files:**
- Modify: `app/Modules/POS/Application/Services/ReceiptReturnService.php` (the return-line creation in `runReturnTransaction`/the line builder that creates the return `ReceiptLine` rows — locate the `ReceiptLine::create(...)` for return lines, downstream of `buildReturnDraft`)
- Test: `tests/Unit/POS/ReceiptReturnServiceDispositionTest.php` (extend)

**Interfaces:**
- Produces: each return `ReceiptLine` row carries `disposition`, `physical_receipt`, `resalable` from its validated line.

- [ ] **Step 1: Write the failing test** — after a SCRAP return, assert the created return `ReceiptLine` has `disposition === ReturnLineDisposition::Scrap`, `physical_receipt === true`, `resalable === false`.
- [ ] **Step 2: Run test to verify it fails.**
- [ ] **Step 3: Set the three fields** on each return-line `ReceiptLine::create([...])` (or model fill) from the matching validated line (`disposition->value`, `physical_receipt`, `resalable`). Locate the return-line creation loop (it pairs 1:1 with `$validatedLines`).
- [ ] **Step 4: Run test to verify it passes.**
- [ ] **Step 5: PHPStan + Pint; commit** — `git commit -m "feat(pos): store disposition + receipt facts on return lines"`.

---

### Task 9: `never ⇒ no RESTOCK` regulated-goods guard

**Files:**
- Modify: `app/Modules/POS/Application/Services/ReceiptReturnService.php` (inject `RestockPolicyResolver`; guard in `validateReturnQuantities`)
- Modify: `tests/Unit/POS/ReceiptReturnServiceTest.php` setup + new disposition test setup (add the 9th constructor arg)
- Test: `tests/Unit/POS/ReceiptReturnServiceDispositionTest.php` (extend)

**Interfaces:**
- Consumes: `RestockPolicyResolver::resolve(string $productId): EffectiveRestockPolicy` (Task 4).
- Produces: `processReturn` throws `\InvalidArgumentException` when a line's resolved `restock_policy === RestockPolicy::Never` and disposition is `RESTOCK`; `SCRAP`/`NOT_RECEIVED` allowed.

- [ ] **Step 1: Write the failing test** — product with `restock_policy = never`; a `disposition=restock` return line throws; `disposition=scrap` succeeds.
- [ ] **Step 2: Run test to verify it fails.**
- [ ] **Step 3: Inject the resolver** — add `private readonly RestockPolicyResolver $restockPolicyResolver` to the constructor (9th arg). **Update every `new ReceiptReturnService(...)` in tests** (`ReceiptReturnServiceTest::setUp`, the disposition test setUp) to pass `$this->app->make(RestockPolicyResolver::class)`.
- [ ] **Step 4: Add the guard** in `validateReturnQuantities` after resolving disposition:

```php
if ($disposition === ReturnLineDisposition::Restock && $originalLine->product_id !== null) {
    $effective = $this->restockPolicyResolver->resolve($originalLine->product_id);
    if ($effective->policy === RestockPolicy::Never) {
        throw new \InvalidArgumentException(
            "Restock not permitted for regulated product on line '{$lineId}' (policy: never)"
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes; re-run `ReceiptReturnServiceTest` by path (constructor change).**
- [ ] **Step 6: PHPStan + Pint; commit** — `git commit -m "feat(pos): never-policy products reject RESTOCK disposition"`.

---

### Task 10: Projection-decrement guard (fast-follow — fiscal §5.1)

**Files:**
- Modify: `app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (`apply` passes the receipt type to `decrementStockForLines`; guard inside it)
- Test: `tests/Feature/Fiscal/PosCoreReceiptProjectionRefundNoDecrementTest.php`

**Interfaces:**
- Consumes: `$receiptTypeEnum` already computed in `apply()` (line ~241) via `resolveReceiptType`.
- Produces: `decrementStockForLines` returns early (no decrement, no movement) when `$receiptTypeEnum === ReceiptType::Return`. (Phase 0 = no-op skip; disposition-gated restock/re-increment is Phase 3.)

- [ ] **Step 1: Write the failing test** — build + apply a `SALE_RECEIPT` fiscal event with `invoice_type_code='REFUND'` and a valid `original_receipt_reference` to an already-projected sale (mirror `PosCoreReceiptProjectionTest` helpers; `app(CompanyContext::class)->clear()` immediately before `apply()`); assert `StockLevel.quantity` is unchanged and zero `pos_sale` movements are written for the refund event.
- [ ] **Step 2: Run test to verify it fails** (it currently decrements).
- [ ] **Step 3: Add the guard.** Change the call site to pass the type and guard at the top of `decrementStockForLines`:

```php
// in apply(): pass the already-resolved type
$this->decrementStockForLines($receiptId, $event, $terminal, $view, $receiptTypeEnum);
```

```php
private function decrementStockForLines(
    string $receiptId, FiscalEvent $event, Terminal $terminal,
    SaleReceiptCanonicalView $view, ReceiptType $receiptType,
): void {
    // Phase 0 fast-follow (return-disposition spec §5.1): a REFUND/VOID-via-
    // SALE_RECEIPT maps to ReceiptType::Return and must NOT decrement stock
    // (it would compound the loss). Disposition-gated restock/re-increment
    // is the Phase 3 deliverable; here we skip.
    if ($receiptType === ReceiptType::Return) {
        return;
    }

    foreach ($view->lineItems as $line) {
        // ... unchanged ...
    }
}
```

- [ ] **Step 4: Run test to verify it passes; re-run `PosCoreReceiptProjectionTest` + variant stock test by path (regression: SALE still decrements).**
- [ ] **Step 5: PHPStan + Pint; commit** — `git commit -m "fix(fiscal): projection must not decrement stock for REFUND/VOID receipts"`.

---

### Task 11: Hardening — mixed dispositions, replay, precision

**Files:**
- Test: `tests/Unit/POS/ReceiptReturnServiceDispositionTest.php` (extend; mostly test-only)

- [ ] **Step 1: Mixed-disposition test** — sell qty 3; first partial return 1×`RESTOCK`, second partial return 2×`SCRAP`; assert cumulative cap stays quantity-only (3rd return of the same line over remaining qty throws) and each return wrote the correct movements.
- [ ] **Step 2: Replay test** — call `processReturn` twice with the same `refund_request_id`; assert stock movements are not double-written (receipt-level idempotency holds for both legs).
- [ ] **Step 3: Precision test** — fractional `SCRAP` qty `1.2345` stays `decimal(4)` (no float drift) across both movements; net sellable returns exactly to baseline.
- [ ] **Step 4: Variant SCRAP test** — variant line scrap writes both movements against the variant-scoped row only (decoy product-level + sibling-variant rows untouched).
- [ ] **Step 5: Run all disposition tests by path; PHPStan + Pint; commit** — `git commit -m "test(pos): mixed-disposition, replay, precision, variant scrap coverage"`.

---

## Self-Review

- **Spec coverage:** §2.1 facts+enum (T1,T2,T5,T6) · §2.2 movement model incl. two-movement SCRAP + batch-skip + NOT_RECEIVED (T7) · §2.3 default RESTOCK (T6) · §3 restock_policy hierarchy + resolver (T1–T4) · §3 `never` guard in Phase 0 (T9) · §5.1 projection decrement guard (T10) · §7 disposition stored unsigned/operational (T8, no hash binding) · §8 test matrix (T7,T9,T11). UI selector (§4) and quarantine/destroy/RTV (§Phase 2), fiscal-event migration (§Phase 3) are out of Phase-0 scope by design.
- **Type consistency:** `ReturnLineDisposition::{Restock,Scrap,NotReceived}`, `RestockPolicy::{Never,IfSealed,DefaultAllow}`, `EffectiveRestockPolicy::$policy/$source/$sourceCategoryId`, `RestockPolicyResolver::resolve(string): EffectiveRestockPolicy`, validated-line keys `{original_line,quantity,already_returned,disposition,physical_receipt,resalable}` — used consistently across T6–T11.
- **Open ratification (does NOT block Phase 0):** standardizing the refund fiscal model on the `invoice_type_code` axis (spec §5) — only affects Phase 3.

## Risks / Notes
- Constructor signature of `ReceiptReturnService` changes in Task 9 — Task 9 explicitly updates all test constructors. Any other instantiation site must be swept (grep `new ReceiptReturnService(`).
- `ExchangeService` calls `processReturn` for its return half — once Task 6 lands, exchange returns default to `RESTOCK` (unchanged behavior). No exchange change in Phase 0.
- Keep `restoreStock` hardcoded to `Receipt/POSReturn` (it is the receive leg for both RESTOCK and SCRAP); only the SCRAP write-off leg and the NOT_RECEIVED skip are new.
