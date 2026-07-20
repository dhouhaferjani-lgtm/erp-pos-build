<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\StatementMatchingService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcquirerFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentRepository $repository;

    private PaymentMethod $method;

    private BankStatementLine $line;

    private string $grossMovementId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $feeAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '627100',
            'name' => 'Card acquiring fees',
            'type' => AccountType::Expense,
            'is_active' => true,
        ]);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'gl_account_id' => $bankAccount->id,
            'currency' => 'TND',
            'balance' => '100.000',
        ]);
        $this->method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD-T7',
            'has_deducted_fees' => true,
            'fee_type' => 'percentage',
            'fee_percent' => '2.00',
            'fee_account_id' => $feeAccount->id,
            'default_repository_id' => $this->repository->id,
        ]);
        $this->line = $this->line('98.000');
        $this->grossMovementId = $this->movement('100.000');
    }

    public function test_acquirer_fee_posts_exact_accounts_and_closes_signed_group(): void
    {
        self::assertSame('0.000', config('treasury.acquirer_fee_vat_rate'));

        app(StatementMatchingService::class)->executeAndAllocate(
            $this->line->id,
            MatchActionType::AcquirerFee,
            $this->params('2.000'),
            $this->user->id,
        );

        $execution = $this->line->executions()->firstOrFail();
        self::assertSame([$this->grossMovementId, $execution->produced_repository_movement_ids[1]], $execution->produced_repository_movement_ids);
        $feeMovement = RepositoryMovement::query()->findOrFail($execution->produced_repository_movement_ids[1]);
        self::assertSame(MovementDirection::Out, $feeMovement->direction);
        self::assertSame(MovementSourceType::Adjustment, $feeMovement->source_type);
        self::assertSame($this->line->id, $feeMovement->source_id);
        self::assertSame('2.000', $feeMovement->amount);
        self::assertSame('2026-07-18', $feeMovement->occurred_at->toDateString());
        self::assertSame("adjustment:{$this->line->id}:acquirer_fee", $feeMovement->idempotency_key);

        $entry = JournalEntry::query()->with('lines')->findOrFail($feeMovement->journal_entry_id);
        self::assertSame(JournalEntryStatus::Posted, $entry->status);
        self::assertSame('acquirer_fee', $entry->source_type);
        self::assertSame(JournalCode::Bank, $entry->journal_code);
        self::assertSame('2026-07-18', $entry->entry_date->toDateString());
        self::assertCount(2, $entry->lines);
        self::assertSame('2.000', $entry->lines->firstWhere('account_id', $this->method->fee_account_id)?->debit);
        self::assertSame('2.000', $entry->lines->firstWhere('account_id', $this->repository->gl_account_id)?->credit);
        self::assertSame(StatementLineMatchStatus::Matched, $this->line->fresh()?->match_status);
        self::assertSame('98.000', $this->signedAllocatedTotal());
    }

    public function test_unmatch_and_reconfirm_reuses_one_fee_execution_movement_and_journal(): void
    {
        $matching = app(StatementMatchingService::class);
        $matching->executeAndAllocate($this->line->id, MatchActionType::AcquirerFee, $this->params('2.000'), $this->user->id);
        $execution = $this->line->executions()->firstOrFail();
        $feeMovementId = $execution->produced_repository_movement_ids[1];
        $feeJournalId = RepositoryMovement::query()->findOrFail($feeMovementId)->journal_entry_id;

        $matching->unallocate($this->line->id, null, $this->user->id);
        $matching->executeAndAllocate($this->line->id, MatchActionType::AcquirerFee, $this->params('2.000'), $this->user->id);

        self::assertDatabaseCount('bank_statement_match_executions', 1);
        self::assertSame(1, RepositoryMovement::query()->where('idempotency_key', "adjustment:{$this->line->id}:acquirer_fee")->count());
        self::assertSame(1, JournalEntry::query()->whereKey($feeJournalId)->count());
        self::assertSame(2, $this->line->allocations()->count());
        self::assertSame('98.000', $this->signedAllocatedTotal());
    }

    public function test_implausible_fee_is_rejected_before_financial_writes(): void
    {
        $this->method->update(['fee_percent' => '1.00']);

        try {
            app(StatementMatchingService::class)->executeAndAllocate(
                $this->line->id,
                MatchActionType::AcquirerFee,
                $this->params('2.000'),
                $this->user->id,
            );
            $this->fail('An acquirer fee outside the payment-method calculation must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('plausible', strtolower($exception->getMessage()));
        }

        self::assertDatabaseCount('bank_statement_match_executions', 0);
        self::assertSame(1, RepositoryMovement::query()->count());
        self::assertDatabaseMissing('journal_entries', ['source_type' => 'acquirer_fee']);
    }

    public function test_non_exempt_acquirer_fee_configuration_fails_closed_until_vat_split_is_supported(): void
    {
        config(['treasury.acquirer_fee_vat_rate' => '19.000']);

        try {
            app(StatementMatchingService::class)->executeAndAllocate(
                $this->line->id,
                MatchActionType::AcquirerFee,
                $this->params('2.000'),
                $this->user->id,
            );
            $this->fail('A non-zero acquirer-fee VAT rate must not silently post as VAT-exempt.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('vat-exempt', strtolower($exception->getMessage()));
        }

        self::assertDatabaseCount('bank_statement_match_executions', 0);
        self::assertSame(1, RepositoryMovement::query()->count());
        self::assertDatabaseMissing('journal_entries', ['source_type' => 'acquirer_fee']);
    }

    /** @return array<string, mixed> */
    private function params(string $fee): array
    {
        return [
            'payment_method_id' => $this->method->id,
            'gross_movement_ids' => [$this->grossMovementId],
            'gross_amount' => '100.000',
            'fee_amount' => $fee,
            'business_date' => '2026-07-18',
        ];
    }

    private function line(string $amount): BankStatementLine
    {
        $statement = BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => $amount,
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/acquirer-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->user->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-18',
            'direction' => MovementDirection::In,
            'amount' => $amount,
            'label' => 'Card batch net settlement',
            'match_status' => StatementLineMatchStatus::Unmatched,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }

    private function movement(string $amount): string
    {
        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => Str::uuid()->toString(),
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'signature_version' => 'none-v1',
            'sequence_number' => 1,
            'event_time_device' => '2026-07-18 12:00:00+01:00',
            'business_date' => '2026-07-18',
            'server_received_at' => '2026-07-18 12:00:00+01:00',
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', Str::uuid()->toString()),
        ]);
        $legKey = "fiscal_event:{$event->id}:payment:0";
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $this->method->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => '2026-07-18',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'origin' => PaymentOrigin::Pos,
            'fiscal_event_id' => $event->id,
            'idempotency_key' => $legKey,
        ]);
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'direction' => MovementDirection::In->value,
            'amount' => $amount,
            'currency' => 'TND',
            'balance_after' => $amount,
            'ordinal' => 1,
            'source_type' => MovementSourceType::FiscalEvent->value,
            'source_id' => $event->id,
            'idempotency_key' => $legKey,
            'occurred_at' => '2026-07-18 12:00:00',
            'created_by' => $this->user->id,
        ]);
        DB::table('payment_repositories')->where('id', $this->repository->id)->update([
            'next_movement_ordinal' => 1,
        ]);

        return $id;
    }

    private function signedAllocatedTotal(): string
    {
        return $this->line->allocations()->with('movement')->get()->reduce(
            fn (string $total, $allocation): string => $allocation->movement->direction === $this->line->direction
                ? bcadd($total, $allocation->matched_amount, 3)
                : bcsub($total, $allocation->matched_amount, 3),
            '0.000',
        );
    }
}
