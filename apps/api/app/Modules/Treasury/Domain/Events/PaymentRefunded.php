<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment is refunded.
 *
 * Dispatched from PaymentRefundService::refundPayment().
 * Immutable — never modify once deployed.
 */
final class PaymentRefunded extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $originalPaymentId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly string $refundedAt,
    ) {
        parent::__construct($paymentId);
    }

    public function getEventName(): string
    {
        return 'treasury.payment.refunded';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'original_payment_id' => $this->originalPaymentId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'refunded_at' => $this->refundedAt,
        ];
    }
}
