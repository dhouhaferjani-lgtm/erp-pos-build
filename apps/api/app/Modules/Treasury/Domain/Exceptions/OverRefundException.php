<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * Thrown when a refund of a completed payment would push the cumulative
 * already-refunded total past the original payment amount (Task 18 — spine
 * Wave D, review F10).
 *
 * The guard is evaluated INSIDE the refund transaction, under a
 * `lockForUpdate()` on the ORIGINAL payment row, so two concurrent partial
 * refunds of the same payment are serialised: whichever transaction acquires
 * the lock second observes the first's committed refund row and is rejected.
 * Without the lock both could read `alreadyRefunded = 0` and each pass a
 * per-request "amount <= original" check while jointly over-refunding.
 *
 * Extends \DomainException so the presentation layer maps it to HTTP 422.
 */
final class OverRefundException extends DomainException
{
    public function __construct(
        public readonly string $originalPaymentId,
        public readonly string $alreadyRefunded,
        public readonly string $requestedRefund,
        public readonly string $originalAmount,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Refund rejected: refunding {$requestedRefund} on top of {$alreadyRefunded} already refunded "
            ."would exceed the original payment amount of {$originalAmount} (payment {$originalPaymentId}).",
            $code,
            $previous,
        );
    }
}
