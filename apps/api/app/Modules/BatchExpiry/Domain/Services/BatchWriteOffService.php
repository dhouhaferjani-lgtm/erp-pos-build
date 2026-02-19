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

            // 1. Deduct aggregate stock via StockAdjustmentService
            $movement = $this->stockAdjustmentService->issue(
                productId: $productId,
                locationId: $locationId,
                quantity: $quantity,
                reference: "Write-off: Batch {$batch->batch_number}" . ($notes !== null ? " - {$notes}" : ''),
                userId: $userId,
                batchId: (int) $batch->id,
            );

            // 2. Deduct batch-level stock
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
                    amount: $this->calculateWriteOffAmount($productId, $quantity),
                    reason: $movementReason,
                    movementId: $movement->id,
                );
            } catch (\RuntimeException $e) {
                // GL accounts may not be configured — log but don't block
                Log::warning('Could not create GL entry for batch write-off: ' . $e->getMessage(), [
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
     * @return numeric-string
     */
    private function calculateWriteOffAmount(string $productId, string $quantity): string
    {
        $product = \App\Modules\Product\Domain\Product::find($productId);
        if ($product === null) {
            return '0.00';
        }

        $unitCost = (string) ($product->weighted_average_cost ?? $product->cost_price ?? '0.00');

        return bcmul($quantity, $unitCost, 2);
    }
}
