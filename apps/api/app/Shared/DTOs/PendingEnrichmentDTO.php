<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Enums\EnrichmentStatus;

/**
 * Represents a product with a pending enrichment submission.
 *
 * Carries tenantId + companyId so the fleet-wide poller in
 * CheckPendingEnrichmentsCommand can rebind CompanyContext per-record
 * before each outbound HTTP call to the Synerivia platform — every
 * outbound request must carry X-Tenant-Id + X-Company-Id reflecting
 * the originating tenant of THIS record, not the previous iteration's.
 */
final readonly class PendingEnrichmentDTO
{
    public function __construct(
        public string $productId,
        public string $tenantId,
        public string $companyId,
        public string $platformSubmissionId,
        public EnrichmentStatus $enrichmentStatus,
    ) {}
}
