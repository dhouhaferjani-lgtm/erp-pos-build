<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\Events\InstrumentReceived;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InstrumentLifecycleService
{
    public function __construct(
        private GeneralLedgerService $generalLedger,
        private InstrumentAccountResolver $accountResolver,
        private CurrencyScaleResolverInterface $scaleResolver,
        private InstrumentRemittanceService $remittanceService,
    ) {}

    /**
     * Register paper in the portfolio and its custody log. Receipt-side GL is
     * owned by the payment/POS writer; a manually registered instrument posts
     * nothing until a later lifecycle transition.
     */
    public function receive(ReceiveInstrumentData $data): PaymentInstrument
    {
        return DB::transaction(function () use ($data): PaymentInstrument {
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
                && $payment->getAttribute('dishonored_at') === null) {
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
            if ($instrument->reference !== '' && $instrument->maturity_date !== null) {
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
