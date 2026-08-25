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
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
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

        // 1a-bis. Reject a target that is itself a reversal. A reversal's inverse
        //         movement carries reverses_movement_id != null AND inherits a
        //         reversible reason (via receive(reason: $original->reason)), so it
        //         would otherwise sail past every other guard and inflate aggregate
        //         + lot stock AGAIN with NO offsetting JE (its JE source_type is
        //         batch_write_off_reversal, not batch_write_off) — chainable for
        //         unbounded phantom stock. A reversal is not itself reversible.
        if ($original->reverses_movement_id !== null) {
            throw new \DomainException(
                'Movement '.$original->id.' is itself a reversal and cannot be reversed.'
            );
        }

        // 1a-ter. Defense in depth: a forward write-off is an ISSUE (outbound)
        //          movement; the inverse a reversal creates is a RECEIPT. Reject
        //          anything that is not the write-off issue movement.
        if ($original->movement_type !== MovementType::Issue) {
            throw new \DomainException(
                'Only write-off issue movements can be reversed; movement '
                .$original->id.' is of type '.$original->movement_type->value.'.'
            );
        }

        // 1a-quater. A POS RETURN SCRAP is not a batch write-off and must not be
        //            reversed here (DPA V10 gate C3).
        //
        //            Until V10 the POS scrap movement was typed `Adjustment`, so
        //            the guard above rejected it by accident. V10 routes it through
        //            StockAdjustmentService::issue() — correct for costing, but it
        //            makes the movement indistinguishable from a lot write-off to
        //            every remaining guard here, while the web Reverse action is
        //            gated on `reason` ALONE. One click would then:
        //              (a) receive() physically destroyed goods back into SELLABLE
        //                  stock while the return receipt still says
        //                  `disposition = scrap` — ledger and fiscal document in
        //                  direct contradiction with no compensating document,
        //                  i.e. the exact document-per-action violation this lane
        //                  exists to remediate; and
        //              (b) on a batch-tracked product (every product in the
        //                  parapharmacy vertical) inflate the lot ledger: a POS
        //                  scrap writes NO inventory_batch_movements row, so the
        //                  lot-restore branch below is skipped, but receive() with
        //                  batchId: null runs ensureDefaultBatchForImplicitPositiveStock,
        //                  which tops the DEFAULT lot up on top of the real lots —
        //                  SUM(inventory_batch_stock) > stock_levels.quantity.
        //
        //                  🚨 Campaign W2-7, gate r1 finding 8 — that helper now
        //                  seeds only the UNTRACKED REMAINDER, AND this service
        //                  passes `creditsLotItself: true` when it is about to
        //                  credit the original lot at step 3 below, so the reversed
        //                  units are booked exactly once. Before the flag the
        //                  ORDERING alone double-booked them: the implicit helper
        //                  runs BEFORE the lot credit, so at helper time the units
        //                  looked untracked, got a DEFAULT lot, and step 3 then
        //                  booked them into the real lot as well (probe: real lot
        //                  26 / aggregate 26 / reverse 4 -> Sigma lots 34 vs 30).
        //                  Pinned by SiblingSeamsUntrackedRemainderTest::
        //                  test_reversing_a_write_off_does_not_double_book_the_reversed_units.
        //
        //            A scrap disposition is undone by CORRECTING THE RETURN (which
        //            re-authors both legs through the fiscal document), never by a
        //            batch write-off reversal.
        if ($original->reference_type === StockMovementReferenceType::PosReceiptReturnScrap->value) {
            throw new \DomainException(
                'Movement '.$original->id.' is a POS return scrap write-off and cannot be reversed here; '
                .'a scrap disposition is undone by correcting the return receipt, not by a batch write-off reversal.'
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
                    // Gate r1 finding 8 — step 3 below credits the ORIGINAL lot
                    // itself. Without this flag the implicit DEFAULT-lot helper runs
                    // FIRST, sees the reversed units as untracked (the lot has not
                    // been credited yet) and backs them with a DEFAULT lot; step 3
                    // then books the same units into the real lot, leaving
                    // SUM(inventory_batch_stock) above stock_levels.quantity.
                    creditsLotItself: $originalBatchMovement !== null,
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
            // index on reverses_movement_id. ONLY that specific constraint is
            // translated to "already reversed" — any OTHER unique violation (e.g.
            // a journal_entries.entry_number collision or a stock_levels index
            // race) is a genuine fault and must surface, not be mis-reported as a
            // 409 on this fiscal path.
            if ($this->isReversesMovementUniqueViolation($e)) {
                throw WriteOffAlreadyReversedException::forMovement($original->id);
            }

            throw $e;
        }
    }

    /**
     * Detect a unique-constraint violation specifically on the
     * `reverses_movement_id` partial unique index (the double-reverse guard) —
     * and ONLY that one.
     *
     * PG raises SQLSTATE 23505 naming the index
     * `stock_movements_reverses_movement_id_unique`; SQLite reports
     * "UNIQUE constraint failed: stock_movements.reverses_movement_id". Both
     * mention `reverses_movement_id`, so we require a unique violation that also
     * references that column/index. Every other unique violation is rethrown.
     */
    private function isReversesMovementUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        $isUniqueViolation = $e->getCode() === '23505'
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique');

        if (! $isUniqueViolation) {
            return false;
        }

        return str_contains($message, 'stock_movements_reverses_movement_id_unique')
            || str_contains($message, 'reverses_movement_id');
    }
}
