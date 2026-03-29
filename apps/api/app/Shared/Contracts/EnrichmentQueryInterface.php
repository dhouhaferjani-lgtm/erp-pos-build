<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\PendingEnrichmentDTO;
use Illuminate\Support\Collection;

/**
 * Interface for querying products with pending enrichment submissions.
 *
 * Implemented by Product module, consumed by PlatformIntegration module.
 */
interface EnrichmentQueryInterface
{
    /**
     * Find products that have pending or in-progress enrichment submissions.
     *
     * @return Collection<int, PendingEnrichmentDTO>
     */
    public function findPendingEnrichments(int $limit, int $staleMinutes): Collection;
}
