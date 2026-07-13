<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $response->assertJsonPath('data.0.currency', 'EUR');
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

    public function test_bank_reference_is_persisted_while_invalid_account_details_only_warn(): void
    {
        $bank = Bank::query()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'name' => 'Banque de test',
            'short_name' => 'BDT',
            'bic' => 'BDTEFRPP',
            'rib_bank_code' => '30004',
            'is_active' => true,
            'is_custom' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', [
            'code' => 'BANK_WARN',
            'name' => 'Warn-only account',
            'type' => 'bank_account',
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'account_number' => 'not-a-valid-rib',
            'iban' => 'not-a-valid-iban',
            'bic' => $bank->bic,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.bank_id', $bank->id)
            ->assertJsonPath('data.bank_account_validation.rib.valid', false)
            ->assertJsonPath('data.bank_account_validation.iban.valid', false)
            ->assertJsonPath('data.bank_account_validation.bic_valid', true);

        $this->assertDatabaseHas('payment_repositories', [
            'code' => 'BANK_WARN',
            'bank_id' => $bank->id,
            'account_number' => 'not-a-valid-rib',
            'iban' => 'not-a-valid-iban',
        ]);
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

    public function test_cannot_reassign_gl_account_with_unposted_transfer_legs(): void
    {
        [$repository, $originalAccount, $replacementAccount] = $this->repositoryAndGlAccounts();
        $this->insertTransferMovement($repository, ordinal: 1);
        $this->insertTransferMovement($repository, ordinal: 2);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/payment-repositories/{$repository->id}", [
            'gl_account_id' => $replacementAccount->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'BUSINESS_ERROR')
            ->assertJsonPath(
                'error.message',
                'Cannot reassign the repository GL account while 2 transfer movement legs have no journal entry.',
            );
        $this->assertSame($originalAccount->id, $repository->refresh()->gl_account_id);
    }

    public function test_can_reassign_gl_account_without_transfer_legs(): void
    {
        [$repository, , $replacementAccount] = $this->repositoryAndGlAccounts();

        $response = $this->actingAs($this->user)->patchJson("/api/v1/payment-repositories/{$repository->id}", [
            'gl_account_id' => $replacementAccount->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.gl_account_id', $replacementAccount->id);
        $this->assertSame($replacementAccount->id, $repository->refresh()->gl_account_id);
    }

    public function test_can_reassign_gl_account_when_transfer_legs_have_journal_entries(): void
    {
        [$repository, , $replacementAccount] = $this->repositoryAndGlAccounts();
        $journalEntry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'TRF-'.Str::upper(Str::random(8)),
            'entry_date' => now(),
            'description' => 'Posted transfer fixture',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
        ]);
        $this->insertTransferMovement($repository, ordinal: 1, journalEntryId: $journalEntry->id);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/payment-repositories/{$repository->id}", [
            'gl_account_id' => $replacementAccount->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.gl_account_id', $replacementAccount->id);
        $this->assertSame($replacementAccount->id, $repository->refresh()->gl_account_id);
    }

    public function test_can_show_single_repository(): void
    {
        $repository = new PaymentRepository([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);
        $repository->forceFill(['currency' => 'USD'])->save();

        $response = $this->actingAs($this->user)->getJson("/api/v1/payment-repositories/{$repository->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'CASH_REG_01');
        $response->assertJsonPath('data.currency', 'USD');
    }

    public function test_can_get_repository_balance(): void
    {
        // `balance` is port-managed and not fillable (Task 22); the factory is
        // unguarded, so it seeds the opening balance on INSERT (which the
        // direct-balance-write trigger permits — it guards UPDATEs only).
        $repository = PaymentRepository::factory()->create([
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

    /**
     * @return array{PaymentRepository, Account, Account}
     */
    private function repositoryAndGlAccounts(): array
    {
        $originalAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '101',
            'name' => 'Original cash account',
            'is_active' => true,
        ]);
        $replacementAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '102',
            'name' => 'Replacement cash account',
            'is_active' => true,
        ]);
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'GL_REASSIGN',
            'name' => 'GL reassignment fixture',
            'type' => 'cash_register',
            'account_id' => $originalAccount->id,
            'gl_account_id' => $originalAccount->id,
            'is_active' => true,
        ]);

        return [$repository, $originalAccount, $replacementAccount];
    }

    private function insertTransferMovement(
        PaymentRepository $repository,
        int $ordinal,
        ?string $journalEntryId = null,
    ): void {
        $transferGroupId = (string) Str::uuid();

        DB::table('repository_movements')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $repository->id,
            'direction' => 'out',
            'amount' => '1.000',
            'currency' => $repository->currency,
            'balance_after' => '0.000',
            'ordinal' => $ordinal,
            'source_type' => 'transfer',
            'source_id' => $transferGroupId,
            'journal_entry_id' => $journalEntryId,
            'idempotency_key' => "test:gl-reassignment:{$repository->id}:{$ordinal}",
            'transfer_group_id' => $transferGroupId,
            'occurred_at' => now(),
        ]);
    }
}
