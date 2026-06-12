<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\LocationStockPageDTO;
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
}
