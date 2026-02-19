<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a loyalty reward is redeemed (V2).
 *
 * This version matches the separate LoyaltyMember entity architecture.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create RewardRedeemedV3.
 */
final class RewardRedeemedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $enrollmentId,
        public readonly string $memberId,
        public readonly string $programId,
        public readonly string $rewardId,
        public readonly float $pointsCost,
        public readonly string $redeemedAt,
    ) {
        parent::__construct($transactionId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.reward.redeemed.v2';
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
            'reward_id' => $this->rewardId,
            'points_cost' => $this->pointsCost,
            'redeemed_at' => $this->redeemedAt,
        ];
    }
}
