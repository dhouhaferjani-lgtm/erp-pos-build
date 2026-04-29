<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use App\Modules\POS\Domain\Enums\RefundDestination;
use RuntimeException;

/**
 * Thrown by RefundDestinationResolver when the requested refund destination is
 * not permitted under the tenant's current refund policy.
 *
 * Callers should surface a user-readable message to the cashier. The exception
 * message includes the refused destination and the policy trigger so that audit
 * logs can record why the destination was blocked.
 */
final class RefundDestinationNotAllowedException extends RuntimeException
{
    public function __construct(
        public readonly RefundDestination $requested,
        string $reason,
    ) {
        parent::__construct(sprintf(
            "Refund destination '%s' is not allowed: %s",
            $requested->value,
            $reason,
        ));
    }
}
