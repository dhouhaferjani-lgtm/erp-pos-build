<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a role is removed from a user.
 *
 * Privileged action — role revocation must be audit-logged with the actor
 * and timestamp, mirroring RoleAssigned. Dispatched from
 * RoleController::removeRole(). Immutable — never modify once deployed.
 */
final class RoleRemoved extends DomainEvent
{
    public function __construct(
        public readonly string $targetUserId,
        public readonly string $roleName,
        public readonly string $companyId,
        public readonly string $actorUserId,
        public readonly string $removedAt,
    ) {
        parent::__construct($targetUserId);
    }

    public function getEventName(): string
    {
        return 'identity.role.removed';
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
            'removed_at' => $this->removedAt,
        ];
    }
}
