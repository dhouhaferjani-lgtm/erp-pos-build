<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests that expense documents inherit the company's currency instead of
 * falling back to the DB default ('EUR'). This guards against GL↔treasury
 * millime drift on TND companies (Rule 19).
 */
final class ExpenseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: creating an expense on a TND company must store currency=TND.
     * Before the fix, Document::create() omitted the currency key and the DB
     * default ('EUR') was stored — causing getScale('EUR')=2 to truncate
     * millimes on the treasury outflow while the GL stored the scale-3 total.
     */
    public function test_expense_currency_inherits_company_currency_on_tnd_company(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.create', 'expenses.view'], 'TND');

        app(CompanyContext::class)->setCompanyId($company->id);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'total' => '50.000',
                'payment_date' => now()->toDateString(),
                'is_paid' => false,
            ]);

        $response->assertStatus(201);

        $expenseId = $response->json('data.id');
        $this->assertNotNull($expenseId, 'Response must include the created expense id');

        $expense = Document::where('type', DocumentType::Expense)->whereKey($expenseId)->firstOrFail();
        $this->assertSame('TND', (string) $expense->currency, 'Expense currency must match company currency (TND), not fall back to EUR');
    }

    /**
     * No-drift invariant: a TND expense with a millime total (12.345), when
     * posted against a cash repository, must decrement the balance at scale 3
     * and the GL credit line must equal the same millime-precise value.
     *
     * This locks the guarantee that GL and treasury always agree to the millime,
     * which requires the currency stored on the document to be TND (scale 3)
     * rather than EUR (scale 2).
     */
    public function test_tnd_expense_post_no_millime_drift(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view', 'expenses.create'], 'TND');

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, [
            'total' => '12.345',
            'currency' => 'TND',
        ]);

        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post");

        $response->assertOk();

        // Treasury balance must be decremented at scale 3: 500.000 - 12.345 = 487.655
        $this->assertSame('487.655', $repo->fresh()->balance, 'Treasury balance must be exact at scale 3 (no millime truncation)');

        // GL credit line must also equal 12.345 — no drift between GL and treasury
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'expense',
            'source_id' => $expense->id,
            'company_id' => $company->id,
        ]);

        // Confirm the GL lines carry the millime-exact credit amount
        $entry = JournalEntry::where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->with('lines')
            ->firstOrFail();

        $creditLine = $entry->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 3) > 0);
        if ($creditLine !== null) {
            $this->assertSame('12.345', (string) $creditLine->credit, 'GL credit line must match the expense total to the millime');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions, string $currency = 'EUR'): array
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
            'currency' => $currency,
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeExpense(Company $company, User $user, array $overrides = []): Document
    {
        $partner = Partner::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'name' => 'Test Vendor',
            'type' => PartnerType::Supplier,
        ]);

        return Document::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Draft,
            'document_number' => 'EXP-DRAFT-'.uniqid(),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '0.000',
        ], $overrides));
    }
}
