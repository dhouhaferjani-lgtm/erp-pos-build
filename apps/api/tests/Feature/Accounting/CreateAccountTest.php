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
use Tests\Traits\AssertsApiValidation;

class CreateAccountTest extends TestCase
{
    use AssertsApiValidation;
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

    public function test_user_can_create_account(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'description' => 'Cash and cash equivalents',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('1100', $response->json('data.code'));
        $this->assertEquals('Cash', $response->json('data.name'));
        $this->assertEquals('asset', $response->json('data.type'));
    }

    public function test_account_requires_code(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'name' => 'Cash',
            'type' => 'asset',
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_account_requires_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'type' => 'asset',
        ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_account_requires_valid_type(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'invalid_type',
        ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_account_code_must_be_unique_per_tenant(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Another Cash Account',
            'type' => 'asset',
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_account_can_have_parent(): void
    {
        $parentAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::Asset,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Current Assets',
            'type' => 'asset',
            'parent_id' => $parentAccount->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals($parentAccount->id, $response->json('data.parent_id'));
    }

    public function test_unauthorized_user_cannot_create_account(): void
    {
        $this->user->revokePermissionTo('accounts.manage');

        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
        ]);

        $response->assertStatus(403);
    }

    public function test_account_is_active_by_default(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.is_active'));
    }

    public function test_account_is_not_system_by_default(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
        ]);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.is_system'));
    }
}
