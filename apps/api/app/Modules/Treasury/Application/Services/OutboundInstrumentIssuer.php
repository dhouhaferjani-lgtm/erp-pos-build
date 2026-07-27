<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentIssueData;
use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentIssueResult;
use App\Shared\Contracts\Treasury\OutboundInstrumentIssuerInterface;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class OutboundInstrumentIssuer implements OutboundInstrumentIssuerInterface
{
    public function __construct(
        private InstrumentLifecycleService $instrumentLifecycle,
        private InstrumentAccountResolver $accountResolver,
        private OutboundRepositoryValidator $repositoryValidator,
        private GeneralLedgerService $generalLedger,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function issue(OutboundInstrumentIssueData $data): OutboundInstrumentIssueResult
    {
        return DB::transaction(function () use ($data): OutboundInstrumentIssueResult {
            $kind = InstrumentKind::tryFrom($data->kind);
            if (! in_array($kind, [InstrumentKind::Cheque, InstrumentKind::Effet], true)) {
                throw new DomainException('Expense settlement supports cheque or effet instruments only.');
            }
            if ($kind === InstrumentKind::Effet && $data->maturityDate === null) {
                throw new DomainException('An effet settlement requires a maturity date.');
            }

            $method = PaymentMethod::query()
                ->where('tenant_id', $data->tenantId)
                ->where('company_id', $data->companyId)
                ->whereKey($data->paymentMethodId)
                ->first();
            if (! $method instanceof PaymentMethod || ! $method->is_active) {
                throw new DomainException('The selected payment method is not active for this company.');
            }
            if ($method->instrument_kind !== $kind) {
                throw new DomainException('The selected payment method does not match the instrument kind.');
            }

            $repository = $this->repositoryValidator->validate(
                repositoryId: $data->repositoryId,
                tenantId: $data->tenantId,
                companyId: $data->companyId,
                currency: $data->currency,
                instrumentBankId: $data->bankId,
            );
            $idempotencyKey = null;
            if ($data->idempotencyPrefix !== null) {
                $cycle = PaymentInstrument::query()
                    ->where('tenant_id', $data->tenantId)
                    ->where('company_id', $data->companyId)
                    ->where('idempotency_key', 'like', $data->idempotencyPrefix.':%')
                    ->count() + 1;
                $idempotencyKey = $data->idempotencyPrefix.':'.$cycle;
            }

            $instrument = $this->instrumentLifecycle->receive(new ReceiveInstrumentData(
                tenantId: $data->tenantId,
                companyId: $data->companyId,
                paymentMethodId: $method->id,
                kind: $kind,
                direction: InstrumentDirection::Outbound,
                origin: InstrumentOrigin::Web,
                reference: $data->reference,
                amount: $data->amount,
                currency: $data->currency,
                repositoryId: $repository->id,
                partnerId: $data->partnerId,
                drawerName: $data->drawerName,
                maturityDate: $data->maturityDate,
                receivedDate: $data->issueDate,
                bankId: $data->bankId,
                bankName: $data->bankName,
                bankBranch: $data->bankBranch,
                bankAccount: $data->bankAccount,
                idempotencyKey: $idempotencyKey,
                createdBy: $data->createdBy,
            ));
            $user = User::query()
                ->where('tenant_id', $data->tenantId)
                ->whereKey($data->createdBy)
                ->firstOrFail();

            return $this->issueExisting(
                instrument: $instrument,
                partnerId: $data->partnerId,
                user: $user,
                issueDate: $data->issueDate,
                expectedAmount: $data->amount,
            );
        });
    }

    /** @param numeric-string|null $expectedAmount */
    public function issueExisting(
        PaymentInstrument $instrument,
        ?string $partnerId,
        User $user,
        string $issueDate,
        ?string $expectedAmount = null,
    ): OutboundInstrumentIssueResult {
        return DB::transaction(function () use ($instrument, $partnerId, $user, $issueDate, $expectedAmount): OutboundInstrumentIssueResult {
            $instrument = PaymentInstrument::query()
                ->where('tenant_id', $instrument->tenant_id)
                ->where('company_id', $instrument->company_id)
                ->whereKey($instrument->id)
                ->lockForUpdate()
                ->firstOrFail();
            $occurredAt = CarbonImmutable::parse($issueDate);
            $actionKey = "instrument:{$instrument->id}:issue";
            $digest = $this->issueDigest($instrument, $occurredAt);
            $issueEvent = InstrumentEvent::query()
                ->where('tenant_id', $instrument->tenant_id)
                ->where('company_id', $instrument->company_id)
                ->where('instrument_id', $instrument->id)
                ->where('action_key', $actionKey)
                ->first();
            if ($issueEvent instanceof InstrumentEvent) {
                $issueEvent->assertSemanticDigest($digest);
                if ($issueEvent->journal_entry_id === null) {
                    throw new \LogicException('Replayed outbound issue has no journal entry.');
                }

                return new OutboundInstrumentIssueResult(
                    instrumentId: $instrument->id,
                    journalEntryId: $issueEvent->journal_entry_id,
                    repositoryId: $instrument->repository_id ?? throw new DomainException('Outbound instrument has no repository.'),
                    paymentMethodId: $instrument->payment_method_id,
                    replayed: true,
                );
            }

            if ($instrument->direction !== InstrumentDirection::Outbound
                || $instrument->status !== InstrumentStatus::Received) {
                throw new DomainException('Only a received outbound instrument can be issued.');
            }
            if ($expectedAmount !== null && bccomp(
                $instrument->amount,
                $expectedAmount,
                $this->scaleResolver->getScale($instrument->currency),
            ) !== 0) {
                throw new DomainException('Outbound instrument amount does not match the payable amount.');
            }
            if (! in_array($instrument->kind, [InstrumentKind::Cheque, InstrumentKind::Effet], true)) {
                throw new DomainException('Outbound instrument kind must be cheque or effet.');
            }
            $repositoryId = $instrument->repository_id
                ?? throw new DomainException('Outbound instrument has no repository.');
            $this->repositoryValidator->validate(
                repositoryId: $repositoryId,
                tenantId: $instrument->tenant_id,
                companyId: $instrument->company_id,
                currency: $instrument->currency,
                instrumentBankId: $instrument->bank_id,
            );
            $purpose = $instrument->kind === InstrumentKind::Cheque
                ? InstrumentAccountPurpose::ChecksToPay
                : InstrumentAccountPurpose::EffetsPayable;
            $entry = $this->generalLedger->createOutboundInstrumentIssueEntry(
                companyId: $instrument->company_id,
                tenantId: $instrument->tenant_id,
                instrumentId: $instrument->id,
                partnerId: $partnerId,
                payableAccountId: $this->accountResolver->resolveOrFail($purpose, $instrument->company_id),
                amount: $instrument->amount,
                date: $occurredAt,
            );
            $this->generalLedger->postEntryNow($entry, $user, $instrument->currency);
            InstrumentEvent::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'company_id' => $instrument->company_id,
                'instrument_id' => $instrument->id,
                'event_type' => InstrumentEventType::Issued,
                'action_key' => $actionKey,
                'semantic_digest' => $digest,
                'from_status' => null,
                'to_status' => InstrumentStatus::Received->value,
                'to_repository_id' => $repositoryId,
                'journal_entry_id' => $entry->id,
                'movement_id' => null,
                'payload' => (new InstrumentEventPayload)->toArray(),
                'occurred_at' => $occurredAt,
                'created_by' => $user->id,
            ]);

            return new OutboundInstrumentIssueResult(
                instrumentId: $instrument->id,
                journalEntryId: $entry->id,
                repositoryId: $repositoryId,
                paymentMethodId: $instrument->payment_method_id,
                replayed: false,
            );
        });
    }

    public function assertReplaceable(string $instrumentId, string $tenantId, string $companyId): void
    {
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($instrumentId)
            ->first();
        if (! $instrument instanceof PaymentInstrument) {
            throw new DomainException('The linked payment instrument could not be resolved for this company.');
        }
        if (! in_array($instrument->status, [InstrumentStatus::Cancelled, InstrumentStatus::Expired], true)) {
            throw new DomainException('This expense already has an outstanding or cleared payment instrument.');
        }
    }

    private function issueDigest(PaymentInstrument $instrument, CarbonImmutable $occurredAt): string
    {
        $canonical = json_encode([
            'action' => 'issue',
            'instrumentId' => $instrument->id,
            'amount' => $instrument->amount,
            'currency' => $instrument->currency,
            'repositoryId' => $instrument->repository_id,
            'occurredAt' => $occurredAt->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }
}
