<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Contracts\InventoryServiceInterface;
use Illuminate\Support\Facades\DB;

/**
 * Application service for inventory operations.
 *
 * Exposes inventory functionality to other modules through the InventoryServiceInterface.
 */
final class InventoryService implements InventoryServiceInterface
{
    public function __construct(
        private readonly ProductCostLock $costLock,
    ) {}

    /**
     * Create or update a stock level.
     *
     * @return string The stock level ID
     */
    public function upsertStockLevel(
        string $tenantId,
        string $companyId,
        string $productId,
        string $locationId,
        int $quantity
    ): string {
        // updateOrCreate can CREATE a new stock_level row, so it must take the
        // ProductCostLock seam (advisory lock on the (tenant, company, product)
        // tuple) to serialize against a concurrent company-wide WAC recompute
        // and avoid a phantom-row race. Same tuple the rest of the seam uses.
        return DB::transaction(
            fn (): string => $this->costLock->acquire(
                $tenantId,
                $companyId,
                [$productId],
                function () use ($tenantId, $companyId, $productId, $locationId, $quantity): string {
                    $stockLevel = StockLevel::updateOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'company_id' => $companyId,
                            'product_id' => $productId,
                            'location_id' => $locationId,
                        ],
                        [
                            'quantity' => $quantity,
                            'reserved' => 0,
                        ]
                    );

                    return $stockLevel->id;
                }
            ),
            attempts: 3
        );
    }

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
