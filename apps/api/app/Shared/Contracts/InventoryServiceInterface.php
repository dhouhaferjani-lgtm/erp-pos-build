<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for inventory operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface InventoryServiceInterface
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
    ): string;
}
