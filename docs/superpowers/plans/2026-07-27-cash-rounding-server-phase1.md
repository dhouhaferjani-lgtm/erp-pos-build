# POS Cash Rounding + Tolerance — Server Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the complete server half (`apps/api`) of POS cash rounding + tender tolerance — config columns, policy resolver + device endpoint, two ops commands, the SALE_RECEIPT v3 payload contract, the v3-gated read-model/GL/Z surfaces, and the deploy artifacts — so that every new behavior is inert until Phase 2 flips the device on.

**Architecture:** Hexagonal per-module (Domain / Application / Infrastructure / Presentation) inside `apps/api/app/Modules/*`. The single cutover discriminator for ALL new read-model and ledger behavior is the persisted `fiscal_events.event_version >= 3` column; the payload contract is extended by a NAMED key-set constant + a version-threaded key-set accessor, never by mutating the v1/v2 constant. Money is bcmath decimal strings end-to-end; the signed cash-rounding denomination is a string-fidelity artifact that must survive every hop unmutated.

**Tech Stack:** Laravel 12 / PHP 8.2+ strict types, PostgreSQL 16 (database-per-tenant, `database/migrations/tenant/`), Horizon queue workers, Spatie LaravelData DTOs + `typescript:transform`, PHPUnit, PHPStan level 8, Pint.

## Global Constraints

- **Rule 19 (precision contract).** Never let a float touch money or a denomination. All arithmetic via `bcadd`/`bcsub`/`bcmul`/`bcdiv`/`bcmod`/`bccomp` on decimal strings. `CurrencyScale::bcformatStrict($value, $scale)` for at-rest normalization; never `bcformat($float, …)`.
- **Queued/console contexts carry NO `CompanyContext`.** `PosCoreReceiptProjection`, `ZReportProjection` and `TreasuryReceiptBridge` run on Horizon workers: always pass an explicit currency (`postEntryNow($entry, $user, $receipt->currency)`), resolve policy rows by direct tenant-DB query, and NEVER call a no-arg `getScale()` in those files.
- **Tests BY PATH only.** Never run the full PHPUnit suite (crashes the laptop). Every step names its exact `--filter`ed path invocation.
- **Bridge / netting / migration suites MUST run on Postgres.** The `pos_receipts_totals` CHECK and `repository_movements` `CHECK (amount > 0)` are pgsql-only and are invisible on SQLite; a green SQLite run proves nothing for those tasks.
- **All migrations must be self-guarding.** Pushing to `origin/dev` auto-deploys and runs `tenants:migrate`; a migration may not assume a seeder or backfill ran first. Guard with `Schema::hasTable` / `Schema::hasColumn` / `DB::connection()->getDriverName() === 'pgsql'` / `IF NOT EXISTS`.
- **ALL new §4.5/§4.6 behavior is gated on `event_version >= 3`.** v1/v2 events must project and post byte-identically to today, on first apply and on every replay.
- **Constructor injection only** — `private readonly` dependencies; never the `app()` helper.
- **Enums for all status/type columns**; no magic strings for statuses.
- **No scope creep beyond the spec.** `apps/pos` (device) is Plan B. The refund-rounding follow-up (§4.7 / §8.2) is a separate track and is NOT planned here. Do not "fix in passing" the training-GL gap, the void-GL gap, or the `PaymentToleranceQueryService` scale-3 hardcode — they are ticketed pre-existing debt.
- **Spec authority:** `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` (Rev 2.2). Every §1 fact there is code-verified; treat it as normative.

---

### Task 1: Migration A1 — `payment_methods.is_cash_tender` + wire-through + seeder

**Files:**
- Create `apps/api/database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php`
- Create `apps/api/tests/Feature/Treasury/PaymentMethodCashTenderTest.php`
- Modify `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php` (docblock `@property` list ends at line 47 `@property-read PaymentRepository|null $defaultRepository`; `protected $fillable` starts line 63)
- Modify `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php` (`store` validation lines 64-112, `PaymentMethod::create` lines 123-145; `update` validation lines 164-211, `$method->update($validated)` line 226; `formatMethod` 17-key allowlist lines 239-260)
- Modify `apps/api/database/seeders/PaymentMethodSeeder.php` (TN CASH entry lines 84-95; FR CASH entry near line 195; default CASH entry near line 354)

**Interfaces:**
- Produces: DB column `payment_methods.is_cash_tender BOOLEAN NOT NULL DEFAULT false`.
- Produces: `PaymentMethodController::formatMethod(PaymentMethod $method): array<string, mixed>` gains an `is_cash_tender` key (18 keys).
- Produces (invariant): `is_cash_tender = true ⇒ code = 'CASH'` EXACT, case-sensitive, enforced on `store` and `update`; `code` is uppercased on write.
- Consumes: `App\Modules\Treasury\Domain\PaymentMethod` (existing model, `HasUuids`).

**Steps:**

- [ ] Write the failing test file `apps/api/tests/Feature/Treasury/PaymentMethodCashTenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PaymentMethodCashTenderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cash Tender Tenant',
            'slug' => 'cash-tender-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Tender Shop',
            'legal_name' => 'Cash Tender Shop SARL',
            'tax_id' => 'TAX-CT-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('treasury.manage_payment_methods', 'sanctum');

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Treasury Admin',
            'email' => 'admin@cash-tender-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->admin->givePermissionTo('treasury.manage_payment_methods');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_column_exists_with_false_default(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_methods', 'is_cash_tender'));

        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $this->assertFalse($method->refresh()->is_cash_tender);
    }

    public function test_format_method_exposes_is_cash_tender(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/treasury/payment-methods');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('code', 'CASH');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('is_cash_tender', $row);
        $this->assertTrue($row['is_cash_tender']);
    }

    public function test_store_rejects_cash_tender_on_non_cash_code(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/treasury/payment-methods', [
                'code' => 'meal_voucher',
                'name' => 'Ticket Restaurant',
                'is_physical' => true,
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_uppercases_code_and_accepts_cash(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/treasury/payment-methods', [
                'code' => 'cash',
                'name' => 'Espèces',
                'is_physical' => true,
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(201);
        $this->assertSame('CASH', $response->json('data.code'));
        $this->assertTrue($response->json('data.is_cash_tender'));
    }

    public function test_update_rejects_flipping_cash_tender_on_non_cash_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/treasury/payment-methods/'.$method->id, [
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_migration_backfills_existing_cash_rows(): void
    {
        // Simulate a brownfield lowercase code by writing past the controller.
        $id = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ])->id;

        \Illuminate\Support\Facades\DB::table('payment_methods')
            ->where('id', $id)
            ->update(['code' => 'cash', 'is_cash_tender' => false]);

        // Re-run the backfill statement the migration performs.
        \Illuminate\Support\Facades\DB::statement(
            "UPDATE payment_methods SET is_cash_tender = true, code = 'CASH' WHERE UPPER(code) = 'CASH'"
        );

        $row = \Illuminate\Support\Facades\DB::table('payment_methods')->where('id', $id)->first();
        $this->assertSame('CASH', $row->code);
        $this->assertTrue((bool) $row->is_cash_tender);
    }
}
```

- [ ] Run it and confirm it FAILS (column absent / key missing):
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/PaymentMethodCashTenderTest.php`
  Expected: errors on `Schema::hasColumn` false and `Unknown column is_cash_tender`.

- [ ] Create the migration `apps/api/database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration A1.
     *
     * ONE cash-ness predicate for every layer (spec §4.1): a tender leg is
     * cash iff its payment method carries `is_cash_tender = true`. The
     * invariant `is_cash_tender = true => code = 'CASH'` (EXACT,
     * case-sensitive) is enforced on write by PaymentMethodController; this
     * migration establishes it for existing rows by normalizing the code at
     * the same time it sets the flag.
     *
     * Self-guarding: the table may not exist on a partially-provisioned
     * tenant DB, and the column may already exist on a re-run.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->boolean('is_cash_tender')
                    ->default(false)
                    ->after('is_physical');
            });
        }

        // Backfill + code normalization in one statement so no row can end
        // up flagged with a non-canonical code.
        DB::statement(
            "UPDATE payment_methods SET is_cash_tender = true, code = 'CASH' WHERE UPPER(code) = 'CASH'"
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "COMMENT ON COLUMN payment_methods.is_cash_tender IS ".
                "'Canonical cash-ness predicate for POS rounding/tolerance. TRUE implies code = ''CASH'' exactly.'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_methods') || ! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            return;
        }

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('is_cash_tender');
        });
    }
};
```

- [ ] Add the property + fillable + cast to `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php`. Insert `@property bool $is_cash_tender` immediately after the existing `@property bool $is_physical` line; add `'is_cash_tender',` to `$fillable` immediately after `'is_physical',`; add `'is_cash_tender' => 'boolean',` to the model's casts array beside `'is_physical' => 'boolean'`.

- [ ] Wire `formatMethod` in `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php` — add the key immediately after `'is_physical'`:

```php
            'is_physical' => $method->is_physical,
            'is_cash_tender' => $method->is_cash_tender,
```

- [ ] Add validation + the invariant to `store()`. In the `$request->validate([...])` array add `'is_cash_tender' => ['nullable', 'boolean'],` after the `'is_physical'` rule. Then, immediately before the `PaymentMethod::create([...])` call, insert:

```php
        $code = strtoupper(trim((string) $validated['code']));
        $isCashTender = (bool) ($validated['is_cash_tender'] ?? false);
        $this->assertCashTenderInvariant($code, $isCashTender);
```

  and change the create payload's `'code' => $validated['code'],` to `'code' => $code,` and add `'is_cash_tender' => $isCashTender,` after `'is_physical' => ...`.

- [ ] Add validation + the invariant to `update()`. Add `'is_cash_tender' => ['sometimes', 'boolean'],` after the `'is_physical'` rule. Then, immediately before `$method->update($validated);`, insert:

```php
        $finalCodeUpper = strtoupper(trim((string) ($validated['code'] ?? $method->code)));
        $finalIsCashTender = array_key_exists('is_cash_tender', $validated)
            ? (bool) $validated['is_cash_tender']
            : $method->is_cash_tender;
        $this->assertCashTenderInvariant($finalCodeUpper, $finalIsCashTender);

        if (array_key_exists('code', $validated)) {
            $validated['code'] = $finalCodeUpper;
        }
```

- [ ] Add the private helper to the same controller, immediately after `assertValidInstrumentConfiguration()`:

```php
    /**
     * Spec §4.1 invariant: `is_cash_tender = true` implies `code = 'CASH'`
     * EXACT (case-sensitive). The device Z aggregation matches
     * `method_code === 'CASH'` case-sensitively while the server matches
     * `UPPER(code)`; only an exact-code invariant makes all three predicates
     * provably coincide. Custom cash methods are a separate ticket.
     */
    private function assertCashTenderInvariant(string $upperCode, bool $isCashTender): void
    {
        if ($isCashTender && $upperCode !== 'CASH') {
            throw ValidationException::withMessages([
                'is_cash_tender' => 'Only the payment method with code CASH may be flagged as a cash tender.',
            ]);
        }
    }
```

- [ ] Update `apps/api/database/seeders/PaymentMethodSeeder.php`: add `'is_cash_tender' => true,` immediately after `'is_physical' => true,` in each of the three `'code' => 'CASH'` entries (Tunisia ~line 86, France ~line 195, default ~line 354). Leave every other method untouched — `MEAL_VOUCHER` stays non-cash.

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/PaymentMethodCashTenderTest.php`
  Expected: 6 tests green.

- [ ] Run Pint + PHPStan on the touched files:
  `cd apps/api && ./vendor/bin/pint app/Modules/Treasury database/seeders/PaymentMethodSeeder.php database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php && ./vendor/bin/phpstan analyse app/Modules/Treasury --no-progress`

- [ ] Commit:

```bash
git add apps/api/database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php apps/api/app/Modules/Treasury/Domain/PaymentMethod.php apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php apps/api/database/seeders/PaymentMethodSeeder.php apps/api/tests/Feature/Treasury/PaymentMethodCashTenderTest.php
git commit -m "$(cat <<'EOF'
Cash rounding P1 T1: payment_methods.is_cash_tender + exact-code invariant

Adds the single cash-ness predicate (spec §4.1) with backfill, seeder
update, and the full server wire-through: formatMethod allowlist key,
store/update validation enforcing is_cash_tender => code = 'CASH' exact,
and uppercase-on-write code normalization.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Migration A2 — `country_payment_settings` rounding + POS tolerance columns, TN upsert, self-healing seeder

**Files:**
- Create `apps/api/database/migrations/tenant/2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php`
- Create `apps/api/database/seeders/CountryPaymentSettingsSeeder.php`
- Create `apps/api/tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php`
- Modify `apps/api/app/Modules/Treasury/Domain/CountryPaymentSettings.php` (`$fillable` lines 18-30, `$casts` lines 32-38)
- Modify `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php` (`use Database\Seeders\CountriesSeeder;` line 17; `seedReferenceData()` body lines 187-191)
- Modify `apps/api/database/seeders/ProductionSeeder.php` (countries call at line 33)

**Interfaces:**
- Produces: `country_payment_settings.cash_rounding_enabled BOOLEAN NOT NULL DEFAULT false`, `.cash_rounding_denomination DECIMAL(15,4) NULL`, `.pos_tolerance_enabled BOOLEAN NOT NULL DEFAULT false`.
- Produces: TN row upserted with `payment_tolerance_enabled = true`, `payment_tolerance_percentage = 0.0050`, `max_payment_tolerance_amount = 0.100`, `pos_tolerance_enabled = false`, `cash_rounding_enabled = false`, `cash_rounding_denomination = 0.0500`, and an explicit `(string) Str::uuid()` id.
- Produces: `Database\Seeders\CountryPaymentSettingsSeeder::run(): void` — idempotent `updateOrInsert` per supported country, safe to call whenever `countries` is already populated.
- Consumes: `App\Modules\Treasury\Domain\CountryPaymentSettings` (existing model).

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Database\Seeders\CountryPaymentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration A2 + CountryPaymentSettingsSeeder.
 *
 * MUST be run on Postgres — the decimal(15,4) round-trip fidelity of
 * `cash_rounding_denomination` is the whole point of the 3-state test.
 */
final class CountryPaymentSettingsCashRoundingTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist_with_fail_closed_defaults(): void
    {
        $this->assertTrue(Schema::hasColumn('country_payment_settings', 'cash_rounding_enabled'));
        $this->assertTrue(Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination'));
        $this->assertTrue(Schema::hasColumn('country_payment_settings', 'pos_tolerance_enabled'));
    }

    public function test_tn_upsert_state_1_no_row_is_created_by_migration(): void
    {
        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->first();

        $this->assertNotNull($row, 'Migration A2 must upsert the TN row even when the original seed insert never ran.');
        $this->assertNotNull($row->id);
        $this->assertTrue($row->payment_tolerance_enabled);
        $this->assertSame(0, bccomp((string) $row->payment_tolerance_percentage, '0.0050', 4));
        $this->assertSame(0, bccomp((string) $row->max_payment_tolerance_amount, '0.1000', 4));
        $this->assertFalse((bool) $row->pos_tolerance_enabled);
        $this->assertFalse((bool) $row->cash_rounding_enabled);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_tn_upsert_state_2_default_row_is_tightened(): void
    {
        // Simulate the pre-migration default-row state, then re-run the upsert.
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'max_payment_tolerance_amount' => '0.5000',
            'cash_rounding_denomination' => null,
        ]);

        (new CountryPaymentSettingsSeeder)->run();

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();
        $this->assertSame(0, bccomp((string) $row->max_payment_tolerance_amount, '0.1000', 4));
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_tn_upsert_state_3_custom_operator_row_keeps_its_switches(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'pos_tolerance_enabled' => true,
            'cash_rounding_denomination' => '0.1000',
        ]);

        (new CountryPaymentSettingsSeeder)->run();

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();
        $this->assertTrue((bool) $row->cash_rounding_enabled, 'Seeder must not stomp an operator-enabled switch.');
        $this->assertTrue((bool) $row->pos_tolerance_enabled);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.1000', 4));
    }

    public function test_denomination_survives_decimal_round_trip_as_string(): void
    {
        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();

        $this->assertIsString($row->cash_rounding_denomination);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', (string) $row->cash_rounding_denomination);
    }

    public function test_tn_row_tightens_the_live_b2b_tolerance_ceiling(): void
    {
        $tenant = Tenant::create([
            'name' => 'CPS Tenant',
            'slug' => 'cps-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'CPS Shop',
            'legal_name' => 'CPS Shop SARL',
            'tax_id' => 'TAX-CPS-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        $service = app(PaymentToleranceService::class);
        $settings = $service->getToleranceSettings($company->id);

        $this->assertSame('0.1000', $settings['max_amount']);
        $this->assertSame('country', $settings['source']);
    }
}
```

- [ ] Run it and confirm it FAILS:
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php`
  Expected: `Schema::hasColumn` false, class `CountryPaymentSettingsSeeder` not found.

- [ ] Create the migration `apps/api/database/migrations/tenant/2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration A2.
     *
     * 1. Adds the country-level rounding switches + denomination.
     * 2. Adds `pos_tolerance_enabled` — a SEPARATE kill-switch from the B2B
     *    `payment_tolerance_enabled` column, so disabling POS auto-accept can
     *    never switch off B2B invoice write-offs.
     * 3. UPSERTS the TN row with an explicit uuid id. The original
     *    `2025_12_10_100000` seed insert omitted `id` on a NOT-NULL uuid PK
     *    and only ran when `countries` was already populated (it is not, at
     *    tenant-migration time), so essentially NO tenant has a row today.
     *
     * OWNER-VISIBLE EFFECT (spec §7): inserting the TN row TIGHTENS the live
     * B2B tolerance ceiling from the 0.50 system default to the intended
     * 0.100. This is the intended correction; POS behavior stays off because
     * `pos_tolerance_enabled` and `cash_rounding_enabled` default to false.
     */
    public function up(): void
    {
        if (! Schema::hasTable('country_payment_settings')) {
            return;
        }

        Schema::table('country_payment_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('country_payment_settings', 'cash_rounding_enabled')) {
                $table->boolean('cash_rounding_enabled')->default(false);
            }
            if (! Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination')) {
                $table->decimal('cash_rounding_denomination', 15, 4)->nullable();
            }
            if (! Schema::hasColumn('country_payment_settings', 'pos_tolerance_enabled')) {
                $table->boolean('pos_tolerance_enabled')->default(false);
            }
        });

        // The FK country_payment_settings.country_code -> countries.code means
        // the row can only exist once the country lookup is populated. Skip
        // silently otherwise; CountryPaymentSettingsSeeder (wired into the
        // real provisioning path, after CountriesSeeder) fills the gap.
        if (! Schema::hasTable('countries')) {
            return;
        }

        $tnExists = DB::table('countries')->where('code', 'TN')->exists();
        if (! $tnExists) {
            return;
        }

        $existing = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $now = now();

        $pinned = [
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'cash_rounding_denomination' => '0.0500',
            'updated_at' => $now,
        ];

        if ($existing === null) {
            DB::table('country_payment_settings')->insert(array_merge($pinned, [
                'id' => (string) Str::uuid(),
                'country_code' => 'TN',
                'pos_tolerance_enabled' => false,
                'cash_rounding_enabled' => false,
                'created_at' => $now,
            ]));

            return;
        }

        // Never stomp operator-flipped switches on an existing row.
        DB::table('country_payment_settings')->where('country_code', 'TN')->update($pinned);
    }

    public function down(): void
    {
        if (! Schema::hasTable('country_payment_settings')) {
            return;
        }

        Schema::table('country_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['cash_rounding_enabled', 'cash_rounding_denomination', 'pos_tolerance_enabled']);
        });
    }
};
```

- [ ] Extend the model `apps/api/app/Modules/Treasury/Domain/CountryPaymentSettings.php` — add to `$fillable` after `'cash_discount_enabled',`:

```php
        'cash_rounding_enabled',
        'cash_rounding_denomination',
        'pos_tolerance_enabled',
```

  and to `$casts`:

```php
        'cash_rounding_enabled' => 'boolean',
        'cash_rounding_denomination' => 'string',
        'pos_tolerance_enabled' => 'boolean',
```

  The `'string'` cast is load-bearing (spec §4.2 string-fidelity contract): a float cast would re-serialize `0.0500` as `0.05` and every signed receipt would quarantine.

- [ ] Create `apps/api/database/seeders/CountryPaymentSettingsSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Greenfield self-healing for `country_payment_settings` (spec §4.2).
 *
 * The original table migration's inline seed insert is broken (no `id` on a
 * NOT-NULL uuid PK) and only ran when `countries` was already populated —
 * which it is not at tenant-migration time. This seeder is hooked into
 * TenantInitializationService::seedReferenceData() (the REAL provisioning
 * path) AFTER CountriesSeeder, plus ProductionSeeder.
 *
 * Rounding and POS tolerance stay DISABLED by default; only the tolerance
 * ceilings and the denomination VALUE are pinned. Existing rows keep their
 * operator-flipped switches.
 */
class CountryPaymentSettingsSeeder extends Seeder
{
    /**
     * @var list<array{country_code: string, payment_tolerance_percentage: string, max_payment_tolerance_amount: string, cash_rounding_denomination: string|null}>
     */
    private const DEFAULTS = [
        [
            'country_code' => 'TN',
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'cash_rounding_denomination' => '0.0500',
        ],
        [
            'country_code' => 'FR',
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.5000',
            'cash_rounding_denomination' => null,
        ],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('country_payment_settings') || ! Schema::hasTable('countries')) {
            return;
        }
        if (! Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination')) {
            return;
        }

        $now = now();

        foreach (self::DEFAULTS as $definition) {
            if (! DB::table('countries')->where('code', $definition['country_code'])->exists()) {
                continue;
            }

            $existing = DB::table('country_payment_settings')
                ->where('country_code', $definition['country_code'])
                ->first();

            $pinned = [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => $definition['payment_tolerance_percentage'],
                'max_payment_tolerance_amount' => $definition['max_payment_tolerance_amount'],
                'updated_at' => $now,
            ];

            if ($definition['cash_rounding_denomination'] !== null) {
                $pinned['cash_rounding_denomination'] = $definition['cash_rounding_denomination'];
            }

            if ($existing === null) {
                DB::table('country_payment_settings')->insert(array_merge($pinned, [
                    'id' => (string) Str::uuid(),
                    'country_code' => $definition['country_code'],
                    'cash_rounding_enabled' => false,
                    'pos_tolerance_enabled' => false,
                    'created_at' => $now,
                ]));

                continue;
            }

            DB::table('country_payment_settings')
                ->where('country_code', $definition['country_code'])
                ->update($pinned);
        }
    }
}
```

- [ ] Hook it into `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php`. Add the import beside the other seeder imports:

```php
use Database\Seeders\CountryPaymentSettingsSeeder;
```

  and extend `seedReferenceData()` (it currently runs `CountriesSeeder` then `CountryTaxRatesSeeder`):

```php
    private function seedReferenceData(): void
    {
        (new CountriesSeeder)->run();
        (new CountryTaxRatesSeeder)->run();
        // MUST run after CountriesSeeder — the country_code FK requires the
        // lookup rows to exist (spec §4.2 greenfield self-healing).
        (new CountryPaymentSettingsSeeder)->run();
    }
```

- [ ] Hook it into `apps/api/database/seeders/ProductionSeeder.php` — immediately after the `CountriesSeeder` call block, add:

```php
        $this->command->info('[1b/6] Seeding country payment settings...');
        $this->call(CountryPaymentSettingsSeeder::class);
        $this->command->info('     Country payment settings seeded successfully.');
```

  and add `use Database\Seeders\CountryPaymentSettingsSeeder;` only if the file's seeders are not already namespace-local (they are in `Database\Seeders`, so no import is needed — reference the class directly).

- [ ] Run the test on Postgres and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php`
  Expected: 6 tests green.

- [ ] Run Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint database/seeders/CountryPaymentSettingsSeeder.php database/seeders/ProductionSeeder.php app/Modules/Treasury/Domain/CountryPaymentSettings.php app/Modules/Tenant/Application/Services/TenantInitializationService.php database/migrations/tenant/2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php && ./vendor/bin/phpstan analyse app/Modules/Treasury/Domain/CountryPaymentSettings.php app/Modules/Tenant/Application/Services/TenantInitializationService.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/database/migrations/tenant/2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php apps/api/database/seeders/CountryPaymentSettingsSeeder.php apps/api/database/seeders/ProductionSeeder.php apps/api/app/Modules/Treasury/Domain/CountryPaymentSettings.php apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php apps/api/tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php
git commit -m "$(cat <<'EOF'
Cash rounding P1 T2: country_payment_settings rounding columns + TN upsert

Adds cash_rounding_enabled / cash_rounding_denomination decimal(15,4) /
pos_tolerance_enabled (decoupled from the B2B switch), upserts the TN row
with an explicit uuid id and pinned values, and adds a self-healing
CountryPaymentSettingsSeeder wired into the real provisioning path after
CountriesSeeder plus ProductionSeeder.

Owner-visible: the TN row tightens the live B2B ceiling from the 0.50
system default to the intended 0.100 (spec §7).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `PosPaymentPolicyResolver` + DTO + endpoint

**Files:**
- Create `apps/api/app/Modules/POS/Application/DTOs/PosPaymentPolicyDTO.php`
- Create `apps/api/app/Modules/POS/Application/Services/PosPaymentPolicyResolver.php`
- Create `apps/api/app/Modules/POS/Presentation/Controllers/PosPaymentPolicyController.php`
- Create `apps/api/tests/Feature/POS/PosPaymentPolicyEndpointTest.php`
- Modify `apps/api/app/Modules/POS/routes.php` (controller imports lines 6-30; the fraud-settings route at line 188 is the placement anchor)

**Interfaces:**
- Produces: `PosPaymentPolicyResolver::forCompany(string $companyId): PosPaymentPolicyDTO` — ALWAYS returns a complete DTO, never null, fail-closed.
- Produces: `PosPaymentPolicyDTO` (Spatie `Data`, `#[TypeScript]`) with `companyId: string`, `currencyCode: string`, `currencyScale: int`, `cashRoundingEnabled: bool`, `cashRoundingDenomination: string`, `tenderToleranceEnabled: bool`, `tenderTolerancePercentage: string`, `tenderToleranceMaxAmount: string`, `refreshedAt: string`.
- Produces: `GET /api/v1/pos/payment-policy` → `{"data": {...}}`, sanctum + `CompanyContext`, no gate (fraud-settings pattern, `FraudSettingsPosController.php:23-38`).
- Consumes: `App\Shared\Domain\CurrencyScale::bcformatStrict(string $value, int $scale): string` (verified `CurrencyScale.php:130`, truncate-only, throws only on non-numeric).
- Consumes: `App\Modules\Company\Services\CompanyContext::requireCompanyId(): string`.

**Fail-closed matrix (spec §4.2/§4.6):**

| Country row | `pos_tolerance_enabled` | `companies.payment_tolerance_enabled` | Result |
|---|---|---|---|
| absent | — | — | both disabled |
| present | false | any | tolerance disabled |
| present | true | `false` | tolerance disabled (company override, fail-closed direction only) |
| present | true | `true` or `null` | tolerance enabled |
| present, `cash_rounding_enabled = true`, denomination non-representable at scale | — | — | rounding disabled |

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/POS/PosPaymentPolicyEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\PosPaymentPolicyResolver;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PosPaymentPolicyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Policy Tenant',
            'slug' => 'policy-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Policy Shop',
            'legal_name' => 'Policy Shop SARL',
            'tax_id' => 'TAX-POL-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Policy Cashier',
            'email' => 'cashier@policy-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
    }

    public function test_endpoint_returns_complete_dto_with_rounding_disabled_by_default(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/payment-policy');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame($this->company->id, $data['companyId']);
        $this->assertSame('TND', $data['currencyCode']);
        $this->assertSame(3, $data['currencyScale']);
        $this->assertFalse($data['cashRoundingEnabled']);
        $this->assertFalse($data['tenderToleranceEnabled']);
    }

    public function test_no_country_row_fails_closed_on_both_switches(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertFalse($dto->cashRoundingEnabled);
        $this->assertFalse($dto->tenderToleranceEnabled);
        $this->assertSame('0.000', $dto->cashRoundingDenomination);
    }

    public function test_pos_tolerance_enabled_is_decoupled_from_b2b_switch(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'payment_tolerance_enabled' => true,
            'pos_tolerance_enabled' => false,
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertFalse($dto->tenderToleranceEnabled, 'B2B tolerance must never enable POS auto-accept.');
    }

    public function test_company_false_override_force_disables_tolerance(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'pos_tolerance_enabled' => true,
        ]);
        DB::table('companies')->where('id', $this->company->id)->update([
            'payment_tolerance_enabled' => false,
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertFalse($dto->tenderToleranceEnabled);
    }

    public function test_company_true_or_null_defers_to_the_country_row(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'pos_tolerance_enabled' => true,
        ]);
        DB::table('companies')->where('id', $this->company->id)->update([
            'payment_tolerance_enabled' => null,
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);
        $this->assertTrue($dto->tenderToleranceEnabled);

        DB::table('companies')->where('id', $this->company->id)->update([
            'payment_tolerance_enabled' => true,
        ]);
        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);
        $this->assertTrue($dto->tenderToleranceEnabled);
    }

    public function test_denomination_is_emitted_at_currency_scale_as_a_string(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0500',
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertTrue($dto->cashRoundingEnabled);
        $this->assertSame('0.050', $dto->cashRoundingDenomination);
        $this->assertMatchesRegularExpression('/^\d+\.\d{3}$/', $dto->cashRoundingDenomination);
    }

    public function test_non_representable_denomination_disables_rounding_instead_of_emitting_it(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0025',
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertFalse($dto->cashRoundingEnabled);
        $this->assertSame('0.000', $dto->cashRoundingDenomination);
    }

    public function test_zero_denomination_disables_rounding(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0000',
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertFalse($dto->cashRoundingEnabled);
    }
}
```

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/PosPaymentPolicyEndpointTest.php`
  Expected: class `PosPaymentPolicyResolver` not found / 404 on the route.

- [ ] Create `apps/api/app/Modules/POS/Application/DTOs/PosPaymentPolicyDTO.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * POS payment policy pushed to the device and cached in SQLite for offline
 * use (spec §4.2). Every money-shaped field is a decimal STRING at the
 * company currency scale — a float here re-serializes `0.050` as `0.05`
 * and every signed receipt built from it quarantines (spec §4.2
 * string-fidelity contract).
 */
#[TypeScript]
final class PosPaymentPolicyDTO extends Data
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $currencyCode,
        public readonly int $currencyScale,
        public readonly bool $cashRoundingEnabled,
        /** Canonical zero at currency scale when rounding does not apply. */
        public readonly string $cashRoundingDenomination,
        public readonly bool $tenderToleranceEnabled,
        /** Fraction, NOT a percentage — e.g. "0.0050" for 0.5%. */
        public readonly string $tenderTolerancePercentage,
        /** Absolute ceiling in the COMPANY currency. */
        public readonly string $tenderToleranceMaxAmount,
        public readonly string $refreshedAt,
    ) {}
}
```

- [ ] Create `apps/api/app/Modules/POS/Application/Services/PosPaymentPolicyResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Application\DTOs\PosPaymentPolicyDTO;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;

/**
 * Resolves the POS payment policy for a company (spec §4.2).
 *
 * Country-row-only in v1 and FAIL-CLOSED everywhere:
 *   - no country row              => rounding OFF, tolerance OFF
 *   - `pos_tolerance_enabled`     => the ONLY switch that turns POS
 *                                    auto-accept on; the B2B
 *                                    `payment_tolerance_enabled` column is
 *                                    never inherited
 *   - `companies.payment_tolerance_enabled === false` force-disables;
 *     `true`/`null` defer to the country row (fail-closed DIRECTION only)
 *   - a denomination that does not round-trip at the company currency scale
 *     is NEVER emitted — rounding is reported disabled instead
 *
 * Always returns a complete DTO; the device caches it verbatim.
 */
final class PosPaymentPolicyResolver
{
    private const DEFAULT_PERCENTAGE_SCALE = 4;

    public function forCompany(string $companyId): PosPaymentPolicyDTO
    {
        $company = Company::query()->findOrFail($companyId);

        $currencyCode = (string) $company->currency;
        $scale = CurrencyScale::for($currencyCode);

        /** @var CountryPaymentSettings|null $row */
        $row = CountryPaymentSettings::query()
            ->where('country_code', (string) $company->country_code)
            ->first();

        $zero = CurrencyScale::bcformatStrict('0', $scale);

        if ($row === null) {
            return new PosPaymentPolicyDTO(
                companyId: $companyId,
                currencyCode: $currencyCode,
                currencyScale: $scale,
                cashRoundingEnabled: false,
                cashRoundingDenomination: $zero,
                tenderToleranceEnabled: false,
                tenderTolerancePercentage: bcadd('0', '0', self::DEFAULT_PERCENTAGE_SCALE),
                tenderToleranceMaxAmount: $zero,
                refreshedAt: now()->utc()->format('Y-m-d\TH:i:s\Z'),
            );
        }

        [$roundingEnabled, $denomination] = $this->resolveRounding($row, $scale, $zero);

        return new PosPaymentPolicyDTO(
            companyId: $companyId,
            currencyCode: $currencyCode,
            currencyScale: $scale,
            cashRoundingEnabled: $roundingEnabled,
            cashRoundingDenomination: $denomination,
            tenderToleranceEnabled: $this->resolveToleranceEnabled($row, $company),
            tenderTolerancePercentage: $this->normalizePercentage($row->payment_tolerance_percentage),
            tenderToleranceMaxAmount: $this->normalizeMoney($row->max_payment_tolerance_amount, $scale, $zero),
            refreshedAt: now()->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function resolveRounding(CountryPaymentSettings $row, int $scale, string $zero): array
    {
        if (! (bool) $row->cash_rounding_enabled) {
            return [false, $zero];
        }

        $raw = $row->cash_rounding_denomination;
        if (! is_string($raw) || ! is_numeric($raw)) {
            return [false, $zero];
        }

        // Round-trip validity: the stored decimal(15,4) value must be exactly
        // representable at the company currency scale. bcformatStrict
        // TRUNCATES, so a value with digits past the scale (e.g. 0.0025 on
        // scale 3) collapses to a different number — reject it rather than
        // emit a denomination the device would sign but the server could not
        // reconstruct.
        $scaled = CurrencyScale::bcformatStrict($raw, $scale);
        if (bccomp($scaled, $raw, self::DEFAULT_PERCENTAGE_SCALE) !== 0) {
            return [false, $zero];
        }

        if (bccomp($scaled, '0', $scale) <= 0) {
            return [false, $zero];
        }

        return [true, $scaled];
    }

    private function resolveToleranceEnabled(CountryPaymentSettings $row, Company $company): bool
    {
        if (! (bool) $row->pos_tolerance_enabled) {
            return false;
        }

        // Fail-closed DIRECTION override only: an explicit company `false`
        // disables; `true` / `null` defer to the country row.
        if ($company->payment_tolerance_enabled === false) {
            return false;
        }

        return true;
    }

    private function normalizePercentage(mixed $value): string
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return bcadd('0', '0', self::DEFAULT_PERCENTAGE_SCALE);
        }

        return CurrencyScale::bcformatStrict($value, self::DEFAULT_PERCENTAGE_SCALE);
    }

    private function normalizeMoney(mixed $value, int $scale, string $zero): string
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return $zero;
        }

        try {
            return CurrencyScale::bcformatStrict($value, $scale);
        } catch (InvalidArgumentException) {
            return $zero;
        }
    }
}
```

  `CurrencyScale::for(string $currencyCode): int` is verified at `apps/api/app/Shared/Domain/CurrencyScale.php:62` — it is the ONLY scale source; do not introduce a second one.

- [ ] Create `apps/api/app/Modules/POS/Presentation/Controllers/PosPaymentPolicyController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\PosPaymentPolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/pos/payment-policy
 *
 * Cash-rounding + tender-tolerance policy for the authenticated terminal's
 * company, cached by the device in SQLite for offline use. Same posture as
 * the fraud-settings endpoint: sanctum + CompanyContext, no extra gate, no
 * new permission.
 */
final class PosPaymentPolicyController extends Controller
{
    public function __construct(
        private readonly PosPaymentPolicyResolver $resolver,
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        return response()->json(['data' => $this->resolver->forCompany($companyId)->toArray()]);
    }
}
```

- [ ] Register the route in `apps/api/app/Modules/POS/routes.php`. Add the import beside `use App\Modules\POS\Presentation\Controllers\PosPendingCustomerController;`:

```php
use App\Modules\POS\Presentation\Controllers\PosPaymentPolicyController;
```

  and add the route immediately after the fraud-settings line:

```php
    // Cash-rounding + tender-tolerance policy cache (device pulls + caches offline)
    Route::get('/pos/payment-policy', [PosPaymentPolicyController::class, 'show']);
```

- [ ] Regenerate TS types (the DTO is `#[TypeScript]`):
  `cd apps/api && CACHE_STORE=array php artisan typescript:transform`

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/PosPaymentPolicyEndpointTest.php`
  Expected: 8 tests green.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/POS && ./vendor/bin/phpstan analyse app/Modules/POS/Application/Services/PosPaymentPolicyResolver.php app/Modules/POS/Application/DTOs/PosPaymentPolicyDTO.php app/Modules/POS/Presentation/Controllers/PosPaymentPolicyController.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Modules/POS/Application/DTOs/PosPaymentPolicyDTO.php apps/api/app/Modules/POS/Application/Services/PosPaymentPolicyResolver.php apps/api/app/Modules/POS/Presentation/Controllers/PosPaymentPolicyController.php apps/api/app/Modules/POS/routes.php apps/api/tests/Feature/POS/PosPaymentPolicyEndpointTest.php packages/shared/types
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T3: PosPaymentPolicyResolver + GET /api/v1/pos/payment-policy

Fail-closed country-row-only resolver: pos_tolerance_enabled is the only
POS switch (never inherits the B2B column), an explicit company `false`
force-disables, and a denomination that does not round-trip at the company
currency scale is reported disabled rather than emitted. Denomination is a
decimal STRING at currency scale throughout (string-fidelity contract).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 4: `pos:configure-cash-rounding` ops command

**Files:**
- Create `apps/api/app/Console/Commands/ConfigureCashRoundingCommand.php`
- Create `apps/api/tests/Feature/POS/ConfigureCashRoundingCommandTest.php`

**Interfaces:**
- Produces: artisan `pos:configure-cash-rounding {--country=TN} {--denomination=} {--enable-rounding} {--disable-rounding} {--enable-tolerance} {--disable-tolerance} {--dry-run} {--verify}`.
- Consumes: `Illuminate\Database\DatabaseManager` (constructor-injected, exemplar `ConfigureMethodRepositoryRoutingCommand.php:19-22`).
- Tenant-DB-scoped, run via `tenants:run`; `Schema::hasTable` / `Schema::hasColumn` guarded; NO `--tenant` flag.
- `--verify` asserts, per company, that at least one `payment_methods` row has `is_cash_tender = true` — this must be run at Phase 1, BEFORE the device's `is_cash_tender` selection predicate ships.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/POS/ConfigureCashRoundingCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ConfigureCashRoundingCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cmd Tenant',
            'slug' => 'cmd-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cmd Shop',
            'legal_name' => 'Cmd Shop SARL',
            'tax_id' => 'TAX-CMD-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => false,
        ]);

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-rounding' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertFalse((bool) $row->cash_rounding_enabled);
    }

    public function test_enable_rounding_writes_only_the_rounding_switch(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-rounding' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertTrue((bool) $row->cash_rounding_enabled);
        $this->assertFalse((bool) $row->pos_tolerance_enabled, 'The two switches are independent.');
    }

    public function test_enable_tolerance_does_not_touch_the_b2b_switch_value(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'payment_tolerance_enabled' => true,
        ]);

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--disable-tolerance' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertFalse((bool) $row->pos_tolerance_enabled);
        $this->assertTrue((bool) $row->payment_tolerance_enabled, 'B2B tolerance must be untouched.');
    }

    public function test_upsert_creates_the_row_when_absent(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0.0500',
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->id);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_verify_fails_when_a_company_has_no_cash_tender_method(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte',
            'is_physical' => false,
            'has_maturity' => false,
            'is_cash_tender' => false,
            'is_active' => true,
            'position' => 1,
        ]);

        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->expectsOutputToContain('has no active is_cash_tender payment method')
            ->assertFailed();
    }

    public function test_verify_succeeds_when_every_company_has_a_cash_tender_method(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ]);

        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->assertSuccessful();
    }

    public function test_rejects_a_denomination_that_is_not_representable_at_company_scale(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0.0025',
        ])->assertFailed();
    }
}
```

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/ConfigureCashRoundingCommandTest.php`
  Expected: `Command "pos:configure-cash-rounding" is not defined.`

- [ ] Create `apps/api/app/Console/Commands/ConfigureCashRoundingCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tenant-DB-scoped cash-rounding configuration (spec §4.2).
 *
 * Run via `php artisan tenants:run pos:configure-cash-rounding -- <flags>`.
 * There is deliberately NO `--tenant` flag: the tenancy runner switches the
 * default connection per tenant, and a flag would invite half-applied state.
 *
 * The two switches are INDEPENDENT (`--enable-rounding` / `--disable-rounding`
 * vs `--enable-tolerance` / `--disable-tolerance`) and neither touches the B2B
 * `payment_tolerance_enabled` column.
 */
final class ConfigureCashRoundingCommand extends Command
{
    protected $signature = 'pos:configure-cash-rounding
                            {--country=TN : ISO 3166-1 alpha-2 country code of the settings row}
                            {--denomination= : Rounding denomination to store, e.g. 0.0500}
                            {--enable-rounding : Set cash_rounding_enabled = true}
                            {--disable-rounding : Set cash_rounding_enabled = false}
                            {--enable-tolerance : Set pos_tolerance_enabled = true}
                            {--disable-tolerance : Set pos_tolerance_enabled = false}
                            {--dry-run : Report changes without writing them}
                            {--verify : Report per-tenant state and assert every company has an is_cash_tender method}';

    protected $description = 'Configure country-level POS cash rounding and tender tolerance for the current tenant database.';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('country_payment_settings')
            || ! Schema::hasTable('companies')
            || ! Schema::hasTable('payment_methods')
            || ! Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination')
            || ! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            $this->error(
                'Cash-rounding tables/columns are unavailable. Run tenant migrations and execute this command inside each tenant context (tenants:run).',
            );

            return self::FAILURE;
        }

        if ((bool) $this->option('verify')) {
            return $this->verify();
        }

        $countryCode = strtoupper(trim((string) $this->option('country')));
        if ($countryCode === '') {
            $this->error('The --country code is required.');

            return self::FAILURE;
        }

        if ((bool) $this->option('enable-rounding') && (bool) $this->option('disable-rounding')) {
            $this->error('--enable-rounding and --disable-rounding are mutually exclusive.');

            return self::FAILURE;
        }
        if ((bool) $this->option('enable-tolerance') && (bool) $this->option('disable-tolerance')) {
            $this->error('--enable-tolerance and --disable-tolerance are mutually exclusive.');

            return self::FAILURE;
        }

        $updates = [];

        $denomination = trim((string) ($this->option('denomination') ?? ''));
        if ($denomination !== '') {
            $normalized = $this->normalizeDenomination($countryCode, $denomination);
            if ($normalized === null) {
                return self::FAILURE;
            }
            $updates['cash_rounding_denomination'] = $normalized;
        }

        if ((bool) $this->option('enable-rounding')) {
            $updates['cash_rounding_enabled'] = true;
        }
        if ((bool) $this->option('disable-rounding')) {
            $updates['cash_rounding_enabled'] = false;
        }
        if ((bool) $this->option('enable-tolerance')) {
            $updates['pos_tolerance_enabled'] = true;
        }
        if ((bool) $this->option('disable-tolerance')) {
            $updates['pos_tolerance_enabled'] = false;
        }

        if ($updates === []) {
            $this->error('Nothing to do: pass --denomination and/or one of the enable/disable flags, or use --verify.');

            return self::FAILURE;
        }

        $existing = $this->database->table('country_payment_settings')
            ->where('country_code', $countryCode)
            ->first();

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf(
                '[DRY-RUN] Country %s: would %s %s',
                $countryCode,
                $existing === null ? 'INSERT' : 'UPDATE',
                json_encode($updates, JSON_THROW_ON_ERROR),
            ));

            return self::SUCCESS;
        }

        $now = now();

        if ($existing === null) {
            if (! $this->database->table('countries')->where('code', $countryCode)->exists()) {
                $this->error(sprintf('Country %s is not present in the countries lookup; cannot create the settings row.', $countryCode));

                return self::FAILURE;
            }

            $this->database->table('country_payment_settings')->insert(array_merge([
                'id' => (string) Str::uuid(),
                'country_code' => $countryCode,
                'cash_rounding_enabled' => false,
                'pos_tolerance_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $updates));

            $this->info(sprintf('Country %s: settings row created.', $countryCode));

            return self::SUCCESS;
        }

        $updates['updated_at'] = $now;
        $this->database->table('country_payment_settings')
            ->where('country_code', $countryCode)
            ->update($updates);

        $this->info(sprintf('Country %s: settings row updated.', $countryCode));

        return self::SUCCESS;
    }

    /**
     * Normalize the operator-supplied denomination to decimal(15,4) storage
     * form and assert it round-trips at EVERY company currency scale in this
     * tenant. A value the resolver would reject must never be stored — the
     * resolver would silently report rounding disabled and the operator would
     * have no signal.
     */
    private function normalizeDenomination(string $countryCode, string $denomination): ?string
    {
        if (! is_numeric($denomination)) {
            $this->error(sprintf('Denomination "%s" is not a numeric string.', $denomination));

            return null;
        }

        try {
            $stored = CurrencyScale::bcformatStrict($denomination, 4);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if (bccomp($stored, '0', 4) <= 0) {
            $this->error(sprintf('Denomination "%s" must be greater than zero.', $denomination));

            return null;
        }

        $companies = $this->database->table('companies')
            ->where('country_code', $countryCode)
            ->select(['id', 'currency'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $scale = CurrencyScale::for((string) $company->currency);
            $scaled = CurrencyScale::bcformatStrict($stored, $scale);
            if (bccomp($scaled, $stored, 4) !== 0) {
                $this->error(sprintf(
                    'Denomination %s is not representable at scale %d for company %s (%s); refusing to store it.',
                    $stored,
                    $scale,
                    (string) $company->id,
                    (string) $company->currency,
                ));

                return null;
            }
        }

        return $stored;
    }

    private function verify(): int
    {
        $failures = 0;

        $rows = $this->database->table('country_payment_settings')
            ->orderBy('country_code')
            ->get(['country_code', 'cash_rounding_enabled', 'cash_rounding_denomination', 'pos_tolerance_enabled', 'payment_tolerance_enabled', 'payment_tolerance_percentage', 'max_payment_tolerance_amount']);

        if ($rows->isEmpty()) {
            $this->warn('No country_payment_settings rows exist in this tenant database.');
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                'country=%s rounding=%s denomination=%s pos_tolerance=%s b2b_tolerance=%s pct=%s max=%s',
                (string) $row->country_code,
                ((bool) $row->cash_rounding_enabled) ? 'ON' : 'off',
                (string) ($row->cash_rounding_denomination ?? 'null'),
                ((bool) $row->pos_tolerance_enabled) ? 'ON' : 'off',
                ((bool) $row->payment_tolerance_enabled) ? 'ON' : 'off',
                (string) $row->payment_tolerance_percentage,
                (string) $row->max_payment_tolerance_amount,
            ));
        }

        $companies = $this->database->table('companies')
            ->select(['id', 'tenant_id', 'name'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $hasCashTender = $this->database->table('payment_methods')
                ->where('tenant_id', (string) $company->tenant_id)
                ->where('company_id', (string) $company->id)
                ->where('is_cash_tender', true)
                ->where('is_active', true)
                ->exists();

            if (! $hasCashTender) {
                $this->error(sprintf(
                    'Company %s (%s) has no active is_cash_tender payment method; the POS cash checkout would be dead once the device predicate ships.',
                    (string) $company->id,
                    (string) $company->name,
                ));
                $failures++;

                continue;
            }

            $this->line(sprintf('Company %s: is_cash_tender OK.', (string) $company->id));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/ConfigureCashRoundingCommandTest.php`
  Expected: 7 tests green.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Console/Commands/ConfigureCashRoundingCommand.php && ./vendor/bin/phpstan analyse app/Console/Commands/ConfigureCashRoundingCommand.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Console/Commands/ConfigureCashRoundingCommand.php apps/api/tests/Feature/POS/ConfigureCashRoundingCommandTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T4: pos:configure-cash-rounding ops command

Tenant-DB-scoped (tenants:run, no --tenant flag), Schema-guarded, upserts
the country row, flips cash_rounding_enabled and pos_tolerance_enabled
independently without touching the B2B switch, refuses a denomination that
is not representable at every company currency scale, and --verify asserts
each company has an active is_cash_tender method before the device
predicate ships.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 5: `accounting:backfill-tolerance-purposes` ops command

**Files:**
- Create `apps/api/app/Console/Commands/BackfillTolerancePurposesCommand.php`
- Create `apps/api/tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php`

**Interfaces:**
- Produces: artisan `accounting:backfill-tolerance-purposes {--dry-run}`.
- Consumes: `Illuminate\Database\DatabaseManager`; `App\Modules\Accounting\Domain\Enums\SystemAccountPurpose::PaymentToleranceExpense` (`'payment_tolerance_expense'`, code 6580) and `::PaymentToleranceIncome` (`'payment_tolerance_income'`, code 7580).
- Per company: create the missing account, OR promote an existing code-matched account that lacks `system_purpose` (exemplar validate-shape step, `BackfillPayableInstrumentAccountsCommand.php:66-80`); HARD-FAIL when the parent account is absent (`:56-63`).
- Parent resolution: TN/FR charts use `65` / `75`; generic uses `6000` / `7000`. `7580` is declared before `75` in the chart seeders, so parent resolution is a lookup per definition, not a positional walk.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillTolerancePurposesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Backfill Tenant',
            'slug' => 'backfill-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill Shop',
            'legal_name' => 'Backfill Shop SARL',
            'tax_id' => 'TAX-BF-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);
    }

    private function seedAccount(string $code, string $type, ?string $parentId, ?string $purpose = null): string
    {
        $id = (string) Str::uuid();
        DB::table('accounts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => 'Account '.$code,
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
            'is_system' => true,
            'balance' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_creates_missing_tolerance_accounts_under_the_tn_parents(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $revenueParent = $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $expense = DB::table('accounts')->where('company_id', $this->company->id)->where('code', '6580')->first();
        $income = DB::table('accounts')->where('company_id', $this->company->id)->where('code', '7580')->first();

        $this->assertNotNull($expense);
        $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense->value, $expense->system_purpose);
        $this->assertSame($expenseParent, $expense->parent_id);

        $this->assertNotNull($income);
        $this->assertSame(SystemAccountPurpose::PaymentToleranceIncome->value, $income->system_purpose);
        $this->assertSame($revenueParent, $income->parent_id);
    }

    public function test_promotes_an_existing_code_matched_account_lacking_a_purpose(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $orphan = $this->seedAccount('6580', 'expense', $expenseParent, null);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $row = DB::table('accounts')->where('id', $orphan)->first();
        $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense->value, $row->system_purpose);
    }

    public function test_hard_fails_when_the_parent_account_is_absent(): void
    {
        // No 65 / 75 parents seeded.
        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('is missing parent account')
            ->assertFailed();

        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '6580',
        ]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '6580',
        ]);
    }

    public function test_rejects_an_existing_account_with_the_wrong_type(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $this->seedAccount('6580', 'revenue', $expenseParent, null);

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('has wrong type')
            ->assertFailed();
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();
        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $this->assertSame(1, DB::table('accounts')->where('company_id', $this->company->id)->where('code', '6580')->count());
    }
}
```

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php`
  Expected: `Command "accounting:backfill-tolerance-purposes" is not defined.`

- [ ] Create `apps/api/app/Console/Commands/BackfillTolerancePurposesCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ensure every company can post the POS rounding / tolerance entries
 * (spec §4.2). Brownfield charts created before the 6580/7580 seeds exist
 * without them, and `GeneralLedgerService::hasAccountForPurpose` is
 * non-throwing — so without this backfill the bridge would silently SKIP
 * the entries and only emit an alert.
 *
 * Follows BackfillPayableInstrumentAccountsCommand:
 *   - validate the shape of an existing code-matched account (type/active),
 *   - PROMOTE it when it merely lacks `system_purpose`,
 *   - HARD-FAIL when the parent account is absent (never invent a parent).
 *
 * Tenant-DB-scoped: run via `tenants:run`.
 */
final class BackfillTolerancePurposesCommand extends Command
{
    protected $signature = 'accounting:backfill-tolerance-purposes
                            {--dry-run : Report changes without writing accounts}';

    protected $description = 'Backfill the payment-tolerance expense (6580) and income (7580) accounts for every company.';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            $this->error(
                'Tenant accounting tables are unavailable. Run this command inside each tenant context (for example via tenants:run).',
            );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $promoted = 0;
        $invalid = 0;

        $companies = $this->database->table('companies')
            ->select(['id', 'tenant_id', 'country_code'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;

            foreach ($this->definitions((string) $company->country_code) as $definition) {
                $parentId = $this->database->table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $definition['parent_code'])
                    ->value('id');

                if (! is_string($parentId)) {
                    $this->error(sprintf(
                        'Company %s is missing parent account %s; tolerance account %s was skipped.',
                        $companyId,
                        $definition['parent_code'],
                        $definition['code'],
                    ));
                    $invalid++;

                    continue;
                }

                $existing = $this->database->table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $definition['code'])
                    ->first();

                if ($existing !== null) {
                    if ((string) $existing->type !== $definition['type']) {
                        $this->error(sprintf(
                            'Company %s account %s has wrong type %s; expected %s. Account was skipped.',
                            $companyId,
                            $definition['code'],
                            (string) $existing->type,
                            $definition['type'],
                        ));
                        $invalid++;

                        continue;
                    }

                    if (! (bool) $existing->is_active) {
                        $this->error(sprintf(
                            'Company %s account %s is inactive; activate it before enabling POS rounding. Account was skipped.',
                            $companyId,
                            $definition['code'],
                        ));
                        $invalid++;

                        continue;
                    }

                    $currentPurpose = $existing->system_purpose === null ? null : (string) $existing->system_purpose;

                    if ($currentPurpose === $definition['purpose']) {
                        continue;
                    }

                    if ($currentPurpose !== null) {
                        $this->error(sprintf(
                            'Company %s account %s already carries system_purpose %s; refusing to repurpose it.',
                            $companyId,
                            $definition['code'],
                            $currentPurpose,
                        ));
                        $invalid++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            '[DRY-RUN] Company %s: would promote account %s to system_purpose %s.',
                            $companyId,
                            $definition['code'],
                            $definition['purpose'],
                        ));
                    } else {
                        $this->database->table('accounts')
                            ->where('id', $existing->id)
                            ->update([
                                'system_purpose' => $definition['purpose'],
                                'is_system' => true,
                                'updated_at' => now(),
                            ]);
                    }
                    $promoted++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] Company %s: would create %s account %s (%s) under parent %s.',
                        $companyId,
                        $definition['type'],
                        $definition['code'],
                        $definition['name'],
                        $definition['parent_code'],
                    ));
                    $created++;

                    continue;
                }

                $now = now();
                $this->database->table('accounts')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => (string) $company->tenant_id,
                    'company_id' => $companyId,
                    'parent_id' => $parentId,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'system_purpose' => $definition['purpose'],
                    'is_active' => true,
                    'is_system' => true,
                    'balance' => '0.000',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info(sprintf(
            '%sTolerance purpose backfill: %d account(s) %s; %d promoted; %d invalid.',
            $prefix,
            $created,
            $dryRun ? 'would be created' : 'created',
            $promoted,
            $invalid,
        ));

        return $invalid === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{code: string, name: string, type: string, parent_code: string, purpose: string}>
     */
    private function definitions(string $countryCode): array
    {
        $isFrenchPlan = in_array(strtoupper($countryCode), ['TN', 'FR'], true);

        return [
            [
                'code' => '6580',
                'name' => $isFrenchPlan ? 'Écart de règlement (charges)' : 'Payment Tolerance Expense',
                'type' => 'expense',
                'parent_code' => $isFrenchPlan ? '65' : '6000',
                'purpose' => SystemAccountPurpose::PaymentToleranceExpense->value,
            ],
            [
                'code' => '7580',
                'name' => $isFrenchPlan ? 'Écart de règlement (produits)' : 'Payment Tolerance Income',
                'type' => 'revenue',
                'parent_code' => $isFrenchPlan ? '75' : '7000',
                'purpose' => SystemAccountPurpose::PaymentToleranceIncome->value,
            ],
        ];
    }
}
```

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php`
  Expected: 6 tests green.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Console/Commands/BackfillTolerancePurposesCommand.php && ./vendor/bin/phpstan analyse app/Console/Commands/BackfillTolerancePurposesCommand.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Console/Commands/BackfillTolerancePurposesCommand.php apps/api/tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T5: accounting:backfill-tolerance-purposes command

Creates or promotes the 6580/7580 payment-tolerance accounts per company so
the v3 bridge entries can actually post on brownfield charts. Validates the
shape of an existing code-matched account, promotes only when
system_purpose is null, refuses to repurpose, and hard-fails when the chart
parent (65/75 or 6000/7000) is absent.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 6: SALE_RECEIPT payload v3 — key set, version threading, binds, DTO

**Files:**
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php` (`PHASE_1_MAP` SALE_RECEIPT entry line 58; `SUPPORTED_VERSIONS` line 106)
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` (`PAYLOAD_KEYS` const line 225; `validatePayloadKeySet` line 316; `validatePerEventConstraints` line 361; `validateSaleReceiptPayload` line 691; `validateSaleReceiptAggregateConsistency` line 866; `moneyRegex` line 2612)
- Modify `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php` (call site line 226; `$eventVersion` already in scope at line 205)
- Modify `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php` (call site lines 312-315)
- Modify `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php` (call site line 312)
- Modify `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php` (call site line 353)
- Modify `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php` (`parse()` lines 65-68; `expectedPayloadFields` line 79; `keySetDefects` line 96; `inferPayloadPath` line 153)
- Modify `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php` (constructor lines 45-73; `fromArray` returns lines 96-130)
- Modify `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SaleReceiptCanonicalView.php` (constructor lines 34-43)
- Create `apps/api/tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php`
- Create `apps/api/tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php`

**Interfaces:**
- Produces: `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3` — `public const list<string>`, 30 keys, lexicographically sorted (the two new keys sort between `buyer` and `cashier_id` because `_` (0x5F) < `i` (0x69)).
- Produces: `FiscalPayloadConstraintValidator::payloadKeysFor(FiscalEventType $type, int $eventVersion): ?array` — returns the version-appropriate expected key list, or `null` for an unimplemented type. Takes NO chain context; the `CASH_OUT`/`SAFE_DROP` z-session override stays inside `validatePayloadKeySet`.
- Changes: `FiscalPayloadConstraintValidator::validatePayloadKeySet(FiscalEventType $type, array $payload, string $chainContext = 'operational', int $eventVersion = 1): ?string` (new trailing param, default preserves today's behavior).
- Produces: `SaleReceiptPayload::$cashRoundingAdjustment: ?string` and `::$cashRoundingDenomination: ?string` — appended LAST with `= null` defaults so positional construction in existing tests keeps compiling.
- Produces: `SaleReceiptCanonicalView::cashRoundingAdjustmentOrZero(): string` and `::cashRoundingDenominationOrZero(): string` — `'0'` when absent (v1/v2).
- Consumes: `FiscalPayloadConstraintValidator::validatePerEventConstraints(FiscalEventType $type, array $payload, string $chainContext = 'operational', int $eventVersion = 1): void` — ALREADY version-threaded (line 361), and `validateSaleReceiptPayload(array $payload, int $eventVersion = 1): void` (line 691). No new plumbing is needed for the binds themselves.
- Consumes: `FiscalEvent::$event_version: int` (`FiscalEvent.php:29,100,160`) — persisted, written from the device envelope only; replay-deterministic.

**Normative bind evaluation ORDER (spec §4.1, do not reorder):**
1. `subtotal + vat_total == (total − adj) + discount`, exact at currency scale.
2. If `adj ≠ 0`: (a) assert `bccomp(denomination, '0', s) > 0` and THROW `RuntimeException` — never reach `bcmod` with a zero divisor, because `DivisionByZeroError` is an `Error` that `StrictCanonicalParser.php:231-233` (`catch (RuntimeException)`) does NOT catch and would kill the worker; (b) `|adj| ≤ denomination/2` with the half computed and compared at `s+1` (truncation-safe); (c) `bcmod(total, denomination, s) == 0`.
3. Static history-stable caps on `denomination`, always (a canonical zero passes): `≤ 1.000` (s=3), `≤ 1.00` (s=2), `≤ 10` (s=0 — JPY 10-yen rounding is real).

**Steps:**

- [ ] Write the failing unit test `apps/api/tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use PHPUnit\Framework\TestCase;

/**
 * Rewritten key-drift gate: the old regex-based test could not parse a named
 * const or a nested shape. This asserts the constants directly.
 */
final class SaleReceiptV3KeySetTest extends TestCase
{
    public function test_v3_key_set_has_thirty_keys(): void
    {
        $this->assertCount(30, FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3);
    }

    public function test_v3_key_set_is_lexicographically_sorted(): void
    {
        $keys = FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3;
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys, 'SALE_RECEIPT_PAYLOAD_KEYS_V3 must be lexicographically sorted');
    }

    public function test_v3_key_set_is_the_v2_set_plus_exactly_the_two_rounding_keys(): void
    {
        $v2 = FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'];
        $v3 = FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3;

        $this->assertCount(28, $v2);
        $this->assertSame(
            ['cash_rounding_adjustment', 'cash_rounding_denomination'],
            array_values(array_diff($v3, $v2)),
        );
        $this->assertSame([], array_values(array_diff($v2, $v3)));
    }

    public function test_the_two_new_keys_sort_between_buyer_and_cashier_id(): void
    {
        $keys = FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3;
        $buyer = array_search('buyer', $keys, true);
        $adj = array_search('cash_rounding_adjustment', $keys, true);
        $denom = array_search('cash_rounding_denomination', $keys, true);
        $cashierId = array_search('cashier_id', $keys, true);

        $this->assertSame($buyer + 1, $adj);
        $this->assertSame($adj + 1, $denom);
        $this->assertSame($denom + 1, $cashierId);
    }

    public function test_payload_keys_for_returns_v2_shape_for_versions_below_three(): void
    {
        $validator = new FiscalPayloadConstraintValidator;

        $this->assertSame(
            FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'],
            $validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 1),
        );
        $this->assertSame(
            FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'],
            $validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 2),
        );
        $this->assertSame(
            FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3,
            $validator->payloadKeysFor(FiscalEventType::SALE_RECEIPT, 3),
        );
    }

    public function test_payload_keys_for_is_version_blind_for_other_event_types(): void
    {
        $validator = new FiscalPayloadConstraintValidator;

        $this->assertSame(
            FiscalPayloadConstraintValidator::PAYLOAD_KEYS['Z_REPORT'],
            $validator->payloadKeysFor(FiscalEventType::Z_REPORT, 3),
        );
        $this->assertNull($validator->payloadKeysFor(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST, 1));
    }

    public function test_registry_supports_versions_one_two_and_three_and_authors_three(): void
    {
        $registry = new FiscalEventPayloadRegistry;

        $this->assertSame([1, 2, 3], $registry->supportedVersionsFor(FiscalEventType::SALE_RECEIPT));
        $this->assertSame(3, $registry->eventVersionFor(FiscalEventType::SALE_RECEIPT));
    }
}
```

  If `FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST` is not a real case, substitute any enum case absent from `PAYLOAD_KEYS` — confirm with `grep -n "case " apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php`.

- [ ] Run it and confirm it FAILS:
  `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php`
  Expected: undefined constant `SALE_RECEIPT_PAYLOAD_KEYS_V3`.

- [ ] Add the named constant + accessor to `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`. Insert immediately AFTER the closing `];` of `PAYLOAD_KEYS` (the line following the `'Z_REPORT' => ZReportPayload::PAYLOAD_KEYS,` entry):

```php
    /**
     * SALE_RECEIPT **v3** key set — the 28-key v2 contract plus the two
     * signed cash-rounding siblings (spec §4.4). Lexicographically sorted:
     * `cash_rounding_*` sorts between `buyer` and `cashier_id` because
     * `_` (0x5F) < `i` (0x69) under the code-unit ordering the device's JCS
     * canonicalizer uses.
     *
     * A NAMED constant, never a mutation of PAYLOAD_KEYS — v1/v2 events must
     * keep rejecting these keys as `payload_extra_field` forever.
     *
     * @var list<string>
     */
    public const SALE_RECEIPT_PAYLOAD_KEYS_V3 = [
        'approval_references',
        'business_date',
        'buyer',
        'cash_rounding_adjustment',
        'cash_rounding_denomination',
        'cashier_id',
        'cashier_name',
        'consumption_mode',
        'currency_code',
        'currency_scale',
        'event_time_device',
        'invoice_type_code',
        'line_items',
        'lottery_code',
        'notes',
        'original_receipt_reference',
        'payments',
        'receipt_uuid',
        'seller',
        'shift_id',
        'subtotal',
        'table_id',
        'terminal_id',
        'total',
        'training_flag',
        'transaction_discount_amount',
        'transaction_discount_reason',
        'vat_breakdown',
        'vat_total',
        'vouchers_redeemed',
    ];

    /**
     * Version-aware expected key set for an event type.
     *
     * Deliberately takes NO chain context: the z-session CASH_OUT/SAFE_DROP
     * override is a CHAIN-context concern and stays inside
     * {@see validatePayloadKeySet()}.
     *
     * @return list<string>|null null when the type has no registered contract
     */
    public function payloadKeysFor(FiscalEventType $type, int $eventVersion): ?array
    {
        if ($type === FiscalEventType::SALE_RECEIPT && $eventVersion >= 3) {
            return self::SALE_RECEIPT_PAYLOAD_KEYS_V3;
        }

        return self::PAYLOAD_KEYS[$type->value] ?? null;
    }
```

- [ ] Thread the version through `validatePayloadKeySet` in the same file. Replace the signature and the first lookup:

```php
    public function validatePayloadKeySet(
        FiscalEventType $type,
        array $payload,
        string $chainContext = 'operational',
        int $eventVersion = 1,
    ): ?string {
        $expected = $this->payloadKeysFor($type, $eventVersion);
        if ($expected === null) {
            return 'event_type_unimplemented:'.$type->value;
        }
```

  Leave the rest of the method (the z-session override, the ACCOUNT_CHARGE guard, the missing/extras diffs) byte-identical.

- [ ] Bump the registry `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`:
  - line 58: `FiscalEventType::SALE_RECEIPT->value => [SaleReceiptPayload::class, 3],`
  - line 106: `FiscalEventType::SALE_RECEIPT->value => [1, 2, 3],`
  - Update the comment block above line 58 to record that v3 adds `cash_rounding_adjustment` + `cash_rounding_denomination`, that authoring version 3 is INERT server-side (no server path authors SALE_RECEIPT), and that v1/v2 remain parseable forever.

- [ ] Thread the version at call site 1 — `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:226`:

```php
        $extrasError = $this->constraintValidator->validatePayloadKeySet($type, $payload, $chainContext, $eventVersion);
```

- [ ] Thread the version at call site 2 — `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:312-315`. This is the QUARANTINE-REPAIR path; without the version a corrected v3 payload would be rejected as carrying extras:

```php
        $extrasError = $this->constraintValidator->validatePayloadKeySet(
            $event->event_type,
            $correctedPayload,
            'operational',
            $event->event_version,
        );
```

- [ ] Thread the version at call site 3 — `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:312`:

```php
        // Server-authored TERMINAL_REGISTRY_SNAPSHOT is a v1-only contract
        // (FiscalEventPayloadRegistry::PHASE_1_MAP). Passed explicitly so the
        // version threading is auditable at every call site.
        $keySetError = $this->payloadValidator->validatePayloadKeySet($type, $payload, 'operational', 1);
```

- [ ] Thread the version at call site 4 — `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:353`:

```php
        // Every type this service authors (DEPOSIT_RECEIPT, cash-drawer
        // movements, session events) is a v1 contract; no server path authors
        // SALE_RECEIPT. Passed explicitly for auditability.
        $keySetError = $this->payloadValidator->validatePayloadKeySet($type, $payload, 'operational', 1);
```

- [ ] Refactor `apps/api/app/Modules/Fiscal/Application/Services/BestEffortPayloadParser.php` onto `payloadKeysFor` with its already-resolved version. Move the `$eventVersion` resolution ABOVE the two calls and thread it:

```php
        /** @var array<string, mixed> $payload */
        // Validate against the envelope's OWN event_version (M4 Codex P2-1)
        // so a v2 SALE_RECEIPT in the repair path is not mis-flagged with
        // v1 line-item defects, and a v3 payload is not mis-flagged as
        // carrying extra fields. Unparseable versions fall back to 1.
        $eventVersion = is_int($envelope['event_version'] ?? null) ? $envelope['event_version'] : 1;
        $parsed = $this->expectedPayloadFields($eventType, $payload, $eventVersion);
        $defects = array_merge($defects, $this->keySetDefects($eventType, $payload, $eventVersion));
        $defects = array_merge($defects, $this->schemaDefects($eventType, $payload, $eventVersion));

        return new BestEffortParseResult($parsed, $this->uniqueDefects($defects));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function expectedPayloadFields(FiscalEventType $eventType, array $payload, int $eventVersion): array
    {
        $expected = $this->constraintValidator->payloadKeysFor($eventType, $eventVersion) ?? [];
        $parsed = [];
        foreach ($expected as $key) {
            if (array_key_exists($key, $payload)) {
                $parsed[$key] = $payload[$key];
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParseDefect>
     */
    private function keySetDefects(FiscalEventType $eventType, array $payload, int $eventVersion): array
    {
        $expected = $this->constraintValidator->payloadKeysFor($eventType, $eventVersion);
        if ($expected === null) {
            return [new ParseDefect('event_type', 'event_type_unimplemented', 'No payload contract is registered for '.$eventType->value.'.')];
        }

        $defects = [];
        foreach (array_diff($expected, array_keys($payload)) as $missing) {
            $defects[] = new ParseDefect('payload.'.$missing, 'payload_missing_required', 'Required payload field is missing.');
        }
        foreach (array_diff(array_keys($payload), $expected) as $extra) {
            $defects[] = new ParseDefect('payload.'.$extra, 'payload_extra_field', 'Payload field is not part of the canonical contract.');
        }

        return $defects;
    }
```

  Also thread the version into `inferPayloadPath` so a v3-only key resolves to its own path. Change its signature to `private function inferPayloadPath(string $message, FiscalEventType $eventType, int $eventVersion = 1): string`, replace its first line with `$expected = $this->constraintValidator->payloadKeysFor($eventType, $eventVersion) ?? [];`, and pass `$eventVersion` at both `inferPayloadPath(...)` call sites inside `schemaDefects()`. The `reasonCode`/`uniqueDefects` helpers stay untouched.

- [ ] Run the unit test and confirm it PASSES:
  `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php`
  Expected: 7 tests green.

- [ ] Run the existing fiscal regression suites BY PATH to prove v1/v2 are byte-untouched:
  `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/SaleReceiptV2GoldenParityTest.php tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php`
  Expected: all green. `FiscalEventPayloadRegistryTest` may pin the old authoring version — if it asserts `2`, update THAT assertion to `3` and add an assertion that `[1, 2, 3]` are supported; do not weaken any golden-byte assertion.

- [ ] Write the failing constraint test `apps/api/tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use RuntimeException;
use Tests\TestCase;

/**
 * SALE_RECEIPT v3 cash-rounding binds (spec §4.1 + §4.4).
 *
 * Shape-only: Tests\TestCase, no RefreshDatabase.
 */
final class SaleReceiptV3PayloadConstraintTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FiscalPayloadConstraintValidator;
    }

    /**
     * TND (scale 3), zero-VAT, one line, one CASH leg.
     * exact_total 9.973 → D 0.050 → rounded 9.950, adj -0.023.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function v3Payload(array $overrides = []): array
    {
        $payload = [
            'approval_references' => [],
            'business_date' => '2026-07-27',
            'buyer' => null,
            'cash_rounding_adjustment' => '-0.023',
            'cash_rounding_denomination' => '0.050',
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Cashier V3',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-07-27T10:15:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '9.973',
                'line_vat' => '0.000',
                'name' => 'Paracétamol 500mg',
                'non_collected_subtype' => null,
                'product_id' => 'prod-v3-1',
                'quantity' => '1.000',
                'sku' => 'SKU-V3-1',
                'tax_category_code' => 'Z',
                'unit_price' => '9.973',
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '9.950',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-0000000000v3',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue de Rome'],
                'name' => 'Pharma Bio SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AAM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '9.973',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '9.950',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '9.973',
                'net_amount' => '9.973',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
            ]],
            'vat_total' => '0.000',
            'vouchers_redeemed' => [],
        ];

        // `receipt_uuid` must be a lowercase-hex UUID — fix the placeholder.
        $payload['receipt_uuid'] = '00000000-0000-4000-8000-000000000003';

        return array_merge($payload, $overrides);
    }

    public function test_v3_rounded_payload_is_accepted(): void
    {
        $payload = $this->v3Payload();

        self::assertNull($this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3
        ));

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
        $this->addToAssertionCount(1);
    }

    public function test_v3_unrounded_payload_uses_canonical_zeroes(): void
    {
        $payload = $this->v3Payload([
            'cash_rounding_adjustment' => '0.000',
            'cash_rounding_denomination' => '0.000',
            'total' => '9.973',
            'payments' => [[
                'amount' => '9.973',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
        $this->addToAssertionCount(1);
    }

    public function test_identity_fails_when_total_was_not_replaced(): void
    {
        // adj set but total left at the exact value — the V3 builder skipped
        // its "replace the total key" step.
        $payload = $this->v3Payload(['total' => '9.973']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_total_arithmetic_mismatch/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_suppression_payload_is_quarantined(): void
    {
        // total suppressed to 5.000 with a -95.000 "adjustment": the identity
        // still balances, so the BINDS are what catch it.
        $payload = $this->v3Payload([
            'total' => '5.000',
            'cash_rounding_adjustment' => '-95.000',
            'subtotal' => '100.000',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '100.000',
                'line_vat' => '0.000',
                'name' => 'Suppressed',
                'non_collected_subtype' => null,
                'product_id' => 'prod-v3-1',
                'quantity' => '1.000',
                'sku' => 'SKU-V3-1',
                'tax_category_code' => 'Z',
                'unit_price' => '100.000',
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'vat_breakdown' => [[
                'gross_amount' => '100.000',
                'net_amount' => '100.000',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
            ]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_adjustment_exceeds_half_denomination/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_zero_denominator_with_nonzero_adjustment_quarantines_and_never_divides_by_zero(): void
    {
        $payload = $this->v3Payload(['cash_rounding_denomination' => '0.000']);

        try {
            $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
            $this->fail('Expected a RuntimeException before any bcmod call.');
        } catch (\DivisionByZeroError $e) {
            $this->fail('A DivisionByZeroError escaped the quarantine path and would kill the worker: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payload_cash_rounding_denomination_not_positive', $e->getMessage());
        }
    }

    public function test_total_must_be_a_multiple_of_the_denomination(): void
    {
        // 9.951 is not a multiple of 0.050; identity kept consistent.
        $payload = $this->v3Payload([
            'total' => '9.951',
            'cash_rounding_adjustment' => '-0.022',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_total_not_multiple/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_denomination_above_the_static_cap_is_rejected(): void
    {
        $payload = $this->v3Payload([
            'cash_rounding_denomination' => '5.000',
            'cash_rounding_adjustment' => '0.000',
            'total' => '9.973',
            'payments' => [[
                'amount' => '9.973',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_denomination_above_cap/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_negative_zero_adjustment_is_rejected(): void
    {
        $payload = $this->v3Payload([
            'cash_rounding_adjustment' => '-0.000',
            'cash_rounding_denomination' => '0.000',
            'total' => '9.973',
            'payments' => [[
                'amount' => '9.973',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_money_negative_zero/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_v2_payload_carrying_a_rounding_field_is_rejected_by_both_layers(): void
    {
        $payload = $this->v3Payload();

        $keySetError = $this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT, $payload, 'operational', 2
        );
        $this->assertNotNull($keySetError);
        $this->assertStringStartsWith('payload_extra_field:', $keySetError);
        $this->assertStringContainsString('cash_rounding_adjustment', $keySetError);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_cash_rounding_forbidden_for_version/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 2);
    }

    public function test_v3_payload_missing_a_rounding_field_is_rejected(): void
    {
        $payload = $this->v3Payload();
        unset($payload['cash_rounding_denomination']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_missing_required:cash_rounding_denomination/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
    }

    public function test_scale_zero_signed_regex_accepts_a_negative_integer_adjustment(): void
    {
        // JPY-style: scale 0, 10-yen rounding.
        $payload = $this->v3Payload([
            'currency_code' => 'JPY',
            'currency_scale' => 0,
            'subtotal' => '1003',
            'vat_total' => '0',
            'transaction_discount_amount' => '0',
            'total' => '1000',
            'cash_rounding_adjustment' => '-3',
            'cash_rounding_denomination' => '10',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0',
                'line_discount_reason' => null,
                'line_subtotal' => '1003',
                'line_vat' => '0',
                'name' => 'Yen item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-jpy',
                'quantity' => '1.000',
                'sku' => 'SKU-JPY',
                'tax_category_code' => 'Z',
                'unit_price' => '1003',
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'payments' => [[
                'amount' => '1000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'vat_breakdown' => [[
                'gross_amount' => '1003',
                'net_amount' => '1003',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0',
            ]],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, 'operational', 3);
        $this->addToAssertionCount(1);
    }
}
```

- [ ] Run it and confirm it FAILS:
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php`
  Expected: v3 payloads rejected as `payload_extra_field` at the key set and `payload_total_arithmetic_mismatch` at the constraints.

- [ ] Implement the v3 field checks in `validateSaleReceiptPayload` (`FiscalPayloadConstraintValidator.php:691`). Insert this block immediately AFTER the step-3 `foreach (['subtotal', 'vat_total', 'total', 'transaction_discount_amount'] as $field)` loop:

```php
        // ---- 3a. v3 cash-rounding siblings (spec §4.4). ----
        // v1/v2 must NEVER carry these keys. The key-set gate already rejects
        // them as `payload_extra_field`, but validatePerEventConstraints is
        // reachable directly (BestEffortPayloadParser::schemaDefects), so the
        // constraint layer states the same rule independently.
        $hasAdjustment = array_key_exists('cash_rounding_adjustment', $payload);
        $hasDenomination = array_key_exists('cash_rounding_denomination', $payload);

        if ($eventVersion < 3) {
            if ($hasAdjustment || $hasDenomination) {
                throw new RuntimeException(
                    'payload_cash_rounding_forbidden_for_version:event_version='.$eventVersion
                );
            }
            // Absent fields ⇒ zero ⇒ v1/v2 identities are unchanged.
            $roundingAdjustment = bcadd('0', '0', $scale);
        } else {
            $missingRounding = [];
            if (! $hasAdjustment) {
                $missingRounding[] = 'cash_rounding_adjustment';
            }
            if (! $hasDenomination) {
                $missingRounding[] = 'cash_rounding_denomination';
            }
            if ($missingRounding !== []) {
                throw new RuntimeException('payload_missing_required:'.implode(',', $missingRounding));
            }

            $this->assertSignedMoneyString($payload, 'cash_rounding_adjustment', $scale);
            // The denomination is NON-negative and already normalized to the
            // currency scale by the policy contract (§4.2) before it can reach
            // a payload, so the existing non-negative regex is correct here.
            $this->assertMoneyString($payload, 'cash_rounding_denomination', $moneyRegex, $scale);

            $roundingAdjustment = $this->asNumericString(
                $payload['cash_rounding_adjustment'],
                'cash_rounding_adjustment',
            );
        }
```

- [ ] Fold the adjustment into step 5's identity in the same method — replace the `$rhs = bcadd($totalN, $discountAmount, $scale);` line with:

```php
        // Spec §4.1 identity (1): subtotal + vat_total == (total − adj) + discount.
        // Absent fields ⇒ adj = 0 ⇒ this reduces to the v1/v2 identity exactly.
        $rhs = bcadd(bcsub($totalN, $roundingAdjustment, $scale), $discountAmount, $scale);
```

- [ ] Run the binds immediately after step 5's identity check (normative order), in the same method:

```php
        // ---- 5a. v3 rounding binds — NORMATIVE ORDER (spec §4.1). ----
        if ($eventVersion >= 3) {
            $this->validateCashRoundingBinds($payload, $totalN, $roundingAdjustment, $scale);
        }
```

- [ ] Thread the adjustment into the aggregate-consistency call at step 9 of the same method:

```php
        $this->validateSaleReceiptAggregateConsistency($payload, $vatBreakdown, $scale, $roundingAdjustment);
```

- [ ] Update `validateSaleReceiptAggregateConsistency` (`:866`) — new trailing param and the same identity fold:

```php
    private function validateSaleReceiptAggregateConsistency(
        array $payload,
        array $vatBreakdown,
        int $scale,
        string $roundingAdjustment = '0',
    ): void {
```

  and replace `$totalPlusDiscount = bcadd($total, $discount, $scale);` with:

```php
        // v3 folds the signed cash-rounding adjustment out of `total` before
        // the NF525 aggregate identity is evaluated; on v1/v2 the default '0'
        // makes this byte-identical to the previous expression.
        /** @var numeric-string $roundingAdjustment */
        $totalPlusDiscount = bcadd(bcsub($total, $roundingAdjustment, $scale), $discount, $scale);
```

- [ ] Add the four new private helpers to the same class, immediately after `moneyRegex()`:

```php
    /**
     * Scale-aware regex for a SIGNED bcformat money string (spec §4.4).
     * Mirrors {@see moneyRegex()} with an optional leading minus; the scale-0
     * branch has NO decimal point.
     */
    private function signedMoneyRegex(int $scale): string
    {
        if ($scale === 0) {
            return '/^-?(0|[1-9]\d*)$/D';
        }

        return '/^-?(0|[1-9]\d*)\.\d{'.$scale.'}$/D';
    }

    /**
     * Assert a signed money field and reject `-0` in every spelling.
     * Canonical zero is the UNSIGNED zero at the currency scale; a signed
     * zero would produce two byte-distinct encodings of the same value and
     * break replay determinism.
     *
     * @param  array<string, mixed>  $bag
     */
    private function assertSignedMoneyString(array $bag, string $field, int $scale): void
    {
        $this->assertMoneyString($bag, $field, $this->signedMoneyRegex($scale), $scale);

        /** @var string $value */
        $value = $bag[$field];
        if (str_starts_with($value, '-') && bccomp($this->asNumericString($value, $field), '0', $scale) === 0) {
            throw new RuntimeException(sprintf(
                'payload_money_negative_zero:field=%s:value=%s',
                $field,
                $value,
            ));
        }
    }

    /**
     * Static, HISTORY-STABLE denomination cap per currency scale (spec §4.1
     * item 3). Deliberately a constant, not a config read: a policy change
     * must never retroactively invalidate a sealed receipt.
     */
    private function denominationCapForScale(int $scale): string
    {
        return match ($scale) {
            0 => '10',
            2 => '1.00',
            default => '1.000',
        };
    }

    /**
     * v3 cash-rounding binds in the NORMATIVE ORDER (spec §4.1):
     *   2a. denominator positivity FIRST — never reach bcmod with a zero
     *       divisor, because DivisionByZeroError is an `Error` that
     *       StrictCanonicalParser's `catch (RuntimeException)` does NOT catch
     *       and would kill the projection worker instead of quarantining.
     *   2b. |adj| <= denomination / 2, computed and compared at scale+1 so
     *       bcdiv truncation cannot reject a legal tie.
     *   2c. total is an exact multiple of the denomination.
     *   3.  static cap on the denomination (checked even when adj == 0).
     *
     * @param  array<string, mixed>  $payload
     * @param  numeric-string  $total
     * @param  numeric-string  $adjustment
     */
    private function validateCashRoundingBinds(array $payload, string $total, string $adjustment, int $scale): void
    {
        $denomination = $this->asNumericString(
            $payload['cash_rounding_denomination'],
            'cash_rounding_denomination',
        );

        if (bccomp($adjustment, '0', $scale) !== 0) {
            if (bccomp($denomination, '0', $scale) <= 0) {
                throw new RuntimeException(sprintf(
                    'payload_cash_rounding_denomination_not_positive:adjustment=%s:denomination=%s',
                    $adjustment,
                    $denomination,
                ));
            }

            $absAdjustment = bccomp($adjustment, '0', $scale) < 0
                ? bcmul($adjustment, '-1', $scale)
                : $adjustment;

            $half = bcdiv($denomination, '2', $scale + 1);
            if (bccomp($absAdjustment, $half, $scale + 1) > 0) {
                throw new RuntimeException(sprintf(
                    'payload_cash_rounding_adjustment_exceeds_half_denomination:adjustment=%s:half=%s',
                    $adjustment,
                    $half,
                ));
            }

            $remainder = bcmod($total, $denomination, $scale);
            if (bccomp($remainder, '0', $scale) !== 0) {
                throw new RuntimeException(sprintf(
                    'payload_cash_rounding_total_not_multiple:total=%s:denomination=%s:remainder=%s',
                    $total,
                    $denomination,
                    $remainder,
                ));
            }
        }

        $cap = $this->denominationCapForScale($scale);
        if (bccomp($denomination, $cap, $scale) > 0) {
            throw new RuntimeException(sprintf(
                'payload_cash_rounding_denomination_above_cap:denomination=%s:cap=%s',
                $denomination,
                $cap,
            ));
        }
    }
```

- [ ] Extend the DTO `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php`. Append two promoted properties at the END of the constructor (after `public array $vouchersRedeemed,`) with `= null` defaults so existing positional/named constructions keep compiling:

```php
        /**
         * v3 signed cash-rounding adjustment (`rounded_total − exact_total`).
         * NULL on v1/v2 payloads — absent means zero, never "unknown".
         */
        public ?string $cashRoundingAdjustment = null,
        /**
         * v3 applied rounding denomination, normalized at currency scale.
         * NULL on v1/v2 payloads.
         */
        public ?string $cashRoundingDenomination = null,
```

  and hydrate them in `fromArray()` using the `array_key_exists` precedent from `LineItemDTO.php:71-79` (append as the last two named arguments of the `new self(...)` call):

```php
            cashRoundingAdjustment: array_key_exists('cash_rounding_adjustment', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'cash_rounding_adjustment')
                : null,
            cashRoundingDenomination: array_key_exists('cash_rounding_denomination', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'cash_rounding_denomination')
                : null,
```

- [ ] Add reader accessors to `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SaleReceiptCanonicalView.php`, after `originalReceiptReference()`:

```php
    /**
     * Signed cash-rounding adjustment, or canonical '0' on a v1/v2 payload.
     * Callers normalize to their own storage scale — this accessor is
     * deliberately scale-free.
     */
    public function cashRoundingAdjustmentOrZero(): string
    {
        return $this->payload->cashRoundingAdjustment ?? '0';
    }

    /** Applied rounding denomination, or canonical '0' on a v1/v2 payload. */
    public function cashRoundingDenominationOrZero(): string
    {
        return $this->payload->cashRoundingDenomination ?? '0';
    }
```

  `CanonicalPayloadReader::forSaleReceipt()` needs NO change: it already passes the whole `SaleReceiptPayload` into the view as `payload:` (`CanonicalPayloadReader.php:106-115`).

- [ ] Run both new tests and confirm they PASS:
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php`
  Expected: 11 + 7 tests green.

- [ ] Run the v1/v2 regression suites BY PATH and confirm NOTHING changed:
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php tests/Unit/Fiscal/SaleReceiptV2GoldenParityTest.php tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php tests/Unit/Fiscal/BestEffortPayloadParserTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php`
  Expected: all green with no assertion edits. If a golden-byte test fails, STOP — the v1/v2 path was mutated and the change must be reverted, not the fixture.

- [ ] Run the parse-failure repair suite (the version-threaded quarantine path):
  `cd apps/api && ./vendor/bin/phpunit --filter ParseFailureResolution tests/Feature/Fiscal`

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/Fiscal && ./vendor/bin/phpstan analyse app/Modules/Fiscal --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Modules/Fiscal apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php apps/api/tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php apps/api/tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T6: SALE_RECEIPT v3 payload contract + version threading

Adds the NAMED 30-key SALE_RECEIPT_PAYLOAD_KEYS_V3 constant and a
version-aware payloadKeysFor() accessor, threads event_version through
validatePayloadKeySet at all four call sites (including the quarantine
repair path) and refactors BestEffortPayloadParser onto the accessor.

Implements the signed money regex with -0 rejection, the folded
subtotal+vat == (total-adj)+discount identity, and the §4.1 binds in the
normative order — denominator positivity FIRST so a DivisionByZeroError can
never escape the RuntimeException-only quarantine catch, half-denomination
compared at scale+1, exact-multiple check, and static per-scale caps.

Registry authors 3 and supports [1,2,3]; v1/v2 golden bytes untouched.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 7: Migration B — `pos_receipts` rounding columns, guarded CHECK swap, journal partial unique indexes

**Files:**
- Create `apps/api/database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php`
- Create `apps/api/tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php`
- Modify `apps/api/app/Modules/POS/Domain/Receipt.php` (`@property` block — anchor `@property numeric-string|null $tolerance_writeoff` line 60; `$fillable` — anchor `'tolerance_writeoff',`; `casts()` line 226 — anchor `'tolerance_writeoff' => 'decimal:3',` line 239)

**Interfaces:**
- Produces: `pos_receipts.cash_rounding_adjustment DECIMAL(12,3) NULL` (sibling-column consistency with `change_due decimal(12,3)`, `2026_04_23_100000:24`) and `pos_receipts.cash_rounding_denomination DECIMAL(15,4) NULL`.
- Produces: `pos_receipts_totals` re-defined as `CHECK (total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0))`, wrapped in the `pgsql` driver guard.
- Produces: `uniq_je_source_pos_cash_rounding` and `uniq_je_source_pos_tolerance_bridge` partial unique indexes on `journal_entries (source_type, source_id)`.
- Consumes: existing constraint definition at `2026_03_09_200000_add_return_fields_to_pos_receipts.php:41-42` (the pgsql-driver-guard precedent is line 39).

**Load-bearing details (do not simplify):**
- `COALESCE(..., 0)` is mandatory. Without it the CHECK expression evaluates to NULL on every legacy row and PostgreSQL treats a NULL CHECK as SATISFIED — the totals identity would silently stop being enforced for all pre-v3 data.
- The whole DROP/ADD must sit inside `if (DB::connection()->getDriverName() === 'pgsql')`: SQLite has no `ALTER TABLE ... DROP CONSTRAINT` and the constraint is pgsql-only, so an unguarded statement breaks every SQLite suite.
- Sibling constraints are audited clean (spec §4.5): `pos_receipts_return_logic`, the fiscal-status/sequence CHECKs, `pos_receipt_payments_amount CHECK (amount > 0)` (tendered semantics — safe), and the line/VAT CHECKs all bind identities rounding never touches.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php` (Postgres-only — it asserts a pgsql CHECK):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration B. MUST run on Postgres: `pos_receipts_totals` is a pgsql-only
 * CHECK and is completely invisible on SQLite.
 */
final class PosReceiptsCashRoundingCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pos_receipts_totals is a pgsql-only CHECK constraint.');
        }
    }

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_denomination'));
    }

    public function test_totals_check_now_includes_the_coalesced_adjustment(): void
    {
        $definition = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'pos_receipts_totals'"
        );

        $this->assertNotNull($definition);
        $this->assertStringContainsString('cash_rounding_adjustment', (string) $definition->def);
        $this->assertStringContainsString('COALESCE', strtoupper((string) $definition->def));
    }

    public function test_legacy_null_adjustment_rows_still_enforce_the_identity(): void
    {
        // COALESCE is load-bearing: without it the CHECK evaluates to NULL on
        // a legacy row and PostgreSQL treats that as SATISFIED.
        $this->expectException(QueryException::class);

        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '11.000', // wrong on purpose
            'cash_rounding_adjustment' => null,
        ]));
    }

    public function test_rounded_row_with_nonzero_adjustment_is_accepted(): void
    {
        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.950',
            'cash_rounding_adjustment' => '-0.023',
        ]));

        $this->assertSame(1, DB::table('pos_receipts')->count());
    }

    public function test_rounded_row_whose_total_ignores_the_adjustment_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.973',
            'cash_rounding_adjustment' => '-0.023',
        ]));
    }

    public function test_journal_partial_unique_indexes_exist(): void
    {
        $names = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'journal_entries'"
        ))->pluck('indexname')->all();

        $this->assertContains('uniq_je_source_pos_cash_rounding', $names);
        $this->assertContains('uniq_je_source_pos_tolerance_bridge', $names);
    }

    /**
     * Minimal pos_receipts row. Fill every NOT-NULL column the current schema
     * requires — inspect with `\d pos_receipts` and extend if the schema has
     * moved; do NOT relax a constraint to make this pass.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function receiptRow(array $overrides): array
    {
        $uuid = fn (): string => (string) \Illuminate\Support\Str::uuid();

        return array_merge([
            'id' => $uuid(),
            'tenant_id' => $uuid(),
            'company_id' => $uuid(),
            'location_id' => $uuid(),
            'terminal_id' => $uuid(),
            'receipt_number' => 'T001-C042-L01-POS03-2026-'.random_int(10000000, 99999999),
            'receipt_type' => 'sale',
            'chain_sequence' => random_int(1, 1000000),
            'receipt_year' => 2026,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => str_repeat('0', 64),
            'vat_breakdown_hash' => str_repeat('b', 64),
            'payment_methods_hash' => str_repeat('c', 64),
            'posted_at' => now(),
            'cashier_id' => $uuid(),
            'cashier_name' => 'Check Test Cashier',
            'currency' => 'TND',
            'fiscal_status' => 'fiscalized',
            'is_voided' => false,
            'is_training' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
```

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php`
  Expected: columns absent, constraint definition without `cash_rounding_adjustment`, indexes missing.

- [ ] Create `apps/api/database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration B.
     *
     * 1. `pos_receipts.cash_rounding_adjustment` decimal(12,3) — SIGNED,
     *    sibling-consistent with `change_due` decimal(12,3).
     *    `cash_rounding_denomination` decimal(15,4) mirrors the
     *    country_payment_settings storage type so the projection's
     *    reconciliation comparison is a scale-4 bccomp, not a string compare.
     * 2. Re-defines `pos_receipts_totals` so a rounded total satisfies the
     *    identity. COALESCE is LOAD-BEARING: without it the expression is
     *    NULL on every legacy row and PostgreSQL silently stops enforcing the
     *    identity for all pre-v3 data.
     * 3. Partial unique indexes for the two new journal source_type literals
     *    (procurement exemplar, `2026_06_26_120000:44-48` — unscoped; the
     *    bridge creates + posts inside one transaction, there is no Draft
     *    re-insert path that would need a status-scoped index).
     *
     * The whole constraint swap sits behind the pgsql driver guard
     * (`2026_03_09_200000:39` precedent): SQLite has no DROP CONSTRAINT and
     * the constraint is pgsql-only.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pos_receipts')) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment')) {
                $table->decimal('cash_rounding_adjustment', 12, 3)
                    ->nullable()
                    ->after('tolerance_writeoff');
            }
            if (! Schema::hasColumn('pos_receipts', 'cash_rounding_denomination')) {
                $table->decimal('cash_rounding_denomination', 15, 4)
                    ->nullable()
                    ->after('cash_rounding_adjustment');
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement(
                'ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK ('.
                'total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)'.
                ')'
            );

            DB::statement(
                "COMMENT ON COLUMN pos_receipts.cash_rounding_adjustment IS ".
                "'Signed cash-rounding adjustment (rounded_total - exact_total) from the signed v3 SALE_RECEIPT payload. NULL on v1/v2 rows.'"
            );
            DB::statement(
                "COMMENT ON COLUMN pos_receipts.cash_rounding_denomination IS ".
                "'Denomination that was applied, as signed by the device. Compared to live policy by bccomp, never string equality.'"
            );
        }

        if (Schema::hasTable('journal_entries')) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uniq_je_source_pos_cash_rounding
                    ON journal_entries (source_type, source_id)
                    WHERE source_type IN ('pos_cash_rounding', 'pos_cash_rounding_refund')
                SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uniq_je_source_pos_tolerance_bridge
                    ON journal_entries (source_type, source_id)
                    WHERE source_type = 'pos_tolerance_bridge'
                SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_entries')) {
            DB::statement('DROP INDEX IF EXISTS uniq_je_source_pos_cash_rounding');
            DB::statement('DROP INDEX IF EXISTS uniq_je_source_pos_tolerance_bridge');
        }

        if (! Schema::hasTable('pos_receipts')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount - discount_amount)');
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropColumn(['cash_rounding_adjustment', 'cash_rounding_denomination']);
        });
    }
};
```

- [ ] Extend `apps/api/app/Modules/POS/Domain/Receipt.php`:
  - after the `@property numeric-string|null $tolerance_writeoff ...` docblock line add:

```php
 * @property numeric-string|null $cash_rounding_adjustment Signed cash-rounding adjustment (rounded − exact); NULL on v1/v2 rows
 * @property numeric-string|null $cash_rounding_denomination Denomination applied, as signed by the device; NULL on v1/v2 rows
```

  - after `'tolerance_writeoff',` in `$fillable` add `'cash_rounding_adjustment',` and `'cash_rounding_denomination',`
  - after `'tolerance_writeoff' => 'decimal:3',` in `casts()` add:

```php
            'cash_rounding_adjustment' => 'decimal:3',
            'cash_rounding_denomination' => 'decimal:4',
```

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php`
  Expected: 6 tests green.

- [ ] Prove SQLite is unaffected — run a SQLite-backed POS suite BY PATH:
  `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`
  Expected: green (the migration's guarded block is skipped, the two columns are added).

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/POS/Domain/Receipt.php database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php && ./vendor/bin/phpstan analyse app/Modules/POS/Domain/Receipt.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php apps/api/app/Modules/POS/Domain/Receipt.php apps/api/tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T7: pos_receipts rounding columns + guarded CHECK swap

Adds cash_rounding_adjustment decimal(12,3) and cash_rounding_denomination
decimal(15,4), re-defines pos_receipts_totals with a COALESCEd adjustment
term inside the pgsql driver guard (COALESCE is load-bearing — without it
PostgreSQL stops enforcing the identity on every legacy row), and creates
the two partial unique indexes for the new journal source_type literals.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 8: `PosCoreReceiptProjection` — v3-gated writes, policy reconciliation, loyalty base

**Files:**
- Modify `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (`SCALE` const line 116; constructor line 134; `apply()` line 158; normalization block lines 194-197; `$row` array lines 256-301; `earnLoyaltyPoints(...)` call line 328 and definition line 870; `normalize()` line 1321)
- Create `apps/api/tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php`

**Interfaces:**
- Consumes: `SaleReceiptCanonicalView::cashRoundingAdjustmentOrZero(): string` / `::cashRoundingDenominationOrZero(): string` (Task 6).
- Consumes: `FiscalEvent::$event_version: int`.
- Consumes: `App\Modules\Compliance\Services\AuditService::record(string $companyId, ?string $userId, string $eventType, string $aggregateType, string $aggregateId, array $payload = [], array $metadata = []): AuditEvent` (verified `AuditService.php:22-30`).
- Changes: `earnLoyaltyPoints(string $receiptId, FiscalEvent $event, SaleReceiptCanonicalView $view, SaleReceiptPayload $payload, ReceiptType $receiptType, string $earnBase): void` — the last parameter is renamed from `$totalNorm` and now receives `total − adjustment`.
- Produces (v3 only): `pos_receipts.cash_rounding_adjustment`, `.cash_rounding_denomination`, `.change_due = max(0, Σ payments − total)`, `.tolerance_writeoff = max(0, total − Σ payments)` (NULL when `training_flag`).
- Produces: audit event `pos.rounding.policy_mismatch`, `aggregate_type = 'fiscal_event'`, `aggregate_id = {fiscal_event_id}`, idempotency-probed before writing.

**Rules:**
- v2 events write NOTHING new — all four columns stay untouched (NULL), consistent-by-omission with the v3-gated GL so the read model and the ledger can never diverge. The v2 tail's missing write-offs are part of the historical-drift quantification ticket, not this task.
- Every write rides the existing `insertReceiptOnConflictDoNothing` gate (`:317-328`) so it is replay-idempotent by construction.
- Scale: reuse the projection's existing `normalize()` (const `SCALE = 3`) for the adjustment, and a new scale-4 normalizer for the denomination (matching its decimal(15,4) column). The fixed-scale debt is inherited and stays noted, not fixed.
- The policy lookup runs on a Horizon worker with NO `CompanyContext` — resolve the country row by direct tenant-DB query; never call a no-arg `getScale()` here.
- Compare the signed denomination to policy with `bccomp` at scale 4, NEVER string equality: PostgreSQL returns `0.0500` from decimal(15,4) while the signed value is `0.050`, so string equality would false-alarm on every rounded receipt.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php`. Build it by copying the fixture scaffolding from `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` (`setUp()` at `:96`, `storeSaleReceiptFiscalEvent()` at `:1365`, `canonicalEncode()` at `:1583`) and extending the event builder with three new parameters — `?string $cashRoundingAdjustment = null, ?string $cashRoundingDenomination = null, int $eventVersion = 1` — that (a) stamp both `fiscal_events.event_version` and the canonical envelope `event_version`, and (b) add the two payload keys only when `$eventVersion >= 3`. The required test methods are:

```php
    public function test_v3_event_writes_the_rounding_columns(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        app(PosCoreReceiptProjection::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $receipt->cash_rounding_adjustment, '-0.023', 3));
        $this->assertSame(0, bccomp((string) $receipt->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_v2_event_writes_nothing_new(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(total: '9.973', subtotal: '9.973', eventVersion: 2);

        app(PosCoreReceiptProjection::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertNull($receipt->cash_rounding_adjustment);
        $this->assertNull($receipt->cash_rounding_denomination);
        $this->assertNull($receipt->change_due);
        $this->assertNull($receipt->tolerance_writeoff);
    }

    public function test_v3_shortfall_becomes_a_tolerance_writeoff(): void
    {
        // total 9.950, tendered 9.900 => shortfall 0.050
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        app(PosCoreReceiptProjection::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $receipt->tolerance_writeoff, '0.050', 3));
        $this->assertSame(0, bccomp((string) $receipt->change_due, '0.000', 3));
    }

    public function test_v3_over_tender_becomes_change_due(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            paymentLinesOverride: [['amount' => '10.000', 'method_code' => 'CASH']],
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        app(PosCoreReceiptProjection::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $receipt->change_due, '0.050', 3));
        $this->assertSame(0, bccomp((string) $receipt->tolerance_writeoff, '0.000', 3));
    }

    public function test_training_receipt_records_no_tolerance_writeoff(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            training: true,
        );

        app(PosCoreReceiptProjection::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertNull($receipt->tolerance_writeoff);
    }

    public function test_replay_is_idempotent(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $projection = app(PosCoreReceiptProjection::class);
        $projection->apply($event);
        $projection->apply($event);

        $this->assertSame(1, Receipt::query()->where('fiscal_event_id', $event->id)->count());
    }

    public function test_loyalty_earn_base_excludes_the_rounding_adjustment(): void
    {
        // Round UP so the earn base is provably lower than the collected total.
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '10.000',
            subtotal: '9.973',
            cashRoundingAdjustment: '0.027',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $captured = null;
        $this->mockLoyaltyEarningCapturing($captured);

        app(PosCoreReceiptProjection::class)->apply($event);

        $this->assertNotNull($captured);
        $this->assertSame(0, bccomp($captured->earnBase, '9.973', 3));
    }

    public function test_policy_mismatch_projects_and_alerts(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.1000',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        app(PosCoreReceiptProjection::class)->apply($event);

        $this->assertDatabaseHas('pos_receipts', ['fiscal_event_id' => $event->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_matching_policy_denomination_does_not_alert_despite_differing_string_scale(): void
    {
        // PG returns '0.0500' from decimal(15,4); the signed value is '0.050'.
        // A string comparison would false-alarm on EVERY rounded receipt.
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.0500',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        app(PosCoreReceiptProjection::class)->apply($event);

        $this->assertDatabaseMissing('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_id' => $event->id,
        ]);
    }
```

  For `mockLoyaltyEarningCapturing`, bind a test double for `App\Shared\Contracts\Loyalty\LoyaltyEarningContract` (confirm the exact interface FQN with `grep -n "LoyaltyEarningContract" apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`) that records the `SaleEarnContext` it receives.

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php`
  Expected: columns NULL on v3, earn base still the rounded total, no audit row.

- [ ] Inject `AuditService` into the projection constructor (`:134` region), keeping every existing dependency:

```php
        private readonly AuditService $auditService,
```

  and add `use App\Modules\Compliance\Services\AuditService;` to the imports.

- [ ] Add a denomination scale constant + normalizer beside `SCALE` (`:116`) and `normalize()` (`:1321`):

```php
    /**
     * `pos_receipts.cash_rounding_denomination` is decimal(15,4), matching
     * `country_payment_settings.cash_rounding_denomination`, so the policy
     * reconciliation below is a scale-4 bccomp rather than a string compare.
     */
    private const int DENOMINATION_SCALE = 4;
```

```php
    /**
     * Normalize a denomination to the decimal(15,4) storage scale.
     *
     * @return numeric-string
     */
    private function normalizeDenomination(string $value): string
    {
        /** @var numeric-string $value */
        /** @var numeric-string $normalized */
        $normalized = bcadd($value, '0', self::DENOMINATION_SCALE);

        return $normalized;
    }
```

- [ ] In `apply()`, immediately after the four `$…Norm` assignments (`:194-197`), add the v3 derivation block:

```php
            // ---- v3-gated derivations (spec §4.5). event_version is the
            // ---- SINGLE cutover discriminator for both this read model and
            // ---- the Treasury bridge, so the two can never diverge.
            $isV3 = $event->event_version >= 3;
            $roundingAdjustmentNorm = null;
            $roundingDenominationNorm = null;
            $changeDueNorm = null;
            $toleranceWriteoffNorm = null;

            if ($isV3) {
                $roundingAdjustmentNorm = $this->normalize($view->cashRoundingAdjustmentOrZero());
                $roundingDenominationNorm = $this->normalizeDenomination($view->cashRoundingDenominationOrZero());

                $tenderedNorm = $this->normalize('0');
                foreach ($view->payments as $paymentLine) {
                    $tenderedNorm = bcadd($tenderedNorm, $this->normalize($paymentLine->amount), self::SCALE);
                }

                $overTender = bcsub($tenderedNorm, $totalNorm, self::SCALE);
                $changeDueNorm = bccomp($overTender, '0', self::SCALE) > 0
                    ? $overTender
                    : $this->normalize('0');

                $shortfall = bcsub($totalNorm, $tenderedNorm, self::SCALE);
                // Training receipts never book a write-off (they never reach GL).
                $toleranceWriteoffNorm = ($payload->trainingFlag === true)
                    ? null
                    : (bccomp($shortfall, '0', self::SCALE) > 0 ? $shortfall : $this->normalize('0'));
            }
```

- [ ] Add the columns to `$row` — insert immediately after the existing `'total' => $totalNorm,` entry (`:277`), but ONLY for v3 so a v2 insert stays byte-identical to today. Because `$row` is a literal array, append the v3 keys after the array literal and before the `pos_receipts_return_logic` block (`:313`):

```php
            if ($isV3) {
                $row['cash_rounding_adjustment'] = $roundingAdjustmentNorm;
                $row['cash_rounding_denomination'] = $roundingDenominationNorm;
                $row['change_due'] = $changeDueNorm;
                $row['tolerance_writeoff'] = $toleranceWriteoffNorm;
            }
```

- [ ] Change the loyalty call (`:328`) to pass the exact sale value:

```php
            // Spec §4.5 consumer matrix: loyalty earns on the SALE VALUE
            // (total − adj), never on the rounded amount collected.
            $earnBase = $isV3
                ? bcsub($totalNorm, (string) $roundingAdjustmentNorm, self::SCALE)
                : $totalNorm;

            $this->earnLoyaltyPoints($receiptId, $event, $view, $payload, $receiptTypeEnum, $earnBase);
```

  and rename the parameter in the method definition (`:870-877`) from `string $totalNorm` to `string $earnBase`, updating the single `earnBase: $totalNorm,` argument (`:893`) to `earnBase: $earnBase,`.

- [ ] Add the policy reconciliation call at the very end of the transaction body in `apply()`, after `applyStockMovementForLines(...)` (`:329`):

```php
            if ($isV3 && bccomp((string) $roundingAdjustmentNorm, '0', self::SCALE) !== 0) {
                $this->reconcileRoundingPolicy($event, (string) $roundingDenominationNorm);
            }
```

- [ ] Add the reconciliation method to the projection:

```php
    /**
     * Flag a projected receipt whose SIGNED denomination no longer matches the
     * live policy (spec §4.5). The receipt still projects — the signature is
     * the fiscal authority; this is drift telemetry, not a gate.
     *
     * Runs on a Horizon worker with NO CompanyContext: the country row is read
     * by direct query and every comparison is bcmath at the storage scale.
     * NEVER string-compare here — PostgreSQL returns `0.0500` from
     * decimal(15,4) while the signed value is `0.050`, so string equality
     * would false-alarm on every rounded receipt.
     */
    private function reconcileRoundingPolicy(FiscalEvent $event, string $signedDenomination): void
    {
        $countryCode = DB::table('companies')
            ->where('id', $event->company_id)
            ->value('country_code');

        $policyDenomination = is_string($countryCode)
            ? DB::table('country_payment_settings')
                ->where('country_code', $countryCode)
                ->value('cash_rounding_denomination')
            : null;

        $matches = is_string($policyDenomination)
            && is_numeric($policyDenomination)
            && bccomp($signedDenomination, $policyDenomination, self::DENOMINATION_SCALE) === 0;

        if ($matches) {
            return;
        }

        $alreadyRecorded = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.rounding.policy_mismatch')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $alreadyRecorded) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $event->operator_id,
                eventType: 'pos.rounding.policy_mismatch',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'signed_denomination' => $signedDenomination,
                    'policy_denomination' => is_string($policyDenomination) ? $policyDenomination : null,
                    'country_code' => is_string($countryCode) ? $countryCode : null,
                ],
            );
        }

        Log::warning('POS receipt signed a rounding denomination that no longer matches live policy.', [
            'fiscal_event_id' => $event->id,
            'signed_denomination' => $signedDenomination,
            'policy_denomination' => is_string($policyDenomination) ? $policyDenomination : null,
        ]);
    }
```

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php`
  Expected: 9 tests green.

- [ ] Run the existing projection suites BY PATH and confirm v1/v2 behavior is unchanged:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php tests/Feature/Fiscal/PosCoreReceiptProjectionD16Test.php tests/Feature/Fiscal/PosCoreReceiptProjectionRefundStockTest.php tests/Feature/Fiscal/PosCoreReceiptProjectionRefundNoDecrementTest.php tests/Feature/Fiscal/PosCoreReceiptProjectionVariantStockTest.php tests/Feature/POS/PosCoreReceiptProjectionLoyaltyEarnTest.php`
  Expected: all green.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php && ./vendor/bin/phpstan analyse app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T8: v3-gated projection writes + policy reconciliation

For event_version >= 3 only, the projection writes cash_rounding_adjustment,
cash_rounding_denomination, change_due and tolerance_writeoff (NULL on
training), moves the loyalty earn base to total − adjustment, and flags
signed-vs-live denomination drift as pos.rounding.policy_mismatch. The
comparison is bccomp at scale 4 — a string compare would false-alarm on
every rounded receipt because PG renders decimal(15,4) as 0.0500.

v2 events write nothing new, pinned by an explicit regression test.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 9: `TreasuryReceiptBridge` — change-netting pre-pass + zero-leg suppression

**Files:**
- Modify `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` (imports lines 7-33; `apply()` line 181; legacy null-key short-circuit lines 307-316; canonical view read line 326; payment loop lines 343-357; `projectPaymentLineFromCanonical()` signature lines 392-401 and its three raw-amount consumers at `:402` (`$amount`), `:634` (`Payment::create`), `:712` (`MovementIntent`); `handleMaturityRefundLeg()` instrument match line 795; alert precedent `recordMaturityRefundAlert()` lines 829-869)
- Create `apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php`
- (The `docs/architecture/precision-contract.md` write-up is deferred to Task 13; only in-code docblocks land in this task.)

**Interfaces:**
- Produces: `TreasuryReceiptBridge::computeNettedAmounts(FiscalEvent $event, SaleReceiptCanonicalView $view, Receipt $receipt): array<int, string>` — index-aligned with `$view->payments`; returns raw tendered amounts unchanged when `event_version < 3`.
- Changes: `projectPaymentLineFromCanonical(FiscalEvent $event, Receipt $receipt, PaymentDTO $line, int $index, int $totalLines, bool $isRefund, ?string $originalEventId, ?string $terminalLocationId, string $nettedAmount, int $currencyScale): void` — two new trailing parameters.
- Produces: audit event `pos.change.exceeds_cash_legs` (same `AuditService::record` + `Log::warning` shape as `recordMaturityRefundAlert`, `aggregate_type = 'fiscal_event'`, `aggregate_id = {fiscal_event_id}`).
- Consumes: `PaymentMethod::$is_cash_tender` (Task 1); `SaleReceiptCanonicalView::$payload->currencyScale`.

**Two-semantics rule (document in BOTH places):**
- `payments.amount` (Treasury) = **RETAINED** — the netted amount, what the business actually kept.
- `pos_receipt_payments.amount` (POS read model) = **TENDERED** — what the customer handed over.
Add this as a docblock on `computeNettedAmounts()` AND on `PosCoreReceiptProjection::writePayments()`.

**Netting mechanics (spec §4.6, gated on `event_version >= 3`):**
- Pre-pass runs in `apply()` AFTER the legacy null-key short-circuit (`:307-316`) so a legacy event still returns before any new work.
- Iterate `$view->payments` in canonical index order; classify each leg via `is_cash_tender`.
- `change = max(0, Σ legs − total)`; subtract from the LAST cash leg first, cascading backwards.
- Zero-netted legs write NO Payment, NO GL entry and NO movement (`repository_movements` has `CHECK (amount > 0)`; ordinal holes are legal because `payments_idempotency_key_uniq` is a PARTIAL index, `2026_07_08_150000:44-46`).
- Maturity legs are non-cash by construction and are therefore NEVER netted — `handleMaturityRefundLeg()`'s instrument match at `:795` keeps using the TENDERED `$line->amount`. State this as an invariant in the docblock and pin it with a test.
- `change > Σ cash legs` (a foreign/malformed event): net what the cash legs allow, leave the remainder, and raise `pos.change.exceeds_cash_legs`.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php`. Build it from `apps/api/tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php` — reuse `setUp()` (`:93`), `seedPosReceiptRowFor()` (`:542`), `storeSaleReceiptFiscalEvent()` (`:680`) and `canonicalEncode()` (`:846`), extending the event builder with `?string $cashRoundingAdjustment = null, ?string $cashRoundingDenomination = null, int $eventVersion = 1` exactly as in Task 8. Seed a `CASH` method with `is_cash_tender = true` and a `CARD` method with `is_cash_tender = false`. Required methods:

```php
    public function test_v2_event_is_untouched_by_netting(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 2,
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $payment->amount, '12.00', 2), 'v1/v2 must post the TENDERED amount, unchanged.');
    }

    public function test_v3_single_cash_leg_is_netted_down_by_the_change(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $payment->amount, '10.00', 2));
    }

    public function test_v3_change_cascades_backwards_across_cash_legs(): void
    {
        // Legs: CARD 4.00, CASH 3.00, CASH 5.00 ; total 10.00 ; change 2.00.
        // Cascade: last CASH 5.00 -> 3.00 ; earlier legs untouched.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '4.00', 'method_code' => 'CARD'],
                ['amount' => '3.00', 'method_code' => 'CASH'],
                ['amount' => '5.00', 'method_code' => 'CASH'],
            ],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = Payment::query()
            ->where('fiscal_event_id', $event->id)
            ->pluck('amount', 'idempotency_key');

        $this->assertSame(0, bccomp((string) $byLeg['fiscal_event:'.$event->id.':payment:0'], '4.00', 2));
        $this->assertSame(0, bccomp((string) $byLeg['fiscal_event:'.$event->id.':payment:1'], '3.00', 2));
        $this->assertSame(0, bccomp((string) $byLeg['fiscal_event:'.$event->id.':payment:2'], '3.00', 2));
    }

    public function test_v3_fully_netted_leg_writes_no_payment_gl_or_movement(): void
    {
        // Legs: CASH 4.00, CASH 8.00 ; total 4.00 ; change 8.00 kills leg 1.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '4.00', 'method_code' => 'CASH'],
                ['amount' => '8.00', 'method_code' => 'CASH'],
            ],
            total: '4.00',
            subtotal: '4.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('payments', [
            'idempotency_key' => 'fiscal_event:'.$event->id.':payment:1',
        ]);
        $this->assertDatabaseMissing('repository_movements', [
            'idempotency_key' => 'fiscal_event:'.$event->id.':payment:1',
        ]);
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
    }

    public function test_v3_change_exceeding_all_cash_legs_alerts_and_nets_what_it_can(): void
    {
        // CARD 20.00 only ; total 10.00 ; change 10.00 but no cash to net.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '20.00', 'method_code' => 'CARD']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $payment->amount, '20.00', 2), 'Non-cash legs are never netted.');
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.change.exceeds_cash_legs',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_v3_replay_with_a_pre_seeded_tendered_payment_and_movement_is_idempotent(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = app(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, DB::table('repository_movements')
            ->where('idempotency_key', 'fiscal_event:'.$event->id.':payment:0')
            ->count());

        // The movement port throws IdempotencyConflictException on an amount
        // mismatch for an existing key — a second apply() proves the netted
        // amount is deterministic across replays.
        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $payment->amount, '10.00', 2));
    }

    public function test_maturity_leg_keeps_its_tendered_amount(): void
    {
        // CHECK is non-cash and has maturity: it must never be netted, so the
        // instrument match on the raw amount keeps working.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '10.00', 'method_code' => 'CHECK', 'instrument_serial' => 'CHK-1', 'instrument_type' => 'cheque'],
                ['amount' => '4.00', 'method_code' => 'CASH'],
            ],
            total: '12.00',
            subtotal: '12.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = Payment::query()
            ->where('fiscal_event_id', $event->id)
            ->pluck('amount', 'idempotency_key');

        $this->assertSame(0, bccomp((string) $byLeg['fiscal_event:'.$event->id.':payment:0'], '10.00', 2));
        $this->assertSame(0, bccomp((string) $byLeg['fiscal_event:'.$event->id.':payment:1'], '2.00', 2));
    }
```

- [ ] Run it on Postgres and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php`
  Expected: every v3 test posts the tendered amount; the zero-leg test fails on the `repository_movements` `CHECK (amount > 0)` or writes a second Payment.

- [ ] Add the import to the bridge:

```php
use App\Modules\Fiscal\Domain\DTOs\Canonical\SaleReceiptCanonicalView;
```

- [ ] In `apply()`, immediately after `$view = $this->canonicalReader->forSaleReceipt($event);` (`:326`) and BEFORE the `$invoiceTypeCode` read, insert:

```php
            // Spec §4.6 netting pre-pass. Gated on event_version >= 3 — the
            // same single discriminator PosCoreReceiptProjection uses, so the
            // read model and the ledger can never disagree about a receipt.
            // Placed AFTER the legacy null-key short-circuit above so a
            // pre-Task-20 event still returns untouched.
            $nettedAmounts = $this->computeNettedAmounts($event, $view, $receipt);
            $currencyScale = $view->payload->currencyScale;
```

- [ ] Thread the netted amount + scale into the loop (`:343-357`):

```php
            $totalLines = count($view->payments);
            $index = 0;
            foreach ($view->payments as $payment) {
                $this->projectPaymentLineFromCanonical(
                    $event,
                    $receipt,
                    $payment,
                    $index,
                    $totalLines,
                    $isRefund,
                    $originalEventId,
                    $terminalLocationId,
                    $nettedAmounts[$index] ?? $payment->amount,
                    $currencyScale,
                );
                $index++;
            }
```

- [ ] Add the pre-pass method to the bridge:

```php
    /**
     * Resolve the RETAINED amount per canonical tender leg (spec §4.6).
     *
     * **Two-semantics rule.** `payments.amount` (Treasury) is the RETAINED
     * amount — what the business kept. `pos_receipt_payments.amount` (the POS
     * read model) is the TENDERED amount — what the customer handed over.
     * The canonical payload carries TENDERED; this pre-pass converts it.
     *
     * Change (`Σ legs − total`) is subtracted from the LAST cash leg first,
     * cascading backwards in canonical index order — deterministic across
     * replays because the payload is immutable.
     *
     * **Invariant:** maturity legs are non-cash by construction, so they are
     * never netted. That is what keeps `handleMaturityRefundLeg()`'s
     * raw-amount instrument match correct.
     *
     * v1/v2 events return the tendered amounts unchanged.
     *
     * @return array<int, string> index-aligned with $view->payments
     */
    private function computeNettedAmounts(
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
        Receipt $receipt,
    ): array {
        $amounts = [];
        foreach ($view->payments as $index => $line) {
            $amounts[$index] = $line->amount;
        }

        if ($event->event_version < 3) {
            return $amounts;
        }

        $scale = $view->payload->currencyScale;
        $total = $view->payload->total;

        $sum = bcadd('0', '0', $scale);
        foreach ($amounts as $amount) {
            if (! is_numeric($amount)) {
                throw new RuntimeException(sprintf(
                    'TreasuryReceiptBridge: payment amount %s is not numeric for fiscal_event %s',
                    $amount,
                    $event->id,
                ));
            }
            /** @var numeric-string $amount */
            $sum = bcadd($sum, $amount, $scale);
        }

        /** @var numeric-string $total */
        $change = bcsub($sum, $total, $scale);
        if (bccomp($change, '0', $scale) <= 0) {
            return $amounts;
        }

        $remaining = $change;

        // Resolve cash-ness ONCE per method code for the whole event.
        $cashByCode = [];
        foreach ($view->payments as $line) {
            $code = $line->methodCode;
            if (array_key_exists($code, $cashByCode)) {
                continue;
            }
            $methodId = $this->paymentMethodResolver->resolveByCode(
                $event->tenant_id,
                $event->company_id,
                $code,
            );
            $method = $methodId === null
                ? null
                : PaymentMethod::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->where('company_id', $event->company_id)
                    ->find($methodId);
            $cashByCode[$code] = $method !== null && $method->is_cash_tender === true;
        }

        for ($i = count($amounts) - 1; $i >= 0; $i--) {
            if (bccomp($remaining, '0', $scale) <= 0) {
                break;
            }
            if ($cashByCode[$view->payments[$i]->methodCode] !== true) {
                continue;
            }

            /** @var numeric-string $legAmount */
            $legAmount = $amounts[$i];
            $deduction = bccomp($legAmount, $remaining, $scale) <= 0 ? $legAmount : $remaining;
            $amounts[$i] = bcsub($legAmount, $deduction, $scale);
            $remaining = bcsub($remaining, $deduction, $scale);
        }

        if (bccomp($remaining, '0', $scale) > 0) {
            $this->recordChangeExceedsCashLegsAlert($event, $receipt, $change, $remaining);
        }

        return $amounts;
    }

    /**
     * Durable alert for a foreign/malformed event whose change exceeds the sum
     * of its cash legs. Same idempotency-guarded audit_events pattern as
     * {@see recordMaturityRefundAlert()}.
     */
    private function recordChangeExceedsCashLegsAlert(
        FiscalEvent $event,
        Receipt $receipt,
        string $change,
        string $unnetted,
    ): void {
        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.change.exceeds_cash_legs')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $receipt->cashier_id,
                eventType: 'pos.change.exceeds_cash_legs',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'change' => $change,
                    'unnetted_remainder' => $unnetted,
                ],
            );
        }

        Log::warning('POS receipt change exceeds the sum of its cash tender legs; netted what cash allowed.', [
            'fiscal_event_id' => $event->id,
            'change' => $change,
            'unnetted_remainder' => $unnetted,
        ]);
    }
```

- [ ] Re-point the three raw-amount consumers in `projectPaymentLineFromCanonical`. Change the signature (`:392-401`) to add the two trailing parameters:

```php
        ?string $terminalLocationId,
        string $nettedAmount,
        int $currencyScale,
    ): void {
```

  and replace `$amount = $line->amount;` (`:402`) with:

```php
        // RETAINED amount (spec §4.6 two-semantics rule) — this is what the
        // Treasury Payment row, the GL entry and the repository movement all
        // consume. The canonical TENDERED value stays on `$line->amount` and
        // is used ONLY by the maturity instrument match below.
        $amount = $nettedAmount;
```

  Then, immediately after the `is_numeric($amount)` guard block (`:409-415`), add the zero-leg suppression:

```php
        // A fully-netted cash leg (the customer's change consumed it) writes
        // NOTHING: no Payment, no GL entry, no movement. `repository_movements`
        // carries CHECK (amount > 0) and the payments idempotency index is
        // PARTIAL, so an ordinal hole is legal. v1/v2 behavior is untouched.
        if ($event->event_version >= 3 && bccomp($amount, '0', $currencyScale) === 0) {
            return;
        }
```

  `Payment::create` (`:634`) and `MovementIntent` (`:712`) already read `$amount`, so both follow automatically. **Do NOT touch `handleMaturityRefundLeg()`** — its `->where('amount', $line->amount)` at `:795` must keep the TENDERED value.

- [ ] Add the two-semantics docblock to `PosCoreReceiptProjection::writePayments()` (find it with `grep -n "private function writePayments" apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`):

```php
    /**
     * **Two-semantics rule (spec §4.6).** `pos_receipt_payments.amount` is the
     * TENDERED amount — exactly what the canonical payload carries and what
     * the customer handed over. Treasury's `payments.amount` is the RETAINED
     * amount (tendered minus change), computed by
     * TreasuryReceiptBridge::computeNettedAmounts(). The two columns are
     * DELIBERATELY different numbers; never reconcile them directly.
     */
```

- [ ] Run the test on Postgres and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php`
  Expected: 7 tests green.

- [ ] Run the existing bridge suite BY PATH on Postgres:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php`
  Expected: green — v1/v2 amounts unchanged.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php && ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T9: bridge change-netting pre-pass + zero-leg suppression

For event_version >= 3 the bridge now posts the RETAINED amount: change is
subtracted from the last cash leg cascading backwards in canonical index
order, and a fully-netted leg writes no Payment, no GL entry and no
movement (ordinal holes are legal under the partial idempotency index).
Maturity legs are non-cash and never netted, so the instrument match keeps
its tendered amount. Change beyond the cash legs raises
pos.change.exceeds_cash_legs.

Documents the two-semantics rule on both sides: Treasury payments.amount =
retained, pos_receipt_payments.amount = tendered.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 10: New GL entries — rounding + tolerance write-off, purpose precheck, audit alerts

**Files:**
- Modify `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (`createPOSPaymentEntry` lines 3313-3374 is the exemplar; `createPOSRefundReversalEntry` lines 3399-3457; `getAccountByPurpose` line 4173 — private; `hasAccountForPurpose` line 4187 — public, non-throwing; `postEntryNow(JournalEntry $entry, ?User $user, ?string $currencyCode = null): void` line 3121)
- Modify `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` (end of the `DB::transaction` body in `apply()`, after the payment loop from Task 9)
- Create `apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php`

**Interfaces:**
- Produces: `GeneralLedgerService::createPosCashRoundingEntry(Receipt $receipt, string $adjustment, string $sourceType): JournalEntry` — returns a Draft entry; the caller posts it.
- Produces: `GeneralLedgerService::createPosToleranceWriteoffEntry(Receipt $receipt, string $shortfall): JournalEntry` — returns a Draft entry.
- Consumes: `SystemAccountPurpose::ProductRevenue`, `::PaymentToleranceExpense` (6580), `::PaymentToleranceIncome` (7580).
- Consumes: `GeneralLedgerService::hasAccountForPurpose(string $companyId, SystemAccountPurpose $purpose): bool`.
- Produces: audit events `pos.gl.tolerance_purpose_missing` and `pos.tolerance.shortfall_exceeds_config`.

**Posting rules (spec §4.6, all gated on `event_version >= 3` AND `training_flag === false`):**
1. **Rounding** `R = cash_rounding_adjustment ≠ 0`:
   - `R > 0` ⇒ `Dr ProductRevenue R / Cr 7580 R`
   - `R < 0` ⇒ `Dr 6580 |R| / Cr ProductRevenue |R|`
   - `source_type = 'pos_cash_rounding'` on a SALE, `'pos_cash_rounding_refund'` on a REFUND/VOID (symmetric reversal — legs flipped).
   - `JournalCode::fromSourceType` falls through to `Misc`/OD for both literals: an EXPLICIT decision, no code change.
2. **Tolerance** `S = max(0, total − Σ TENDERED) > 0` ⇒ `Dr 6580 S / Cr ProductRevenue S`, `source_type = 'pos_tolerance_bridge'`.
   - Compute `S` from the TENDERED amounts (`$line->amount`), NOT the netted ones — a shortfall is by definition a gap in what was handed over.
   - Beyond-config shortfall without approval evidence ⇒ still POST, then alert `pos.tolerance.shortfall_exceeds_config`.
3. **Purpose precheck.** Call `hasAccountForPurpose` before building either entry; if the account is missing, SKIP that entry and raise `pos.gl.tolerance_purpose_missing`. The tender legs must still post — the precheck runs after the payment loop, so a missing 6580 never blocks revenue recognition.
4. **Idempotency.** `journal_entries` has NO `fiscal_event_id`; disjointness from the legacy 658 writer (`ReceiptPaymentService.php:399-406`) rests entirely on the DISTINCT `source_type` literals. Use `source_id = $receipt->id` (consistent with `createPOSPaymentEntry:3345`) and probe `(source_type, source_id)` before creating. The partial unique indexes from Task 7 are the DB-level backstop.
5. Everything runs INSIDE the existing `apply()` transaction, and `postEntryNow($entry, $receipt->cashier, $receipt->currency)` passes the currency EXPLICITLY (worker, no CompanyContext — rule 19/20).

**Worked check (spec §4.6, use it as a test):** exact 9.973, D 0.050 ⇒ rounded 9.950, adj −0.023; tendered 9.900 ⇒ S 0.050. Legs net to 9.900 ⇒ `Dr Cash 9.900 / Cr Rev 9.900`; tolerance `Dr 6580 0.050 / Cr Rev 0.050`; rounding `Dr 6580 0.023 / Cr Rev 0.023`. Cash 9.900; Revenue 9.973 = exact; 6580 0.073. Balanced.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php`, reusing the Task 9 scaffolding plus a chart of accounts that carries 707/6580/7580 (seed via `TunisiaChartOfAccountsSeeder` or explicit inserts). Required methods:

```php
    public function test_round_down_posts_dr_6580_cr_revenue(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        $this->assertSame($this->accountIdForCode('6580'), $lines[0]->account_id);
        $this->assertSame(0, bccomp((string) $lines[0]->debit, '0.023', 3));
        $this->assertSame($this->accountIdForCode('707'), $lines[1]->account_id);
        $this->assertSame(0, bccomp((string) $lines[1]->credit, '0.023', 3));
    }

    public function test_round_up_posts_dr_revenue_cr_7580(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '10.000', 'method_code' => 'CASH']],
            total: '10.000',
            subtotal: '9.973',
            cashRoundingAdjustment: '0.027',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        $this->assertSame($this->accountIdForCode('707'), $lines[0]->account_id);
        $this->assertSame(0, bccomp((string) $lines[0]->debit, '0.027', 3));
        $this->assertSame($this->accountIdForCode('7580'), $lines[1]->account_id);
        $this->assertSame(0, bccomp((string) $lines[1]->credit, '0.027', 3));
    }

    public function test_worked_example_posts_all_three_entries_and_balances(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_receipt', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);

        // Revenue ends at the EXACT sale value 9.973.
        $revenueCredit = $this->netCreditForCode('707');
        $this->assertSame(0, bccomp($revenueCredit, '9.973', 3));

        // 6580 carries the rounding 0.023 + the tolerance 0.050.
        $this->assertSame(0, bccomp($this->netDebitForCode('6580'), '0.073', 3));
    }

    public function test_v2_event_posts_no_rounding_or_tolerance_entry(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '10.000',
            subtotal: '10.000',
            eventVersion: 2,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
    }

    public function test_training_receipt_posts_no_rounding_or_tolerance_entry(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            training: true,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
    }

    public function test_missing_purpose_account_skips_the_entry_alerts_and_still_posts_the_legs(): void
    {
        DB::table('accounts')
            ->where('company_id', $this->companyId)
            ->where('code', '6580')
            ->delete();

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_receipt', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.gl.tolerance_purpose_missing',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
    }

    public function test_refund_posts_the_symmetric_reversal_under_a_distinct_source_type(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            invoiceTypeCode: 'REFUND',
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding_refund')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        // Sale posted Dr 6580 / Cr 707; the refund is the exact inverse.
        $this->assertSame($this->accountIdForCode('707'), $lines[0]->account_id);
        $this->assertSame($this->accountIdForCode('6580'), $lines[1]->account_id);
    }

    public function test_replay_posts_each_entry_exactly_once(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        $bridge = app(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'pos_cash_rounding')->where('source_id', $receipt->id)->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'pos_tolerance_bridge')->where('source_id', $receipt->id)->count());
    }

    public function test_shortfall_beyond_config_still_posts_and_alerts(): void
    {
        // TN ceiling is 0.100; a 5.000 shortfall is far beyond it.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '4.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.tolerance.shortfall_exceeds_config',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }
```

  Extend the shared event builder with `bool $training = false` and `string $invoiceTypeCode = 'SALE'` (a REFUND needs a non-null `original_receipt_reference`; mirror the existing refund fixtures in `TreasuryReceiptBridgeTest`).

- [ ] Run it on Postgres and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php`
  Expected: `createPosCashRoundingEntry` undefined / no journal rows.

- [ ] Add the two entry builders to `GeneralLedgerService`, immediately after `createPOSRefundReversalEntry()` (`:3457`):

```php
    /**
     * POS cash-rounding difference (spec §4.6 entry 1).
     *
     *   R > 0 (rounded UP, more was collected than the sale is worth):
     *       Dr ProductRevenue R / Cr PaymentToleranceIncome (7580) R
     *   R < 0 (rounded DOWN):
     *       Dr PaymentToleranceExpense (6580) |R| / Cr ProductRevenue |R|
     *
     * Net effect: the revenue account ends at the EXACT sale value while the
     * cash account carries what was actually collected.
     *
     * `$sourceType` is 'pos_cash_rounding' on a sale and
     * 'pos_cash_rounding_refund' on a refund/void — the DISTINCT literals are
     * the entire basis of disjointness from the legacy 658 writer, because
     * journal_entries has no fiscal_event_id. `JournalCode::fromSourceType`
     * falls through to Misc/OD for both: an explicit decision.
     *
     * Returned in Draft; the caller posts it via postEntryNow() inside its own
     * transaction, exactly as with createPOSPaymentEntry().
     */
    public function createPosCashRoundingEntry(Receipt $receipt, string $adjustment, string $sourceType): JournalEntry
    {
        if (! is_numeric($adjustment)) {
            throw new \InvalidArgumentException('Cash-rounding adjustment must be a numeric string; got '.$adjustment);
        }

        return DB::transaction(function () use ($receipt, $adjustment, $sourceType): JournalEntry {
            $companyId = (string) $receipt->company_id;
            $scale = CurrencyScale::for((string) $receipt->currency);

            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            $roundedUp = bccomp($adjustment, '0', $scale) > 0;
            $counterAccount = $this->getAccountByPurpose(
                $companyId,
                $roundedUp ? SystemAccountPurpose::PaymentToleranceIncome : SystemAccountPurpose::PaymentToleranceExpense,
            );

            /** @var numeric-string $adjustment */
            $magnitude = $roundedUp ? $adjustment : bcmul($adjustment, '-1', $scale);

            $entry = JournalEntry::create([
                'tenant_id' => (string) $receipt->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $receipt->posted_at,
                'description' => "POS cash rounding {$receipt->receipt_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => $sourceType,
                'journal_code' => JournalCode::fromSourceType($sourceType)->value,
                'source_id' => $receipt->id,
            ]);

            $debitAccountId = $roundedUp ? $revenueAccount->id : $counterAccount->id;
            $creditAccountId = $roundedUp ? $counterAccount->id : $revenueAccount->id;

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'partner_id' => null,
                'debit' => $magnitude,
                'credit' => '0',
                'description' => 'POS cash rounding difference',
                'line_order' => 0,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $magnitude,
                'description' => 'POS cash rounding difference',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
    }

    /**
     * POS tender-tolerance write-off (spec §4.6 entry 2).
     *
     *   Dr PaymentToleranceExpense (6580) S / Cr ProductRevenue S
     *
     * `S` is the positive shortfall between the receipt total and the TENDERED
     * sum. `source_type = 'pos_tolerance_bridge'` — distinct from the legacy
     * B2B writer's source type so the two ledgers never collide.
     *
     * Returned in Draft; the caller posts it.
     */
    public function createPosToleranceWriteoffEntry(Receipt $receipt, string $shortfall): JournalEntry
    {
        if (! is_numeric($shortfall)) {
            throw new \InvalidArgumentException('Tolerance shortfall must be a numeric string; got '.$shortfall);
        }

        return DB::transaction(function () use ($receipt, $shortfall): JournalEntry {
            $companyId = (string) $receipt->company_id;

            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            $expenseAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::PaymentToleranceExpense);

            $entry = JournalEntry::create([
                'tenant_id' => (string) $receipt->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $receipt->posted_at,
                'description' => "POS tender tolerance {$receipt->receipt_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_tolerance_bridge',
                'journal_code' => JournalCode::fromSourceType('pos_tolerance_bridge')->value,
                'source_id' => $receipt->id,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expenseAccount->id,
                'partner_id' => null,
                'debit' => $shortfall,
                'credit' => '0',
                'description' => 'POS tender tolerance write-off',
                'line_order' => 0,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $shortfall,
                'description' => 'POS tender tolerance write-off',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
    }
```

  Add `use App\Shared\Domain\CurrencyScale;` to the file's imports if it is not already there (`grep -n "use App\\\\Shared\\\\Domain\\\\CurrencyScale;" apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`).

- [ ] In `TreasuryReceiptBridge::apply()`, at the very END of the `DB::transaction` closure (after the payment `foreach`), add:

```php
            // Spec §4.6 new entries — v3-gated and training-guarded, inside the
            // SAME transaction as the tender legs. Both run AFTER the loop so a
            // missing purpose account can never block revenue recognition.
            if ($event->event_version >= 3 && $view->payload->trainingFlag !== true) {
                $this->postCashRoundingEntry($event, $receipt, $view, $isRefund, $currencyScale);
                $this->postToleranceWriteoffEntry($event, $receipt, $view, $currencyScale);
            }
```

- [ ] Add the three helpers to the bridge:

```php
    /**
     * Post the cash-rounding difference for a v3 receipt (spec §4.6 entry 1).
     * No-ops on a canonical zero adjustment.
     */
    private function postCashRoundingEntry(
        FiscalEvent $event,
        Receipt $receipt,
        SaleReceiptCanonicalView $view,
        bool $isRefund,
        int $currencyScale,
    ): void {
        $adjustment = $view->cashRoundingAdjustmentOrZero();
        if (! is_numeric($adjustment) || bccomp($adjustment, '0', $currencyScale) === 0) {
            return;
        }

        $sourceType = $isRefund ? 'pos_cash_rounding_refund' : 'pos_cash_rounding';

        if ($this->journalEntryExists($sourceType, (string) $receipt->id)) {
            return;
        }

        $roundedUp = bccomp($adjustment, '0', $currencyScale) > 0;
        $requiredPurpose = $roundedUp
            ? SystemAccountPurpose::PaymentToleranceIncome
            : SystemAccountPurpose::PaymentToleranceExpense;

        if (! $this->generalLedgerService->hasAccountForPurpose($event->company_id, $requiredPurpose)) {
            $this->recordTolerancePurposeMissingAlert($event, $receipt, $requiredPurpose->value, $sourceType);

            return;
        }

        $entry = $this->generalLedgerService->createPosCashRoundingEntry($receipt, $adjustment, $sourceType);
        // Explicit currency: this projector runs on a Horizon worker where no
        // CompanyContext is bound and the no-arg scale resolution fails loud.
        $this->generalLedgerService->postEntryNow($entry, $receipt->cashier, $receipt->currency);
    }

    /**
     * Post the tender-tolerance write-off for a v3 receipt (spec §4.6 entry 2).
     *
     * The shortfall is computed from the TENDERED leg amounts — the netted
     * amounts would define the gap away.
     */
    private function postToleranceWriteoffEntry(
        FiscalEvent $event,
        Receipt $receipt,
        SaleReceiptCanonicalView $view,
        int $currencyScale,
    ): void {
        $tendered = bcadd('0', '0', $currencyScale);
        foreach ($view->payments as $line) {
            if (! is_numeric($line->amount)) {
                return;
            }
            /** @var numeric-string $legAmount */
            $legAmount = $line->amount;
            $tendered = bcadd($tendered, $legAmount, $currencyScale);
        }

        /** @var numeric-string $total */
        $total = $view->payload->total;
        $shortfall = bcsub($total, $tendered, $currencyScale);
        if (bccomp($shortfall, '0', $currencyScale) <= 0) {
            return;
        }

        if ($this->journalEntryExists('pos_tolerance_bridge', (string) $receipt->id)) {
            return;
        }

        if (! $this->generalLedgerService->hasAccountForPurpose($event->company_id, SystemAccountPurpose::PaymentToleranceExpense)) {
            $this->recordTolerancePurposeMissingAlert(
                $event,
                $receipt,
                SystemAccountPurpose::PaymentToleranceExpense->value,
                'pos_tolerance_bridge',
            );

            return;
        }

        $entry = $this->generalLedgerService->createPosToleranceWriteoffEntry($receipt, $shortfall);
        $this->generalLedgerService->postEntryNow($entry, $receipt->cashier, $receipt->currency);

        $this->alertIfShortfallExceedsConfig($event, $receipt, $shortfall, $total, $currencyScale);
    }

    private function journalEntryExists(string $sourceType, string $sourceId): bool
    {
        return DB::table('journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }
```

  Add the import `use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;` to the bridge.

- [ ] Add the two remaining alert helpers to the bridge, following `recordMaturityRefundAlert()` (`:829-869`) exactly:

```php
    private function recordTolerancePurposeMissingAlert(
        FiscalEvent $event,
        Receipt $receipt,
        string $purpose,
        string $sourceType,
    ): void {
        $aggregateId = $event->id;
        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.gl.tolerance_purpose_missing')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $aggregateId)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $receipt->cashier_id,
                eventType: 'pos.gl.tolerance_purpose_missing',
                aggregateType: 'fiscal_event',
                aggregateId: $aggregateId,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'receipt_id' => (string) $receipt->id,
                    'missing_purpose' => $purpose,
                    'skipped_source_type' => $sourceType,
                ],
            );
        }

        Log::warning('POS GL skipped a rounding/tolerance entry: the system-purpose account is missing.', [
            'fiscal_event_id' => $event->id,
            'missing_purpose' => $purpose,
            'skipped_source_type' => $sourceType,
        ]);
    }

    /**
     * The write-off is POSTED regardless — the money moved. This only raises a
     * durable signal when the shortfall exceeded the configured ceiling and no
     * approval evidence rode the payload.
     */
    private function alertIfShortfallExceedsConfig(
        FiscalEvent $event,
        Receipt $receipt,
        string $shortfall,
        string $total,
        int $currencyScale,
    ): void {
        $countryCode = DB::table('companies')->where('id', $event->company_id)->value('country_code');
        $row = is_string($countryCode)
            ? DB::table('country_payment_settings')->where('country_code', $countryCode)->first()
            : null;

        if ($row === null) {
            return;
        }

        $percentage = (string) $row->payment_tolerance_percentage;
        $maxAmount = (string) $row->max_payment_tolerance_amount;
        if (! is_numeric($percentage) || ! is_numeric($maxAmount)) {
            return;
        }

        /** @var numeric-string $percentage */
        /** @var numeric-string $maxAmount */
        /** @var numeric-string $total */
        $percentageCeiling = bcmul($total, $percentage, $currencyScale + 1);
        $effectiveMax = bccomp($percentageCeiling, $maxAmount, $currencyScale + 1) <= 0
            ? $percentageCeiling
            : $maxAmount;

        if (bccomp($shortfall, $effectiveMax, $currencyScale + 1) <= 0) {
            return;
        }

        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.tolerance.shortfall_exceeds_config')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $receipt->cashier_id,
                eventType: 'pos.tolerance.shortfall_exceeds_config',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'shortfall' => $shortfall,
                    'effective_max' => $effectiveMax,
                    'total' => $total,
                ],
            );
        }

        Log::warning('POS tolerance write-off exceeded the configured ceiling; posted and flagged.', [
            'fiscal_event_id' => $event->id,
            'shortfall' => $shortfall,
            'effective_max' => $effectiveMax,
        ]);
    }
```

- [ ] Run the test on Postgres and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php`
  Expected: 9 tests green.

- [ ] Re-run the netting suite and the legacy bridge suite on Postgres:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php`

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php && ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php apps/api/tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T10: rounding + tolerance GL entries with purpose precheck

Adds createPosCashRoundingEntry / createPosToleranceWriteoffEntry and posts
them from the bridge inside the existing transaction, v3-gated and
training-guarded, with an explicit currency for the worker context.
Idempotency rests on the distinct source_type literals plus a
(source_type, source_id) probe backed by the partial unique indexes.

A missing 6580/7580 SKIPS only that entry and raises
pos.gl.tolerance_purpose_missing — the tender legs still post. A shortfall
beyond the configured ceiling posts and raises
pos.tolerance.shortfall_exceeds_config.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 11: Server-derived Z `cash_rounding_summary` + additive hash normalization

**Files:**
- Modify `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php` (`rowFromPayload()` lines 96-114 — `'report_data' => $this->legacyReportData($payload),` at line 105; `legacyReportData()` lines 120-153, fixed key list ending at `'canonical_z_report' => $payload,`)
- Modify `apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php` (`normalizeForHash()` lines 88-148; the `tolerance_summary` per-key `isset` block at lines 131-136 is the exact precedent to mirror)
- Create `apps/api/tests/Feature/POS/ZReportCashRoundingSummaryTest.php`

**Interfaces:**
- Changes: `ZReportProjection::legacyReportData(FiscalEvent $event, array $payload): array<string, mixed>` — gains the event so the summary can be derived from projected receipts.
- Produces: `report_data['cash_rounding_summary'] = ['total_adjustment' => numeric-string at scale 3, 'receipt_count' => int]`.
- Consumes: `pos_receipts.cash_rounding_adjustment` (Task 7) joined to `fiscal_events.event_version` (v3-gated).
- Consumes: `ZReportHashService::normalizeForHash(array $reportData): array` (`:88`).

**Why derived, not device-supplied (spec §4.3/§4.5):** the projection REBUILDS `report_data` from the canonical payload with a FIXED key list (`ZReportProjection.php:120-153`), and the legacy sync endpoint is 409-retired for cutover terminals — so device-authored `report_data` never reaches the server on exactly the terminals that round. No ingress regex is needed. The per-receipt SALE_RECEIPT signatures remain the fiscal authority for rounding; the Z line is derived observability (Goal 4).

**Do NOT bump `schema_version`.** It stays at the value `legacyReportData` already writes. Bumping it would pull `refunds_amount` (and the voucher keys) into a different normalization branch and break byte-parity for the legacy hash path. The additive block below is `isset`-guarded, which is what makes an additive key legacy-safe.

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/POS/ZReportCashRoundingSummaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Services\ZReportHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-derived Z cash-rounding summary + additive hash normalization.
 *
 * Build the Z_REPORT fiscal event and the projected pos_receipts rows with
 * the scaffolding in tests/Feature/POS/GenerateZReportToleranceSummaryTest.php
 * (canonical Z payload) and tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php
 * (SALE_RECEIPT projection).
 */
final class ZReportCashRoundingSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_sums_only_v3_receipts_in_the_z_window(): void
    {
        // Two v3 rounded receipts (-0.023 and +0.027) plus one v2 receipt in
        // the same terminal/time window.
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '0.027', 'event_version' => 3],
            ['adjustment' => null, 'event_version' => 2],
        ]);

        $reportData = $this->projectedReportData($event);

        $this->assertArrayHasKey('cash_rounding_summary', $reportData);
        $this->assertSame(0, bccomp((string) $reportData['cash_rounding_summary']['total_adjustment'], '0.004', 3));
        $this->assertSame(2, $reportData['cash_rounding_summary']['receipt_count']);
    }

    public function test_summary_is_canonical_zero_when_no_receipt_rounded(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => null, 'event_version' => 2],
        ]);

        $reportData = $this->projectedReportData($event);

        $this->assertSame(0, bccomp((string) $reportData['cash_rounding_summary']['total_adjustment'], '0.000', 3));
        $this->assertSame(0, $reportData['cash_rounding_summary']['receipt_count']);
    }

    public function test_voided_receipts_are_excluded(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
            ['adjustment' => '-0.023', 'event_version' => 3, 'is_voided' => true],
        ]);

        $reportData = $this->projectedReportData($event);

        $this->assertSame(1, $reportData['cash_rounding_summary']['receipt_count']);
    }

    public function test_schema_version_is_unchanged_by_this_task(): void
    {
        $event = $this->projectZReportOverReceipts([
            ['adjustment' => '-0.023', 'event_version' => 3],
        ]);

        $reportData = $this->projectedReportData($event);

        // Pinned so a future edit cannot silently re-normalize refunds_amount.
        $this->assertSame(3, $reportData['schema_version']);
    }

    public function test_hash_normalization_is_additive_and_legacy_safe(): void
    {
        $service = app(ZReportHashService::class);

        $legacy = ['schema_version' => 2, 'expected_cash' => '10.5', 'actual_cash' => '10.5'];
        $this->assertSame($service->normalizeForHash($legacy), $service->normalizeForHash($legacy));
        $this->assertArrayNotHasKey('cash_rounding_summary', $service->normalizeForHash($legacy));

        $withSummary = [
            'schema_version' => 3,
            'cash_rounding_summary' => ['total_adjustment' => '-0.0230', 'receipt_count' => 1],
        ];
        $normalized = $service->normalizeForHash($withSummary);
        $this->assertSame('-0.023', $normalized['cash_rounding_summary']['total_adjustment']);
        $this->assertSame(1, $normalized['cash_rounding_summary']['receipt_count']);
    }

    public function test_legacy_report_data_without_the_key_hashes_byte_identically(): void
    {
        $service = app(ZReportHashService::class);

        $before = [
            'schema_version' => 3,
            'expected_cash' => '100.000',
            'actual_cash' => '100.000',
            'refunds_amount' => '0.000',
        ];

        $this->assertSame(
            hash('sha256', json_encode($service->normalizeForHash($before), JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($service->normalizeForHash($before), JSON_THROW_ON_ERROR)),
        );
        $this->assertArrayNotHasKey('cash_rounding_summary', $service->normalizeForHash($before));
    }
}
```

  Implement `projectZReportOverReceipts(array $receipts)` and `projectedReportData(FiscalEvent $event)` as private helpers in the test: seed the receipts with the right `terminal_id` / `posted_at` inside the Z period, project the Z_REPORT event via `app(ZReportProjection::class)->apply($event)`, then read `ZReport::query()->where('fiscal_event_id', $event->id)->firstOrFail()->report_data`.

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/ZReportCashRoundingSummaryTest.php`
  Expected: `cash_rounding_summary` key absent.

- [ ] Thread the event into `legacyReportData` in `ZReportProjection`. Change the call in `rowFromPayload()` (`:105`):

```php
            'report_data' => $this->legacyReportData($event, $payload),
```

  and the signature + the new key (append it immediately BEFORE `'canonical_z_report' => $payload,`):

```php
    private function legacyReportData(FiscalEvent $event, array $payload): array
    {
```

```php
            'tolerance_summary' => $payload['tolerance_summary'] ?? null,
            'cash_rounding_summary' => $this->cashRoundingSummary($event, $payload),
            'canonical_z_report' => $payload,
```

- [ ] Add the derivation method to `ZReportProjection`:

```php
    /**
     * Derive the Z-level cash-rounding summary from PROJECTED receipts
     * (spec §4.5). This is DERIVED OBSERVABILITY, not fiscal authority: the
     * per-receipt SALE_RECEIPT signatures are what a verifier checks.
     *
     * v3-gated via a join onto fiscal_events.event_version, so a mixed window
     * during cutover reports only what was actually signed with a rounding
     * adjustment. Voided and training receipts are excluded, matching every
     * other receipt aggregator.
     *
     * Runs on a Horizon worker with NO CompanyContext: scale 3 is the
     * projection's fixed storage scale (inherited debt, tracked with the
     * file's other money columns), never a resolver call.
     *
     * @param  array<string, mixed>  $payload
     * @return array{total_adjustment: string, receipt_count: int}
     */
    private function cashRoundingSummary(FiscalEvent $event, array $payload): array
    {
        $periodStart = $payload['period_start'] ?? null;
        $periodEnd = $payload['period_end'] ?? null;

        if (! is_string($periodStart) || ! is_string($periodEnd)) {
            return ['total_adjustment' => bcadd('0', '0', 3), 'receipt_count' => 0];
        }

        /** @var object{total: mixed, cnt: mixed}|null $row */
        $row = DB::table('pos_receipts')
            ->join('fiscal_events', 'pos_receipts.fiscal_event_id', '=', 'fiscal_events.id')
            ->where('pos_receipts.terminal_id', (string) $payload['terminal_id'])
            ->whereBetween('pos_receipts.posted_at', [$periodStart, $periodEnd])
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.is_training', false)
            ->where('fiscal_events.event_version', '>=', 3)
            ->whereNotNull('pos_receipts.cash_rounding_adjustment')
            ->where('pos_receipts.cash_rounding_adjustment', '!=', 0)
            ->selectRaw('COALESCE(SUM(pos_receipts.cash_rounding_adjustment), 0) AS total, COUNT(*) AS cnt')
            ->first();

        $total = $row === null ? '0' : (string) $row->total;
        $count = $row === null ? 0 : (int) $row->cnt;

        if (! is_numeric($total)) {
            $total = '0';
        }

        /** @var numeric-string $total */
        return [
            'total_adjustment' => bcadd($total, '0', 3),
            'receipt_count' => $count,
        ];
    }
```

  `use Illuminate\Support\Facades\DB;` is already imported (`ZReportProjection.php:12`).

- [ ] Add the additive normalization to `ZReportHashService::normalizeForHash()`, immediately AFTER the existing `tolerance_summary` block (`:131-136`) and BEFORE the `payment_methods` block:

```php
        // Additive, per-key `isset`-guarded — legacy report_data that predates
        // this key hashes byte-identically. Deliberately NOT tied to a
        // schema_version bump: bumping the version would re-normalize
        // refunds_amount and break parity with the device mirror.
        if (isset($reportData['cash_rounding_summary']) && is_array($reportData['cash_rounding_summary'])
            && isset($reportData['cash_rounding_summary']['total_adjustment'])) {
            $reportData['cash_rounding_summary']['total_adjustment'] = CurrencyScale::bcformat(
                (string) $reportData['cash_rounding_summary']['total_adjustment'], 3
            );
        }
```

  Note for Plan B (device, out of scope here): the identical block must also land in `apps/pos/src/lib/fiscal/zReportHashService.ts:86-95`, or legacy-path hashes diverge between the two implementations.

- [ ] Run the test and confirm it PASSES:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/ZReportCashRoundingSummaryTest.php`
  Expected: 6 tests green.

- [ ] Run the existing Z suites BY PATH:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/GenerateZReportToleranceSummaryTest.php tests/Feature/POS/GenerateZReportEndToEndTest.php tests/Feature/POS/GenerateZReportWithCountsTest.php tests/Feature/POS/GenerateZReportToleranceWiringTest.php`
  Expected: all green — no hash assertion may be edited.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/POS/Application/Projections/ZReportProjection.php app/Modules/POS/Domain/Services/ZReportHashService.php && ./vendor/bin/phpstan analyse app/Modules/POS/Application/Projections/ZReportProjection.php app/Modules/POS/Domain/Services/ZReportHashService.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php apps/api/tests/Feature/POS/ZReportCashRoundingSummaryTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T11: server-derived Z cash_rounding_summary + hash key

ZReportProjection now derives cash_rounding_summary from projected
pos_receipts joined to fiscal_events.event_version >= 3, excluding voided
and training rows. ZReportHashService gains a per-key isset-guarded
normalization for total_adjustment — additive and legacy-safe, with NO
schema_version bump (a bump would re-normalize refunds_amount and break
byte parity with the device mirror).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 12: Server print / NF525 export, tolerance drill-down endpoint, GrandtotalService unreachability pins

**Files:**
- Modify `apps/api/resources/views/pos/receipt.blade.php` (totals section lines 419-432)
- Modify `apps/api/app/Shared/Contracts/Compliance/DTOs/Nf525ReceiptData.php` (constructor — append after `public array $voucherLedgerEntries = [],`)
- Modify `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` (`mapSaleReceiptFromCanonical()` lines 603-652 — the `new Nf525ReceiptData(...)` call ends at line 651)
- Modify `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php` (ticket totals lines 114-117)
- Create `apps/api/app/Modules/POS/Presentation/Controllers/ShiftToleranceController.php`
- Modify `apps/api/app/Modules/POS/routes.php` (shift receipts route at line 181 is the placement anchor)
- Create `apps/api/tests/Feature/POS/CashRoundingPrintAndReportsTest.php`
- Create `apps/api/tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php`

**Interfaces:**
- Produces: `Nf525ReceiptData::$cashRoundingAdjustment: ?string` — appended LAST with a `= null` default so the two legacy mapping paths (`mapSaleReceiptLegacy`, the void/return mappers) need no change.
- Produces: `GET /api/v1/pos/shifts/{id}/tolerance-receipts` → `{"data": [TolerancePaymentReceiptDTO, ...]}`.
- Consumes: `PaymentToleranceQueryService::receiptsWithToleranceForShift(string $shiftId): array<int, TolerancePaymentReceiptDTO>` (already exists; its scale-3 hardcode at `:30` is inherited debt and stays).
- Consumes: `ReportGenerationService::assertServerReportAuthoringAllowed(Terminal $terminal, string $operation): void` (`:69-74`) — throws `ServerFiscalAuthoringRetiredException` for `fiscal_schema_version >= 3`.
- Consumes: `ReceiptPdfService` passes the Receipt model straight into the blade (`'receipt' => $receipt`, `:157`), so the new column is reachable in the template with no service change.

**GrandtotalService stance (spec §4.5):** `GrandtotalService::calculatePeriodTotals` computes `netSales = gross − tax` (`:139-147`), which would be WRONG for a rounded receipt. It is safe only because it is UNREACHABLE: the device rounds only on `fiscal_schema_version === 3` terminals, and server report authoring throws for exactly those. This task pins that with tests; it does NOT change `GrandtotalService`. (The paired device-side pin — "the device signs `adj ≠ 0` ONLY when its cached terminal `fiscal_schema_version === 3`" — is a Plan B / `apps/pos` test and is out of scope here.)

**Steps:**

- [ ] Write the failing test `apps/api/tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Exceptions\ServerFiscalAuthoringRetiredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the foreclosure that makes GrandtotalService's `netSales = gross - tax`
 * formula safe under rounding (spec §4.5): server Z/X authoring is retired for
 * exactly the terminals that are allowed to round.
 */
final class ServerReportAuthoringUnreachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_z_report_is_retired_at_fiscal_schema_version_three(): void
    {
        [$terminal, $user] = $this->seedTerminalAtSchemaVersion(3);

        $this->expectException(ServerFiscalAuthoringRetiredException::class);
        app(ReportGenerationService::class)->generateZReport($terminal, $user);
    }

    public function test_server_x_report_is_retired_at_fiscal_schema_version_three(): void
    {
        [$terminal, $user] = $this->seedTerminalAtSchemaVersion(3);

        $this->expectException(ServerFiscalAuthoringRetiredException::class);
        app(ReportGenerationService::class)->generateXReport($terminal, $user);
    }

    public function test_grandtotal_service_is_reachable_only_from_the_retired_report_path(): void
    {
        // Static pin: the only production caller of GrandtotalService is
        // ReportGenerationService, which is gated above. If a new caller
        // appears, the netSales = gross − tax formula must be revisited for
        // rounded receipts BEFORE that caller ships.
        $root = base_path('app');
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'GrandtotalService')
                && ! str_contains($file->getPathname(), 'GrandtotalService.php')) {
                $matches[] = str_replace($root.'/', '', $file->getPathname());
            }
        }

        sort($matches);
        $this->assertSame(
            ['Modules/POS/Application/Services/ReportGenerationService.php'],
            $matches,
            'A new GrandtotalService caller appeared; its netSales formula is rounding-unsafe.',
        );
    }

    /**
     * @return array{0: \App\Modules\POS\Domain\Terminal, 1: \App\Modules\Identity\Domain\User}
     */
    private function seedTerminalAtSchemaVersion(int $version): array
    {
        // Reuse the terminal/user/shift scaffolding from
        // tests/Feature/POS/FiscalSchemaCutoverServiceTest.php and set
        // `fiscal_schema_version` on the terminal row.
        throw new \RuntimeException('Implement using the FiscalSchemaCutoverServiceTest scaffolding.');
    }
}
```

  Replace the `seedTerminalAtSchemaVersion` body with real scaffolding copied from `apps/api/tests/Feature/POS/FiscalSchemaCutoverServiceTest.php`. Confirm the exception FQN with `grep -rn "class ServerFiscalAuthoringRetiredException" apps/api/app`.

- [ ] Run it and confirm it FAILS (the helper throws), then implement the helper and re-run: the first two tests should PASS immediately (the guard already exists — these are PINS, not new behavior), and the third establishes the caller baseline.
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php`

- [ ] Write the failing test `apps/api/tests/Feature/POS/CashRoundingPrintAndReportsTest.php` covering the print/export/drill-down surfaces:

```php
    public function test_receipt_blade_renders_a_rounding_line_when_the_adjustment_is_non_zero(): void
    {
        $receipt = $this->seedProjectedReceipt(['cash_rounding_adjustment' => '-0.023']);

        $html = $this->renderReceiptHtml($receipt);

        // Test RENDERED OUTPUT, never CSS class names.
        $this->assertStringContainsString(__('pos.cash_rounding'), $html);
        $this->assertStringContainsString('-0.023', $html);
    }

    public function test_receipt_blade_omits_the_rounding_line_on_a_legacy_receipt(): void
    {
        $receipt = $this->seedProjectedReceipt(['cash_rounding_adjustment' => null]);

        $html = $this->renderReceiptHtml($receipt);

        $this->assertStringNotContainsString(__('pos.cash_rounding'), $html);
    }

    public function test_nf525_export_carries_the_adjustment_and_the_exact_total(): void
    {
        $event = $this->storeV3SaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
        );
        $receipt = $this->projectReceipt($event);

        $data = app(Nf525DataProvider::class)->forReceipt($receipt);

        $this->assertSame('-0.023', $data->cashRoundingAdjustment);
        $this->assertSame('9.950', $data->total);

        $xml = app(Nf525XmlBuilder::class)->addTickets([$data])->toXml();
        $this->assertStringContainsString('<ArrondiEspeces>-0.023</ArrondiEspeces>', $xml);
        $this->assertStringContainsString('<TotalExact>9.973</TotalExact>', $xml);
    }

    public function test_nf525_export_emits_no_rounding_elements_for_a_legacy_receipt(): void
    {
        $event = $this->storeV2SaleReceiptFiscalEvent(total: '9.973', subtotal: '9.973');
        $receipt = $this->projectReceipt($event);

        $data = app(Nf525DataProvider::class)->forReceipt($receipt);
        $this->assertNull($data->cashRoundingAdjustment);

        $xml = app(Nf525XmlBuilder::class)->addTickets([$data])->toXml();
        $this->assertStringNotContainsString('ArrondiEspeces', $xml);
        $this->assertStringNotContainsString('TotalExact', $xml);
    }

    public function test_tolerance_drill_down_endpoint_returns_receipt_level_rows(): void
    {
        $shift = $this->seedShiftWithToleranceReceipts();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/shifts/'.$shift->id.'/tolerance-receipts');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('0.050', $rows[0]['writeoffAmount']);
    }

    public function test_tolerance_drill_down_rejects_a_shift_from_another_company(): void
    {
        $foreignShift = $this->seedShiftForAnotherCompany();

        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/shifts/'.$foreignShift->id.'/tolerance-receipts')
            ->assertStatus(403);
    }
```

  Confirm the real `Nf525DataProvider` public entry point before writing (`grep -n "public function" apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`) and use it; `forReceipt` above is illustrative.

- [ ] Run it and confirm it FAILS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/CashRoundingPrintAndReportsTest.php`

- [ ] Add the rounding line to `apps/api/resources/views/pos/receipt.blade.php`, between the tax line (ends line 428) and the `grand-total` block (line 429):

```blade
            @if ($receipt->cash_rounding_adjustment !== null && bccomp((string) $receipt->cash_rounding_adjustment, '0', 3) !== 0)
                <div class="total-line">
                    <span>{{ __('pos.cash_rounding') }}:</span>
                    <span>{{ $formatMoney($receipt->cash_rounding_adjustment) }}</span>
                </div>
            @endif
```

  Add the `pos.cash_rounding` key to the `en`, `fr` and `ar` `pos` translation files (find them with `ls apps/api/lang/*/pos.php`). If `$formatMoney` cannot render a negative value, keep the sign explicit in the template rather than changing the shared helper.

- [ ] Append the field to `Nf525ReceiptData`:

```php
        /**
         * Signed cash-rounding adjustment from the v3 SALE_RECEIPT payload.
         * NULL on v1/v2 receipts and on every legacy mapping path.
         */
        public ?string $cashRoundingAdjustment = null,
```

- [ ] Populate it in `Nf525DataProvider::mapSaleReceiptFromCanonical()` — add as the LAST named argument of the `new Nf525ReceiptData(...)` call (after `voucherLedgerEntries: $voucherLedgerEntries,`):

```php
            cashRoundingAdjustment: $payload->cashRoundingAdjustment,
```

  Leave `mapSaleReceiptLegacy()` and the void/return mappers untouched — the default `null` is correct there.

- [ ] Emit the elements in `Nf525XmlBuilder`, immediately after the existing `$this->addElement($ticket, 'Total', $receipt->total);` (`:117`):

```php
            // v3 cash rounding: `Total` stays the SIGNED, collected amount
            // (the fiscal authority). The exact sale value and the adjustment
            // are emitted alongside it so the VAT declaration reconciles.
            if ($receipt->cashRoundingAdjustment !== null) {
                $this->addElement($ticket, 'ArrondiEspeces', $receipt->cashRoundingAdjustment);
                $this->addElement($ticket, 'TotalExact', bcsub(
                    $receipt->total,
                    $receipt->cashRoundingAdjustment,
                    strlen(substr(strrchr($receipt->total, '.') ?: '.', 1)),
                ));
            }
```

  If the scale derivation above reads awkwardly in review, replace it with an explicit scale threaded from `Nf525ReceiptData` — but do NOT use a float and do NOT hardcode 2 or 3.

- [ ] Create `apps/api/app/Modules/POS/Presentation/Controllers/ShiftToleranceController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Shift;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/pos/shifts/{id}/tolerance-receipts
 *
 * Receipt-level drill-down behind the Z-report tolerance_summary block.
 * Mirrors ShiftController::receipts() for scoping: authorize
 * `pos.operate_terminal`, then reject a shift whose terminal belongs to
 * another company.
 */
final class ShiftToleranceController extends Controller
{
    public function __construct(
        private readonly PaymentToleranceQueryService $queryService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $shift = Shift::with('terminal')->findOrFail($id);

        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        return response()->json([
            'data' => array_map(
                static fn (object $dto): array => (array) $dto,
                $this->queryService->receiptsWithToleranceForShift($id),
            ),
        ]);
    }
}
```

  If `TolerancePaymentReceiptDTO` is a Spatie `Data` object, use `->toArray()` instead of the `(array)` cast — check with `head -30 apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentReceiptDTO.php`.

- [ ] Register the route in `apps/api/app/Modules/POS/routes.php` immediately after the shift-receipts route (`:181`):

```php
    // Tolerance write-off drill-down behind the Z-report tolerance_summary
    Route::get('/pos/shifts/{id}/tolerance-receipts', [ShiftToleranceController::class, 'index']);
```

  and add `use App\Modules\POS\Presentation\Controllers\ShiftToleranceController;` to the imports.

- [ ] Run both tests and confirm they PASS:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/CashRoundingPrintAndReportsTest.php tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php`

- [ ] Run the NF525 + void suites BY PATH:
  `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit --filter Nf525 tests/Feature/Compliance tests/Feature/POS`
  Expected: green. The existing void-audit test stays as-is — `ReceiptVoidService` performs NO GL work at all today; that is a pre-existing, ticketed gap this feature does not touch, and the test must not be read as asserting a reversal.

- [ ] Pint + PHPStan:
  `cd apps/api && ./vendor/bin/pint app/Modules/POS app/Modules/Compliance app/Shared/Contracts/Compliance && ./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/ShiftToleranceController.php app/Modules/POS/Application/Services/Nf525DataProvider.php app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php --no-progress`

- [ ] Commit:

```bash
git add apps/api/resources/views/pos/receipt.blade.php apps/api/lang apps/api/app/Shared/Contracts/Compliance/DTOs/Nf525ReceiptData.php apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php apps/api/app/Modules/POS/Presentation/Controllers/ShiftToleranceController.php apps/api/app/Modules/POS/routes.php apps/api/tests/Feature/POS/CashRoundingPrintAndReportsTest.php apps/api/tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T12: server print, NF525 export, drill-down, unreachability pins

Adds the rounding line to the server receipt template, threads the signed
adjustment into Nf525ReceiptData / the canonical mapper / the JET XML
(ArrondiEspeces + TotalExact, emitted only when present), and exposes the
existing receiptsWithToleranceForShift drill-down as a company-scoped
endpoint.

Pins that server Z/X authoring is retired at fiscal_schema_version >= 3 and
that ReportGenerationService remains GrandtotalService's only caller — the
two facts that keep its netSales = gross - tax formula safe under rounding.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

### Task 13: Docs — precision-contract signed-field entry + Phase-1 deploy checklist

**Files:**
- Modify `docs/architecture/precision-contract.md` (insert a new section between `## Emission & display` and `## Regression guards (CI)`)
- Create `docs/handoff/cash-rounding-phase1-deploy-checklist.md`

**Interfaces:** documentation only — no code, no tests. This task exists because the spec explicitly CREATES the signed-field exception entry in the precision contract (§1 last bullet) and because §7 lists Phase-1 deploy steps that the owner must run by hand after the auto-deploy.

**Steps:**

- [ ] Add the signed-field section to `docs/architecture/precision-contract.md`:

```markdown
## Signed fiscal fields — the string-fidelity exception (SALE_RECEIPT v3 cash rounding)

Everything above governs values at rest and in transit. Two fields are stricter
still, because they live inside **cryptographically signed bytes** and any
re-serialization is a permanent, unrepairable defect.

`SALE_RECEIPT` payload v3 carries:

| Field | Type | Rule |
|---|---|---|
| `cash_rounding_adjustment` | SIGNED decimal string at `currency_scale` | `-0` is REJECTED — canonical zero is the unsigned zero at scale |
| `cash_rounding_denomination` | NON-NEGATIVE decimal string at `currency_scale` | Canonical zero when rounding did not apply |

**The signed denomination must survive every hop UNMUTATED.** `'0.050'` becoming
`0.05` anywhere on the path is 100% quarantine for every receipt authored after
that point. Concretely:

- `country_payment_settings.cash_rounding_denomination` is `decimal(15,4)` with
  a `'string'` Eloquent cast — NEVER a float cast, which re-serializes `0.0500`
  as `0.05`.
- `PosPaymentPolicyResolver` re-scales it to the company currency scale with
  `CurrencyScale::bcformatStrict()` and validates round-trip equality; a
  non-representable value is reported as `cashRoundingEnabled: false` rather
  than emitted broken.
- `PosPaymentPolicyDTO::$cashRoundingDenomination` is typed `string`; the
  generated TypeScript type is `string`; the device SQLite cache column is
  `TEXT` (NUMERIC/REAL affinity strips trailing zeros).

**Comparisons against policy use `bccomp`, never string equality.** PostgreSQL
renders `decimal(15,4)` as `0.0500` while the signed value is `0.050` — a string
compare would false-alarm on every rounded receipt.

**`total` semantics under rounding.** On a v3 receipt the signed `total` is the
ROUNDED amount actually collected. The NF525 aggregate identity becomes
`subtotal + vat_total == (total − cash_rounding_adjustment) + discount`. Absent
fields mean zero, so v1/v2 reduce to the previous identity exactly. Revenue and
loyalty consume the EXACT value `total − adjustment`; the drawer, the payable
and the Z gross consume the rounded `total`; VAT is untouched (rounding is
VAT-neutral).

**`pos_receipts_totals` (pgsql-only CHECK)** is
`total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)`.
The `COALESCE` is load-bearing: without it the expression is NULL on every
legacy row and PostgreSQL treats a NULL CHECK as satisfied, silently disabling
the identity for all pre-v3 data.
```

- [ ] Create `docs/handoff/cash-rounding-phase1-deploy-checklist.md`:

````markdown
# Cash Rounding — Phase 1 (server) deploy checklist

**Spec:** `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` §7
**Scope:** `apps/api` only. The device (`apps/pos`) ships in Phase 2.

Pushing to `origin/dev` auto-deploys staging AND runs `tenants:migrate`. Every
migration in this batch is self-guarding, so the deploy itself is safe. The
steps below are what the auto-deploy does NOT do.

## 0. What lands automatically

- `2026_07_28_100000_add_is_cash_tender_to_payment_methods` — adds the column,
  backfills `is_cash_tender = true` + normalizes `code` to `'CASH'`.
- `2026_07_28_100100_add_cash_rounding_to_country_payment_settings` — adds the
  three columns and UPSERTS the TN row.
- `2026_07_28_100200_add_cash_rounding_to_pos_receipts` — adds the two receipt
  columns, swaps the `pos_receipts_totals` CHECK (pgsql-guarded), creates the
  two partial unique indexes on `journal_entries`.

Everything else is version-gated on `event_version >= 3` and therefore INERT
until a device signs a v3 receipt.

## 1. ⚠️ Owner-visible behavior change (intended)

Inserting the TN `country_payment_settings` row **tightens the live B2B payment
tolerance ceiling from the 0.50 system default to the intended 0.100.** No
tenant has a row today (the original seed insert was broken), so
`PaymentToleranceService::getToleranceSettings()` currently falls through to
system defaults. This is the intended correction — confirm the accountant is
aware before promoting to production.

POS behavior is UNAFFECTED: `pos_tolerance_enabled` and `cash_rounding_enabled`
both default to `false` and are independent of the B2B switch.

## 2. Manual steps, in order

1. **Backfill the tolerance purpose accounts** — dry run first:
   ```bash
   php artisan tenants:run accounting:backfill-tolerance-purposes -- --dry-run
   php artisan tenants:run accounting:backfill-tolerance-purposes
   ```
   A non-zero exit means at least one company is missing its chart parent
   (`65`/`75` or `6000`/`7000`). Fix the chart — do NOT skip this: without
   6580/7580 the bridge silently SKIPS the rounding/tolerance entries and only
   emits `pos.gl.tolerance_purpose_missing`.

2. **Verify the cash-tender predicate** — this MUST pass before the Phase-2
   device build ships, because the device's cash-method selection switches to
   `is_cash_tender`:
   ```bash
   php artisan tenants:run pos:configure-cash-rounding -- --verify
   ```
   Every company must report `is_cash_tender OK`. A company with no active
   `is_cash_tender` method would have a DEAD cash checkout after Phase 2.

3. **Confirm the country row state** (the same `--verify` output): TN should
   show `rounding=off`, `denomination=0.0500`, `pos_tolerance=off`,
   `b2b_tolerance=ON`, `max=0.1000`.

4. **Restart Horizon** so the workers pick up the new projection/bridge code:
   ```bash
   php artisan horizon:terminate
   ```

5. **Reseed nothing.** This phase adds no permissions, so there is NO
   `RolesAndPermissionsSeeder` run and NO `permission:cache-reset`.

## 3. Post-deploy verification

- `GET /api/v1/pos/payment-policy` (sanctum + `X-Company-Id`) returns a complete
  DTO with `cashRoundingEnabled: false`, `tenderToleranceEnabled: false`,
  `cashRoundingDenomination: "0.000"`.
- No new rows appear for these audit event types (all should be silent while
  Phase 1 is inert):
  `pos.rounding.policy_mismatch`, `pos.gl.tolerance_purpose_missing`,
  `pos.tolerance.shortfall_exceeds_config`, `pos.change.exceeds_cash_legs`.
- Existing POS receipts keep projecting: spot-check that a freshly synced v2
  receipt has NULL `cash_rounding_adjustment`, NULL `cash_rounding_denomination`,
  NULL `change_due` and NULL `tolerance_writeoff`.
- `SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'pos_receipts_totals';`
  contains `COALESCE(cash_rounding_adjustment`.

## 4. Rollback

Code rollback is safe: the migrations are additive and the CHECK swap tolerates
NULL adjustments. The only non-additive effect is the TN row's tightened B2B
ceiling — restore it with a direct update if needed:
```sql
UPDATE country_payment_settings SET max_payment_tolerance_amount = 0.5000 WHERE country_code = 'TN';
```

## 5. Phase 2 preconditions (NOT part of this deploy)

- Every terminal in a rounding-enabled tenant must be cut over to
  `fiscal_schema_version = 3` via `FiscalSchemaCutoverService` BEFORE
  `pos:configure-cash-rounding --enable-rounding`. The device's rounding gate is
  the schema-version predicate; a non-cutover terminal on a new build would sign
  rounded receipts that reach the still-live server Z path.
- Then enable, in this order: purposes verified → `--enable-rounding` →
  POS build (SQLite v63 + v3 authoring).
- Early v3 events authored before the server half is live quarantine and are
  repairable post-deploy through the version-threaded repair path.
````

- [ ] Verify the two files render (no broken fences) and that the precision-contract table lines up:
  `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && sed -n '/## Signed fiscal fields/,/## Regression guards/p' docs/architecture/precision-contract.md | head -40`

- [ ] Commit:

```bash
git add docs/architecture/precision-contract.md docs/handoff/cash-rounding-phase1-deploy-checklist.md
git commit -m "$(cat <<'GITEOF'
Cash rounding P1 T13: precision-contract signed-field entry + deploy checklist

Documents the string-fidelity exception for the two signed v3 fields (no
float cast anywhere on the path, bccomp never string equality, the folded
NF525 identity, and why COALESCE in pos_receipts_totals is load-bearing),
and adds the Phase-1 deploy checklist including the owner-visible B2B
tolerance ceiling tightening.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
GITEOF
)"
```

---

## Final verification (run before declaring Phase 1 complete)

- [ ] Full targeted suite on **Postgres**, BY PATH (never the whole suite):

```bash
cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit \
  tests/Feature/Treasury/PaymentMethodCashTenderTest.php \
  tests/Feature/Treasury/CountryPaymentSettingsCashRoundingTest.php \
  tests/Feature/POS/PosPaymentPolicyEndpointTest.php \
  tests/Feature/POS/ConfigureCashRoundingCommandTest.php \
  tests/Feature/Accounting/BackfillTolerancePurposesCommandTest.php \
  tests/Feature/Fiscal/SaleReceiptV3PayloadConstraintTest.php \
  tests/Unit/Fiscal/SaleReceiptV3KeySetTest.php \
  tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php \
  tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php \
  tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php \
  tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php \
  tests/Feature/POS/ZReportCashRoundingSummaryTest.php \
  tests/Feature/POS/CashRoundingPrintAndReportsTest.php \
  tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php
```

- [ ] Regression suites on **Postgres**, BY PATH:

```bash
cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit \
  tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php \
  tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php \
  tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php \
  tests/Feature/POS/GenerateZReportToleranceSummaryTest.php \
  tests/Feature/POS/GenerateZReportEndToEndTest.php
```

- [ ] Golden-byte suites (SQLite is fine — they are shape-only):

```bash
cd apps/api && ./vendor/bin/phpunit \
  tests/Unit/Fiscal/CanonicalGoldenVectorPhpTest.php \
  tests/Unit/Fiscal/SaleReceiptV2GoldenParityTest.php \
  tests/Unit/Fiscal/StrictCanonicalParserTest.php \
  tests/Unit/Fiscal/BestEffortPayloadParserTest.php
```

- [ ] `cd apps/api && ./vendor/bin/pint --test && ./vendor/bin/phpstan --no-progress`
- [ ] `./scripts/preflight.sh` — the standing pre-commit gate.
- [ ] DB-backed smoke on Postgres (spec §6 E2E): author a v3 SALE_RECEIPT with exact 9.997 → rounded 10.000, adjustment +0.003, denomination 0.050 signed; assert the projection columns, the three journal entries, and the Z `cash_rounding_summary`. Capture the output as committed evidence.

## Explicitly OUT of scope for this plan

- Everything in `apps/pos` — SQLite v63, `payment_policy_cache`, `CheckoutPolicySnapshot`, `computeExactCartTotal` unification, the V3 payload builder, the device key-drift gate, the device Z `tolerance_summary` real values, the device `zReportHashService.ts` mirror block, the per-shift auto-accept counter, i18n keys. That is **Plan B**.
- The refund-rounding follow-up (spec §4.7 / §8.2) — a separate small track with its own spec.
- Pre-existing ticketed debt this plan deliberately does NOT fix: the bridge posting GL for TRAINING receipts, `ReceiptVoidService` performing no GL work at all, `PaymentToleranceQueryService`'s scale-3 hardcode, the projection's fixed-scale money columns, `'CASH'` string-match normalization beyond the exact-code invariant, the historical GL drift quantification report, analytics on rounded totals, and Z_REPORT v2 (a signed Z-level rounding key).
