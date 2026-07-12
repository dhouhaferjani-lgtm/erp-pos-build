<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Treasury\Application\DTOs\RepositoryTransferResult;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RepositoryTransferService
{
    private const TRANSFERABLE_TYPES = [
        RepositoryType::CashRegister,
        RepositoryType::Safe,
        RepositoryType::BankAccount,
    ];

    public function __construct(
        private readonly GeneralLedgerService $generalLedger,
        private readonly TreasuryMovementService $movementService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function transfer(
        string $tenantId,
        string $companyId,
        string $fromRepositoryId,
        string $toRepositoryId,
        string $amount,
        ?string $notes,
        ?string $transferGroupId,
        string $userId,
    ): RepositoryTransferResult {
        $from = $this->resolveRepository($tenantId, $companyId, $fromRepositoryId);
        $to = $this->resolveRepository($tenantId, $companyId, $toRepositoryId);

        foreach ([$from, $to] as $repository) {
            if ($repository->frozen_at !== null) {
                throw new RepositoryFrozenException($repository->id, (string) $repository->frozen_reason);
            }
        }

        $amount = CurrencyScale::bcformatStrict(
            $amount,
            $this->scaleResolver->getScale($from->currency),
        );

        $crossGl = $from->gl_account_id !== $to->gl_account_id;
        if ($crossGl && ($from->gl_account_id === null || $to->gl_account_id === null)) {
            throw new \DomainException('Both repositories must have a linked GL account for a cross-account transfer.');
        }

        $groupId = $transferGroupId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $from,
            $to,
            $amount,
            $notes,
            $groupId,
            $crossGl,
            $tenantId,
            $companyId,
            $userId,
        ): RepositoryTransferResult {
            $draft = null;
            if ($crossGl) {
                $draft = $this->generalLedger->createRepositoryTransferJournalEntry(
                    companyId: $companyId,
                    tenantId: $tenantId,
                    transferGroupId: $groupId,
                    fromGlAccountId: (string) $from->gl_account_id,
                    toGlAccountId: (string) $to->gl_account_id,
                    amount: $amount,
                    date: now(),
                    description: trim("Transfert {$from->code} → {$to->code}".($notes !== null ? ": {$notes}" : '')),
                );
            }

            $result = $this->movementService->transfer(new TransferIntent(
                fromRepositoryId: $from->id,
                toRepositoryId: $to->id,
                tenantId: $tenantId,
                companyId: $companyId,
                amount: $amount,
                currency: $from->currency,
                transferGroupId: $groupId,
                journalEntryId: $draft?->id,
                occurredAt: null,
                createdBy: $userId,
                notes: $notes,
            ));

            $outLeg = RepositoryMovement::query()->findOrFail($result->outLeg->movementId);
            if ($draft !== null && $outLeg->journal_entry_id !== $draft->id) {
                $draft->delete();
            }

            return new RepositoryTransferResult(
                transferGroupId: $groupId,
                journalEntryId: $outLeg->journal_entry_id,
                out: $result->outLeg,
                in: $result->inLeg,
                idempotentReplay: $result->outLeg->wasIdempotentHit && $result->inLeg->wasIdempotentHit,
            );
        });
    }

    private function resolveRepository(string $tenantId, string $companyId, string $id): PaymentRepository
    {
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if (! $repository->is_active) {
            throw new \DomainException("Repository {$repository->code} is inactive and cannot take part in a transfer.");
        }

        if (! in_array($repository->type, self::TRANSFERABLE_TYPES, true)) {
            throw new \DomainException("Repository {$repository->code} is a virtual bucket; cash transfers require a physical repository (register, safe, or bank account).");
        }

        return $repository;
    }
}
