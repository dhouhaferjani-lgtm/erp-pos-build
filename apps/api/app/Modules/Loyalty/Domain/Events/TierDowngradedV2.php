<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a member is downgraded to a lower loyalty tier (V2).
 *
 * This version matches the separate LoyaltyMember entity architecture.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create TierDowngradedV3.
 */
final class TierDowngradedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $enrollmentId,
        public readonly string $memberId,
        public readonly string $programId,
        public readonly ?string $previousTierId,
        public readonly string $newTierId,
        public readonly string $newTierName,
        public readonly string $downgradedAt,
    ) {
        parent::__construct($enrollmentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.tier.downgraded.v2';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'enrollment_id' => $this->enrollmentId,
            'member_id' => $this->memberId,
            'program_id' => $this->programId,
            'previous_tier_id' => $this->previousTierId,
            'new_tier_id' => $this->newTierId,
            'new_tier_name' => $this->newTierName,
            'downgraded_at' => $this->downgradedAt,
        ];
    }
}
