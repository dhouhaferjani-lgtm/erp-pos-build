<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
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

final class ExpenseShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_200_for_authorized_user(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.view']);

        app(CompanyContext::class)->setCompanyId($company->id);

        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $expense->id);
    }

    public function test_show_exposes_supplier_and_vat_response_contract_as_strings(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.view']);

        app(CompanyContext::class)->setCompanyId($company->id);

        $supplier = Partner::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'name' => 'Papeterie Atlas',
            'type' => PartnerType::Supplier,
        ]);
        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
            'partner_id' => $supplier->id,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'vendor_name' => 'Atlas receipt counter',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.partner_id', $supplier->id)
            ->assertJsonPath('data.partner.id', $supplier->id)
            ->assertJsonPath('data.partner.name', 'Papeterie Atlas')
            ->assertJsonPath('data.subtotal', '100.000')
            ->assertJsonPath('data.tax_amount', '19.000')
            ->assertJsonPath('data.metadata.vat_rate', '19.00')
            ->assertJsonPath('data.metadata.vat_deductible_percent', '80.00');
    }

    public function test_show_does_not_disclose_an_inconsistent_cross_company_partner(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.view']);
        $otherCompany = Company::factory()->create([
            'tenant_id' => $user->tenant_id,
        ]);
        $foreignSupplier = Partner::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $otherCompany->id,
            'name' => 'Foreign Company Supplier',
            'type' => PartnerType::Supplier,
        ]);
        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
            'partner_id' => $foreignSupplier->id,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.partner_id', null)
            ->assertJsonPath('data.partner', null)
            ->assertJsonMissing(['name' => 'Foreign Company Supplier']);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
