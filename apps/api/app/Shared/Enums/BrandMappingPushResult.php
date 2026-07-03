<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Outcome of pushing a local brand id to the platform external-mapping endpoint.
 * Conflict and NotFound are terminal reconciliation outcomes; only Failed is retryable.
 */
enum BrandMappingPushResult: string
{
    case Mapped = 'mapped';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case Failed = 'failed';
}
