<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\PosPaymentPolicyResolver;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryPaymentSettingsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end launch contract for a fresh tenant (first-tenant launch, Lane D1
 * task 4).
 *
 * Two states, pinned end to end:
 *
 *   (a) FRESHLY PROVISIONED — replays the real provisioning order
 *       (CountriesSeeder, then CountryPaymentSettingsSeeder — exactly what
 *       TenantInitializationService::seedReferenceData does, and now also what
 *       every demo seeder does per this lane's task 1), PaymentMethodSeeder for
 *       a valid CASH tender, and a terminal created through the REAL
 *       TerminalController::store() HTTP path (not a factory/direct insert) —
 *       and asserts the terminal lands at fiscal schema 3 and
 *       PosPaymentPolicyResolver reports a coherent DISABLED policy.
 *
 *   (b) AFTER `pos:configure-cash-rounding` — enabling rounding at
 *       denomination 0.050 for the tenant's country must be visible through
 *       BOTH the resolver (used elsewhere in the app) AND the HTTP policy
 *       endpoint the device actually polls (PosPaymentPolicyController),
 *       proving the command's write is what the device would receive.
 *
 * SQLITE LIMITATION (round-2 tenancy-authz review): like
 * CoffeeShopSeederOrderingTest, this class's default (non-PG) run executes on
 * a single SQLite connection SHARED by every table — there is no physical
 * per-tenant database and no connection swap. What THIS harness actually
 * proves: the command mutates the one row the resolver and the HTTP endpoint
 * both read, on the SAME connection — i.e. the resolver/endpoint/command
 * agree with each other. It does NOT exercise cross-tenant-DATABASE scoping
 * (that `pos:configure-cash-rounding`, run via `tenants:run` against ONE
 * physical tenant database, cannot leak into another tenant's database). This
 * class is also registered in the `backend-test-pgsql` CI job's `--filter`
 * list alongside `PosPaymentPolicyEndpointTest`, so the real per-tenant-DB
 * claim runs against actual PostgreSQL connections in CI, not just here.
 */
final class TenantLaunchContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Real provisioning order (spec §4.2 / TenantInitializationService::
        // seedReferenceData): countries must exist before the settings row's
        // country_code FK, and CountryPaymentSettingsSeeder is what a fresh
        // tenant's provisioning path now unconditionally calls (Lane D1 task 1).
        $this->seed(CountriesSeeder::class);
        $this->seed(CountryPaymentSettingsSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Launch Contract Tenant',
            'slug' => 'launch-contract-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Launch Contract Shop',
            'legal_name' => 'Launch Contract Shop SARL',
            'tax_id' => 'TAX-LAUNCH-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
            // Owner ruling B-3, 2026-08-23: terminal acquisition now refuses a
            // location with POS switched off. The factory leaves `pos_enabled`
            // at the column's `default(false)`, so a fixture that intends to
            // host a till must say so — stated here rather than hidden in the
            // factory, so the enforcement stays visible at the fixture.
            'pos_enabled' => true,
        ]);

        // Explicit $company argument — the seeder's `?Company $company = null`
        // parameter silently no-ops under `tenants:run db:seed` when omitted
        // (this lane's known trap); passing it directly here is the same
        // pattern the real provisioning path (AuthController / demo seeders)
        // uses.
        (new PaymentMethodSeeder)->run($this->company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_terminals', 'sanctum');
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->adminUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->adminUser->givePermissionTo('pos.manage_terminals');
        $this->adminUser->givePermissionTo('pos.operate_terminal');

        Sanctum::actingAs($this->adminUser);
    }

    public function test_state_a_fresh_tenant_has_settings_row_cash_tender_v3_terminal_and_coherent_disabled_policy(): void
    {
        // --- country_payment_settings row exists (Lane D1 task 1) ---
        $settingsRow = CountryPaymentSettings::query()
            ->where('country_code', 'TN')
            ->first();
        $this->assertNotNull($settingsRow, 'A fresh tenant must have a country_payment_settings row for its country.');
        $this->assertFalse((bool) $settingsRow->cash_rounding_enabled, 'CountryPaymentSettingsSeeder must create new rows with rounding DISABLED.');
        $this->assertFalse((bool) $settingsRow->pos_tolerance_enabled, 'CountryPaymentSettingsSeeder must create new rows with POS tolerance DISABLED.');

        // --- a valid CASH tender exists ---
        $cashMethod = PaymentMethod::query()
            ->where('company_id', $this->company->id)
            ->where('is_cash_tender', true)
            ->first();
        $this->assertNotNull($cashMethod, 'A fresh tenant/company must have an active is_cash_tender payment method.');
        $this->assertSame('CASH', $cashMethod->code, 'The cash-tender payment method must be code=CASH.');
        $this->assertTrue((bool) $cashMethod->is_active);

        // --- a terminal created through the REAL creation path lands at v3 ---
        $response = $this->postJson('/api/v1/pos/terminals', [
            'name' => 'Launch Terminal',
            'location_id' => $this->location->id,
        ]);
        $response->assertStatus(201);

        $terminal = Terminal::forCompany($this->company->id)->where('name', 'Launch Terminal')->firstOrFail();
        $this->assertSame(3, $terminal->fiscal_schema_version, 'A terminal created via the real TerminalController::store() path must be fiscal schema 3 from creation.');

        // --- resolver reports a coherent DISABLED policy ---
        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);
        $this->assertFalse($dto->cashRoundingEnabled, 'Fresh tenant: cash rounding must be disabled.');
        $this->assertFalse($dto->tenderToleranceEnabled, 'Fresh tenant: tender tolerance must be disabled.');
        $this->assertSame('0.000', $dto->cashRoundingDenomination);
    }

    public function test_state_b_after_configure_cash_rounding_resolver_and_http_endpoint_report_enabled_at_denomination(): void
    {
        // Precondition: the real terminal-creation path is v3 (state a re-verified
        // narrowly so this test is independently meaningful under --filter).
        $this->postJson('/api/v1/pos/terminals', [
            'name' => 'Launch Terminal',
            'location_id' => $this->location->id,
        ])->assertStatus(201);

        // The REUSED, EXISTING operator command. Under this (default, SQLite)
        // harness there is only ONE shared connection, so "scoped to this
        // tenant" means only that the command, the resolver, and the HTTP
        // endpoint below all read/write the same in-test database — it does
        // NOT exercise `tenants:run`'s real per-tenant-DATABASE isolation (see
        // the class docblock's SQLITE LIMITATION note). That production
        // guarantee is what registering this class in the `backend-test-pgsql`
        // CI job now covers, against real per-connection PostgreSQL databases.
        $this->artisan('pos:configure-cash-rounding', [
            '--country' => 'TN',
            '--denomination' => '0.050',
            '--enable-rounding' => true,
        ])->assertSuccessful();

        // --- resolver reflects the change ---
        $dto = app(PosPaymentPolicyResolver::class)->forCompany($this->company->id);
        $this->assertTrue($dto->cashRoundingEnabled);
        $this->assertSame('0.050', $dto->cashRoundingDenomination);

        // --- the HTTP endpoint the device actually polls reflects the same state ---
        $response = $this->getJson('/api/v1/pos/payment-policy');
        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['cashRoundingEnabled']);
        $this->assertSame('0.050', $data['cashRoundingDenomination']);
    }
}
