<?php

declare(strict_types=1);

namespace Tests\Feature\Income;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Domain\IncomeMetadata;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class IncomeStoreTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    /**
     * Storing an income creates a draft Income document with metadata and
     * accepts a 3-decimal money amount.
     */
    public function test_store_creates_draft_income_and_accepts_three_decimal_amount(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.create', 'income.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $incomeAccount = Account::query()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Revenue)
            ->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/income', [
            'total' => '150.750',
            'source_name' => 'Scrap metal sale',
            'income_account_id' => $incomeAccount->id,
            'document_date' => now()->toDateString(),
            'is_received' => true,
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');
        $this->assertNotNull($id);

        $this->assertDatabaseHas('documents', [
            'id' => $id,
            'type' => DocumentType::Income->value,
            'status' => DocumentStatus::Draft->value,
            'total' => '150.750',
        ]);
        $this->assertDatabaseHas('income_metadata', [
            'document_id' => $id,
            'income_account_id' => $incomeAccount->id,
            'source_name' => 'Scrap metal sale',
        ]);
    }

    /**
     * The money regex ceiling rejects amounts with more than 3 decimals.
     */
    public function test_store_rejects_amount_with_four_decimals(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.create']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/income', [
            'total' => '10.1234',
            'document_date' => now()->toDateString(),
        ]);
        $this->assertApiValidationErrors($response, ['total']);
    }

    /**
     * income_account_id must reference a class-7 (Revenue-type) account; an
     * expense-type account is rejected.
     */
    public function test_store_rejects_non_revenue_income_account(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.create']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $expenseAccount = Account::query()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Expense)
            ->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/income', [
            'total' => '50.000',
            'income_account_id' => $expenseAccount->id,
            'document_date' => now()->toDateString(),
        ]);
        $this->assertApiValidationErrors($response, ['income_account_id']);
    }

    /**
     * Creating an income with the same idempotency_key twice returns the same
     * document (no duplicate).
     */
    public function test_store_is_idempotent(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.create', 'income.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $key = (string) Str::uuid();
        $payload = [
            'total' => '25.000',
            'document_date' => now()->toDateString(),
            'idempotency_key' => $key,
        ];

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/income', $payload);
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/income', $payload);
        $second->assertStatus(201);

        $this->assertSame($firstId, $second->json('data.id'));
        $this->assertSame(1, IncomeMetadata::query()->where('idempotency_key', $key)->count());
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
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
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
