<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\StatementSuggestionService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Enums\StatementMatchType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CardBatchSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentRepository $repository;

    private Account $feeAccount;

    private int $ordinal = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => 'Africa/Tunis',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->feeAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '627200',
            'name' => 'Acquirer fees',
            'type' => AccountType::Expense,
            'is_active' => true,
        ]);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
    }

    public function test_groups_by_payment_method_and_fiscal_business_day_across_midnight(): void
    {
        $twoPercent = $this->method('CARD-A', '2.00');
        $threePercent = $this->method('CARD-B', '3.00');
        $event = $this->event('2026-07-18', '2026-07-19 00:30:00+01:00');
        $movementA = $this->sale($event, $twoPercent, '100.000', 0, '2026-07-19 00:30:00');
        $movementB = $this->sale($event, $threePercent, '50.000', 1, '2026-07-19 00:30:00');

        $suggestions = collect(app(StatementSuggestionService::class)->suggest($this->line('98.000')->id))
            ->filter(static fn ($suggestion): bool => $suggestion->tier === 4)->values();

        self::assertCount(1, $suggestions);
        $suggestion = $suggestions->firstOrFail();
        self::assertSame(MatchActionType::AcquirerFee, $suggestion->actionType);
        self::assertSame([$movementA], $suggestion->movementIds);
        self::assertNotContains($movementB, $suggestion->movementIds);
        self::assertSame($twoPercent->id, $suggestion->actionParams['payment_method_id']);
        self::assertSame('2026-07-18', $suggestion->actionParams['business_date']);
        self::assertSame('100.000', $suggestion->actionParams['gross_amount']);
        self::assertSame('2.000', $suggestion->actionParams['fee_amount']);
        self::assertSame('card_batch_fee', $suggestion->reasonCode);
        self::assertSame(['method' => 'CARD-A', 'date' => '2026-07-18'], $suggestion->reasonParams);
    }

    public function test_refund_is_a_negative_member_of_its_original_sale_business_day(): void
    {
        $method = $this->method('CARD-REFUND', '2.00');
        $event = $this->event('2026-07-18', '2026-07-18 14:00:00+01:00');
        [$saleMovement, $payment] = $this->saleWithPayment($event, $method, '100.000', 0, '2026-07-18 14:00:00');
        $refundMovement = $this->refund($payment, '20.000', '2026-07-19 10:00:00');

        $suggestion = collect(app(StatementSuggestionService::class)->suggest($this->line('78.400')->id))
            ->first(static fn ($candidate): bool => $candidate->tier === 4);

        self::assertNotNull($suggestion);
        self::assertEqualsCanonicalizing([$saleMovement, $refundMovement], $suggestion->movementIds);
        self::assertSame('80.000', $suggestion->actionParams['gross_amount']);
        self::assertSame('1.600', $suggestion->actionParams['fee_amount']);
    }

    public function test_multi_day_partial_and_out_of_bounds_batches_fall_back_to_manual(): void
    {
        $method = $this->method('CARD-MANUAL', '2.00');
        $first = $this->sale($this->event('2026-07-18', '2026-07-18 10:00:00+01:00'), $method, '100.000', 0, '2026-07-18 10:00:00');
        $this->sale($this->event('2026-07-19', '2026-07-19 10:00:00+01:00'), $method, '100.000', 0, '2026-07-19 10:00:00');

        self::assertNull(collect(app(StatementSuggestionService::class)->suggest($this->line('196.000')->id))
            ->first(static fn ($candidate): bool => $candidate->tier === 4));

        $partialConsumer = $this->line('1.000');
        BankStatementLineAllocation::query()->create([
            'bank_statement_line_id' => $partialConsumer->id,
            'repository_movement_id' => $first,
            'matched_amount' => '1.000',
            'match_type' => StatementMatchType::Manual,
            'matched_by' => $this->user->id,
            'matched_at' => now(),
        ]);
        self::assertNull(collect(app(StatementSuggestionService::class)->suggest($this->line('98.000')->id))
            ->first(static fn ($candidate): bool => $candidate->tier === 4
                && $candidate->actionParams['business_date'] === '2026-07-18'));
        self::assertNull(collect(app(StatementSuggestionService::class)->suggest($this->line('97.000')->id))
            ->first(static fn ($candidate): bool => $candidate->tier === 4));
    }

    private function method(string $code, string $percent): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'has_deducted_fees' => true,
            'fee_type' => 'percentage',
            'fee_percent' => $percent,
            'fee_account_id' => $this->feeAccount->id,
            'default_repository_id' => $this->repository->id,
        ]);
    }

    private function event(string $businessDate, string $deviceTime): FiscalEvent
    {
        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => Str::uuid()->toString(),
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'signature_version' => 'none-v1',
            'sequence_number' => 1,
            'event_time_device' => $deviceTime,
            'business_date' => $businessDate,
            'server_received_at' => $deviceTime,
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function sale(FiscalEvent $event, PaymentMethod $method, string $amount, int $index, string $occurredAt): string
    {
        return $this->saleWithPayment($event, $method, $amount, $index, $occurredAt)[0];
    }

    /** @return array{string, Payment} */
    private function saleWithPayment(
        FiscalEvent $event,
        PaymentMethod $method,
        string $amount,
        int $index,
        string $occurredAt,
    ): array {
        $legKey = "fiscal_event:{$event->id}:payment:{$index}";
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => $event->business_date,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'origin' => PaymentOrigin::Pos,
            'fiscal_event_id' => $event->id,
            'idempotency_key' => $legKey,
        ]);

        return [$this->movement(MovementDirection::In, $amount, MovementSourceType::FiscalEvent, $event->id, $legKey, $occurredAt), $payment];
    }

    private function refund(Payment $original, string $amount, string $occurredAt): string
    {
        return $this->movement(
            MovementDirection::Out,
            $amount,
            MovementSourceType::Refund,
            $original->id,
            "refund:{$original->id}:".Str::uuid()->toString(),
            $occurredAt,
        );
    }

    private function movement(
        MovementDirection $direction,
        string $amount,
        MovementSourceType $sourceType,
        string $sourceId,
        string $idempotencyKey,
        string $occurredAt,
    ): string {
        $id = Str::uuid()->toString();
        $this->ordinal++;
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'direction' => $direction->value,
            'amount' => $amount,
            'currency' => 'TND',
            'balance_after' => '0.000',
            'ordinal' => $this->ordinal,
            'source_type' => $sourceType->value,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $occurredAt,
            'created_by' => $this->user->id,
        ]);
        DB::table('payment_repositories')->where('id', $this->repository->id)->update([
            'next_movement_ordinal' => $this->ordinal,
        ]);

        return $id;
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
            'source_file_path' => 'bank-statements/card-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->user->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-20',
            'direction' => MovementDirection::In,
            'amount' => $amount,
            'label' => 'Card acquirer settlement',
            'match_status' => StatementLineMatchStatus::Unmatched,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }
}
