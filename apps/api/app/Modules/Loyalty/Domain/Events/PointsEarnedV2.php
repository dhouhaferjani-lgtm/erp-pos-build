<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when loyalty points are earned (V2).
 *
 * This version matches the separate LoyaltyMember entity architecture and
 * includes source tracking for the earning transaction.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PointsEarnedV3.
 */
final class PointsEarnedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $enrollmentId,
        public readonly string $memberId,
        public readonly string $programId,
        public readonly float $amount,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $earnedAt,
    ) {
        parent::__construct($transactionId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.points.earned.v2';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'enrollment_id' => $this->enrollmentId,
            'member_id' => $this->memberId,
            'program_id' => $this->programId,
            'amount' => $this->amount,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'earned_at' => $this->earnedAt,
        ];
    }
}
