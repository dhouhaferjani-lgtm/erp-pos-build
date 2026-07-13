<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
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
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ExpenseRequestVatValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Partner $partner;

    private Partner $otherCompanyPartner;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Expense VAT Validation Tenant',
            'slug' => 'expense-vat-validation-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = $this->createCompany($tenant, 'Primary Company', 'PRIMARY-TAX');
        $otherCompany = $this->createCompany($tenant, 'Other Company', 'OTHER-TAX');

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Expense Creator',
            'email' => 'expense_creator_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo('expenses.create');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = $this->createPartner($tenant, $this->company, 'Primary Supplier');
        $this->otherCompanyPartner = $this->createPartner($tenant, $otherCompany, 'Other Supplier');
    }

    public function test_partner_id_from_another_company_is_rejected(): void
    {
        $response = $this->postExpense([
            'partner_id' => $this->otherCompanyPartner->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('partner_id', 'error.errors');
    }

    public function test_vat_rate_rejects_more_than_two_decimal_places(): void
    {
        $response = $this->postExpense([
            'vat_rate' => '19.555',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('vat_rate', 'error.errors');
    }

    public function test_vat_amount_rejects_more_than_three_decimal_places(): void
    {
        $response = $this->postExpense([
            'vat_amount' => '1.2345',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('vat_amount', 'error.errors');
    }

    public function test_vat_deductible_percent_rejects_values_above_one_hundred(): void
    {
        $response = $this->postExpense([
            'vat_deductible_percent' => '101',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('vat_deductible_percent', 'error.errors');
    }

    public function test_partner_and_vat_amount_with_valid_formats_are_accepted(): void
    {
        $this->postExpense([
            'partner_id' => $this->partner->id,
            'vat_amount' => '1.900',
        ])->assertCreated();
    }

    public function test_create_response_exposes_supplier_and_vat_response_contract(): void
    {
        $this->postExpense([
            'partner_id' => $this->partner->id,
            'vendor_name' => 'Primary Supplier receipt',
            'total' => '119.000',
            'vat_amount' => '19.000',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ])->assertCreated()
            ->assertJsonPath('data.partner_id', $this->partner->id)
            ->assertJsonPath('data.partner.id', $this->partner->id)
            ->assertJsonPath('data.partner.name', 'Primary Supplier')
            ->assertJsonPath('data.subtotal', '100.000')
            ->assertJsonPath('data.tax_amount', '19.000')
            ->assertJsonPath('data.metadata.vat_rate', '19.00')
            ->assertJsonPath('data.metadata.vat_deductible_percent', '80.00');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return TestResponse<Response>
     */
    private function postExpense(array $overrides): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/expenses', array_merge([
                'total' => '10.000',
                'is_paid' => false,
            ], $overrides));
    }

    private function createCompany(Tenant $tenant, string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => $taxId,
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createPartner(Tenant $tenant, Company $company, string $name): Partner
    {
        return Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => $name,
            'type' => PartnerType::Supplier,
        ]);
    }
}
