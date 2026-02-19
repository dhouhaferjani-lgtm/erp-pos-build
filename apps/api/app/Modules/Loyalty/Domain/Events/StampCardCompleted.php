<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a member completes a stamp card.
 *
 * This operational event captures when a member fills all stamps on their stamp card
 * and becomes eligible for the completion reward. The actual reward issuance is tracked
 * separately through PointsEarned or RewardRedeemed events.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create StampCardCompletedV2.
 */
final class StampCardCompleted extends DomainEvent
{
    public function __construct(
        public readonly string $stampCardId,
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $partnerId,
        public readonly string $programId,
        public readonly string $campaignId,
        public readonly string $campaignName,
        public readonly int $stampsRequired,
        public readonly int $stampsEarned,
        public readonly string $completedAt,
        public readonly ?string $rewardId = null,
        public readonly ?string $rewardName = null,
    ) {
        parent::__construct($stampCardId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.stamp_card.completed';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'stamp_card_id' => $this->stampCardId,
            'enrollment_id' => $this->enrollmentId,
            'partner_id' => $this->partnerId,
            'program_id' => $this->programId,
            'campaign_id' => $this->campaignId,
            'campaign_name' => $this->campaignName,
            'stamps_required' => $this->stampsRequired,
            'stamps_earned' => $this->stampsEarned,
            'completed_at' => $this->completedAt,
            'reward_id' => $this->rewardId,
            'reward_name' => $this->rewardName,
        ];
    }
}
