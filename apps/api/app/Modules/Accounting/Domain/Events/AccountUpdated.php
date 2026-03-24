<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when an existing account is updated in the chart of accounts.
 *
 * Dispatched from AccountController::update() after the account
 * is successfully modified. Contains the changed fields for audit purposes.
 *
 * Once dispatched, this event is immutable and forms part of the permanent
 * audit log for financial operations.
 */
final class AccountUpdated extends DomainEvent
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly array $changes,
        public readonly string $updatedAt,
    ) {
        parent::__construct($accountId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'accounting.account.updated';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'account_id' => $this->accountId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'changes' => $this->changes,
            'updated_at' => $this->updatedAt,
        ];
    }
}
