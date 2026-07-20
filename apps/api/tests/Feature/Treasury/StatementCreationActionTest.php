<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\StatementMatchingService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StatementCreationActionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'location_id' => $this->location->id,
            'balance' => '0.000',
        ]);
    }

    public function test_create_expense_uses_line_value_date_location_and_event_owned_expense_flow(): void
    {
        $line = $this->line(MovementDirection::Out, '12.500', 'Bank agio');

        app(StatementMatchingService::class)->executeAndAllocate(
            $line->id,
            MatchActionType::CreateExpense,
            ['vendor_name' => 'Banque de Tunisie', 'notes' => 'Agio juillet'],
            $this->user->id,
        );

        $execution = $line->executions()->firstOrFail();
        $expense = Document::query()->with('expenseMetadata')->findOrFail($execution->target_id);
        self::assertSame(DocumentType::Expense, $expense->type);
        self::assertSame($this->location->id, $expense->location_id);
        self::assertSame('2026-07-18', $expense->document_date->toDateString());
        $expenseMetadata = $expense->expenseMetadata()->firstOrFail();
        self::assertSame('2026-07-18', $expenseMetadata->payment_date?->toDateString());
        self::assertSame('Banque de Tunisie', $expenseMetadata->vendor_name);
        $movement = RepositoryMovement::query()
            ->where('source_type', MovementSourceType::Expense)
            ->where('source_id', $expense->id)
            ->firstOrFail();
        self::assertSame('2026-07-18', $movement->occurred_at->toDateString());
        self::assertSame(MovementDirection::Out, $movement->direction);
        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail();
        self::assertSame('2026-07-18', $entry->entry_date->toDateString());
        self::assertSame(StatementLineMatchStatus::ResolvedByCreation, $line->fresh()?->match_status);
    }

    public function test_create_income_requires_and_uses_explicit_income_account_override(): void
    {
        $incomeAccount = Account::factory()->revenue()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '768100',
            'name' => 'Bank interest income',
            'type' => AccountType::Revenue,
        ]);
        $line = $this->line(MovementDirection::In, '5.250', 'Bank interest');

        app(StatementMatchingService::class)->executeAndAllocate(
            $line->id,
            MatchActionType::CreateIncome,
            ['income_account_id' => $incomeAccount->id, 'source_name' => 'Interest'],
            $this->user->id,
        );

        $execution = $line->executions()->firstOrFail();
        $income = Document::query()->with('incomeMetadata')->findOrFail($execution->target_id);
        self::assertSame(DocumentType::Income, $income->type);
        self::assertSame($this->location->id, $income->location_id);
        self::assertSame('2026-07-18', $income->document_date->toDateString());
        self::assertSame($incomeAccount->id, $income->incomeMetadata?->income_account_id);
        $movement = RepositoryMovement::query()
            ->where('source_type', MovementSourceType::Income)
            ->where('source_id', $income->id)
            ->firstOrFail();
        self::assertSame('2026-07-18', $movement->occurred_at->toDateString());
        self::assertSame(MovementDirection::In, $movement->direction);
        $entry = JournalEntry::query()->with('lines')->where('source_type', 'income')->where('source_id', $income->id)->firstOrFail();
        self::assertSame('2026-07-18', $entry->entry_date->toDateString());
        self::assertSame('5.250', $entry->lines->firstWhere('account_id', $incomeAccount->id)?->credit);
        self::assertSame(StatementLineMatchStatus::ResolvedByCreation, $line->fresh()?->match_status);
    }

    public function test_create_income_rejects_missing_account_before_any_financial_write(): void
    {
        $line = $this->line(MovementDirection::In, '1.000', 'Interest without account');

        try {
            app(StatementMatchingService::class)->executeAndAllocate(
                $line->id,
                MatchActionType::CreateIncome,
                [],
                $this->user->id,
            );
            $this->fail('Create-income must require an explicit revenue account.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('income_account_id', $exception->getMessage());
        }
        self::assertDatabaseCount('bank_statement_match_executions', 0);
        self::assertDatabaseCount('repository_movements', 0);
    }

    private function line(MovementDirection $direction, string $amount, string $label): BankStatementLine
    {
        $statement = BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/create-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->user->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-18',
            'direction' => $direction,
            'amount' => $amount,
            'label' => $label,
            'match_status' => StatementLineMatchStatus::Unmatched,
            'location_id' => $this->location->id,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }
}
