<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\EnrichmentSubmissionCorrelatorInterface;
use App\Shared\Enums\EnrichmentStatus;
use App\Shared\Exceptions\EnrichmentAlreadyPendingException;
use App\Shared\Exceptions\EnrichmentCorrelationConflictException;
use App\Shared\Exceptions\ProductNotFoundForEnrichmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Product-module implementation of the enrichment correlation bridge.
 *
 * Owns the write to products.platform_submission_id / enrichment_status so
 * the PlatformIntegration module never touches the Product model directly.
 */
final class ProductEnrichmentCorrelationService implements EnrichmentSubmissionCorrelatorInterface
{
    /**
     * Enrichment states that represent a live, in-flight submission. A
     * product in one of these states already has a tracking id awaiting a
     * webhook/poll result — resubmitting would orphan that correlation.
     *
     * @var list<EnrichmentStatus>
     */
    private const LIVE_STATUSES = [EnrichmentStatus::Pending, EnrichmentStatus::Enriching];

    public function assertSubmittable(string $productId, string $companyId): void
    {
        $product = $this->findOwnedProduct($productId, $companyId);

        if ($product === null) {
            throw new ProductNotFoundForEnrichmentException($productId);
        }

        if ($this->isLive($product->enrichment_status)) {
            throw new EnrichmentAlreadyPendingException($productId);
        }
    }

    public function correlateSubmission(string $productId, string $companyId, string $trackingId): void
    {
        try {
            DB::transaction(function () use ($productId, $companyId, $trackingId): void {
                $product = $this->findOwnedProduct($productId, $companyId, lockForUpdate: true);

                if ($product === null) {
                    throw new ProductNotFoundForEnrichmentException($productId);
                }

                if ($this->isLive($product->enrichment_status)) {
                    throw new EnrichmentAlreadyPendingException($productId);
                }

                $product->update([
                    'platform_submission_id' => $trackingId,
                    'enrichment_status' => EnrichmentStatus::Pending,
                ]);
            });
        } catch (QueryException $e) {
            // The products.platform_submission_id UNIQUE constraint rejects a
            // tracking id already bound to another product.
            if (str_contains($e->getMessage(), '23505') || str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new EnrichmentCorrelationConflictException($trackingId);
            }

            throw $e;
        }
    }

    private function findOwnedProduct(string $productId, string $companyId, bool $lockForUpdate = false): ?Product
    {
        $query = Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isLive(?EnrichmentStatus $status): bool
    {
        return $status !== null && in_array($status, self::LIVE_STATUSES, true);
    }
}
