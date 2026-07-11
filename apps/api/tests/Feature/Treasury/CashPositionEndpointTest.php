<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_forbidden_without_treasury_view_permission(): void
    {
        $this->user->revokePermissionTo('treasury.view');

        $response = $this->actingAs($this->user)->getJson('/api/v1/treasury/cash-position');

        $response->assertStatus(403);
    }
}
