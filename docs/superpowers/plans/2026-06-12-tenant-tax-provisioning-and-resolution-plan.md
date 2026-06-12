# Tenant Tax Provisioning & Default Resolution — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every provisioned tenant/company gets country-appropriate tax configurations (TN + FR) and a company default tax, and every sellable product/composite persists with a non-NULL tax rate — so POS sales and invoices resolve correct tax on all provisioning paths.

**Architecture:** A shared `CompanyTaxProvisioningService` (driven by a `CountryTaxConfigurationRegistry`) seeds country configs + sets the company default FK/rate; it is called from every company-creation path. A repaired `TaxResolutionService` is the single resolver, called by every product/composite writer to materialize a default `tax_rate` when none is supplied (the "no NULL tax" invariant). The fiscal line-creation/calculation path is **not** modified.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PHPUnit (`RefreshDatabase`, scoped `--filter`), PostgreSQL (db-per-tenant), Spatie tenancy seeders.

**Spec:** `docs/superpowers/specs/2026-06-12-tenant-tax-provisioning-and-resolution-design.md` (rev 2)
**Review addressed:** `docs/superpowers/specs/reviews/2026-06-12-tax-pos-specs-codex-review.md`

**Conventions (from CLAUDE.md):** constructor injection only (no `app()`); strict typing (no `mixed`); enums for status/type; PHPStan level 8 on new code; `CurrencyScale`/`QuantityScale` for money/qty (tax math here is integer/string rates, not currency at-rest); **never run the full PHPUnit suite** — always `--filter` or a path. Pint + PHPStan before each commit.

---

## File Structure

**New files:**
- `app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php` — country_code → seeder map.
- `app/Modules/Taxation/Application/Services/CompanyTaxProvisioningService.php` — seed configs + set company default FK/rate; fail-loud on missing `countries`.
- `database/seeders/FranceTaxConfigurationSeeder.php` — FR TVA configs.
- Test files mirrored under `tests/Feature/Taxation/` and `tests/Unit/Taxation/`.

**Modified (responsibility):**
- `TaxResolutionService.php` — repair `getDefaultTaxForNewProduct` (UUID category id); becomes the canonical default resolver.
- `TenantInitializationService.php` — delegate to `CompanyTaxProvisioningService`.
- `CompanyController.php` — call provisioning on add-company.
- Demo seeders — call provisioning per company; `CoffeeShopSeeder` ordering fix.
- `Category.php`, `CategoryData.php`, `CategoryController.php`, category requests — expose + validate tax fields.
- `Create/UpdateProductRequest.php`, `StoreCompositeItemRequest.php` parity — coherence rule.
- `ProductController.php`, `ProductService.php`, `CompositeItemController.php`, `CompositeItemImportService.php` — invariant wiring.

---

## Phase 1 — Core units

### Task 1: `CountryTaxConfigurationRegistry`

**Files:**
- Create: `app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php`
- Test: `tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Application\Registries\CountryTaxConfigurationRegistry;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Tests\TestCase;

final class CountryTaxConfigurationRegistryTest extends TestCase
{
    public function test_maps_known_countries_to_seeder_classes(): void
    {
        $registry = new CountryTaxConfigurationRegistry();

        $this->assertSame(TunisiaTaxConfigurationSeeder::class, $registry->seederFor('TN'));
        $this->assertSame(FranceTaxConfigurationSeeder::class, $registry->seederFor('fr')); // case-insensitive
        $this->assertNull($registry->seederFor('US'));
        $this->assertTrue($registry->supports('TN'));
        $this->assertFalse($registry->supports('US'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CountryTaxConfigurationRegistryTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Registries;

use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Database\Seeder;

/**
 * Maps an ISO 3166-1 alpha-2 country code to the seeder that loads that
 * country's tax_configurations. Add new countries here only.
 *
 * @var array<string, class-string<Seeder>>
 */
final class CountryTaxConfigurationRegistry
{
    private const MAP = [
        'TN' => TunisiaTaxConfigurationSeeder::class,
        'FR' => FranceTaxConfigurationSeeder::class,
    ];

    /** @return class-string<Seeder>|null */
    public function seederFor(string $countryCode): ?string
    {
        return self::MAP[strtoupper($countryCode)] ?? null;
    }

    public function supports(string $countryCode): bool
    {
        return $this->seederFor($countryCode) !== null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CountryTaxConfigurationRegistryTest`
Expected: PASS. Then `./vendor/bin/pint app/Modules/Taxation/Application/Registries && ./vendor/bin/phpstan analyse app/Modules/Taxation/Application/Registries`.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Taxation/Application/Registries tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php
git commit -m "feat(taxation): country tax-config registry"
```

---

### Task 2: `FranceTaxConfigurationSeeder`

**Files:**
- Create: `database/seeders/FranceTaxConfigurationSeeder.php`
- Test: `tests/Feature/Taxation/FranceTaxConfigurationSeederTest.php`

**Reference template:** `database/seeders/TunisiaTaxConfigurationSeeder.php` (same column set; `applicable_document_types` list copied verbatim; **no** stamp-duty section).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FranceTaxConfigurationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_five_french_vat_configs_with_20pct_default(): void
    {
        (new CountriesSeeder())->run();

        (new FranceTaxConfigurationSeeder())->run();

        $configs = TaxConfiguration::where('country_code', 'FR')->get();
        $this->assertCount(5, $configs);

        $default = $configs->firstWhere('is_default', true);
        $this->assertNotNull($default);
        $this->assertSame('20.00', (string) $default->percentage_rate);
        $this->assertSame('TVA_FR_20', $default->code);

        // Idempotent
        (new FranceTaxConfigurationSeeder())->run();
        $this->assertSame(5, TaxConfiguration::where('country_code', 'FR')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FranceTaxConfigurationSeederTest`
Expected: FAIL (seeder class not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Seeder;

class FranceTaxConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $vatRates = [
            ['name' => 'TVA 20% (taux normal)', 'code' => 'TVA_FR_20', 'percentage_rate' => '20.00', 'is_default' => true, 'sequence_order' => 1],
            ['name' => 'TVA 10% (taux intermédiaire)', 'code' => 'TVA_FR_10', 'percentage_rate' => '10.00', 'is_default' => false, 'sequence_order' => 2],
            ['name' => 'TVA 5,5% (taux réduit)', 'code' => 'TVA_FR_5_5', 'percentage_rate' => '5.50', 'is_default' => false, 'sequence_order' => 3],
            ['name' => 'TVA 2,1% (taux particulier)', 'code' => 'TVA_FR_2_1', 'percentage_rate' => '2.10', 'is_default' => false, 'sequence_order' => 4],
            ['name' => 'Exonéré TVA', 'code' => 'TVA_FR_EXEMPT', 'percentage_rate' => '0.00', 'is_default' => false, 'sequence_order' => 5],
        ];

        foreach ($vatRates as $rate) {
            TaxConfiguration::updateOrCreate(
                ['country_code' => 'FR', 'code' => $rate['code']],
                [
                    'name' => $rate['name'],
                    'tax_type' => 'PERCENTAGE',
                    'percentage_rate' => $rate['percentage_rate'],
                    'fixed_amount' => null,
                    'applies_to' => 'LINE_ITEMS',
                    'is_default' => $rate['is_default'],
                    'is_active' => true,
                    'sequence_order' => $rate['sequence_order'],
                    'stacks_on' => 'SUBTOTAL',
                    'applicable_document_types' => [
                        'TAX_INVOICE', 'FISCAL_RECEIPT', 'CREDIT_NOTE',
                        'PURCHASE_INVOICE', 'DELIVERY_NOTE', 'QUOTATION',
                    ],
                    'is_stamp_duty' => false,
                    'is_recoverable' => true,
                ]
            );
        }

        $this->command?->info('France VAT rates seeded.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FranceTaxConfigurationSeederTest`
Expected: PASS. Then Pint on `database/seeders/FranceTaxConfigurationSeeder.php`.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/FranceTaxConfigurationSeeder.php tests/Feature/Taxation/FranceTaxConfigurationSeederTest.php
git commit -m "feat(taxation): France TVA configuration seeder (20/10/5.5/2.1/exempt)"
```

---

### Task 3: `CompanyTaxProvisioningService`

**Files:**
- Create: `app/Modules/Taxation/Application/Services/CompanyTaxProvisioningService.php`
- Test: `tests/Feature/Taxation/CompanyTaxProvisioningServiceTest.php`

**Behavior:** given a `Company`, run the country seeder (via registry), then set `company.default_tax_configuration_id` to the `is_default` config and `company.default_tax_rate` to its `percentage_rate`. If `countries` lacks the company country → throw in non-production (`app()->environment` is read via injected config is not allowed; inject a flag) — see implementation note.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tests\Support\CompanyFactoryHelper; // existing helper or inline create

final class CompanyTaxProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_tn_configs_and_sets_company_default(): void
    {
        (new CountriesSeeder())->run();
        $company = $this->makeCompany('TN'); // helper that creates tenant+company with country_code TN

        (new CompanyTaxProvisioningService(failLoudOnMissingCountry: true))->provisionForCompany($company);

        $this->assertGreaterThanOrEqual(4, TaxConfiguration::where('country_code', 'TN')->count());
        $company->refresh();
        $default = TaxConfiguration::where('country_code', 'TN')->where('is_default', true)->first();
        $this->assertNotNull($company->default_tax_configuration_id);
        $this->assertSame($default->id, $company->default_tax_configuration_id);
        $this->assertSame('19.00', (string) $company->default_tax_rate);
    }

    public function test_is_idempotent(): void
    {
        (new CountriesSeeder())->run();
        $company = $this->makeCompany('FR');
        $service = new CompanyTaxProvisioningService(failLoudOnMissingCountry: true);

        $service->provisionForCompany($company);
        $service->provisionForCompany($company);

        $this->assertSame(5, TaxConfiguration::where('country_code', 'FR')->count());
    }

    public function test_fails_loud_when_countries_missing(): void
    {
        // countries NOT seeded
        $company = $this->makeCompany('TN');

        $this->expectException(RuntimeException::class);
        (new CompanyTaxProvisioningService(failLoudOnMissingCountry: true))->provisionForCompany($company);
    }

    public function test_skips_silently_for_unsupported_country(): void
    {
        (new CountriesSeeder())->run();
        $company = $this->makeCompany('US');

        (new CompanyTaxProvisioningService(failLoudOnMissingCountry: true))->provisionForCompany($company);

        $company->refresh();
        $this->assertNull($company->default_tax_configuration_id); // no seeder for US, nothing set
    }
}
```

> **Note on `makeCompany()`:** reuse the existing tenant/company creation test helper used by `TenantInitializationService` tests (search `tests/` for a `Company::factory` or a registration helper). If none exists, create a small `protected function makeCompany(string $country): Company` in the test that creates a tenant + company with `country_code` set, matching how `TenantProvisioningService` builds them.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyTaxProvisioningServiceTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Taxation\Application\Registries\CountryTaxConfigurationRegistry;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single entry point for giving a Company its country tax configurations and a
 * sensible company-level default tax. Idempotent. Call from EVERY company writer.
 *
 * Runs on the active (per-tenant) connection — the caller must have the tenant
 * connection initialized and `countries` seeded before calling.
 */
final class CompanyTaxProvisioningService
{
    public function __construct(
        private readonly CountryTaxConfigurationRegistry $registry = new CountryTaxConfigurationRegistry(),
        private readonly bool $failLoudOnMissingCountry = false,
    ) {}

    public function provisionForCompany(Company $company): void
    {
        $countryCode = strtoupper($company->country_code);

        $seederClass = $this->registry->seederFor($countryCode);
        if ($seederClass === null) {
            return; // no configs defined for this country yet
        }

        $countryExists = DB::table('countries')->where('code', $countryCode)->exists();
        if (! $countryExists) {
            if ($this->failLoudOnMissingCountry) {
                throw new RuntimeException(
                    "Cannot seed tax configurations: country '{$countryCode}' is missing from the countries table. "
                    .'Seed reference data (CountriesSeeder) before provisioning company tax.'
                );
            }

            return;
        }

        /** @var Seeder $seeder */
        $seeder = new $seederClass();
        $seeder->run();

        $default = TaxConfiguration::where('country_code', $countryCode)
            ->where('is_default', true)
            ->first();

        if ($default !== null) {
            $company->update([
                'default_tax_configuration_id' => $default->id,
                'default_tax_rate' => $default->percentage_rate,
            ]);
        }
    }
}
```

> **DI note:** the `failLoudOnMissingCountry` flag defaults to `false`; bind it `true` in seed/dev/testing via a service-provider binding keyed on `app()->environment(['local','testing'])` **inside the provider** (allowed — providers may use the container), or pass `true` explicitly from seeders. Production registration passes `false` (countries are seeded first there anyway). Do NOT call `app()` inside the service.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CompanyTaxProvisioningServiceTest`
Expected: PASS (all 4). Pint + PHPStan on the new service.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Taxation/Application/Services/CompanyTaxProvisioningService.php tests/Feature/Taxation/CompanyTaxProvisioningServiceTest.php
git commit -m "feat(taxation): shared company tax provisioning service (fail-loud, idempotent)"
```

---

### Task 4: Repair `TaxResolutionService::getDefaultTaxForNewProduct` for UUID categories

**Files:**
- Modify: `app/Modules/Taxation/Domain/Services/TaxResolutionService.php:67-80`
- Test: `tests/Unit/Taxation/TaxResolutionServiceDefaultTest.php`

**Why:** the method is typed `?int $categoryId` and uses `DB::table('categories')->find($categoryId)`, but categories use UUID ids. It silently never matches a category.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Category;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaxResolutionServiceDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_product_default_inherits_category_then_company(): void
    {
        $service = new TaxResolutionService();
        $company = $this->makeCompany('FR'); // sets default_tax_rate '20.00'
        $company->update(['default_tax_rate' => '20.00']);

        // No category -> company default
        $this->assertSame('20.00', $service->getDefaultTaxForNewProduct($company, null));

        // Category with a default -> category default
        $category = Category::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'name' => 'Médicaments',
            'default_tax_rate' => '10.00',
        ]);
        $this->assertSame('10.00', $service->getDefaultTaxForNewProduct($company, $category->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TaxResolutionServiceDefaultTest`
Expected: FAIL (category branch returns company default because `find()` by UUID against `?int` mismatches, or Category not fillable for `default_tax_rate` — note Task 6 makes it fillable; if Task 6 not yet done, this test also drives that).

- [ ] **Step 3: Write minimal implementation**

Replace the method body:

```php
    /**
     * Get the default tax rate for a NEW product (no explicit rate yet).
     * Inherits category default → company default.
     */
    public function getDefaultTaxForNewProduct(Company $company, ?string $categoryId = null): string
    {
        if ($categoryId !== null) {
            $rate = \DB::table('categories')
                ->where('id', $categoryId)
                ->value('default_tax_rate');

            if ($rate !== null) {
                return (string) $rate;
            }
        }

        return (string) ($company->default_tax_rate ?? '0.00');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TaxResolutionServiceDefaultTest`
Expected: PASS. PHPStan + Pint.

> If PHPStan flags callers passing `int`, update them — there should be none (method was unused).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Taxation/Domain/Services/TaxResolutionService.php tests/Unit/Taxation/TaxResolutionServiceDefaultTest.php
git commit -m "fix(taxation): resolve new-product default tax by UUID category id"
```

---

## Phase 2 — Wire provisioning into every company writer

### Task 5: Delegate `TenantInitializationService` to the shared service

**Files:**
- Modify: `app/Modules/Tenant/Application/Services/TenantInitializationService.php:235-271`
- Test: `tests/Feature/Tenant/TenantInitializationTaxTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantInitializationTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sets_company_default_tax_configuration(): void
    {
        // Use the existing registration/init harness used by other TenantInitialization tests.
        [$tenant, $company, $user] = $this->initializeNewRegistration('TN');

        $company->refresh();
        $this->assertNotNull($company->default_tax_configuration_id);
        $this->assertSame('19.00', (string) $company->default_tax_rate);
        $this->assertGreaterThanOrEqual(4, TaxConfiguration::where('country_code', 'TN')->count());
    }
}
```

> Reuse the harness already used by existing `TenantInitializationService` tests (search `tests/Feature/Tenant` / `tests/Feature/Identity`). If `initializeNewRegistration()` doesn't exist, wrap a direct call to `TenantInitializationService::initializeForNewRegistration()` after building tenant/company/user like `TenantProvisioningService` does.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TenantInitializationTaxTest`
Expected: FAIL (`default_tax_configuration_id` is null — today only `default_tax_rate` is set).

- [ ] **Step 3: Write minimal implementation**

Replace `seedTaxConfigurations()` and fold in the company-default assignment by delegating. Inject the service via constructor (add to the class constructor):

```php
// constructor (add param)
public function __construct(
    private readonly \App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService $companyTaxProvisioning,
) {}
```

```php
// replace the body of seedTaxConfigurations()
private function seedTaxConfigurations(Company $company): void
{
    // Delegates to the shared provisioning service: seeds the country configs
    // AND sets company.default_tax_configuration_id + default_tax_rate.
    $this->companyTaxProvisioning->provisionForCompany($company);
}
```

Remove the now-redundant `setDefaultTaxRate()` call at line 76 **only if** the provisioning service sets the rate for supported countries; keep `setDefaultTaxRate()` as the fallback for unsupported countries (it sets `default_tax_rate` even when no config seeder exists). Order: call `setDefaultTaxRate()` first (fallback), then `seedTaxConfigurations()` (overrides rate from the default config when a seeder exists). Leave the call sites at lines 76 + 79 in that order.

> The container will resolve `CompanyTaxProvisioningService` (the `failLoudOnMissingCountry` flag comes from its provider binding). If `TenantInitializationService` is currently `new`-ed anywhere instead of resolved, update that call site to resolve via the container/constructor injection.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TenantInitializationTaxTest`
Expected: PASS. Also re-run `--filter=TenantInitialization` to confirm no regression. PHPStan + Pint.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Tenant/Application/Services/TenantInitializationService.php tests/Feature/Tenant/TenantInitializationTaxTest.php
git commit -m "refactor(tenant): delegate tax seeding to CompanyTaxProvisioningService; set company default FK"
```

---

### Task 6: `CompanyController::store` provisions tax

**Files:**
- Modify: `app/Modules/Company/Presentation/Controllers/CompanyController.php:66-104,145-152`
- Test: `tests/Feature/Company/CompanyStoreTaxTest.php`

- [ ] **Step 1: Write the failing test** — assert that after `POST` create-company (TN), the new company has `default_tax_configuration_id` set and TN configs exist. (Model after existing `CompanyController` feature tests for auth/route setup.)

- [ ] **Step 2: Run** `php artisan test --filter=CompanyStoreTaxTest` → FAIL.

- [ ] **Step 3: Implement** — constructor-inject `CompanyTaxProvisioningService`; after the company is created (and CoA seeded at :145-152), call `$this->companyTaxProvisioning->provisionForCompany($company);`.

- [ ] **Step 4: Run** → PASS; PHPStan + Pint.

- [ ] **Step 5: Commit** `git commit -m "feat(company): provision country tax on add-company endpoint"`

---

### Task 7: `CoffeeShopSeeder` tenant-connection ordering fix (prerequisite for Task 8)

**Files:**
- Modify: `database/seeders/CoffeeShopSeeder.php:95-102,195-228`
- Test: `tests/Feature/Seeders/CoffeeShopSeederTaxTest.php`

**Why (M3):** `CoffeeShopSeeder` seeds reference data before creating/initializing the tenant, unlike `ParapharmacySeeder`/`DatabaseSeeder`. Under db-per-tenant the tax step would run before `countries` exists and trip the fail-loud guard.

- [ ] **Step 1:** Write a test that runs `CoffeeShopSeeder` and asserts (a) it completes, (b) the TN coffee-shop company ends with `default_tax_configuration_id` set. Expected FAIL initially.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Reorder so the tenant is created + connection initialized + `countries`/reference data seeded **before** the financial-foundation/tax step, mirroring `ParapharmacySeeder.php:317-323,176-195`. (No tax call yet — that's Task 8; this task only fixes ordering and proves reference data lands in the tenant DB.)
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `git commit -m "fix(seeders): coffee-shop tenant-connection ordering before reference data"`

---

### Task 8: Call provisioning from all demo/vertical seeders

**Files (modify, each in its financial-foundation/company-create step):**
- `database/seeders/ParapharmacySeeder.php:412-426`
- `database/seeders/ParapharmacyMultiBranchSeeder.php:197-215`
- `database/seeders/CoffeeShopSeeder.php:276-287` (after Task 7)
- `database/seeders/TunisianParapharmacySeeder.php:99-101,157-178` (replace direct `TunisiaTaxConfigurationSeeder` call)
- `database/seeders/DatabaseSeeder.php:75-118`
- `database/seeders/DemoTenantSeeder.php` (each company create: :184-196 + the :1480-2026 blocks)
- `database/seeders/ProductionSeeder.php:41-44` (add FR alongside TN; stays lookup-only — call the two seeders directly, NOT `provisionForCompany`, since it creates no company)
- Test: `tests/Feature/Seeders/DemoSeedersTaxTest.php`

- [ ] **Step 1: Write the failing test** — a data provider over the company-creating seeders; for each, run it and assert the created company(ies) have non-zero country configs + a set `default_tax_configuration_id`. Start with `ParapharmacySeeder` (FR) and `CoffeeShopSeeder` (TN).

```php
public function test_parapharmacy_seeder_provisions_fr_tax(): void
{
    $this->artisan('db:seed', ['--class' => \Database\Seeders\ParapharmacySeeder::class, '--force' => true])->assertExitCode(0);
    $company = \App\Modules\Company\Domain\Company::where('country_code', 'FR')->latest()->first();
    $this->assertNotNull($company->default_tax_configuration_id);
    $this->assertSame(5, \App\Modules\Taxation\Domain\Entities\TaxConfiguration::where('country_code', 'FR')->count());
}
```

- [ ] **Step 2: Run** `php artisan test --filter=DemoSeedersTaxTest` → FAIL.

- [ ] **Step 3: Implement** — in each company-creating seeder, after the company + CoA are created and the tenant connection + `countries` are in place, add:

```php
$companyTaxProvisioning = new \App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService(
    failLoudOnMissingCountry: true,
);
$companyTaxProvisioning->provisionForCompany($company);
```

For `ProductionSeeder` (no company), seed both reference sets:

```php
(new \Database\Seeders\TunisiaTaxConfigurationSeeder())->run();
(new \Database\Seeders\FranceTaxConfigurationSeeder())->run();
```

- [ ] **Step 4: Run** `php artisan test --filter=DemoSeedersTaxTest` → PASS. Run each touched seeder once locally against the real tenant DB to confirm no ordering trip.

- [ ] **Step 5: Commit** `git commit -m "feat(seeders): provision country tax on every company-creating seeder"`

---

## Phase 3 — Repair the category group engine

### Task 9: Expose category tax fields on the model + DTO

**Files:**
- Modify: `app/Modules/Product/Domain/Category.php:47-58,73-79`
- Modify: `app/Modules/Product/Application/DTOs/CategoryData.php:18-33`
- Test: `tests/Unit/Product/CategoryTaxFieldsTest.php`

- [ ] **Step 1:** Test that `Category::create([... 'default_tax_rate' => '10.00', 'default_tax_configuration_id' => $cfgId])` persists both (mass-assignment) and that the cast returns the right types. Expected FAIL.
- [ ] **Step 2:** Run → FAIL (fields not fillable).
- [ ] **Step 3:** Add `'default_tax_rate'`, `'default_tax_configuration_id'` to `$fillable`; add casts (`'default_tax_rate' => 'decimal:2'`); add to the docblock; add both to `CategoryData` (nullable `?string`) and its `fromModel`/constructor.
- [ ] **Step 4:** Run → PASS; PHPStan + Pint.
- [ ] **Step 5:** Commit `git commit -m "fix(product): expose dormant category tax fields on model + DTO"`

---

### Task 10: Category create/update validation with coherence rule

**Files:**
- Modify: `app/Modules/Product/Presentation/Controllers/CategoryController.php:116-123,168-174` (or the FormRequests it uses)
- Test: `tests/Feature/Product/CategoryTaxValidationTest.php`

- [ ] **Step 1:** Test that creating a category with a `default_tax_configuration_id` whose country ≠ company country is rejected (422), and a same-country one is accepted. Expected FAIL.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add validation rules: `'default_tax_rate' => ['nullable','numeric','min:0','max:100','regex:/^\d+(\.\d{1,2})?$/']`, `'default_tax_configuration_id' => ['nullable','uuid','exists:tax_configurations,id', new TaxConfigurationCountryCoherent($companyCountryCode)]`. Resolve `$companyCountryCode` from the bound company context the controller already uses.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `git commit -m "feat(product): validate category default tax with country coherence"`

---

### Task 11: Product request coherence parity

**Files:**
- Modify: `app/Modules/Product/Presentation/Requests/CreateProductRequest.php:53-62`, `UpdateProductRequest.php:55-63`
- Test: `tests/Feature/Product/ProductTaxCoherenceTest.php`

- [ ] **Step 1:** Test that creating a product with a wrong-country `default_tax_configuration_id` is rejected. Expected FAIL (today only `exists:`).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add `new TaxConfigurationCountryCoherent($companyCountryCode)` to both requests' `default_tax_configuration_id` rule (mirror `StoreCompositeItemRequest:51-62`).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `git commit -m "feat(product): country-coherence on product default tax config"`

---

## Phase 4 — Enforce the NULL-tax invariant at every writer

### Task 12: Resolve default rate on product import (`ProductService::upsert`)

**Files:**
- Modify: `app/Modules/Product/Application/Services/ProductService.php:43-86`
- Test: `tests/Feature/Product/ProductUpsertDefaultTaxTest.php`

- [ ] **Step 1:** Test: `upsert()` with no `tax_rate` and a known company → product persists company default rate; with a `category_name` whose category has a default → category rate; with explicit `tax_rate` → preserved. Expected FAIL.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Inject `TaxResolutionService` (constructor) + a way to load the company. After resolving `category_id`, when `$attributes['tax_rate']` is null, set it via `getDefaultTaxForNewProduct($company, $attributes['category_id'] ?? null)`. Load `$company = Company::where('tenant_id',$tenantId)->where('id',$companyId)->firstOrFail();`.

```php
if (($attributes['tax_rate'] ?? null) === null) {
    $attributes['tax_rate'] = $this->taxResolution->getDefaultTaxForNewProduct(
        $company,
        $attributes['category_id'] ?? null,
    );
}
```

- [ ] **Step 4:** Run → PASS; PHPStan + Pint.
- [ ] **Step 5:** Commit `git commit -m "feat(product): import resolves default tax rate (no NULL)"`

---

### Task 13: Resolve default rate on product API create (`ProductController::store`)

**Files:**
- Modify: `app/Modules/Product/Presentation/Controllers/ProductController.php:304-308`
- Test: `tests/Feature/Product/ProductStoreDefaultTaxTest.php`

- [ ] **Step 1:** Test: `POST` create product (TN company) with no `tax_rate` → stored product has 19.00; explicit preserved. Expected FAIL.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Constructor-inject `TaxResolutionService`; before `Product::create(...)`, if validated `tax_rate` is null, set it from `getDefaultTaxForNewProduct($company, $validated['category_id'] ?? null)`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `git commit -m "feat(product): API create resolves default tax rate (no NULL)"`

---

### Task 14: Composite item create + import resolve default rate

**Files:**
- Modify: `app/Modules/Catalog/Presentation/Controllers/CompositeItemController.php:83-91` (store) + decide `duplicate()` preserves source
- Modify: `app/Modules/Catalog/Application/Services/CompositeItemImportService.php:19-69`
- Test: `tests/Feature/Catalog/CompositeItemDefaultTaxTest.php`

- [ ] **Step 1:** Tests: composite created via API/import with no `tax_rate` → company default; `duplicate()` preserves the source composite's tax fields. Expected FAIL.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Same resolver pattern as Tasks 12–13 in store + import upsert. In `duplicate()`, copy `tax_rate` + `default_tax_configuration_id` from source (no recompute).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `git commit -m "feat(catalog): composite items resolve/preserve default tax rate"`

---

### Task 15: Seed explicit rates in demo-seeder product creates

**Files:**
- Modify: `database/seeders/ParapharmacySeeder.php:536-546`, `CoffeeShopSeeder.php:334-343`, `ParapharmacyMultiBranchSeeder.php:438-448`
- Test: covered by Task 16 (POS receipt integration).

- [ ] **Step 1–4:** Give each seeded product an explicit `tax_rate` appropriate to its catalog (e.g. parapharmacy cosmetics 20% FR / 19% TN; medicines 10% FR / 7% TN). No new test file; verified by Task 16. Run the seeders to confirm no NULL rates: `php artisan tinker --execute="echo App\Modules\Product\Domain\Product::whereNull('tax_rate')->count();"` → expect 0 after seeding.
- [ ] **Step 5:** Commit `git commit -m "feat(seeders): explicit tax rates on demo products"`

---

## Phase 5 — Onboarding + end-to-end integration

### Task 16: POS/invoice resolves NON-ZERO tax end-to-end

**Files:**
- Test only: `tests/Feature/Taxation/EndToEndTaxResolutionTest.php`

- [ ] **Step 1:** Test: demo-provision a TN tenant; create a product via API with no rate; build a POS receipt (via `ReceiptCreationService`) and a document draft with that product; assert the persisted line `tax_rate` is 19.00 and `TaxCalculationService` returns non-zero tax. Mirror existing POS receipt tests for setup.
- [ ] **Step 2:** Run → expect PASS (all upstream tasks make it green). If it fails, the failing writer is the bug — fix at that writer, not here.
- [ ] **Step 3–4:** N/A (integration assertion).
- [ ] **Step 5:** Commit `git commit -m "test(taxation): end-to-end non-zero tax resolution across provisioning + writers"`

---

### Task 17: Onboarding tax step auto-satisfied

**Files:**
- Test: `tests/Feature/Tenant/OnboardingTaxStepTest.php`
- Optionally modify: `app/Modules/Tenant/Application/Services/OnboardingChecklistService.php` (label only, if relabeling to "Double-check taxes")

- [ ] **Step 1:** Test: a freshly demo-provisioned tenant → `checkTaxConfig()` true. Expected PASS (Task 5/8 set the FK). If relabeling, assert the step label string.
- [ ] **Step 2:** Run → PASS.
- [ ] **Step 5:** Commit `git commit -m "test(tenant): onboarding tax step satisfied after provisioning"`

---

## Self-review checklist (completed)

- **Spec coverage:** §3.1→Tasks 1,3,5,6,7,8; §3.2→Task 2; §3.3→Tasks 9,10,11 + Task 4; §3.4→Tasks 12–15; §2.2 FODEC/eco-tax→explicitly no task (out of scope); §3.5 onboarding→Task 17; §5 tests→folded per task + Task 16.
- **Placeholder scan:** seeder/test harness reuse is explicitly flagged where an existing helper must be located; no "TBD"/"add validation" left abstract.
- **Type consistency:** `getDefaultTaxForNewProduct(Company, ?string $categoryId): string`, `provisionForCompany(Company): void`, `seederFor(string): ?class-string` used consistently across tasks.
- **Known follow-ups (tracked, not in plan):** one-off backfill of pre-existing NULL-rate products; FODEC/eco-tax modeling; category tax UI; UK/IT seeders.
