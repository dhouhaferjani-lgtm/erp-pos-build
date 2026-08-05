<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\DTOs\PendingEnrichmentDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Database\Eloquent\Builder;
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
        return $this->pendingWindow($staleMinutes)
            ->where('tenant_id', $tenantId)
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

    public function countPendingEnrichmentsOnConnection(int $staleMinutes): int
    {
        return $this->pendingWindow($staleMinutes)->count();
    }

    public function countPendingEnrichmentsForTenant(string $tenantId, int $staleMinutes): int
    {
        return $this->pendingWindow($staleMinutes)->where('tenant_id', $tenantId)->count();
    }

    /**
     * @return list<string>
     */
    public function findPendingEnrichmentIdsOutsideTenant(string $tenantId, int $staleMinutes, int $limit): array
    {
        return array_values(
            $this->pendingWindow($staleMinutes)
                ->where(static function (Builder $query) use ($tenantId): void {
                    $query->where('tenant_id', '!=', $tenantId)->orWhereNull('tenant_id');
                })
                ->limit($limit)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
    }

    /**
     * The polling window, and the ONLY definition of it.
     *
     * The read path and all three drift probes (N-6) share it on purpose: the
     * probe compares an unfiltered count to a tenant-scoped one, so the moment
     * the two sides disagree about staleness, status or submission-id presence
     * the delta stops meaning "drift" and starts meaning "the two queries are
     * different queries".
     *
     * @return Builder<Product>
     */
    private function pendingWindow(int $staleMinutes): Builder
    {
        return Product::query()
            ->whereIn('enrichment_status', [EnrichmentStatus::Pending, EnrichmentStatus::Enriching])
            ->whereNotNull('platform_submission_id')
            ->where('updated_at', '<', now()->subMinutes($staleMinutes));
    }
}
