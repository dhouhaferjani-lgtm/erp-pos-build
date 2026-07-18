<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\BounceInstrumentData;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceLineStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentReceived;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\InstrumentRemittanceLine;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InstrumentLifecycleService
{
    private const TOLERANCE_SCALE = 4;

    public function __construct(
        private GeneralLedgerService $generalLedger,
        private InstrumentAccountResolver $accountResolver,
        private CurrencyScaleResolverInterface $scaleResolver,
        private InstrumentRemittanceService $remittanceService,
        private TreasuryMovementServiceInterface $movementService,
    ) {}

    /**
     * Register paper in the portfolio and its custody log. Receipt-side GL is
     * owned by the payment/POS writer; a manually registered instrument posts
     * nothing until a later lifecycle transition.
     */
    public function receive(ReceiveInstrumentData $data): PaymentInstrument
    {
        return DB::transaction(function () use ($data): PaymentInstrument {
            $companyCurrency = DB::table('companies')
                ->where('tenant_id', $data->tenantId)
                ->where('id', $data->companyId)
                ->value('currency');
            if (! is_string($companyCurrency) || strtoupper($data->currency) !== strtoupper($companyCurrency)) {
                throw new DomainException('Instrument currency must match company currency.');
            }

            $amount = CurrencyScale::bcformatStrict(
                $data->amount,
                $this->scaleResolver->getScale($data->currency),
            );

            $instrument = PaymentInstrument::query()->create([
                'tenant_id' => $data->tenantId,
                'company_id' => $data->companyId,
                'payment_method_id' => $data->paymentMethodId,
                'kind' => $data->kind,
                'direction' => $data->direction,
                'origin' => $data->origin,
                'reference' => $data->reference,
                'amount' => $amount,
                'currency' => strtoupper($data->currency),
                'repository_id' => $data->repositoryId,
                'location_id' => $data->locationId,
                'partner_id' => $data->partnerId,
                'drawer_name' => $data->drawerName,
                'maturity_date' => $data->maturityDate,
                'received_date' => $data->receivedDate ?? now()->toDateString(),
                'bank_id' => $data->bankId,
                'bank_name' => $data->bankName,
                'bank_branch' => $data->bankBranch,
                'bank_account' => $data->bankAccount,
                'idempotency_key' => $data->idempotencyKey,
                'needs_details' => $data->needsDetails,
                'status' => InstrumentStatus::Received,
                'created_by' => $data->createdBy,
            ]);

            InstrumentEvent::query()->create([
                'tenant_id' => $data->tenantId,
                'company_id' => $data->companyId,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Created,
                'from_status' => null,
                'to_status' => InstrumentStatus::Received->value,
                'to_repository_id' => $data->repositoryId,
                'payload' => (new InstrumentEventPayload)->toArray(),
                'occurred_at' => now(),
                'created_by' => $data->createdBy,
            ]);

            DB::afterCommit(fn () => event(new InstrumentReceived(
                instrumentId: $instrument->id,
                tenantId: $data->tenantId,
                companyId: $data->companyId,
                amount: $amount,
                receivedAt: now()->toIso8601String(),
            )));

            return $instrument;
        });
    }

    public function custodyTransfer(string $instrumentId, string $toRepositoryId, ?string $userId): void
    {
        DB::transaction(function () use ($instrumentId, $toRepositoryId, $userId): void {
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($instrumentId);
            if ($instrument->direction === InstrumentDirection::Outbound) {
                throw new DomainException('Outbound (supplier-direction) instruments have no collection lifecycle; deposit/clear/bounce apply to inbound instruments only.');
            }
            if (! $instrument->status->canTransfer()) {
                throw new DomainException('Instrument status does not allow custody transfer.');
            }

            PaymentRepository::query()
                ->where('company_id', $instrument->company_id)
                ->findOrFail($toRepositoryId);
            $fromRepositoryId = $instrument->repository_id;
            $instrument->update(['repository_id' => $toRepositoryId]);

            InstrumentEvent::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'company_id' => $instrument->company_id,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::CustodyTransferred,
                'from_status' => $instrument->status->value,
                'to_status' => $instrument->status->value,
                'from_repository_id' => $fromRepositoryId,
                'to_repository_id' => $toRepositoryId,
                'payload' => (new InstrumentEventPayload)->toArray(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            DB::afterCommit(fn () => event(new InstrumentTransferred(
                instrumentId: $instrument->id,
                tenantId: $instrument->tenant_id,
                companyId: $instrument->company_id,
                fromRepositoryId: $fromRepositoryId ?? '',
                toRepositoryId: $toRepositoryId,
                amount: $instrument->amount,
                transferredAt: now()->toIso8601String(),
            )));
        });
    }

    public function deposit(string $instrumentId, string $bankRepositoryId, ?string $userId): void
    {
        DB::transaction(function () use ($instrumentId, $bankRepositoryId, $userId): void {
            $instrument = PaymentInstrument::query()->findOrFail($instrumentId);
            if ($instrument->direction === InstrumentDirection::Outbound) {
                throw new DomainException('Outbound (supplier-direction) instruments have no collection lifecycle; deposit/clear/bounce apply to inbound instruments only.');
            }
            $remittance = $this->remittanceService->createDraft(
                companyId: $instrument->company_id,
                tenantId: $instrument->tenant_id,
                bankRepositoryId: $bankRepositoryId,
                type: RemittanceType::Collection,
                kind: $instrument->kind ?? throw new DomainException('Instrument kind is required.'),
                userId: $userId,
            );
            $this->remittanceService->addLine($remittance->id, $instrument->id);
            $this->remittanceService->remit($remittance->id, $userId);
        });
    }

    public function clear(ClearInstrumentData $data): PaymentInstrument
    {
        return DB::transaction(function () use ($data): PaymentInstrument {
            // Global order starts with the instrument row. Line/slip reads and GL
            // follow; the repository row is locked only inside the movement port.
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($data->instrumentId);
            if ($instrument->direction === InstrumentDirection::Outbound) {
                throw new DomainException('Outbound (supplier-direction) instruments have no collection lifecycle; deposit/clear/bounce apply to inbound instruments only.');
            }
            if (! $instrument->status->canClear()) {
                throw new DomainException('Instrument cannot clear in its current status.');
            }
            if ($instrument->currency !== strtoupper($data->currency)) {
                throw new DomainException('Clearing currency does not match the instrument.');
            }

            $line = InstrumentRemittanceLine::query()
                ->where('instrument_id', $instrument->id)
                ->where('line_status', RemittanceLineStatus::Pending)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                throw new DomainException('Instrument is not pending on a remitted slip.');
            }
            $remittance = InstrumentRemittance::query()->lockForUpdate()->findOrFail($line->remittance_id);
            if ($remittance->status !== RemittanceStatus::Remitted) {
                throw new DomainException('Instrument slip has not been remitted.');
            }
            $repository = PaymentRepository::query()->findOrFail($remittance->bank_repository_id);
            if ($repository->gl_account_id === null) {
                throw new DomainException('Bank repository has no GL account.');
            }

            $scale = $this->scaleResolver->getScale($data->currency);
            $fee = CurrencyScale::bcformatStrict($data->feeAmount, $scale);
            $feeVat = CurrencyScale::bcformatStrict($data->feeVatAmount, $scale);
            if (bccomp($fee, '0', $scale) < 0 || bccomp($feeVat, '0', $scale) < 0) {
                throw new DomainException('Clearing fees cannot be negative.');
            }
            $feeGross = bcadd($fee, $feeVat, $scale);
            $net = bcsub($instrument->amount, $feeGross, $scale);
            if (bccomp($net, '0', $scale) <= 0) {
                throw new DomainException('Clearing net amount must be positive.');
            }

            $portfolioPurpose = $instrument->kind === InstrumentKind::Cheque
                ? InstrumentAccountPurpose::ChecksToCollect
                : InstrumentAccountPurpose::EffectsInCollection;
            $entryDate = $data->valueDate !== null ? CarbonImmutable::parse($data->valueDate) : CarbonImmutable::now();
            $entry = $this->generalLedger->createInstrumentClearingEntry(
                companyId: $instrument->company_id,
                tenantId: $instrument->tenant_id,
                instrumentId: $instrument->id,
                bankAccountId: $repository->gl_account_id,
                portfolioAccountId: $this->accountResolver->resolveOrFail($portfolioPurpose, $instrument->company_id),
                feeAccountId: $this->accountResolver->resolveOrFail(
                    InstrumentAccountPurpose::InstrumentBankFees,
                    $instrument->company_id,
                ),
                vatAccountId: $this->accountResolver->resolveOrFail(
                    InstrumentAccountPurpose::VatRecoverableOnFees,
                    $instrument->company_id,
                ),
                nominal: $instrument->amount,
                net: $net,
                fee: $fee,
                feeVat: $feeVat,
                scale: $scale,
                date: $entryDate,
            );
            $this->generalLedger->postEntryNow($entry, User::query()->find($data->userId), $data->currency);

            $movement = $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $instrument->tenant_id,
                companyId: $instrument->company_id,
                direction: MovementDirection::In,
                amount: $net,
                currency: $data->currency,
                sourceType: MovementSourceType::Instrument,
                sourceId: $instrument->id,
                idempotencyLeg: "clear:{$line->id}",
                journalEntryId: $entry->id,
                occurredAt: $entryDate,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: $data->userId,
                notes: null,
                allowWhileFrozen: false,
            ));

            $instrument->update(['status' => InstrumentStatus::Cleared, 'cleared_at' => now()]);
            $line->update(['line_status' => RemittanceLineStatus::Cleared, 'cleared_at' => now()]);
            if (! InstrumentRemittanceLine::query()
                ->where('remittance_id', $remittance->id)
                ->where('line_status', RemittanceLineStatus::Pending)
                ->exists()) {
                $remittance->update(['status' => RemittanceStatus::Closed]);
            }
            InstrumentEvent::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'company_id' => $instrument->company_id,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Cleared,
                'from_status' => InstrumentStatus::Deposited->value,
                'to_status' => InstrumentStatus::Cleared->value,
                'from_repository_id' => $instrument->repository_id,
                'to_repository_id' => $repository->id,
                'remittance_id' => $remittance->id,
                'journal_entry_id' => $entry->id,
                'movement_id' => $movement->movementId,
                'payload' => (new InstrumentEventPayload(feeAmount: $fee, feeVatAmount: $feeVat))->toArray(),
                'occurred_at' => now(),
                'created_by' => $data->userId,
            ]);
            DB::afterCommit(fn () => event(new InstrumentCleared(
                instrumentId: $instrument->id,
                tenantId: $instrument->tenant_id,
                companyId: $instrument->company_id,
                amount: $instrument->amount,
                clearedAt: now()->toIso8601String(),
            )));

            return $instrument->fresh() ?? $instrument;
        });
    }

    public function bounce(BounceInstrumentData $data): PaymentInstrument
    {
        return DB::transaction(function () use ($data): PaymentInstrument {
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($data->instrumentId);
            if ($instrument->direction === InstrumentDirection::Outbound) {
                throw new DomainException('Outbound (supplier-direction) instruments have no collection lifecycle; deposit/clear/bounce apply to inbound instruments only.');
            }
            if (! $instrument->status->canBounce()) {
                throw new DomainException('Instrument cannot bounce in its current status.');
            }
            if ($instrument->currency !== strtoupper($data->currency)) {
                throw new DomainException('Dishonor currency does not match the instrument.');
            }

            $line = InstrumentRemittanceLine::query()
                ->where('instrument_id', $instrument->id)
                ->whereIn('line_status', [RemittanceLineStatus::Pending, RemittanceLineStatus::Cleared])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                throw new DomainException('Instrument is not on a remitted slip.');
            }
            $remittance = InstrumentRemittance::query()->lockForUpdate()->findOrFail($line->remittance_id);
            if (! in_array($remittance->status, [RemittanceStatus::Remitted, RemittanceStatus::Closed], true)) {
                throw new DomainException('Instrument slip has not been remitted.');
            }
            $repository = PaymentRepository::query()->findOrFail($remittance->bank_repository_id);
            if ($repository->gl_account_id === null) {
                throw new DomainException('Bank repository has no GL account.');
            }

            $payment = $instrument->payment()->lockForUpdate()->first();
            $allocations = collect();
            $toleranceAllocations = collect();
            $documents = collect();
            if ($data->routing !== DishonorRouting::RePresent && $payment !== null) {
                $allocations = PaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->where('amount', '>', 0)
                    ->orderBy('document_id')
                    ->orderBy('id')
                    ->get();
                $documentIds = $allocations->pluck('document_id')->unique()->sort()->values();
                if ($documentIds->isNotEmpty()) {
                    // Document locks precede every GL advisory lock. The stable id
                    // order matches the payment writer and prevents AB/BA waits.
                    $documents = Document::query()
                        ->whereIn('id', $documentIds)
                        ->where('company_id', $instrument->company_id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                    if ($documents->count() !== $documentIds->count()) {
                        throw new DomainException('A payment allocation references a document outside the instrument company.');
                    }
                    $toleranceAllocations = PaymentAllocation::query()
                        ->whereNull('payment_id')
                        ->whereIn('document_id', $documentIds)
                        ->whereColumn('tolerance_writeoff', 'amount')
                        ->where('amount', '>', 0)
                        ->orderBy('document_id')
                        ->orderBy('id')
                        ->get();
                }
            }

            $scale = $this->scaleResolver->getScale($data->currency);
            $fee = CurrencyScale::bcformatStrict($data->feeAmount, $scale);
            $feeVat = CurrencyScale::bcformatStrict($data->feeVatAmount, $scale);
            if (bccomp($fee, '0', $scale) < 0 || bccomp($feeVat, '0', $scale) < 0) {
                throw new DomainException('Dishonor fees cannot be negative.');
            }
            $feeGross = bcadd($fee, $feeVat, $scale);
            $afterClearing = $instrument->status === InstrumentStatus::Cleared;
            $movementAmount = $afterClearing
                ? bcadd($instrument->amount, $feeGross, $scale)
                : $feeGross;
            $routingAccountId = match ($data->routing) {
                DishonorRouting::Doubtful => $this->accountResolver->resolveOrFail(
                    InstrumentAccountPurpose::DoubtfulReceivables,
                    $instrument->company_id,
                ),
                DishonorRouting::RePresent => $instrument->kind === InstrumentKind::Effet
                    ? $this->accountResolver->resolveOrFail(
                        InstrumentAccountPurpose::EffectsReceivable,
                        $instrument->company_id,
                    )
                    : Account::findByPurposeOrFail(
                        $instrument->company_id,
                        SystemAccountPurpose::CustomerReceivable,
                    )->id,
                DishonorRouting::Receivable => Account::findByPurposeOrFail(
                    $instrument->company_id,
                    SystemAccountPurpose::CustomerReceivable,
                )->id,
            };
            $portfolioPurpose = $instrument->kind === InstrumentKind::Cheque
                ? InstrumentAccountPurpose::ChecksToCollect
                : InstrumentAccountPurpose::EffectsInCollection;
            $entry = $this->generalLedger->createInstrumentDishonorEntry(
                companyId: $instrument->company_id,
                tenantId: $instrument->tenant_id,
                instrumentId: $instrument->id,
                partnerId: $instrument->partner_id,
                routingAccountId: $routingAccountId,
                portfolioAccountId: $this->accountResolver->resolveOrFail($portfolioPurpose, $instrument->company_id),
                bankAccountId: $repository->gl_account_id,
                feeAccountId: $this->accountResolver->resolveOrFail(
                    InstrumentAccountPurpose::InstrumentBankFees,
                    $instrument->company_id,
                ),
                vatAccountId: $this->accountResolver->resolveOrFail(
                    InstrumentAccountPurpose::VatRecoverableOnFees,
                    $instrument->company_id,
                ),
                nominal: $instrument->amount,
                fee: $fee,
                feeVat: $feeVat,
                afterClearing: $afterClearing,
                scale: $scale,
                date: now(),
            );
            $actor = User::query()->find($data->userId);
            $this->generalLedger->postEntryNow($entry, $actor, $data->currency);

            foreach ($toleranceAllocations as $tolerance) {
                // ASSUMPTION: at most ONE active tolerance write-off JE per document.
                // `latest('created_at')` picks the newest posted payment_tolerance entry
                // for the document; that is correct today because a document's tolerance
                // write-off closes it — a second tolerance JE can only exist after a prior
                // dishonor reversed the first and reopened the document, so the newest one
                // is always the active one. Note the reversal entry
                // (source_type='instrument_tolerance_reversal') stamps source_id with the
                // INSTRUMENT id — not the original JE or the document — and nothing writes
                // journal_entries.reversed_at / reversal_entry_id, so there is currently NO
                // queryable predicate to exclude already-reversed originals. If multiple
                // tolerance write-offs per document ever coexist, stamp reversal linkage on
                // the original entry and filter on it here instead of relying on recency.
                $original = JournalEntry::query()
                    ->where('company_id', $instrument->company_id)
                    ->where('source_type', 'payment_tolerance')
                    ->where('source_id', $tolerance->document_id)
                    ->where('status', 'posted')
                    ->latest('created_at')
                    ->first();
                if ($original === null) {
                    throw new DomainException('Tolerance write-off entry is missing for a reopened document.');
                }
                $reversal = $this->generalLedger->createInstrumentToleranceReversalEntry(
                    $instrument->company_id,
                    $instrument->tenant_id,
                    $instrument->id,
                    $original,
                    now(),
                );
                $this->generalLedger->postEntryNow($reversal, $actor, $data->currency);
            }

            $movementId = null;
            if (bccomp($movementAmount, '0', $scale) > 0) {
                $leg = $afterClearing ? "dishonor:{$line->id}" : "bounce_fee:{$line->id}";
                $movement = $this->movementService->record(new MovementIntent(
                    repositoryId: $repository->id,
                    tenantId: $instrument->tenant_id,
                    companyId: $instrument->company_id,
                    direction: MovementDirection::Out,
                    amount: $movementAmount,
                    currency: $data->currency,
                    sourceType: MovementSourceType::Instrument,
                    sourceId: $instrument->id,
                    idempotencyLeg: $leg,
                    journalEntryId: $entry->id,
                    occurredAt: CarbonImmutable::now(),
                    reasonCode: null,
                    reversesMovementId: null,
                    createdBy: $data->userId,
                    notes: $data->reason,
                    allowWhileFrozen: false,
                ));
                $movementId = $movement->movementId;
            }

            if ($data->routing !== DishonorRouting::RePresent && $payment !== null) {
                foreach ($allocations as $allocation) {
                    PaymentAllocation::query()->create([
                        'payment_id' => $payment->id,
                        'document_id' => $allocation->document_id,
                        'amount' => bcmul($allocation->amount, '-1', $scale),
                        'tolerance_writeoff' => null,
                    ]);
                }
                foreach ($toleranceAllocations as $tolerance) {
                    $toleranceAmount = CurrencyScale::bcformatStrict($tolerance->amount, $scale);
                    $toleranceWriteoff = CurrencyScale::bcformatStrict(
                        (string) $tolerance->tolerance_writeoff,
                        self::TOLERANCE_SCALE,
                    );
                    PaymentAllocation::query()->create([
                        'payment_id' => null,
                        'document_id' => $tolerance->document_id,
                        'amount' => bcsub('0', $toleranceAmount, $scale),
                        'tolerance_writeoff' => bcsub('0', $toleranceWriteoff, self::TOLERANCE_SCALE),
                    ]);
                }
                foreach ($documents as $document) {
                    $documentTotal = CurrencyScale::bcformatStrict((string) $document->total, $scale);
                    $allocated = PaymentAllocation::query()
                        ->where('document_id', $document->id)
                        ->get('amount')
                        ->reduce(fn (string $sum, PaymentAllocation $row): string => bcadd($sum, $row->amount, $scale), '0');
                    $credited = CreditNoteAllocation::query()
                        ->where('invoice_id', $document->id)
                        ->get('amount')
                        ->reduce(fn (string $sum, CreditNoteAllocation $row): string => bcadd($sum, $row->amount, $scale), '0');
                    $document->update([
                        'balance_due' => bcsub(bcsub($documentTotal, $allocated, $scale), $credited, $scale),
                        'status' => $document->status === DocumentStatus::Paid ? DocumentStatus::Posted : $document->status,
                    ]);
                }
            }
            if ($payment !== null) {
                $payment->update(['dishonored_at' => now()]);
            }

            $instrument->update([
                'status' => InstrumentStatus::Bounced,
                'bounced_at' => now(),
                'bounce_reason' => $data->reason,
                'dishonor_routing' => $data->routing,
            ]);
            $line->update(['line_status' => RemittanceLineStatus::Bounced, 'bounced_at' => now()]);
            if (! InstrumentRemittanceLine::query()
                ->where('remittance_id', $remittance->id)
                ->where('line_status', RemittanceLineStatus::Pending)
                ->exists()) {
                $remittance->update(['status' => RemittanceStatus::Closed]);
            }
            InstrumentEvent::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'company_id' => $instrument->company_id,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Bounced,
                'from_status' => $afterClearing ? InstrumentStatus::Cleared->value : InstrumentStatus::Deposited->value,
                'to_status' => InstrumentStatus::Bounced->value,
                'from_repository_id' => $instrument->repository_id,
                'to_repository_id' => $repository->id,
                'remittance_id' => $remittance->id,
                'journal_entry_id' => $entry->id,
                'movement_id' => $movementId,
                'payload' => (new InstrumentEventPayload(
                    dishonorRouting: $data->routing,
                    feeAmount: $fee,
                    feeVatAmount: $feeVat,
                    reason: $data->reason,
                ))->toArray(),
                'occurred_at' => now(),
                'created_by' => $data->userId,
            ]);
            DB::afterCommit(fn () => event(new InstrumentBounced(
                instrumentId: $instrument->id,
                tenantId: $instrument->tenant_id,
                companyId: $instrument->company_id,
                amount: $instrument->amount,
                reason: $data->reason ?? '',
                bouncedAt: now()->toIso8601String(),
            )));

            return $instrument->fresh() ?? $instrument;
        });
    }

    public function cancel(
        string $instrumentId,
        ?string $userId,
        string $reason,
        CancellationShape $shape = CancellationShape::B2b,
    ): void {
        DB::transaction(function () use ($instrumentId, $userId, $reason, $shape): void {
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($instrumentId);
            if ($instrument->status !== InstrumentStatus::Received) {
                throw new DomainException('Only a received instrument can be cancelled.');
            }

            $payment = $instrument->payment()->first();
            if ($payment !== null && $payment->status !== PaymentStatus::Reversed
                && $payment->getAttribute('dishonored_at') === null
                && $shape !== CancellationShape::PosRevenue) {
                throw new DomainException('Settle or reverse the linked payment before cancelling its instrument.');
            }

            $journalEntryId = null;
            if ($payment !== null && $payment->journal_entry_id !== null) {
                $purpose = $instrument->kind === InstrumentKind::Cheque
                    ? InstrumentAccountPurpose::ChecksToCollect
                    : InstrumentAccountPurpose::EffectsReceivable;
                $portfolioAccountId = $this->accountResolver->resolveOrFail($purpose, $instrument->company_id);
                $entry = $this->generalLedger->createInstrumentCancellationEntry(
                    companyId: $instrument->company_id,
                    tenantId: $instrument->tenant_id,
                    instrumentId: $instrument->id,
                    partnerId: $instrument->partner_id,
                    portfolioAccountId: $portfolioAccountId,
                    amount: $instrument->amount,
                    shape: $shape,
                    date: now(),
                );
                $this->generalLedger->postEntryNow($entry, User::query()->find($userId), $instrument->currency);
                $journalEntryId = $entry->id;
            }

            if ($payment !== null && $shape === CancellationShape::PosRevenue) {
                $payment->update(['status' => PaymentStatus::Reversed]);
            }

            $instrument->update(['status' => InstrumentStatus::Cancelled]);
            InstrumentEvent::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'company_id' => $instrument->company_id,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Cancelled,
                'from_status' => InstrumentStatus::Received->value,
                'to_status' => InstrumentStatus::Cancelled->value,
                'from_repository_id' => $instrument->repository_id,
                'journal_entry_id' => $journalEntryId,
                'payload' => (new InstrumentEventPayload(reason: $reason))->toArray(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    public function updateDetails(string $instrumentId, array $changes, ?string $userId): PaymentInstrument
    {
        return DB::transaction(function () use ($instrumentId, $changes, $userId): PaymentInstrument {
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($instrumentId);
            if ($instrument->status !== InstrumentStatus::Received) {
                throw new DomainException('Instrument details can only be changed while received.');
            }

            $allowed = [
                'reference', 'maturity_date', 'drawer_name', 'bank_id',
                'bank_name', 'bank_branch', 'bank_account', 'partner_id',
            ];
            $updates = array_intersect_key($changes, array_flip($allowed));
            if ($updates === []) {
                throw new DomainException('No supported instrument details were supplied.');
            }

            $diff = [];
            foreach ($updates as $field => $value) {
                $old = $instrument->getRawOriginal($field);
                $diff[$field] = [
                    'old' => is_string($old) ? $old : null,
                    'new' => $value,
                ];
            }
            $instrument->fill($updates);
            if ($instrument->reference !== ''
                && ($instrument->kind === InstrumentKind::Cheque || $instrument->maturity_date !== null)) {
                $instrument->needs_details = false;
            }
            $instrument->save();

            InstrumentEvent::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'company_id' => $instrument->company_id,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::DetailsUpdated,
                'from_status' => InstrumentStatus::Received->value,
                'to_status' => InstrumentStatus::Received->value,
                'from_repository_id' => $instrument->repository_id,
                'to_repository_id' => $instrument->repository_id,
                'payload' => (new InstrumentEventPayload(detailsDiff: $diff))->toArray(),
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);

            return $instrument->fresh() ?? $instrument;
        });
    }
}
