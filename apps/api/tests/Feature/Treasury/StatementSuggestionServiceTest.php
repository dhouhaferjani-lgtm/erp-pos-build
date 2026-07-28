<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\StatementSuggestionService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\StatementDirectionConvention;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Enums\StatementMatchType;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StatementSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentRepository $repository;

    private StatementImportProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
        $this->profile = StatementImportProfile::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'name' => 'Suggestion profile',
            'is_active' => true,
            'parser_key' => StatementParserKey::Csv,
            'column_map' => ['value_date' => 'Date', 'amount' => 'Amount', 'label' => 'Label'],
            'date_format' => 'Y-m-d',
            'decimal_format' => 'dot',
            'direction_convention' => StatementDirectionConvention::SignedAmount,
            'header_rows' => 0,
            'matching_window_days' => 5,
        ]);
    }

    public function test_reference_hit_uses_partially_allocated_movement_remaining_capacity_without_writes(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->repository->id,
            'currency' => 'TND',
            'amount' => '100.000',
            'reference' => 'PAY-REF-4242',
        ]);
        $movementId = $this->movement('100.000', MovementDirection::In, '2026-06-01', MovementSourceType::Payment, $payment->id);
        $this->allocate($this->line('40.000', MovementDirection::In, 'Already matched'), $movementId, '40.000');
        $line = $this->line('60.000', MovementDirection::In, 'Transfer PAY-REF-4242 received');
        $allocationCount = BankStatementLineAllocation::query()->count();

        $suggestions = app(StatementSuggestionService::class)->suggest($line->id);

        $reference = collect($suggestions)->first(
            static fn ($suggestion): bool => $suggestion->tier === 1 && $suggestion->movementIds === [$movementId],
        );
        self::assertNotNull($reference);
        self::assertSame('60.000', $reference->amount);
        self::assertSame('reference_amount_match', $reference->reasonCode);
        self::assertSame([], $reference->reasonParams);
        self::assertSame($allocationCount, BankStatementLineAllocation::query()->count());
        self::assertDatabaseCount('bank_statement_match_executions', 0);
    }

    public function test_out_of_window_reference_match_is_found_despite_many_in_window_movements(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->repository->id,
            'currency' => 'TND',
            'amount' => '88.000',
            'reference' => 'OOW-REF-55123',
        ]);
        // Reference movement dated far outside the ±5 day window (value_date 2026-07-18).
        $referenceMovement = $this->movement('88.000', MovementDirection::In, '2026-05-02', MovementSourceType::Payment, $payment->id);
        // Many in-window movements with no reference and a non-matching amount:
        // the reference match must still be found (it is bounded in SQL by the
        // reference predicate, not dropped by a cap over a large hydration).
        $this->bulkNoiseMovements(120, '13.000', '2026-07-18');

        $line = $this->line('88.000', MovementDirection::In, 'Wire OOW-REF-55123 cleared');

        $reference = collect(app(StatementSuggestionService::class)->suggest($line->id))->first(
            static fn ($suggestion): bool => $suggestion->tier === 1 && $suggestion->movementIds === [$referenceMovement],
        );

        self::assertNotNull($reference);
        self::assertSame('reference_amount_match', $reference->reasonCode);
        self::assertSame('88.000', $reference->amount);
    }

    public function test_unique_amount_date_respects_profile_window_and_remaining_capacity(): void
    {
        $line = $this->line('50.000', MovementDirection::In, 'No reference');
        $partial = $this->movement('100.000', MovementDirection::In, '2026-07-22');
        $this->allocate($this->line('50.000', MovementDirection::In, 'Partial consumer'), $partial, '50.000');
        $full = $this->movement('50.000', MovementDirection::In, '2026-07-22');
        $this->allocate($this->line('50.000', MovementDirection::In, 'Full consumer'), $full, '50.000');
        $this->profile->update(['matching_window_days' => 2]);

        self::assertNull(collect(app(StatementSuggestionService::class)->suggest($line->id))
            ->first(static fn ($suggestion): bool => $suggestion->tier === 2));

        $this->profile->update(['matching_window_days' => 5]);
        $amountDate = collect(app(StatementSuggestionService::class)->suggest($line->id))
            ->first(static fn ($suggestion): bool => $suggestion->tier === 2);

        self::assertNotNull($amountDate);
        self::assertSame([$partial], $amountDate->movementIds);
        self::assertSame('50.000', $amountDate->amount);
        self::assertSame('unique_amount_window', $amountDate->reasonCode);
        self::assertSame(['days' => 5], $amountDate->reasonParams);
    }

    public function test_pending_instrument_tier_includes_received_bounced_and_deposited_states(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $received = $this->instrument($method, InstrumentDirection::Outbound, InstrumentStatus::Received, '70.000');
        $bounced = $this->instrument($method, InstrumentDirection::Outbound, InstrumentStatus::Bounced, '70.000');
        $deposited = $this->instrument($method, InstrumentDirection::Inbound, InstrumentStatus::Deposited, '70.000');

        $outSuggestions = collect(app(StatementSuggestionService::class)->suggest(
            $this->line('70.000', MovementDirection::Out, 'Supplier cheque')->id,
        ));
        $outboundIds = $outSuggestions
            ->filter(static fn ($suggestion): bool => $suggestion->tier === 3
                && $suggestion->actionType === MatchActionType::OutboundClear)
            ->pluck('targetId')
            ->all();
        self::assertEqualsCanonicalizing([$received->id, $bounced->id], $outboundIds);

        $inSuggestions = collect(app(StatementSuggestionService::class)->suggest(
            $this->line('70.000', MovementDirection::In, 'Customer cheque cleared')->id,
        ));
        $inbound = $inSuggestions->first(static fn ($suggestion): bool => $suggestion->tier === 3
            && $suggestion->actionType === MatchActionType::InboundClear);
        self::assertNotNull($inbound);
        self::assertSame($deposited->id, $inbound->targetId);
    }

    public function test_pending_expense_suggestion_is_collected_through_the_expense_event_listener(): void
    {
        $expense = Document::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_date' => '2026-06-01',
            'currency' => 'TND',
            'total' => '30.000',
            'balance_due' => '30.000',
        ]);
        ExpenseMetadata::query()->create([
            'document_id' => $expense->id,
            'payment_repository_id' => $this->repository->id,
            'payment_date' => '2026-07-19',
            'is_paid' => false,
            'vendor_name' => 'Tunisie Telecom',
        ]);
        $line = $this->line('30.000', MovementDirection::Out, 'TUNISIE TELECOM invoice');

        $suggestion = collect(app(StatementSuggestionService::class)->suggest($line->id))
            ->first(static fn ($suggestion): bool => $suggestion->tier === 3
                && $suggestion->actionType === MatchActionType::ExpenseSettle);

        self::assertNotNull($suggestion);
        self::assertSame($expense->id, $suggestion->targetId);
        self::assertTrue($suggestion->referenceMatched);
        self::assertSame('unsettled_expense', $suggestion->reasonCode);
        self::assertSame(['label' => 'Tunisie Telecom', 'date' => '2026-07-19'], $suggestion->reasonParams);
    }

    private function line(string $amount, MovementDirection $direction, string $label): BankStatementLine
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
            'source_file_path' => 'bank-statements/suggestion-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => $this->profile->id,
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
            'location_id' => $this->repository->location_id,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }

    private function movement(
        string $amount,
        MovementDirection $direction,
        string $occurredAt,
        MovementSourceType $sourceType = MovementSourceType::Adjustment,
        ?string $sourceId = null,
    ): string {
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'direction' => $direction->value,
            'amount' => $amount,
            'currency' => 'TND',
            'balance_after' => $amount,
            'ordinal' => random_int(1, 1000000),
            'source_type' => $sourceType->value,
            'source_id' => $sourceId ?? Str::uuid()->toString(),
            'idempotency_key' => 'statement-suggestion:'.Str::uuid()->toString(),
            'occurred_at' => $occurredAt,
            'created_by' => $this->user->id,
        ]);

        return $id;
    }

    private function bulkNoiseMovements(int $count, string $amount, string $occurredAt): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'payment_repository_id' => $this->repository->id,
                'direction' => MovementDirection::In->value,
                'amount' => $amount,
                'currency' => 'TND',
                'balance_after' => $amount,
                'ordinal' => random_int(1, 1000000000),
                'source_type' => MovementSourceType::Adjustment->value,
                'source_id' => Str::uuid()->toString(),
                'idempotency_key' => 'statement-suggestion-noise:'.Str::uuid()->toString(),
                'occurred_at' => $occurredAt,
                'created_by' => $this->user->id,
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('repository_movements')->insert($chunk);
        }
    }

    private function allocate(BankStatementLine $line, string $movementId, string $amount): void
    {
        BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => $amount,
            'match_type' => StatementMatchType::Manual,
            'matched_by' => $this->user->id,
            'matched_at' => now(),
        ]);
    }

    private function instrument(
        PaymentMethod $method,
        InstrumentDirection $direction,
        InstrumentStatus $status,
        string $amount,
    ): PaymentInstrument {
        return PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'reference' => 'SUG-'.Str::upper(Str::random(8)),
            'partner_id' => $this->partner->id,
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => '2026-07-15',
            'maturity_date' => '2026-07-18',
            'status' => $status,
            'kind' => InstrumentKind::Cheque,
            'direction' => $direction,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $direction === InstrumentDirection::Outbound ? $this->repository->id : null,
            'deposited_to_id' => $direction === InstrumentDirection::Inbound ? $this->repository->id : null,
            'created_by' => $this->user->id,
        ]);
    }
}
