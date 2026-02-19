<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a loyalty program is activated.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ProgramActivatedV2.
 */
final class ProgramActivated extends DomainEvent
{
    public function __construct(
        public readonly string $programId,
        public readonly string $tenantId,
        public readonly string $programName,
        public readonly string $activatedAt,
    ) {
        parent::__construct($programId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.program.activated';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'program_id' => $this->programId,
            'tenant_id' => $this->tenantId,
            'program_name' => $this->programName,
            'activated_at' => $this->activatedAt,
        ];
    }
}
