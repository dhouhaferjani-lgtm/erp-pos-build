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

    /**
     * Return true iff a non-reversed Opening movement exists for the product
     * in the given company (tenant isolation via the per-tenant DB).
     *
     * "Non-reversed" means the row itself is not a reversal (reverses_movement_id IS NULL)
     * AND no other row has reversed it (whereDoesntHave reversalOf).
     */
    public function hasActiveOpening(string $companyId, string $productId): bool;

    /**
     * Return true iff any movement that is neither an Opening nor a reversal
     * of an Opening exists for the product in the given company.
     *
     * This indicates post-opening stock activity that would prevent
     * an opening balance from being safely modified or reversed.
     */
    public function hasDownstreamMovements(string $companyId, string $productId): bool;
}
