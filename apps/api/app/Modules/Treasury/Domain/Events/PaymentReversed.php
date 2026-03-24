<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment is reversed.
 *
 * Dispatched from PaymentRefundService::reversePayment().
 * Immutable — never modify once deployed.
 */
final class PaymentReversed extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reversedAt,
    ) {
        parent::__construct($paymentId);
    }

    public function getEventName(): string
    {
        return 'treasury.payment.reversed';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reversed_at' => $this->reversedAt,
        ];
    }
}
