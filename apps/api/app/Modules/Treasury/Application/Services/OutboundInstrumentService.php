<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\OutboundTransitionResult;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Exceptions\InvalidInstrumentTransitionException;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class OutboundInstrumentService
{
    public function __construct(
        private GeneralLedgerService $generalLedger,
        private TreasuryMovementServiceInterface $movementService,
        private InstrumentAccountResolver $accountResolver,
        private OutboundRepositoryValidator $repositoryValidator,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function clear(
        string $instrumentId,
        string $tenantId,
        string $companyId,
        string $userId,
        ?string $occurredAt = null,
    ): OutboundTransitionResult {
        return DB::transaction(function () use (
            $instrumentId,
            $tenantId,
            $companyId,
            $userId,
            $occurredAt,
        ): OutboundTransitionResult {
            $instrument = PaymentInstrument::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($instrumentId)
                ->lockForUpdate()
                ->firstOrFail();

            $cycle = $instrument->presentation_cycle;
            $actionKey = "instrument:{$instrument->id}:clear:{$cycle}";
            $replay = InstrumentEvent::query()
                ->where('instrument_id', $instrument->id)
                ->where('action_key', $actionKey)
                ->first();
            $entryDate = $occurredAt === null
                ? ($replay instanceof InstrumentEvent ? $replay->occurred_at : CarbonImmutable::now())
                : CarbonImmutable::parse($occurredAt);
            $digest = $this->semanticDigest(
                action: 'clear',
                instrument: $instrument,
                repositoryId: $instrument->repository_id,
                occurredOn: $entryDate->toDateString(),
            );
            if ($replay instanceof InstrumentEvent) {
                $replay->assertSemanticDigest($digest);

                return new OutboundTransitionResult(
                    instrumentId: $instrument->id,
                    fromStatus: (string) $replay->from_status,
                    toStatus: (string) $replay->to_status,
                    journalEntryId: $replay->journal_entry_id,
                    movementId: $replay->movement_id,
                    replayed: true,
                );
            }

            if ($instrument->direction !== InstrumentDirection::Outbound) {
                throw new DomainException('The outbound instrument service rejects inbound instruments.');
            }
            if ($instrument->status !== InstrumentStatus::Received) {
                throw new InvalidInstrumentTransitionException('clear', $instrument->status);
            }
            if ($instrument->repository_id === null) {
                throw new DomainException('Outbound instrument has no settlement repository.');
            }

            $repository = $this->repositoryValidator->validate(
                repositoryId: $instrument->repository_id,
                tenantId: $tenantId,
                companyId: $companyId,
                currency: $instrument->currency,
                instrumentBankId: $instrument->bank_id,
            );
            $purpose = $instrument->kind === InstrumentKind::Cheque
                ? InstrumentAccountPurpose::ChecksToPay
                : InstrumentAccountPurpose::EffetsPayable;
            $scale = $this->scaleResolver->getScale($instrument->currency);
            $amount = CurrencyScale::bcformatStrict($instrument->amount, $scale);
            $bankAccountId = $repository->gl_account_id;
            if ($bankAccountId === null) {
                throw new DomainException('Validated outbound repository has no GL account.');
            }
            $entry = $this->generalLedger->createOutboundInstrumentClearingEntry(
                companyId: $companyId,
                tenantId: $tenantId,
                instrumentId: $instrument->id,
                payableAccountId: $this->accountResolver->resolveOrFail($purpose, $companyId),
                bankAccountId: $bankAccountId,
                amount: $amount,
                date: $entryDate,
            );
            $this->generalLedger->postEntryNow(
                $entry,
                User::query()->where('tenant_id', $tenantId)->findOrFail($userId),
                $instrument->currency,
            );

            $movement = $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $tenantId,
                companyId: $companyId,
                direction: MovementDirection::Out,
                amount: $amount,
                currency: $instrument->currency,
                sourceType: MovementSourceType::Instrument,
                sourceId: $instrument->id,
                idempotencyLeg: "clear:{$cycle}",
                journalEntryId: $entry->id,
                occurredAt: $entryDate,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: $userId,
                notes: null,
                allowWhileFrozen: false,
            ));

            $fromStatus = $instrument->status;
            $instrument->forceFill([
                'status' => InstrumentStatus::Cleared,
                'cleared_at' => $entryDate,
                'bounced_at' => null,
                'bounce_reason' => null,
            ])->save();
            InstrumentEvent::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Cleared,
                'action_key' => $actionKey,
                'semantic_digest' => $digest,
                'from_status' => $fromStatus->value,
                'to_status' => InstrumentStatus::Cleared->value,
                'from_repository_id' => $repository->id,
                'to_repository_id' => $repository->id,
                'journal_entry_id' => $entry->id,
                'movement_id' => $movement->movementId,
                'payload' => (new InstrumentEventPayload)->toArray(),
                'occurred_at' => $entryDate,
                'created_by' => $userId,
            ]);

            DB::afterCommit(fn () => event(new InstrumentCleared(
                instrumentId: $instrument->id,
                tenantId: $tenantId,
                companyId: $companyId,
                amount: $amount,
                clearedAt: $entryDate->toIso8601String(),
            )));

            return new OutboundTransitionResult(
                instrumentId: $instrument->id,
                fromStatus: $fromStatus->value,
                toStatus: InstrumentStatus::Cleared->value,
                journalEntryId: $entry->id,
                movementId: $movement->movementId,
                replayed: false,
            );
        });
    }

    public function bounce(
        string $instrumentId,
        string $tenantId,
        string $companyId,
        string $userId,
        ?string $reason = null,
    ): OutboundTransitionResult {
        return DB::transaction(function () use (
            $instrumentId,
            $tenantId,
            $companyId,
            $userId,
            $reason,
        ): OutboundTransitionResult {
            $instrument = PaymentInstrument::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($instrumentId)
                ->lockForUpdate()
                ->firstOrFail();

            $cycle = $instrument->presentation_cycle;
            $actionKey = "instrument:{$instrument->id}:bounce:{$cycle}";
            $replay = InstrumentEvent::query()
                ->where('instrument_id', $instrument->id)
                ->where('action_key', $actionKey)
                ->first();
            $entryDate = $replay instanceof InstrumentEvent ? $replay->occurred_at : CarbonImmutable::now();
            $digest = $this->semanticDigest(
                action: 'bounce',
                instrument: $instrument,
                repositoryId: $instrument->repository_id,
                occurredOn: $entryDate->toDateString(),
            );
            if ($replay instanceof InstrumentEvent) {
                $replay->assertSemanticDigest($digest);

                return new OutboundTransitionResult(
                    instrumentId: $instrument->id,
                    fromStatus: (string) $replay->from_status,
                    toStatus: (string) $replay->to_status,
                    journalEntryId: $replay->journal_entry_id,
                    movementId: $replay->movement_id,
                    replayed: true,
                );
            }

            if ($instrument->direction !== InstrumentDirection::Outbound) {
                throw new DomainException('The outbound instrument service rejects inbound instruments.');
            }
            if ($instrument->status !== InstrumentStatus::Cleared) {
                throw new InvalidInstrumentTransitionException('bounce', $instrument->status);
            }
            if ($instrument->repository_id === null) {
                throw new DomainException('Outbound instrument has no settlement repository.');
            }

            $repository = $this->repositoryValidator->validate(
                repositoryId: $instrument->repository_id,
                tenantId: $tenantId,
                companyId: $companyId,
                currency: $instrument->currency,
                instrumentBankId: $instrument->bank_id,
            );
            $bankAccountId = $repository->gl_account_id;
            if ($bankAccountId === null) {
                throw new DomainException('Validated outbound repository has no GL account.');
            }
            $clearEvent = InstrumentEvent::query()
                ->where('instrument_id', $instrument->id)
                ->where('action_key', "instrument:{$instrument->id}:clear:{$cycle}")
                ->first();
            if (! $clearEvent instanceof InstrumentEvent || $clearEvent->movement_id === null) {
                throw new DomainException('Outbound instrument clearing movement is missing.');
            }

            $purpose = $instrument->kind === InstrumentKind::Cheque
                ? InstrumentAccountPurpose::ChecksToPay
                : InstrumentAccountPurpose::EffetsPayable;
            $scale = $this->scaleResolver->getScale($instrument->currency);
            $amount = CurrencyScale::bcformatStrict($instrument->amount, $scale);
            $entry = $this->generalLedger->createOutboundInstrumentDishonorEntry(
                companyId: $companyId,
                tenantId: $tenantId,
                instrumentId: $instrument->id,
                bankAccountId: $bankAccountId,
                payableAccountId: $this->accountResolver->resolveOrFail($purpose, $companyId),
                amount: $amount,
                date: $entryDate,
            );
            $this->generalLedger->postEntryNow(
                $entry,
                User::query()->where('tenant_id', $tenantId)->findOrFail($userId),
                $instrument->currency,
            );
            $movement = $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $tenantId,
                companyId: $companyId,
                direction: MovementDirection::In,
                amount: $amount,
                currency: $instrument->currency,
                sourceType: MovementSourceType::Instrument,
                sourceId: $instrument->id,
                idempotencyLeg: "bounce:{$cycle}",
                journalEntryId: $entry->id,
                occurredAt: $entryDate,
                reasonCode: null,
                reversesMovementId: $clearEvent->movement_id,
                createdBy: $userId,
                notes: $reason,
                allowWhileFrozen: false,
            ));

            $eventReason = $reason ?? 'Bank dishonor';
            $instrument->forceFill([
                'status' => InstrumentStatus::Bounced,
                'bounced_at' => $entryDate,
                'bounce_reason' => $eventReason,
            ])->save();
            InstrumentEvent::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Bounced,
                'action_key' => $actionKey,
                'semantic_digest' => $digest,
                'from_status' => InstrumentStatus::Cleared->value,
                'to_status' => InstrumentStatus::Bounced->value,
                'from_repository_id' => $repository->id,
                'to_repository_id' => $repository->id,
                'journal_entry_id' => $entry->id,
                'movement_id' => $movement->movementId,
                'payload' => (new InstrumentEventPayload(reason: $eventReason))->toArray(),
                'occurred_at' => $entryDate,
                'created_by' => $userId,
            ]);
            DB::afterCommit(fn () => event(new InstrumentBounced(
                instrumentId: $instrument->id,
                tenantId: $tenantId,
                companyId: $companyId,
                amount: $amount,
                reason: $eventReason,
                bouncedAt: $entryDate->toIso8601String(),
            )));

            return new OutboundTransitionResult(
                instrumentId: $instrument->id,
                fromStatus: InstrumentStatus::Cleared->value,
                toStatus: InstrumentStatus::Bounced->value,
                journalEntryId: $entry->id,
                movementId: $movement->movementId,
                replayed: false,
            );
        });
    }

    public function represent(
        string $instrumentId,
        string $tenantId,
        string $companyId,
        string $userId,
    ): OutboundTransitionResult {
        return DB::transaction(function () use (
            $instrumentId,
            $tenantId,
            $companyId,
            $userId,
        ): OutboundTransitionResult {
            $instrument = PaymentInstrument::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($instrumentId)
                ->lockForUpdate()
                ->firstOrFail();

            $targetCycle = $instrument->status === InstrumentStatus::Bounced
                ? $instrument->presentation_cycle + 1
                : $instrument->presentation_cycle;
            $actionKey = "instrument:{$instrument->id}:clear:{$targetCycle}";
            $replay = InstrumentEvent::query()
                ->where('instrument_id', $instrument->id)
                ->where('action_key', $actionKey)
                ->first();
            $entryDate = $replay instanceof InstrumentEvent ? $replay->occurred_at : CarbonImmutable::now();
            $digest = $this->semanticDigest(
                action: 'represent',
                instrument: $instrument,
                repositoryId: $instrument->repository_id,
                occurredOn: $entryDate->toDateString(),
            );
            if ($replay instanceof InstrumentEvent) {
                $replay->assertSemanticDigest($digest);

                return new OutboundTransitionResult(
                    instrumentId: $instrument->id,
                    fromStatus: (string) $replay->from_status,
                    toStatus: (string) $replay->to_status,
                    journalEntryId: $replay->journal_entry_id,
                    movementId: $replay->movement_id,
                    replayed: true,
                );
            }

            if ($instrument->direction !== InstrumentDirection::Outbound) {
                throw new DomainException('The outbound instrument service rejects inbound instruments.');
            }
            if ($instrument->status !== InstrumentStatus::Bounced) {
                throw new InvalidInstrumentTransitionException('represent', $instrument->status);
            }
            if ($instrument->repository_id === null) {
                throw new DomainException('Outbound instrument has no settlement repository.');
            }

            $repository = $this->repositoryValidator->validate(
                repositoryId: $instrument->repository_id,
                tenantId: $tenantId,
                companyId: $companyId,
                currency: $instrument->currency,
                instrumentBankId: $instrument->bank_id,
            );
            $bankAccountId = $repository->gl_account_id;
            if ($bankAccountId === null) {
                throw new DomainException('Validated outbound repository has no GL account.');
            }
            $purpose = $instrument->kind === InstrumentKind::Cheque
                ? InstrumentAccountPurpose::ChecksToPay
                : InstrumentAccountPurpose::EffetsPayable;
            $scale = $this->scaleResolver->getScale($instrument->currency);
            $amount = CurrencyScale::bcformatStrict($instrument->amount, $scale);

            $instrument->forceFill(['presentation_cycle' => $targetCycle])->save();
            $entry = $this->generalLedger->createOutboundInstrumentClearingEntry(
                companyId: $companyId,
                tenantId: $tenantId,
                instrumentId: $instrument->id,
                payableAccountId: $this->accountResolver->resolveOrFail($purpose, $companyId),
                bankAccountId: $bankAccountId,
                amount: $amount,
                date: $entryDate,
            );
            $this->generalLedger->postEntryNow(
                $entry,
                User::query()->where('tenant_id', $tenantId)->findOrFail($userId),
                $instrument->currency,
            );
            $movement = $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $tenantId,
                companyId: $companyId,
                direction: MovementDirection::Out,
                amount: $amount,
                currency: $instrument->currency,
                sourceType: MovementSourceType::Instrument,
                sourceId: $instrument->id,
                idempotencyLeg: "clear:{$targetCycle}",
                journalEntryId: $entry->id,
                occurredAt: $entryDate,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: $userId,
                notes: null,
                allowWhileFrozen: false,
            ));

            $instrument->forceFill([
                'status' => InstrumentStatus::Cleared,
                'cleared_at' => $entryDate,
                'bounced_at' => null,
                'bounce_reason' => null,
            ])->save();
            InstrumentEvent::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::RePresented,
                'action_key' => $actionKey,
                'semantic_digest' => $digest,
                'from_status' => InstrumentStatus::Bounced->value,
                'to_status' => InstrumentStatus::Cleared->value,
                'from_repository_id' => $repository->id,
                'to_repository_id' => $repository->id,
                'journal_entry_id' => $entry->id,
                'movement_id' => $movement->movementId,
                'payload' => (new InstrumentEventPayload)->toArray(),
                'occurred_at' => $entryDate,
                'created_by' => $userId,
            ]);
            DB::afterCommit(fn () => event(new InstrumentCleared(
                instrumentId: $instrument->id,
                tenantId: $tenantId,
                companyId: $companyId,
                amount: $amount,
                clearedAt: $entryDate->toIso8601String(),
            )));

            return new OutboundTransitionResult(
                instrumentId: $instrument->id,
                fromStatus: InstrumentStatus::Bounced->value,
                toStatus: InstrumentStatus::Cleared->value,
                journalEntryId: $entry->id,
                movementId: $movement->movementId,
                replayed: false,
            );
        });
    }

    private function semanticDigest(
        string $action,
        PaymentInstrument $instrument,
        ?string $repositoryId,
        string $occurredOn,
    ): string {
        $canonical = json_encode([
            'action' => $action,
            'instrumentId' => $instrument->id,
            'amount' => $instrument->amount,
            'currency' => $instrument->currency,
            'repositoryId' => $repositoryId,
            'occurredAt' => $occurredOn,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }
}
