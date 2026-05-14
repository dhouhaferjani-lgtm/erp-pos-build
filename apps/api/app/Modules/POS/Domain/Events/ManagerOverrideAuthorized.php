<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a manager's POS PIN is successfully verified at the
 * override gate (ManagerPinController::verify) — a manager authorising a
 * cashier to exceed a limit (e.g. closing a shift with an out-of-tolerance
 * cash variance).
 *
 * A successful verification is itself a privileged action. The downstream
 * receipt.authorized_by_user_id / shift.manager_override_by columns capture
 * the manager identity but not *when* the authorisation happened; this event
 * is the durable, timestamped audit record.
 *
 * Shape note (N1 / G2): verify() receives only user_id + pin — it does not
 * know the override type or the target receipt/shift id (those are recorded
 * downstream, on a later request). The event therefore carries only what the
 * gate knows: the manager who authorised, the caller who requested, the
 * company scope, and the timestamp. Immutable — never modify once deployed.
 */
final class ManagerOverrideAuthorized extends DomainEvent
{
    public function __construct(
        public readonly string $managerId,
        public readonly string $callerId,
        public readonly string $companyId,
        public readonly string $verifiedAt,
    ) {
        parent::__construct($managerId);
    }

    public function getEventName(): string
    {
        return 'pos.manager_override.authorized';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'manager_id' => $this->managerId,
            'caller_id' => $this->callerId,
            'verified_at' => $this->verifiedAt,
        ];
    }
}
