<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a loyalty program is created.
 *
 * This is an operational event capturing the creation of a new loyalty program
 * within a company. It records the program configuration and settings.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ProgramCreatedV2.
 */
final class ProgramCreated extends DomainEvent
{
    public function __construct(
        public readonly string $programId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $programName,
        public readonly string $programType,
        public readonly string $currency,
        public readonly bool $isActive,
        public readonly ?string $description = null,
        public readonly ?string $createdAt = null,
    ) {
        parent::__construct($programId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'loyalty.program.created';
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
            'program_name' => $this->programName,
            'program_type' => $this->programType,
            'currency' => $this->currency,
            'is_active' => $this->isActive,
            'description' => $this->description,
            'created_at' => $this->createdAt ?? now()->toIso8601String(),
        ];
    }
}
