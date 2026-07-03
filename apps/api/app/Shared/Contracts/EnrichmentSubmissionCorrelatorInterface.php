<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\TrackingIdHolderDTO;
use App\Shared\Exceptions\EnrichmentAlreadyPendingException;
use App\Shared\Exceptions\EnrichmentCorrelationConflictException;
use App\Shared\Exceptions\ProductNotFoundForEnrichmentException;

/**
 * Bridge for correlating a platform enrichment submission back to a local
 * product.
 *
 * Implemented by the Product module (which owns the Product model),
 * consumed by the PlatformIntegration module (which owns the outbound
 * platform submit). This keeps PlatformIntegration free of any direct
 * dependency on the Product Eloquent model — the same Shared/Contracts
 * pattern used by {@see EnrichmentQueryInterface}.
 */
interface EnrichmentSubmissionCorrelatorInterface
{
    /**
     * Verify a product can be submitted for enrichment before any outbound
     * platform call is made (fail fast, avoid orphan platform submissions).
     *
     * @throws ProductNotFoundForEnrichmentException product missing or not owned by the company
     * @throws EnrichmentAlreadyPendingException product already has a live submission
     */
    public function assertSubmittable(string $productId, string $companyId): void;

    /**
     * Return the company-scoped product currently holding a platform tracking
     * id, if any. Keeps consumers from reading Product directly.
     */
    public function findTrackingIdHolder(string $trackingId, string $companyId): ?TrackingIdHolderDTO;

    /**
     * Persist the platform tracking id against a company-scoped product so
     * inbound enrichment webhooks / polling can correlate the result back.
     *
     * Atomic: writes platform_submission_id + enrichment_status = pending in
     * a single transaction, re-checking the not-pending invariant to close
     * the race window opened by {@see assertSubmittable}.
     *
     * @throws ProductNotFoundForEnrichmentException product missing or not owned by the company
     * @throws EnrichmentAlreadyPendingException product acquired a live submission in the interim
     * @throws EnrichmentCorrelationConflictException tracking id already bound to another product
     */
    public function correlateSubmission(string $productId, string $companyId, string $trackingId): void;
}
