<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Cross-module read of on-hand stock for a single variant.
 * Implemented by Inventory, consumed by Catalog's variant delete guard.
 */
interface VariantStockReader
{
    /**
     * Total on-hand quantity for the variant across all locations.
     *
     * @return numeric-string quantity-scale-4 numeric string (e.g. "5.5000")
     */
    public function variantOnHandQuantity(string $tenantId, string $companyId, string $variantId): string;
}
