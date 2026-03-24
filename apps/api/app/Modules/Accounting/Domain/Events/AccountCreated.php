<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a new account is created in the chart of accounts.
 *
 * Dispatched from AccountController::store() after the account
 * is persisted to the database.
 *
 * Once dispatched, this event is immutable and forms part of the permanent
 * audit log for financial operations.
 */
final class AccountCreated extends DomainEvent
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type,
        public readonly string $createdAt,
    ) {
        parent::__construct($accountId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'accounting.account.created';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'account_id' => $this->accountId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'created_at' => $this->createdAt,
        ];
    }
}
