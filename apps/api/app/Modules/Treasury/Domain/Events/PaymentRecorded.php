<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment is recorded.
 *
 * This is a fiscal event that will be part of the hash chain
 * for compliance purposes.
 */
final class PaymentRecorded extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $paymentMethodId,
        public readonly string $recordedAt,
    ) {
        parent::__construct($paymentId);
    }

    /**
     * Get the event name for audit logging.
     */
    public function getEventName(): string
    {
        return 'payment.recorded';
    }

    /**
     * Get the data to be used for hash chain calculation.
     *
     * @return array<string, string>
     */
    public function getHashableData(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'recorded_at' => $this->recordedAt,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
