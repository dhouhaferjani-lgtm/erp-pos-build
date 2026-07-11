<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\InstrumentEventPayload;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceLineStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\InstrumentRemittanceLine;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InstrumentRemittanceService
{
    public function __construct(
        private GeneralLedgerService $generalLedger,
        private InstrumentAccountResolver $accountResolver,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function createDraft(
        string $companyId,
        string $tenantId,
        string $bankRepositoryId,
        RemittanceType $type,
        InstrumentKind $kind,
        ?string $userId,
    ): InstrumentRemittance {
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($bankRepositoryId);
        if ($repository->type !== RepositoryType::BankAccount) {
            throw new DomainException('Instrument remittances require a bank-account repository.');
        }

        return DB::transaction(fn (): InstrumentRemittance => InstrumentRemittance::query()->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'number' => InstrumentRemittance::allocateNumber($companyId),
            'remittance_type' => $type,
            'instrument_kind' => $kind,
            'bank_repository_id' => $repository->id,
            'status' => RemittanceStatus::Draft,
            'created_by' => $userId,
        ]));
    }

    public function addLine(string $remittanceId, string $instrumentId): InstrumentRemittanceLine
    {
        $snapshot = InstrumentRemittance::query()->findOrFail($remittanceId);

        return DB::transaction(function () use ($snapshot, $instrumentId): InstrumentRemittanceLine {
            // Global order: instrument first, then slip/line rows, then GL (remit only).
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($instrumentId);
            $remittance = InstrumentRemittance::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->assertDraft($remittance);
            $this->assertEligible($remittance, $instrument);

            $alreadyActive = InstrumentRemittanceLine::query()
                ->where('instrument_id', $instrument->id)
                ->where('line_status', RemittanceLineStatus::Pending)
                ->whereHas('remittance', fn ($query) => $query->whereIn('status', [
                    RemittanceStatus::Draft,
                    RemittanceStatus::Remitted,
                ]))
                ->exists();
            if ($alreadyActive) {
                throw new DomainException('Instrument already belongs to an active remittance.');
            }

            $line = InstrumentRemittanceLine::query()->create([
                'remittance_id' => $remittance->id,
                'instrument_id' => $instrument->id,
                'amount' => $instrument->amount,
                'line_status' => RemittanceLineStatus::Pending,
            ]);
            $instrument->update(['remittance_id' => $remittance->id]);

            return $line;
        });
    }

    public function removeLine(string $remittanceId, string $lineId): void
    {
        $lineSnapshot = InstrumentRemittanceLine::query()
            ->where('remittance_id', $remittanceId)
            ->findOrFail($lineId);

        DB::transaction(function () use ($remittanceId, $lineSnapshot): void {
            $instrument = PaymentInstrument::query()->lockForUpdate()->findOrFail($lineSnapshot->instrument_id);
            $remittance = InstrumentRemittance::query()->lockForUpdate()->findOrFail($remittanceId);
            $this->assertDraft($remittance);
            InstrumentRemittanceLine::query()->whereKey($lineSnapshot->id)->delete();
            if ($instrument->remittance_id === $remittance->id) {
                $instrument->update(['remittance_id' => null]);
            }
        });
    }

    public function remit(string $remittanceId, ?string $userId): InstrumentRemittance
    {
        $snapshot = InstrumentRemittance::query()->with('lines')->findOrFail($remittanceId);
        $instrumentIds = $snapshot->lines->pluck('instrument_id')->sort()->values()->all();
        if ($instrumentIds === []) {
            throw new DomainException('Cannot remit an empty slip.');
        }

        return DB::transaction(function () use ($snapshot, $instrumentIds, $userId): InstrumentRemittance {
            /** @var list<PaymentInstrument> $instruments */
            $instruments = PaymentInstrument::query()
                ->whereIn('id', $instrumentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();
            $remittance = InstrumentRemittance::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->assertDraft($remittance);
            if ($remittance->remittance_type !== RemittanceType::Collection) {
                throw new DomainException('Discount remittances are reserved for a later phase.');
            }

            foreach ($instruments as $instrument) {
                $this->assertEligible($remittance, $instrument);
            }

            $currency = $instruments[0]->currency;
            $scale = $this->scaleResolver->getScale($currency);
            $total = '0';
            foreach ($instruments as $instrument) {
                if ($instrument->currency !== $currency) {
                    throw new DomainException('All remittance instruments must share one currency.');
                }
                $total = bcadd($total, $instrument->amount, $scale);
            }
            $total = CurrencyScale::bcformatStrict($total, $scale);

            $slipEntry = null;
            if ($remittance->instrument_kind === InstrumentKind::Effet) {
                $slipEntry = $this->createEffetRemittanceEntry($remittance, $total, $userId, $currency);
            }

            foreach ($instruments as $instrument) {
                $wasRePresented = $instrument->status === InstrumentStatus::Bounced;
                $lineEntry = $slipEntry;
                if ($wasRePresented && $instrument->kind === InstrumentKind::Cheque) {
                    $lineEntry = $this->createChequeRepresentationEntry($instrument, $userId);
                }

                $instrument->update([
                    'status' => InstrumentStatus::Deposited,
                    'deposited_at' => now(),
                    'deposited_to_id' => $remittance->bank_repository_id,
                    'remittance_id' => $remittance->id,
                ]);
                InstrumentEvent::query()->create([
                    'tenant_id' => $instrument->tenant_id,
                    'company_id' => $instrument->company_id,
                    'instrument_id' => $instrument->id,
                    'event_type' => $wasRePresented
                        ? InstrumentEventType::RePresented
                        : InstrumentEventType::Remitted,
                    'from_status' => $wasRePresented
                        ? InstrumentStatus::Bounced->value
                        : InstrumentStatus::Received->value,
                    'to_status' => InstrumentStatus::Deposited->value,
                    'from_repository_id' => $instrument->repository_id,
                    'to_repository_id' => $remittance->bank_repository_id,
                    'remittance_id' => $remittance->id,
                    'journal_entry_id' => $lineEntry?->id,
                    'payload' => (new InstrumentEventPayload)->toArray(),
                    'occurred_at' => now(),
                    'created_by' => $userId,
                ]);
                DB::afterCommit(fn () => event(new InstrumentDeposited(
                    instrumentId: $instrument->id,
                    tenantId: $instrument->tenant_id,
                    companyId: $instrument->company_id,
                    repositoryId: $remittance->bank_repository_id,
                    amount: $instrument->amount,
                    depositedAt: now()->toIso8601String(),
                )));
            }

            $remittance->update([
                'status' => RemittanceStatus::Remitted,
                'remitted_at' => now(),
                'journal_entry_id' => $slipEntry?->id,
            ]);

            return $remittance->fresh(['lines']) ?? $remittance;
        });
    }

    private function createEffetRemittanceEntry(
        InstrumentRemittance $remittance,
        string $total,
        ?string $userId,
        string $currency,
    ): JournalEntry {
        $entry = $this->generalLedger->createInstrumentRemittanceEntry(
            companyId: $remittance->company_id,
            tenantId: $remittance->tenant_id,
            remittanceId: $remittance->id,
            debitAccountId: $this->accountResolver->resolveOrFail(
                InstrumentAccountPurpose::EffectsInCollection,
                $remittance->company_id,
            ),
            creditAccountId: $this->accountResolver->resolveOrFail(
                InstrumentAccountPurpose::EffectsReceivable,
                $remittance->company_id,
            ),
            amount: $total,
            date: now(),
        );
        $this->generalLedger->postEntryNow($entry, User::query()->find($userId), $currency);

        return $entry;
    }

    private function createChequeRepresentationEntry(PaymentInstrument $instrument, ?string $userId): JournalEntry
    {
        $entry = $this->generalLedger->createInstrumentRepresentationEntry(
            companyId: $instrument->company_id,
            tenantId: $instrument->tenant_id,
            instrumentId: $instrument->id,
            partnerId: $instrument->partner_id,
            portfolioAccountId: $this->accountResolver->resolveOrFail(
                InstrumentAccountPurpose::ChecksToCollect,
                $instrument->company_id,
            ),
            amount: $instrument->amount,
            date: now(),
        );
        $this->generalLedger->postEntryNow($entry, User::query()->find($userId), $instrument->currency);

        return $entry;
    }

    private function assertDraft(InstrumentRemittance $remittance): void
    {
        if ($remittance->status !== RemittanceStatus::Draft) {
            throw new DomainException('Only a draft remittance can be composed or remitted.');
        }
    }

    private function assertEligible(InstrumentRemittance $remittance, PaymentInstrument $instrument): void
    {
        $received = $instrument->status === InstrumentStatus::Received;
        $rePresentable = $instrument->status === InstrumentStatus::Bounced
            && $instrument->dishonor_routing === DishonorRouting::RePresent;
        if (! $received && ! $rePresentable) {
            throw new DomainException('Instrument is not eligible for remittance.');
        }
        if ($instrument->kind !== $remittance->instrument_kind) {
            throw new DomainException('Instrument kind does not match the remittance.');
        }
        if ($instrument->company_id !== $remittance->company_id) {
            throw new DomainException('Instrument and remittance must belong to the same company.');
        }
    }
}
