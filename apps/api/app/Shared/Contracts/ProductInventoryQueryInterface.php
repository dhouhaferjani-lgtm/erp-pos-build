<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\ProductInventoryDTO;
use Illuminate\Support\Collection;

/**
 * Interface for querying product inventory by platform article IDs.
 *
 * Implemented by Product module, consumed by PlatformIntegration module.
 */
interface ProductInventoryQueryInterface
{
    /**
     * Find products linked to the given platform article IDs, with stock data.
     *
     * @param  array<int, string>  $platformArticleIds
     * @return Collection<string, ProductInventoryDTO> Keyed by platform article ID
     */
    public function findByPlatformArticleIds(string $companyId, array $platformArticleIds): Collection;
}
