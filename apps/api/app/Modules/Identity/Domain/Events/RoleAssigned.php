<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a role is assigned to a user.
 *
 * Privileged action — "who granted whom which role, and when" is a primary
 * compliance audit question. Dispatched from RoleController::assignRole().
 * Immutable — never modify once deployed.
 */
final class RoleAssigned extends DomainEvent
{
    public function __construct(
        public readonly string $targetUserId,
        public readonly string $roleName,
        public readonly string $companyId,
        public readonly string $actorUserId,
        public readonly string $assignedAt,
    ) {
        parent::__construct($targetUserId);
    }

    public function getEventName(): string
    {
        return 'identity.role.assigned';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'target_user_id' => $this->targetUserId,
            'role_name' => $this->roleName,
            'actor_user_id' => $this->actorUserId,
            'assigned_at' => $this->assignedAt,
        ];
    }
}
