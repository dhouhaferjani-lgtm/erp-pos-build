<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * The platform tracking id is already bound to another product
 * (products.platform_submission_id UNIQUE constraint violation).
 */
final class EnrichmentCorrelationConflictException extends RuntimeException
{
    public function __construct(string $trackingId)
    {
        parent::__construct("Enrichment tracking id [{$trackingId}] is already correlated to another product.");
    }
}
