<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when loyalty points expire.
 *
 * This is a fiscal event that forms part of the compliance chain for loyalty programs.
 * Points expiration reduces the member's balance and must be tracked with full traceability.
 *
 * This event is part of the hash chain to ensure tamper-proof loyalty point tracking.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PointsExpiredV2.
 */
final class PointsExpired extends DomainEvent
{
    public function __construct(
        public readonly string $expirationId,
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $programId,
        public readonly string $points,
        public readonly string $monetaryValue,
        public readonly string $currency,
        public readonly string $originalTransactionId,
        public readonly string $earnedAt,
        public readonly string $expiredAt,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($expirationId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.points.expired';
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
            'expiration_id' => $this->expirationId,
            'enrollment_id' => $this->enrollmentId,
            'expired_at' => $this->expiredAt,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'original_transaction_id' => $this->originalTransactionId,
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
            'expiration_id' => $this->expirationId,
            'enrollment_id' => $this->enrollmentId,
            'partner_id' => $this->partnerId,
            'program_id' => $this->programId,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'original_transaction_id' => $this->originalTransactionId,
            'earned_at' => $this->earnedAt,
            'expired_at' => $this->expiredAt,
            'reason' => $this->reason,
        ];
    }
}
