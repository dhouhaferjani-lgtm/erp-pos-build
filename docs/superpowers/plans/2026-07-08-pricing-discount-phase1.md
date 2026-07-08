# Pricing Discount Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Phase 1 Web advisory pricing intelligence, product/category/company discount caps, a DTO-only discount policy service, advisory-by-default document validation, and the guarded read API.

**Architecture:** Product owns all Product/Category/Company model reads through a batch subject provider. Pricing consumes `app/Shared/DTOs/*` plus `app/Shared/Contracts/*`, resolves caps/floors/regulatory warnings with bcmath strings, and exposes a guarded `POST /pricing/discount-policy`. Document requests call one injected validation service from their existing `withValidator()->after()` closures, route-gated to invoice/order store/update only.

**Tech Stack:** Laravel 12, PHP 8.3, Spatie Data + TypeScript transformer, PostgreSQL tenant migrations, bcmath, Spatie permissions, React 19, Vite/Vitest, TanStack Query, react-i18next.

## Global Constraints

- Rev 3 overrides every older spec section: no `margin_floor_buffer_percent`, no `pricing.override_discount_floor`, no POS/device/fiscal snapshot work in Phase 1.
- Floor reuses existing `default_minimum_margin` cascade through `MarginResolver`; below-cost remains governed by `allow_below_cost_sales` and `pricing.sell_below_cost`.
- Phase-1 B2B document enforcement defaults to `companies.discount_floor_mode = Advisory`; `Block` and `WarnRequiresPermission` are opt-in.
- Phase-1 verdict cap is product -> category chain -> company only; no user, terminal, or POS cap participation.
- `sale_price` is canonical HT/net. TTC is derived only for display or TTC entry mode.
- `companies.price_entry_mode` and `country_pricing_regulations` are in Phase 1 per `CODEX-TASK-pricing-discount-phase1.md` Scope and spec §3.1/§3.2. TN pharma is seed-only with `active=false`; no TN pharma enforcement/rule provider ships in Phase 1.
- New percent fields use `numeric|min:0|max:100|regex:/^\d+(\.\d{1,2})?$/`; percents are fixed scale 2.
- Money math uses bcmath only. Boundary formatting is `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale($currency))`; pass explicit currency.
- Constructor injection only with `private readonly`; do not introduce `app()` in new code.
- Pricing code must not import Product, Category, or Company models. It consumes Shared contracts and DTOs only.
- Frontend user-facing text uses `t()`. Tenant data query keys use `tenantScopedKey([...])`. `apiPost`/`apiGet` are already unwrapped.
- Run tests by path only. Never run the full PHPUnit or Vitest suite on this machine.
- After every wave, run targeted checks, save an Opus adversarial review under `docs/superpowers/*/reviews/`, reconcile every BLOCKER/MAJOR, then commit with `Co-Authored-By: Codex <noreply@openai.com>`.

---

## Planned File Structure

**Backend migrations and enums**
- Create `apps/api/database/migrations/tenant/2026_07_08_130000_add_discount_policy_columns.php`: add `products.max_discount_percent`, `categories.max_discount_percent`, `companies.default_max_discount_percent`, `companies.discount_floor_mode`, `companies.price_entry_mode`, plus database range checks.
- Create `apps/api/database/migrations/tenant/2026_07_08_131000_create_country_pricing_regulations_table.php`: tenant table with unique `(country_code, rule_type)`.
- Create `apps/api/app/Modules/Company/Domain/Enums/DiscountFloorMode.php`.
- Create `apps/api/app/Modules/Company/Domain/Enums/PriceEntryMode.php` for company HT/TTC entry preference.
- Create `apps/api/app/Modules/Pricing/Domain/Enums/RegulatoryRuleType.php`.
- Create `apps/api/app/Modules/Pricing/Domain/Enums/RegulatoryEnforcement.php`.
- Create `apps/api/app/Modules/Pricing/Domain/Enums/PriceBasis.php`.
- Create `apps/api/app/Modules/Pricing/Domain/Enums/FloorBasis.php`.
- Modify `apps/api/app/Modules/Product/Domain/Product.php`, `apps/api/app/Modules/Product/Domain/Category.php`, `apps/api/app/Modules/Company/Domain/Company.php`.
- Modify factories for `Product`, `Company`, and `Category` so default states include nullable discount-policy fields that match migration defaults.

**Backend DTOs and contracts**
- Create `apps/api/app/Shared/DTOs/DiscountPolicyContext.php`.
- Create `apps/api/app/Shared/DTOs/DiscountPolicySubject.php`.
- Create `apps/api/app/Shared/DTOs/DiscountPolicyVerdict.php`.
- Create `apps/api/app/Shared/DTOs/DiscountPolicyLineContext.php`.
- Create `apps/api/app/Shared/Contracts/DiscountPolicyInterface.php`.
- Create `apps/api/app/Shared/Contracts/DiscountPolicySubjectProviderInterface.php`.

**Product module provider**
- Create `apps/api/app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php`.
- Modify `apps/api/app/Modules/Product/ProductServiceProvider.php` to bind `DiscountPolicySubjectProviderInterface`.

**Pricing module**
- Create `apps/api/app/Modules/Pricing/Domain/CountryPricingRegulation.php`.
- Create `apps/api/app/Modules/Pricing/Domain/Services/DiscountCapResolver.php`.
- Create `apps/api/app/Modules/Pricing/Domain/Services/DiscountPolicyService.php`.
- Create `apps/api/app/Modules/Pricing/Presentation/Controllers/DiscountPolicyController.php`.
- Modify `apps/api/app/Modules/Pricing/Providers/PricingServiceProvider.php` for bindings.
- Modify `apps/api/app/Modules/Pricing/Presentation/routes.php` to add guarded `POST /pricing/discount-policy`.
- Create `apps/api/database/seeders/CountryPricingRegulationSeeder.php`.
- Modify `apps/api/database/seeders/RolesAndPermissionsSeeder.php` and `PermissionSeeder.php` only for missing role mappings, not new floor permissions.
- Add existing margin/cost permissions to both permission catalogs when missing: `pricing.view_cost_prices`, `pricing.sell_below_minimum_margin`, `pricing.sell_below_cost`. Do not add `pricing.override_discount_floor`.

**Product/category/company write surfaces**
- Modify `CreateProductRequest`, `UpdateProductRequest`, `ProductData`, `ProductController`.
- Modify `CategoryController`, `CategoryData`.
- Modify `UpdateCompanyRequest`, `CompanyController::formatCompany`.

**Document validation**
- Create `apps/api/app/Modules/Document/Presentation/Validation/DiscountPolicyDocumentValidator.php`.
- Modify `CreateDocumentRequest` and `UpdateDocumentRequest` constructors and `withValidator()` closures.

**Frontend**
- Create `apps/web/src/features/inventory/components/pricing/PricingIntelligencePanel.tsx`.
- Create `apps/web/src/features/inventory/components/pricing/pricingMath.ts`.
- Create `apps/web/src/features/inventory/components/pricing/PricingModeCalculator.tsx`.
- Create tests beside these files.
- Modify `ProductForm.tsx`, `ProductDetailPage.tsx`, `productPayload.ts`, `types.ts`, `usePermissions.ts`.
- Modify locale files `apps/web/src/locales/en/inventory.json`, `fr/inventory.json`, `ar/inventory.json` and related `products.json` or `pricing.json` only where keys already belong.

**Review and handoff artifacts**
- Create `docs/superpowers/plans/reviews/2026-07-08-pricing-discount-phase1-plan-opus-review.md`.
- Create per-wave review files under `docs/superpowers/reviews/pricing-discount-phase1/`.
- Create final `docs/sessions/HANDOFF-pricing-discount-phase1.md`.

---

### Wave 1: Schema, Enums, Seeds, and Write-Surface Validation

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_08_130000_add_discount_policy_columns.php`
- Create: `apps/api/database/migrations/tenant/2026_07_08_131000_create_country_pricing_regulations_table.php`
- Create: `apps/api/app/Modules/Company/Domain/Enums/DiscountFloorMode.php`
- Create: `apps/api/app/Modules/Company/Domain/Enums/PriceEntryMode.php`
- Create: `apps/api/app/Modules/Pricing/Domain/Enums/RegulatoryRuleType.php`
- Create: `apps/api/app/Modules/Pricing/Domain/Enums/RegulatoryEnforcement.php`
- Create: `apps/api/app/Modules/Pricing/Domain/CountryPricingRegulation.php`
- Create: `apps/api/database/seeders/CountryPricingRegulationSeeder.php`
- Modify: `apps/api/app/Modules/Product/Domain/Product.php`
- Modify: `apps/api/app/Modules/Product/Domain/Category.php`
- Modify: `apps/api/app/Modules/Company/Domain/Company.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php`
- Modify: `apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php`
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `apps/api/database/seeders/PermissionSeeder.php`
- Test: `apps/api/tests/Feature/Pricing/DiscountPolicySchemaTest.php`
- Test: `apps/api/tests/Feature/Pricing/PricingPermissionSeederTest.php`
- Test: `apps/api/tests/Feature/Product/ProductDiscountCapValidationTest.php`
- Test: `apps/api/tests/Feature/Product/CategoryDiscountCapValidationTest.php`
- Test: `apps/api/tests/Feature/Company/CompanyDiscountPolicySettingsTest.php`

**Interfaces:**
- Produces DB columns and enum casts used by all later waves.
- Produces seeded manager permissions: `pricing.view_cost_prices`, `pricing.sell_below_minimum_margin`, `pricing.sell_below_cost`.
- Produces country pricing regulation rows for `FR` and `TN`.

- [ ] **Step 1: Write failing schema and seeder tests**

Add `DiscountPolicySchemaTest` asserting columns/defaults and seeded regulatory rows:

```php
public function test_discount_policy_columns_exist_with_rev3_defaults(): void
{
    $company = Company::factory()->create();
    $product = Product::factory()->for($company)->create();
    $category = Category::factory()->for($company)->create();

    self::assertNull($product->max_discount_percent);
    self::assertNull($category->max_discount_percent);
    self::assertNull($company->default_max_discount_percent);
    self::assertSame(DiscountFloorMode::Advisory, $company->discount_floor_mode);
    self::assertSame(PriceEntryMode::Ht, $company->price_entry_mode);
    self::assertFalse(Schema::hasColumn('companies', 'margin_floor_buffer_percent'));
}

public function test_country_pricing_regulations_seed_advisory_rules(): void
{
    $this->seed(CountryPricingRegulationSeeder::class);

    $this->assertDatabaseHas('country_pricing_regulations', [
        'country_code' => 'FR',
        'rule_type' => RegulatoryRuleType::BelowCostFloor->value,
        'enforcement' => RegulatoryEnforcement::Advisory->value,
        'active' => true,
    ]);
    $this->assertDatabaseHas('country_pricing_regulations', [
        'country_code' => 'TN',
        'rule_type' => RegulatoryRuleType::PharmaMarginSchedule->value,
        'active' => false,
    ]);
}
```

Extend `PricingPermissionSeederTest`:

```php
public function test_manager_gets_cost_and_floor_permissions_without_new_override_permission(): void
{
    $tenant = $this->makeTenant('manager-cost');
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $this->seed(RolesAndPermissionsSeeder::class);

    $manager = Role::where('name', 'manager')->firstOrFail();
    self::assertTrue($manager->hasPermissionTo('pricing.view_cost_prices', 'sanctum'));
    self::assertTrue($manager->hasPermissionTo('pricing.sell_below_minimum_margin', 'sanctum'));
    self::assertTrue($manager->hasPermissionTo('pricing.sell_below_cost', 'sanctum'));
    self::assertNull(Permission::where('name', 'pricing.override_discount_floor')->first());
}

public function test_legacy_permission_catalog_also_registers_existing_margin_permissions(): void
{
    $tenant = $this->makeTenant('legacy-catalog');
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $this->seed(PermissionSeeder::class);

    self::assertNotNull(Permission::where('name', 'pricing.view_cost_prices')->first());
    self::assertNotNull(Permission::where('name', 'pricing.sell_below_minimum_margin')->first());
    self::assertNotNull(Permission::where('name', 'pricing.sell_below_cost')->first());
    self::assertNull(Permission::where('name', 'pricing.override_discount_floor')->first());
}

public function test_permission_cache_reset_makes_manager_cost_permission_visible(): void
{
    $tenant = $this->makeTenant('cache-reset');
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $this->seed(RolesAndPermissionsSeeder::class);

    $manager = Role::where('name', 'manager')->firstOrFail();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    self::assertTrue($manager->fresh()->hasPermissionTo('pricing.view_cost_prices', 'sanctum'));
}
```

Add Product/Category/Company validation tests:

```php
public function test_product_max_discount_percent_rejects_more_than_two_decimals(): void
{
    $response = $this->actingAs($this->manager, 'sanctum')
        ->postJson('/api/v1/products', $this->payload(['max_discount_percent' => '12.345']));

    $this->assertApiValidationErrors($response, ['max_discount_percent']);
}

public function test_category_max_discount_percent_persists_as_string(): void
{
    $response = $this->actingAs($this->manager, 'sanctum')
        ->postJson('/api/v1/categories', ['name' => 'Fluids', 'max_discount_percent' => '7.50']);

    $response->assertCreated()->assertJsonPath('data.max_discount_percent', '7.50');
}

public function test_company_discount_policy_settings_persist(): void
{
    $response = $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/companies/{$this->company->id}", [
            'default_max_discount_percent' => '12.50',
            'discount_floor_mode' => DiscountFloorMode::WarnRequiresPermission->value,
            'price_entry_mode' => PriceEntryMode::Ttc->value,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.default_max_discount_percent', '12.50')
        ->assertJsonPath('data.discount_floor_mode', 'WarnRequiresPermission')
        ->assertJsonPath('data.price_entry_mode', 'Ttc');
}
```

- [ ] **Step 2: Run red tests**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Pricing/DiscountPolicySchemaTest.php tests/Feature/Pricing/PricingPermissionSeederTest.php tests/Feature/Product/ProductDiscountCapValidationTest.php tests/Feature/Product/CategoryDiscountCapValidationTest.php tests/Feature/Company/CompanyDiscountPolicySettingsTest.php
```

Expected: FAIL because the new columns, enums, seeder rows, request rules, and role grants do not exist yet.

- [ ] **Step 3: Implement minimal schema and validation**

Implement migration columns with checks:

```php
$table->decimal('max_discount_percent', 5, 2)->nullable()->after('minimum_margin_override');
DB::statement('ALTER TABLE products ADD CONSTRAINT products_max_discount_percent_range CHECK (max_discount_percent IS NULL OR max_discount_percent BETWEEN 0 AND 100)');
```

Use analogous category/company constraints. Create enums with exact Rev 3 values:

```php
enum DiscountFloorMode: string
{
    case Advisory = 'Advisory';
    case Block = 'Block';
    case WarnRequiresPermission = 'WarnRequiresPermission';
}
```

Add fillable/casts:

```php
'max_discount_percent',
// casts
'max_discount_percent' => 'decimal:2',
```

Add request rules:

```php
'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
```

Verify both permission catalogs and grant manager permissions in `RolesAndPermissionsSeeder`:

```php
'pricing.view_cost_prices',
'pricing.sell_below_minimum_margin',
'pricing.sell_below_cost',
```

If any of the three existing permission strings is absent from `PermissionSeeder` or `RolesAndPermissionsSeeder::createPermissions()`, add it there. Do not add `pricing.override_discount_floor`.

- [ ] **Step 4: Run green tests**

Run the same command from Step 2.

Expected: PASS.

- [ ] **Step 5: Run wave static checks**

Run:

```bash
cd apps/api
./vendor/bin/pint --test app/Modules/Company app/Modules/Product app/Modules/Pricing database/seeders tests/Feature/Pricing tests/Feature/Product tests/Feature/Company
./vendor/bin/phpstan analyse --level=8 app/Modules/Company/Domain/Enums app/Modules/Pricing app/Modules/Product/Domain app/Modules/Product/Presentation app/Modules/Company/Presentation --memory-limit=2G
```

Expected: PASS.

- [ ] **Step 6: Opus review and reconcile**

Run:

```bash
claude -p --model claude-opus-4-8 "You are an adversarial reviewer. Review Wave 1 of Product Pricing Panel + Discount Policy Cascade Phase 1. Verify Rev 3 compliance: no margin_floor_buffer_percent, no pricing.override_discount_floor, Advisory default, manager role grants, percent validation, enum casts, country pricing regulations. Cite file:line, verify against code, never hallucinate. Gate: do not approve unless clean." > docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave1-opus-review.md
```

Reconcile every BLOCKER/MAJOR with tests before continuing.

- [ ] **Step 7: Commit Wave 1**

Run:

```bash
git add apps/api docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave1-opus-review.md
git commit -m "Phase 1.0.1: Add discount policy schema

Co-Authored-By: Codex <noreply@openai.com>"
```

---

### Wave 2: Product-Module Subject Provider and Shared Contracts

**Files:**
- Create: `apps/api/app/Shared/DTOs/DiscountPolicySubject.php`
- Create: `apps/api/app/Shared/DTOs/DiscountPolicyContext.php`
- Create: `apps/api/app/Shared/DTOs/DiscountPolicyLineContext.php`
- Create: `apps/api/app/Shared/DTOs/DiscountPolicyVerdict.php`
- Create: `apps/api/app/Shared/Contracts/DiscountPolicyInterface.php`
- Create: `apps/api/app/Shared/Contracts/DiscountPolicySubjectProviderInterface.php`
- Create: `apps/api/app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php`
- Modify: `apps/api/app/Modules/Product/ProductServiceProvider.php`
- Modify: `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`
- Test: `apps/api/tests/Feature/Product/DiscountPolicySubjectProviderTest.php`
- Test: `apps/api/tests/Unit/Shared/DiscountPolicyDtoTest.php`

**Interfaces:**
- `DiscountPolicySubjectProviderInterface::resolveMany(string $companyId, array $contexts): array`
- `DiscountPolicySubjectProviderInterface::resolve(string $companyId, string $productId, ?string $variantId = null): DiscountPolicySubject` is a convenience wrapper over `resolveMany()` used only in tests and single-product endpoint code.
- Context entries are `DiscountPolicyLineContext` keyed by request line key; return is `array<string, DiscountPolicySubject>`.
- `DiscountPolicySubject` contains product/category/company caps, WAC, last purchase cost, resolved minimum margin, tax config/rate, resolved effective tax rate, company mode, currency, `policyAsOf`, and `policyVersion` as a short hash of those inputs.
- `DiscountPolicyInterface::resolve(DiscountPolicyContext $context): DiscountPolicyVerdict`.
- `DiscountPolicyInterface::resolveMany(array $contexts): array` where keys are caller line keys and values are `DiscountPolicyContext`; the returned array uses the same keys and `DiscountPolicyVerdict` values.

- [ ] **Step 1: Write failing provider tests**

```php
public function test_resolve_many_returns_nearest_cap_and_minimum_margin_without_n_plus_one(): void
{
    DB::enableQueryLog();

    $root = Category::factory()->for($this->company)->create(['max_discount_percent' => '15.00']);
    $child = Category::factory()->for($this->company)->create([
        'parent_id' => $root->id,
        'max_discount_percent' => '10.00',
    ]);
    $product = Product::factory()->for($this->company)->create([
        'category_id' => $child->id,
        'max_discount_percent' => null,
        'cost_price' => '100.123456',
        'last_purchase_cost' => '98.000000',
        'minimum_margin_override' => '12.00',
    ]);

    $subjects = $this->provider->resolveMany($this->company->id, [
        'line-0' => new DiscountPolicyLineContext(productId: $product->id, variantId: null),
    ]);

    self::assertSame('10.00', $subjects['line-0']->effectiveMaxDiscountPercent());
    self::assertSame('12.00', $subjects['line-0']->minimumMarginPercent);
    self::assertSame('100.123456', $subjects['line-0']->wacNet);
    self::assertNotSame('', $subjects['line-0']->policyVersion);
    self::assertLessThanOrEqual(6, count(DB::getQueryLog()));
}
```

```php
public function test_subject_uses_product_cap_before_category_and_company(): void
{
    $this->company->update(['default_max_discount_percent' => '20.00']);
    $category = Category::factory()->for($this->company)->create(['max_discount_percent' => '10.00']);
    $product = Product::factory()->for($this->company)->create([
        'category_id' => $category->id,
        'max_discount_percent' => '5.00',
    ]);

    $subject = $this->provider->resolve($this->company->id, $product->id);

    self::assertSame('5.00', $subject->effectiveMaxDiscountPercent());
}
```

- [ ] **Step 2: Run red tests**

```bash
cd apps/api
php artisan test tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php
```

Expected: FAIL because DTOs and provider do not exist.

- [ ] **Step 3: Implement DTOs, contract, provider, and binding**

Implement DTO methods without model imports in Shared:

```php
#[TypeScript]
final class DiscountPolicySubject extends Data
{
    public function __construct(
        public string $companyId,
        public string $productId,
        public ?string $variantId,
        public ?string $productMaxDiscountPercent,
        /** @var array<int, string> */
        public array $categoryMaxDiscountPercents,
        public ?string $companyMaxDiscountPercent,
        public ?string $wacNet,
        public ?string $lastPurchaseCost,
        public ?string $minimumMarginPercent,
        public string $currency,
        public ?string $taxConfigurationId,
        public ?string $taxRate,
        public string $resolvedTaxRate,
        public string $discountFloorMode,
        public string $priceEntryMode,
        public string $policyAsOf,
        public string $policyVersion,
    ) {}
}
```

Provider lives in Product module and may use Product/Category/Company plus `MarginResolver::resolveMany()`:

```php
final class DiscountPolicySubjectProvider implements DiscountPolicySubjectProviderInterface
{
    public function __construct(private readonly MarginResolver $marginResolver) {}

    public function resolveMany(string $companyId, array $contexts): array
    {
        $productIds = collect($contexts)->pluck('productId')->filter()->unique()->values()->all();
        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->with(['company', 'category'])
            ->get()
            ->keyBy('id');
        $margins = $this->marginResolver->resolveMany($products->values());
        // Map each requested context to DiscountPolicySubject.
    }

    public function resolve(string $companyId, string $productId, ?string $variantId = null): DiscountPolicySubject
    {
        return $this->resolveMany($companyId, [
            'single' => new DiscountPolicyLineContext($productId, $variantId),
        ])['single'];
    }
}
```

When `default_tax_configuration_id` is present, resolve its `percentage_rate` in the Product module and set `resolvedTaxRate` from that row. Fall back to `Product.tax_rate`, then category/company defaults already used by `TaxResolutionService`.

- [ ] **Step 4: Generate types**

```bash
cd apps/api
CACHE_STORE=array php artisan typescript:transform
```

Expected: `packages/shared/types/generated.d.ts` includes `App.Shared.DTOs.DiscountPolicySubject`, `DiscountPolicyContext`, and `DiscountPolicyVerdict`.

- [ ] **Step 5: Run green tests**

Run the Step 2 command.

Expected: PASS.

- [ ] **Step 6: Run wave static checks**

```bash
cd apps/api
./vendor/bin/pint --test app/Shared app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php app/Modules/Product/ProductServiceProvider.php tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php
./vendor/bin/phpstan analyse --level=8 app/Shared app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php --memory-limit=2G
```

Expected: PASS.

- [ ] **Step 7: Opus review and reconcile**

```bash
claude -p --model claude-opus-4-8 "You are an adversarial reviewer. Review Wave 2 of Product Pricing Panel + Discount Policy Cascade Phase 1. Verify module boundaries: Product provider may read Product/Category/Company, Pricing has not imported those models, provider is batch resolveMany-style, DTOs are string-safe and TypeScript-tagged, floor reuses MarginResolver. Cite file:line, verify against code, never hallucinate. Gate: do not approve unless clean." > docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave2-opus-review.md
```

Reconcile every BLOCKER/MAJOR before continuing.

- [ ] **Step 8: Commit Wave 2**

```bash
git add apps/api packages/shared/types/generated.d.ts docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave2-opus-review.md
git commit -m "Phase 1.0.2: Add discount policy subject boundary

Co-Authored-By: Codex <noreply@openai.com>"
```

---

### Wave 3: DTO-Only Pricing Policy Service and Guarded Read API

**Files:**
- Create: `apps/api/app/Modules/Pricing/Domain/Enums/PriceBasis.php`
- Create: `apps/api/app/Modules/Pricing/Domain/Enums/FloorBasis.php`
- Create: `apps/api/app/Modules/Pricing/Domain/Services/DiscountCapResolver.php`
- Create: `apps/api/app/Modules/Pricing/Domain/Services/DiscountPolicyService.php`
- Create: `apps/api/app/Modules/Pricing/Presentation/Controllers/DiscountPolicyController.php`
- Modify: `apps/api/app/Modules/Pricing/Providers/PricingServiceProvider.php`
- Modify: `apps/api/app/Modules/Pricing/Presentation/routes.php`
- Test: `apps/api/tests/Unit/Pricing/DiscountCapResolverTest.php`
- Test: `apps/api/tests/Unit/Pricing/DiscountPolicyServiceTest.php`
- Test: `apps/api/tests/Feature/Pricing/DiscountPolicyEndpointTest.php`

**Interfaces:**
- `DiscountCapResolver::resolve(DiscountPolicySubject $subject): string`.
- `DiscountPolicyService::resolve(DiscountPolicyContext $context): DiscountPolicyVerdict`.
- `DiscountPolicyService::resolveMany(array $contexts): array` accepts `array<string, DiscountPolicyContext>` and returns `array<string, DiscountPolicyVerdict>` while calling the subject provider once.
- Endpoint returns `{data: DiscountPolicyVerdict, meta: ...}` and is guarded by `can:pricing.view_cost_prices`.

- [ ] **Step 1: Write failing resolver/service/endpoint tests**

```php
public function test_cap_resolver_uses_product_category_company_nearest_wins(): void
{
    $resolver = new DiscountCapResolver();

    self::assertSame('5.00', $resolver->resolve($this->subject(product: '5.00', categories: ['10.00'], company: '20.00')));
    self::assertSame('10.00', $resolver->resolve($this->subject(product: null, categories: ['10.00', '15.00'], company: '20.00')));
    self::assertSame('20.00', $resolver->resolve($this->subject(product: null, categories: [], company: '20.00')));
    self::assertSame('100.00', $resolver->resolve($this->subject(product: null, categories: [], company: null)));
}
```

```php
public function test_discount_policy_service_resolves_minimum_margin_floor_with_explicit_currency_scale(): void
{
    $context = new DiscountPolicyContext(
        productId: 'product-1',
        variantId: null,
        effectiveUnitPrice: '109.990',
        priceBasis: PriceBasis::NetExclTax->value,
        currency: 'TND',
        quantity: '1.0000',
        companyId: 'company-1',
        taxConfigurationId: null,
        taxRate: '19.00',
    );

    $verdict = $this->serviceWithSubject($this->subject(wac: '100.000000', minimumMargin: '10.00'))->resolve($context);

    self::assertSame('110.000', $verdict->floorPriceNet);
    self::assertSame(FloorBasis::MinimumMargin->value, $verdict->floorBasis);
    self::assertFalse($verdict->blocksSale);
}

public function test_discount_policy_floor_matches_existing_margin_service_threshold(): void
{
    $product = Product::factory()->for($this->company)->create([
        'cost_price' => '100.000000',
        'minimum_margin_override' => '10.00',
    ]);

    $atFloor = $this->policy->resolve($this->contextFor($product, '110.000'));
    $belowFloor = $this->policy->resolve($this->contextFor($product, '109.990'));

    self::assertSame('110.000', $atFloor->floorPriceNet);
    self::assertTrue($atFloor->allowed);
    self::assertSame('pricing.sell_below_minimum_margin', $belowFloor->requiresPermission);
    self::assertSame('orange', $this->marginService->getMarginLevel($product, '109.990')['level']);
}

public function test_discount_policy_skips_floor_when_cost_is_missing_or_zero(): void
{
    $verdict = $this->serviceWithSubject($this->subject(wac: '0.000000', minimumMargin: '10.00'))->resolve(
        $this->context(price: '1.000', currency: 'TND')
    );

    self::assertNull($verdict->floorPriceNet);
    self::assertSame(FloorBasis::None->value, $verdict->floorBasis);
    self::assertTrue($verdict->allowed);
}

public function test_resolve_many_batches_subject_provider_calls(): void
{
    $provider = new SpySubjectProvider();
    $service = $this->serviceWithProvider($provider);

    $service->resolveMany([
        'line-0' => $this->context(productId: 'product-1'),
        'line-1' => $this->context(productId: 'product-2'),
    ]);

    self::assertSame(1, $provider->resolveManyCalls);
    self::assertSame(['line-0', 'line-1'], array_keys($provider->lastContexts));
}
```

```php
public function test_discount_policy_endpoint_requires_cost_permission(): void
{
    $response = $this->actingAs($this->cashier, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/pricing/discount-policy', [
            'product_id' => $this->product->id,
            'effective_unit_price' => '100.00',
            'price_basis' => PriceBasis::NetExclTax->value,
            'currency' => 'EUR',
            'quantity' => '1.0000',
        ]);

    $response->assertForbidden();
}
```

- [ ] **Step 2: Run red tests**

```bash
cd apps/api
php artisan test tests/Unit/Pricing/DiscountCapResolverTest.php tests/Unit/Pricing/DiscountPolicyServiceTest.php tests/Feature/Pricing/DiscountPolicyEndpointTest.php
```

Expected: FAIL because Pricing service, controller, route, and bindings do not exist.

- [ ] **Step 3: Implement Pricing service**

Service must avoid Product/Category/Company imports. It should consume `DiscountPolicySubjectProviderInterface`, `CurrencyScaleResolverInterface`, and `DiscountCapResolver`. Do not introduce a Phase-1 regulatory rule engine; the regulatory table is seed/readiness data only in this phase.

```php
final class DiscountPolicyService implements DiscountPolicyInterface
{
    public function __construct(
        private readonly DiscountPolicySubjectProviderInterface $subjects,
        private readonly DiscountCapResolver $capResolver,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function resolve(DiscountPolicyContext $context): DiscountPolicyVerdict
    {
        $subjects = $this->subjects->resolveMany($context->companyId, [
            'line' => new DiscountPolicyLineContext($context->productId, $context->variantId),
        ]);

        return $this->resolveForSubject($context, $subjects['line']);
    }

    public function resolveMany(array $contexts): array
    {
        $companyId = $this->singleCompanyId($contexts);
        $lineContexts = [];
        foreach ($contexts as $key => $context) {
            $lineContexts[$key] = new DiscountPolicyLineContext($context->productId, $context->variantId);
        }

        $subjects = $this->subjects->resolveMany($companyId, $lineContexts);

        $verdicts = [];
        foreach ($contexts as $key => $context) {
            $verdicts[$key] = $this->resolveForSubject($context, $subjects[$key]);
        }

        return $verdicts;
    }
}
```

Floor math:

```php
$scale = $this->scaleResolver->getScale($context->currency);
$intermediate = $scale + 1;
if ($subject->wacNet === null || bccomp($subject->wacNet, '0', $intermediate) <= 0) {
    return null;
}
$factor = bcadd('1', bcdiv($subject->minimumMarginPercent, '100', $intermediate), $intermediate);
$floor = CurrencyScale::bcformatStrict(bcmul($subject->wacNet, $factor, $intermediate), $scale);
```

This formula is deliberate parity with the existing `MarginService::calculateMargin()` definition: `((sell - cost) / cost) * 100`. The Wave 3 parity test must fail if future `MarginService` semantics change.

Gross-to-net conversion:

```php
private function netUnitPrice(DiscountPolicyContext $context, DiscountPolicySubject $subject, int $scale): string
{
    if ($context->priceBasis === PriceBasis::NetExclTax->value) {
        return CurrencyScale::bcformatStrict($context->effectiveUnitPrice, $scale);
    }

    $rate = $context->taxRate ?? $subject->resolvedTaxRate;
    $divisor = bcadd('1', bcdiv($rate, '100', $scale + 4), $scale + 4);
    return CurrencyScale::bcformatStrict(bcdiv($context->effectiveUnitPrice, $divisor, $scale + 4), $scale);
}
```

- [ ] **Step 4: Implement endpoint and bindings**

Add route inside existing Pricing group:

```php
Route::post('/pricing/discount-policy', [DiscountPolicyController::class, 'resolve'])
    ->middleware('can:pricing.view_cost_prices')
    ->name('pricing.discount-policy');
```

Controller validates strings:

```php
$validated = $request->validate([
    'product_id' => ['required', 'uuid'],
    'variant_id' => ['nullable', 'uuid'],
    'effective_unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
    'price_basis' => ['required', Rule::enum(PriceBasis::class)],
    'currency' => ['required', 'string', 'size:3'],
    'quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
    'tax_configuration_id' => ['nullable', 'uuid'],
    'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
]);
```

- [ ] **Step 5: Run green tests**

Run the Step 2 command.

Expected: PASS.

- [ ] **Step 6: Run PHPStan boundary grep**

```bash
cd apps/api
./vendor/bin/phpstan analyse --level=8 app/Modules/Pricing app/Shared --memory-limit=2G
rg -n "Modules\\\\Product\\\\Domain|Modules\\\\Company\\\\Domain\\\\Company|Modules\\\\Product\\\\Domain\\\\Category" app/Modules/Pricing app/Shared
```

Expected: PHPStan PASS; `rg` returns no Pricing/Shared model imports except allowed DTO/type names.

- [ ] **Step 7: Opus review and reconcile**

```bash
claude -p --model claude-opus-4-8 "You are an adversarial reviewer. Review Wave 3 of Product Pricing Panel + Discount Policy Cascade Phase 1. Verify guarded Pricing route stack, can:pricing.view_cost_prices, DTO-only Pricing module, bcmath string math, explicit currency scale, gross-to-net conversion, no POS endpoint changes, no new override permission. Cite file:line, verify against code, never hallucinate. Gate: do not approve unless clean." > docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave3-opus-review.md
```

Reconcile every BLOCKER/MAJOR before continuing.

- [ ] **Step 8: Commit Wave 3**

```bash
git add apps/api packages/shared/types/generated.d.ts docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave3-opus-review.md
git commit -m "Phase 1.0.3: Add guarded discount policy service

Co-Authored-By: Codex <noreply@openai.com>"
```

---

### Wave 4: Document Advisory/Warn/Block Validation

**Files:**
- Create: `apps/api/app/Modules/Document/Presentation/Validation/DiscountPolicyDocumentValidator.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/SalesOrderController.php`
- Test: `apps/api/tests/Feature/Document/DiscountPolicyDocumentValidationTest.php`

**Interfaces:**
- `DiscountPolicyDocumentValidator::validate(FormRequest $request, Validator $validator): void`.
- Uses route names `invoices.store`, `invoices.update`, `orders.store`, `orders.update`.
- Resolves all product lines in one batch via `DiscountPolicyInterface::resolveMany`.

- [ ] **Step 1: Write failing document validation tests**

```php
public function test_advisory_mode_warns_but_does_not_reject_below_floor_invoice(): void
{
    $this->company->update(['discount_floor_mode' => DiscountFloorMode::Advisory]);
    $product = $this->product(['cost_price' => '100.000000', 'sale_price' => '105.000']);

    $response = $this->actingAs($this->cashier, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/invoices', $this->payload($product, unitPrice: '105.000'));

    $response->assertCreated()
        ->assertJsonPath('meta.discount_policy_warnings.0.line', 0)
        ->assertJsonPath('meta.discount_policy_warnings.0.requires_permission', 'pricing.sell_below_minimum_margin');
}
```

```php
public function test_block_mode_rejects_user_without_sell_below_minimum_margin(): void
{
    $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);
    $product = $this->product(['cost_price' => '100.000000', 'sale_price' => '105.000']);

    $response = $this->actingAs($this->cashier, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/invoices', $this->payload($product, unitPrice: '105.000'));

    $this->assertApiValidationErrors($response, ['lines.0.unit_price']);
}
```

```php
public function test_block_mode_allows_user_with_existing_minimum_margin_permission(): void
{
    $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);
    $this->manager->givePermissionTo('pricing.sell_below_minimum_margin');
    $product = $this->product(['cost_price' => '100.000000', 'sale_price' => '105.000']);

    $response = $this->actingAs($this->manager, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/invoices', $this->payload($product, unitPrice: '105.000'));

    $response->assertCreated();
}
```

```php
public function test_warn_requires_permission_rejects_user_without_existing_permission(): void
{
    $this->company->update(['discount_floor_mode' => DiscountFloorMode::WarnRequiresPermission]);
    $product = $this->product(['cost_price' => '100.000000', 'sale_price' => '105.000']);

    $response = $this->actingAs($this->cashier, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/orders', $this->payload($product, unitPrice: '105.000'));

    $this->assertApiValidationErrors($response, ['lines.0.unit_price']);
}
```

```php
public function test_warn_requires_permission_allows_holder_and_returns_warning_meta(): void
{
    $this->company->update(['discount_floor_mode' => DiscountFloorMode::WarnRequiresPermission]);
    $this->manager->givePermissionTo('pricing.sell_below_minimum_margin');
    $product = $this->product(['cost_price' => '100.000000', 'sale_price' => '105.000']);

    $response = $this->actingAs($this->manager, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/orders', $this->payload($product, unitPrice: '105.000'));

    $response->assertCreated()
        ->assertJsonPath('meta.discount_policy_warnings.0.requires_permission', 'pricing.sell_below_minimum_margin');
}
```

```php
public function test_quote_route_skips_discount_policy_validation(): void
{
    $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);

    $response = $this->actingAs($this->cashier, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/quotes', $this->payload($this->product(), unitPrice: '1.000'));

    $response->assertCreated();
}
```

```php
public function test_document_validator_batches_product_lines(): void
{
    $spy = new SpyDiscountPolicyService();
    $this->app->instance(DiscountPolicyInterface::class, $spy);

    $this->actingAs($this->manager, 'sanctum')
        ->withHeader('X-Company-Id', $this->company->id)
        ->postJson('/api/v1/orders', $this->payloadWithTwoProductLines())
        ->assertCreated();

    self::assertSame(1, $spy->resolveManyCalls);
    self::assertCount(2, $spy->lastContexts);
}
```

- [ ] **Step 2: Run red test**

```bash
cd apps/api
php artisan test tests/Feature/Document/DiscountPolicyDocumentValidationTest.php
```

Expected: FAIL because validator is not wired and meta warnings are absent.

- [ ] **Step 3: Implement validator and request injection**

Extend constructors:

```php
public function __construct(
    private readonly CompanyContext $companyContext,
    private readonly CompanyConfigService $configService,
    private readonly PurchaseBonusGate $purchaseBonusGate,
    private readonly DiscountPolicyDocumentValidator $discountPolicyValidator,
) {
    parent::__construct();
}
```

Call inside existing `withValidator()->after()` after total-line check:

```php
$this->discountPolicyValidator->validate($this, $validator);
```

Validator route guard:

```php
private function isDiscountPolicyDocumentRoute(FormRequest $request): bool
{
    $route = $request->route();
    if (! $route instanceof Route) {
        return false;
    }

    return in_array($route->getName(), [
        'invoices.store',
        'invoices.update',
        'orders.store',
        'orders.update',
    ], true);
}
```

Use `DiscountPolicyInterface::resolveMany()` once for product lines and add validation errors only for `Block` or `WarnRequiresPermission` when the current user lacks the verdict's existing `requiresPermission`. Advisory warnings and permission-holder warnings are attached to request attributes for controller response meta.

This validator extends the existing `MarginService::canSellAtPrice()` behavior by consuming the policy verdict fields that were parity-tested against `MarginService` in Wave 3: `allowed`, `requiresPermission`, `marginLevel`, and below-cost/minimum-margin reason. It must not invent a new floor override permission or a second permission decision.

- [ ] **Step 4: Ensure controllers surface warning meta**

Modify invoice/order store/update response assembly only where those responses already include meta. Add:

```php
$warnings = $request->attributes->get('discount_policy_warnings', []);
if ($warnings !== []) {
    $payload['meta']['discount_policy_warnings'] = $warnings;
}
```

If a controller returns a bare `{data: ...}` array, add `meta.timestamp` and `meta.request_id` while preserving existing `data`.

- [ ] **Step 5: Run green tests**

Run the Step 2 command plus the existing tolerance regression:

```bash
cd apps/api
php artisan test tests/Feature/Document/DiscountPolicyDocumentValidationTest.php tests/Feature/Document/DiscountToleranceValidationTest.php
```

Expected: PASS.

- [ ] **Step 6: Run wave static checks**

```bash
cd apps/api
./vendor/bin/pint --test app/Modules/Document tests/Feature/Document/DiscountPolicyDocumentValidationTest.php
./vendor/bin/phpstan analyse --level=8 app/Modules/Document/Presentation/Validation app/Modules/Document/Presentation/Requests --memory-limit=2G
rg -n "app\\(" app/Modules/Document/Presentation/Validation app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php
```

Expected: Pint/PHPStan PASS. `rg` may show the pre-existing `app()` in `AppliesDiscountToleranceRule`; it must not show new `app()` in the validator or modified requests.

- [ ] **Step 7: Opus review and reconcile**

```bash
claude -p --model claude-opus-4-8 "You are an adversarial reviewer. Review Wave 4 of Product Pricing Panel + Discount Policy Cascade Phase 1. Verify document validation is advisory by default, Block opt-in only, WarnRequiresPermission uses existing sell_below_minimum_margin/sell_below_cost permissions, route-gated to invoice/order store/update, all lines resolved in one batch, constructor injection only, no fiscal/POS scope. Cite file:line, verify against code, never hallucinate. Gate: do not approve unless clean." > docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave4-opus-review.md
```

Reconcile every BLOCKER/MAJOR before continuing.

- [ ] **Step 8: Commit Wave 4**

```bash
git add apps/api docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave4-opus-review.md
git commit -m "Phase 1.0.4: Add advisory document discount validation

Co-Authored-By: Codex <noreply@openai.com>"
```

---

### Wave 5: Frontend Pricing Intelligence Panel and HT/TTC Reconciliation

**Files:**
- Create: `apps/web/src/features/inventory/components/pricing/pricingMath.ts`
- Create: `apps/web/src/features/inventory/components/pricing/pricingMath.test.ts`
- Create: `apps/web/src/features/inventory/components/pricing/PricingIntelligencePanel.tsx`
- Create: `apps/web/src/features/inventory/components/pricing/PricingIntelligencePanel.test.tsx`
- Create: `apps/web/src/features/inventory/components/pricing/PricingModeCalculator.tsx`
- Create: `apps/web/src/features/inventory/components/pricing/PricingModeCalculator.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Modify: `apps/web/src/features/inventory/__tests__/productPayload.test.ts`
- Modify: `apps/web/src/features/inventory/ProductForm.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.test.tsx`
- Modify: `apps/web/src/hooks/usePermissions.ts`
- Modify: `apps/web/src/locales/en/inventory.json`
- Modify: `apps/web/src/locales/fr/inventory.json`
- Modify: `apps/web/src/locales/ar/inventory.json`

**Interfaces:**
- `PricingIntelligencePanel` accepts string props from Product DTO and optionally a `DiscountPolicyVerdict`.
- `PricingModeCalculator` emits HT `sale_price` strings only.
- `ProductForm` stores/sends HT in `sale_price`; TTC input derives from HT using selected tax.
- `pricing.view_cost_prices` gates cost, WAC, last purchase, margin, floor, and calculator controls.

- [ ] **Step 1: Write failing decimal math tests**

```ts
it('keeps sale_price canonical HT and derives TTC without mutating the stored value', () => {
  expect(priceTtcFromHt('100.00', '19.00', 2)).toBe('119.00')
  expect(priceHtFromTtc('119.00', '19.00', 2)).toBe('100.00')
})

it('returns null margin state when there is no cost data', () => {
  expect(resolveMarginState({ cost: '0.000000', salePriceHt: '100.00', minimumMargin: '10.00', targetMargin: '30.00' }))
    .toEqual({ level: 'none', marginPercent: null })
})

it('calculates margin and coefficient with string math', () => {
  expect(priceHtFromMargin('80.000000', '25.00', 2)).toBe('100.00')
  expect(coefficientFromCostAndPrice('80.000000', '100.00')).toBe('1.25')
})
```

- [ ] **Step 2: Write failing component tests**

```tsx
it('hides WAC and calculator when pricing.view_cost_prices is denied', async () => {
  mockHasPermission.mockImplementation((permission: string) => permission !== 'pricing.view_cost_prices')
  render(<PricingIntelligencePanel product={productFixture()} currency="EUR" locale="fr-FR" />)

  expect(screen.queryByText('inventory:pricing.wac')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'inventory:pricing.mode.margin' })).not.toBeInTheDocument()
})

it('renders WAC, last purchase, margin state, HT/TTC toggle, and product cap', async () => {
  mockHasPermission.mockReturnValue(true)
  render(<PricingIntelligencePanel product={productFixture({ max_discount_percent: '8.50' })} currency="EUR" locale="fr-FR" />)

  expect(screen.getByText('inventory:pricing.wac')).toBeInTheDocument()
  expect(screen.getByText('inventory:pricing.lastPurchase')).toBeInTheDocument()
  expect(screen.getByText('8.50%')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'inventory:pricing.basis.ht' })).toHaveAttribute('aria-pressed', 'true')
})

it('margin mode updates ProductForm sale_price as HT', async () => {
  render(<ProductForm />)
  await user.type(screen.getByLabelText('inventory:products.purchasePrice'), '80')
  await user.type(screen.getByLabelText('inventory:pricing.marginPercent'), '25')

  expect(screen.getByLabelText('inventory:products.salePrice')).toHaveValue(100)
})
```

- [ ] **Step 3: Run red frontend tests**

```bash
pnpm --filter @autoerp/web test -- apps/web/src/features/inventory/components/pricing/pricingMath.test.ts apps/web/src/features/inventory/components/pricing/PricingIntelligencePanel.test.tsx apps/web/src/features/inventory/components/pricing/PricingModeCalculator.test.tsx apps/web/src/features/inventory/ProductForm.test.tsx apps/web/src/features/inventory/ProductDetailPage.test.tsx
```

Expected: FAIL because new files and HT behavior do not exist.

- [ ] **Step 4: Implement string math helpers**

Use `bcadd`, `bcsub`, `bcdiv`, `bcmul`, and `bccomp` from `@/lib/decimal`. Do not call `parseFloat` or `Number` for money/quantity.

```ts
export function priceTtcFromHt(ht: string, taxRate: string, scale: number): string {
  if (ht.trim() === '') return ''
  return bcmul(ht, taxDivisor(taxRate), scale)
}

export function priceHtFromMargin(cost: string, marginPercent: string, scale: number): string {
  if (cost.trim() === '' || marginPercent.trim() === '' || bccomp(cost, '0') <= 0) return ''
  const factor = bcadd('1', bcdiv(marginPercent, '100', 6), 6)
  return bcmul(cost, factor, scale)
}
```

- [ ] **Step 5: Implement panel and ProductForm HT behavior**

`ProductForm` changes:
- `priceHtValue = salePriceValue`, not `priceHtFromTtc(salePriceValue, ...)`.
- HT input writes `sale_price` directly.
- TTC input writes `sale_price = priceHtFromTtc(ttc, taxRate, scale)`.
- Existing `sale_price` form field label becomes explicit HT.
- Cost/margin strip is wrapped by `hasPermission('pricing.view_cost_prices')`; non-cost users still see basic sale price HT.

`ProductDetailPage` changes:
- Replace the inline pricing card with `PricingIntelligencePanel`.
- Use `apiPost<DiscountPolicyVerdict>('/pricing/discount-policy', ...)` with `tenantScopedKey(['pricing-discount-policy', product.id, product.sale_price])` only when the user has `pricing.view_cost_prices`.
- Do not double-unwrap response data.

- [ ] **Step 6: Add translations**

Add keys under `inventory.pricing`:

```json
{
  "wac": "WAC",
  "lastPurchase": "Last purchase",
  "noCostData": "No cost data",
  "mode": { "margin": "Margin", "coefficient": "Coefficient", "manual": "Manual" },
  "basis": { "ht": "HT", "ttc": "TTC" },
  "maxDiscount": "Max discount"
}
```

Use equivalent FR/AR translations without hardcoded visible English in components.

- [ ] **Step 7: Run green frontend tests**

Run Step 3 command.

Expected: PASS. If Vitest hangs, run:

```bash
ps aux | grep 'node (vitest' | grep -v grep
kill <pid>
```

- [ ] **Step 8: Run frontend static checks**

```bash
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web lint:eslint apps/web/src/features/inventory/ProductForm.tsx apps/web/src/features/inventory/ProductDetailPage.tsx apps/web/src/features/inventory/components/pricing
pnpm --filter @autoerp/web audit:keys
npx react-doctor@latest --verbose --diff
```

Expected: PASS or no score regression from React Doctor. Fix any regression before review.

- [ ] **Step 9: Opus review and reconcile**

```bash
claude -p --model claude-opus-4-8 "You are an adversarial reviewer. Review Wave 5 of Product Pricing Panel + Discount Policy Cascade Phase 1 frontend. Verify sale_price is HT, TTC is derived, no parseFloat/Number on money, cost panel and endpoint calls are gated by pricing.view_cost_prices, tenantScopedKey is used, apiPost is not double-unwrapped, all visible text uses t(), and UI stays Phase-1 only. Cite file:line, verify against code, never hallucinate. Gate: do not approve unless clean." > docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave5-opus-review.md
```

Reconcile every BLOCKER/MAJOR before continuing.

- [ ] **Step 10: Commit Wave 5**

```bash
git add apps/web packages/shared/types/generated.d.ts docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-wave5-opus-review.md
git commit -m "Phase 1.0.5: Add pricing intelligence panel

Co-Authored-By: Codex <noreply@openai.com>"
```

---

### Wave 6: Integrated Verification, Browser Trace, Handoff, and Push

**Files:**
- Create: `docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-final-opus-review.md`
- Create: `docs/sessions/HANDOFF-pricing-discount-phase1.md`
- Modify only files required to reconcile final review findings.

**Interfaces:**
- Feature branch is pushed as `feat/pricing-discount-panel`.
- Handoff records shipped scope, deploy owes, exact verification, browser trace, and Phase 2 follow-on scope.

- [ ] **Step 1: Run scoped preflight**

Run:

```bash
PREFLIGHT_TEST_PATHS='tests/Feature/Pricing/DiscountPolicySchemaTest.php tests/Feature/Pricing/PricingPermissionSeederTest.php tests/Feature/Pricing/DiscountPolicyEndpointTest.php tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Feature/Product/ProductDiscountCapValidationTest.php tests/Feature/Product/CategoryDiscountCapValidationTest.php tests/Feature/Company/CompanyDiscountPolicySettingsTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php tests/Feature/Document/DiscountToleranceValidationTest.php tests/Unit/Pricing/DiscountCapResolverTest.php tests/Unit/Pricing/DiscountPolicyServiceTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php' ./scripts/preflight.sh
```

Expected: backend path tests PASS, PHPStan/Pint PASS, generated types in sync, frontend gates run. If full Vitest inside preflight is too broad or hangs, stop it and run the exact Wave 5 Vitest path command instead, documenting the substitution in handoff.

- [ ] **Step 2: Run final adversarial review**

```bash
claude -p --model claude-opus-4-8 "You are an adversarial reviewer. Final review Product Pricing Panel + Discount Policy Cascade Phase 1. Verify all Rev 3 deliverables and exclusions: no POS device/fiscal chain, no margin_floor_buffer_percent, no pricing.override_discount_floor, floor reuses minimum margin, document advisory default, cap cascade product/category/company only, guarded endpoint, manager cost permission, sale_price HT, bcmath/string precision, frontend gates and translations. Cite file:line, verify against code and tests, never hallucinate. Gate: do not approve unless clean." > docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-final-opus-review.md
```

Reconcile every BLOCKER/MAJOR and rerun affected path tests.

- [ ] **Step 3: Browser verification trace**

Start only the required servers. Use an existing dev server if already running; otherwise:

```bash
pnpm --filter @autoerp/web dev -- --host 127.0.0.1 --port 5173
```

Verify in browser:
- Open a product detail page.
- Confirm WAC, last purchase, margin traffic-light, HT/TTC toggle, and max discount render for a manager/admin.
- Set a price by margin and confirm the submitted `sale_price` remains HT.
- Set a per-product cap and confirm the panel shows the product cap over inherited caps.
- Submit an invoice/order line below floor in `Block` mode and observe 422; in `Advisory` mode observe create succeeds with warning meta.

Record the trace in handoff with exact URLs. If a screenshot tool is used during verification, save the screenshot under `docs/sessions/artifacts/pricing-discount-phase1/` and link its path in the handoff.

- [ ] **Step 4: Write handoff**

Create `docs/sessions/HANDOFF-pricing-discount-phase1.md` with:
- What shipped.
- Verification commands and results.
- Review artifacts and reconciliation summary.
- Deploy owes: `tenants:migrate`, `db:seed --class=CountryPricingRegulationSeeder`, permission reseed, `permission:cache-reset` per tenant DB.
- Open questions and deferred work.
- Phase 2 follow-on: POS device enforcement, redacted signed sync DTO, sealed policy snapshot, ingestion audit replay, TN pharma enforcement.

- [ ] **Step 5: Final status and push**

```bash
git status --short
git add docs/sessions/HANDOFF-pricing-discount-phase1.md docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-final-opus-review.md
git commit -m "Phase 1.0.6: Document pricing discount handoff

Co-Authored-By: Codex <noreply@openai.com>"
git push origin feat/pricing-discount-panel
```

Expected: branch pushed, not merged to `dev`, not pushed to `origin/dev`.
