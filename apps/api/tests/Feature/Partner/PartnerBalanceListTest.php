<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartnerBalanceListTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-balance',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'balance-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_balance_fields_appear_in_list_response(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer With Balance',
            'type' => PartnerType::Customer,
            'receivable_balance' => '1500.000',
            'credit_balance' => '200.000',
            'payable_balance' => '0.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'type',
                        'receivable_balance',
                        'credit_balance',
                        'payable_balance',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
                'aggregates' => ['total_partners', 'total_active', 'total_receivable', 'total_payable'],
            ])
            ->assertJsonPath('data.0.receivable_balance', '1500.000')
            ->assertJsonPath('data.0.credit_balance', '200.000')
            ->assertJsonPath('data.0.payable_balance', '0.000');
    }

    public function test_sort_by_receivable_balance(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Low Balance',
            'type' => PartnerType::Customer,
            'receivable_balance' => '100.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'High Balance',
            'type' => PartnerType::Customer,
            'receivable_balance' => '5000.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?sort_by=receivable_balance&sort_dir=desc');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('High Balance', $data[0]['name']);
        $this->assertEquals('Low Balance', $data[1]['name']);
    }

    public function test_sort_by_net_balance(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Low Net',
            'type' => PartnerType::Customer,
            'receivable_balance' => '1000.000',
            'credit_balance' => '800.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'High Net',
            'type' => PartnerType::Customer,
            'receivable_balance' => '1000.000',
            'credit_balance' => '100.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?sort_by=net_balance&sort_dir=desc');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('High Net', $data[0]['name']);
        $this->assertEquals('Low Net', $data[1]['name']);
    }

    public function test_sort_by_net_balance_offsets_both_partner_payable_balance(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Both Partner',
            'type' => PartnerType::Both,
            'receivable_balance' => '1000.000',
            'credit_balance' => '100.000',
            'payable_balance' => '250.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer Net Partner',
            'type' => PartnerType::Customer,
            'receivable_balance' => '800.000',
            'credit_balance' => '0.000',
            'payable_balance' => '0.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?sort_by=net_balance&sort_dir=desc');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('Customer Net Partner', $data[0]['name']);
        $this->assertEquals('Both Partner', $data[1]['name']);
    }

    public function test_has_balance_filter_excludes_zero_balance_partners(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'With Balance',
            'type' => PartnerType::Customer,
            'receivable_balance' => '500.000',
            'credit_balance' => '0.000',
            'payable_balance' => '0.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Zero Balance',
            'type' => PartnerType::Customer,
            'receivable_balance' => '0.000',
            'credit_balance' => '0.000',
            'payable_balance' => '0.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?has_balance=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'With Balance');
    }

    public function test_has_balance_filter_includes_payable_balance(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier With Payable',
            'type' => PartnerType::Supplier,
            'receivable_balance' => '0.000',
            'credit_balance' => '0.000',
            'payable_balance' => '300.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Zero Supplier',
            'type' => PartnerType::Supplier,
            'receivable_balance' => '0.000',
            'credit_balance' => '0.000',
            'payable_balance' => '0.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?has_balance=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Supplier With Payable');
    }

    public function test_balance_min_and_max_range_filters(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Small',
            'type' => PartnerType::Customer,
            'receivable_balance' => '50.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Medium',
            'type' => PartnerType::Customer,
            'receivable_balance' => '500.000',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Large',
            'type' => PartnerType::Customer,
            'receivable_balance' => '5000.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?balance_min=100&balance_max=1000');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Medium');
    }

    public function test_aggregates_included_in_response(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Active Customer',
            'type' => PartnerType::Customer,
            'receivable_balance' => '1000.000',
            'payable_balance' => '0.000',
        ]);

        $inactiveSupplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Inactive Supplier',
            'type' => PartnerType::Supplier,
            'receivable_balance' => '0.000',
            'payable_balance' => '750.000',
        ]);
        $inactiveSupplier->is_active = false;
        $inactiveSupplier->save();

        $inactiveCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Inactive Customer',
            'type' => PartnerType::Customer,
            'receivable_balance' => '200.000',
            'payable_balance' => '0.000',
        ]);
        $inactiveCustomer->is_active = false;
        $inactiveCustomer->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners');

        $response->assertOk();
        $aggregates = $response->json('aggregates');

        $this->assertEquals(3, $aggregates['total_partners']);
        $this->assertEquals(1, $aggregates['total_active']);
        $this->assertEquals('1200.000', $aggregates['total_receivable']);
        $this->assertEquals('750.000', $aggregates['total_payable']);
    }

    public function test_offset_pagination_meta_shape(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Partner::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => "Partner {$i}",
                'type' => PartnerType::Customer,
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?per_page=10&page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertCount(10, $response->json('data'));
    }
}
