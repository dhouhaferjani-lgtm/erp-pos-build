<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when loyalty points are earned by a member.
 *
 * This is a fiscal event that forms part of the compliance chain for loyalty programs.
 * Points earned from purchases are tracked with full traceability to the originating transaction.
 *
 * This event is part of the hash chain to ensure tamper-proof loyalty point tracking.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PointsEarnedV2.
 */
final class PointsEarned extends DomainEvent
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $programId,
        public readonly string $points,
        public readonly string $monetaryValue,
        public readonly string $currency,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $earnedAt,
        public readonly ?string $expiresAt = null,
        public readonly ?string $description = null,
    ) {
        parent::__construct($transactionId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.points.earned';
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
            'transaction_id' => $this->transactionId,
            'enrollment_id' => $this->enrollmentId,
            'earned_at' => $this->earnedAt,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
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
            'transaction_id' => $this->transactionId,
            'enrollment_id' => $this->enrollmentId,
            'partner_id' => $this->partnerId,
            'program_id' => $this->programId,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'earned_at' => $this->earnedAt,
            'expires_at' => $this->expiresAt,
            'description' => $this->description,
        ];
    }
}
