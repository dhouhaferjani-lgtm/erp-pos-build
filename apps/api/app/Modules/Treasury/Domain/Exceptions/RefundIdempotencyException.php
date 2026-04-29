<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a duplicate refund insertion is attempted for a (company_id,
 * original_payment_id, refund_request_id) combination that is already present
 * in the payments table with payment_type = 'refund'.
 *
 * The unique partial index `payments_refund_idempotency_uniq` is the DB-level
 * guard; this exception surfaces the violation to service callers.
 */
final class RefundIdempotencyException extends RuntimeException
{
    public function __construct(
        public readonly string $originalPaymentId,
        public readonly string $refundRequestId,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "A refund for original_payment_id={$originalPaymentId} with refund_request_id={$refundRequestId} already exists.",
            $code,
            $previous,
        );
    }
}
