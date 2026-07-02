<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\DemoPharmacySeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class DemoPharmacySeederExpensesTest extends TestCase
{
    use RefreshDatabase;

    private const MONEY_SCALE = 3;

    public function test_seed_tunisia_expenses_posts_paid_expenses_and_decrements_the_till_balance(): void
    {
        [$tenant, $company, $repository, $bankRepository] = $this->seedExpenseFixture();
        $openingBalance = (string) $repository->balance;
        $bankOpeningBalance = (string) $bankRepository->balance;

        $this->invokeSeedTunisiaExpenses($company);

        $expenses = Document::query()
            ->where('company_id', $company->id)
            ->where('type', DocumentType::Expense)
            ->whereHas('expenseMetadata', fn ($query) => $query->whereRaw('idempotency_key like ?', ['DEMO-EXP-%']))
            ->with('expenseMetadata')
            ->get();

        $this->assertCount(10, $expenses);
        $this->assertSame(10, $expenses->where('status', DocumentStatus::Posted)->count());

        $totalSeededExpenses = '0.000';
        $totalBankExpenses = '0.000';
        foreach ($expenses as $expense) {
            $metadata = $expense->expenseMetadata;
            if ($metadata === null) {
                $this->fail('Seeded expense is missing expense metadata.');
            }

            $this->assertTrue($metadata->is_paid);
            $this->assertContains($metadata->payment_repository_id, [$repository->id, $bankRepository->id]);
            if ($metadata->payment_repository_id === $repository->id) {
                $totalSeededExpenses = bcadd($totalSeededExpenses, $expense->total ?? '0.000', self::MONEY_SCALE);
            } else {
                $totalBankExpenses = bcadd($totalBankExpenses, $expense->total ?? '0.000', self::MONEY_SCALE);
            }

            $entry = JournalEntry::query()
                ->where('tenant_id', $tenant->id)
                ->where('company_id', $company->id)
                ->where('source_type', 'expense')
                ->where('source_id', $expense->id)
                ->with('lines')
                ->first();

            $this->assertNotNull($entry);
            $this->assertSame(JournalEntryStatus::Posted, $entry->status);

            $debits = '0.000';
            $credits = '0.000';
            foreach ($entry->lines as $line) {
                $debits = bcadd($debits, $line->debit, self::MONEY_SCALE);
                $credits = bcadd($credits, $line->credit, self::MONEY_SCALE);
            }
            $this->assertSame($debits, $credits);
        }

        $repository->refresh();
        $bankRepository->refresh();
        $this->assertSame(bcsub($openingBalance, $totalSeededExpenses, self::MONEY_SCALE), (string) $repository->balance);
        $this->assertSame(bcsub($bankOpeningBalance, $totalBankExpenses, self::MONEY_SCALE), (string) $bankRepository->balance);
        $this->assertGreaterThanOrEqual(0, bccomp((string) $repository->balance, '0.000', self::MONEY_SCALE), 'Seeded expenses must not overdraw the till');
        $this->assertGreaterThanOrEqual(0, bccomp((string) $bankRepository->balance, '0.000', self::MONEY_SCALE), 'Seeded expenses must not overdraw the bank account');
    }

    /**
     * @return array{Tenant, Company, PaymentRepository, PaymentRepository}
     */
    private function seedExpenseFixture(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'demo-pharmacy-tn',
        ]);
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'owner@pharmabio.tn',
        ]);

        $cashAccount = $this->createAccount($tenant, $company, '54', AccountType::Asset, SystemAccountPurpose::Cash);
        $bankAccount = $this->createAccount($tenant, $company, '532', AccountType::Asset, SystemAccountPurpose::Bank);
        $this->createAccount($tenant, $company, '65', AccountType::Expense, SystemAccountPurpose::GeneralExpense);
        foreach (['613', '615', '616', '624', '626'] as $code) {
            $this->createAccount($tenant, $company, $code, AccountType::Expense, null);
        }

        (new ExpenseCategorySeeder)->seedForCompany($company);
        $this->assertCount(6, ExpenseCategory::query()->where('company_id', $company->id)->get());

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        // Mirror the REAL PaymentRepositorySeeder opening balances so this test
        // catches a till overdraft (CASH-01 opens at 500.000, not a cushy 5000).
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'balance' => '500.000',
        ]);

        $bankRepository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'BANK-01',
            'name' => 'Main Bank Account',
            'type' => RepositoryType::BankAccount,
            'account_id' => $bankAccount->id,
            'gl_account_id' => $bankAccount->id,
            'balance' => '25000.000',
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($company->id);

        return [$tenant, $company, $repository, $bankRepository];
    }

    private function createAccount(
        Tenant $tenant,
        Company $company,
        string $code,
        AccountType $type,
        ?SystemAccountPurpose $purpose,
    ): Account {
        return Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => $code,
            'type' => $type,
            'system_purpose' => $purpose,
        ]);
    }

    private function invokeSeedTunisiaExpenses(Company $company): void
    {
        /** @var DemoPharmacySeeder $seeder */
        $seeder = $this->app->make(DemoPharmacySeeder::class);
        $command = new class extends Command
        {
            protected $signature = 'test:demo-pharmacy-seeder-expenses';
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $seeder->setContainer($this->app);
        $seeder->setCommand($command);

        $method = new ReflectionMethod($seeder, 'seedTunisiaExpenses');
        $method->setAccessible(true);
        $method->invoke($seeder, $company);
    }
}
