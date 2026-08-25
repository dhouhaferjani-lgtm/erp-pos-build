<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryAlreadySeededException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatIntent;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatRepositoryDescriptor;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatResult;
use App\Shared\Contracts\Treasury\RepositoryOpeningBalanceSeederInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes the day-one opening float onto a treasury repository (W4-2).
 *
 * See {@see RepositoryOpeningBalanceSeederInterface} for the contract and for
 * why this is the ONE sanctioned path.
 *
 * This service deliberately owns NO transaction and posts NO journal entry of
 * its own. The GL leg belongs to the ACCOUNTING opening batch (Dr the
 * repository's own cash/bank account / Cr Opening Balance Equity, on a
 * `is_historical = true` entry that is excluded from the fiscal hash chain —
 * seeding a till is not a transaction and has no business being sealed into the
 * chain). The batch's own `DB::transaction` encloses both halves, so the
 * movement and its journal entry commit or roll back together.
 */
final readonly class RepositoryOpeningBalanceService implements RepositoryOpeningBalanceSeederInterface
{
    public function __construct(
        private TreasuryMovementServiceInterface $movementService,
    ) {}

    public function describeByCode(
        string $tenantId,
        string $companyId,
        string $repositoryCode,
    ): ?OpeningFloatRepositoryDescriptor {
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $repositoryCode)
            ->where('is_active', true)
            ->first();

        if (! $repository instanceof PaymentRepository) {
            return null;
        }

        return new OpeningFloatRepositoryDescriptor(
            id: $repository->id,
            code: $repository->code,
            name: $repository->name,
            currency: $repository->currency,
            glAccountId: $repository->gl_account_id,
            hasMovements: $this->hasForeignMovement($repository->id, null),
        );
    }

    public function describeByGlAccounts(string $tenantId, string $companyId, array $glAccountIds): array
    {
        if ($glAccountIds === []) {
            return [];
        }

        return PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('gl_account_id', $glAccountIds)
            ->get()
            ->map(fn (PaymentRepository $repository): OpeningFloatRepositoryDescriptor => new OpeningFloatRepositoryDescriptor(
                id: $repository->id,
                code: $repository->code,
                name: $repository->name,
                currency: $repository->currency,
                glAccountId: $repository->gl_account_id,
                hasMovements: $this->hasForeignMovement($repository->id, null),
            ))
            ->values()
            ->all();
    }

    public function seed(OpeningFloatIntent $intent): OpeningFloatResult
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'RepositoryOpeningBalanceService::seed() must be called inside the batch\'s DB::transaction '
                .'(with the GL opening post), so the float and its journal entry commit together.'
            );
        }

        // gate r1 F-12 — the contract asserts "the batch IS the justifying
        // document (document-per-action)". Assert it instead of assuming it: an
        // opening movement whose source_id points at nothing is a float with no
        // document behind it, which is precisely what document-per-action
        // forbids. Read through the table rather than the Accounting model, so
        // Treasury does not import another module's domain (rule 6).
        $batchExists = DB::table('opening_balance_batches')
            ->where('id', $intent->batchId)
            ->where('tenant_id', $intent->tenantId)
            ->where('company_id', $intent->companyId)
            ->exists();

        if (! $batchExists) {
            throw new \DomainException(
                "Opening float batch {$intent->batchId} was not found for this company: an opening ".
                'movement must be justified by a real opening-balance batch.'
            );
        }

        $repository = PaymentRepository::query()
            ->where('tenant_id', $intent->tenantId)
            ->where('company_id', $intent->companyId)
            ->whereKey($intent->repositoryId)
            ->first();

        if (! $repository instanceof PaymentRepository) {
            throw new \DomainException(
                "Opening float target repository {$intent->repositoryId} was not found for this company."
            );
        }

        $idempotencyKey = MovementSourceType::OpeningBalance->value
            .':'.$intent->batchId
            .':'.$intent->idempotencyLeg();

        // AUTHORITATIVE post-time guard. Validation warns the operator early;
        // this is what actually holds, because a batch's rows can be validated
        // long before they are posted and a till can start trading in between.
        // Our OWN replay is not "already seeded" — it is the idempotent retry
        // the port is built for, so it is excluded from the census.
        if ($this->hasForeignMovement($repository->id, $idempotencyKey)) {
            throw new RepositoryAlreadySeededException(
                repositoryId: $repository->id,
                repositoryName: $repository->name,
                repositoryCode: $repository->code,
            );
        }

        $result = $this->movementService->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $intent->tenantId,
            companyId: $intent->companyId,
            direction: MovementDirection::In,
            amount: $intent->amount,
            currency: $intent->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $intent->batchId,
            idempotencyLeg: $intent->idempotencyLeg(),
            journalEntryId: $intent->journalEntryId,
            occurredAt: $intent->occurredAt,
            // No reason code: MovementReasonCode is the operator-chosen
            // COUNT-VARIANCE vocabulary (count_variance/correction/theft_loss/
            // other) offered by the "Adjust balance" dialog. An opening float is
            // not a variance, and adding a case there would surface "opening
            // balance" as an adjustment choice — reopening exactly the
            // book-the-float-as-revenue misuse RepositoryNotSeededException
            // closes. `source_type = opening_balance` already carries the
            // meaning.
            reasonCode: null,
            reversesMovementId: null,
            createdBy: $intent->createdBy,
            notes: null,
            allowWhileFrozen: false,
            allowBehindCheckpoint: false,
            allowNegative: false,
        ));

        return new OpeningFloatResult(
            movementId: $result->movementId,
            balanceAfter: $result->balanceAfter,
            wasIdempotentHit: $result->wasIdempotentHit,
        );
    }

    /**
     * True when the repository carries any movement that is NOT our own replay.
     *
     * `$ownKey` is null during validation (no batch is being posted yet), where
     * ANY movement disqualifies the repository.
     */
    private function hasForeignMovement(string $repositoryId, ?string $ownKey): bool
    {
        $query = RepositoryMovement::query()->where('payment_repository_id', $repositoryId);

        if ($ownKey !== null) {
            $query->where('idempotency_key', '!=', $ownKey);
        }

        return $query->exists();
    }
}
