<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Formally writes off expired/damaged batch stock with GL entries.
 *
 * Handles both:
 * 1. Aggregate stock deduction (via StockAdjustmentService)
 * 2. Batch-level stock deduction (via BatchStockService)
 * 3. GL entry: Dr. COGS/WriteOff Expense, Cr. Inventory Asset
 */
final class BatchWriteOffService
{
    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly BatchStockService $batchStockService,
        private readonly GeneralLedgerService $glService,
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Write off batch stock with audit trail and GL entries.
     *
     * @param  numeric-string  $quantity
     *
     * @throws \DomainException If insufficient stock or invalid reason
     */
    public function writeOff(
        Batch $batch,
        string $locationId,
        string $quantity,
        string $reason,
        string $userId,
        ?string $notes = null,
    ): StockMovement {
        $movementReason = match ($reason) {
            'expiry' => MovementReason::Expiry,
            'damage' => MovementReason::Damage,
            'other' => MovementReason::WriteOff,
            default => throw new \DomainException("Invalid write-off reason: {$reason}"),
        };

        return DB::transaction(function () use ($batch, $locationId, $quantity, $movementReason, $userId, $notes): StockMovement {
            $productId = (string) $batch->product_id;

            // Resolve the unit cost at the time of write-off so that a future
            // Phase C "reverse write-off" entry can recover the ORIGINAL cost from
            // the movement row itself — not by recomputing from a now-changed WAC.
            // We use the same fallback chain as calculateWriteOffAmount:
            //   cost_price (the persisted WAC) ?? '0.00'
            // (weighted_average_cost is a virtual accessor not in the @property
            // list; cost_price is the DB-persisted WAC column and the canonical
            // fallback used by calculateWriteOffAmount.)
            // The product is scoped to the batch's own tenant + company for
            // defense-in-depth (mirrors calculateWriteOffAmount's scope guard).
            $product = Product::query()
                ->where('tenant_id', $batch->tenant_id)
                ->where('company_id', $batch->company_id)
                ->find($productId);

            /** @var numeric-string $writeOffUnitCost */
            $writeOffUnitCost = $product !== null
                ? (string) ($product->cost_price ?? '0.00')
                : '0.00';

            // 1. Deduct AGGREGATE stock only. We intentionally do NOT pass batchId
            //    here: issue() with a batchId also decrements inventory_batch_stock
            //    internally, which — combined with issueBatchStock() below — would
            //    double-decrement the lot (and block writing off a lot's full
            //    on-hand). issueBatchStock() is the single authority for batch stock.
            $movement = $this->stockAdjustmentService->issue(
                productId: $productId,
                locationId: $locationId,
                quantity: $quantity,
                reference: "Write-off: Batch {$batch->batch_number}".($notes !== null ? " - {$notes}" : ''),
                userId: $userId,
                expectedCompanyId: $batch->company_id,
                reason: $movementReason,
                unitCost: $writeOffUnitCost,
            );

            // 2. Deduct batch-level stock (sole batch-stock writer; performs the
            //    availability check and links the movement to the batch ledger).
            $this->batchStockService->issueBatchStock(
                tenantId: $batch->tenant_id,
                batchId: (int) $batch->id,
                locationId: $locationId,
                quantity: $quantity,
                movementId: $movement->id,
            );

            // 3. Create GL entry: Dr. COGS (write-off expense), Cr. Inventory
            $company = $this->companyContext->requireCompany();
            try {
                $this->glService->createInventoryWriteOffEntry(
                    companyId: (string) $company->id,
                    batchNumber: $batch->batch_number,
                    productId: $productId,
                    amount: $this->calculateWriteOffAmount(
                        productId: $productId,
                        quantity: $quantity,
                        tenantId: (string) $batch->tenant_id,
                        companyId: (string) $batch->company_id,
                        currency: $company->currency,
                    ),
                    reason: $movementReason,
                    movementId: $movement->id,
                    postedByUserId: $userId,
                    currencyCode: $company->currency,
                );
            } catch (\RuntimeException $e) {
                // GL accounts may not be configured — log but don't block
                Log::warning('Could not create GL entry for batch write-off: '.$e->getMessage(), [
                    'batch_id' => $batch->id,
                    'quantity' => $quantity,
                    'reason' => $movementReason->value,
                ]);
            }

            return $movement;
        });
    }

    /**
     * Calculate the write-off amount using the product's WAC.
     *
     * api.unmapped.014 (api.inventory): the lookup is scoped by the source
     * batch's tenant_id + company_id. The public write-off route is already
     * structurally protected by BatchController::findBatchOrFail (which
     * enforces $batch->company_id === current company), but a service-direct
     * caller (queue job, cross-module orchestrator) can hit this method with
     * arbitrary product_ids; scoping by the batch's own tenant + company
     * provides defense-in-depth and pins the SQL invariant.
     *
     * @return numeric-string
     */
    private function calculateWriteOffAmount(
        string $productId,
        string $quantity,
        string $tenantId,
        string $companyId,
        string $currency,
    ): string {
        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($productId);
        if ($product === null) {
            return '0.00';
        }

        /** @var numeric-string $unitCost */
        $unitCost = (string) ($product->weighted_average_cost ?? $product->cost_price ?? '0.00');
        /** @var numeric-string $quantity */

        // Resolve scale from the company's own currency (context-safe AND
        // fiscally correct: EUR→2, TND→3) so a service-direct caller (queue job /
        // cross-module orchestrator) does not depend on a bound CompanyContext.
        return bcmul($quantity, $unitCost, $this->scaleResolver->getScale($currency));
    }
}
