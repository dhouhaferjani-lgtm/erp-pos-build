<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\SubmissionStatusDTO;
use App\Shared\Enums\BrandMappingPushResult;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;

/**
 * Interface for checking platform submission status.
 *
 * Implemented by PlatformIntegration module, consumed by Product module.
 */
interface PlatformSubmissionInterface
{
    public function checkStatus(string $trackingId): ?SubmissionStatusDTO;

    public function sendFeedback(
        string $trackingId,
        EnrichmentFeedbackAction $action,
        ?EnrichmentFeedbackReason $reason,
        ?string $notes,
    ): bool;

    /**
     * Push the local ERP brand id for a canonical platform brand to the
     * idempotent external-mapping endpoint.
     */
    public function pushBrandMapping(string $canonicalBrandId, string $externalBrandId): BrandMappingPushResult;
}
