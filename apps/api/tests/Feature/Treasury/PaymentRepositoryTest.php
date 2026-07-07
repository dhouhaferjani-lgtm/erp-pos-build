<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
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
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class PaymentRepositoryTest extends TestCase
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
        $this->user->givePermissionTo(['repositories.view', 'repositories.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_can_list_payment_repositories(): void
    {
        PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Main Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK_01',
            'name' => 'Main Bank Account',
            'type' => 'bank_account',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payment-repositories');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_create_cash_register(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CASH_REG_01',
            'name' => 'Main Cash Register',
            'type' => 'cash_register',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'CASH_REG_01');
        $response->assertJsonPath('data.type', 'cash_register');
        $response->assertJsonPath('data.balance', '0.000');

        $this->assertDatabaseHas('payment_repositories', [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'type' => 'cash_register',
        ]);
    }

    public function test_tenant_backfill_migration_copies_gl_account_to_missing_account_id(): void
    {
        $account = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '101',
            'name' => 'Cash',
            'is_active' => true,
        ]);

        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_GL',
            'name' => 'Cash With GL',
            'type' => 'cash_register',
            'account_id' => null,
            'gl_account_id' => $account->id,
            'is_active' => true,
        ]);

        $withoutGl = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_NO_GL',
            'name' => 'Cash Without GL',
            'type' => 'cash_register',
            'account_id' => null,
            'gl_account_id' => null,
            'is_active' => true,
        ]);

        $migration = $this->paymentRepositoryAccountBackfillMigration();
        $migrationUp = new ReflectionMethod($migration, 'up');
        $migrationUp->invoke($migration);
        $migrationUp->invoke($migration);

        $this->assertSame($account->id, $repository->refresh()->account_id);
        $this->assertNull($withoutGl->refresh()->account_id);
    }

    public function test_create_repository_defaults_account_id_to_gl_account_id_when_omitted(): void
    {
        $account = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '102',
            'name' => 'POS Cash',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CASH_GL_DEFAULT',
            'name' => 'Cash GL Default',
            'type' => 'cash_register',
            'gl_account_id' => $account->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payment_repositories', [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_GL_DEFAULT',
            'account_id' => $account->id,
            'gl_account_id' => $account->id,
        ]);
    }

    public function test_can_create_bank_account_with_details(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'BANK_MAIN',
            'name' => 'Main Operating Account',
            'type' => 'bank_account',
            'bank_name' => 'BNP Paribas',
            'account_number' => '12345678901234',
            'iban' => 'FR7612345678901234567890123',
            'bic' => 'BNPAFRPP',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'bank_account');
        $response->assertJsonPath('data.bank_name', 'BNP Paribas');
        $response->assertJsonPath('data.iban', 'FR7612345678901234567890123');
    }

    public function test_can_create_safe_for_checks(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CHECK_SAFE',
            'name' => 'Check Safe',
            'type' => 'safe',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'safe');
    }

    public function test_can_create_virtual_repository(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'VOUCHER_BOX',
            'name' => 'Meal Voucher Collection',
            'type' => 'virtual',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'virtual');
    }

    public function test_cannot_create_duplicate_repository_code(): void
    {
        PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CASH_REG_01',
            'name' => 'Another Cash Register',
            'type' => 'cash_register',
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_can_update_repository(): void
    {
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/payment-repositories/{$repository->id}", [
            'name' => 'Main Cash Register (Updated)',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Main Cash Register (Updated)');
    }

    public function test_update_repository_preserves_distinct_stored_account_id_when_account_id_is_omitted(): void
    {
        $b2bAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivable',
            'is_active' => true,
        ]);
        $posAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '101',
            'name' => 'POS Cash',
            'is_active' => true,
        ]);
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_SPLIT',
            'name' => 'Split Cash Register',
            'type' => 'cash_register',
            'account_id' => $b2bAccount->id,
            'gl_account_id' => $posAccount->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/payment-repositories/{$repository->id}", [
            'name' => 'Renamed Split Cash Register',
        ]);

        $response->assertStatus(200);
        $fresh = $repository->refresh();
        $this->assertSame($b2bAccount->id, $fresh->account_id);
        $this->assertSame($posAccount->id, $fresh->gl_account_id);
    }

    public function test_can_show_single_repository(): void
    {
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/payment-repositories/{$repository->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'CASH_REG_01');
    }

    public function test_can_get_repository_balance(): void
    {
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'balance' => '1500.00',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/payment-repositories/{$repository->id}/balance");

        $response->assertStatus(200);
        $response->assertJsonPath('data.balance', '1500.000');
    }

    public function test_unauthorized_user_cannot_create_repository(): void
    {
        $this->user->revokePermissionTo('repositories.manage');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
        ]);

        $response->assertStatus(403);
    }

    public function test_repositories_are_tenant_isolated(): void
    {
        PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        // Create another tenant with a user
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $otherUser->givePermissionTo(['repositories.view', 'repositories.manage']);

        UserCompanyMembership::create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($otherCompany->id);

        $response = $this->actingAs($otherUser)->getJson('/api/v1/payment-repositories');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    private function paymentRepositoryAccountBackfillMigration(): Migration
    {
        $matches = glob(database_path('migrations/tenant/*_backfill_payment_repository_account_ids.php'));
        $this->assertIsArray($matches);
        $this->assertCount(1, $matches, 'Expected exactly one tenant backfill migration for payment repository account ids.');

        $migration = require $matches[0];
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
