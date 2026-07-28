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
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryPaymentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/v1/pos/payment-policy + PosPaymentPolicyResolver (spec §4.2).
 *
 * `country_payment_settings` is EMPTY after RefreshDatabase's migrate:fresh —
 * the A2 migration's TN upsert is FK-guarded on `countries`, which is seeded
 * and never migrated (see CountryPaymentSettingsCashRoundingTest). setUp()
 * therefore replays the REAL provisioning order (CountriesSeeder, then
 * CountryPaymentSettingsSeeder — exactly what
 * TenantInitializationService::seedReferenceData does) so the TN row under
 * test is the one a freshly provisioned tenant actually gets.
 */
final class PosPaymentPolicyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);
        $this->seed(CountryPaymentSettingsSeeder::class);

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

        // Complete DTO — the device caches it verbatim, so every key must be present.
        $this->assertSame('0.000', $data['cashRoundingDenomination']);
        $this->assertSame('0.0050', $data['tenderTolerancePercentage']);
        $this->assertSame('0.100', $data['tenderToleranceMaxAmount']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $data['refreshedAt'],
        );
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

    /**
     * The scale-4 -> scale-3 re-scaling hop must survive JSON serialization too:
     * a float anywhere in the DTO/serializer re-emits `0.050` as `0.05` and every
     * signed receipt built from the cached policy quarantines.
     */
    public function test_denomination_survives_the_wire_as_a_scale_3_string(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0500',
            'pos_tolerance_enabled' => true,
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/payment-policy');

        $response->assertOk();

        $this->assertStringContainsString('"cashRoundingDenomination":"0.050"', $response->getContent() ?: '');

        $data = $response->json('data');
        $this->assertTrue($data['cashRoundingEnabled']);
        $this->assertTrue($data['tenderToleranceEnabled']);
        $this->assertIsString($data['cashRoundingDenomination']);
        $this->assertSame('0.050', $data['cashRoundingDenomination']);
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

    public function test_null_denomination_disables_rounding(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => null,
        ]);

        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);

        $this->assertFalse($dto->cashRoundingEnabled);
        $this->assertSame('0.000', $dto->cashRoundingDenomination);
    }
}
