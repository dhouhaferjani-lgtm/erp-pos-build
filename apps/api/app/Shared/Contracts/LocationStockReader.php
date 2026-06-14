<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\LocationStockPageDTO;
use App\Shared\DTOs\StockDistributionDTO;
use Carbon\CarbonImmutable;

/**
 * Location-scoped stock + incoming read model for the POS device sync.
 * Implemented by Inventory, consumed by POS (spec §4.1).
 *
 * Contract notes:
 * - $updatedSince = null  ⇒ FULL mode: complete stock set for the location
 *   (client treats the union of all pages as replace-all).
 * - $updatedSince set     ⇒ DELTA mode: stock rows with updated_at > cursor.
 * - The incoming set is ALWAYS complete for the location regardless of mode
 *   (incoming changes don't touch destination stock_levels.updated_at).
 */
interface LocationStockReader
{
    public function read(
        string $tenantId,
        string $companyId,
        string $locationId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): LocationStockPageDTO;

    /**
     * Cross-location stock distribution for one product (+ optional variant)
     * across ALL active shop+warehouse locations of the company.
     *
     * For each location returns on-hand (available = quantity − reserved) and
     * in-transit incoming (sum of in-transit stock-transfer line quantities
     * whose destination is that location), zero-filled for locations with no
     * stock. The current location is sorted first, then by name. All
     * quantities are quantity-scale-4 numeric strings. Variant grain: when
     * $variantId is null both stock and transfer terms match variant_id IS
     * NULL (never summed across variants).
     */
    public function stockDistributionForProduct(
        string $tenantId,
        string $companyId,
        string $productId,
        ?string $variantId,
        string $currentLocationId,
    ): StockDistributionDTO;
}
