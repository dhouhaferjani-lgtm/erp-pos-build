<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when loyalty points are redeemed (spent) by a member.
 *
 * This is a fiscal event that forms part of the compliance chain for loyalty programs.
 * Points redemption is tracked with full traceability to ensure accurate balance management.
 *
 * This event is part of the hash chain to ensure tamper-proof loyalty point tracking.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create PointsRedeemedV2.
 */
final class PointsRedeemed extends DomainEvent
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
        public readonly string $redemptionType,
        public readonly string $redemptionId,
        public readonly string $redeemedAt,
        public readonly ?string $description = null,
    ) {
        parent::__construct($transactionId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.points.redeemed';
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
            'redeemed_at' => $this->redeemedAt,
            'points' => $this->points,
            'monetary_value' => $this->monetaryValue,
            'currency' => $this->currency,
            'redemption_type' => $this->redemptionType,
            'redemption_id' => $this->redemptionId,
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
            'redemption_type' => $this->redemptionType,
            'redemption_id' => $this->redemptionId,
            'redeemed_at' => $this->redeemedAt,
            'description' => $this->description,
        ];
    }
}
