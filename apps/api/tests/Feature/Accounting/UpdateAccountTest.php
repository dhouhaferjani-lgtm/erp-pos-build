<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateAccountTest extends TestCase
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
            'slug' => 'test-tenant',
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
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['accounts.view', 'accounts.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_user_can_update_account_name(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'name' => 'Cash and Cash Equivalents',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Cash and Cash Equivalents', $response->json('data.name'));
    }

    public function test_user_can_update_account_description(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'description' => 'All cash accounts including petty cash',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('All cash accounts including petty cash', $response->json('data.description'));
    }

    public function test_system_account_cannot_be_modified(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'is_system' => true,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'name' => 'Modified Name',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('SYSTEM_ACCOUNT_IMMUTABLE', $response->json('error.code'));
    }

    public function test_account_type_cannot_be_changed(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'type' => 'liability',
        ]);

        // Type change should be ignored or rejected
        $response->assertStatus(200);
        $this->assertEquals('asset', $response->json('data.type'));
    }

    public function test_account_can_be_deactivated(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.is_active'));
    }

    public function test_unauthorized_user_cannot_update_account(): void
    {
        $this->user->revokePermissionTo('accounts.manage');

        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_update_account_from_other_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $account = Account::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/accounts/{$account->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertStatus(404);
    }
}
