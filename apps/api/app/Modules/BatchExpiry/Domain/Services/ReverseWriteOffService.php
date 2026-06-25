<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Exceptions\WriteOffAlreadyReversedException;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a previously posted batch write-off (Phase C / C2).
 *
 * A reversal is the exact inverse of {@see BatchWriteOffService::writeOff()}:
 *  1. Restores AGGREGATE stock via StockAdjustmentService::receive() (the inverse
 *     of the write-off's issue()), recovering the ORIGINAL unit cost from the
 *     write-off movement row (B2 persisted it) — never recomputing WAC.
 *  2. Restores BATCH (lot) stock via BatchStockService::receiveBatchStock() (the
 *     inverse of issueBatchStock()), linked to the inverse movement.
 *  3. Posts a reversing journal entry mirroring the original write-off JE with
 *     debit/credit flipped (handled by GeneralLedgerService).
 *
 * A reversal creates a NEW inverse movement; it NEVER mutates the original row.
 * It is idempotent per original: a write-off can be reversed at most once,
 * guarded at the app layer ({@see StockMovement::reversalOf()}) and, as a
 * race-safe backstop, by the PostgreSQL partial unique index on
 * `reverses_movement_id`.
 *
 * The whole operation runs inside ONE DB transaction.
 */
final class ReverseWriteOffService
{
    /**
     * Write-off reasons that are eligible for reversal. Reversing any other
     * movement (sale, transfer, opening-balance, …) is rejected.
     *
     * @var list<MovementReason>
     */
    private const REVERSIBLE_REASONS = [
        MovementReason::Expiry,
        MovementReason::Damage,
        MovementReason::WriteOff,
    ];

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly BatchStockService $batchStockService,
        private readonly GeneralLedgerService $glService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Reverse a posted write-off movement, restoring stock and posting a
     * reversing journal entry.
     *
     * @throws \DomainException if $original is not a reversible write-off
     *                          or belongs to another tenant/company
     * @throws WriteOffAlreadyReversedException if $original has already been reversed
     */
    public function reverse(StockMovement $original, string $userId): StockMovement
    {
        // 1a. Reversible reason guard.
        $reason = $original->reason;
        if (! $reason instanceof MovementReason || ! in_array($reason, self::REVERSIBLE_REASONS, true)) {
            throw new \DomainException(
                'Only expiry/damage/write-off movements can be reversed; '
                .'movement '.$original->id.' has reason '
                .($reason instanceof MovementReason ? $reason->value : 'null').'.'
            );
        }

        // 1c. Tenant/company scoping (defense-in-depth; the controller also scopes).
        $contextCompanyId = $this->companyContext->requireCompanyId();
        $contextTenantId = $this->companyContext->requireTenantId();
        if ($original->company_id !== $contextCompanyId || $original->tenant_id !== $contextTenantId) {
            throw new \DomainException(
                'Cannot reverse a write-off that belongs to another company or tenant.'
            );
        }

        // 1b. Already-reversed guard (app layer). The DB partial unique index is
        //     the race-safe backstop, caught below.
        if ($original->reversalOf()->exists()) {
            throw WriteOffAlreadyReversedException::forMovement($original->id);
        }

        try {
            return DB::transaction(function () use ($original, $userId): StockMovement {
                // Locate the original batch (lot) this write-off drained, via the
                // inventory_batch_movements link written by issueBatchStock().
                $originalBatchMovement = BatchMovement::query()
                    ->where('movement_id', $original->id)
                    ->first();

                /** @var numeric-string $quantity */
                $quantity = (string) $original->quantity;
                $unitCost = $original->unit_cost !== null ? (string) $original->unit_cost : null;

                // 2. Restore AGGREGATE stock (inverse of the write-off's issue()).
                //    We intentionally do NOT pass batchId here — receiveBatchStock()
                //    is the single authority for batch stock; passing batchId would
                //    double-increment the lot.
                $inverse = $this->stockAdjustmentService->receive(
                    productId: (string) $original->product_id,
                    locationId: (string) $original->location_id,
                    quantity: $quantity,
                    reference: 'Reversal of write-off '.$original->id,
                    userId: $userId,
                    expectedCompanyId: (string) $original->company_id,
                    variantId: $original->variant_id,
                    reason: $original->reason,
                    unitCost: $unitCost,
                );

                // 5. Link the inverse to the original. The PG partial unique index
                //    enforces at-most-one reversal; a concurrent double-reverse
                //    surfaces here as a unique violation (translated below).
                $inverse->reverses_movement_id = $original->id;
                $inverse->save();

                // 3. Restore BATCH (lot) stock (inverse of issueBatchStock()).
                if ($originalBatchMovement !== null) {
                    $this->batchStockService->receiveBatchStock(
                        tenantId: (string) $original->tenant_id,
                        batchId: (int) $originalBatchMovement->batch_id,
                        locationId: (string) $original->location_id,
                        quantity: $quantity,
                        movementId: $inverse->id,
                    );
                }

                // 4. Post the reversing journal entry mirroring the original
                //    write-off JE (debit/credit flipped). ABSENT/Draft/Posted are
                //    all handled by the GL service.
                $currencyCode = Company::query()->whereKey($original->company_id)->value('currency');
                $this->glService->reverseInventoryWriteOffEntry(
                    companyId: (string) $original->company_id,
                    originalMovementId: $original->id,
                    reversalMovementId: $inverse->id,
                    postedByUserId: $userId,
                    currencyCode: is_string($currencyCode) ? $currencyCode : null,
                );

                return $inverse;
            });
        } catch (QueryException $e) {
            // Race-safe backstop: a concurrent reversal tripped the partial unique
            // index on reverses_movement_id.
            if ($this->isUniqueViolation($e)) {
                throw WriteOffAlreadyReversedException::forMovement($original->id);
            }

            throw $e;
        }
    }

    /**
     * Detect a unique-constraint violation across drivers (PG SQLSTATE 23505;
     * SQLite reports "UNIQUE constraint failed" in the driver message).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        if (($e->getCode()) === '23505') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
