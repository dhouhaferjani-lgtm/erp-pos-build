<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury spine (Task 25): `GET /api/v1/treasury/cash-position` — server-side
 * aggregation over `payment_repositories.balance` (the port-managed, reconcile-
 * guarded, authoritative cached balance — Task 22), replacing the FE client-side
 * sum in TreasuryOverviewPage.
 */
class CashPositionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cash Position Tenant',
            'slug' => 'cash-position-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Position User',
            'email' => 'cash-position@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['treasury.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->companyA->id);
    }

    public function test_aggregates_balances_by_type_with_grand_total_company_scoped(): void
    {
        // Company A — two active cash registers, one active bank account, one
        // active safe, one INACTIVE cash register (excluded), one VIRTUAL
        // repository (excluded — mirrors the FE's isCashRepositoryType()).
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => 'CASH_01',
            'name' => 'Cash Register 1',
            'type' => 'cash_register',
            'balance' => '100.000',
            'is_active' => true,
        ]);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => 'CASH_02',
            'name' => 'Cash Register 2',
            'type' => 'cash_register',
            'balance' => '50.500',
            'is_active' => true,
        ]);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => 'BANK_01',
            'name' => 'Main Bank Account',
            'type' => 'bank_account',
            'balance' => '200.250',
            'is_active' => true,
        ]);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => 'SAFE_01',
            'name' => 'Main Safe',
            'type' => 'safe',
            'balance' => '75.000',
            'is_active' => true,
        ]);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => 'CASH_03_INACTIVE',
            'name' => 'Closed Cash Register',
            'type' => 'cash_register',
            'balance' => '99999.000',
            'is_active' => false,
        ]);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => 'VIRTUAL_01',
            'name' => 'Suspense',
            'type' => 'virtual',
            'balance' => '999.000',
            'is_active' => true,
        ]);

        // Company B (same tenant, different company) — must be excluded entirely.
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'code' => 'CASH_B_01',
            'name' => 'Company B Cash Register',
            'type' => 'cash_register',
            'balance' => '1000.000',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/treasury/cash-position');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('as_of', $data);
        $this->assertNotEmpty($data['as_of']);
        $this->assertSame('TND', $data['currency']);

        $groupsByType = collect($data['groups'])->keyBy('type');

        // Exactly the three cash-position groups — no phantom 'virtual' group.
        $this->assertSame(['cash_register', 'bank_account', 'safe'], $groupsByType->keys()->all());

        $this->assertSame('150.500', $groupsByType['cash_register']['total']);
        $this->assertIsString($groupsByType['cash_register']['total']);
        $this->assertCount(2, $groupsByType['cash_register']['repositories']);

        $this->assertSame('200.250', $groupsByType['bank_account']['total']);
        $this->assertCount(1, $groupsByType['bank_account']['repositories']);

        $this->assertSame('75.000', $groupsByType['safe']['total']);
        $this->assertCount(1, $groupsByType['safe']['repositories']);

        $this->assertSame('425.750', $data['grand_total']);
        $this->assertIsString($data['grand_total']);

        foreach ($groupsByType as $group) {
            foreach ($group['repositories'] as $repository) {
                $this->assertIsString($repository['balance']);
            }
        }
    }

    public function test_group_by_location_reconciles_and_exposes_unattributed_bucket(): void
    {
        $storeA = Location::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Store A']);
        $storeB = Location::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Store B']);
        PaymentRepository::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->companyA->id, 'type' => 'cash_register', 'location_id' => $storeA->id, 'balance' => '100.000', 'is_active' => true]);
        PaymentRepository::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->companyA->id, 'type' => 'safe', 'location_id' => $storeB->id, 'balance' => '250.000', 'is_active' => true]);
        PaymentRepository::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->companyA->id, 'type' => 'bank_account', 'location_id' => null, 'balance' => '500.000', 'is_active' => true]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/treasury/cash-position?group_by=location');

        $response->assertOk()->assertJsonPath('data.groups_by_location.0.location_name', 'Store A');
        $this->assertSame('850.000', $response->json('data.grand_total'));
        $this->assertNotNull(collect($response->json('data.groups_by_location'))->firstWhere('location_id', null));
    }

    public function test_restricted_membership_hides_other_locations_and_unattributed_without_a_filter(): void
    {
        $storeA = Location::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Store A']);
        $storeB = Location::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Store B']);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'type' => 'cash_register',
            'location_id' => $storeA->id,
            'balance' => '10.000',
            'is_active' => true,
        ]);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'type' => 'cash_register',
            'location_id' => $storeB->id,
            'balance' => '20.000',
            'is_active' => true,
        ]);
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'type' => 'bank_account',
            'location_id' => null,
            'balance' => '30.000',
            'is_active' => true,
        ]);
        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->companyA->id)
            ->update(['allowed_location_ids' => [$storeA->id]]);

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/cash-position?group_by=location')
            ->assertOk()
            ->json('data');

        self::assertSame('10.000', $data['grand_total']);
        self::assertCount(1, $data['groups_by_location']);
        self::assertSame($storeA->id, $data['groups_by_location'][0]['location_id']);
    }

    public function test_flows_are_absent_without_param(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/cash-position')
            ->assertOk()
            ->assertJsonMissingPath('data.flows');
    }

    public function test_flows_window_sums_in_and_out_using_occurred_at(): void
    {
        $cashRegister = $this->createRepository('CASH_FLOW', 'cash_register');
        $bankAccount = $this->createRepository('BANK_FLOW', 'bank_account');
        $safe = $this->createRepository('SAFE_FLOW', 'safe');
        $inactive = $this->createRepository('CASH_INACTIVE', 'cash_register', false);
        $virtual = $this->createRepository('VIRTUAL_FLOW', 'virtual');

        $this->insertMovement($cashRegister, 'in', '100.125', now()->subDays(6), now()->subDays(30), 1);
        $this->insertMovement($bankAccount, 'in', '25.375', now()->subDay(), now(), 1);
        $this->insertMovement($safe, 'out', '40.250', now()->subDays(2), now(), 1);

        // created_at is in range, but occurred_at is not: business date wins.
        $this->insertMovement($cashRegister, 'in', '999.000', now()->subDays(8), now(), 2);
        $this->insertMovement($inactive, 'in', '888.000', now()->subDay(), now(), 1);
        $this->insertMovement($virtual, 'out', '777.000', now()->subDay(), now(), 1);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/cash-position?flows_window=7');

        $response->assertOk()
            ->assertJsonPath('data.flows.window_days', 7)
            ->assertJsonPath('data.flows.in', '125.500')
            ->assertJsonPath('data.flows.out', '40.250');

        $this->assertIsString($response->json('data.flows.in'));
        $this->assertIsString($response->json('data.flows.out'));
    }

    public function test_flows_exclude_foreign_currency_repositories(): void
    {
        $companyCurrencyRepository = $this->createRepository('TND_FLOW', 'cash_register');
        $foreignCurrencyRepository = $this->createRepository('EUR_FLOW', 'bank_account', true, 'EUR');

        $this->insertMovement($companyCurrencyRepository, 'in', '10.125', now()->subDay(), now(), 1);
        $this->insertMovement($foreignCurrencyRepository, 'in', '999.990', now()->subDay(), now(), 1, 'EUR');
        $this->insertMovement($foreignCurrencyRepository, 'out', '500.000', now()->subDay(), now(), 2, 'EUR');

        $this->actingAs($this->user)
            ->getJson('/api/v1/treasury/cash-position?flows_window=7')
            ->assertOk()
            ->assertJsonPath('data.flows.in', '10.125')
            ->assertJsonPath('data.flows.out', '0.000');
    }

    public function test_flows_window_is_validated_with_canonical_error_envelope(): void
    {
        foreach (['0', '91', 'x'] as $window) {
            $this->actingAs($this->user)
                ->getJson("/api/v1/treasury/cash-position?flows_window={$window}")
                ->assertStatus(422)
                ->assertExactJson([
                    'error' => [
                        'code' => 'BUSINESS_ERROR',
                        'message' => 'flows_window must be an integer between 1 and 90.',
                    ],
                ]);
        }
    }

    public function test_forbidden_without_treasury_view_permission(): void
    {
        $this->user->revokePermissionTo('treasury.view');

        $response = $this->actingAs($this->user)->getJson('/api/v1/treasury/cash-position');

        $response->assertStatus(403);
    }

    private function createRepository(
        string $code,
        string $type,
        bool $isActive = true,
        string $currency = 'TND',
    ): PaymentRepository {
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'balance' => '0.000',
            'currency' => $currency,
            'is_active' => $isActive,
        ]);
    }

    private function insertMovement(
        PaymentRepository $repository,
        string $direction,
        string $amount,
        mixed $occurredAt,
        mixed $createdAt,
        int $ordinal,
        string $currency = 'TND',
    ): void {
        DB::table('repository_movements')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'payment_repository_id' => $repository->id,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $currency,
            'balance_after' => '0.000',
            'ordinal' => $ordinal,
            'source_type' => 'opening_balance',
            'source_id' => (string) Str::uuid(),
            'idempotency_key' => 'cash-position:'.Str::uuid(),
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
        ]);
    }
}
