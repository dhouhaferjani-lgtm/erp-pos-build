<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
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
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
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
}
