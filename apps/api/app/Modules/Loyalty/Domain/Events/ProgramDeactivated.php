<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a loyalty program is deactivated.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ProgramDeactivatedV2.
 */
final class ProgramDeactivated extends DomainEvent
{
    public function __construct(
        public readonly string $programId,
        public readonly string $tenantId,
        public readonly string $programName,
        public readonly string $deactivatedAt,
    ) {
        parent::__construct($programId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.program.deactivated';
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
            'deactivated_at' => $this->deactivatedAt,
        ];
    }
}
