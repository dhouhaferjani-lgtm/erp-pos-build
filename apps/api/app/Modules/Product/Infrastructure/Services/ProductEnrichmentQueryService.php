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
     * @return Collection<int, PendingEnrichmentDTO>
     */
    public function findPendingEnrichments(int $limit, int $staleMinutes): Collection
    {
        return Product::query()
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
