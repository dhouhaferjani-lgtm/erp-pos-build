<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\DTOs\PendingEnrichmentDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Support\Collection;

final class ProductEnrichmentQueryService implements EnrichmentQueryInterface
{
    /**
     * The `tenant_id` predicate is the same explicit guard the sibling
     * conversions carry (`DetectFraudPatterns`, `ChannelReconcileCommand`).
     * Redundant under database-per-tenant, REQUIRED under the compat mode where
     * every tenant shares one database — see the interface docblock (B2).
     *
     * It is applied BEFORE `limit`, so the budget is genuinely per tenant: one
     * tenant's backlog can no longer consume another's polling slots.
     *
     * @return Collection<int, PendingEnrichmentDTO>
     */
    public function findPendingEnrichments(string $tenantId, int $limit, int $staleMinutes): Collection
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('enrichment_status', [EnrichmentStatus::Pending, EnrichmentStatus::Enriching])
            ->whereNotNull('platform_submission_id')
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): PendingEnrichmentDTO => new PendingEnrichmentDTO(
                productId: $product->id,
                tenantId: $product->tenant_id,
                companyId: $product->company_id,
                platformSubmissionId: (string) $product->platform_submission_id,
                enrichmentStatus: $product->enrichment_status ?? EnrichmentStatus::Pending,
            ));
    }
}
