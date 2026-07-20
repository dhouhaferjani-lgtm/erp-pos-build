<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\OutboundInstrumentService;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Exceptions\InstrumentActionConflictException;
use App\Modules\Treasury\Domain\Exceptions\InvalidInstrumentTransitionException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryCheckpointException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OutboundInstrumentServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: Tenant, company: Company, user: User, partner: Partner, bank: PaymentRepository, method: PaymentMethod} */
    private array $context;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'gl_account_id' => Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'OUT-CHEQUE-'.Str::upper(Str::random(6)),
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $this->context = compact('tenant', 'company', 'user', 'partner', 'bank', 'method');
    }

    public function test_clear_posts_payable_to_exact_bank_records_out_movement_and_dispatches_event(): void
    {
        Event::fake([InstrumentCleared::class]);
        $instrument = $this->instrument();

        $result = $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        self::assertSame(InstrumentStatus::Received->value, $result->fromStatus);
        self::assertSame(InstrumentStatus::Cleared->value, $result->toStatus);
        self::assertFalse($result->replayed);
        self::assertSame(InstrumentStatus::Cleared, $instrument->fresh()?->status);
        self::assertNotNull($result->journalEntryId);
        self::assertNotNull($result->movementId);

        $movement = RepositoryMovement::query()->findOrFail($result->movementId);
        self::assertSame(MovementDirection::Out, $movement->direction);
        self::assertSame('125.000', $movement->amount);
        self::assertSame("instrument:{$instrument->id}:clear:1", $movement->idempotency_key);

        $entry = JournalEntry::query()->with('lines')->findOrFail($result->journalEntryId);
        self::assertSame('instrument', $entry->source_type);
        self::assertSame($instrument->id, $entry->source_id);
        self::assertSame(JournalCode::Effets, $entry->journal_code);
        $payableAccountId = app(InstrumentAccountResolver::class)->resolveOrFail(
            InstrumentAccountPurpose::ChecksToPay,
            $this->context['company']->id,
        );
        self::assertSame('125.000', $entry->lines->firstWhere('account_id', $payableAccountId)?->debit);
        self::assertSame('125.000', $entry->lines->firstWhere('account_id', $this->context['bank']->gl_account_id)?->credit);
        Event::assertDispatched(InstrumentCleared::class, fn (InstrumentCleared $event): bool => $event->instrumentId === $instrument->id);
    }

    public function test_exact_replay_returns_original_artifacts_before_transition_validation(): void
    {
        $instrument = $this->instrument();
        $first = $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        $second = $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        self::assertTrue($second->replayed);
        self::assertSame($first->journalEntryId, $second->journalEntryId);
        self::assertSame($first->movementId, $second->movementId);
        self::assertSame(1, InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:clear:1")->count());
        self::assertSame(1, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        self::assertSame(1, JournalEntry::query()->where('source_type', 'instrument')->where('source_id', $instrument->id)->count());
    }

    public function test_value_dated_clear_and_bounce_reject_writes_inside_a_reconciled_period(): void
    {
        $instrument = $this->instrument();
        $this->context['bank']->forceFill([
            'last_reconciled_at' => '2026-07-31 23:59:59',
            'last_reconciled_balance' => '0.000',
        ])->save();

        try {
            $this->service()->clear(
                $instrument->id,
                $this->context['tenant']->id,
                $this->context['company']->id,
                $this->context['user']->id,
                '2026-07-18',
            );
            $this->fail('An outbound clear cannot be backdated into a reconciled period.');
        } catch (RepositoryCheckpointException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());

        $this->context['bank']->forceFill([
            'last_reconciled_at' => null,
            'last_reconciled_balance' => null,
        ])->save();
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );
        $this->context['bank']->forceFill([
            'last_reconciled_at' => now()->endOfDay(),
            'last_reconciled_balance' => $this->context['bank']->fresh()?->balance,
        ])->save();

        try {
            $this->service()->bounce(
                $instrument->id,
                $this->context['tenant']->id,
                $this->context['company']->id,
                $this->context['user']->id,
                'Dishonored after checkpoint',
            );
            $this->fail('An outbound bounce cannot write inside a reconciled period.');
        } catch (RepositoryCheckpointException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(InstrumentStatus::Cleared, $instrument->fresh()->status);
        self::assertSame(1, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
    }

    public function test_issue_dishonor_and_cancellation_builders_keep_partner_tags_only_on_401(): void
    {
        $instrument = $this->instrument();
        $resolver = app(InstrumentAccountResolver::class);
        $payableId = $resolver->resolveOrFail(
            InstrumentAccountPurpose::ChecksToPay,
            $this->context['company']->id,
        );
        $supplierPayableId = Account::findByPurposeOrFail(
            $this->context['company']->id,
            SystemAccountPurpose::SupplierPayable,
        )->id;
        $ledger = app(GeneralLedgerService::class);

        [$issue, $dishonor, $cancellation] = DB::transaction(function () use (
            $instrument,
            $payableId,
            $ledger,
        ): array {
            $common = [
                'companyId' => $this->context['company']->id,
                'tenantId' => $this->context['tenant']->id,
                'instrumentId' => $instrument->id,
                'amount' => '125.000',
                'date' => now(),
            ];

            return [
                $ledger->createOutboundInstrumentIssueEntry(
                    ...$common,
                    partnerId: $this->context['partner']->id,
                    payableAccountId: $payableId,
                ),
                $ledger->createOutboundInstrumentDishonorEntry(
                    ...$common,
                    bankAccountId: (string) $this->context['bank']->gl_account_id,
                    payableAccountId: $payableId,
                ),
                $ledger->createOutboundInstrumentCancellationEntry(
                    ...$common,
                    partnerId: $this->context['partner']->id,
                    payableAccountId: $payableId,
                ),
            ];
        });

        $issueSupplierLine = $issue->lines->firstWhere('account_id', $supplierPayableId);
        self::assertNotNull($issueSupplierLine);
        self::assertSame('125.000', $issueSupplierLine->debit);
        self::assertSame($this->context['partner']->id, $issueSupplierLine->partner_id);
        self::assertNull($issue->lines->firstWhere('account_id', $payableId)?->partner_id);
        self::assertSame('125.000', $dishonor->lines->firstWhere('account_id', $this->context['bank']->gl_account_id)?->debit);
        self::assertNull($dishonor->lines->firstWhere('account_id', $payableId)?->partner_id);
        $cancellationSupplierLine = $cancellation->lines->firstWhere('account_id', $supplierPayableId);
        self::assertNotNull($cancellationSupplierLine);
        self::assertSame('125.000', $cancellationSupplierLine->credit);
        self::assertSame($this->context['partner']->id, $cancellationSupplierLine->partner_id);
    }

    public function test_replay_with_different_semantics_throws_conflict_before_transition_validation(): void
    {
        $instrument = $this->instrument();
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        $this->expectException(InstrumentActionConflictException::class);
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-19',
        );
    }

    public function test_inbound_instrument_is_rejected_by_the_dedicated_outbound_service(): void
    {
        $instrument = $this->instrument(InstrumentDirection::Inbound);

        $this->expectException(DomainException::class);
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
        );
    }

    public function test_gl_failure_rolls_back_status_journal_movement_and_event(): void
    {
        $instrument = $this->instrument();
        FiscalPeriod::query()->where('company_id', $this->context['company']->id)->delete();
        FiscalYear::query()->where('company_id', $this->context['company']->id)->delete();
        $year = FiscalYear::query()->create([
            'company_id' => $this->context['company']->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->context['company']->id,
            'name' => 'July 2025',
            'period_number' => 7,
            'start_date' => '2025-07-01',
            'end_date' => '2025-07-31',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $this->context['user']->id,
        ]);
        $journalCount = JournalEntry::query()->count();

        try {
            $this->service()->clear(
                $instrument->id,
                $this->context['tenant']->id,
                $this->context['company']->id,
                $this->context['user']->id,
                '2025-07-18',
            );
            $this->fail('A closed fiscal period must reject the outbound clearing GL post.');
        } catch (ClosedFiscalPeriodException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);
        self::assertSame($journalCount, JournalEntry::query()->count());
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        self::assertSame(0, InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:clear:1")->count());
    }

    public function test_bounce_posts_dishonor_and_compensating_in_movement_without_touching_401(): void
    {
        $instrument = $this->instrument();
        $clear = $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        $bounce = $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'Insufficient funds',
        );

        self::assertSame(InstrumentStatus::Bounced->value, $bounce->toStatus);
        self::assertSame(InstrumentStatus::Bounced, $instrument->fresh()?->status);
        $movement = RepositoryMovement::query()->findOrFail($bounce->movementId);
        self::assertSame(MovementDirection::In, $movement->direction);
        self::assertSame($clear->movementId, $movement->reverses_movement_id);
        self::assertSame("instrument:{$instrument->id}:bounce:1", $movement->idempotency_key);
        $entry = JournalEntry::query()->with('lines')->findOrFail($bounce->journalEntryId);
        self::assertSame('125.000', $entry->lines->firstWhere('account_id', $this->context['bank']->gl_account_id)?->debit);
        $supplierPayableId = Account::findByPurposeOrFail(
            $this->context['company']->id,
            SystemAccountPurpose::SupplierPayable,
        )->id;
        self::assertSame(0, JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $supplierPayableId)->count());
    }

    public function test_represent_increments_cycle_and_clears_on_new_keys_without_mutating_cycle_one(): void
    {
        $instrument = $this->instrument();
        $clearOne = $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );
        $bounceOne = $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'First dishonor',
        );

        $clearTwo = $this->service()->represent(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
        );

        self::assertSame(2, $instrument->fresh()?->presentation_cycle);
        self::assertSame(InstrumentStatus::Cleared->value, $clearTwo->toStatus);
        self::assertNotSame($clearOne->journalEntryId, $clearTwo->journalEntryId);
        self::assertNotSame($clearOne->movementId, $clearTwo->movementId);
        self::assertNotNull(InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:clear:1")->first());
        self::assertNotNull(InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:bounce:1")->first());
        self::assertNotNull(InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:clear:2")->first());
        self::assertNotNull(RepositoryMovement::query()->find($clearOne->movementId));
        self::assertNotNull(RepositoryMovement::query()->find($bounceOne->movementId));

        $bounceTwo = $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'Second dishonor',
        );
        self::assertNotNull(InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:bounce:2")->first());
        self::assertSame($clearTwo->movementId, RepositoryMovement::query()->findOrFail($bounceTwo->movementId)->reverses_movement_id);
    }

    public function test_bounce_from_received_is_rejected(): void
    {
        $instrument = $this->instrument();

        $this->expectException(InvalidInstrumentTransitionException::class);
        $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
        );
    }

    public function test_representation_gl_failure_rolls_back_cycle_increment(): void
    {
        $instrument = $this->instrument();
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );
        $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
        );
        $this->replaceFiscalCalendarWithClosedJuly2025();
        $this->travelTo(CarbonImmutable::parse('2025-07-18 12:00:00'));

        try {
            $this->service()->represent(
                $instrument->id,
                $this->context['tenant']->id,
                $this->context['company']->id,
                $this->context['user']->id,
            );
            $this->fail('A closed fiscal period must reject re-presentation.');
        } catch (ClosedFiscalPeriodException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->travelBack();
        }

        $freshInstrument = $instrument->fresh();
        self::assertNotNull($freshInstrument);
        self::assertSame(1, $freshInstrument->presentation_cycle);
        self::assertSame(InstrumentStatus::Bounced, $freshInstrument->status);
        self::assertNull(InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:clear:2")->first());
    }

    public function test_identical_bounce_retry_returns_the_original_artifacts(): void
    {
        $instrument = $this->instrument();
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );
        $first = $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'Retry-safe',
        );
        $second = $this->service()->bounce(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            'Retry-safe',
        );

        self::assertTrue($second->replayed);
        self::assertSame($first->journalEntryId, $second->journalEntryId);
        self::assertSame($first->movementId, $second->movementId);
        self::assertSame(1, InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:bounce:1")->count());
    }

    public function test_represent_on_a_never_bounced_cleared_instrument_is_an_invalid_transition(): void
    {
        $instrument = $this->instrument();
        $this->service()->clear(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
            '2026-07-18',
        );

        $this->expectException(InvalidInstrumentTransitionException::class);
        $this->service()->represent(
            $instrument->id,
            $this->context['tenant']->id,
            $this->context['company']->id,
            $this->context['user']->id,
        );
    }

    public function test_movement_port_failure_rolls_back_the_preceding_gl_post_and_clear_state(): void
    {
        $instrument = $this->instrument();
        $journalCount = JournalEntry::query()->count();
        $this->context['bank']->forceFill([
            'frozen_at' => now(),
            'frozen_reason' => 'Injected movement-port rejection',
        ])->save();

        try {
            $this->service()->clear(
                $instrument->id,
                $this->context['tenant']->id,
                $this->context['company']->id,
                $this->context['user']->id,
                '2026-07-18',
            );
            $this->fail('Frozen repository must reject the movement after GL drafting/posting.');
        } catch (RepositoryFrozenException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);
        self::assertSame($journalCount, JournalEntry::query()->count());
        self::assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        self::assertSame(0, InstrumentEvent::query()->where('action_key', "instrument:{$instrument->id}:clear:1")->count());
    }

    private function instrument(InstrumentDirection $direction = InstrumentDirection::Outbound): PaymentInstrument
    {
        return PaymentInstrument::query()->create([
            'tenant_id' => $this->context['tenant']->id,
            'company_id' => $this->context['company']->id,
            'payment_method_id' => $this->context['method']->id,
            'reference' => 'OUT-'.Str::upper(Str::random(8)),
            'partner_id' => $this->context['partner']->id,
            'amount' => '125.000',
            'currency' => 'TND',
            'received_date' => '2026-07-18',
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => $direction,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $this->context['bank']->id,
            'created_by' => $this->context['user']->id,
        ]);
    }

    private function service(): OutboundInstrumentService
    {
        return app(OutboundInstrumentService::class);
    }

    private function replaceFiscalCalendarWithClosedJuly2025(): void
    {
        FiscalPeriod::query()->where('company_id', $this->context['company']->id)->delete();
        FiscalYear::query()->where('company_id', $this->context['company']->id)->delete();
        $year = FiscalYear::query()->create([
            'company_id' => $this->context['company']->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->context['company']->id,
            'name' => 'July 2025',
            'period_number' => 7,
            'start_date' => '2025-07-01',
            'end_date' => '2025-07-31',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $this->context['user']->id,
        ]);
    }
}
