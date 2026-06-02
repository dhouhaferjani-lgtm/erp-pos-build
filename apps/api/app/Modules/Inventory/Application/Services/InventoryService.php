<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
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
}
