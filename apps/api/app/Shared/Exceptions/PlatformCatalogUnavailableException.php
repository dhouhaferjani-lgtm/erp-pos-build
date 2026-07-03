<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * The platform catalog could not answer a lookup (circuit open, transport
 * error, 5xx). Distinct from a genuine not_found so callers never treat a
 * transient outage as "this product does not exist".
 */
final class PlatformCatalogUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Platform catalog unavailable: {$reason}");
    }
}
