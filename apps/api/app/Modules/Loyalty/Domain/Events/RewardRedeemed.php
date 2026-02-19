<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a member redeems a reward from the loyalty program.
 *
 * This operational event captures when a member exchanges their points for a specific reward.
 * It links to the PointsRedeemed event that records the actual point deduction.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create RewardRedeemedV2.
 */
final class RewardRedeemed extends DomainEvent
{
    public function __construct(
        public readonly string $redemptionId,
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $programId,
        public readonly string $rewardId,
        public readonly string $rewardName,
        public readonly string $pointsCost,
        public readonly string $redeemedAt,
        public readonly ?string $fulfilledAt = null,
        public readonly ?string $notes = null,
    ) {
        parent::__construct($redemptionId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.reward.redeemed';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'redemption_id' => $this->redemptionId,
            'enrollment_id' => $this->enrollmentId,
            'partner_id' => $this->partnerId,
            'program_id' => $this->programId,
            'reward_id' => $this->rewardId,
            'reward_name' => $this->rewardName,
            'points_cost' => $this->pointsCost,
            'redeemed_at' => $this->redeemedAt,
            'fulfilled_at' => $this->fulfilledAt,
            'notes' => $this->notes,
        ];
    }
}
