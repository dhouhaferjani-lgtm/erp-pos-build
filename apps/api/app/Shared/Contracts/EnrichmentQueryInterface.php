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
     * Find products that have pending or in-progress enrichment submissions,
     * for ONE tenant.
     *
     * `$tenantId` is REQUIRED rather than optional on purpose (2026-08-05
     * cat-(b) wave-1 review, B2). `products` is a tenant table, so under
     * database-per-tenant the bound connection already scopes the read and the
     * predicate is redundant-but-harmless; under the pre-flip compat mode
     * (`tenancy_resolver.db_per_tenant=false` — still the whole test suite's
     * mode) every tenant shares ONE database and the predicate is the only
     * thing that stops each tenant's poll from re-reading the same rows. A
     * defaulted/nullable parameter would make that difference silently
     * forgettable, which is exactly how this call site was missed.
     *
     * @return Collection<int, PendingEnrichmentDTO>
     */
    public function findPendingEnrichments(string $tenantId, int $limit, int $staleMinutes): Collection;
}
