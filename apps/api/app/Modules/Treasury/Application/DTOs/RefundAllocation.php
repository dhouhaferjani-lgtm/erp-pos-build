<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

/**
 * Value object representing one proration slice of a receipt refund.
 *
 * Each allocation maps back to one original payment row via $originalPaymentId,
 * and carries the positive refund amount (i.e. the absolute value — the negative
 * sign is applied by PaymentRefundService when writing the Payment row).
 */
final readonly class RefundAllocation
{
    /**
     * @param  string  $originalPaymentId  UUID of the original Payment row
     * @param  string  $paymentId  UUID of the newly-written refund Payment row
     * @param  numeric-string  $amount  Positive decimal at currency scale (e.g. "30.00" for EUR)
     */
    public function __construct(
        public readonly string $originalPaymentId,
        public readonly string $paymentId,
        public readonly string $amount,
    ) {}
}
