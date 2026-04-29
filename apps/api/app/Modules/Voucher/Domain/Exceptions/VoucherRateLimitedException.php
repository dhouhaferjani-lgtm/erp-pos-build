<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by VoucherLookupRateLimiter when any rate-limit counter is tripped.
 *
 * Callers MUST NOT surface the specific reason to end-users — return a generic
 * "invalid" response on every failure path (spec §4.7 enumeration-attack guard).
 */
final class VoucherRateLimitedException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
