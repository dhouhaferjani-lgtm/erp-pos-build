<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\EnrichmentStatus;

/**
 * Synchronous, operator-triggered enrichment status poll.
 *
 * Mirrors the async webhook path ({@see ProcessEnrichmentEventListener}):
 * fetch the current platform status for a submitted product, update the
 * local enrichment_status, and — when completed — pull the enriched payload
 * into the review queue.
 */
final class ManualEnrichmentRefreshService
{
    public function __construct(
        private readonly PlatformSubmissionInterface $submissionService,
        private readonly EnrichmentReviewService $reviewService,
    ) {}

    /**
     * @return EnrichmentStatus|null the refreshed status, or null when the
     *                               platform is unavailable / has no record.
     */
    public function refresh(Product $product): ?EnrichmentStatus
    {
        $trackingId = $product->platform_submission_id;

        // Callers guard against a null submission id before invoking; this is
        // a defensive short-circuit for a product without a live submission.
        if ($trackingId === null) {
            return null;
        }

        $statusDTO = $this->submissionService->checkStatus($trackingId);

        if ($statusDTO === null) {
            return null;
        }

        $status = EnrichmentStatus::fromPlatformStatus($statusDTO->status);

        $product->update(['enrichment_status' => $status]);

        if ($status === EnrichmentStatus::Completed) {
            $this->reviewService->fetchAndStore($trackingId, $product, $statusDTO->locale);
        }

        return $status;
    }
}
