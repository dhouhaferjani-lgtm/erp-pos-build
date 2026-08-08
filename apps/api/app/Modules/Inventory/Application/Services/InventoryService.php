<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Contracts\InventoryServiceInterface;

/**
 * Application service for inventory operations.
 *
 * Exposes inventory functionality to other modules through the InventoryServiceInterface.
 *
 * Read-only by design since owner ruling D4 retired `upsertStockLevel`: that
 * method was the one write here, and it set an absolute quantity via
 * `StockLevel::updateOrCreate` with no stock movement and no justifying
 * document. Cross-module callers that need to CHANGE stock must go through a
 * document-backed path (StockAdjustmentService / OpeningBalancePostingService),
 * which is why this service no longer takes the ProductCostLock seam.
 */
final class InventoryService implements InventoryServiceInterface
{
    /**
     * Return true iff a non-reversed Opening movement exists for the product
     * in the given company. Mirrors the enter-once guard in
     * OpeningBalancePostingService (lines 80–87) but at product scope
     * (no location_id filter) so the Product module can ask once without
     * importing Inventory models directly.
     */
    public function hasActiveOpening(string $companyId, string $productId): bool
    {
        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('movement_type', MovementType::Opening)
            ->whereNull('reverses_movement_id')
            ->whereDoesntHave('reversalOf')
            ->exists();
    }

    /**
     * Return true iff any movement that is neither an Opening nor a reversal
     * of an Opening exists for the product in the given company.
     *
     * A reversal row has reverses_movement_id set — it points back to the
     * original opening it undoes. Excluding it keeps the "downstream activity"
     * definition clean: only real inventory transactions (receipts, issues,
     * adjustments, transfers) count.
     */
    public function hasDownstreamMovements(string $companyId, string $productId): bool
    {
        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('movement_type', '!=', MovementType::Opening)
            ->whereNull('reverses_movement_id')
            ->exists();
    }
}
