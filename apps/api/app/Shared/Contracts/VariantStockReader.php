<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Cross-module read of on-hand stock for a single variant.
 * Implemented by Inventory, consumed by Catalog's variant delete guard.
 */
interface VariantStockReader
{
    /** Total on-hand quantity (quantity-scale-4 numeric string) for the variant across all locations. */
    public function variantOnHandQuantity(string $tenantId, string $companyId, string $variantId): string;
}
