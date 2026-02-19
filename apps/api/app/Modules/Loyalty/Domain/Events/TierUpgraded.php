<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a member is upgraded to a higher loyalty tier.
 *
 * This operational event captures tier progression when a member reaches the threshold
 * for a higher tier based on points earned, spend amount, or tier qualification criteria.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create TierUpgradedV2.
 */
final class TierUpgraded extends DomainEvent
{
    public function __construct(
        public readonly string $tierChangeId,
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $programId,
        public readonly string $fromTierId,
        public readonly string $toTierId,
        public readonly string $fromTierName,
        public readonly string $toTierName,
        public readonly string $qualificationMetric,
        public readonly string $qualificationValue,
        public readonly string $upgradedAt,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($tierChangeId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.tier.upgraded';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'tier_change_id' => $this->tierChangeId,
            'enrollment_id' => $this->enrollmentId,
            'partner_id' => $this->partnerId,
            'program_id' => $this->programId,
            'from_tier_id' => $this->fromTierId,
            'to_tier_id' => $this->toTierId,
            'from_tier_name' => $this->fromTierName,
            'to_tier_name' => $this->toTierName,
            'qualification_metric' => $this->qualificationMetric,
            'qualification_value' => $this->qualificationValue,
            'upgraded_at' => $this->upgradedAt,
            'reason' => $this->reason,
        ];
    }
}
