<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Domain\CountryPaymentDefaults;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryPaymentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `pos:configure-cash-rounding` (spec §4.2, Task 4).
 *
 * `country_payment_settings` is EMPTY after RefreshDatabase's migrate:fresh —
 * the A2 migration's TN upsert is FK-guarded on `countries`, which is seeded
 * and never migrated (see CountryPaymentSettingsCashRoundingTest). setUp()
 * therefore replays the REAL provisioning order (CountriesSeeder, then
 * CountryPaymentSettingsSeeder — what TenantInitializationService::seedReferenceData
 * does), so the TN row under test is the one a freshly provisioned tenant gets.
 */
final class ConfigureCashRoundingCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);
        $this->seed(CountryPaymentSettingsSeeder::class);

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
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->cash_rounding_enabled);
    }

    public function test_enable_rounding_writes_only_the_rounding_switch(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-rounding' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->cash_rounding_enabled);
        $this->assertFalse((bool) $row->pos_tolerance_enabled, 'The two switches are independent.');
    }

    public function test_enable_tolerance_does_not_touch_the_b2b_switch_or_the_rounding_switch(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'payment_tolerance_enabled' => false,
        ]);

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-tolerance' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->pos_tolerance_enabled);
        $this->assertFalse((bool) $row->payment_tolerance_enabled, 'B2B tolerance must be untouched.');
        $this->assertFalse((bool) $row->cash_rounding_enabled, 'The two switches are independent.');
    }

    public function test_disable_tolerance_does_not_touch_the_b2b_switch_value(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'payment_tolerance_enabled' => true,
            'pos_tolerance_enabled' => true,
        ]);

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--disable-tolerance' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
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
        $this->assertFalse((bool) $row->cash_rounding_enabled, 'A created row must stay fail-closed.');
        $this->assertFalse((bool) $row->pos_tolerance_enabled);
    }

    /**
     * A row this command creates must carry the seeder's PINNED ceilings — the
     * raw column default (0.50) would ship a 5x looser POS tolerance than the
     * 0.100 TN sanctions, straight through the resolver to the device.
     */
    public function test_enable_tolerance_on_a_missing_row_carries_the_pinned_ceilings(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-tolerance' => true,
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->pos_tolerance_enabled);
        $this->assertSame(
            0,
            bccomp((string) $row->max_payment_tolerance_amount, '0.1000', 4),
            'The created row must carry the pinned TN ceiling, not the 0.50 column default.',
        );
        $this->assertSame(0, bccomp((string) $row->payment_tolerance_percentage, '0.0050', 4));
        $this->assertFalse((bool) $row->cash_rounding_enabled);
    }

    public function test_enable_tolerance_on_a_missing_row_for_an_unseeded_country_is_refused(): void
    {
        // IT is in the countries lookup but has no pinned defaults.
        $this->assertNull(CountryPaymentDefaults::forCountry('IT'));

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'IT',
            '--enable-tolerance' => true,
        ])
            ->expectsOutputToContain('CountryPaymentSettingsSeeder')
            ->assertFailed();

        $this->assertSame(0, DB::table('country_payment_settings')->where('country_code', 'IT')->count());
    }

    public function test_creating_a_row_for_an_unseeded_country_without_tolerance_is_allowed(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'IT',
            '--denomination' => '0.0500',
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'IT')->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->pos_tolerance_enabled, 'POS tolerance stays off, so the ceiling never ships.');
        $this->assertFalse((bool) $row->cash_rounding_enabled);
    }

    public function test_upsert_refuses_to_create_a_row_for_an_unknown_country(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'ZZ',
            '--denomination' => '0.0500',
        ])->assertFailed();

        $this->assertSame(0, DB::table('country_payment_settings')->where('country_code', 'ZZ')->count());
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

    public function test_verify_fails_when_the_only_cash_tender_method_is_inactive(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => false,
            'position' => 1,
        ]);

        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->expectsOutputToContain('has no active is_cash_tender payment method')
            ->assertFailed();
    }

    public function test_verify_succeeds_when_every_company_has_a_cash_tender_method(): void
    {
        $this->createCashMethod();

        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->assertSuccessful();
    }

    /**
     * `tenants:run` swallows the child exit code (Run::handle() returns null),
     * so deploy checklists gate on this token. Its EXACT shape is the contract.
     */
    public function test_verify_emits_the_stable_gate_token_when_clean(): void
    {
        $this->createCashMethod();

        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->expectsOutputToContain('CASH-ROUNDING VERIFY FAILURES: 0')
            ->assertSuccessful();
    }

    public function test_verify_emits_the_stable_gate_token_with_the_failure_count(): void
    {
        // One company, no cash tender => exactly one failure.
        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->expectsOutputToContain('CASH-ROUNDING VERIFY FAILURES: 1')
            ->assertFailed();
    }

    public function test_verify_fails_when_rounding_is_enabled_with_an_unusable_denomination(): void
    {
        $this->createCashMethod();

        // Written behind the command's back (direct SQL / an older ops run):
        // 5.0000 blows the §4.1 scale-3 cap, so the resolver silently reports
        // rounding DISABLED while the operator believes it is on.
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '5.0000',
        ]);

        $this->artisan('pos:configure-cash-rounding', ['--verify' => true])
            ->expectsOutputToContain('exceeds the maximum sanctioned denomination')
            ->assertFailed();
    }

    public function test_rejects_a_denomination_that_is_not_representable_at_company_scale(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0.0025',
        ])->assertFailed();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_rejects_a_denomination_above_the_shared_static_cap(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '5.0000',
        ])
            ->expectsOutputToContain('exceeds the maximum sanctioned denomination')
            ->assertFailed();
    }

    public function test_allows_the_denomination_exactly_at_the_shared_static_cap(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '1.0000',
        ])->assertSuccessful();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '1.0000', 4));
    }

    public function test_rejects_a_denomination_that_loses_precision_in_the_column(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0.00255',
        ])->assertFailed();
    }

    public function test_rejects_scientific_notation_instead_of_crashing(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '5e-2',
        ])->assertFailed();
    }

    public function test_rejects_a_non_positive_denomination(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0',
        ])->assertFailed();
    }

    public function test_the_currency_scale_comes_from_the_countries_lookup_not_the_iso_map(): void
    {
        // ISO says TND = 3; the tenant lookup row is authoritative (the same
        // single-source rule PosPaymentPolicyResolver::resolveScale follows).
        DB::table('countries')->where('code', 'TN')->update(['currency_decimal_places' => 2]);

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0.0050',
        ])->assertFailed();
    }

    public function test_falls_back_to_the_country_lookup_when_no_company_uses_the_country(): void
    {
        // No company is registered in FR, so the countries lookup row supplies
        // the scale — an uncapped write must still be refused.
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'FR',
            '--denomination' => '5.0000',
        ])->assertFailed();

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'FR',
            '--denomination' => '0.0500',
        ])->assertSuccessful();
    }

    public function test_enabling_rounding_over_an_unusable_stored_denomination_is_refused(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => null,
        ]);

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-rounding' => true,
        ])->assertFailed();

        $row = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->cash_rounding_enabled);
    }

    public function test_mutually_exclusive_flags_are_rejected(): void
    {
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-rounding' => true,
            '--disable-rounding' => true,
        ])->assertFailed();

        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--enable-tolerance' => true,
            '--disable-tolerance' => true,
        ])->assertFailed();
    }

    public function test_a_no_op_invocation_is_rejected(): void
    {
        $this->artisan('pos:configure-cash-rounding', ['--country' => 'TN'])->assertFailed();
    }

    private function createCashMethod(): void
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
    }
}
