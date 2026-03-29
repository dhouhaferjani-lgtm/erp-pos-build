<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Enums\EnrichmentStatus;

/**
 * Represents a product with a pending enrichment submission.
 */
final readonly class PendingEnrichmentDTO
{
    public function __construct(
        public string $productId,
        public string $platformSubmissionId,
        public EnrichmentStatus $enrichmentStatus,
    ) {}
}
