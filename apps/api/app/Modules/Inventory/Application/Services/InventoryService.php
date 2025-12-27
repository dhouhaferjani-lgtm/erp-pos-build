<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Contracts\InventoryServiceInterface;

/**
 * Application service for inventory operations.
 *
 * Exposes inventory functionality to other modules through the InventoryServiceInterface.
 */
final class InventoryService implements InventoryServiceInterface
{
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
}
