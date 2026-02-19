<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a loyalty member enrolls in a program (V2).
 *
 * This version matches the separate LoyaltyMember entity architecture where
 * members can exist independently of Partner (customer) records.
 *
 * Key differences from V1:
 * - Uses member_id instead of partner_id (nullable customer linkage)
 * - No membership_number (not part of entity design)
 * - Optional welcome_bonus tracking
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create MemberEnrolledV3.
 */
final class MemberEnrolledV2 extends DomainEvent
{
    public function __construct(
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $programId,
        public readonly string $memberId,
        public readonly string $enrolledAt,
        public readonly ?string $customerId = null,
        public readonly ?float $welcomeBonus = null,
    ) {
        parent::__construct($enrollmentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.member.enrolled.v2';
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
            'tenant_id' => $this->tenantId,
            'program_id' => $this->programId,
            'member_id' => $this->memberId,
            'customer_id' => $this->customerId,
            'enrolled_at' => $this->enrolledAt,
            'welcome_bonus' => $this->welcomeBonus,
        ];
    }
}
