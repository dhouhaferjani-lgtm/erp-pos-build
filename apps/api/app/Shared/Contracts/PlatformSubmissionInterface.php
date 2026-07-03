<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\SubmissionStatusDTO;
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
}
