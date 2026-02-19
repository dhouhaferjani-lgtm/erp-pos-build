<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when an administrator manually adjusts a member's loyalty balance.
 *
 * This is a fiscal event that forms part of the compliance chain for loyalty programs.
 * Manual adjustments must be tracked with full traceability including the reason and
 * the user who made the adjustment for audit and fraud detection purposes.
 *
 * This event is part of the hash chain to ensure tamper-proof loyalty point tracking.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create LoyaltyAdjustedV2.
 */
final class LoyaltyAdjusted extends DomainEvent
{
    public function __construct(
        public readonly string $adjustmentId,
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $programId,
        public readonly string $adjustmentType,
        public readonly string $points,
        public readonly string $monetaryValue,
        public readonly string $currency,
        public readonly string $previousBalance,
        public readonly string $newBalance,
        public readonly string $adjustedBy,
        public readonly string $adjustedAt,
        public readonly string $reason,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId = null,
    ) {
        parent::__construct($adjustmentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.balance.adjusted';
    }

    /**
     * Get the data to be used for hash chain calculation.
     *
     * This data is used to create a tamper-proof chain of loyalty point transactions.
     *
     * @return array<string, string>
     */
    public function getHashableData(): array
    {
        return [
            'adjustment_id' => $this->adjustmentId,
            'enrollment_id' => $this->enrollmentId,
            'adjusted_at' => $this->adjustedAt,
            'adjustment_type' => $this->adjustmentType,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'previous_balance' => $this->previousBalance,
            'new_balance' => $this->newBalance,
            'adjusted_by' => $this->adjustedBy,
        ];
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'adjustment_id' => $this->adjustmentId,
            'enrollment_id' => $this->enrollmentId,
            'partner_id' => $this->partnerId,
            'program_id' => $this->programId,
            'adjustment_type' => $this->adjustmentType,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'previous_balance' => $this->previousBalance,
            'new_balance' => $this->newBalance,
            'adjusted_by' => $this->adjustedBy,
            'adjusted_at' => $this->adjustedAt,
            'reason' => $this->reason,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
        ];
    }
}
