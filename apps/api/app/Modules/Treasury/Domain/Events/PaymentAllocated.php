<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment is allocated to invoices.
 *
 * This event captures the details of how a payment was distributed
 * across one or more invoices, including any excess amounts.
 */
final class PaymentAllocated extends DomainEvent
{
    /**
     * @param  array<int, array{document_id: string, amount: string}>  $allocations
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $allocationMethod,
        public readonly array $allocations,
        public readonly string $totalAllocated,
        public readonly string $excessAmount,
        public readonly string $allocatedAt,
    ) {
        parent::__construct($paymentId);
    }

    /**
     * Get the event name for audit logging.
     */
    public function getEventName(): string
    {
        return 'payment.allocated';
    }

    /**
     * Get the data to be used for audit trail.
     *
     * @return array<string, mixed>
     */
    public function getAuditData(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'allocation_method' => $this->allocationMethod,
            'total_allocated' => $this->totalAllocated,
            'excess_amount' => $this->excessAmount,
            'allocation_count' => count($this->allocations),
            'allocated_at' => $this->allocatedAt,
        ];
    }
}
