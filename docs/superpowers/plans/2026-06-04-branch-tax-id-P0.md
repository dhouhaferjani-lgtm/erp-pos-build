# Branch Tax-ID (P0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Execution model for THIS plan (owner, 2026-06-04): Codex implements each task; Opus reviews every task before the next starts.** After each task's final commit there is an explicit **🔍 Opus review gate** — do not begin the next task until the review passes. Codex writes the failing test first, makes it pass, commits; Opus reviews the diff against the spec + this plan and the AutoERP conventions, then approves or returns findings.

**Goal:** Resolve the seller tax identity (tax_id / vat_number / legal_identifiers) per sellable branch with fallback to the company, across the receipt PDF, FacturX, the NF525 header fix, and the POS device's `seller.tax_number` source — with no fiscal payload version bump.

**Architecture:** Nullable override columns on `locations` → one `TaxIdentityResolver` (server) returning a `TaxIdentityDTO` → consumed by the 2 location-scoped server sites; the device applies the identical fallback on its already-synced `terminal.location`. Entry validation uses a shared country tax-number rule set kept in parity with the fiscal validator. Phase 1 (server) is fiscally inert and ships alone; Phase 2 (device) is a separate PR.

**Tech Stack:** Laravel 12 / PHP 8.2 strict (PHPUnit, PHPStan L8, Pint); React 19 / Vite / TS strict (Vitest); Tauri POS (TS). Backend tests use `RefreshDatabase` + real models + `RolesAndPermissionsSeeder` (see `tests/Feature/Location/LocationTest.php` for the canonical tenant/company/user setup).

**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2). **Out of scope:** everything in `docs/superpowers/specs/2026-06-04-per-branch-program-design.md` (P1–P5).

---

## File Structure

**Phase 1 — server (apps/api):**
- Create: `database/migrations/tenant/2026_06_05_000000_add_tax_fields_to_locations.php`
- Modify: `app/Modules/Company/Domain/Location.php` (fillable, casts, docblock)
- Create: `config/tax_identity.php` (per-country mode + required flag)
- Create: `app/Modules/Company/Application/Services/CountryTaxIdentityConfig.php` (typed config reader)
- Create: `app/Shared/Domain/Validation/CountryTaxNumberRules.php` (canonical regex set + matcher)
- Create: `app/Shared/Contracts/Company/TaxIdentityData.php` (resolver DTO)
- Create: `app/Modules/Company/Application/Services/TaxIdentityResolver.php`
- Modify: `app/Modules/Company/Presentation/Requests/CreateLocationRequest.php`, `UpdateLocationRequest.php`
- Modify: `app/Modules/Company/Presentation/Controllers/LocationController.php` (`store()` whitelist)
- Modify: `app/Modules/Company/Presentation/Resources/LocationResource.php`
- Modify: `app/Modules/POS/Application/Services/ReceiptPdfService.php` + `resources/views/pos/receipt.blade.php`
- Modify: `app/Modules/Document/Application/Services/FacturXService.php`
- Modify: `app/Modules/POS/Application/Services/Nf525DataProvider.php` (`buildCompanyHeader` null-SIRET fix)
- Frontend: `apps/web/src/features/locations/types.ts`, `apps/web/src/features/location/api.ts`, `apps/web/src/features/settings/LocationsPage.tsx`

**Phase 2 — device (apps/api + apps/pos):**
- Modify: `app/Modules/POS/Presentation/Resources/TerminalResource.php` (+ ensure `location` loaded on all terminal endpoints)
- Modify: `apps/pos/src/stores/terminalStore.ts` (`Terminal.location` type)
- Modify: `apps/pos/src/stores/paymentStore.ts` (SALE_RECEIPT + ACCOUNT_PAYMENT seller sourcing)

---

# PHASE 1 — Server (no fiscal risk, ships alone)

## Task 1: Migration — add nullable tax-identity columns to `locations`

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_05_000000_add_tax_fields_to_locations.php`
- Test: `apps/api/tests/Feature/Location/LocationTaxFieldsMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationTaxFieldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_locations_table_has_nullable_tax_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('locations', 'tax_id'));
        $this->assertTrue(Schema::hasColumn('locations', 'vat_number'));
        $this->assertTrue(Schema::hasColumn('locations', 'legal_identifiers'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Location/LocationTaxFieldsMigrationTest.php`
Expected: FAIL — `hasColumn('locations','tax_id')` returns false.

- [ ] **Step 3: Write the migration** (mirrors `2025_12_30_103000_add_tax_fields_to_companies.php`)

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
        Schema::table('locations', function (Blueprint $table) {
            // Per-branch tax-identity overrides. NULL ⇒ inherit the parent company's value.
            // Establishment identity is meaningful only for sellable (Shop) locations, but the
            // columns are universal; "required" is enforced at the request layer, not the schema.
            $table->string('tax_id', 50)->nullable()->after('address_country');
            $table->string('vat_number', 50)->nullable()->after('tax_id');
            $table->jsonb('legal_identifiers')->nullable()->after('vat_number');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['tax_id', 'vat_number', 'legal_identifiers']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && php artisan test tests/Feature/Location/LocationTaxFieldsMigrationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/tenant/2026_06_05_000000_add_tax_fields_to_locations.php apps/api/tests/Feature/Location/LocationTaxFieldsMigrationTest.php
git commit -m "feat(branch-tax-id): add nullable tax-identity columns to locations"
```

🔍 **Opus review gate** — confirm: additive only, nullable, jsonb for legal_identifiers, `after()` placement, down() drops all three, no backfill.

---

## Task 2: `Location` model — fillable + casts + docblock

**Files:**
- Modify: `apps/api/app/Modules/Company/Domain/Location.php` (fillable `:70-88`, casts `:95-105`, docblock `:16-44`)
- Test: `apps/api/tests/Feature/Location/LocationTaxFieldsModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTaxFieldsModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_persists_and_casts_tax_identity_fields(): void
    {
        $tenant = Tenant::create([
            'name' => 'T', 'slug' => 't', 'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional,
        ]);
        $company = Company::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'legal_name' => 'C LLC',
            'country_code' => 'FR', 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris',
            'currency' => 'EUR', 'status' => CompanyStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id, 'name' => 'Branch A', 'code' => 'BRA',
            'type' => LocationType::Shop, 'is_default' => false, 'is_active' => true,
            'tax_id' => '73282932000074',
            'vat_number' => 'FR40303265045',
            'legal_identifiers' => ['siret' => '73282932000074'],
        ]);

        $fresh = $location->fresh();
        $this->assertSame('73282932000074', $fresh->tax_id);
        $this->assertSame('FR40303265045', $fresh->vat_number);
        $this->assertSame(['siret' => '73282932000074'], $fresh->legal_identifiers);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Location/LocationTaxFieldsModelTest.php`
Expected: FAIL — mass-assignment ignores the new fields (tax_id null) and/or `legal_identifiers` returns a string not an array.

- [ ] **Step 3: Edit `Location.php`**

In `$fillable` (after `'address_country',`):
```php
        'address_country',
        'tax_id',
        'vat_number',
        'legal_identifiers',
        'latitude',
```

In `casts()` (add the jsonb cast):
```php
            'pos_enabled' => 'boolean',
            'legal_identifiers' => 'array',
        ];
```

In the docblock property list (after `@property string|null $address_country ...`):
```php
 * @property string|null $tax_id Per-branch tax number override (null ⇒ inherit company)
 * @property string|null $vat_number Per-branch VAT registration override (null ⇒ inherit company)
 * @property array<string, mixed>|null $legal_identifiers Per-branch legal identifiers (e.g. siret); null ⇒ inherit company
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && php artisan test tests/Feature/Location/LocationTaxFieldsModelTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Company/Domain/Location.php apps/api/tests/Feature/Location/LocationTaxFieldsModelTest.php
git commit -m "feat(branch-tax-id): Location model fillable/casts/docblock for tax fields"
```

🔍 **Opus review gate** — confirm cast is `array`, fillable order, docblock types match.

---

## Task 3: Shared `CountryTaxNumberRules` + fiscal-parity test

**Files:**
- Create: `apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php`
- Test: `apps/api/tests/Unit/Shared/CountryTaxNumberRulesTest.php`

> Single source of truth for tax-number formats. Holds the SAME patterns as `FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS`; a reflection parity test prevents drift WITHOUT modifying the fiscal validator (keeps Phase 1 fiscally inert). Matches the fiscal TN normalization (strip `/ -` + whitespace, uppercase).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CountryTaxNumberRulesTest extends TestCase
{
    public function test_matches_fr_siren_and_siret(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('FR', '732829320'));        // 9-digit SIREN
        $this->assertTrue(CountryTaxNumberRules::matches('FR', '73282932000074'));   // 14-digit SIRET
        $this->assertFalse(CountryTaxNumberRules::matches('FR', '1234'));
    }

    public function test_matches_tn_compact_matricule_two_letters(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('TN', '1234567AM000'));
        $this->assertTrue(CountryTaxNumberRules::matches('TN', '1234567/A/M/000')); // normalized
        $this->assertFalse(CountryTaxNumberRules::matches('TN', '1234567A000'));     // 1 letter -> reject
    }

    public function test_unknown_country_is_permissive(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('XX', 'anything'));
    }

    public function test_patterns_stay_in_parity_with_fiscal_validator(): void
    {
        $ref = new ReflectionClass(FiscalPayloadConstraintValidator::class);
        /** @var array<string,string> $fiscal */
        $fiscal = $ref->getConstant('TAX_NUMBER_PATTERNS');

        foreach ($fiscal as $country => $pattern) {
            $this->assertArrayHasKey($country, CountryTaxNumberRules::PATTERNS,
                "Shared rules missing fiscal country {$country}");
            $this->assertSame($pattern, CountryTaxNumberRules::PATTERNS[$country],
                "Pattern drift for {$country} vs fiscal validator");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Unit/Shared/CountryTaxNumberRulesTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create `CountryTaxNumberRules.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\Validation;

/**
 * Canonical per-country tax-number formats.
 *
 * MUST stay in parity with FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS
 * (enforced by CountryTaxNumberRulesTest). Entry-time Location validation uses
 * this so Phase 1 can never reject a value the device/fiscal path must author.
 */
final class CountryTaxNumberRules
{
    /** @var array<string,string> */
    public const PATTERNS = [
        'FR' => '/^([0-9]{9}|[0-9]{14})$/D',
        'TN' => '/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D',
        'SA' => '/^3[0-9]{12}03$/D',
        'DE' => '/^DE[0-9]{9}$/D',
        'IT' => '/^[0-9]{11}$/D',
    ];

    /**
     * True when no pattern is known for the country (permissive) or the
     * normalized value matches the country's pattern.
     */
    public static function matches(string $countryCode, string $value): bool
    {
        $country = strtoupper($countryCode);
        $pattern = self::PATTERNS[$country] ?? null;
        if ($pattern === null) {
            return true;
        }

        return preg_match($pattern, self::normalize($country, $value)) === 1;
    }

    private static function normalize(string $country, string $value): string
    {
        $normalized = strtoupper(trim($value));
        if ($country === 'TN') {
            // Slash/dash form 1234567/A/M/000 -> compact 1234567AM000.
            $normalized = (string) preg_replace('/[\s\/\-]/', '', $normalized);
        }

        return $normalized;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && php artisan test tests/Unit/Shared/CountryTaxNumberRulesTest.php`
Expected: PASS (all 4, including parity).

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php apps/api/tests/Unit/Shared/CountryTaxNumberRulesTest.php
git commit -m "feat(branch-tax-id): shared CountryTaxNumberRules with fiscal-parity test"
```

🔍 **Opus review gate** — confirm patterns are byte-identical to fiscal const; TN normalization matches the fiscal normalizer; parity test reads the fiscal const via reflection (not a copy).

---

## Task 4: Country tax-identity config + reader

**Files:**
- Create: `apps/api/config/tax_identity.php`
- Create: `apps/api/app/Modules/Company/Application/Services/CountryTaxIdentityConfig.php`
- Test: `apps/api/tests/Unit/Company/CountryTaxIdentityConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Company;

use App\Modules\Company\Application\Services\CountryTaxIdentityConfig;
use Tests\TestCase;

class CountryTaxIdentityConfigTest extends TestCase
{
    public function test_structural_countries_require_branch_tax_id(): void
    {
        $config = new CountryTaxIdentityConfig();
        $this->assertTrue($config->isBranchTaxIdRequired('FR'));
        $this->assertTrue($config->isBranchTaxIdRequired('TN'));
    }

    public function test_single_vat_countries_do_not_require_branch_tax_id(): void
    {
        $config = new CountryTaxIdentityConfig();
        $this->assertFalse($config->isBranchTaxIdRequired('IT'));
        $this->assertFalse($config->isBranchTaxIdRequired('AE'));
        $this->assertFalse($config->isBranchTaxIdRequired('XX')); // unknown -> not required
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/api && php artisan test tests/Unit/Company/CountryTaxIdentityConfigTest.php`
Expected: FAIL — class/config missing.

- [ ] **Step 3: Create the config + reader**

`config/tax_identity.php`:
```php
<?php

declare(strict_types=1);

// Per-country establishment-ID mode (Doc 05 §5) + whether a sellable branch
// must carry its own tax number. mode: structural | separate-linked | branch-code | none.
return [
    'countries' => [
        'FR' => ['mode' => 'structural', 'branch_tax_id_required' => true],
        'TN' => ['mode' => 'structural', 'branch_tax_id_required' => true],
        'MA' => ['mode' => 'structural', 'branch_tax_id_required' => true],
        'DZ' => ['mode' => 'separate-linked', 'branch_tax_id_required' => false],
        'IT' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'ES' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'DE' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'AE' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'GB' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'SA' => ['mode' => 'branch-code', 'branch_tax_id_required' => false],
        'EG' => ['mode' => 'branch-code', 'branch_tax_id_required' => false],
    ],
    'default' => ['mode' => 'none', 'branch_tax_id_required' => false],
];
```

`app/Modules/Company/Application/Services/CountryTaxIdentityConfig.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

final class CountryTaxIdentityConfig
{
    public function isBranchTaxIdRequired(string $countryCode): bool
    {
        return (bool) $this->forCountry($countryCode)['branch_tax_id_required'];
    }

    public function mode(string $countryCode): string
    {
        return (string) $this->forCountry($countryCode)['mode'];
    }

    /** @return array{mode:string, branch_tax_id_required:bool} */
    private function forCountry(string $countryCode): array
    {
        /** @var array<string, array{mode:string, branch_tax_id_required:bool}> $countries */
        $countries = config('tax_identity.countries', []);
        /** @var array{mode:string, branch_tax_id_required:bool} $default */
        $default = config('tax_identity.default', ['mode' => 'none', 'branch_tax_id_required' => false]);

        return $countries[strtoupper($countryCode)] ?? $default;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd apps/api && php artisan test tests/Unit/Company/CountryTaxIdentityConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/config/tax_identity.php apps/api/app/Modules/Company/Application/Services/CountryTaxIdentityConfig.php apps/api/tests/Unit/Company/CountryTaxIdentityConfigTest.php
git commit -m "feat(branch-tax-id): per-country tax-identity config + reader"
```

🔍 **Opus review gate** — confirm config matches Doc 05 §5 modes; default is none/not-required.

---

## Task 5: `CreateLocationRequest` — add fields, format validation, conditional-required

**Files:**
- Modify: `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php`
- Test: `apps/api/tests/Feature/Location/CreateLocationTaxValidationTest.php`

> Required when: type is `shop` AND the location country (`address_country`, fallback handled at controller/company level — at create time we use the submitted `address_country`; if absent we do NOT hard-require, since country is unknown) is a `branch_tax_id_required` country. Format-validated via `CountryTaxNumberRules` when present.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

// ... reuse the setUp() pattern from tests/Feature/Location/LocationTest.php
// (Tenant + Company[country_code FR] + admin user + membership + Sanctum auth).

class CreateLocationTaxValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    // setUp() identical to LocationTest::setUp() — tenant, FR company, admin user, actingAs.

    public function test_rejects_malformed_fr_tax_id(): void
    {
        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Branch A', 'type' => 'shop', 'address_country' => 'FR',
            'tax_id' => '123', // not 9 or 14 digits
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_accepts_valid_fr_siret(): void
    {
        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Branch A', 'type' => 'shop', 'address_country' => 'FR',
            'tax_id' => '73282932000074',
        ]);
        $response->assertStatus(201);
    }

    public function test_requires_tax_id_for_sellable_shop_in_fr(): void
    {
        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Branch A', 'type' => 'shop', 'address_country' => 'FR',
            // tax_id omitted
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_does_not_require_tax_id_for_warehouse(): void
    {
        $response = $this->postJson('/api/v1/locations', [
            'name' => 'WH', 'type' => 'warehouse', 'address_country' => 'FR',
        ]);
        $response->assertStatus(201);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Location/CreateLocationTaxValidationTest.php`
Expected: FAIL — fields not validated; malformed accepted; required-shop not enforced.

- [ ] **Step 3: Edit `CreateLocationRequest.php`**

Add `use` imports at top:
```php
use App\Modules\Company\Application\Services\CountryTaxIdentityConfig;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use Closure;
```

Add to `rules()` (after `'pos_enabled' => ...`):
```php
            'pos_enabled' => ['nullable', 'boolean'],
            'tax_id' => [
                'nullable', 'string', 'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $country = strtoupper((string) ($this->input('address_country') ?? ''));
                    if (is_string($value) && $value !== '' && $country !== ''
                        && ! CountryTaxNumberRules::matches($country, $value)) {
                        $fail('The branch tax ID format is invalid for '.$country.'.');
                    }
                },
            ],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'legal_identifiers' => ['nullable', 'array'],
```

Add a `withValidator()` method for the conditional-required rule (sellable shop in a requiring country):
```php
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $type = (string) $this->input('type');
            $country = strtoupper((string) ($this->input('address_country') ?? ''));
            if ($type !== 'shop' || $country === '') {
                return;
            }
            if ((new CountryTaxIdentityConfig())->isBranchTaxIdRequired($country)
                && in_array($this->input('tax_id'), [null, ''], true)) {
                $validator->errors()->add('tax_id',
                    'A branch tax ID is required for a sellable shop in '.$country.'.');
            }
        });
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd apps/api && php artisan test tests/Feature/Location/CreateLocationTaxValidationTest.php`
Expected: PASS (all 4).

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php apps/api/tests/Feature/Location/CreateLocationTaxValidationTest.php
git commit -m "feat(branch-tax-id): CreateLocationRequest tax-field validation + conditional-required"
```

🔍 **Opus review gate** — confirm: format check only when present+country known; required only for shop+requiring-country; warehouse/unknown-country never blocked; uses the shared rules (not the Partner validator). Note: `CountryTaxIdentityConfig` is `new`'d in the request (Laravel requests aren't constructor-injectable) — acceptable for a stateless config reader; flag if a cleaner seam is wanted.

---

## Task 6: `UpdateLocationRequest` — add fields + format validation

**Files:**
- Modify: `apps/api/app/Modules/Company/Presentation/Requests/UpdateLocationRequest.php`
- Test: `apps/api/tests/Feature/Location/UpdateLocationTaxValidationTest.php`

- [ ] **Step 1: Write the failing test**

```php
// setUp() as in LocationTest; create a shop location, then PATCH it.
public function test_rejects_malformed_tax_id_on_update(): void
{
    $loc = \App\Modules\Company\Domain\Location::create([
        'company_id' => $this->company->id, 'name' => 'B', 'code' => 'B',
        'type' => \App\Modules\Company\Domain\Enums\LocationType::Shop,
        'address_country' => 'FR', 'is_default' => false, 'is_active' => true,
        'tax_id' => '73282932000074',
    ]);
    $response = $this->patchJson("/api/v1/locations/{$loc->id}", ['tax_id' => 'BAD']);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors('tax_id');
}

public function test_accepts_clearing_tax_id_to_inherit(): void
{
    $loc = \App\Modules\Company\Domain\Location::create([
        'company_id' => $this->company->id, 'name' => 'B', 'code' => 'B',
        'type' => \App\Modules\Company\Domain\Enums\LocationType::Warehouse,
        'address_country' => 'FR', 'is_default' => false, 'is_active' => true,
    ]);
    $response = $this->patchJson("/api/v1/locations/{$loc->id}", ['tax_id' => null]);
    $response->assertStatus(200);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Location/UpdateLocationTaxValidationTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `UpdateLocationRequest.php`**

Add the same imports (`CountryTaxNumberRules`, `Closure`). Add to `rules()`:
```php
            'pos_enabled' => ['sometimes', 'boolean'],
            'tax_id' => [
                'sometimes', 'nullable', 'string', 'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // On update the country may be on the existing row; validate only
                    // against the submitted address_country when present (else skip format check).
                    $country = strtoupper((string) ($this->input('address_country') ?? ''));
                    if (is_string($value) && $value !== '' && $country !== ''
                        && ! CountryTaxNumberRules::matches($country, $value)) {
                        $fail('The branch tax ID format is invalid for '.$country.'.');
                    }
                },
            ],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'legal_identifiers' => ['sometimes', 'nullable', 'array'],
```

> Note: update does not re-enforce conditional-required (a branch can be edited without re-supplying); the create gate is the enforcement point. If business wants update to also block clearing a required shop's tax_id, add it in a follow-up — out of scope here.

- [ ] **Step 4: Run to verify it passes**

Run: `cd apps/api && php artisan test tests/Feature/Location/UpdateLocationTaxValidationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Company/Presentation/Requests/UpdateLocationRequest.php apps/api/tests/Feature/Location/UpdateLocationTaxValidationTest.php
git commit -m "feat(branch-tax-id): UpdateLocationRequest tax-field validation"
```

🔍 **Opus review gate** — confirm symmetry with create; clearing to null allowed.

---

## Task 7: `LocationController::store()` — whitelist new fields

**Files:**
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php` (`store()` `:81-118`)
- Test: `apps/api/tests/Feature/Location/CreateLocationTaxPersistenceTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_store_persists_tax_fields(): void
{
    $response = $this->postJson('/api/v1/locations', [
        'name' => 'Branch A', 'type' => 'shop', 'address_country' => 'FR',
        'tax_id' => '73282932000074',
        'vat_number' => 'FR40303265045',
        'legal_identifiers' => ['siret' => '73282932000074'],
    ]);
    $response->assertStatus(201);
    $response->assertJsonPath('data.tax_id', '73282932000074');

    $loc = \App\Modules\Company\Domain\Location::where('company_id', $this->company->id)
        ->where('name', 'Branch A')->first();
    $this->assertSame('FR40303265045', $loc->vat_number);
    $this->assertSame(['siret' => '73282932000074'], $loc->legal_identifiers);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Location/CreateLocationTaxPersistenceTest.php`
Expected: FAIL — `store()` whitelist drops the fields (vat_number null, legal_identifiers null), and `data.tax_id` missing.

- [ ] **Step 3: Edit `store()`** — add to the `Location::create([...])` array (after `'address_country' => ...`):
```php
            'address_country' => $validated['address_country'] ?? null,
            'tax_id' => $validated['tax_id'] ?? null,
            'vat_number' => $validated['vat_number'] ?? null,
            'legal_identifiers' => $validated['legal_identifiers'] ?? null,
```

(The `update()` method mass-applies `$validated`, so it already picks up the new fields once they're in the request rules — no change needed there.)

- [ ] **Step 4: Run to verify it passes** — also passes only after Task 8 adds `tax_id` to the resource (the `data.tax_id` assertion). Implement Task 8 in the same task window if the JSON-path assertion fails for that reason; otherwise split the assertion.

Run: `cd apps/api && php artisan test tests/Feature/Location/CreateLocationTaxPersistenceTest.php`
Expected: DB asserts PASS; `data.tax_id` path passes after Task 8.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php apps/api/tests/Feature/Location/CreateLocationTaxPersistenceTest.php
git commit -m "feat(branch-tax-id): persist tax fields in LocationController::store"
```

🔍 **Opus review gate** — confirm store() whitelist updated; update() relies on mass-assign + fillable (Task 2); no double-handling.

---

## Task 8: `LocationResource` — expose new fields

**Files:**
- Modify: `apps/api/app/Modules/Company/Presentation/Resources/LocationResource.php` (`:21-41`)
- Test: covered by Task 7's `data.tax_id` assertion; add a focused resource assertion.

- [ ] **Step 1: Write the failing assertion** (append to `CreateLocationTaxPersistenceTest`):
```php
public function test_resource_exposes_all_tax_fields(): void
{
    $response = $this->postJson('/api/v1/locations', [
        'name' => 'Branch B', 'type' => 'shop', 'address_country' => 'FR',
        'tax_id' => '73282932000074', 'vat_number' => 'FR40303265045',
        'legal_identifiers' => ['siret' => '73282932000074'],
    ]);
    $response->assertJsonPath('data.vat_number', 'FR40303265045');
    $response->assertJsonPath('data.legal_identifiers.siret', '73282932000074');
}
```

- [ ] **Step 2: Run to verify it fails** — keys absent.

- [ ] **Step 3: Edit `toArray()`** — add (after `'address_country' => $this->address_country,`):
```php
            'address_country' => $this->address_country,
            'tax_id' => $this->tax_id,
            'vat_number' => $this->vat_number,
            'legal_identifiers' => $this->legal_identifiers,
```

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Company/Presentation/Resources/LocationResource.php apps/api/tests/Feature/Location/CreateLocationTaxPersistenceTest.php
git commit -m "feat(branch-tax-id): expose tax fields in LocationResource"
```

🔍 **Opus review gate.**

---

## Task 9: `TaxIdentityResolver` + `TaxIdentityData` DTO

**Files:**
- Create: `apps/api/app/Shared/Contracts/Company/TaxIdentityData.php`
- Create: `apps/api/app/Modules/Company/Application/Services/TaxIdentityResolver.php`
- Test: `apps/api/tests/Feature/Company/TaxIdentityResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional]);

        return Company::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'legal_name' => 'C LLC',
            'tax_id' => 'COMPANY-TAX', 'vat_number' => 'COMPANY-VAT',
            'legal_identifiers' => ['siret' => 'COMPANY-SIRET'],
            'country_code' => 'FR', 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris',
            'currency' => 'EUR', 'status' => CompanyStatus::Active,
        ]);
    }

    public function test_branch_value_overrides_company_per_field(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id, 'name' => 'B', 'code' => 'B',
            'type' => LocationType::Shop, 'is_default' => false, 'is_active' => true,
            'tax_id' => 'BRANCH-TAX', 'legal_identifiers' => ['siret' => 'BRANCH-SIRET'],
            // vat_number intentionally null -> inherit
        ]);

        $resolved = (new TaxIdentityResolver())->resolve($location);

        $this->assertSame('BRANCH-TAX', $resolved->taxId);
        $this->assertSame('COMPANY-VAT', $resolved->vatNumber);       // inherited
        $this->assertSame('BRANCH-SIRET', $resolved->legalIdentifiers['siret']);
        $this->assertSame('FR', $resolved->countryCode);
    }

    public function test_all_null_inherits_company(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id, 'name' => 'B2', 'code' => 'B2',
            'type' => LocationType::Warehouse, 'is_default' => false, 'is_active' => true,
        ]);

        $resolved = (new TaxIdentityResolver())->resolve($location);

        $this->assertSame('COMPANY-TAX', $resolved->taxId);
        $this->assertSame('COMPANY-VAT', $resolved->vatNumber);
        $this->assertSame('COMPANY-SIRET', $resolved->legalIdentifiers['siret']);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — classes missing.

- [ ] **Step 3: Create DTO + resolver**

`app/Shared/Contracts/Company/TaxIdentityData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Company;

final class TaxIdentityData
{
    /** @param array<string, mixed> $legalIdentifiers */
    public function __construct(
        public readonly ?string $taxId,
        public readonly ?string $vatNumber,
        public readonly array $legalIdentifiers,
        public readonly ?string $countryCode,
    ) {}
}
```

`app/Modules/Company/Application/Services/TaxIdentityResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Shared\Contracts\Company\TaxIdentityData;

/**
 * Single source for resolving a sellable branch's tax identity with per-field
 * fallback to the parent company. Used by every server output site; the POS
 * device mirrors the identical fallback rule on its local terminal.location.
 */
final class TaxIdentityResolver
{
    public function resolve(Location $location): TaxIdentityData
    {
        $location->loadMissing('company');
        $company = $location->company;

        /** @var array<string,mixed> $companyLegal */
        $companyLegal = $company?->legal_identifiers ?? [];
        /** @var array<string,mixed> $locationLegal */
        $locationLegal = $location->legal_identifiers ?? [];

        return new TaxIdentityData(
            taxId: $location->tax_id ?? $company?->tax_id,
            vatNumber: $location->vat_number ?? $company?->vat_number,
            // location keys override company keys; missing keys inherit.
            legalIdentifiers: array_merge($companyLegal, $locationLegal),
            countryCode: $location->address_country ?? $company?->country_code,
        );
    }
}
```

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Shared/Contracts/Company/TaxIdentityData.php apps/api/app/Modules/Company/Application/Services/TaxIdentityResolver.php apps/api/tests/Feature/Company/TaxIdentityResolverTest.php
git commit -m "feat(branch-tax-id): TaxIdentityResolver + TaxIdentityData DTO"
```

🔍 **Opus review gate** — confirm per-field fallback; legal_identifiers key-merge semantics (location overrides, missing inherit); DTO placed in Shared/Contracts for cross-module use; no `app()`.

---

## Task 10: Receipt PDF — resolve seller tax id per location

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php` (constructor-inject resolver; `prepareData()` `:140-173`)
- Modify: `apps/api/resources/views/pos/receipt.blade.php` (`:328` tax_id line)
- Test: `apps/api/tests/Feature/POS/ReceiptPdfBranchTaxIdTest.php`

- [ ] **Step 1: Write the failing test** — assert the rendered receipt shows the branch tax id when the receipt's location overrides it. (Test the resolved value passed to the view via a small accessor, or render and assert HTML contains the branch SIRET. Follow the existing ReceiptPdfService test style; assert on `prepareData` output through a render that includes the branch tax_id string.)

```php
// Arrange a company (tax_id COMPANY-TAX) + a shop location (tax_id BRANCH-TAX) +
// a finalized receipt at that location. Render and assert the PDF/view data
// uses BRANCH-TAX, not COMPANY-TAX.
public function test_receipt_uses_branch_tax_id_when_set(): void
{
    // ... build tenant/company/location(tax_id='BRANCH-TAX')/terminal/receipt
    $html = view('pos.receipt', app(\App\Modules\POS\Application\Services\ReceiptPdfService::class)
        ->viewDataFor($receipt))->render();
    $this->assertStringContainsString('BRANCH-TAX', $html);
    $this->assertStringNotContainsString('COMPANY-TAX', $html);
}
```

> Implementation note: expose a small `viewDataFor(Receipt): array` (refactor of the existing private `prepareData`) so the resolved data is unit-testable without DomPDF. If the team prefers not to widen the API, assert via the generated PDF text; pick one in review.

- [ ] **Step 2: Run to verify it fails** — blade still prints `$company->tax_id`.

- [ ] **Step 3: Implement**

Constructor-inject the resolver (follow the module's existing constructor-injection pattern):
```php
public function __construct(
    private readonly TaxIdentityResolver $taxIdentityResolver,
    // ... existing injected deps
) {}
```

In `prepareData()`, compute the resolved identity from the receipt's location and add to the returned array:
```php
$taxIdentity = $receipt->location !== null
    ? $this->taxIdentityResolver->resolve($receipt->location)
    : null;

return [
    'receipt' => $receipt,
    'company' => $company,
    'location' => $receipt->location,
    // resolved seller tax id: branch override, else company
    'sellerTaxId' => $taxIdentity?->taxId ?? $company->tax_id,
    // ... rest unchanged
];
```

In `receipt.blade.php`, change the tax_id line:
```blade
        @if(!empty($sellerTaxId ?? $company->tax_id))
            {{ __('pos.tax_id') }}: {{ $sellerTaxId ?? $company->tax_id }}<br>
        @endif
```

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php apps/api/resources/views/pos/receipt.blade.php apps/api/tests/Feature/POS/ReceiptPdfBranchTaxIdTest.php
git commit -m "feat(branch-tax-id): receipt PDF resolves seller tax id per branch"
```

🔍 **Opus review gate** — confirm resolver injected (not `app()`); blade falls back to company when null; test asserts branch-over-company.

---

## Task 11: FacturX — resolve seller block from the document's location

**Files:**
- Modify: `apps/api/app/Modules/Document/Application/Services/FacturXService.php` (`:69-83` load, `:110-145` setSellerInformation)
- Test: `apps/api/tests/Feature/Document/FacturXBranchSellerTest.php`

- [ ] **Step 1: Write the failing test** — a document with `location_id` whose location overrides `vat_number`/`tax_id`/`siret` produces FacturX XML containing the branch values (VA/FC/legal-org).

```php
public function test_facturx_seller_uses_branch_identity(): void
{
    // company vat=COMPANY-VAT tax_id=COMPANY-FC siret=COMPANY-SIRET;
    // location vat=BR-VAT tax_id=BR-FC legal_identifiers.siret=BR-SIRET;
    // document at that location.
    $xml = app(\App\Modules\Document\Application\Services\FacturXService::class)->generateXml($document);
    $this->assertStringContainsString('BR-VAT', $xml);
    $this->assertStringContainsString('BR-FC', $xml);
    $this->assertStringContainsString('BR-SIRET', $xml);
    $this->assertStringNotContainsString('COMPANY-FC', $xml);
}
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement** — load `location`, resolve, feed the resolved values:

In `generateXml()`:
```php
$document->loadMissing(['company', 'partner', 'lines', 'location']);
// ...
$this->setSellerInformation($builder, $company, $document->location);
```

Change `setSellerInformation()` signature + body to use the resolver when a location exists (fallback to company-only when null):
```php
private function setSellerInformation(ZugferdDocumentBuilder $builder, Company $company, ?Location $location): void
{
    $identity = $location !== null
        ? $this->taxIdentityResolver->resolve($location)
        : new TaxIdentityData($company->tax_id, $company->vat_number, $company->legal_identifiers ?? [], $company->country_code);

    $builder->setDocumentSeller($company->legal_name ?? $company->name);
    $builder->setDocumentSellerAddress(
        $company->address_street, $company->address_street_2, null,
        $company->address_postal_code, $company->address_city, $company->country_code,
    );

    if ($identity->vatNumber !== null) {
        $builder->addDocumentSellerTaxRegistration('VA', $identity->vatNumber);
    }
    if ($identity->taxId !== null) {
        $builder->addDocumentSellerTaxRegistration('FC', $identity->taxId);
    }
    $siret = $identity->legalIdentifiers['siret'] ?? null;
    if (is_string($siret)) {
        $builder->setDocumentSellerLegalOrganisation($siret, '0002', $company->legal_name ?? $company->name);
    }
    if ($company->email !== null || $company->phone !== null) {
        $builder->setDocumentSellerContact(null, null, $company->phone, null, $company->email);
    }
}
```

Constructor-inject `TaxIdentityResolver` into `FacturXService`. Seller name/address stay company-level (per spec — branch address override out of scope).

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Document/Application/Services/FacturXService.php apps/api/tests/Feature/Document/FacturXBranchSellerTest.php
git commit -m "feat(branch-tax-id): FacturX seller block resolves branch identity"
```

🔍 **Opus review gate** — confirm null-location fallback to company; VA/FC/legal-org all sourced from resolver; cross-module DTO import is allowed (Shared/Contracts).

---

## Task 12: NF525 `buildCompanyHeader` — fix the null-SIRET bug (company-level)

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` (`buildCompanyHeader` `:524-542`)
- Test: `apps/api/tests/Feature/POS/Nf525CompanyHeaderSiretTest.php`

> Company-level (the JET export is company-wide — no single location). Source `siret` from `legal_identifiers['siret'] ?? tax_id`; `address` from the company address parts. This fixes the latent magic-attribute null.

- [ ] **Step 1: Write the failing test**

```php
public function test_company_header_emits_siret_from_legal_identifiers_or_tax_id(): void
{
    // company with legal_identifiers.siret = '73282932000074' and address parts
    $header = (new \ReflectionClass($provider))->getMethod('buildCompanyHeader');
    $header->setAccessible(true);
    $data = $header->invoke($provider, $company);
    $this->assertSame('73282932000074', $data->siret);
    $this->assertNotNull($data->address);
}
```

- [ ] **Step 2: Run to verify it fails** — currently `siret` is null (magic attr).

- [ ] **Step 3: Implement**

```php
private function buildCompanyHeader(Company $company): Nf525CompanyHeaderData
{
    // NF525 JET <Societe> header. SIRET = company's establishment registration:
    // prefer legal_identifiers['siret'], fall back to tax_id. Address is composed
    // from the company address parts. (Previously read non-existent magic
    // attributes and emitted null — see branch-tax-id spec §5 #3.)
    $siret = $company->legal_identifiers['siret'] ?? $company->tax_id;
    $address = trim(implode(' ', array_filter([
        $company->address_street,
        $company->address_postal_code,
        $company->address_city,
    ])));

    return new Nf525CompanyHeaderData(
        id: (string) $company->id,
        name: $company->name,
        siret: is_string($siret) ? $siret : null,
        address: $address !== '' ? $address : null,
    );
}
```

(Remove the now-unused `readNullableString` calls for `siret`/`address` if they become dead — check via PHPStan.)

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php apps/api/tests/Feature/POS/Nf525CompanyHeaderSiretTest.php
git commit -m "fix(branch-tax-id): NF525 company header emits real SIRET/address (null-SIRET bug)"
```

🔍 **Opus review gate** — confirm company-level (not per-location); SIRET fallback order; no regression in other Nf525DataProvider tests (`php artisan test tests/Feature/POS` for the NF525 group).

---

## Task 13: Frontend — types, API shapes, transform

**Files:**
- Modify: `apps/web/src/features/locations/types.ts` (`Location` `:8-25`)
- Modify: `apps/web/src/features/location/api.ts` (`CreateLocationInput`, `UpdateLocationInput`, `CreateLocationPayload`, `UpdateLocationPayload`, `LocationApiResponse`, `transformLocationResponse`)
- Test: `apps/web/src/features/location/__tests__/transformLocationResponse.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest'
import { transformLocationResponse } from '../api'

describe('transformLocationResponse tax fields', () => {
  it('maps tax_id, vat_number, legal_identifiers to camelCase', () => {
    const out = transformLocationResponse({
      id: '1', company_id: 'c', name: 'B', code: 'B', type: 'shop',
      phone: null, email: null, address_street: null, address_city: null,
      address_postal_code: null, address_country: 'FR',
      tax_id: '73282932000074', vat_number: 'FR40303265045',
      legal_identifiers: { siret: '73282932000074' },
      is_default: false, is_active: true, pos_enabled: true,
      created_at: 'x', updated_at: 'y',
    } as never)
    expect(out.taxId).toBe('73282932000074')
    expect(out.vatNumber).toBe('FR40303265045')
    expect(out.legalIdentifiers).toEqual({ siret: '73282932000074' })
  })
})
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/web && pnpm test src/features/location/__tests__/transformLocationResponse.test.ts`
Expected: FAIL — `taxId` undefined.

- [ ] **Step 3: Implement**

`types.ts` `Location` — add after `addressCountry`:
```ts
  addressCountry: string | null
  taxId: string | null
  vatNumber: string | null
  legalIdentifiers: Record<string, unknown> | null
```

`api.ts` — add to `CreateLocationInput` and `UpdateLocationInput`:
```ts
  taxId?: string | undefined
  vatNumber?: string | undefined
  legalIdentifiers?: Record<string, unknown> | undefined
```
Add to `CreateLocationPayload`/`UpdateLocationPayload`:
```ts
  tax_id?: string | undefined
  vat_number?: string | undefined
  legal_identifiers?: Record<string, unknown> | undefined
```
Add to `LocationApiResponse` (the snake response type): `tax_id: string | null; vat_number: string | null; legal_identifiers: Record<string, unknown> | null`.
Add to `transformLocationResponse` return:
```ts
    addressCountry: response.address_country,
    taxId: response.tax_id,
    vatNumber: response.vat_number,
    legalIdentifiers: response.legal_identifiers,
```
And in the camel→snake mapping functions for create/update payloads, map `taxId→tax_id`, `vatNumber→vat_number`, `legalIdentifiers→legal_identifiers`.

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/locations/types.ts apps/web/src/features/location/api.ts apps/web/src/features/location/__tests__/transformLocationResponse.test.ts
git commit -m "feat(branch-tax-id): web Location types/api map tax fields"
```

🔍 **Opus review gate** — confirm all 4 shapes + response type + transform + both payload mappers updated (memory: missing a mapping shape silently drops fields).

---

## Task 14: Frontend — Location form field + conditional-required hint

**Files:**
- Modify: `apps/web/src/features/settings/LocationsPage.tsx` (`LocationFormData` `:28-39`, `emptyForm` `:41-52`, the form JSX, submit mapping)
- Test: component test asserting the tax-id input renders for a Shop and submits.

- [ ] **Step 1: Write the failing test** (Vitest + Testing Library; mock the API hook per AutoERP frontend test convention). Assert: a "Tax ID" input appears; when type=shop and country is FR, the field is marked required (use `t()` keys, no hardcoded strings).

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement** — add `taxId`, `vatNumber` to `LocationFormData` + `emptyForm` (`taxId: '', vatNumber: ''`); add labeled inputs using `t('locations.taxId')` / `t('locations.vatNumber')` (add the keys to the locations i18n namespace in all 3 places per the i18n convention); include them in the create/update submit mapping; show a required marker + helper text when `type==='shop'` and the selected country is in the required set (mirror backend; a small `BRANCH_TAX_REQUIRED_COUNTRIES = ['FR','TN','MA']` constant or fetch from config — keep client-side as a hint; the server is the enforcement).

- [ ] **Step 4: Run to verify it passes** (`pnpm test`, `pnpm lint`, `pnpm typecheck`).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/settings/LocationsPage.tsx apps/web/src/i18n/...
git commit -m "feat(branch-tax-id): Location settings form captures branch tax id"
```

🔍 **Opus review gate** — confirm no hardcoded strings (t() keys, i18n in 3 places), design tokens for any colors, required-hint mirrors server, fields submit through the camel→snake mapper.

---

## Phase 1 wrap

- [ ] **Run preflight:** `cd apps/api && ./vendor/bin/pint && ./vendor/bin/phpstan && php artisan test` ; `cd apps/web && pnpm lint && pnpm typecheck && pnpm test`. All green.
- [ ] **Phase-1 PR.** No fiscal payload touched. 🔍 Opus end-of-phase review against spec §4–§6.

---

# PHASE 2 — Device (own PR, no payload version bump)

## Task 15: `TerminalResource` — add tax fields to the location object (all endpoints)

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` (`location` block `:28-34`)
- Verify (no change unless missing): `TerminalController` endpoints load `location` (`show/index/claim/by-device/toggle-training/activate`).
- Test: `apps/api/tests/Feature/POS/TerminalResourceBranchTaxTest.php`

- [ ] **Step 1: Write the failing test** — a terminal whose location has tax fields serializes them inside `location`.

```php
public function test_terminal_resource_includes_location_tax_fields(): void
{
    // location tax_id='BRANCH-TAX' vat_number='BR-VAT' legal_identifiers.siret='BR-SIRET'
    $terminal->load('location');
    $array = (new TerminalResource($terminal))->toArray(request());
    $this->assertSame('BRANCH-TAX', $array['location']['tax_id']);
    $this->assertSame('BR-VAT', $array['location']['vat_number']);
    $this->assertSame('BR-SIRET', $array['location']['legal_identifiers']['siret']);
}
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement** — extend the `location` closure:
```php
        'location' => $this->whenLoaded('location', function () {
            return [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'code' => $this->location->code,
                'tax_id' => $this->location->tax_id,
                'vat_number' => $this->location->vat_number,
                'legal_identifiers' => $this->location->legal_identifiers,
            ];
        }),
```
Then verify every terminal endpoint the POS store consumes eager-loads `location` (add `->load('location')` where missing) so the device never caches a tax-less location.

- [ ] **Step 4: Run to verify it passes** (+ run the existing terminal-activation test to confirm no regression).

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php apps/api/tests/Feature/POS/TerminalResourceBranchTaxTest.php
git commit -m "feat(branch-tax-id): TerminalResource carries branch tax fields on location"
```

🔍 **Opus review gate** — confirm all terminal endpoints load `location` (m1); shape additive.

---

## Task 16: Device `terminalStore.ts` — extend `Terminal.location` type

**Files:**
- Modify: `apps/pos/src/stores/terminalStore.ts` (`Terminal.location` `:46-50`)
- Test: `apps/pos/src/stores/__tests__/terminalStore.branchTax.test.ts` (type/round-trip assertion through persist/rehydrate).

- [ ] **Step 1: Write the failing test** — a stored terminal with `location.tax_id` rehydrates with the field intact.

- [ ] **Step 2: Run to verify it fails** (`cd apps/pos && pnpm test`).

- [ ] **Step 3: Implement**:
```ts
  location: {
    id: string
    name: string
    code: string
    tax_id: string | null
    vat_number: string | null
    legal_identifiers: Record<string, unknown> | null
  }
```

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/stores/terminalStore.ts apps/pos/src/stores/__tests__/terminalStore.branchTax.test.ts
git commit -m "feat(branch-tax-id): device Terminal.location carries tax fields"
```

🔍 **Opus review gate.**

---

## Task 17: Device seller sourcing — prefer branch tax id (SALE_RECEIPT + ACCOUNT_PAYMENT)

**Files:**
- Modify: `apps/pos/src/stores/paymentStore.ts` (SALE_RECEIPT `:543-550`, ACCOUNT_PAYMENT `:654-661`)
- Test: `apps/pos/src/stores/__tests__/paymentStore.branchSeller.test.ts`

> ACCOUNT_CHARGE is OUT of P0 (`AccountChargePayload.ts:256` is a fixture; live builder has no production caller — spec §8). Document, do not patch.

- [ ] **Step 1: Write the failing test** — when the active terminal's `location.tax_id` is set, the built `seller.taxNumber` equals the branch value; when null, it falls back to the company value.

```ts
// Arrange: authStore company.tax_id='COMPANY-TAX'; terminalStore terminal.location.tax_id='BRANCH-TAX'.
// Act: build the SALE_RECEIPT seller block.
// Assert: seller.taxNumber === 'BRANCH-TAX'. Then set location.tax_id=null -> 'COMPANY-TAX'.
```

- [ ] **Step 2: Run to verify it fails** (`cd apps/pos && pnpm test`).

- [ ] **Step 3: Implement** — in both seller blocks, change the `taxNumber` line to prefer the terminal's branch value (the active terminal is available in this store scope as confirmed by the device-path map; read it from `useTerminalStore.getState().terminal`):

```ts
const branchTaxId = useTerminalStore.getState().terminal?.location?.tax_id ?? null

seller: {
  name: companyField(company, 'legalName', 'legal_name') ?? company?.name ?? null,
  taxNumber: branchTaxId ?? companyField(company, 'taxId', 'tax_id'),
  countryCode: companyField(company, 'countryCode', 'country_code'),
  street: companyField(company, 'addressStreet', 'address_street'),
  city: companyField(company, 'addressCity', 'address_city'),
  postalCode: companyField(company, 'addressPostalCode', 'address_postal_code'),
},
```

(Apply identically to the ACCOUNT_PAYMENT block. Confirm the import/access pattern for `useTerminalStore` matches the file's existing store-access idiom.)

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/stores/paymentStore.ts apps/pos/src/stores/__tests__/paymentStore.branchSeller.test.ts
git commit -m "feat(branch-tax-id): device sources seller.tax_number from branch (fallback company)"
```

🔍 **Opus review gate** — confirm branch-over-company fallback on BOTH SALE_RECEIPT and ACCOUNT_PAYMENT; ACCOUNT_CHARGE explicitly untouched; value-only change (no payload schema/version touched).

---

## Task 18: Cross-language parity + Phase-2 wrap

**Files:**
- Test: confirm `apps/pos/scripts/check-fiscal-fixture-parity.sh` still passes (fixtures unchanged — value-source change only).

- [ ] **Step 1:** Run the fiscal fixture parity check.

Run: `cd apps/pos && bash scripts/check-fiscal-fixture-parity.sh`
Expected: PASS — golden fixtures unchanged (no schema/version bump; §7).

- [ ] **Step 2:** Add a source-path test asserting the device prefers the branch value (covered by Task 17) and that the PHP validator accepts a branch-authored TN/FR value (a small feature test feeding a branch `seller.tax_number` through `FiscalPayloadConstraintValidator::validateSeller` — assert no exception).

- [ ] **Step 3: Commit** any test additions.

```bash
git add apps/pos/... apps/api/tests/Feature/Fiscal/BranchSellerTaxNumberValidationTest.php
git commit -m "test(branch-tax-id): cross-language source-path + validator acceptance for branch seller"
```

- [ ] **Step 4: Phase-2 PR.** 🔍 Opus end-of-phase review: value-only, no payload version bump, fixtures intact, both live seller paths sourced, ACCOUNT_CHARGE documented as out-of-scope.

---

## Self-review notes (plan author)

- **Spec coverage:** D1 (Task 1-2), D2 (Tasks 2/9), D3 (Task 1 nullable + Task 5 conditional), D4 (Tasks 3-6 fiscal-aligned validator + conditional-required), D5 (Task 1 no backfill), D6 (Tasks 17-18 value-only), D7 (not in plan — no numbering). §5 sites: receipt (10), FacturX (11), NF525 fix (12); TEJ/cert intentionally company-level (no task — documented in spec §5). Device (15-17).
- **Validator single-source:** Task 3 keeps parity by reflection test without touching fiscal code (preserves "Phase 1 no fiscal risk"). A later optional refactor to make the fiscal validator consume `CountryTaxNumberRules` is noted but out of P0.
- **Type consistency:** `TaxIdentityData{taxId,vatNumber,legalIdentifiers,countryCode}` used identically in Tasks 9/10/11; device fields `tax_id/vat_number/legal_identifiers` consistent across Tasks 15/16/17.
- **Open for executor:** exact `ReceiptPdfService` test seam (render-vs-accessor, Task 10) and the `useTerminalStore` access idiom in `paymentStore.ts` (Task 17) — both flagged for the implementing agent to match existing patterns; Opus confirms at the gate.
