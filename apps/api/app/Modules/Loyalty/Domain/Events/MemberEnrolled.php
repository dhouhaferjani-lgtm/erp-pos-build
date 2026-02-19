<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a member enrolls in a loyalty program.
 *
 * This operational event captures when a partner (customer) enrolls in a loyalty program,
 * creating a new enrollment record with initial tier and zero balance.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create MemberEnrolledV2.
 */
final class MemberEnrolled extends DomainEvent
{
    public function __construct(
        public readonly string $enrollmentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $programId,
        public readonly string $partnerId,
        public readonly string $membershipNumber,
        public readonly string $initialTierId,
        public readonly string $enrolledAt,
    ) {
        parent::__construct($enrollmentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.member.enrolled';
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
            'program_id' => $this->programId,
            'partner_id' => $this->partnerId,
            'membership_number' => $this->membershipNumber,
            'initial_tier_id' => $this->initialTierId,
            'enrolled_at' => $this->enrolledAt,
        ];
    }
}
